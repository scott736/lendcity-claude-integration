/**
 * Advanced SEO Scoring System
 *
 * Comprehensive SEO optimizations for internal linking:
 * 1. Anchor text diversity tracking - prevents over-optimization
 * 2. Keyword-anchor alignment - matches anchor to target's focus keywords
 * 3. Link position scoring - favors early content placement
 * 4. First link priority - tracks first anchor to each target
 * 5. Reciprocal link detection - penalizes A↔B patterns
 * 6. Internal PageRank modeling - calculates authority flow
 * 7. Content type restrictions - pages never link to posts
 */

const { getAllArticles, getArticle, updateMetadata } = require('./pinecone');

// In-memory cache for site-wide SEO data (refreshed periodically)
let seoCache = {
  anchorUsage: {},           // { "anchor text": { count: 5, targetIds: [1,2,3] } }
  reciprocalLinks: {},       // { "123-456": true } (postId pairs with reciprocal links)
  internalPageRank: {},      // { postId: 0.85 }
  firstLinkAnchors: {},      // { targetPostId: "first anchor used" }
  lastRefresh: null
};

const CACHE_TTL = 5 * 60 * 1000; // 5 minutes

/**
 * Refresh SEO cache from Pinecone data
 * Should be called before batch operations
 */
async function refreshSEOCache() {
  const now = Date.now();
  if (seoCache.lastRefresh && (now - seoCache.lastRefresh) < CACHE_TTL) {
    return seoCache; // Cache still valid
  }

  console.log('Refreshing SEO cache...');

  try {
    const allArticles = await getAllArticles({ limit: 5000 });

    // Reset cache
    seoCache = {
      anchorUsage: {},
      reciprocalLinks: {},
      internalPageRank: {},
      firstLinkAnchors: {},
      linkGraph: {},        // For PageRank calculation
      lastRefresh: now
    };

    // Build data from articles
    for (const article of allArticles) {
      const meta = article.metadata || article;
      const postId = meta.postId;

      // Track anchor phrases used to link TO this article
      if (meta.inboundAnchors && Array.isArray(meta.inboundAnchors)) {
        for (const anchor of meta.inboundAnchors) {
          const anchorLower = anchor.text?.toLowerCase() || anchor.toLowerCase();
          if (!seoCache.anchorUsage[anchorLower]) {
            seoCache.anchorUsage[anchorLower] = { count: 0, targetIds: [], sourceIds: [] };
          }
          seoCache.anchorUsage[anchorLower].count++;
          seoCache.anchorUsage[anchorLower].targetIds.push(postId);
          if (anchor.sourceId) {
            seoCache.anchorUsage[anchorLower].sourceIds.push(anchor.sourceId);
          }
        }
      }

      // Track outbound links for reciprocal detection and PageRank
      if (meta.outboundLinks && Array.isArray(meta.outboundLinks)) {
        seoCache.linkGraph[postId] = meta.outboundLinks.map(l => l.targetId || l);

        // Check for reciprocal links
        for (const targetId of seoCache.linkGraph[postId]) {
          const pairKey = [postId, targetId].sort().join('-');
          // Will be marked reciprocal when we find the reverse link
          if (seoCache.linkGraph[targetId]?.includes(postId)) {
            seoCache.reciprocalLinks[pairKey] = true;
          }
        }
      }

      // Initialize PageRank (will be calculated below)
      seoCache.internalPageRank[postId] = 1.0;
    }

    // Calculate Internal PageRank (simplified iterative algorithm)
    calculateInternalPageRank(allArticles);

    console.log(`SEO cache refreshed: ${Object.keys(seoCache.anchorUsage).length} anchors, ${Object.keys(seoCache.reciprocalLinks).length} reciprocal pairs`);

  } catch (error) {
    console.error('Failed to refresh SEO cache:', error.message);
  }

  return seoCache;
}

/**
 * Calculate Internal PageRank using iterative algorithm
 * Simplified version of Google's PageRank for internal links
 */
