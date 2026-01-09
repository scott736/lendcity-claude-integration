/**
 * Advanced SEO Scoring System v2.0
 *
 * Comprehensive SEO optimizations for internal linking:
 * 1. Anchor text diversity tracking - prevents over-optimization
 * 2. Anchor text ratio monitoring - branded vs keyword-rich vs generic
 * 3. Keyword-anchor alignment - with stemming and synonym support
 * 4. Link position scoring - with semantic position awareness (headings, lists)
 * 5. First link priority - site-wide first link tracking
 * 6. Reciprocal link detection - penalizes A↔B patterns
 * 7. Internal PageRank modeling - with convergence detection and topic-sensitivity
 * 8. Link relevance decay - time-based scoring
 * 9. Orphan page detection and prioritization
 * 10. Link context quality scoring
 * 11. Competitor gap analysis integration
 * 12. Content type restrictions - pages never link to posts
 *
 * Performance optimizations:
 * - Extended cache TTL (15 minutes)
 * - Convergence-based PageRank (early exit when stable)
 * - Incremental cache updates
 * - Singleton pattern for article cache
 */

const { getAllArticles, getArticle, updateMetadata } = require('./pinecone');

// ============================================================================
// STEMMING AND SYNONYM SUPPORT
// ============================================================================

/**
 * Common English word stems for real estate domain
 * Maps inflected forms to base form
 */
const STEM_MAP = {
  // Investment terms
  'investing': 'invest', 'invested': 'invest', 'investor': 'invest', 'investors': 'invest', 'investment': 'invest', 'investments': 'invest',
  'financing': 'finance', 'financed': 'finance', 'financial': 'finance', 'finances': 'finance',
  'mortgages': 'mortgage', 'mortgaged': 'mortgage',
  'properties': 'property', 'rental': 'rent', 'rentals': 'rent', 'renting': 'rent', 'rented': 'rent',
  'refinancing': 'refinance', 'refinanced': 'refinance',
  'buying': 'buy', 'bought': 'buy', 'buyer': 'buy', 'buyers': 'buy',
  'selling': 'sell', 'sold': 'sell', 'seller': 'sell', 'sellers': 'sell',
  'lending': 'lend', 'lender': 'lend', 'lenders': 'lend', 'loans': 'loan', 'loaning': 'loan',
  'strategies': 'strategy', 'strategic': 'strategy',
  'analyzing': 'analyze', 'analysis': 'analyze', 'analyzed': 'analyze',
  'managing': 'manage', 'managed': 'manage', 'management': 'manage', 'manager': 'manage',
  'renovating': 'renovate', 'renovated': 'renovate', 'renovation': 'renovate', 'renovations': 'renovate',
  // Canadian specific
  'canadian': 'canada', 'canadians': 'canada',
};

/**
 * Domain-specific synonyms for real estate
 */
const SYNONYM_MAP = {
  'brrrr': ['buy-rehab-rent-refinance', 'buy-rehab-rent-refinance-repeat', 'brrrr-strategy', 'brrrr-method'],
  'roi': ['return-on-investment', 'returns', 'yield'],
  'cap-rate': ['capitalization-rate', 'cap'],
  'cash-flow': ['cashflow', 'cash-on-cash', 'monthly-income'],
  'down-payment': ['downpayment', 'deposit'],
  'private-lending': ['private-money', 'hard-money', 'alternative-lending'],
  'flip': ['flipping', 'house-flip', 'fix-and-flip'],
  'wholesaling': ['wholesale', 'assignment'],
  'multi-family': ['multifamily', 'apartment', 'duplex', 'triplex', 'fourplex'],
  'single-family': ['sfh', 'single-family-home', 'detached'],
  'appreciation': ['value-increase', 'equity-growth'],
  'leverage': ['leveraging', 'opm', 'other-peoples-money'],
};

/**
 * Get stem of a word
 */
function getStem(word) {
  const lower = word.toLowerCase();
  return STEM_MAP[lower] || lower;
}

/**
 * Get synonyms for a word/phrase
 */
function getSynonyms(word) {
  const lower = word.toLowerCase().replace(/\s+/g, '-');

  // Check direct match
  if (SYNONYM_MAP[lower]) {
    return [lower, ...SYNONYM_MAP[lower]];
  }

  // Check if word is a synonym of something
  for (const [base, synonyms] of Object.entries(SYNONYM_MAP)) {
    if (synonyms.includes(lower)) {
      return [base, ...synonyms];
    }
  }

  return [lower];
}

// ============================================================================
// ANCHOR TEXT TYPE CLASSIFICATION
// ============================================================================

/**
 * Anchor text type definitions for ratio monitoring
 */
const ANCHOR_TYPES = {
  BRANDED: 'branded',           // Contains brand name (LendCity)
  EXACT_MATCH: 'exact_match',   // Exact keyword match
  PARTIAL_MATCH: 'partial_match', // Contains target keyword
  GENERIC: 'generic',           // "click here", "learn more", "read more"
  NAKED_URL: 'naked_url',       // URL as anchor text
  NATURAL: 'natural'            // Natural phrase, mixed
};

/**
 * Generic anchor patterns to detect
 */
const GENERIC_ANCHORS = [
  'click here', 'learn more', 'read more', 'find out more', 'see more',
  'here', 'this article', 'this page', 'this post', 'more info',
  'check it out', 'discover more', 'get started', 'start here'
];

/**
 * Brand terms to detect
 */
const BRAND_TERMS = ['lendcity', 'lend city', 'lend-city'];

/**
 * Classify anchor text type
 * @param {string} anchorText - The anchor text to classify
 * @param {object} target - Target article metadata
 * @returns {string} Anchor type classification
 */
function classifyAnchorType(anchorText, target = {}) {
  const anchorLower = anchorText.toLowerCase().trim();

  // Check for naked URL
  if (anchorLower.startsWith('http') || anchorLower.startsWith('www.')) {
    return ANCHOR_TYPES.NAKED_URL;
  }

  // Check for branded
  if (BRAND_TERMS.some(brand => anchorLower.includes(brand))) {
    return ANCHOR_TYPES.BRANDED;
  }

  // Check for generic
  if (GENERIC_ANCHORS.some(generic => anchorLower === generic || anchorLower.includes(generic))) {
    return ANCHOR_TYPES.GENERIC;
  }

  // Check for exact match with target title or main topic
  const targetTitle = (target.title || '').toLowerCase();
  const targetTopics = (target.mainTopics || []).map(t => t.toLowerCase());

  if (anchorLower === targetTitle || targetTopics.includes(anchorLower)) {
    return ANCHOR_TYPES.EXACT_MATCH;
  }

  // Check for partial match
  const anchorWords = anchorLower.split(/\s+/);
  const titleWords = targetTitle.split(/\s+/).filter(w => w.length > 3);
  const matchCount = anchorWords.filter(w => titleWords.includes(w)).length;

  if (matchCount >= 2 || (matchCount === 1 && anchorWords.length <= 3)) {
    return ANCHOR_TYPES.PARTIAL_MATCH;
  }

  return ANCHOR_TYPES.NATURAL;
}

