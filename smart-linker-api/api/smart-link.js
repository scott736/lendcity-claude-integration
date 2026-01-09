const { querySimilar, getArticle, incrementInboundLinks } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { analyzeContentForLinking, generateAnchorText } = require('../lib/claude');
const { getRecommendations, calculateHybridScoreWithSEO, filterByContentType } = require('../lib/scoring');
const {
  refreshSEOCache,
  trackAnchorUsage,
  calculateSEOScore,
  getSitewideSEOMetrics
} = require('../lib/seo-scoring');

/**
 * Smart Link Endpoint
 * Main endpoint for generating internal link suggestions
 *
 * POST /api/smart-link
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // Verify API key
  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  try {
    const {
      postId,
      content,
      title,
      // Source article metadata for scoring
      topicCluster,
      relatedClusters = [],
      funnelStage,
      targetPersona,
      contentType = 'post',  // 'page' or 'post' - pages never link to posts
      // Options
      maxLinks = 5,
      minScore = 40,
      excludeIds = [],
      useClaudeAnalysis = true,
      autoInsert = false,
      strictSilo = false,     // Only link within same cluster
      includeSEOMetrics = true // Include detailed SEO scoring
    } = req.body;

    // Validate required fields
    if (!content) {
      return res.status(400).json({
        error: 'Missing required field: content'
      });
    }

    // Refresh SEO cache for accurate scoring
    if (includeSEOMetrics) {
      await refreshSEOCache();
    }

    // Build source article metadata
    const sourceArticle = {
      postId,
      title,
      content,  // Include content for SEO position scoring
      topicCluster,
      relatedClusters,
      funnelStage,
      targetPersona,
      contentType: contentType.toLowerCase()
    };

    // Early exit for pages - they don't get automatic links
    if (contentType.toLowerCase() === 'page') {
      console.log(`Skipping smart linking for page ${postId} - pages are manually managed`);
      return res.status(200).json({
        success: true,
        links: [],
        message: 'Pages do not receive automatic links - manage page links manually',
        contentType: 'page'
      });
    }

    // Step 1: Generate embedding for source content
    const contentText = extractBodyText(content);
    const embedding = await generateEmbedding(`${title || ''} ${contentText}`);

    // Step 2: Query Pinecone for similar articles
    const excludeList = [...excludeIds];
    if (postId) excludeList.push(postId);

    let candidates = await querySimilar(embedding, {
      topK: 50, // Get more candidates for filtering
      excludeIds: excludeList
    });

    // Filter candidates by content type (posts can only link to pages & posts, not vice versa)
    // Since source is a post (pages exit early), we allow all target types
    // But we still filter using the utility for consistency
    candidates = filterByContentType(candidates, contentType);

    if (candidates.length === 0) {
      return res.status(200).json({
        success: true,
        links: [],
        message: 'No candidate articles found'
      });
    }

    // Step 3: Apply hybrid scoring (vectors + business rules)
    const { recommendations, totalCandidates, passedFilter, averageScore } = getRecommendations(
      sourceArticle,
      candidates,
      { minScore, maxResults: maxLinks * 2, strictSilo } // Get extra for Claude to filter
    );

    if (recommendations.length === 0) {
      return res.status(200).json({
        success: true,
        links: [],
        message: 'No articles passed scoring threshold',
        debug: { totalCandidates, minScore }
      });
    }

    // Step 4: Claude analysis for placement and anchors (optional)
    let finalLinks = [];

    if (useClaudeAnalysis && recommendations.length > 0) {
      // Get existing links in content
      const existingLinks = extractExistingLinks(content);

      // Ask Claude to analyze and select best placements
      const analysis = await analyzeContentForLinking(
        content,
        recommendations.map(r => ({
          ...r.candidate,
          score: r.totalScore
        })),
        { maxLinks, existingLinks }
      );

      // Map Claude's selections back to full recommendation data with SEO scoring
      const linkPromises = analysis.links.map(async (link) => {
        const rec = recommendations[link.candidateIndex];

        // Calculate full SEO score now that we have anchor text
        let seoData = null;
        if (includeSEOMetrics) {
          seoData = await calculateSEOScore({
            sourceId: postId,
            sourceType: contentType,
            targetId: rec.candidate.postId,
            targetType: rec.candidate.contentType || 'post',
            target: rec.candidate,
            anchorText: link.anchorText,
            content,
            existingLinks
          });
        }

        return {
          postId: rec.candidate.postId,
          title: rec.candidate.title,
          url: rec.candidate.url,
          topicCluster: rec.candidate.topicCluster,
          contentType: rec.candidate.contentType || 'post',
          score: rec.totalScore,
          scoreBreakdown: rec.breakdown,
          anchorText: link.anchorText,
          placement: link.placement,
          reasoning: link.reasoning,
          // SEO optimization data
          seo: seoData ? {
            score: seoData.totalSEOScore,
            allowed: seoData.allowed,
            breakdown: seoData.breakdown
          } : null
        };
      });

      finalLinks = await Promise.all(linkPromises);

      // Re-sort by combined score (original + SEO boost)
      if (includeSEOMetrics) {
        finalLinks.sort((a, b) => {
          const aTotal = a.score + (a.seo?.score || 0) * 0.2;
          const bTotal = b.score + (b.seo?.score || 0) * 0.2;
          return bTotal - aTotal;
        });
      }
    } else {
      // Without Claude, just use top recommendations with title as anchor
      finalLinks = recommendations.slice(0, maxLinks).map(rec => ({
        postId: rec.candidate.postId,
        title: rec.candidate.title,
        url: rec.candidate.url,
        topicCluster: rec.candidate.topicCluster,
        contentType: rec.candidate.contentType || 'post',
        score: rec.totalScore,
        scoreBreakdown: rec.breakdown,
        anchorText: rec.candidate.title,
        placement: null,
        reasoning: 'Top-scored by hybrid algorithm',
        seo: null
      }));
    }

    // Step 5: Generate content with links inserted (if autoInsert)
    let linkedContent = null;
    if (autoInsert && finalLinks.length > 0) {
      linkedContent = insertLinksIntoContent(content, finalLinks);

      // Update inbound link counts and track anchor usage for SEO
      for (const link of finalLinks) {
        await incrementInboundLinks(link.postId);

        // Track anchor usage for diversity scoring
        await trackAnchorUsage(link.anchorText, postId, link.postId, true);
      }
    }

    // Get site-wide SEO metrics if requested
    let seoMetrics = null;
    if (includeSEOMetrics) {
      seoMetrics = await getSitewideSEOMetrics();
    }

    return res.status(200).json({
      success: true,
      links: finalLinks,
      linkedContent,
      stats: {
        candidatesFound: totalCandidates,
        passedScoring: passedFilter,
        averageScore,
        linksGenerated: finalLinks.length
      },
      // SEO optimization summary
      seoSummary: includeSEOMetrics ? {
        sitewideHealth: seoMetrics?.health || null,
        anchorDiversityStatus: seoMetrics?.anchors?.overused > 5 ? 'warning' : 'good',
        reciprocalLinkRatio: seoMetrics?.links?.reciprocalRatio || 0,
        recommendations: generateSEORecommendations(finalLinks, seoMetrics)
      } : null
    });

  } catch (error) {
    console.error('Smart link error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Extract existing links from content
 */