function calculateInternalPageRank(articles) {
  const dampingFactor = 0.85;
  const iterations = 20;
  const numArticles = articles.length;

  if (numArticles === 0) return;

  // Initialize all pages with equal rank
  const ranks = {};
  for (const article of articles) {
    const postId = article.metadata?.postId || article.postId;
    ranks[postId] = 1.0 / numArticles;
  }

  // Iteratively calculate PageRank
  for (let i = 0; i < iterations; i++) {
    const newRanks = {};

    for (const article of articles) {
      const postId = article.metadata?.postId || article.postId;
      let incomingRank = 0;

      // Sum up rank from all pages linking to this one
      for (const [sourceId, outboundLinks] of Object.entries(seoCache.linkGraph)) {
        if (outboundLinks && outboundLinks.includes(postId)) {
          const sourceOutboundCount = outboundLinks.length || 1;
          incomingRank += (ranks[sourceId] || 0) / sourceOutboundCount;
        }
      }

      // Apply damping factor
      newRanks[postId] = (1 - dampingFactor) / numArticles + dampingFactor * incomingRank;
    }

    // Update ranks
    Object.assign(ranks, newRanks);
  }

  // Normalize to 0-100 scale
  const maxRank = Math.max(...Object.values(ranks), 0.01);
  for (const postId of Object.keys(ranks)) {
    seoCache.internalPageRank[postId] = Math.round((ranks[postId] / maxRank) * 100);
  }
}

/**
 * Get anchor text diversity score
 * Penalizes overused anchor text to avoid Google penalties
 *
 * @param {string} anchorText - Proposed anchor text
 * @param {number} targetId - Target article ID
 * @returns {{ score: number, usage: number, recommendation: string }}
 */
function getAnchorDiversityScore(anchorText, targetId) {
  const anchorLower = anchorText.toLowerCase().trim();
  const usage = seoCache.anchorUsage[anchorLower];

  if (!usage) {
    // New anchor - perfect diversity
    return {
      score: 30,
      usage: 0,
      recommendation: 'Unique anchor text - excellent diversity'
    };
  }

  const count = usage.count;
  const alreadyPointsToTarget = usage.targetIds.includes(targetId);

  // Scoring based on usage frequency
  // Google looks for unnatural anchor text patterns
  if (count === 1) {
    return { score: 28, usage: count, recommendation: 'Low usage - good diversity' };
  } else if (count === 2) {
    return { score: 25, usage: count, recommendation: 'Moderate usage - acceptable' };
  } else if (count <= 5) {
    return { score: 20, usage: count, recommendation: 'Consider varying anchor text' };
  } else if (count <= 10) {
    return { score: 10, usage: count, recommendation: 'High usage - vary anchor text' };
  } else {
    // Over-optimization risk
    return {
      score: 0,
      usage: count,
      recommendation: `WARNING: Anchor used ${count} times - over-optimization risk`
    };
  }
}

/**
 * Calculate keyword-anchor alignment score
 * Checks how well the anchor matches the target's focus keywords
 *
 * @param {string} anchorText - Proposed anchor text
 * @param {object} target - Target article metadata
 * @returns {{ score: number, alignment: number, matchedKeywords: string[] }}
 */
