const { querySimilar, getArticle, incrementInboundLinks } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { analyzeContentForLinking, generateAnchorText } = require('../lib/claude');
const { getRecommendations, calculateHybridScoreWithSEO, filterByContentType } = require('../lib/scoring');
const {
  refreshSEOCache,
  trackAnchorUsage,
  calculateSEOScore,
  getSitewideSEOMetrics,
  getOrphanPagesReport,
  incrementalCacheUpdate,
  batchIncrementalCacheUpdate,
  trackLinkVelocity,
  getLinkVelocityScore,
  getEEATScore,
  getActionAnchorScore,
  getSemanticClusterScore,
  getBalancedRecommendations,
  ANCHOR_TYPES
} = require('../lib/seo-scoring');

// v2.1: Integrate previously unused modules
const { suggestEntityBasedLinks, getEntity } = require('../lib/knowledge-graph');
const { twoStageRetrieval, preFilterCandidates } = require('../lib/cross-encoder');
const { calculateSeasonalScore, applySeasonalBoosting } = require('../lib/seasonal-boosting');
const { getDecayScore, checkAllArticlesForDecay } = require('../lib/link-decay');

const cheerio = require('cheerio');

// ============================================================================
// RESPONSE CACHE (Perf #11)
// ============================================================================

// In-memory response cache with 24-hour TTL
const responseCache = new Map();
const RESPONSE_CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours

// Request deduplication map (Perf #8)
const pendingRequests = new Map();

/**
 * Generate cache key from request parameters
 */
function getCacheKey(postId, contentHash, maxLinks) {
  return `smart-link:${postId}:${contentHash}:${maxLinks}`;
}

/**
 * Simple content hash for cache key
 */
function hashContent(content) {
  let hash = 0;
  const str = content.slice(0, 1000); // Only hash first 1000 chars for speed
  for (let i = 0; i < str.length; i++) {
    const char = str.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash;
  }
  return hash.toString(36);
}

/**
 * Check if cached response is valid
 */
function getCachedResponse(cacheKey) {
  const cached = responseCache.get(cacheKey);
  if (cached && (Date.now() - cached.timestamp) < RESPONSE_CACHE_TTL) {
    return cached.data;
  }
  if (cached) {
    responseCache.delete(cacheKey);
  }
  return null;
}

/**
 * Store response in cache
 */
function setCachedResponse(cacheKey, data) {
  // Limit cache size to prevent memory issues
  if (responseCache.size > 1000) {
    // Remove oldest 100 entries
    const keys = Array.from(responseCache.keys()).slice(0, 100);
    keys.forEach(k => responseCache.delete(k));
  }
  responseCache.set(cacheKey, { data, timestamp: Date.now() });
}

// ============================================================================
// CONTENT PRE-PROCESSING (Perf #4)
// ============================================================================

/**
 * Pre-process content for Claude analysis
 * Strips unnecessary HTML, normalizes whitespace, extracts linkable sentences
 */
function preprocessContent(html) {
  const $ = cheerio.load(html);

  // Remove script, style, and other non-content elements
  $('script, style, noscript, iframe, svg, canvas').remove();

  // Extract text while preserving structure markers
  let processed = '';

  // Get headings for context
  $('h1, h2, h3, h4, h5, h6').each((_, el) => {
    processed += `[HEADING] ${$(el).text().trim()} [/HEADING]\n`;
  });

  // Get list items (good link candidates)
  $('li').each((_, el) => {
    const text = $(el).text().trim();
    if (text.length > 20 && text.length < 200) {
      processed += `[LIST] ${text} [/LIST]\n`;
    }
  });

  // Get paragraphs
  $('p').each((_, el) => {
    const text = $(el).text().trim();
    if (text.length > 50) {
      processed += `${text}\n\n`;
    }
  });

  // Normalize whitespace
  processed = processed.replace(/\s+/g, ' ').trim();

  // Limit size to reduce Claude token usage
  if (processed.length > 8000) {
    processed = processed.slice(0, 8000) + '...';
  }

  return processed;
}

/**
 * Extract existing links using cheerio (more reliable than regex)
 */
function extractExistingLinks(html) {
  const $ = cheerio.load(html);
  const links = [];

  $('a[href]').each((_, el) => {
    const $el = $(el);
    const href = $el.attr('href');
    const anchor = $el.text().trim();

    if (href && anchor) {
      links.push({
        url: href,
        anchor,
        // Extract target post ID if it's an internal link
        targetId: extractPostIdFromUrl(href)
      });
    }
  });

  return links;
}

