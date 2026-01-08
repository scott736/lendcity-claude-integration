const OpenAI = require('openai');

let openaiClient = null;

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
 */
async function generateEmbedding(text, options = {}) {
  const client = getClient();
  const { model = 'text-embedding-3-small' } = options;

  // Clean and truncate text (max ~8000 tokens)
  const cleanText = cleanForEmbedding(text);

  const response = await client.embeddings.create({
    model,
    input: cleanText
  });

  return response.data[0].embedding;
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