function getKeywordAlignmentScore(anchorText, target) {
  const anchorLower = anchorText.toLowerCase();
  const anchorWords = anchorLower.split(/\s+/).filter(w => w.length > 2);

  // Gather target's keywords from various sources
  const targetKeywords = new Set();

  // From title
  if (target.title) {
    target.title.toLowerCase().split(/\s+/)
      .filter(w => w.length > 3)
      .forEach(w => targetKeywords.add(w.replace(/[^\w]/g, '')));
  }

  // From main topics
  if (target.mainTopics) {
    target.mainTopics.forEach(topic => {
      topic.toLowerCase().split(/\s+/)
        .filter(w => w.length > 3)
        .forEach(w => targetKeywords.add(w.replace(/[^\w]/g, '')));
    });
  }

  // From semantic keywords
  if (target.semanticKeywords) {
    target.semanticKeywords.forEach(kw => {
      kw.toLowerCase().split(/\s+/)
        .filter(w => w.length > 3)
        .forEach(w => targetKeywords.add(w.replace(/[^\w]/g, '')));
    });
  }

  // From topic cluster
  if (target.topicCluster) {
    target.topicCluster.split('-')
      .filter(w => w.length > 3)
      .forEach(w => targetKeywords.add(w));
  }

  if (targetKeywords.size === 0) {
    return { score: 15, alignment: 0.5, matchedKeywords: [] }; // Neutral if no keywords
  }

  // Count matching keywords
  const matchedKeywords = [];
  for (const word of anchorWords) {
    if (targetKeywords.has(word)) {
      matchedKeywords.push(word);
    }
  }

  const alignment = matchedKeywords.length / Math.min(anchorWords.length, targetKeywords.size);

  // Score based on alignment percentage
  let score;
  if (alignment >= 0.8) {
    score = 25; // Excellent alignment
  } else if (alignment >= 0.6) {
    score = 20; // Good alignment
  } else if (alignment >= 0.4) {
    score = 15; // Moderate alignment
  } else if (alignment >= 0.2) {
    score = 10; // Low alignment
  } else {
    score = 5; // Poor alignment
  }

  return { score, alignment: Math.round(alignment * 100) / 100, matchedKeywords };
}

/**
 * Calculate link position score
 * Links earlier in content carry more weight with Google
 *
 * @param {string} content - Full article content
 * @param {string} anchorText - The anchor text to find
 * @returns {{ score: number, position: string, percentile: number }}
 */
function getLinkPositionScore(content, anchorText) {
  const plainText = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const contentLength = plainText.length;
  const anchorLower = anchorText.toLowerCase();
  const textLower = plainText.toLowerCase();

  const position = textLower.indexOf(anchorLower);

  if (position === -1) {
    return { score: 10, position: 'not_found', percentile: 100 };
  }

  const percentile = Math.round((position / contentLength) * 100);

  // Score based on position in content
  // First 20% is prime real estate (above the fold)
  if (percentile <= 10) {
    return { score: 20, position: 'intro_prime', percentile };
  } else if (percentile <= 20) {
    return { score: 18, position: 'intro', percentile };
  } else if (percentile <= 40) {
    return { score: 15, position: 'early_body', percentile };
  } else if (percentile <= 60) {
    return { score: 12, position: 'mid_body', percentile };
  } else if (percentile <= 80) {
    return { score: 10, position: 'late_body', percentile };
  } else {
    return { score: 8, position: 'conclusion', percentile };
  }
}

/**
 * Check first link priority
 * Google primarily uses the first anchor text for ranking signals
 *
 * @param {number} sourceId - Source article ID
 * @param {number} targetId - Target article ID
 * @param {string} anchorText - Proposed anchor text
 * @param {Array} existingLinks - Existing links in source content
 * @returns {{ score: number, isFirstLink: boolean, existingAnchor: string|null }}
 */
function getFirstLinkScore(sourceId, targetId, anchorText, existingLinks = []) {
  // Check if source already links to target
  const existingLink = existingLinks.find(l =>
    l.targetId === targetId || l.postId === targetId
  );

  if (existingLink) {
    // Already have a link - this additional anchor won't help SEO
    return {
      score: 0,
      isFirstLink: false,
      existingAnchor: existingLink.anchor || existingLink.anchorText,
      recommendation: 'Target already linked - additional link has minimal SEO value'
    };
  }

  // This would be the first link - maximum value
  return {
    score: 15,
    isFirstLink: true,
    existingAnchor: null,
    recommendation: 'First link to target - full SEO value'
  };
}