// ============================================================================
// CACHE MANAGEMENT
// ============================================================================

// Extended cache TTL for better performance (was 5 minutes)
const CACHE_TTL = 15 * 60 * 1000; // 15 minutes

// Convergence threshold for PageRank
const PAGERANK_CONVERGENCE_THRESHOLD = 0.0001;
const PAGERANK_MAX_ITERATIONS = 50;

// In-memory cache for site-wide SEO data (refreshed periodically)
let seoCache = {
  anchorUsage: {},           // { "anchor text": { count: 5, targetIds: [1,2,3], type: 'keyword' } }
  anchorTypeRatios: {},      // { branded: 0.3, exact_match: 0.2, ... }
  reciprocalLinks: {},       // { "123-456": true } (postId pairs with reciprocal links)
  internalPageRank: {},      // { postId: 0.85 }
  topicPageRank: {},         // { topicCluster: { postId: 0.85 } } - topic-sensitive PR
  firstLinkAnchors: {},      // { targetPostId: { anchor: "text", sourceId: 123 } } - site-wide first link
  dismissedOpportunities: {}, // { sourcePostId: { targetPostId: { dismissedAt, reason } } }
  orphanPages: [],           // Array of { postId, title, inboundCount }
  linkGraph: {},             // { sourceId: [targetId1, targetId2] }
  articleMetadata: {},       // { postId: { updatedAt, topicCluster, ... } } - for decay scoring
  competitorGaps: {},        // { keyword: { ranking: 5, potentialBoost: [...postIds] } }
  lastRefresh: null,
  lastIncrementalUpdate: null
};

// Singleton article cache to prevent multiple fetches
let articleCache = {
  articles: null,
  lastFetch: null,
  ttl: 10 * 60 * 1000 // 10 minutes
};

/**
 * Get all articles with singleton caching
 * Prevents multiple expensive Pinecone fetches
 */
async function getCachedArticles(forceRefresh = false) {
  const now = Date.now();

  if (!forceRefresh && articleCache.articles && articleCache.lastFetch &&
      (now - articleCache.lastFetch) < articleCache.ttl) {
    return articleCache.articles;
  }

  console.log('Fetching articles from Pinecone (singleton cache miss)...');
  articleCache.articles = await getAllArticles({ limit: 5000 });
  articleCache.lastFetch = now;

  return articleCache.articles;
}

/**
 * Refresh SEO cache from Pinecone data
 * Should be called before batch operations
 *
 * Performance: Uses extended TTL and singleton article cache
 */
async function refreshSEOCache(forceRefresh = false) {
  const now = Date.now();
  if (!forceRefresh && seoCache.lastRefresh && (now - seoCache.lastRefresh) < CACHE_TTL) {
    return seoCache; // Cache still valid
  }

  console.log('Refreshing SEO cache...');
  const startTime = Date.now();

  try {
    const allArticles = await getCachedArticles(forceRefresh);

    // Reset cache while preserving dismissed opportunities
    const preservedDismissed = seoCache.dismissedOpportunities;

    seoCache = {
      anchorUsage: {},
      anchorTypeRatios: {
        [ANCHOR_TYPES.BRANDED]: 0,
        [ANCHOR_TYPES.EXACT_MATCH]: 0,
        [ANCHOR_TYPES.PARTIAL_MATCH]: 0,
        [ANCHOR_TYPES.GENERIC]: 0,
        [ANCHOR_TYPES.NAKED_URL]: 0,
        [ANCHOR_TYPES.NATURAL]: 0
      },
      reciprocalLinks: {},
      internalPageRank: {},
      topicPageRank: {},
      firstLinkAnchors: {},
      dismissedOpportunities: preservedDismissed,
      orphanPages: [],
      linkGraph: {},
      articleMetadata: {},
      competitorGaps: {},
      lastRefresh: now,
      lastIncrementalUpdate: now
    };

    // Track anchor type counts for ratio calculation
    const anchorTypeCounts = {
      [ANCHOR_TYPES.BRANDED]: 0,
      [ANCHOR_TYPES.EXACT_MATCH]: 0,
      [ANCHOR_TYPES.PARTIAL_MATCH]: 0,
      [ANCHOR_TYPES.GENERIC]: 0,
      [ANCHOR_TYPES.NAKED_URL]: 0,
      [ANCHOR_TYPES.NATURAL]: 0
    };
    let totalAnchors = 0;

    // Build data from articles
    for (const article of allArticles) {
      const meta = article.metadata || article;
      const postId = meta.postId;

      // Store metadata for decay scoring
      seoCache.articleMetadata[postId] = {
        updatedAt: meta.updatedAt,
        publishedAt: meta.publishedAt,
        topicCluster: meta.topicCluster,
        inboundLinkCount: meta.inboundLinkCount || 0,
        title: meta.title,
        url: meta.url,
        isPillar: meta.isPillar
      };

      // Track orphan pages (0-2 inbound links)
      const inboundCount = meta.inboundLinkCount || 0;
      if (inboundCount <= 2) {
        seoCache.orphanPages.push({
          postId,
          title: meta.title,
          url: meta.url,
          inboundCount,
          topicCluster: meta.topicCluster
        });
      }

      // Track anchor phrases used to link TO this article
      if (meta.inboundAnchors && Array.isArray(meta.inboundAnchors)) {
        for (const anchor of meta.inboundAnchors) {
          const anchorText = anchor.text || (typeof anchor === 'string' ? anchor : '');
          const anchorLower = anchorText.toLowerCase();

          if (!anchorLower) continue;

          // Classify anchor type
          const anchorType = classifyAnchorType(anchorText, meta);
          anchorTypeCounts[anchorType]++;
          totalAnchors++;

          if (!seoCache.anchorUsage[anchorLower]) {
            seoCache.anchorUsage[anchorLower] = {
              count: 0,
              targetIds: [],
              sourceIds: [],
              type: anchorType,
              createdAt: anchor.createdAt || null
            };
          }
          seoCache.anchorUsage[anchorLower].count++;
          seoCache.anchorUsage[anchorLower].targetIds.push(postId);
          if (anchor.sourceId) {
            seoCache.anchorUsage[anchorLower].sourceIds.push(anchor.sourceId);

            // Track site-wide first link to each target
            if (!seoCache.firstLinkAnchors[postId]) {
              seoCache.firstLinkAnchors[postId] = {
                anchor: anchorText,
                sourceId: anchor.sourceId,
                createdAt: anchor.createdAt
              };
            } else if (anchor.createdAt && seoCache.firstLinkAnchors[postId].createdAt) {
              // Update if this link is older
              if (new Date(anchor.createdAt) < new Date(seoCache.firstLinkAnchors[postId].createdAt)) {
                seoCache.firstLinkAnchors[postId] = {
                  anchor: anchorText,
                  sourceId: anchor.sourceId,
                  createdAt: anchor.createdAt
                };
              }
            }
          }
        }
      }

      // Track outbound links for reciprocal detection and PageRank
      if (meta.outboundLinks && Array.isArray(meta.outboundLinks)) {
        seoCache.linkGraph[postId] = meta.outboundLinks.map(l => l.targetId || l);

        // Check for reciprocal links
        for (const targetId of seoCache.linkGraph[postId]) {
          const pairKey = [postId, targetId].sort().join('-');
          if (seoCache.linkGraph[targetId]?.includes(postId)) {
            seoCache.reciprocalLinks[pairKey] = true;
          }
        }
      }

      // Initialize PageRank
      seoCache.internalPageRank[postId] = 1.0;
    }

    // Calculate anchor type ratios
    if (totalAnchors > 0) {
      for (const type of Object.keys(anchorTypeCounts)) {
        seoCache.anchorTypeRatios[type] = Math.round((anchorTypeCounts[type] / totalAnchors) * 100);
      }
    }

    // Sort orphan pages by inbound count (most orphaned first)
    seoCache.orphanPages.sort((a, b) => a.inboundCount - b.inboundCount);

    // Calculate Internal PageRank with convergence detection
    calculateInternalPageRank(allArticles);

    // Calculate topic-sensitive PageRank
    calculateTopicPageRank(allArticles);

    const elapsed = Date.now() - startTime;
    console.log(`SEO cache refreshed in ${elapsed}ms: ${Object.keys(seoCache.anchorUsage).length} anchors, ${Object.keys(seoCache.reciprocalLinks).length} reciprocal pairs, ${seoCache.orphanPages.length} orphan pages`);

  } catch (error) {
    console.error('Failed to refresh SEO cache:', error.message);
  }

  return seoCache;
}

