/**
 * Voice Search Optimization
 *
 * Optimizes content for voice search queries by identifying
 * question-based content and conversational patterns.
 */

const { getClient } = require('./claude');

// Common question patterns for voice search
const QUESTION_PATTERNS = [
  /^what\s+/i,
  /^how\s+/i,
  /^why\s+/i,
  /^when\s+/i,
  /^where\s+/i,
  /^who\s+/i,
  /^which\s+/i,
  /^can\s+/i,
  /^is\s+/i,
  /^are\s+/i,
  /^do\s+/i,
  /^does\s+/i,
  /^should\s+/i,
  /^will\s+/i,
  /^would\s+/i
];

// Voice search intent categories
const VOICE_INTENTS = {
  INFORMATIONAL: 'informational',   // What is, How does
  NAVIGATIONAL: 'navigational',     // Where is, Find
  TRANSACTIONAL: 'transactional',   // Buy, Get, Apply
  LOCAL: 'local',                   // Near me, In [city]
  COMPARISON: 'comparison'          // Best, vs, compared to
};

/**
 * Extract questions from content
 */
function extractQuestions(content) {
  const questions = [];

  // Split by sentence
  const sentences = content.split(/[.!]\s+/);

  for (const sentence of sentences) {
    // Check if sentence is a question
    if (sentence.trim().endsWith('?')) {
      questions.push(sentence.trim());
      continue;
    }

    // Check for question patterns
    for (const pattern of QUESTION_PATTERNS) {
      if (pattern.test(sentence.trim())) {
        questions.push(sentence.trim() + '?');
        break;
      }
    }
  }

  return questions;
}

/**
 * Analyze content for voice search optimization
 */
function analyzeVoiceSearchReadiness(content, title = '') {
  const questions = extractQuestions(content);
  const wordCount = content.split(/\s+/).length;

  // Check for featured snippet patterns
  const hasDefinition = /is\s+a\s+|refers\s+to|means\s+/i.test(content);
  const hasList = /<li>|^\s*[\d•-]+\s+/m.test(content);
  const hasSteps = /step\s+\d|first|second|third|finally/i.test(content);

  // Calculate voice search score
  let score = 0;

  // Questions in content
  if (questions.length > 0) score += 20;
  if (questions.length > 3) score += 10;

  // Featured snippet patterns
  if (hasDefinition) score += 15;
  if (hasList) score += 15;
  if (hasSteps) score += 15;

  // Title optimization
  if (QUESTION_PATTERNS.some(p => p.test(title))) score += 15;

  // Conversational length (40-60 words in first paragraph is ideal)
  const firstParagraph = content.split(/\n\n/)[0] || '';
  const fpWords = firstParagraph.split(/\s+/).length;
  if (fpWords >= 40 && fpWords <= 60) score += 10;

  return {
    score: Math.min(score, 100),
    isVoiceOptimized: score >= 50,
    questionsFound: questions,
    patterns: {
      hasDefinition,
      hasList,
      hasSteps,
      questionInTitle: QUESTION_PATTERNS.some(p => p.test(title))
    },
    recommendations: generateVoiceRecommendations(score, questions, title, content)
  };
}

/**
 * Generate voice search optimization recommendations
 */
function generateVoiceRecommendations(score, questions, title, content) {
  const recommendations = [];

  if (questions.length === 0) {
    recommendations.push({
      priority: 'high',
      type: 'add_questions',
      suggestion: 'Add question-based headings (H2/H3) that voice assistants can answer'
    });
  }

  if (!QUESTION_PATTERNS.some(p => p.test(title))) {
    recommendations.push({
      priority: 'medium',
      type: 'title_optimization',
      suggestion: 'Consider starting the title with "How to", "What is", or similar question patterns'
    });
  }

  if (!/is\s+a\s+|refers\s+to|defined\s+as/i.test(content)) {
    recommendations.push({
      priority: 'medium',
      type: 'add_definition',
      suggestion: 'Add a clear definition in the first paragraph for featured snippets'
    });
  }

  if (score < 50) {
    recommendations.push({
      priority: 'high',
      type: 'conversational_tone',
      suggestion: 'Use more conversational language and direct answers'
    });
  }

  return recommendations;
}