/**
 * Check for reciprocal links and apply penalty
 * Excessive A↔B patterns look unnatural to Google
 *
 * @param {number} sourceId - Source article ID
 * @param {number} targetId - Target article ID
 * @returns {{ score: number, isReciprocal: boolean, recommendation: string }}
 */
function getReciprocalLinkScore(sourceId, targetId) {
  // Check if target already links to source
  const targetOutbound = seoCache.linkGraph[targetId] || [];
  const isReciprocal = targetOutbound.includes(sourceId);

  if (isReciprocal) {
    // Reciprocal link exists - apply penalty
    return {
      score: -15,
      isReciprocal: true,
      recommendation: 'Reciprocal link detected - consider one-way linking'
    };
  }

  // Check site-wide reciprocal ratio
  const pairKey = [sourceId, targetId].sort().join('-');
  if (seoCache.reciprocalLinks[pairKey]) {
    return {
      score: -10,
      isReciprocal: true,
      recommendation: 'Known reciprocal pair - minor penalty'
    };
  }

  return {
    score: 0,
    isReciprocal: false,
    recommendation: 'No reciprocal link - good'
  };
}

/**
 * Get Internal PageRank score for target
 * Linking FROM high-PR pages or TO low-PR pages has different values
 *
 * @param {number} sourceId - Source article ID
 * @param {number} targetId - Target article ID
 * @returns {{ score: number, sourcePR: number, targetPR: number }}
 */
function getPageRankScore(sourceId, targetId) {
  const sourcePR = seoCache.internalPageRank[sourceId] || 50;
  const targetPR = seoCache.internalPageRank[targetId] || 50;

  // High PR source linking to low PR target = good for distributing authority
  // This helps surface orphaned/deep content
  let score = 0;

  if (sourcePR >= 70 && targetPR <= 30) {
    score = 20; // High authority source boosting low authority target
  } else if (sourcePR >= 50 && targetPR <= 50) {
    score = 15; // Moderate boost
  } else if (targetPR >= 70) {
    score = 10; // Linking to authority page (pillar)
  } else {
    score = 5; // Neutral
  }

  return { score, sourcePR, targetPR };
}

/**
 * Check content type linking rules
 * PAGES should NEVER link to POSTS (user manages page links)
 * POSTS can link to both PAGES and POSTS
 *
 * @param {string} sourceType - Source content type ('page' or 'post')
 * @param {string} targetType - Target content type ('page' or 'post')
 * @returns {{ allowed: boolean, reason: string }}
 */
function checkContentTypeLinking(sourceType, targetType) {
  const sourceLower = (sourceType || 'post').toLowerCase();
  const targetLower = (targetType || 'post').toLowerCase();

  // Pages should NEVER link to posts
  if (sourceLower === 'page' && targetLower === 'post') {
    return {
      allowed: false,
      reason: 'Pages cannot link to posts - page links are managed manually'
    };
  }

  // Posts can link to anything
  if (sourceLower === 'post') {
    return {
      allowed: true,
      reason: targetLower === 'page' ? 'Post linking to page - allowed' : 'Post linking to post - allowed'
    };
  }

  // Pages linking to pages - allowed but typically managed manually
  if (sourceLower === 'page' && targetLower === 'page') {
    return {
      allowed: true,
      reason: 'Page linking to page - allowed (verify manual management)'
    };
  }

  return { allowed: true, reason: 'Default allow' };
}

/**
 * Calculate comprehensive SEO score for a link candidate
 * Combines all SEO factors into a single score
 *
 * @param {object} params - All parameters for scoring
 * @returns {object} Comprehensive SEO score breakdown
 */