/**
 * Calculate Internal PageRank with convergence detection
 * Stops early if ranks stabilize (performance optimization)
 */
function calculateInternalPageRank(articles) {
  const dampingFactor = 0.85;
  const numArticles = articles.length;

  if (numArticles === 0) return;

  // Initialize all pages with equal rank
  const ranks = {};
  for (const article of articles) {
    const postId = article.metadata?.postId || article.postId;
    ranks[postId] = 1.0 / numArticles;
  }

  // Iteratively calculate PageRank with convergence check
  for (let i = 0; i < PAGERANK_MAX_ITERATIONS; i++) {
    const newRanks = {};
    let maxDelta = 0;

    for (const article of articles) {
      const postId = article.metadata?.postId || article.postId;
      const meta = article.metadata || article;
      let incomingRank = 0;

      // Sum up rank from all pages linking to this one
      for (const [sourceId, outboundLinks] of Object.entries(seoCache.linkGraph)) {
        if (outboundLinks && outboundLinks.includes(postId)) {
          const sourceOutboundCount = outboundLinks.length || 1;
          incomingRank += (ranks[sourceId] || 0) / sourceOutboundCount;
        }
      }

      // Apply damping factor with pillar page boost
      const pillarBoost = meta.isPillar ? 1.2 : 1.0;
      newRanks[postId] = ((1 - dampingFactor) / numArticles + dampingFactor * incomingRank) * pillarBoost;

      // Track convergence
      const delta = Math.abs(newRanks[postId] - (ranks[postId] || 0));
      if (delta > maxDelta) maxDelta = delta;
    }

    // Update ranks
    Object.assign(ranks, newRanks);

    // Check convergence - exit early if stable
    if (maxDelta < PAGERANK_CONVERGENCE_THRESHOLD) {
      console.log(`PageRank converged after ${i + 1} iterations (delta: ${maxDelta.toFixed(6)})`);
      break;
    }
  }

  // Normalize to 0-100 scale
  const maxRank = Math.max(...Object.values(ranks), 0.01);
  for (const postId of Object.keys(ranks)) {
    seoCache.internalPageRank[postId] = Math.round((ranks[postId] / maxRank) * 100);
  }
}

/**
 * Calculate topic-sensitive PageRank
 * Weights links within the same topic cluster higher
 */
function calculateTopicPageRank(articles) {
  // Group articles by topic cluster
  const clusterArticles = {};
  for (const article of articles) {
    const meta = article.metadata || article;
    const cluster = meta.topicCluster || 'general';
    if (!clusterArticles[cluster]) {
      clusterArticles[cluster] = [];
    }
    clusterArticles[cluster].push(article);
  }

  // Calculate PageRank per cluster
  for (const [cluster, clusterArts] of Object.entries(clusterArticles)) {
    if (clusterArts.length < 2) continue;

    seoCache.topicPageRank[cluster] = {};
    const ranks = {};
    const numInCluster = clusterArts.length;

    // Initialize
    for (const article of clusterArts) {
      const postId = article.metadata?.postId || article.postId;
      ranks[postId] = 1.0 / numInCluster;
    }

    // Iterate (fewer iterations for topic PR)
    for (let i = 0; i < 10; i++) {
      const newRanks = {};

      for (const article of clusterArts) {
        const postId = article.metadata?.postId || article.postId;
        let incomingRank = 0;

        // Only count links from within same cluster
        for (const sourceArticle of clusterArts) {
          const sourceId = sourceArticle.metadata?.postId || sourceArticle.postId;
          const outboundLinks = seoCache.linkGraph[sourceId] || [];

          if (outboundLinks.includes(postId)) {
            // Count only in-cluster outbound links
            const inClusterOutbound = outboundLinks.filter(tid =>
              clusterArts.some(a => (a.metadata?.postId || a.postId) === tid)
            ).length || 1;
            incomingRank += (ranks[sourceId] || 0) / inClusterOutbound;
          }
        }

        newRanks[postId] = 0.15 / numInCluster + 0.85 * incomingRank;
      }

      Object.assign(ranks, newRanks);
    }

    // Normalize
    const maxRank = Math.max(...Object.values(ranks), 0.01);
    for (const postId of Object.keys(ranks)) {
      seoCache.topicPageRank[cluster][postId] = Math.round((ranks[postId] / maxRank) * 100);
    }
  }
}

