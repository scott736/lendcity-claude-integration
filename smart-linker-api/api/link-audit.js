const { querySimilar, getArticle, getAllArticles } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { getRecommendations } = require('../lib/scoring');

/**
 * Link Audit Endpoint
 * Analyzes existing links in content and suggests improvements
 *
 * POST /api/link-audit
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
      existingLinks = [], // Array of { anchor, url, targetId }
      topicCluster,
      maxSuggestions = 5
    } = req.body;

    if (!content) {
      return res.status(400).json({ error: 'content is required' });
    }

    const audit = {
      existing: {
        total: existingLinks.length,
        valid: [],
        broken: [],
        suboptimal: []
      },
      suggestions: {
        upgrades: [],    // Better targets for existing anchors
        missing: [],     // New links that should be added
        redundant: []    // Links that might be removed
      },
      stats: {}
    };

    // Step 1: Validate existing links against Pinecone catalog
    for (const link of existingLinks) {
      const targetArticle = await getArticle(link.targetId);

      if (!targetArticle) {
        // Link points to article not in catalog (might be deleted or external)
        audit.existing.broken.push({
          ...link,
          issue: 'Target article not found in catalog',
          action: 'Consider removing or updating this link'
        });
        continue;
      }

      // Check if there's a better target for this anchor text
      const anchorEmbedding = await generateEmbedding(link.anchor);
      const betterMatches = await querySimilar(anchorEmbedding, {
        topK: 5,
        excludeIds: [postId, link.targetId]
      });

      // Score the current target vs alternatives
      const currentScore = targetArticle.qualityScore || 50;
      const betterOptions = betterMatches.filter(match => {
        const matchScore = match.metadata?.qualityScore || 50;
        const similarity = match.score || 0;
        // Better if: higher quality AND good semantic match
        return matchScore > currentScore && similarity > 0.7;
      });

      if (betterOptions.length > 0) {
        audit.existing.suboptimal.push({
          ...link,
          currentTarget: {
            title: targetArticle.title,
            url: targetArticle.url,
            qualityScore: currentScore
          },
          betterOptions: betterOptions.slice(0, 2).map(opt => ({
            postId: opt.metadata.postId,
            title: opt.metadata.title,
            url: opt.metadata.url,
            qualityScore: opt.metadata.qualityScore || 50,
            similarity: Math.round(opt.score * 100)
          }))
        });
      } else {
        audit.existing.valid.push({
          ...link,
          target: {
            title: targetArticle.title,
            qualityScore: currentScore,
            topicCluster: targetArticle.topicCluster
          },
          status: 'optimal'
        });
      }
    }

    // Step 2: Find missing link opportunities
    const contentText = extractBodyText(content);
    const contentEmbedding = await generateEmbedding(`${title || ''} ${contentText}`);

    // Get all candidate articles
    const excludeIds = [postId, ...existingLinks.map(l => l.targetId)];
    const candidates = await querySimilar(contentEmbedding, {
      topK: 20,
      excludeIds
    });

    if (candidates.length > 0) {
      // Score candidates
      const sourceArticle = { postId, title, topicCluster };
      const { recommendations } = getRecommendations(
        sourceArticle,
        candidates,
        { minScore: 50, maxResults: maxSuggestions }
      );

      audit.suggestions.missing = recommendations.map(rec => ({
        postId: rec.candidate.postId,
        title: rec.candidate.title,
        url: rec.candidate.url,
        topicCluster: rec.candidate.topicCluster,
        score: rec.totalScore,
        reason: `High relevance (${Math.round(rec.vectorScore || 0)}% semantic match)`
      }));
    }

    // Step 3: Check for redundant links (multiple links to same cluster)
    const clusterCounts = {};
    for (const link of audit.existing.valid) {
      const cluster = link.target?.topicCluster || 'unknown';
      clusterCounts[cluster] = (clusterCounts[cluster] || 0) + 1;
    }

    for (const [cluster, count] of Object.entries(clusterCounts)) {
      if (count > 2) {
        audit.suggestions.redundant.push({
          cluster,
          count,
          suggestion: `Consider reducing links to "${cluster}" cluster (currently ${count})`
        });
      }
    }

    // Calculate stats
    audit.stats = {
      totalLinks: existingLinks.length,
      validLinks: audit.existing.valid.length,
      brokenLinks: audit.existing.broken.length,
      suboptimalLinks: audit.existing.suboptimal.length,
      missingOpportunities: audit.suggestions.missing.length,
      healthScore: existingLinks.length > 0
        ? Math.round((audit.existing.valid.length / existingLinks.length) * 100)
        : 100
    };

    return res.status(200).json({
      success: true,
      postId,
      audit
    });

  } catch (error) {
    console.error('Link audit error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