async function calculateSEOScore(params) {
  const {
    sourceId,
    sourceType = 'post',
    targetId,
    targetType = 'post',
    target,
    anchorText,
    content,
    existingLinks = []
  } = params;

  // Ensure cache is fresh
  await refreshSEOCache();

  // Check content type rules first (hard filter)
  const contentTypeCheck = checkContentTypeLinking(sourceType, targetType);
  if (!contentTypeCheck.allowed) {
    return {
      totalSEOScore: -999, // Disqualified
      allowed: false,
      reason: contentTypeCheck.reason,
      breakdown: {
        contentType: { score: -999, reason: contentTypeCheck.reason }
      }
    };
  }

  // Calculate all SEO component scores
  const anchorDiversity = getAnchorDiversityScore(anchorText, targetId);
  const keywordAlignment = getKeywordAlignmentScore(anchorText, target);
  const linkPosition = getLinkPositionScore(content, anchorText);
  const firstLink = getFirstLinkScore(sourceId, targetId, anchorText, existingLinks);
  const reciprocal = getReciprocalLinkScore(sourceId, targetId);
  const pageRank = getPageRankScore(sourceId, targetId);

  // Calculate total SEO score
  const totalSEOScore =
    anchorDiversity.score +    // 0-30
    keywordAlignment.score +   // 0-25
    linkPosition.score +       // 0-20
    firstLink.score +          // 0-15
    reciprocal.score +         // -15 to 0
    pageRank.score;            // 0-20

  // Max possible: 110, Min possible: -15
  // Normalize to 0-100 scale
  const normalizedScore = Math.max(0, Math.min(100, ((totalSEOScore + 15) / 125) * 100));

  return {
    totalSEOScore: Math.round(normalizedScore),
    rawScore: totalSEOScore,
    allowed: true,
    breakdown: {
      anchorDiversity: {
        score: anchorDiversity.score,
        usage: anchorDiversity.usage,
        recommendation: anchorDiversity.recommendation
      },
      keywordAlignment: {
        score: keywordAlignment.score,
        alignment: keywordAlignment.alignment,
        matchedKeywords: keywordAlignment.matchedKeywords
      },
      linkPosition: {
        score: linkPosition.score,
        position: linkPosition.position,
        percentile: linkPosition.percentile
      },
      firstLink: {
        score: firstLink.score,
        isFirstLink: firstLink.isFirstLink,
        existingAnchor: firstLink.existingAnchor
      },
      reciprocal: {
        score: reciprocal.score,
        isReciprocal: reciprocal.isReciprocal,
        recommendation: reciprocal.recommendation
      },
      pageRank: {
        score: pageRank.score,
        sourcePR: pageRank.sourcePR,
        targetPR: pageRank.targetPR
      }
    }
  };
}

/**
 * Track anchor usage after a link is created
 * Updates the cache and optionally persists to Pinecone
 *
 * @param {string} anchorText - The anchor text used
 * @param {number} sourceId - Source article ID
 * @param {number} targetId - Target article ID
 * @param {boolean} persist - Whether to persist to Pinecone
 */
async function trackAnchorUsage(anchorText, sourceId, targetId, persist = true) {
  const anchorLower = anchorText.toLowerCase().trim();

  // Update cache
  if (!seoCache.anchorUsage[anchorLower]) {
    seoCache.anchorUsage[anchorLower] = { count: 0, targetIds: [], sourceIds: [] };
  }
  seoCache.anchorUsage[anchorLower].count++;
  seoCache.anchorUsage[anchorLower].targetIds.push(targetId);
  seoCache.anchorUsage[anchorLower].sourceIds.push(sourceId);

  // Update link graph for reciprocal detection
  if (!seoCache.linkGraph[sourceId]) {
    seoCache.linkGraph[sourceId] = [];
  }
  if (!seoCache.linkGraph[sourceId].includes(targetId)) {
    seoCache.linkGraph[sourceId].push(targetId);
  }

  // Check if this creates a reciprocal link
  if (seoCache.linkGraph[targetId]?.includes(sourceId)) {
    const pairKey = [sourceId, targetId].sort().join('-');
    seoCache.reciprocalLinks[pairKey] = true;
  }

  // Persist to Pinecone if requested
  if (persist) {
    try {
      const targetArticle = await getArticle(targetId);
      if (targetArticle) {
        const inboundAnchors = targetArticle.inboundAnchors || [];
        inboundAnchors.push({
          text: anchorText,
          sourceId,
          createdAt: new Date().toISOString()
        });

        await updateMetadata(targetId, { inboundAnchors });
      }

      const sourceArticle = await getArticle(sourceId);
      if (sourceArticle) {
        const outboundLinks = sourceArticle.outboundLinks || [];
        outboundLinks.push({
          targetId,
          anchor: anchorText,
          createdAt: new Date().toISOString()
        });

        await updateMetadata(sourceId, { outboundLinks });
      }
    } catch (error) {
      console.error('Failed to persist anchor tracking:', error.message);
    }
  }
}