// ============================================================================
// SEO SCORING FUNCTIONS
// ============================================================================

/**
 * Get anchor text diversity score
 * Penalizes overused anchor text to avoid Google penalties
 */
function getAnchorDiversityScore(anchorText, targetId) {
  const anchorLower = anchorText.toLowerCase().trim();
  const usage = seoCache.anchorUsage[anchorLower];

  if (!usage) {
    return {
      score: 30,
      usage: 0,
      type: classifyAnchorType(anchorText),
      recommendation: 'Unique anchor text - excellent diversity'
    };
  }

  const count = usage.count;
  const anchorType = usage.type || classifyAnchorType(anchorText);

  // Scoring based on usage frequency
  let score;
  let recommendation;

  if (count === 1) {
    score = 28;
    recommendation = 'Low usage - good diversity';
  } else if (count === 2) {
    score = 25;
    recommendation = 'Moderate usage - acceptable';
  } else if (count <= 5) {
    score = 20;
    recommendation = 'Consider varying anchor text';
  } else if (count <= 10) {
    score = 10;
    recommendation = 'High usage - vary anchor text';
  } else {
    score = 0;
    recommendation = `WARNING: Anchor used ${count} times - over-optimization risk`;
  }

  return { score, usage: count, type: anchorType, recommendation };
}

/**
 * Get anchor text ratio health score
 * Monitors distribution of anchor types across the site
 *
 * Ideal ratios:
 * - Branded: 30-40%
 * - Keyword-rich (exact + partial): 30-40%
 * - Generic: <5%
 * - Natural/mixed: 20-30%
 */
function getAnchorRatioScore(anchorText, target = {}) {
  const anchorType = classifyAnchorType(anchorText, target);
  const ratios = seoCache.anchorTypeRatios;

  let score = 15; // Default neutral
  let recommendation = '';
  let warning = null;

  // Check if this anchor type is over-represented
  switch (anchorType) {
    case ANCHOR_TYPES.EXACT_MATCH:
      if (ratios[ANCHOR_TYPES.EXACT_MATCH] > 40) {
        score = 5;
        warning = 'Over-optimized: Too many exact match anchors';
      } else if (ratios[ANCHOR_TYPES.EXACT_MATCH] > 30) {
        score = 10;
        recommendation = 'Consider more natural anchor variations';
      } else {
        score = 20;
        recommendation = 'Exact match ratio healthy';
      }
      break;

    case ANCHOR_TYPES.GENERIC:
      if (ratios[ANCHOR_TYPES.GENERIC] > 10) {
        score = 5;
        warning = 'Too many generic anchors - use descriptive text';
      } else {
        score = 10;
        recommendation = 'Generic anchors acceptable but not ideal';
      }
      break;

    case ANCHOR_TYPES.BRANDED:
      if (ratios[ANCHOR_TYPES.BRANDED] < 20) {
        score = 20;
        recommendation = 'More branded anchors can help';
      } else if (ratios[ANCHOR_TYPES.BRANDED] > 50) {
        score = 10;
        recommendation = 'Consider more descriptive anchors';
      } else {
        score = 18;
        recommendation = 'Good branded anchor ratio';
      }
      break;

    case ANCHOR_TYPES.NATURAL:
      score = 20;
      recommendation = 'Natural anchor text - excellent';
      break;

    default:
      score = 15;
  }

  return { score, type: anchorType, ratios, recommendation, warning };
}

/**
 * Calculate keyword-anchor alignment score
 * Enhanced with stemming and synonym support
 */
function getKeywordAlignmentScore(anchorText, target) {
  const anchorLower = anchorText.toLowerCase();
  const anchorWords = anchorLower.split(/\s+/).filter(w => w.length > 2);

  // Gather target's keywords with stems
  const targetKeywords = new Set();
  const targetStems = new Set();

  // From title
  if (target.title) {
    target.title.toLowerCase().split(/\s+/)
      .filter(w => w.length > 3)
      .forEach(w => {
        const clean = w.replace(/[^\w]/g, '');
        targetKeywords.add(clean);
        targetStems.add(getStem(clean));
      });
  }

  // From main topics
  if (target.mainTopics) {
    target.mainTopics.forEach(topic => {
      topic.toLowerCase().split(/\s+/)
        .filter(w => w.length > 3)
        .forEach(w => {
          const clean = w.replace(/[^\w]/g, '');
          targetKeywords.add(clean);
          targetStems.add(getStem(clean));
        });

      // Add synonyms for topics
      getSynonyms(topic).forEach(syn => {
        syn.split('-').forEach(w => targetStems.add(getStem(w)));
      });
    });
  }

  // From semantic keywords
  if (target.semanticKeywords) {
    target.semanticKeywords.forEach(kw => {
      kw.toLowerCase().split(/\s+/)
        .filter(w => w.length > 3)
        .forEach(w => {
          const clean = w.replace(/[^\w]/g, '');
          targetKeywords.add(clean);
          targetStems.add(getStem(clean));
        });
    });
  }

  // From topic cluster
  if (target.topicCluster) {
    target.topicCluster.split('-')
      .filter(w => w.length > 3)
      .forEach(w => {
        targetKeywords.add(w);
        targetStems.add(getStem(w));
      });

    // Add cluster synonyms
    getSynonyms(target.topicCluster).forEach(syn => {
      syn.split('-').forEach(w => targetStems.add(getStem(w)));
    });
  }

  if (targetKeywords.size === 0 && targetStems.size === 0) {
    return { score: 15, alignment: 0.5, matchedKeywords: [], matchType: 'none' };
  }

  // Count matching keywords (direct and stemmed)
  const matchedKeywords = [];
  const matchedStems = [];

  for (const word of anchorWords) {
    const stem = getStem(word);

    if (targetKeywords.has(word)) {
      matchedKeywords.push(word);
    } else if (targetStems.has(stem)) {
      matchedStems.push(word);
    }
  }

  // Calculate alignment with bonus for stem matches
  const directMatches = matchedKeywords.length;
  const stemMatches = matchedStems.length;
  const totalMatches = directMatches + (stemMatches * 0.8); // Stem matches worth 80%

  const alignment = totalMatches / Math.min(anchorWords.length, Math.max(targetKeywords.size, targetStems.size));

  // Score based on alignment percentage
  let score;
  let matchType = 'none';

  if (directMatches > 0 && alignment >= 0.8) {
    score = 25;
    matchType = 'exact';
  } else if (alignment >= 0.8) {
    score = 22;
    matchType = 'stem';
  } else if (alignment >= 0.6) {
    score = 20;
    matchType = directMatches > 0 ? 'partial_exact' : 'partial_stem';
  } else if (alignment >= 0.4) {
    score = 15;
    matchType = 'low';
  } else if (alignment >= 0.2) {
    score = 10;
    matchType = 'minimal';
  } else {
    score = 5;
    matchType = 'poor';
  }

  return {
    score,
    alignment: Math.round(alignment * 100) / 100,
    matchedKeywords: [...matchedKeywords, ...matchedStems],
    matchType
  };
}

