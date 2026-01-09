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
 * Enhanced with semantic enrichment data (v2.0)
 */
async function upsertArticle(article) {
  const index = getIndex();

  // Prepare anchor phrases for storage (handle both old and new formats)
  let anchorPhrasesForStorage = [];
  if (article.anchorPhrases) {
    if (Array.isArray(article.anchorPhrases)) {
      anchorPhrasesForStorage = article.anchorPhrases.map(p =>
        typeof p === 'string' ? p : (p.phrase || p.anchorPhrase || '')
      ).filter(Boolean);
    }
  }

  // Prepare linkable moments for storage (extract just the phrases)
  let linkableMomentsForStorage = [];
  if (article.linkableMoments) {
    linkableMomentsForStorage = article.linkableMoments
      .slice(0, 10) // Limit to 10
      .map(m => m.anchorPhrase || m.matchedText || '')
      .filter(Boolean);
  }

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
      anchorPhrases: anchorPhrasesForStorage,
      primaryAnchor: article.primaryAnchor || '',

      // SEO data
      summary: article.summary || '',
      mainTopics: article.mainTopics || [],
      semanticKeywords: article.semanticKeywords || [],

      // === NEW: Semantic Enrichment Data (v2.0) ===

      // LSI Keywords - semantically related terms Google associates with content
      lsiKeywords: (article.lsiKeywords || []).slice(0, 25),
      questionKeywords: (article.questionKeywords || []).slice(0, 10),

      // Content Structure Analysis
      hasTable: article.contentStructure?.hasTable || false,
      hasFaq: article.contentStructure?.hasFaq || false,
      hasVideo: article.contentStructure?.hasVideo || false,
      hasCalculator: article.contentStructure?.hasCalculator || false,
      hasDownloads: article.contentStructure?.hasDownloads || false,
      contentFormat: article.contentStructure?.contentFormat || 'standard-article',
      structureScore: article.contentStructure?.structureScore || 50,

      // H2 Topics (section headers for matching)
      h2Topics: (article.h2Topics || []).slice(0, 15),

      // Linkable Moments (pre-identified link insertion opportunities)
      linkableMoments: linkableMomentsForStorage,

      // Content Comprehensiveness
      wordCount: article.comprehensiveness?.wordCount || 0,
      comprehensivenessScore: article.comprehensiveness?.totalScore || 50,
      comprehensivenessLevel: article.comprehensiveness?.level || 'adequate',

      // E-E-A-T Scores
      eeatScore: article.eeatAnalysis?.totalScore || 50,
      eeatLevel: article.eeatAnalysis?.level || 'adequate',
      experienceScore: article.eeatAnalysis?.breakdown?.experience || 0,
      expertiseScore: article.eeatAnalysis?.breakdown?.expertise || 0,
      authoritativenessScore: article.eeatAnalysis?.breakdown?.authoritativeness || 0,
      trustworthinessScore: article.eeatAnalysis?.breakdown?.trustworthiness || 0,

      // Topical Authority
      topicalAuthorityScore: article.topicalAuthority?.totalScore || 0,
      topicalAuthorityLevel: article.topicalAuthority?.level || 'basic',

      // Search Intent (from voice-search integration)
      searchIntent: article.searchIntent || 'informational',

      // Dates
      publishedAt: article.publishedAt,
      updatedAt: article.updatedAt || new Date().toISOString(),
      enrichedAt: article.enrichedAt || null
    }
  }]);

  // Store section embeddings separately if provided (for advanced matching)
  if (article.sections && article.sections.length > 0) {
    await storeSectionEmbeddings(article.postId, article.sections);
  }

  return { success: true, id: `article-${article.postId}` };
}

/**
 * Store section-level embeddings for an article
 * Uses a separate ID pattern: section-{postId}-{sectionIndex}
 */
async function storeSectionEmbeddings(postId, sections) {
  const index = getIndex();

  const sectionVectors = sections
    .filter(s => s.embedding && s.embedding.length > 0)
    .map((section, idx) => ({
      id: `section-${postId}-${idx}`,
      values: section.embedding,
      metadata: {
        parentPostId: postId,
        sectionIndex: idx,
        sectionHeader: section.header || '',
        sectionType: section.type || 'section',
        contentPreview: (section.content || '').slice(0, 500)
      }
    }));

  if (sectionVectors.length > 0) {
    await index.upsert(sectionVectors);
  }

  return { success: true, sectionsStored: sectionVectors.length };
}

/**
 * Query similar sections (for precise content matching)
 */
