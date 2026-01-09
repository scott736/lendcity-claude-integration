const {
  refreshSEOCache,
  getSitewideSEOMetrics,
  getAnchorDiversityScore,
  getReciprocalLinkScore,
  getPageRankScore
} = require('../lib/seo-scoring');
const { getAllArticles } = require('../lib/pinecone');

/**
 * SEO Metrics Endpoint
 * Provides site-wide SEO health metrics and optimization recommendations
 *
 * GET /api/seo-metrics - Get all metrics
 * POST /api/seo-metrics - Get metrics with optional filters
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  // Verify API key
  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  try {
    const {
      refreshCache = true,
      includeOverusedAnchors = true,
      includePageRankDistribution = true,
      includeContentTypeBreakdown = true,
      topOverusedLimit = 20
    } = req.method === 'POST' ? req.body : req.query;

    // Refresh cache if requested
    if (refreshCache) {
      await refreshSEOCache();
    }

    // Get site-wide metrics
    const metrics = await getSitewideSEOMetrics();

    // Build response
    const response = {
      success: true,
      timestamp: new Date().toISOString(),
      health: metrics.health,
      summary: {
        anchorDiversity: {
          totalUniqueAnchors: metrics.anchors.total,
          averageUsage: metrics.anchors.averageUsage,
          overusedCount: metrics.anchors.overused,
          status: metrics.health.anchorDiversity
        },
        linkProfile: {
          totalLinks: metrics.links.total,
          reciprocalLinks: metrics.links.reciprocal,
          reciprocalRatio: `${metrics.links.reciprocalRatio}%`,
          status: metrics.health.reciprocalRatio
        },
        internalPageRank: {
          average: metrics.pageRank.average,
          distribution: metrics.pageRank.distribution
        }
      }
    };

    // Include detailed overused anchors if requested
    if (includeOverusedAnchors && metrics.anchors.overusedList) {
      response.overusedAnchors = metrics.anchors.overusedList.slice(0, topOverusedLimit);
    }

    // Include content type breakdown if requested
    if (includeContentTypeBreakdown) {
      const articles = await getAllArticles({ limit: 5000 });

      const breakdown = {
        pages: { total: 0, pillar: 0, withLinks: 0 },
        posts: { total: 0, orphaned: 0, withLinks: 0 }
      };

      for (const article of articles) {
        const meta = article.metadata || article;
        const type = (meta.contentType || 'post').toLowerCase();
        const hasLinks = (meta.inboundLinkCount || 0) > 0;

        if (type === 'page') {
          breakdown.pages.total++;
          if (meta.isPillar) breakdown.pages.pillar++;
          if (hasLinks) breakdown.pages.withLinks++;
        } else {
          breakdown.posts.total++;
          if (!hasLinks) breakdown.posts.orphaned++;
          if (hasLinks) breakdown.posts.withLinks++;
        }
      }

      response.contentTypeBreakdown = breakdown;
    }

    // Include PageRank distribution if requested
    if (includePageRankDistribution) {
      response.pageRankDistribution = {
        highAuthority: metrics.pageRank.distribution.high,
        mediumAuthority: metrics.pageRank.distribution.medium,
        lowAuthority: metrics.pageRank.distribution.low,
        explanation: {
          high: 'Pages with PageRank >= 70 - strong internal authority',
          medium: 'Pages with PageRank 30-69 - moderate authority',
          low: 'Pages with PageRank < 30 - need more internal links'
        }
      };
    }

    // Generate recommendations
    response.recommendations = generateSEORecommendations(metrics, response);

    return res.status(200).json(response);

  } catch (error) {
    console.error('SEO metrics error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Generate SEO recommendations based on metrics
 */
function generateSEORecommendations(metrics, response) {
  const recommendations = [];

  // Anchor diversity recommendations
  if (metrics.health.anchorDiversity === 'poor') {
    recommendations.push({
      priority: 'high',
      category: 'anchor_diversity',
      title: 'Critical: Anchor Text Over-Optimization Risk',
      description: `${metrics.anchors.overused} anchor texts are used more than 5 times. This pattern can trigger Google penalties.`,
      action: 'Review and vary anchor text for frequently used phrases. Use synonyms, partial matches, and branded anchors.',
      impact: 'High - directly affects ranking potential'
    });
  } else if (metrics.health.anchorDiversity === 'moderate') {
    recommendations.push({
      priority: 'medium',
      category: 'anchor_diversity',
      title: 'Moderate: Diversify Anchor Text',
      description: `Some anchor texts are being reused frequently. Consider varying your anchor text strategy.`,
      action: 'Audit top used anchors and create variations.',
      impact: 'Medium - preventive measure'
    });
  }

  // Reciprocal link recommendations
  if (metrics.health.reciprocalRatio === 'poor') {
    recommendations.push({
      priority: 'high',
      category: 'link_pattern',
      title: 'High Reciprocal Link Ratio',
      description: `${metrics.links.reciprocalRatio}% of your links are reciprocal (A↔B pattern). This can look unnatural to Google.`,
      action: 'Review reciprocal links and convert some to one-way links. Focus on topical hierarchy.',
      impact: 'Medium-High - affects link credibility'
    });
  }

  // Orphaned content recommendations
  if (response.contentTypeBreakdown?.posts?.orphaned > 5) {
    recommendations.push({
      priority: 'high',
      category: 'site_structure',
      title: 'Orphaned Content Detected',
      description: `${response.contentTypeBreakdown.posts.orphaned} posts have no internal links pointing to them.`,
      action: 'Add internal links to orphaned posts from related content. These pages may not be indexed properly.',
      impact: 'High - affects crawlability and indexation'
    });
  }

  // Low authority page recommendations
  if (response.pageRankDistribution?.lowAuthority > response.pageRankDistribution?.highAuthority) {
    recommendations.push({
      priority: 'medium',
      category: 'authority_distribution',
      title: 'Unbalanced Authority Distribution',
      description: `More pages have low internal authority than high authority. Link equity is not flowing effectively.`,
      action: 'Add links from high-authority pages (pillar content) to low-authority pages.',
      impact: 'Medium - affects ranking potential of deep pages'
    });
  }

  // Pillar page recommendations
  if (response.contentTypeBreakdown?.pages?.pillar < 3) {
    recommendations.push({
      priority: 'medium',
      category: 'content_structure',
      title: 'Consider Adding More Pillar Pages',
      description: `Only ${response.contentTypeBreakdown?.pages?.pillar || 0} pillar pages found. Pillar pages are crucial for topical authority.`,
      action: 'Create comprehensive pillar pages for your main topic clusters.',
      impact: 'Medium - affects topical authority signals'
    });
  }

  return recommendations.sort((a, b) => {
    const priorityOrder = { high: 0, medium: 1, low: 2 };
    return priorityOrder[a.priority] - priorityOrder[b.priority];
  });
}
