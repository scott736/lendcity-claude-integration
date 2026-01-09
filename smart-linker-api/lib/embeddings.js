const OpenAI = require('openai');

let openaiClient = null;

// ============================================================================
// EMBEDDING CACHE (Perf #9)
// ============================================================================

// In-memory embedding cache with content hash as key
const embeddingCache = new Map();
const EMBEDDING_CACHE_TTL = 24 * 60 * 60 * 1000; // 24 hours
const MAX_CACHE_SIZE = 500;

/**
 * Generate a hash for content (for cache key)
 */
function hashText(text) {
  let hash = 0;
  const str = text.slice(0, 2000); // Hash first 2000 chars
  for (let i = 0; i < str.length; i++) {
    const char = str.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash;
  }
  return `emb:${hash.toString(36)}:${text.length}`;
}

/**
 * Get cached embedding
 */
function getCachedEmbedding(cacheKey) {
  const cached = embeddingCache.get(cacheKey);
  if (cached && (Date.now() - cached.timestamp) < EMBEDDING_CACHE_TTL) {
    return cached.embedding;
  }
  if (cached) {
    embeddingCache.delete(cacheKey);
  }
  return null;
}

/**
 * Store embedding in cache
 */
function setCachedEmbedding(cacheKey, embedding) {
  // Limit cache size
  if (embeddingCache.size >= MAX_CACHE_SIZE) {
    // Remove oldest 50 entries
    const keys = Array.from(embeddingCache.keys()).slice(0, 50);
    keys.forEach(k => embeddingCache.delete(k));
  }
  embeddingCache.set(cacheKey, { embedding, timestamp: Date.now() });
}

/**
 * Initialize OpenAI client (singleton)
 */
function getClient() {
  if (!openaiClient) {
    openaiClient = new OpenAI({
      apiKey: process.env.OPENAI_API_KEY
    });
  }
  return openaiClient;
}

/**
 * Generate embedding for text
 * Uses text-embedding-3-small for cost efficiency
 * Switch to text-embedding-3-large for better quality
 *
 * Performance: Caches embeddings by content hash (Perf #9)
 */
async function generateEmbedding(text, options = {}) {
  const client = getClient();
  const { model = 'text-embedding-3-small', skipCache = false } = options;

  // Clean and truncate text (max ~8000 tokens)
  const cleanText = cleanForEmbedding(text);

  // Check cache (Perf #9)
  if (!skipCache) {
    const cacheKey = hashText(cleanText);
    const cached = getCachedEmbedding(cacheKey);
    if (cached) {
      console.log('Embedding cache hit');
      return cached;
    }
  }

  const response = await client.embeddings.create({
    model,
    input: cleanText
  });

  const embedding = response.data[0].embedding;

  // Cache the result
  if (!skipCache) {
    const cacheKey = hashText(cleanText);
    setCachedEmbedding(cacheKey, embedding);
  }

  return embedding;
}

/**
 * Generate embeddings for multiple texts (batch)
 */
async function generateEmbeddings(texts, options = {}) {
  const client = getClient();
  const { model = 'text-embedding-3-small' } = options;

  const cleanTexts = texts.map(cleanForEmbedding);

  const response = await client.embeddings.create({
    model,
    input: cleanTexts
  });

  return response.data.map(d => d.embedding);
}

/**
 * Generate embedding for an article
 * Combines title, summary, and body for rich representation
 */
async function generateArticleEmbedding(article) {
  const textParts = [
    article.title,
    article.summary || '',
    extractBodyText(article.body || article.content || '')
  ].filter(Boolean);

  const combinedText = textParts.join('\n\n');
  return generateEmbedding(combinedText);
}

/**
 * Clean text for embedding generation
 */
function cleanForEmbedding(text) {
  if (!text) return '';

  return text
    // Remove HTML tags
    .replace(/<[^>]*>/g, ' ')
    // Remove shortcodes
    .replace(/\[[^\]]*\]/g, ' ')
    // Remove URLs
    .replace(/https?:\/\/[^\s]+/g, ' ')
    // Remove extra whitespace
    .replace(/\s+/g, ' ')
    // Trim
    .trim()
    // Truncate to ~8000 tokens (~32000 chars)
    .slice(0, 32000);
}

/**
 * Extract body text from HTML content
 * Uses full content - no truncation for complete semantic understanding
 */
function extractBodyText(html) {
  const text = html
    .replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  // Full content - OpenAI embeddings handle up to 8191 tokens (~32k chars)
  // For longer articles, the cleanForEmbedding function will handle truncation
  return text;
}

/**
 * Calculate cosine similarity between two embeddings
 */
function cosineSimilarity(a, b) {
  if (a.length !== b.length) {
    throw new Error('Embeddings must have same dimensions');
  }

  let dotProduct = 0;
  let normA = 0;
  let normB = 0;

  for (let i = 0; i < a.length; i++) {
    dotProduct += a[i] * b[i];
    normA += a[i] * a[i];
    normB += b[i] * b[i];
  }

  return dotProduct / (Math.sqrt(normA) * Math.sqrt(normB));
}

/**
 * Check if two articles are semantically similar
 * Useful for duplicate detection
 */
async function areSimilar(text1, text2, threshold = 0.9) {
  const [emb1, emb2] = await generateEmbeddings([text1, text2]);
  const similarity = cosineSimilarity(emb1, emb2);
  return {
    similar: similarity >= threshold,
    similarity
  };
}

module.exports = {
  getClient,
  generateEmbedding,
  generateEmbeddings,
  generateArticleEmbedding,
  cleanForEmbedding,
  extractBodyText,
  cosineSimilarity,
  areSimilar
};
