const { upsertArticle, deleteArticle, getArticle } = require('../lib/pinecone');
const { generateArticleEmbedding } = require('../lib/embeddings');
const { generateSummary, extractKeywords, autoAnalyzeArticle } = require('../lib/claude');

/**
 * Catalog Sync Endpoint
 * Receives article data from WordPress and syncs to Pinecone
 *
 * POST /api/catalog-sync
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, DELETE, OPTIONS');
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
    if (req.method === 'POST') {
      return await handleSync(req, res);
    } else if (req.method === 'DELETE') {
      return await handleDelete(req, res);
    } else {
      return res.status(405).json({ error: 'Method not allowed' });
    }
  } catch (error) {
    console.error('Catalog sync error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Handle article sync (create/update)
 */
async function handleSync(req, res) {
  const {
    postId,
    title,
    url,
    slug,
    content,
    contentType = 'article',
    // Business rule fields
    topicCluster,
    relatedClusters = [],
    funnelStage,
    targetPersona,
    difficultyLevel = 'intermediate',
    // Quality signals
    qualityScore = 50,
    contentLifespan = 'evergreen',
    isPillar = false,
    // Optional - will generate if not provided
    summary = null,
    mainTopics = null,
    semanticKeywords = null,
    anchorPhrases = [],
    // Dates
    publishedAt,
    updatedAt
  } = req.body;

  // Validate required fields
  if (!postId || !title || !url || !content) {
    return res.status(400).json({
      error: 'Missing required fields',
      required: ['postId', 'title', 'url', 'content']
    });
  }

  // Check if article exists (for update vs create)
  const existing = await getArticle(postId);
  const isUpdate = !!existing;

  // Auto-analyze with Claude if metadata is missing
  // This makes the system fully intelligent without needing WordPress metadata
  let analyzedData = {};
  let wasAutoAnalyzed = false;

  const needsAnalysis = !topicCluster || topicCluster === 'general' ||
                        !funnelStage || !targetPersona;

  if (needsAnalysis) {
    console.log(`Auto-analyzing article ${postId}: "${title}"`);
    analyzedData = await autoAnalyzeArticle(title, content);
    wasAutoAnalyzed = true;
    console.log(`Auto-analysis complete:`, analyzedData);
  }

  // Use analyzed values if WordPress didn't provide them
  const finalTopicCluster = (topicCluster && topicCluster !== 'general')
    ? topicCluster : (analyzedData.topicCluster || 'general');
  const finalRelatedClusters = relatedClusters.length > 0
    ? relatedClusters : (analyzedData.relatedClusters || []);
  const finalFunnelStage = funnelStage || analyzedData.funnelStage || 'awareness';
  const finalTargetPersona = targetPersona || analyzedData.targetPersona || 'general';
  const finalDifficultyLevel = (difficultyLevel !== 'intermediate' ? difficultyLevel : null)
    || analyzedData.difficultyLevel || 'intermediate';
  const finalQualityScore = qualityScore !== 50
    ? qualityScore : (analyzedData.qualityScore || 50);
  const finalContentLifespan = contentLifespan !== 'evergreen'
    ? contentLifespan : (analyzedData.contentLifespan || 'evergreen');
  const finalIsPillar = isPillar || analyzedData.isPillar || false;

  // Generate embedding
  const embedding = await generateArticleEmbedding({
    title,
    summary: summary || '',
    body: content
  });

  // Generate summary if not provided
  let articleSummary = summary;
  if (!articleSummary) {
    articleSummary = await generateSummary(content);
  }

  // Extract keywords if not provided
  let keywords = { mainTopics: mainTopics || [], semanticKeywords: semanticKeywords || [] };
  if (!mainTopics || mainTopics.length === 0) {
    keywords = await extractKeywords(content);
  }

  // Prepare article data with auto-analyzed values
  const articleData = {
    postId,
    title,
    url,
    slug: slug || url.split('/').pop(),
    contentType,
    topicCluster: finalTopicCluster,
    relatedClusters: finalRelatedClusters,
    funnelStage: finalFunnelStage,
    targetPersona: finalTargetPersona,
    difficultyLevel: finalDifficultyLevel,
    qualityScore: finalQualityScore,
    contentLifespan: finalContentLifespan,
    isPillar: finalIsPillar,
    summary: articleSummary,
    mainTopics: keywords.mainTopics,
    semanticKeywords: keywords.semanticKeywords,
    anchorPhrases,
    inboundLinkCount: existing?.inboundLinkCount || 0,
    publishedAt: publishedAt || new Date().toISOString(),
    updatedAt: updatedAt || new Date().toISOString(),
    embedding
  };

  // Upsert to Pinecone
  const result = await upsertArticle(articleData);

  return res.status(200).json({
    success: true,
    action: isUpdate ? 'updated' : 'created',
    postId,
    vectorId: result.id,
    generatedSummary: !summary,
    generatedKeywords: !mainTopics || mainTopics.length === 0,
    autoAnalyzed: wasAutoAnalyzed,
    metadata: wasAutoAnalyzed ? {
      topicCluster: finalTopicCluster,
      funnelStage: finalFunnelStage,
      targetPersona: finalTargetPersona,
      difficultyLevel: finalDifficultyLevel,
      qualityScore: finalQualityScore,
      isPillar: finalIsPillar
    } : null
  });
}

/**
 * Handle article deletion
 */
async function handleDelete(req, res) {
  const { postId } = req.body;

  if (!postId) {
    return res.status(400).json({ error: 'postId is required' });
  }

  await deleteArticle(postId);

  return res.status(200).json({
    success: true,
    action: 'deleted',
    postId
  });
}
