/**
 * Link Decay Detection
 *
 * Detects when content has drifted semantically from its original
 * version, suggesting that internal links may no longer be relevant.
 */

const { getAllArticles, getArticle, updateMetadata } = require('./pinecone');
const { generateEmbedding, cosineSimilarity } = require('./embeddings');

/**
 * Thresholds for decay detection
 */
const DECAY_THRESHOLDS = {
  HEALTHY: 0.95,      // > 0.95 similarity = content unchanged
  MINOR: 0.85,        // 0.85-0.95 = minor updates
  MODERATE: 0.70,     // 0.70-0.85 = significant changes
  MAJOR: 0.50         // < 0.70 = major rewrite, links may be stale
};

/**
 * Check if a single article has decayed
 *
 * @param {number} postId - Article ID
 * @param {string} currentContent - Current content to compare
 * @returns {Object} Decay analysis
 */
async function checkArticleDecay(postId, currentContent) {
  const article = await getArticle(postId);

  if (!article) {
    return { error: 'Article not found in catalog' };
  }

  // Get stored embedding
  // Note: In production, you'd fetch the actual vector from Pinecone
  // For now, we regenerate and compare metadata signals

  // Generate new embedding for current content
  const currentEmbedding = await generateEmbedding(currentContent);

  // Compare with stored metadata signals
  const analysis = {
    postId,
    title: article.title,
    lastUpdated: article.updatedAt,
    decayStatus: 'unknown',
    similarity: null,
    recommendations: []
  };

  // Check freshness based on update date
  const daysSinceUpdate = article.updatedAt
    ? (Date.now() - new Date(article.updatedAt).getTime()) / (1000 * 60 * 60 * 24)
    : 999;

  const lifespan = article.contentLifespan || 'evergreen';

  // Time-based decay check
  if (lifespan === 'time-sensitive') {
    if (daysSinceUpdate > 90) {
      analysis.decayStatus = 'stale';
      analysis.recommendations.push({
        type: 'update_needed',
        reason: 'Time-sensitive content over 90 days old',
        priority: 'high'
      });
    }
  } else if (lifespan === 'evergreen') {
    if (daysSinceUpdate > 365) {
      analysis.decayStatus = 'review_needed';
      analysis.recommendations.push({
        type: 'review_suggested',
        reason: 'Evergreen content not updated in over a year',
        priority: 'medium'
      });
    }
  }

  // If we detect the embedding has drifted significantly
  // this would require storing previous embeddings for comparison
  // For MVP, we flag based on metadata changes

  return analysis;
}

/**
 * Batch check all articles for decay signals
 */
async function checkAllArticlesForDecay() {
  const articles = await getAllArticles();
  const now = Date.now();

  const results = {
    healthy: [],
    needsReview: [],
    stale: [],
    summary: {}
  };

  for (const article of articles) {
    const meta = article.metadata || article;
    const updatedAt = meta.updatedAt ? new Date(meta.updatedAt).getTime() : 0;
    const daysSinceUpdate = (now - updatedAt) / (1000 * 60 * 60 * 24);
    const lifespan = meta.contentLifespan || 'evergreen';

    let status = 'healthy';
    let reason = null;

    // Check based on content lifespan
    if (lifespan === 'time-sensitive') {
      if (daysSinceUpdate > 180) {
        status = 'stale';
        reason = 'Time-sensitive content over 6 months old';
      } else if (daysSinceUpdate > 90) {
        status = 'needs_review';
        reason = 'Time-sensitive content over 3 months old';
      }
    } else if (lifespan === 'seasonal') {
      if (daysSinceUpdate > 365) {
        status = 'needs_review';
        reason = 'Seasonal content not updated this year';
      }
    } else {
      // Evergreen
      if (daysSinceUpdate > 730) {
        status = 'needs_review';
        reason = 'Content not updated in 2+ years';
      }
    }

    const item = {
      postId: meta.postId,
      title: meta.title,
      url: meta.url,
      cluster: meta.topicCluster,
      lifespan,
      daysSinceUpdate: Math.round(daysSinceUpdate),
      reason
    };

    if (status === 'stale') {
      results.stale.push(item);
    } else if (status === 'needs_review') {
      results.needsReview.push(item);
    } else {
      results.healthy.push(item);
    }
  }

  // Sort by age
  results.stale.sort((a, b) => b.daysSinceUpdate - a.daysSinceUpdate);
  results.needsReview.sort((a, b) => b.daysSinceUpdate - a.daysSinceUpdate);

  results.summary = {
    total: articles.length,
    healthy: results.healthy.length,
    needsReview: results.needsReview.length,
    stale: results.stale.length,
    healthPercentage: Math.round((results.healthy.length / articles.length) * 100)
  };

  return results;
}

/**
 * Find links that may have decayed
 * (Links from fresh content to stale content)
 */
async function findDecayedLinks() {
  const decayCheck = await checkAllArticlesForDecay();
  const staleIds = new Set(decayCheck.stale.map(a => a.postId));
  const articles = await getAllArticles();

  const decayedLinks = [];

  // Find fresh articles linking to stale content
  for (const article of articles) {
    const meta = article.metadata || article;
    const updatedAt = meta.updatedAt ? new Date(meta.updatedAt).getTime() : 0;
    const daysSinceUpdate = (Date.now() - updatedAt) / (1000 * 60 * 60 * 24);

    // Fresh article (updated in last 90 days)
    if (daysSinceUpdate < 90) {
      // Check if it might link to stale content
      // In production, you'd check actual link data
      const sameCluster = decayCheck.stale.filter(s => s.cluster === meta.topicCluster);

      if (sameCluster.length > 0) {
        decayedLinks.push({
          freshArticle: {
            postId: meta.postId,
            title: meta.title,
            cluster: meta.topicCluster
          },
          potentialStaleLinks: sameCluster.map(s => ({
            postId: s.postId,
            title: s.title,
            daysOld: s.daysSinceUpdate
          }))
        });
      }
    }
  }

  return {
    decayedLinks,
    totalStale: decayCheck.stale.length,
    recommendation: decayedLinks.length > 0
      ? 'Review these articles and update or remove links to stale content'
      : 'No immediate link decay issues detected'
  };
}

/**
 * Get decay score for use in link scoring
 * Fresh content scores higher
 *
 * @param {Object} article - Article metadata
 * @returns {number} Decay score (0-20, higher = fresher)
 */
function getDecayScore(article) {
  const updatedAt = article.updatedAt ? new Date(article.updatedAt).getTime() : 0;
  const daysSinceUpdate = (Date.now() - updatedAt) / (1000 * 60 * 60 * 24);
  const lifespan = article.contentLifespan || 'evergreen';

  if (lifespan === 'evergreen') {
    if (daysSinceUpdate < 180) return 20;
    if (daysSinceUpdate < 365) return 15;
    if (daysSinceUpdate < 730) return 10;
    return 5;
  } else if (lifespan === 'time-sensitive') {
    if (daysSinceUpdate < 30) return 20;
    if (daysSinceUpdate < 60) return 15;
    if (daysSinceUpdate < 90) return 10;
    if (daysSinceUpdate < 180) return 5;
    return 0; // Stale time-sensitive content
  } else {
    // Seasonal
    if (daysSinceUpdate < 90) return 15;
    if (daysSinceUpdate < 180) return 10;
    return 5;
  }
}

module.exports = {
  checkArticleDecay,
  checkAllArticlesForDecay,
  findDecayedLinks,
  getDecayScore,
  DECAY_THRESHOLDS
};