async function querySimilarSections(embedding, options = {}) {
  const index = getIndex();
  const { topK = 20, excludePostIds = [] } = options;

  const results = await index.query({
    vector: embedding,
    topK,
    filter: {
      parentPostId: { $nin: excludePostIds }
    },
    includeMetadata: true
  });

  return results.matches || [];
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
 * Get all articles using proper pagination
 * Uses Pinecone's list operation to avoid zero-vector anti-pattern
 *
 * @param {Object} options - Options with filter and limit
 * @returns {Array} Array of articles with metadata
 */
async function getAllArticles(options = {}) {
  const index = getIndex();
  const { filter = {}, limit = 1000 } = options;

  const articles = [];
  let paginationToken = null;

  try {
    // Use list operation with pagination
    do {
      const listOptions = {
        limit: Math.min(limit - articles.length, 1000), // Increased from 100 to 1000 for better performance
        prefix: 'article-' // All our IDs start with 'article-'
      };

      if (paginationToken) {
        listOptions.paginationToken = paginationToken;
      }

      const listResult = await index.listPaginated(listOptions);

      if (listResult.vectors && listResult.vectors.length > 0) {
        // Fetch metadata for listed IDs
        const ids = listResult.vectors.map(v => v.id);
        const fetchResult = await index.fetch(ids);

        // Extract articles with metadata
        for (const id of ids) {
          const record = fetchResult.records[id];
          if (record && record.metadata) {
            // Apply filter if specified
            if (Object.keys(filter).length > 0) {
              let passesFilter = true;
              for (const [key, value] of Object.entries(filter)) {
                if (record.metadata[key] !== value) {
                  passesFilter = false;
                  break;
                }
              }
              if (!passesFilter) continue;
            }

            articles.push({
              id: record.id,
              score: 1, // No score for list operations
              metadata: record.metadata
            });
          }
        }
      }

      paginationToken = listResult.pagination?.next || null;

    } while (paginationToken && articles.length < limit);

    return articles;

  } catch (error) {
    // Fallback: If list isn't available (older index), use optimized query
    console.warn('List operation failed, falling back to query:', error.message);
    return getAllArticlesFallback(filter, limit);
  }
}

/**
 * Fallback method using query with a representative vector
 * Used when list operation is not available
 */
async function getAllArticlesFallback(filter = {}, limit = 1000) {
  const index = getIndex();

  // Use a normalized random vector instead of zeros for better distribution
  const randomVector = new Array(1536).fill(0).map(() => Math.random() - 0.5);
  const norm = Math.sqrt(randomVector.reduce((sum, v) => sum + v * v, 0));
  const normalizedVector = randomVector.map(v => v / norm);

  const results = await index.query({
    vector: normalizedVector,
    topK: Math.min(limit, 10000),
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

/**
 * Get all pillar pages with their keywords
 * Used for topic cluster matching
 * Optimized: Uses list + fetch instead of zero-vector query
 */
async function getPillarPages() {
  const index = getIndex();

  try {
    // First, get all pillar pages using getAllArticles with filter
    const allArticles = await getAllArticles({
      filter: { isPillar: true },
      limit: 100
    });

    // Return pillar pages with their cluster-defining data
    return allArticles
      .filter(a => a.metadata.contentType === 'page')
      .map(a => ({
        postId: a.metadata.postId,
        title: a.metadata.title,
        url: a.metadata.url,
        topicCluster: a.metadata.topicCluster,
        mainTopics: a.metadata.mainTopics || [],
        semanticKeywords: a.metadata.semanticKeywords || [],
        summary: a.metadata.summary || ''
      }));

  } catch (error) {
    console.error('getPillarPages error:', error.message);

    // Fallback: Use query with normalized random vector
    const randomVector = new Array(1536).fill(0).map(() => Math.random() - 0.5);
    const norm = Math.sqrt(randomVector.reduce((sum, v) => sum + v * v, 0));
    const normalizedVector = randomVector.map(v => v / norm);

    const results = await index.query({
      vector: normalizedVector,
      topK: 100,
      filter: {
        isPillar: true,
        contentType: 'page'
      },
      includeMetadata: true
    });

    return (results.matches || []).map(match => ({
      postId: match.metadata.postId,
      title: match.metadata.title,
      url: match.metadata.url,
      topicCluster: match.metadata.topicCluster,
      mainTopics: match.metadata.mainTopics || [],
      semanticKeywords: match.metadata.semanticKeywords || [],
      summary: match.metadata.summary || ''
    }));
  }
}

module.exports = {
  getClient,
  getIndex,
  upsertArticle,
  querySimilar,
  querySimilarSections,
  storeSectionEmbeddings,
  getArticle,
  deleteArticle,
  getAllArticles,
  updateMetadata,
  incrementInboundLinks,
  getPillarPages
};