/**
 * Calculate link position score with semantic awareness
 * Enhanced to detect headings, lists, and callouts
 */
function getLinkPositionScore(content, anchorText) {
  const anchorLower = anchorText.toLowerCase();

  // Check if anchor is in a heading
  const headingMatch = content.match(new RegExp(`<h[1-6][^>]*>[^<]*${escapeRegex(anchorText)}[^<]*</h[1-6]>`, 'i'));
  if (headingMatch) {
    return {
      score: 25,
      position: 'heading',
      percentile: 0,
      semanticBonus: 'Link in heading - high visibility'
    };
  }

  // Check if anchor is in a list item
  const listMatch = content.match(new RegExp(`<li[^>]*>[^<]*${escapeRegex(anchorText)}`, 'i'));
  if (listMatch) {
    return {
      score: 22,
      position: 'list_item',
      percentile: 0,
      semanticBonus: 'Link in list - good scanability'
    };
  }

  // Check if in callout/blockquote
  const calloutMatch = content.match(new RegExp(`<(blockquote|aside|div class="[^"]*callout[^"]*")[^>]*>[^<]*${escapeRegex(anchorText)}`, 'i'));
  if (calloutMatch) {
    return {
      score: 20,
      position: 'callout',
      percentile: 0,
      semanticBonus: 'Link in callout - draws attention'
    };
  }

  // Calculate position in plain text
  const plainText = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const contentLength = plainText.length;
  const textLower = plainText.toLowerCase();
  const position = textLower.indexOf(anchorLower);

  if (position === -1) {
    return { score: 10, position: 'not_found', percentile: 100, semanticBonus: null };
  }

  const percentile = Math.round((position / contentLength) * 100);

  // Score based on position in content with granular scoring
  let score, positionLabel;

  if (percentile <= 5) {
    score = 20;
    positionLabel = 'intro_prime';
  } else if (percentile <= 10) {
    score = 19;
    positionLabel = 'intro';
  } else if (percentile <= 20) {
    score = 17;
    positionLabel = 'early_intro';
  } else if (percentile <= 30) {
    score = 15;
    positionLabel = 'early_body';
  } else if (percentile <= 50) {
    score = 13;
    positionLabel = 'mid_body';
  } else if (percentile <= 70) {
    score = 11;
    positionLabel = 'late_body';
  } else if (percentile <= 85) {
    score = 9;
    positionLabel = 'near_conclusion';
  } else {
    score = 7;
    positionLabel = 'conclusion';
  }

  return { score, position: positionLabel, percentile, semanticBonus: null };
}

/**
 * Check first link priority - SITE-WIDE
 * Tracks the first anchor used for each target across the entire site
 */
function getFirstLinkScore(sourceId, targetId, anchorText, existingLinks = []) {
  // Check if source already links to target (within this article)
  const existingLink = existingLinks.find(l =>
    l.targetId === targetId || l.postId === targetId
  );

  if (existingLink) {
    return {
      score: 0,
      isFirstLink: false,
      isFirstSitewide: false,
      existingAnchor: existingLink.anchor || existingLink.anchorText,
      recommendation: 'Target already linked in this article - additional link has minimal SEO value'
    };
  }

  // Check site-wide first link
  const siteFirstLink = seoCache.firstLinkAnchors[targetId];

  if (siteFirstLink) {
    // Someone else was first - check if anchor matches
    const anchorLower = anchorText.toLowerCase().trim();
    const firstAnchorLower = (siteFirstLink.anchor || '').toLowerCase().trim();

    if (anchorLower === firstAnchorLower) {
      return {
        score: 12,
        isFirstLink: true,
        isFirstSitewide: false,
        existingAnchor: siteFirstLink.anchor,
        firstLinkSource: siteFirstLink.sourceId,
        recommendation: 'Same anchor as first site-wide link - good consistency'
      };
    } else {
      return {
        score: 8,
        isFirstLink: true,
        isFirstSitewide: false,
        existingAnchor: siteFirstLink.anchor,
        firstLinkSource: siteFirstLink.sourceId,
        recommendation: `Different anchor than first site-wide link ("${siteFirstLink.anchor}") - may dilute signal`
      };
    }
  }

  // This would be the first site-wide link - maximum value
  return {
    score: 15,
    isFirstLink: true,
    isFirstSitewide: true,
    existingAnchor: null,
    recommendation: 'First link to target site-wide - full SEO value'
  };
}

/**
 * Check for reciprocal links and apply penalty
 */
