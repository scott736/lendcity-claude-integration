/**
 * Readability-Aware Linking
 *
 * Matches content difficulty levels to ensure links
 * connect readers to appropriately complex content.
 */

/**
 * Difficulty levels
 */
const DIFFICULTY_LEVELS = {
  BEGINNER: 'beginner',
  INTERMEDIATE: 'intermediate',
  ADVANCED: 'advanced',
  EXPERT: 'expert'
};

/**
 * Difficulty level numeric values for comparison
 */
const DIFFICULTY_VALUES = {
  beginner: 1,
  intermediate: 2,
  advanced: 3,
  expert: 4
};

/**
 * Calculate Flesch-Kincaid reading ease score
 * Higher scores = easier to read
 */
function calculateFleschKincaid(text) {
  const sentences = countSentences(text);
  const words = countWords(text);
  const syllables = countSyllables(text);

  if (words === 0 || sentences === 0) return 0;

  // Flesch Reading Ease formula
  const score = 206.835 - 1.015 * (words / sentences) - 84.6 * (syllables / words);

  return Math.max(0, Math.min(100, score));
}

/**
 * Calculate Gunning Fog Index
 * Estimates years of education needed
 */
function calculateGunningFog(text) {
  const sentences = countSentences(text);
  const words = countWords(text);
  const complexWords = countComplexWords(text);

  if (words === 0 || sentences === 0) return 0;

  return 0.4 * ((words / sentences) + 100 * (complexWords / words));
}

/**
 * Count sentences in text
 */
function countSentences(text) {
  const matches = text.match(/[.!?]+/g);
  return matches ? matches.length : 1;
}

/**
 * Count words in text
 */
function countWords(text) {
  const words = text.trim().split(/\s+/).filter(Boolean);
  return words.length;
}

/**
 * Estimate syllable count
 */
function countSyllables(text) {
  const words = text.toLowerCase().split(/\s+/).filter(Boolean);
  let count = 0;

  for (const word of words) {
    count += countWordSyllables(word);
  }

  return count;
}

/**
 * Count syllables in a single word
 */
function countWordSyllables(word) {
  word = word.toLowerCase().replace(/[^a-z]/g, '');
  if (word.length <= 3) return 1;

  word = word.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '');
  word = word.replace(/^y/, '');

  const matches = word.match(/[aeiouy]{1,2}/g);
  return matches ? matches.length : 1;
}

/**
 * Count complex words (3+ syllables)
 */
function countComplexWords(text) {
  const words = text.toLowerCase().split(/\s+/).filter(Boolean);
  let count = 0;

  for (const word of words) {
    if (countWordSyllables(word) >= 3) {
      count++;
    }
  }

  return count;
}

/**
 * Analyze readability of content
 */
function analyzeReadability(text) {
  const fleschScore = calculateFleschKincaid(text);
  const fogIndex = calculateGunningFog(text);
  const wordCount = countWords(text);
  const sentenceCount = countSentences(text);
  const avgSentenceLength = wordCount / Math.max(sentenceCount, 1);

  // Determine difficulty level
  let difficulty;
  if (fleschScore >= 70) {
    difficulty = DIFFICULTY_LEVELS.BEGINNER;
  } else if (fleschScore >= 50) {
    difficulty = DIFFICULTY_LEVELS.INTERMEDIATE;
  } else if (fleschScore >= 30) {
    difficulty = DIFFICULTY_LEVELS.ADVANCED;
  } else {
    difficulty = DIFFICULTY_LEVELS.EXPERT;
  }

  return {
    fleschScore: Math.round(fleschScore * 10) / 10,
    fogIndex: Math.round(fogIndex * 10) / 10,
    difficulty,
    stats: {
      wordCount,
      sentenceCount,
      avgSentenceLength: Math.round(avgSentenceLength * 10) / 10,
      avgSyllablesPerWord: Math.round((countSyllables(text) / wordCount) * 10) / 10
    },
    recommendations: generateReadabilityRecommendations(fleschScore, avgSentenceLength)
  };
}

/**
 * Generate recommendations for improving readability
 */
function generateReadabilityRecommendations(fleschScore, avgSentenceLength) {
  const recommendations = [];

  if (fleschScore < 50) {
    recommendations.push({
      issue: 'Complex vocabulary',
      suggestion: 'Use simpler words where possible'
    });
  }

  if (avgSentenceLength > 25) {
    recommendations.push({
      issue: 'Long sentences',
      suggestion: 'Break up sentences to average 15-20 words'
    });
  }

  if (fleschScore < 30) {
    recommendations.push({
      issue: 'Very difficult to read',
      suggestion: 'Consider adding explanations for technical terms'
    });
  }

  return recommendations;
}

/**
 * Calculate readability compatibility score between source and target
 */
function calculateReadabilityCompatibility(sourceLevel, targetLevel) {
  const sourceValue = DIFFICULTY_VALUES[sourceLevel] || 2;
  const targetValue = DIFFICULTY_VALUES[targetLevel] || 2;
  const diff = Math.abs(sourceValue - targetValue);

  // Same level = best match
  if (diff === 0) return 20;

  // One level difference = good match
  if (diff === 1) {
    // Linking to easier content is better than harder
    if (targetValue < sourceValue) return 15;
    return 10;
  }

  // Two levels = moderate match
  if (diff === 2) return 5;

  // Three+ levels = poor match
  return 0;
}

/**
 * Filter candidates by readability compatibility
 */
function filterByReadability(candidates, sourceLevel, options = {}) {
  const {
    maxDifficultyJump = 1, // Max levels harder allowed
    minDifficultyDrop = 2   // Max levels easier allowed
  } = options;

  const sourceValue = DIFFICULTY_VALUES[sourceLevel] || 2;

  return candidates.filter(candidate => {
    const meta = candidate.metadata || candidate;
    const targetLevel = meta.difficultyLevel || 'intermediate';
    const targetValue = DIFFICULTY_VALUES[targetLevel] || 2;

    const diff = targetValue - sourceValue;

    // Don't link to content much harder
    if (diff > maxDifficultyJump) return false;

    // Don't link to content much easier
    if (diff < -minDifficultyDrop) return false;

    return true;
  });
}

/**
 * Score candidates with readability factor
 */
function applyReadabilityScoring(candidates, sourceLevel) {
  return candidates.map(candidate => {
    const meta = candidate.metadata || candidate;
    const targetLevel = meta.difficultyLevel || 'intermediate';
    const compatibilityScore = calculateReadabilityCompatibility(sourceLevel, targetLevel);

    return {
      ...candidate,
      readabilityScore: compatibilityScore,
      sourceDifficulty: sourceLevel,
      targetDifficulty: targetLevel
    };
  });
}

/**
 * Auto-detect difficulty level from content
 */
function detectDifficultyLevel(content) {
  const analysis = analyzeReadability(content);
  return analysis.difficulty;
}

/**
 * Get difficulty distribution of catalog
 */
function getDifficultyDistribution(articles) {
  const distribution = {
    beginner: 0,
    intermediate: 0,
    advanced: 0,
    expert: 0,
    unknown: 0
  };

  for (const article of articles) {
    const meta = article.metadata || article;
    const level = meta.difficultyLevel || 'unknown';
    distribution[level] = (distribution[level] || 0) + 1;
  }

  return distribution;
}

module.exports = {
  DIFFICULTY_LEVELS,
  DIFFICULTY_VALUES,
  calculateFleschKincaid,
  calculateGunningFog,
  analyzeReadability,
  calculateReadabilityCompatibility,
  filterByReadability,
  applyReadabilityScoring,
  detectDifficultyLevel,
  getDifficultyDistribution
};