/**
 * Detect voice search intent
 */
function detectVoiceIntent(query) {
  const lower = query.toLowerCase();

  // Local intent
  if (/near\s+me|in\s+\w+|local|nearby/i.test(lower)) {
    return VOICE_INTENTS.LOCAL;
  }

  // Transactional intent
  if (/buy|purchase|get|apply|sign\s+up|order|book/i.test(lower)) {
    return VOICE_INTENTS.TRANSACTIONAL;
  }

  // Comparison intent
  if (/best|vs|versus|compared|difference|better/i.test(lower)) {
    return VOICE_INTENTS.COMPARISON;
  }

  // Navigational intent
  if (/find|where|locate|directions/i.test(lower)) {
    return VOICE_INTENTS.NAVIGATIONAL;
  }

  // Default to informational
  return VOICE_INTENTS.INFORMATIONAL;
}

/**
 * Generate voice search optimized summary
 */
async function generateVoiceOptimizedSummary(content, title) {
  const client = getClient();

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 300,
    messages: [{
      role: 'user',
      content: `Write a voice search optimized summary for this content.

Requirements:
- 40-60 words (ideal for voice snippets)
- Start with a direct answer
- Use conversational language
- Include the main topic keywords naturally

Title: ${title}
Content: ${content.slice(0, 3000)}

Return only the summary, no explanation.`
    }]
  });

  return response.content[0].text.trim();
}

/**
 * Generate FAQ schema for voice search
 */
function generateVoiceFAQs(questions, answers = {}) {
  return questions.slice(0, 10).map(q => ({
    question: q,
    answer: answers[q] || '',
    speakableAnswer: truncateForVoice(answers[q] || '', 150)
  }));
}

/**
 * Truncate text for voice output
 */
function truncateForVoice(text, maxLength = 150) {
  if (!text || text.length <= maxLength) return text;

  // Try to cut at sentence boundary
  const truncated = text.slice(0, maxLength);
  const lastSentence = truncated.lastIndexOf('.');

  if (lastSentence > maxLength * 0.6) {
    return truncated.slice(0, lastSentence + 1);
  }

  return truncated.trim() + '...';
}

/**
 * Score article for voice search potential
 */
function calculateVoiceSearchScore(article) {
  const meta = article.metadata || article;

  let score = 0;

  // Question in title
  if (QUESTION_PATTERNS.some(p => p.test(meta.title || ''))) {
    score += 25;
  }

  // Informational/educational content
  if (meta.funnelStage === 'awareness') {
    score += 15;
  }

  // Beginner difficulty (more likely voice searched)
  if (meta.difficultyLevel === 'beginner') {
    score += 15;
  }

  // How-to content
  if (/how\s+to/i.test(meta.title || '')) {
    score += 20;
  }

  // FAQ or guide content
  if (/faq|guide|explained|basics/i.test(meta.title || '')) {
    score += 15;
  }

  return {
    score: Math.min(score, 100),
    isGoodVoiceCandidate: score >= 40
  };
}

/**
 * Find best articles for voice search targeting
 */
function findVoiceSearchOpportunities(articles) {
  const opportunities = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    const voiceScore = calculateVoiceSearchScore(article);

    if (voiceScore.isGoodVoiceCandidate) {
      opportunities.push({
        postId: meta.postId,
        title: meta.title,
        url: meta.url,
        voiceScore: voiceScore.score,
        cluster: meta.topicCluster
      });
    }
  }

  return opportunities.sort((a, b) => b.voiceScore - a.voiceScore);
}

module.exports = {
  VOICE_INTENTS,
  extractQuestions,
  analyzeVoiceSearchReadiness,
  detectVoiceIntent,
  generateVoiceOptimizedSummary,
  generateVoiceFAQs,
  calculateVoiceSearchScore,
  findVoiceSearchOpportunities
};
