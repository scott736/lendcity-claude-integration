const { upsertArticle, deleteArticle, getArticle, getPillarPages } = require('../lib/pinecone');
const { generateEmbeddings, cleanForEmbedding } = require('../lib/embeddings');
const { batchAnalyzeArticles } = require('../lib/claude');
const { enrichArticleLight, analyzeContentStructure, analyzeEEAT, extractLSIKeywords, calculateComprehensiveness } = require('../lib/semantic-enrichment');

/**
 * Batch Catalog Sync Endpoint
 * Receives multiple articles from WordPress and syncs to Pinecone in parallel
 *
 * POST /api/catalog-sync-batch
 *
 * Optimized for speed:
 * - Single Claude call for multiple articles
 * - Batch embedding generation
 * - Parallel Pinecone upserts
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
    const { articles } = req.body;

    if (!articles || !Array.isArray(articles) || articles.length === 0) {
      return res.status(400).json({
        error: 'Missing required field: articles (array)'
      });
    }

    // Limit batch size to prevent timeouts
    const MAX_BATCH_SIZE = 20;
    if (articles.length > MAX_BATCH_SIZE) {
      return res.status(400).json({
        error: `Batch size exceeds maximum of ${MAX_BATCH_SIZE} articles`
      });
    }

    // Validate required fields for each article
    const validArticles = [];
    const errors = [];

    for (const article of articles) {
      if (!article.postId || !article.title || !article.url || !article.content) {
        errors.push({
          postId: article.postId || 'unknown',
          error: 'Missing required fields: postId, title, url, content'
        });
      } else {
        validArticles.push(article);
      }
    }

    if (validArticles.length === 0) {
      return res.status(400).json({
        error: 'No valid articles in batch',
        errors
      });
    }

    // Step 1: Fetch pillar pages once for all articles (cached for batch)
    let pillarPages = [];
    try {
      pillarPages = await getPillarPages();
    } catch (err) {
      console.warn('Could not fetch pillar pages:', err.message);
    }

    // Step 2: Identify articles needing analysis
    const articlesNeedingAnalysis = validArticles.filter(a =>
      !a.topicCluster || a.topicCluster === 'general' || !a.funnelStage || !a.targetPersona
    );

    // Step 3: Batch analyze with Claude (single API call for all)
    let analysisResults = {};
    if (articlesNeedingAnalysis.length > 0) {
      try {
        analysisResults = await batchAnalyzeArticles(articlesNeedingAnalysis, pillarPages);
      } catch (err) {
        console.error('Batch analysis failed:', err.message);
        // Continue without analysis - use defaults
      }
    }

    // Step 4: Prepare texts for batch embedding
    const textsForEmbedding = validArticles.map(article => {
      const cleanContent = cleanForEmbedding(article.content);
      return `${article.title}\n\n${cleanContent}`;
    });

    // Step 5: Generate all embeddings in single batch call
    const embeddings = await generateEmbeddings(textsForEmbedding);

    // Step 6: Prepare article data with analysis results, embeddings, AND light semantic enrichment
    const articlesToUpsert = validArticles.map((article, index) => {
      const analysis = analysisResults[article.postId] || {};

      // Merge WordPress data with analysis results
      const finalTopicCluster = (article.topicCluster && article.topicCluster !== 'general')
        ? article.topicCluster : (analysis.topicCluster || 'general');
      const finalFunnelStage = article.funnelStage || analysis.funnelStage || 'awareness';
      const finalTargetPersona = article.targetPersona || analysis.targetPersona || 'general';
      const finalIsPillar = article.contentType === 'page' && (article.isPillar || false);
      const mainTopics = article.mainTopics || analysis.mainTopics || [];

      // === v2.2: Light Semantic Enrichment (fast, no additional API calls) ===
      // These are computed locally without Claude/OpenAI calls for batch efficiency
      const contentStructure = analyzeContentStructure(article.content || '');
      const comprehensiveness = calculateComprehensiveness(
        article.content || '',
        contentStructure,
        mainTopics
      );
      const lsiKeywords = extractLSIKeywords(
        article.content || '',
        mainTopics,
        finalTopicCluster
      );
      const eeatAnalysis = analyzeEEAT(article.content || '', {
        updatedAt: article.updatedAt,
        publishedAt: article.publishedAt
      });

      return {
        postId: article.postId,
        title: article.title,
        url: article.url,
        slug: article.slug || article.url.split('/').filter(Boolean).pop(),
        contentType: article.contentType || 'article',
        topicCluster: finalTopicCluster,
        relatedClusters: article.relatedClusters || analysis.relatedClusters || [],
        funnelStage: finalFunnelStage,
        targetPersona: finalTargetPersona,
        difficultyLevel: article.difficultyLevel || analysis.difficultyLevel || 'intermediate',
        qualityScore: article.qualityScore || analysis.qualityScore || 50,
        contentLifespan: article.contentLifespan || 'evergreen',
        isPillar: finalIsPillar,
        summary: article.summary || analysis.summary || '',
        mainTopics: mainTopics,
        semanticKeywords: article.semanticKeywords || analysis.semanticKeywords || [],
        anchorPhrases: article.anchorPhrases || [],
        inboundLinkCount: article.inboundLinkCount || 0,
        publishedAt: article.publishedAt || new Date().toISOString(),
        updatedAt: article.updatedAt || new Date().toISOString(),
        embedding: embeddings[index],

        // === Light Semantic Enrichment Data (v2.2) ===
        lsiKeywords: lsiKeywords,
        contentStructure: contentStructure,
        comprehensiveness: comprehensiveness,
        eeatAnalysis: eeatAnalysis,
        enrichedAt: new Date().toISOString()
      };
    });

    // Step 7: Parallel upsert to Pinecone
    const upsertResults = await Promise.allSettled(
      articlesToUpsert.map(article => upsertArticle(article))
    );

    // Compile results with enrichment stats
    const results = {
      success: true,
      processed: validArticles.length,
      succeeded: 0,
      failed: 0,
      details: [],
      errors,
      enrichmentStats: {
        articlesEnriched: articlesToUpsert.length,
        averageComprehensiveness: Math.round(
          articlesToUpsert.reduce((sum, a) => sum + (a.comprehensiveness?.totalScore || 0), 0) / articlesToUpsert.length
        ),
        averageEEAT: Math.round(
          articlesToUpsert.reduce((sum, a) => sum + (a.eeatAnalysis?.totalScore || 0), 0) / articlesToUpsert.length
        ),
        contentFormats: articlesToUpsert.reduce((acc, a) => {
          const format = a.contentStructure?.contentFormat || 'standard-article';
          acc[format] = (acc[format] || 0) + 1;
          return acc;
        }, {}),
        totalLSIKeywords: articlesToUpsert.reduce((sum, a) => sum + (a.lsiKeywords?.length || 0), 0)
      }
    };

    upsertResults.forEach((result, index) => {
      const article = validArticles[index];
      const enrichedArticle = articlesToUpsert[index];
      if (result.status === 'fulfilled') {
        results.succeeded++;
        results.details.push({
          postId: article.postId,
          status: 'success',
          vectorId: result.value.id,
          enrichment: {
            lsiKeywords: (enrichedArticle.lsiKeywords || []).length,
            contentFormat: enrichedArticle.contentStructure?.contentFormat || 'unknown',
            comprehensiveness: enrichedArticle.comprehensiveness?.totalScore || 0,
            eeatScore: enrichedArticle.eeatAnalysis?.totalScore || 0
          }
        });
      } else {
        results.failed++;
        results.details.push({
          postId: article.postId,
          status: 'failed',
          error: result.reason?.message || 'Unknown error'
        });
      }
    });

    return res.status(200).json(results);

  } catch (error) {
    console.error('Batch catalog sync error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
