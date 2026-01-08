const { Pinecone } = require('@pinecone-database/pinecone');

let pineconeClient = null;
let pineconeIndex = null;

/**
 * Initialize Pinecone client (singleton)
 */
function getClient() {
  if (!pineconeClient) {
    pineconeClient = new Pinecone({
      apiKey: process.env.PINECONE_API_KEY
    });
  }
  return pineconeClient;
}

/**
 * Get Pinecone index
 */
function getIndex() {
  if (!pineconeIndex) {
    const client = getClient();
    pineconeIndex = client.index(process.env.PINECONE_INDEX);
  }
  return pineconeIndex;
}

/**
 * Upsert article to Pinecone catalog
 */
async function upsertArticle(article) {
  const index = getIndex();

  await index.upsert([{
    id: `article-${article.postId}`,
    values: article.embedding,
    metadata: {
      postId: article.postId,
      title: article.title,
      url: article.url,
      slug: article.slug,
      contentType: article.contentType || 'article',

      // Business rule fields
      topicCluster: article.topicCluster,
      relatedClusters: article.relatedClusters || [],
      funnelStage: article.funnelStage,
      targetPersona: article.targetPersona,
      difficultyLevel: article.difficultyLevel || 'intermediate',

      // Quality signals
      qualityScore: article.qualityScore || 50,
      contentLifespan: article.contentLifespan || 'evergreen',
      isPillar: article.isPillar || false,

      // Linking data
      inboundLinkCount: article.inboundLinkCount || 0,
      anchorPhrases: article.anchorPhrases || [],

      // SEO data
      summary: article.summary || '',
      mainTopics: article.mainTopics || [],
      semanticKeywords: article.semanticKeywords || [],

      // Dates
      publishedAt: article.publishedAt,
      updatedAt: article.updatedAt || new Date().toISOString()
    }
  }]);

  return { success: true, id: `article-${article.postId}` };
}

/**
 * Query similar articles using vector search
 */
async function querySimilar(embedding, options = {}) {
  const index = getIndex();

  const {
    topK = 20,
    filter = {},
    excludeIds = [],
    includeMetadata = true
  } = options;

  // Build filter with exclusions
  const queryFilter = { ...filter };
  if (excludeIds.length > 0) {
    queryFilter.postId = { $nin: excludeIds };
  }

  const results = await index.query({
    vector: embedding,
    topK,
    filter: Object.keys(queryFilter).length > 0 ? queryFilter : undefined,
    includeMetadata
  });

  return results.matches || [];
}

/**
 * Get article by ID
 */
async function getArticle(postId) {
  const index = getIndex();

  const result = await index.fetch([`article-${postId}`]);
  const record = result.records[`article-${postId}`];

  if (!record) return null;

  return {
    id: record.id,
    ...record.metadata
  };
}

/**
 * Delete article from catalog
 */
async function deleteArticle(postId) {
  const index = getIndex();
  await index.deleteOne(`article-${postId}`);
  return { success: true };
}

/**
 * Get all articles (for analysis/reporting)
 * Note: Use sparingly - fetches all records
 */
async function getAllArticles(options = {}) {
  const index = getIndex();
  const { filter = {} } = options;

  // Pinecone doesn't have a "get all" - we use a dummy vector query with high topK
  // For production, you'd want to paginate or use a different approach
  const results = await index.query({
    vector: new Array(1536).fill(0), // Dummy vector
    topK: 10000,
    filter: Object.keys(filter).length > 0 ? filter : undefined,
    includeMetadata: true
  });

  return results.matches || [];
}

/**
 * Update article metadata (without re-embedding)
 */
async function updateMetadata(postId, metadata) {
  const index = getIndex();

  await index.update({
    id: `article-${postId}`,
    metadata
  });

  return { success: true };
}

/**
 * Increment inbound link count
 */
async function incrementInboundLinks(postId, count = 1) {
  const article = await getArticle(postId);
  if (!article) return { success: false, error: 'Article not found' };

  await updateMetadata(postId, {
    inboundLinkCount: (article.inboundLinkCount || 0) + count
  });

  return { success: true };
}

module.exports = {
  getClient,
  getIndex,
  upsertArticle,
  querySimilar,
  getArticle,
  deleteArticle,
  getAllArticles,
  updateMetadata,
  incrementInboundLinks
};
