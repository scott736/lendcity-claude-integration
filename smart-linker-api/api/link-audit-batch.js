const { querySimilar, getArticle, getAllArticles } = require('../lib/pinecone');
const { generateEmbeddings, generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { getRecommendations, filterByContentType, checkContentTypeLinking } = require('../lib/scoring');
const {
  refreshSEOCache,
  calculateSEOScore,
  getSitewideSEOMetrics,
  getAnchorDiversityScore,
  getReciprocalLinkScore,
  getDismissedOpportunities,
  filterDismissedOpportunities
} = require('../lib/seo-scoring');

/**
 * Batch Link Audit Endpoint
 * v6.3: Processes multiple articles in parallel with batched embeddings
 *
 * POST /api/link-audit-batch
 *
 * Optimizations:
 * - Single SEO cache refresh for entire batch
 * - Batched embedding generation
 * - Parallel article processing
 * - Shared site-wide metrics
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
    const { articles, includeSEOMetrics = true } = req.body;

    if (!articles || !Array.isArray(articles) || articles.length === 0) {
      return res.status(400).json({
        error: 'Missing required field: articles (array)'
      });
    }

    // Limit batch size to prevent timeouts
    const MAX_BATCH_SIZE = 10;
    if (articles.length > MAX_BATCH_SIZE) {
      return res.status(400).json({
        error: `Batch size exceeds maximum of ${MAX_BATCH_SIZE} articles`
      });
    }

    console.log(`Batch link audit: ${articles.length} articles`);
    const startTime = Date.now();

    // Step 1: Single SEO cache refresh for entire batch
    if (includeSEOMetrics) {
      await refreshSEOCache();
    }

    // Step 2: Get site-wide metrics once
    let sitewideMetrics = null;
    if (includeSEOMetrics) {
      sitewideMetrics = await getSitewideSEOMetrics();
    }

    // Step 3: Collect all texts that need embeddings
    const embeddingTexts = [];
    const embeddingMap = {}; // Maps index to { type, articleIndex, linkIndex? }

    articles.forEach((article, articleIndex) => {
      // Content embedding for finding missing opportunities
      const contentText = extractBodyText(article.content || '');
      embeddingTexts.push(`${article.title || ''} ${contentText}`);
      embeddingMap[embeddingTexts.length - 1] = { type: 'content', articleIndex };

      // Anchor embeddings for existing links (only if we need to check for better targets)
      if (article.existingLinks && article.existingLinks.length > 0) {
        article.existingLinks.forEach((link, linkIndex) => {
          embeddingTexts.push(link.anchor || '');
          embeddingMap[embeddingTexts.length - 1] = { type: 'anchor', articleIndex, linkIndex };
        });
      }
    });

    // Step 4: Generate all embeddings in single batch call
    console.log(`Generating ${embeddingTexts.length} embeddings in batch...`);
    const allEmbeddings = await generateEmbeddings(embeddingTexts);

    // Step 5: Process all articles in parallel
    console.log(`Processing ${articles.length} articles in parallel...`);
    const auditPromises = articles.map(async (article, articleIndex) => {
      try {
        const {
          postId,
          content,
          title,
          existingLinks = [],
          topicCluster,
          contentType = 'post',
          maxSuggestions = 5
        } = article;

        if (!content) {
          return {
            postId,
            success: false,
            error: 'content is required'
          };
        }

        const sourceType = contentType.toLowerCase();
        const isPage = sourceType === 'page';

        const audit = {
          existing: {
            total: existingLinks.length,
            valid: [],
            broken: [],
            suboptimal: [],
            contentTypeViolations: []
          },
          suggestions: {
            upgrades: [],
            missing: [],
            redundant: []
          },
          seo: {
            anchorDiversity: [],
            reciprocalLinks: [],
            recommendations: []
          },
          stats: {}
        };

        if (isPage) {
          audit.note = 'Page content - link suggestions disabled.';
        }

        // Get content embedding from batch results
        const contentEmbeddingIndex = Object.entries(embeddingMap)
          .find(([idx, map]) => map.type === 'content' && map.articleIndex === articleIndex)?.[0];
        const contentEmbedding = contentEmbeddingIndex !== undefined
          ? allEmbeddings[parseInt(contentEmbeddingIndex)]
          : null;

        // Process existing links
        for (let linkIndex = 0; linkIndex < existingLinks.length; linkIndex++) {
          const link = existingLinks[linkIndex];
          const targetArticle = await getArticle(link.targetId);

          if (!targetArticle) {
            audit.existing.broken.push({
              ...link,
              issue: 'Target article not found in catalog',
              action: 'Consider removing or updating this link'
            });
            continue;
          }

          const targetType = (targetArticle.contentType || 'post').toLowerCase();

          // Check content type rules
          const contentTypeCheck = checkContentTypeLinking(sourceType, targetType);
          if (!contentTypeCheck.allowed) {
            audit.existing.contentTypeViolations.push({
              ...link,
              sourceType,
              targetType,
              target: {
                title: targetArticle.title,
                url: targetArticle.url,
                contentType: targetType
              },
              issue: contentTypeCheck.reason,
              action: 'REMOVE this link - pages should not link to posts',
              severity: 'error'
            });
            continue;
          }

          // SEO Analysis
          if (includeSEOMetrics) {
            const diversityScore = getAnchorDiversityScore(link.anchor, link.targetId);
            if (diversityScore.usage > 3) {
              audit.seo.anchorDiversity.push({
                anchor: link.anchor,
                target: targetArticle.title,
                usage: diversityScore.usage,
                recommendation: diversityScore.recommendation
              });
            }

            const reciprocalCheck = getReciprocalLinkScore(postId, link.targetId);
            if (reciprocalCheck.isReciprocal) {
              audit.seo.reciprocalLinks.push({
                source: postId,
                target: link.targetId,
                targetTitle: targetArticle.title,
                recommendation: reciprocalCheck.recommendation
              });
            }
          }

          // Get anchor embedding from batch results
          const anchorEmbeddingIndex = Object.entries(embeddingMap)
            .find(([idx, map]) =>
              map.type === 'anchor' &&
              map.articleIndex === articleIndex &&
              map.linkIndex === linkIndex
            )?.[0];

          const anchorEmbedding = anchorEmbeddingIndex !== undefined
            ? allEmbeddings[parseInt(anchorEmbeddingIndex)]
            : null;

          // Check for better targets (simplified - skip if no embedding)
          if (anchorEmbedding) {
            let betterMatches = await querySimilar(anchorEmbedding, {
              topK: 5,
              excludeIds: [postId, link.targetId]
            });

            betterMatches = filterByContentType(betterMatches, sourceType);

            const currentScore = targetArticle.qualityScore || 50;
            const betterOptions = betterMatches.filter(match => {
              const matchScore = match.metadata?.qualityScore || 50;
              const similarity = match.score || 0;
              return matchScore > currentScore && similarity > 0.75;
            });

            if (betterOptions.length > 0) {
              audit.existing.suboptimal.push({
                ...link,
                currentTarget: {
                  title: targetArticle.title,
                  url: targetArticle.url,
                  qualityScore: currentScore,
                  contentType: targetType
                },
                betterOptions: betterOptions.slice(0, 2).map(opt => ({
                  postId: opt.metadata.postId,
                  title: opt.metadata.title,
                  url: opt.metadata.url,
                  qualityScore: opt.metadata.qualityScore || 50,
                  contentType: opt.metadata.contentType || 'post',
                  similarity: Math.round(opt.score * 100)
                }))
              });
            } else {
              audit.existing.valid.push({
                ...link,
                target: {
                  title: targetArticle.title,
                  qualityScore: currentScore,
                  topicCluster: targetArticle.topicCluster,
                  contentType: targetType
                },
                status: 'optimal'
              });
            }
          } else {
            // No embedding - just mark as valid
            audit.existing.valid.push({
              ...link,
              target: {
                title: targetArticle.title,
                qualityScore: targetArticle.qualityScore || 50,
                topicCluster: targetArticle.topicCluster,
                contentType: targetType
              },
              status: 'valid'
            });
          }
        }

        // Find missing opportunities (only for posts, not pages)
        if (!isPage && contentEmbedding) {
          const excludeIds = [postId, ...existingLinks.map(l => l.targetId)];
          let candidates = await querySimilar(contentEmbedding, {
            topK: 20,
            excludeIds
          });

          candidates = filterByContentType(candidates, sourceType);

          if (candidates.length > 0) {
            const sourceArticle = { postId, title, topicCluster, contentType: sourceType };
            const { recommendations } = getRecommendations(
              sourceArticle,
              candidates,
              { minScore: 50, maxResults: maxSuggestions }
            );

            // Simplified: Just return top recommendations without complex anchor finding
            // Full anchor analysis can be done when user clicks to add link
            audit.suggestions.missing = recommendations.slice(0, maxSuggestions).map(rec => ({
              postId: rec.candidate.postId,
              title: rec.candidate.title,
              url: rec.candidate.url,
              topicCluster: rec.candidate.topicCluster,
              contentType: rec.candidate.contentType || 'post',
              score: rec.totalScore,
              reason: `${rec.relevanceScore}% relevant, same cluster: ${rec.candidate.topicCluster === topicCluster}`
            }));
          }
        }

        // Check for redundant links
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
              suggestion: `Consider reducing links to "${cluster}" cluster`
            });
          }
        }

        // Calculate stats
        audit.stats = {
          totalLinks: existingLinks.length,
          validLinks: audit.existing.valid.length,
          brokenLinks: audit.existing.broken.length,
          suboptimalLinks: audit.existing.suboptimal.length,
          contentTypeViolations: audit.existing.contentTypeViolations.length,
          missingOpportunities: audit.suggestions.missing.length,
          healthScore: existingLinks.length > 0
            ? Math.round((audit.existing.valid.length / existingLinks.length) * 100)
            : 100
        };

        return {
          postId,
          success: true,
          contentType: sourceType,
          audit
        };

      } catch (error) {
        console.error(`Audit failed for article ${article.postId}:`, error.message);
        return {
          postId: article.postId,
          success: false,
          error: error.message
        };
      }
    });

    const auditResults = await Promise.all(auditPromises);

    // Aggregate stats
    const aggregateStats = {
      totalArticles: articles.length,
      succeeded: auditResults.filter(r => r.success).length,
      failed: auditResults.filter(r => !r.success).length,
      totalLinks: 0,
      brokenLinks: 0,
      suboptimalLinks: 0,
      missingOpportunities: 0,
      contentTypeViolations: 0
    };

    for (const result of auditResults) {
      if (result.success && result.audit?.stats) {
        aggregateStats.totalLinks += result.audit.stats.totalLinks || 0;
        aggregateStats.brokenLinks += result.audit.stats.brokenLinks || 0;
        aggregateStats.suboptimalLinks += result.audit.stats.suboptimalLinks || 0;
        aggregateStats.missingOpportunities += result.audit.stats.missingOpportunities || 0;
        aggregateStats.contentTypeViolations += result.audit.stats.contentTypeViolations || 0;
      }
    }

    console.log(`Batch audit complete in ${Date.now() - startTime}ms`);

    return res.status(200).json({
      success: true,
      batchSize: articles.length,
      processingTime: Date.now() - startTime,
      stats: aggregateStats,
      results: auditResults,
      sitewideHealth: sitewideMetrics?.health || null
    });

  } catch (error) {
    console.error('Batch link audit error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