function getReciprocalLinkScore(sourceId, targetId) {
  const targetOutbound = seoCache.linkGraph[targetId] || [];
  const isReciprocal = targetOutbound.includes(sourceId);

  if (isReciprocal) {
    return {
      score: -15,
      isReciprocal: true,
      recommendation: 'Reciprocal link detected - consider one-way linking'
    };
  }

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
 * Get Internal PageRank score with topic sensitivity
 */
function getPageRankScore(sourceId, targetId, topicCluster = null) {
  const sourcePR = seoCache.internalPageRank[sourceId] || 50;
  const targetPR = seoCache.internalPageRank[targetId] || 50;

  // Check topic-sensitive PageRank if available
  let topicBonus = 0;
  if (topicCluster && seoCache.topicPageRank[topicCluster]) {
    const sourceTopicPR = seoCache.topicPageRank[topicCluster][sourceId] || 50;
    const targetTopicPR = seoCache.topicPageRank[topicCluster][targetId] || 50;

    // Bonus for high authority within the cluster
    if (sourceTopicPR >= 70 && targetTopicPR <= 30) {
      topicBonus = 5;
    }
  }

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

  return {
    score: score + topicBonus,
    sourcePR,
    targetPR,
    topicBonus,
    topicCluster
  };
}

/**
 * Calculate link relevance decay based on time
 * Newer links have more SEO impact
 */
function getLinkRelevanceDecayScore(targetId) {
  const targetMeta = seoCache.articleMetadata[targetId];

  if (!targetMeta || !targetMeta.updatedAt) {
    return { score: 10, decay: 'unknown', daysSinceUpdate: null };
  }

  const updatedAt = new Date(targetMeta.updatedAt);
  const now = new Date();
  const daysSinceUpdate = Math.floor((now - updatedAt) / (1000 * 60 * 60 * 24));

  let score, decay;

  if (daysSinceUpdate <= 30) {
    score = 15;
    decay = 'fresh';
  } else if (daysSinceUpdate <= 90) {
    score = 12;
    decay = 'recent';
  } else if (daysSinceUpdate <= 180) {
    score = 10;
    decay = 'moderate';
  } else if (daysSinceUpdate <= 365) {
    score = 7;
    decay = 'aging';
  } else {
    score = 5;
    decay = 'stale';
  }

  return { score, decay, daysSinceUpdate };
}

/**
 * Get link context quality score
 * Evaluates the surrounding text quality around the link
 */
function getLinkContextScore(content, anchorText) {
  const anchorLower = anchorText.toLowerCase();
  const contentLower = content.toLowerCase();
  const position = contentLower.indexOf(anchorLower);

  if (position === -1) {
    return { score: 10, quality: 'not_found', context: null };
  }

  // Extract surrounding context (100 chars before and after)
  const start = Math.max(0, position - 100);
  const end = Math.min(content.length, position + anchorText.length + 100);
  const context = content.slice(start, end);

  let score = 15; // Default neutral
  let quality = 'neutral';
  const factors = [];

  // Check if context contains topic-relevant words
  const topicWords = ['invest', 'property', 'mortgage', 'financing', 'rental', 'strategy',
                      'real estate', 'brrrr', 'cash flow', 'roi', 'return'];
  const contextWordsLower = context.toLowerCase();
  const topicMatches = topicWords.filter(tw => contextWordsLower.includes(tw)).length;

  if (topicMatches >= 3) {
    score += 5;
    factors.push('highly relevant context');
    quality = 'excellent';
  } else if (topicMatches >= 1) {
    score += 2;
    factors.push('relevant context');
    quality = 'good';
  }

  // Check if in boilerplate sections (negative)
  const boilerplatePatterns = ['copyright', 'all rights reserved', 'privacy policy',
                               'terms of service', 'footer', 'sidebar'];
  if (boilerplatePatterns.some(bp => contextWordsLower.includes(bp))) {
    score -= 10;
    factors.push('boilerplate section detected');
    quality = 'poor';
  }

  // Check if preceded by action words (positive)
  const actionWords = ['learn', 'discover', 'explore', 'understand', 'master', 'see how'];
  if (actionWords.some(aw => contextWordsLower.slice(0, 100).includes(aw))) {
    score += 3;
    factors.push('preceded by action word');
  }

  return {
    score: Math.max(0, Math.min(25, score)),
    quality,
    factors,
    contextPreview: context.slice(0, 50) + '...'
  };
}

/**
 * Check content type linking rules
 */
function checkContentTypeLinking(sourceType, targetType) {
  const sourceLower = (sourceType || 'post').toLowerCase();
  const targetLower = (targetType || 'post').toLowerCase();

  if (sourceLower === 'page') {
    return {
      allowed: false,
      reason: 'Pages do not receive automatic link suggestions - all page links are managed manually'
    };
  }

  if (sourceLower === 'post') {
    return {
      allowed: true,
      reason: targetLower === 'page' ? 'Post linking to page - allowed' : 'Post linking to post - allowed'
    };
  }

  return { allowed: true, reason: 'Default allow' };
}

/**
 * Calculate comprehensive SEO score for a link candidate
 * Enhanced with all new scoring factors
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
      totalSEOScore: -999,
      allowed: false,
      reason: contentTypeCheck.reason,
      breakdown: {
        contentType: { score: -999, reason: contentTypeCheck.reason }
      }
    };
  }

  // Calculate all SEO component scores
  const anchorDiversity = getAnchorDiversityScore(anchorText, targetId);
  const anchorRatio = getAnchorRatioScore(anchorText, target);
  const keywordAlignment = getKeywordAlignmentScore(anchorText, target);
  const linkPosition = getLinkPositionScore(content, anchorText);
  const firstLink = getFirstLinkScore(sourceId, targetId, anchorText, existingLinks);
  const reciprocal = getReciprocalLinkScore(sourceId, targetId);
  const pageRank = getPageRankScore(sourceId, targetId, target?.topicCluster);
  const relevanceDecay = getLinkRelevanceDecayScore(targetId);
  const contextQuality = getLinkContextScore(content, anchorText);

  // Calculate total SEO score with new factors
  const totalSEOScore =
    anchorDiversity.score +    // 0-30
    anchorRatio.score +        // 0-20
    keywordAlignment.score +   // 0-25
    linkPosition.score +       // 0-25
    firstLink.score +          // 0-15
    reciprocal.score +         // -15 to 0
    pageRank.score +           // 0-25
    relevanceDecay.score +     // 0-15
    contextQuality.score;      // 0-25

  // Max possible: 180, Min possible: -15
  const normalizedScore = Math.max(0, Math.min(100, ((totalSEOScore + 15) / 195) * 100));

  return {
    totalSEOScore: Math.round(normalizedScore),
    rawScore: totalSEOScore,
    allowed: true,
    breakdown: {
      anchorDiversity: {
        score: anchorDiversity.score,
        usage: anchorDiversity.usage,
        type: anchorDiversity.type,
        recommendation: anchorDiversity.recommendation
      },
      anchorRatio: {
        score: anchorRatio.score,
        type: anchorRatio.type,
        siteRatios: anchorRatio.ratios,
        warning: anchorRatio.warning
      },
      keywordAlignment: {
        score: keywordAlignment.score,
        alignment: keywordAlignment.alignment,
        matchedKeywords: keywordAlignment.matchedKeywords,
        matchType: keywordAlignment.matchType
      },
      linkPosition: {
        score: linkPosition.score,
        position: linkPosition.position,
        percentile: linkPosition.percentile,
        semanticBonus: linkPosition.semanticBonus
      },
      firstLink: {
        score: firstLink.score,
        isFirstLink: firstLink.isFirstLink,
        isFirstSitewide: firstLink.isFirstSitewide,
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
        targetPR: pageRank.targetPR,
        topicBonus: pageRank.topicBonus
      },
      relevanceDecay: {
        score: relevanceDecay.score,
        decay: relevanceDecay.decay,
        daysSinceUpdate: relevanceDecay.daysSinceUpdate
      },
      contextQuality: {
        score: contextQuality.score,
        quality: contextQuality.quality,
        factors: contextQuality.factors
      }
    }
  };
}

/**
 * Track anchor usage after a link is created
 */
async function trackAnchorUsage(anchorText, sourceId, targetId, persist = true) {
  const anchorLower = anchorText.toLowerCase().trim();
  const anchorType = classifyAnchorType(anchorText);

  // Update cache
  if (!seoCache.anchorUsage[anchorLower]) {
    seoCache.anchorUsage[anchorLower] = { count: 0, targetIds: [], sourceIds: [], type: anchorType };
  }
  seoCache.anchorUsage[anchorLower].count++;
  seoCache.anchorUsage[anchorLower].targetIds.push(targetId);
  seoCache.anchorUsage[anchorLower].sourceIds.push(sourceId);

  // Update link graph
  if (!seoCache.linkGraph[sourceId]) {
    seoCache.linkGraph[sourceId] = [];
  }
  if (!seoCache.linkGraph[sourceId].includes(targetId)) {
    seoCache.linkGraph[sourceId].push(targetId);
  }

  // Track site-wide first link
  if (!seoCache.firstLinkAnchors[targetId]) {
    seoCache.firstLinkAnchors[targetId] = {
      anchor: anchorText,
      sourceId,
      createdAt: new Date().toISOString()
    };
  }

  // Check for reciprocal link
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
          type: anchorType,
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
 * Get orphan pages report
 * Returns pages with 0-2 inbound links, prioritized for linking
 */
async function getOrphanPagesReport(options = {}) {
  await refreshSEOCache();

  const { limit = 50, topicCluster = null } = options;

  let orphans = [...seoCache.orphanPages];

  // Filter by topic cluster if specified
  if (topicCluster) {
    orphans = orphans.filter(p => p.topicCluster === topicCluster);
  }

  return {
    total: orphans.length,
    pages: orphans.slice(0, limit),
    byCluster: orphans.reduce((acc, p) => {
      const cluster = p.topicCluster || 'uncategorized';
      acc[cluster] = (acc[cluster] || 0) + 1;
      return acc;
    }, {}),
    priority: orphans.filter(p => p.inboundCount === 0).length,
    recommendations: [
      orphans.length > 20 ? 'High number of orphan pages - consider bulk linking campaign' : null,
      orphans.filter(p => p.inboundCount === 0).length > 5 ? 'Critical: Multiple pages with zero inbound links' : null
    ].filter(Boolean)
  };
}

/**
 * Get site-wide SEO health metrics
 * Enhanced with anchor ratio monitoring and orphan page stats
 */
async function getSitewideSEOMetrics() {
  await refreshSEOCache();

  const anchorCounts = Object.values(seoCache.anchorUsage).map(a => a.count);
  const totalAnchors = Object.keys(seoCache.anchorUsage).length;
  const overusedAnchors = Object.entries(seoCache.anchorUsage)
    .filter(([_, data]) => data.count > 5)
    .map(([anchor, data]) => ({ anchor, count: data.count, type: data.type }));

  const reciprocalCount = Object.keys(seoCache.reciprocalLinks).length;
  const totalLinks = Object.values(seoCache.linkGraph)
    .reduce((sum, links) => sum + (links?.length || 0), 0);

  const pageRankValues = Object.values(seoCache.internalPageRank);
  const avgPageRank = pageRankValues.length > 0
    ? pageRankValues.reduce((a, b) => a + b, 0) / pageRankValues.length
    : 50;

  // Anchor ratio health check
  const ratios = seoCache.anchorTypeRatios;
  const keywordRichRatio = (ratios[ANCHOR_TYPES.EXACT_MATCH] || 0) + (ratios[ANCHOR_TYPES.PARTIAL_MATCH] || 0);

  let anchorRatioHealth = 'good';
  let anchorRatioWarnings = [];

  if (keywordRichRatio > 50) {
    anchorRatioHealth = 'warning';
    anchorRatioWarnings.push('Over 50% keyword-rich anchors - risk of over-optimization');
  }
  if ((ratios[ANCHOR_TYPES.GENERIC] || 0) > 10) {
    anchorRatioHealth = anchorRatioHealth === 'warning' ? 'poor' : 'warning';
    anchorRatioWarnings.push('Too many generic anchors (>10%) - use descriptive text');
  }
  if ((ratios[ANCHOR_TYPES.BRANDED] || 0) < 15) {
    anchorRatioWarnings.push('Low branded anchor ratio (<15%) - consider more brand mentions');
  }

  return {
    anchors: {
      total: totalAnchors,
      averageUsage: anchorCounts.length > 0
        ? Math.round(anchorCounts.reduce((a, b) => a + b, 0) / anchorCounts.length * 10) / 10
        : 0,
      overused: overusedAnchors.length,
      overusedList: overusedAnchors.slice(0, 10),
      typeRatios: ratios,
      ratioHealth: anchorRatioHealth,
      ratioWarnings: anchorRatioWarnings
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
    orphanPages: {
      total: seoCache.orphanPages.length,
      critical: seoCache.orphanPages.filter(p => p.inboundCount === 0).length,
      needsAttention: seoCache.orphanPages.filter(p => p.inboundCount <= 1).length
    },
    health: {
      anchorDiversity: overusedAnchors.length < 5 ? 'good' : overusedAnchors.length < 15 ? 'moderate' : 'poor',
      anchorRatios: anchorRatioHealth,
      reciprocalRatio: reciprocalCount / totalLinks < 0.2 ? 'good' : reciprocalCount / totalLinks < 0.4 ? 'moderate' : 'poor',
      orphanPages: seoCache.orphanPages.length < 10 ? 'good' : seoCache.orphanPages.length < 30 ? 'moderate' : 'poor'
    },
    lastRefresh: seoCache.lastRefresh
  };
}

/**
 * Filter candidates by content type rules
 */
function filterByContentType(candidates, sourceType) {
  if (sourceType?.toLowerCase() === 'page') {
    return [];
  }
  return candidates;
}

// ============================================================================
// DISMISS FUNCTIONALITY
// ============================================================================

async function dismissOpportunity(sourceId, targetId, reason = '', persist = true) {
  if (!seoCache.dismissedOpportunities[sourceId]) {
    seoCache.dismissedOpportunities[sourceId] = {};
  }

  seoCache.dismissedOpportunities[sourceId][targetId] = {
    dismissedAt: new Date().toISOString(),
    reason: reason || 'User dismissed'
  };

  if (persist) {
    try {
      const sourceArticle = await getArticle(sourceId);
      if (sourceArticle) {
        const dismissedLinks = sourceArticle.dismissedLinks || [];
        const existingIndex = dismissedLinks.findIndex(d => d.targetId === targetId);

        if (existingIndex >= 0) {
          dismissedLinks[existingIndex] = {
            targetId,
            dismissedAt: new Date().toISOString(),
            reason: reason || 'User dismissed'
          };
        } else {
          dismissedLinks.push({
            targetId,
            dismissedAt: new Date().toISOString(),
            reason: reason || 'User dismissed'
          });
        }

        await updateMetadata(sourceId, { dismissedLinks });
      }
    } catch (error) {
      console.error('Failed to persist dismissed opportunity:', error.message);
      return { success: false, error: error.message };
    }
  }

  return {
    success: true,
    sourceId,
    targetId,
    dismissedAt: seoCache.dismissedOpportunities[sourceId][targetId].dismissedAt
  };
}

async function restoreOpportunity(sourceId, targetId, persist = true) {
  if (seoCache.dismissedOpportunities[sourceId]) {
    delete seoCache.dismissedOpportunities[sourceId][targetId];
  }

  if (persist) {
    try {
      const sourceArticle = await getArticle(sourceId);
      if (sourceArticle) {
        const dismissedLinks = (sourceArticle.dismissedLinks || [])
          .filter(d => d.targetId !== targetId);
        await updateMetadata(sourceId, { dismissedLinks });
      }
    } catch (error) {
      console.error('Failed to persist restored opportunity:', error.message);
      return { success: false, error: error.message };
    }
  }

  return { success: true, sourceId, targetId, restored: true };
}

async function clearDismissedOpportunities(sourceId, persist = true) {
  const count = Object.keys(seoCache.dismissedOpportunities[sourceId] || {}).length;
  seoCache.dismissedOpportunities[sourceId] = {};

  if (persist) {
    try {
      await updateMetadata(sourceId, { dismissedLinks: [] });
    } catch (error) {
      console.error('Failed to clear dismissed opportunities:', error.message);
      return { success: false, error: error.message };
    }
  }

  return { success: true, sourceId, clearedCount: count };
}

async function getDismissedOpportunities(sourceId) {
  if (seoCache.dismissedOpportunities[sourceId]) {
    return Object.entries(seoCache.dismissedOpportunities[sourceId]).map(([targetId, data]) => ({
      targetId: parseInt(targetId),
      ...data
    }));
  }

  try {
    const sourceArticle = await getArticle(sourceId);
    if (sourceArticle && sourceArticle.dismissedLinks) {
      seoCache.dismissedOpportunities[sourceId] = {};
      for (const dismissed of sourceArticle.dismissedLinks) {
        seoCache.dismissedOpportunities[sourceId][dismissed.targetId] = {
          dismissedAt: dismissed.dismissedAt,
          reason: dismissed.reason
        };
      }
      return sourceArticle.dismissedLinks;
    }
  } catch (error) {
    console.error('Failed to get dismissed opportunities:', error.message);
  }

  return [];
}

function isOpportunityDismissed(sourceId, targetId) {
  return !!(seoCache.dismissedOpportunities[sourceId]?.[targetId]);
}

function filterDismissedOpportunities(sourceId, opportunities) {
  const dismissed = seoCache.dismissedOpportunities[sourceId] || {};
  return opportunities.filter(opp => {
    const targetId = opp.postId || opp.targetId;
    return !dismissed[targetId];
  });
}

// ============================================================================
// COMPETITOR GAP ANALYSIS
// ============================================================================

/**
 * Store competitor gap data for a keyword
 * Integration point for external SEO tools (Ahrefs, SEMrush, etc.)
 */
async function setCompetitorGap(keyword, data) {
  seoCache.competitorGaps[keyword.toLowerCase()] = {
    ...data,
    updatedAt: new Date().toISOString()
  };
  return { success: true };
}

/**
 * Get pages that could benefit from internal link boost for a keyword
 */
function getCompetitorGapOpportunities(keyword) {
  const gap = seoCache.competitorGaps[keyword.toLowerCase()];
  if (!gap) return null;

  return {
    keyword,
    currentRanking: gap.ranking,
    potentialPages: gap.potentialBoost || [],
    recommendation: gap.ranking <= 10
      ? 'Already ranking - maintain with consistent linking'
      : gap.ranking <= 20
        ? 'Near first page - prioritize internal links to push to page 1'
        : 'Needs significant link boost - consider content refresh + linking campaign',
    updatedAt: gap.updatedAt
  };
}

/**
 * Get all competitor gap opportunities
 */
function getAllCompetitorGaps() {
  return Object.keys(seoCache.competitorGaps).map(keyword =>
    getCompetitorGapOpportunities(keyword)
  ).filter(Boolean);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Escape special regex characters
 */
function escapeRegex(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Force refresh the cache
 */
async function forceRefreshCache() {
  articleCache.articles = null;
  articleCache.lastFetch = null;
  seoCache.lastRefresh = null;
  return await refreshSEOCache(true);
}

// ============================================================================
// EXPORTS
// ============================================================================

module.exports = {
  // Core functions
  refreshSEOCache,
  forceRefreshCache,
  calculateSEOScore,
  trackAnchorUsage,
  getSitewideSEOMetrics,
  filterByContentType,
  checkContentTypeLinking,

  // Anchor analysis
  getAnchorDiversityScore,
  getAnchorRatioScore,
  classifyAnchorType,
  ANCHOR_TYPES,

  // Keyword alignment
  getKeywordAlignmentScore,
  getStem,
  getSynonyms,

  // Position and context
  getLinkPositionScore,
  getLinkContextScore,

  // Link analysis
  getFirstLinkScore,
  getReciprocalLinkScore,
  getPageRankScore,
  getLinkRelevanceDecayScore,

  // Orphan pages
  getOrphanPagesReport,

  // Competitor gaps
  setCompetitorGap,
  getCompetitorGapOpportunities,
  getAllCompetitorGaps,

  // Dismiss functionality
  dismissOpportunity,
  restoreOpportunity,
  clearDismissedOpportunities,
  getDismissedOpportunities,
  isOpportunityDismissed,
  filterDismissedOpportunities,

  // Cache access for testing
  _getCache: () => seoCache,
  _getArticleCache: () => articleCache
};