/**
 * Extract post ID from internal URL
 */
function extractPostIdFromUrl(url) {
  // Handle various URL formats
  const patterns = [
    /[?&]p=(\d+)/,           // ?p=123
    /\/(\d+)\/?$/,            // /123/
    /post_id=(\d+)/          // post_id=123
  ];

  for (const pattern of patterns) {
    const match = url.match(pattern);
    if (match) return parseInt(match[1]);
  }

  return null;
}

// ============================================================================
// OPTIMIZED LINK INSERTION (Perf #12)
// ============================================================================

/**
 * Insert links into content using cheerio HTML parser
 * More reliable and efficient than string/regex replacement
 */
function insertLinksIntoContent(html, links) {
  const $ = cheerio.load(html, { decodeEntities: false });

  for (const link of links) {
    if (!link.anchorText || !link.url) continue;

    const anchorText = link.anchorText;
    let inserted = false;

    // Try to find exact text match in paragraphs
    $('p, li, td, div').each((_, el) => {
      if (inserted) return;

      const $el = $(el);
      const html = $el.html();

      if (!html) return;

      // Check if anchor text exists and isn't already linked
      const anchorLower = anchorText.toLowerCase();
      const textLower = $el.text().toLowerCase();

      if (textLower.includes(anchorLower)) {
        // Check if this text is already inside a link
        const existingLinks = $el.find('a').text().toLowerCase();
        if (existingLinks.includes(anchorLower)) return;

        // Create case-insensitive regex to find the anchor
        const escapedAnchor = anchorText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedAnchor})(?![^<]*>)`, 'i');

        if (regex.test(html)) {
          // v2.1: Add Schema.org itemprop for SEO - signals semantic relationship to search engines
          const newHtml = html.replace(regex, `<a href="${link.url}" itemprop="relatedLink">$1</a>`);
          $el.html(newHtml);
          inserted = true;
        }
      }
    });
  }

  return $.html();
}

// ============================================================================
// MAIN HANDLER
// ============================================================================

/**
 * Smart Link Endpoint
 * Main endpoint for generating internal link suggestions
 *
 * POST /api/smart-link
 *
 * Performance optimizations:
 * - Response caching (24h TTL)
 * - Request deduplication
 * - Parallel SEO score calculations
 * - Optimized content preprocessing
 * - Cheerio-based link insertion
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  // Edge caching headers for Vercel Edge Network
  // Cache successful responses for 5 minutes, stale-while-revalidate for 1 hour
  res.setHeader('Cache-Control', 's-maxage=300, stale-while-revalidate=3600');

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
      topicCluster,
      relatedClusters = [],
      funnelStage,
      targetPersona,
      contentType = 'post',
      maxLinks = 5,
      minScore = 40,
      excludeIds = [],
      useClaudeAnalysis = true,
      autoInsert = false,
      strictSilo = false,
      includeSEOMetrics = true,
      skipCache = false // Allow bypassing cache
    } = req.body;

    // Validate required fields
    if (!content) {
      return res.status(400).json({
        error: 'Missing required field: content'
      });
    }

    // Generate cache key
    const contentHash = hashContent(content);
    const cacheKey = getCacheKey(postId, contentHash, maxLinks);

    // Check cache (Perf #11)
    if (!skipCache) {
      const cachedResponse = getCachedResponse(cacheKey);
      if (cachedResponse) {
        console.log(`Cache hit for post ${postId}`);
        return res.status(200).json({
          ...cachedResponse,
          cached: true
        });
      }
    }

    // Request deduplication (Perf #8)
    if (pendingRequests.has(cacheKey)) {
      console.log(`Deduplicating request for post ${postId}`);
      try {
        const result = await pendingRequests.get(cacheKey);
        return res.status(200).json({ ...result, deduplicated: true });
      } catch (error) {
        // If pending request failed, continue with new request
      }
    }

    // Create promise for this request (for deduplication)
    const requestPromise = processSmartLinkRequest({
      postId,
      content,
      title,
      topicCluster,
      relatedClusters,
      funnelStage,
      targetPersona,
      contentType,
      maxLinks,
      minScore,
      excludeIds,
      useClaudeAnalysis,
      autoInsert,
      strictSilo,
      includeSEOMetrics
    });

    pendingRequests.set(cacheKey, requestPromise);

    try {
      const result = await requestPromise;

      // Cache successful response
      if (result.success) {
        setCachedResponse(cacheKey, result);
      }

      return res.status(200).json(result);

    } finally {
      // Clean up pending request
      pendingRequests.delete(cacheKey);
    }

  } catch (error) {
    console.error('Smart link error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Process smart link request (separated for deduplication)
 */
async function processSmartLinkRequest(params) {
  const {
    postId,
    content,
    title,
    topicCluster,
    relatedClusters,
    funnelStage,
    targetPersona,
    contentType,
    maxLinks,
    minScore,
    excludeIds,
    useClaudeAnalysis,
    autoInsert,
    strictSilo,
    includeSEOMetrics
  } = params;

  // Refresh SEO cache (uses extended TTL now - 15 min)
  if (includeSEOMetrics) {
    await refreshSEOCache();
  }

  // Build source article metadata
  const sourceArticle = {
    postId,
    title,
    content,
    topicCluster,
    relatedClusters,
    funnelStage,
    targetPersona,
    contentType: contentType.toLowerCase()
  };

  // Early exit for pages - they don't get automatic links
  if (contentType.toLowerCase() === 'page') {
    console.log(`Skipping smart linking for page ${postId} - pages are manually managed`);
    return {
      success: true,
      links: [],
      message: 'Pages do not receive automatic links - manage page links manually',
      contentType: 'page'
    };
  }

  // Early exit for fully-linked posts - skip expensive analysis
  const existingLinks = extractExistingLinks(content);
  const existingSmartLinks = existingLinks.filter(l => l.url && !l.url.startsWith('http://') || l.url.includes(process.env.SITE_DOMAIN || 'lendcity'));
  if (existingSmartLinks.length >= maxLinks) {
    console.log(`Skipping smart linking for post ${postId} - already has ${existingSmartLinks.length} links (max: ${maxLinks})`);
    return {
      success: true,
      links: [],
      message: `Post already has ${existingSmartLinks.length} internal links (max: ${maxLinks})`,
      skipped: true,
      existingLinkCount: existingSmartLinks.length
    };
  }

  // Step 1: Generate embedding for source content (Perf #9 - caching in embeddings.js)
  const contentText = extractBodyText(content);
  const embedding = await generateEmbedding(`${title || ''} ${contentText}`);

  // Step 2: Query Pinecone for similar articles + entity-based suggestions IN PARALLEL
  const excludeList = [...excludeIds];
  if (postId) excludeList.push(postId);

  // v2.2: Run vector search and entity-based search in parallel for better coverage
  const [vectorCandidates, entitySuggestions] = await Promise.all([
    querySimilar(embedding, {
      topK: 50,
      excludeIds: excludeList
    }),
    postId ? suggestEntityBasedLinks(postId) : Promise.resolve([])
  ]);

  // Merge entity-based suggestions with vector candidates (deduplicate by postId)
  const seenPostIds = new Set(vectorCandidates.map(c => c.metadata?.postId || c.postId));
  const uniqueEntitySuggestions = entitySuggestions.filter(e => !seenPostIds.has(e.postId));

  // Convert entity suggestions to candidate format and boost their score for shared entities
  const entityCandidates = uniqueEntitySuggestions.map(e => ({
    ...e,
    metadata: { ...e, entityOverlap: e.entityOverlap, sharedEntities: e.sharedEntities },
    score: 0.5 + (e.entityOverlap * 0.1), // Base score + boost per shared entity
    sourceType: 'entity-graph'
  }));

  let candidates = [...vectorCandidates, ...entityCandidates];

  // Filter candidates by content type
  candidates = filterByContentType(candidates, contentType);

  if (candidates.length === 0) {
    return {
      success: true,
      links: [],
      message: 'No candidate articles found'
    };
  }

  // v2.2: Apply cross-encoder re-ranking for improved accuracy
  // Uses Claude to score relevance more accurately than vector similarity alone
  const { results: reRankedCandidates, stats: reRankStats } = await twoStageRetrieval(
    contentText,
    title || '',
    candidates,
    {
      preFilterThreshold: 0.25,  // Keep candidates with 25%+ vector similarity
      maxReRank: 20,             // Re-rank top 20 candidates
      finalTopK: maxLinks * 3   // Return 3x maxLinks for scoring pipeline
    }
  );

  // Use re-ranked candidates if available, otherwise fall back to original
  if (reRankedCandidates.length > 0) {
    candidates = reRankedCandidates;
    console.log(`Cross-encoder re-ranked ${reRankStats.reRanked} candidates from ${reRankStats.vectorCandidates} initial`);
  }

  // Step 3: Apply hybrid scoring
  const { recommendations, totalCandidates, passedFilter, averageScore } = getRecommendations(
    sourceArticle,
    candidates,
    { minScore, maxResults: maxLinks * 2, strictSilo }
  );

  if (recommendations.length === 0) {
    return {
      success: true,
      links: [],
      message: 'No articles passed scoring threshold',
      debug: { totalCandidates, minScore }
    };
  }

  // Step 3.5: Apply advanced scoring enhancements (v2.1)
  const enhancedRecommendations = recommendations.map(rec => {
    const target = rec.candidate;
    let enhancedScore = rec.totalScore;
    const enhancements = [];

    // Apply seasonal boosting
    const seasonalData = calculateSeasonalScore(target);
    if (seasonalData.isSeasonallyRelevant) {
      enhancedScore *= seasonalData.boostMultiplier;
      enhancements.push({ type: 'seasonal', boost: seasonalData.boostMultiplier, topics: seasonalData.matchedTopics });
    }

    // Apply decay scoring (fresher content scores higher)
    const decayScore = getDecayScore(target);
    enhancedScore += decayScore;
    enhancements.push({ type: 'freshness', score: decayScore });

    // Apply E-E-A-T scoring
    const eeatData = getEEATScore(postId, target.postId, target);
    enhancedScore += eeatData.score;
    if (eeatData.factors.length > 0) {
      enhancements.push({ type: 'eeat', score: eeatData.score, factors: eeatData.factors });
    }

    // Apply link velocity check (penalize if too aggressive)
    const velocityData = getLinkVelocityScore(postId);
    enhancedScore += velocityData.score;
    if (velocityData.status !== 'healthy') {
      enhancements.push({ type: 'velocity', score: velocityData.score, status: velocityData.status });
    }

    return {
      ...rec,
      totalScore: Math.round(enhancedScore),
      enhancements,
      seasonalBoost: seasonalData.isSeasonallyRelevant ? seasonalData.boostMultiplier : 1.0
    };
  });

  // Re-sort by enhanced scores
  enhancedRecommendations.sort((a, b) => b.totalScore - a.totalScore);

  // Apply semantic clustering for balanced recommendations
  const { recommendations: balancedRecs, distribution, isBalanced } = getBalancedRecommendations(
    enhancedRecommendations,
    { maxLinks: maxLinks * 2 }
  );

  // Step 4: Claude analysis with preprocessed content (Perf #4)
  let finalLinks = [];
  // Use balanced recommendations for better funnel coverage
  const recsToAnalyze = balancedRecs.length > 0 ? balancedRecs : enhancedRecommendations;

  if (useClaudeAnalysis && recsToAnalyze.length > 0) {
    // Preprocess content for Claude (Perf #4)
    const processedContent = preprocessContent(content);

    // Get existing links using cheerio (Perf #12)
    const existingLinks = extractExistingLinks(content);

    // Ask Claude to analyze and select best placements
    const analysis = await analyzeContentForLinking(
      processedContent, // Use preprocessed content
      recsToAnalyze.map(r => ({
        ...r.candidate,
        score: r.totalScore,
        enhancements: r.enhancements,
        seasonalBoost: r.seasonalBoost
      })),
      { maxLinks, existingLinks }
    );

    // Step 5: Calculate SEO scores IN PARALLEL (Perf #14)
    const seoPromises = analysis.links.map(async (link) => {
      const rec = recsToAnalyze[link.candidateIndex];

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
        seo: seoData ? {
          score: seoData.totalSEOScore,
          allowed: seoData.allowed,
          breakdown: seoData.breakdown
        } : null
      };
    });

    // Wait for all SEO calculations in parallel
    finalLinks = await Promise.all(seoPromises);

    // Re-sort by combined score (original + SEO boost)
    if (includeSEOMetrics) {
      finalLinks.sort((a, b) => {
        const aTotal = a.score + (a.seo?.score || 0) * 0.2;
        const bTotal = b.score + (b.seo?.score || 0) * 0.2;
        return bTotal - aTotal;
      });
    }

    // Limit to maxLinks
    finalLinks = finalLinks.slice(0, maxLinks);

  } else {
    // Without Claude, use balanced enhanced recommendations with title as anchor
    finalLinks = recsToAnalyze.slice(0, maxLinks).map(rec => ({
      postId: rec.candidate.postId,
      title: rec.candidate.title,
      url: rec.candidate.url,
      topicCluster: rec.candidate.topicCluster,
      contentType: rec.candidate.contentType || 'post',
      funnelStage: rec.candidate.funnelStage,
      score: rec.totalScore,
      scoreBreakdown: rec.breakdown,
      enhancements: rec.enhancements,
      anchorText: rec.candidate.title,
      placement: null,
      reasoning: 'Top-scored by enhanced hybrid algorithm (v2.1)',
      seo: null
    }));
  }

  // Step 6: Insert links if autoInsert enabled (using optimized cheerio method)
  let linkedContent = null;
  if (autoInsert && finalLinks.length > 0) {
    linkedContent = insertLinksIntoContent(content, finalLinks);

    // Use batch incremental cache update (faster than individual updates)
    batchIncrementalCacheUpdate(finalLinks.map(link => ({
      sourceId: postId,
      targetId: link.postId,
      anchorText: link.anchorText,
      targetMeta: { title: link.title, topicCluster: link.topicCluster }
    })));

    // Track link velocity (v2.1 - prevents over-optimization)
    for (const link of finalLinks) {
      trackLinkVelocity(postId, link.postId);
    }

    // Track in Pinecone in parallel (async persistence)
    const trackingPromises = finalLinks.map(link =>
      Promise.all([
        incrementInboundLinks(link.postId),
        trackAnchorUsage(link.anchorText, postId, link.postId, true)
      ])
    );

    // Don't await - let persistence happen in background
    Promise.all(trackingPromises).catch(err =>
      console.error('Background tracking error:', err.message)
    );
  }

  // Get site-wide SEO metrics if requested
  let seoMetrics = null;
  if (includeSEOMetrics) {
    seoMetrics = await getSitewideSEOMetrics();
  }

  // Get velocity report
  const velocityReport = getLinkVelocityScore(postId);

  return {
    success: true,
    links: finalLinks,
    linkedContent,
    stats: {
      candidatesFound: totalCandidates,
      passedScoring: passedFilter,
      averageScore,
      linksGenerated: finalLinks.length,
      // v2.1: Enhanced stats
      funnelDistribution: distribution,
      isBalanced,
      velocityStatus: velocityReport.status,
      // v2.2: Entity-graph and cross-encoder stats
      entityBasedCandidates: entityCandidates?.length || 0,
      crossEncoderReRanked: reRankStats?.reRanked || 0
    },
    seoSummary: includeSEOMetrics ? {
      sitewideHealth: seoMetrics?.health || null,
      anchorDiversityStatus: seoMetrics?.anchors?.overused > 5 ? 'warning' : 'good',
      anchorRatioHealth: seoMetrics?.anchors?.ratioHealth || 'unknown',
      reciprocalLinkRatio: seoMetrics?.links?.reciprocalRatio || 0,
      orphanPagesCount: seoMetrics?.orphanPages?.total || 0,
      // v2.1: Enhanced SEO metrics
      linkVelocity: velocityReport,
      recommendations: generateSEORecommendations(finalLinks, seoMetrics)
    } : null
  };
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

    // Check first link status
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

    // Check anchor type ratio warnings
    if (link.seo?.breakdown?.anchorRatio?.warning) {
      recommendations.push({
        type: 'anchor_ratio',
        severity: 'warning',
        message: link.seo.breakdown.anchorRatio.warning
      });
    }

    // Check content freshness
    if (link.seo?.breakdown?.relevanceDecay?.decay === 'stale') {
      recommendations.push({
        type: 'content_freshness',
        severity: 'info',
        target: link.title,
        message: `"${link.title}" hasn't been updated in over a year. Consider refreshing content.`
      });
    }

    // Check context quality
    if (link.seo?.breakdown?.contextQuality?.quality === 'poor') {
      recommendations.push({
        type: 'context_quality',
        severity: 'warning',
        target: link.title,
        message: `Link to "${link.title}" appears in low-quality context. Consider better placement.`
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

  // Orphan page recommendations
  if (seoMetrics?.orphanPages?.critical > 5) {
    recommendations.push({
      type: 'orphan_pages',
      severity: 'warning',
      message: `${seoMetrics.orphanPages.critical} pages have zero inbound links. Prioritize linking to orphan content.`
    });
  }

  // Anchor ratio warnings
  if (seoMetrics?.anchors?.ratioWarnings?.length > 0) {
    seoMetrics.anchors.ratioWarnings.forEach(warning => {
      recommendations.push({
        type: 'anchor_ratio',
        severity: 'info',
        message: warning
      });
    });
  }

  return recommendations;
}