/**
 * Get site-wide SEO health metrics
 * Useful for auditing overall link profile
 */
async function getSitewideSEOMetrics() {
  await refreshSEOCache();

  const anchorCounts = Object.values(seoCache.anchorUsage).map(a => a.count);
  const totalAnchors = Object.keys(seoCache.anchorUsage).length;
  const overusedAnchors = Object.entries(seoCache.anchorUsage)
    .filter(([_, data]) => data.count > 5)
    .map(([anchor, data]) => ({ anchor, count: data.count }));

  const reciprocalCount = Object.keys(seoCache.reciprocalLinks).length;
  const totalLinks = Object.values(seoCache.linkGraph)
    .reduce((sum, links) => sum + (links?.length || 0), 0);

  const pageRankValues = Object.values(seoCache.internalPageRank);
  const avgPageRank = pageRankValues.length > 0
    ? pageRankValues.reduce((a, b) => a + b, 0) / pageRankValues.length
    : 50;

  return {
    anchors: {
      total: totalAnchors,
      averageUsage: anchorCounts.length > 0
        ? Math.round(anchorCounts.reduce((a, b) => a + b, 0) / anchorCounts.length * 10) / 10
        : 0,
      overused: overusedAnchors.length,
      overusedList: overusedAnchors.slice(0, 10)
    },
    links: {
      total: totalLinks,
      reciprocal: reciprocalCount,
      reciprocalRatio: totalLinks > 0
        ? Math.round((reciprocalCount / totalLinks) * 100)
        : 0
    },
    pageRank: {
      average: Math.round(avgPageRank),
      distribution: {
        high: pageRankValues.filter(pr => pr >= 70).length,
        medium: pageRankValues.filter(pr => pr >= 30 && pr < 70).length,
        low: pageRankValues.filter(pr => pr < 30).length
      }
    },
    health: {
      anchorDiversity: overusedAnchors.length < 5 ? 'good' : overusedAnchors.length < 15 ? 'moderate' : 'poor',
      reciprocalRatio: reciprocalCount / totalLinks < 0.2 ? 'good' : reciprocalCount / totalLinks < 0.4 ? 'moderate' : 'poor'
    },
    lastRefresh: seoCache.lastRefresh
  };
}

/**
 * Filter candidates by content type rules
 * Removes posts from candidates when source is a page
 *
 * @param {Array} candidates - Array of candidate articles
 * @param {string} sourceType - Source content type
 * @returns {Array} Filtered candidates
 */
function filterByContentType(candidates, sourceType) {
  if (sourceType?.toLowerCase() !== 'page') {
    return candidates; // Posts can link to anything
  }

  // Pages can only link to pages
  return candidates.filter(c => {
    const meta = c.metadata || c;
    const targetType = (meta.contentType || 'post').toLowerCase();
    return targetType === 'page';
  });
}

module.exports = {
  refreshSEOCache,
  getAnchorDiversityScore,
  getKeywordAlignmentScore,
  getLinkPositionScore,
  getFirstLinkScore,
  getReciprocalLinkScore,
  getPageRankScore,
  checkContentTypeLinking,
  calculateSEOScore,
  trackAnchorUsage,
  getSitewideSEOMetrics,
  filterByContentType,
  // Export cache for testing
  _getCache: () => seoCache
};
