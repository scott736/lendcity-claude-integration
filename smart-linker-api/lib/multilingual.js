/**
 * Multi-Language Support
 *
 * Handles content in multiple languages with appropriate
 * embedding models and language-aware linking.
 */

const OpenAI = require('openai');
const { getClient } = require('./claude');

// Supported languages
const SUPPORTED_LANGUAGES = {
  en: { name: 'English', embeddingModel: 'text-embedding-3-small' },
  fr: { name: 'French', embeddingModel: 'text-embedding-3-small' },
  es: { name: 'Spanish', embeddingModel: 'text-embedding-3-small' },
  de: { name: 'German', embeddingModel: 'text-embedding-3-small' },
  pt: { name: 'Portuguese', embeddingModel: 'text-embedding-3-small' },
  zh: { name: 'Chinese', embeddingModel: 'text-embedding-3-small' }
};

/**
 * Detect language of content
 */
async function detectLanguage(text) {
  const client = getClient();

  // Use first 500 chars for detection
  const sample = text.slice(0, 500);

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 50,
    messages: [{
      role: 'user',
      content: `What language is this text written in? Reply with only the ISO 639-1 two-letter code (e.g., 'en' for English, 'fr' for French).\n\nText: ${sample}`
    }]
  });

  const langCode = response.content[0].text.trim().toLowerCase().slice(0, 2);
  return SUPPORTED_LANGUAGES[langCode] ? langCode : 'en';
}

/**
 * Generate language-aware embedding
 * Uses multilingual model for non-English content
 */
async function generateMultilingualEmbedding(text, language = null) {
  const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

  // Detect language if not provided
  const lang = language || await detectLanguage(text);
  const config = SUPPORTED_LANGUAGES[lang] || SUPPORTED_LANGUAGES.en;

  const response = await openai.embeddings.create({
    model: config.embeddingModel,
    input: text.slice(0, 8000)
  });

  return {
    embedding: response.data[0].embedding,
    language: lang,
    model: config.embeddingModel
  };
}

/**
 * Filter candidates by language
 */
function filterByLanguage(candidates, sourceLanguage, options = {}) {
  const { allowCrossLanguage = false, preferredLanguages = [] } = options;

  if (allowCrossLanguage) {
    // Sort by language preference but include all
    return candidates.sort((a, b) => {
      const aLang = a.metadata?.language || 'en';
      const bLang = b.metadata?.language || 'en';

      if (aLang === sourceLanguage && bLang !== sourceLanguage) return -1;
      if (bLang === sourceLanguage && aLang !== sourceLanguage) return 1;

      const aPreferred = preferredLanguages.indexOf(aLang);
      const bPreferred = preferredLanguages.indexOf(bLang);

      if (aPreferred !== -1 && bPreferred === -1) return -1;
      if (bPreferred !== -1 && aPreferred === -1) return 1;

      return 0;
    });
  }

  // Strict mode: only same language
  return candidates.filter(c => {
    const cLang = c.metadata?.language || 'en';
    return cLang === sourceLanguage;
  });
}

/**
 * Translate anchor text for cross-language linking
 */
async function translateAnchor(anchorText, fromLang, toLang) {
  if (fromLang === toLang) return anchorText;

  const client = getClient();

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 100,
    messages: [{
      role: 'user',
      content: `Translate this anchor text from ${SUPPORTED_LANGUAGES[fromLang]?.name || fromLang} to ${SUPPORTED_LANGUAGES[toLang]?.name || toLang}. Keep it natural and SEO-friendly. Return only the translation.\n\nAnchor text: ${anchorText}`
    }]
  });

  return response.content[0].text.trim();
}

/**
 * Get language statistics for content
 */
async function getLanguageStats(articles) {
  const stats = {};

  for (const article of articles) {
    const lang = article.metadata?.language || 'en';
    if (!stats[lang]) {
      stats[lang] = {
        code: lang,
        name: SUPPORTED_LANGUAGES[lang]?.name || 'Unknown',
        count: 0,
        articles: []
      };
    }
    stats[lang].count++;
    if (stats[lang].articles.length < 5) {
      stats[lang].articles.push({
        postId: article.metadata?.postId,
        title: article.metadata?.title
      });
    }
  }

  return {
    languages: Object.values(stats).sort((a, b) => b.count - a.count),
    primaryLanguage: Object.values(stats).sort((a, b) => b.count - a.count)[0]?.code || 'en',
    isMultilingual: Object.keys(stats).length > 1
  };
}

/**
 * Score language compatibility for linking
 */
function getLanguageScore(sourceLanguage, targetLanguage) {
  if (sourceLanguage === targetLanguage) return 20;

  // Related languages get partial score
  const relatedLanguages = {
    en: ['en'],
    fr: ['fr'],
    es: ['es', 'pt'],
    pt: ['pt', 'es'],
    de: ['de'],
    zh: ['zh']
  };

  const related = relatedLanguages[sourceLanguage] || [];
  if (related.includes(targetLanguage)) return 10;

  return 0; // Different language families
}

module.exports = {
  SUPPORTED_LANGUAGES,
  detectLanguage,
  generateMultilingualEmbedding,
  filterByLanguage,
  translateAnchor,
  getLanguageStats,
  getLanguageScore
};