function extractExistingLinks(content) {
  const links = [];
  const regex = /<a[^>]+href=["']([^"']+)["'][^>]*>([^<]+)<\/a>/gi;
  let match;

  while ((match = regex.exec(content)) !== null) {
    links.push({
      url: match[1],
      anchor: match[2]
    });
  }

  return links;
}

/**
 * Insert links into content at suggested placements
 */
function insertLinksIntoContent(content, links) {
  let result = content;

  for (const link of links) {
    if (!link.placement) continue;

    // Find the placement text in content
    const placementText = link.placement;
    const anchorText = link.anchorText;

    // Check if anchor text exists in the placement context
    if (placementText.includes(anchorText)) {
      // Replace just the anchor text with a link
      const linkedText = placementText.replace(
        anchorText,
        `<a href="${link.url}">${anchorText}</a>`
      );
      result = result.replace(placementText, linkedText);
    } else {
      // Try to find a natural insertion point
      // Look for the first sentence that contains related keywords
      const sentences = result.split(/(?<=[.!?])\s+/);
      for (let i = 0; i < sentences.length; i++) {
        const sentence = sentences[i];
        // Simple heuristic: if sentence relates to the topic, append link
        if (sentence.toLowerCase().includes(link.topicCluster?.replace(/-/g, ' ') || '')) {
          sentences[i] = sentence + ` For more details, see <a href="${link.url}">${anchorText}</a>.`;
          break;
        }
      }
      result = sentences.join(' ');
    }
  }

  return result;
}

/**
 * Generate SEO recommendations based on link analysis
 */
function generateSEORecommendations(links, seoMetrics) {
  const recommendations = [];

  // Check anchor diversity issues
  for (const link of links) {
    if (link.seo?.breakdown?.anchorDiversity?.usage > 5) {
      recommendations.push({
        type: 'anchor_diversity',
        severity: 'warning',
        link: link.anchorText,
        target: link.title,
        message: `Anchor "${link.anchorText}" is overused (${link.seo.breakdown.anchorDiversity.usage} times). Consider varying anchor text.`
      });
    }

    // Check for reciprocal link warnings
    if (link.seo?.breakdown?.reciprocal?.isReciprocal) {
      recommendations.push({
        type: 'reciprocal_link',
        severity: 'info',
        target: link.title,
        message: `Reciprocal link detected with "${link.title}". Consider one-way linking for better SEO.`
      });
    }

    // Check for first link priority
    if (link.seo?.breakdown?.firstLink && !link.seo.breakdown.firstLink.isFirstLink) {
      recommendations.push({
        type: 'duplicate_target',
        severity: 'info',
        target: link.title,
        message: `Already linked to "${link.title}" with anchor "${link.seo.breakdown.firstLink.existingAnchor}". Additional link has reduced SEO value.`
      });
    }

    // Check position scoring
    if (link.seo?.breakdown?.linkPosition?.percentile > 80) {
      recommendations.push({
        type: 'link_position',
        severity: 'suggestion',
        target: link.title,
        message: `Link to "${link.title}" appears late in content (${link.seo.breakdown.linkPosition.percentile}%). Earlier placement has more SEO value.`
      });
    }
  }

  // Site-wide recommendations
  if (seoMetrics?.anchors?.overused > 10) {
    recommendations.push({
      type: 'sitewide_anchor_diversity',
      severity: 'warning',
      message: `${seoMetrics.anchors.overused} anchor texts are overused site-wide. Review and diversify anchor text strategy.`
    });
  }

  if (seoMetrics?.links?.reciprocalRatio > 30) {
    recommendations.push({
      type: 'sitewide_reciprocal',
      severity: 'warning',
      message: `High reciprocal link ratio (${seoMetrics.links.reciprocalRatio}%). Consider more one-way linking patterns.`
    });
  }

  return recommendations;
}
