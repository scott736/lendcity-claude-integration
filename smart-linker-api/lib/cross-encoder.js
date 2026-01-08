/**
 * Cross-Encoder Re-ranking
 *
 * Two-stage retrieval: fast vector search followed by
 * more accurate cross-encoder scoring for top candidates.
 */

const { getClient } = require('./claude');

/**
 * Re-rank candidates using Claude as a cross-encoder
 * More accurate than vector similarity alone but slower
 */
async function reRankWithCrossEncoder(sourceContent, candidates, options = {}) {
  const {
    maxCandidates = 10,
    sourceTitle = ''
  } = options;

  if (candidates.length === 0) return [];

  const client = getClient();

  // Prepare candidate list for Claude
  const candidateList = candidates.slice(0, maxCandidates).map((c, i) => {
    const meta = c.metadata || c;
    return `${i + 1}. "${meta.title}" - ${meta.summary || meta.topicCluster || 'No summary'}`;
  }).join('\n');

  const prompt = `You are evaluating link relevance for internal linking.

SOURCE ARTICLE:
Title: ${sourceTitle}
Content preview: ${sourceContent.slice(0, 2000)}

CANDIDATE ARTICLES TO LINK TO:
${candidateList}

For each candidate, score its relevance to the source article from 0-100:
- 90-100: Directly related, essential link
- 70-89: Highly relevant, strong connection
- 50-69: Moderately relevant, useful link
- 30-49: Loosely related, optional link
- 0-29: Not relevant, should not link

Return JSON array with scores:
[
  {"index": 1, "score": 85, "reason": "brief reason"},
  {"index": 2, "score": 45, "reason": "brief reason"}
]`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 600,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text;
    const jsonMatch = text.match(/\[[\s\S]*\]/);
    const scores = jsonMatch ? JSON.parse(jsonMatch[0]) : [];

    // Merge scores back into candidates
    const reRanked = candidates.slice(0, maxCandidates).map((candidate, i) => {
      const scoreData = scores.find(s => s.index === i + 1) || { score: 50, reason: 'Default score' };
      return {
        ...candidate,
        crossEncoderScore: scoreData.score,
        crossEncoderReason: scoreData.reason,
        originalVectorScore: candidate.score,
        // Combined score: 40% vector + 60% cross-encoder
        combinedScore: (candidate.score * 0.4) + (scoreData.score / 100 * 0.6)
      };
    });

    // Sort by combined score
    return reRanked.sort((a, b) => b.combinedScore - a.combinedScore);

  } catch (e) {
    console.error('Cross-encoder parsing error:', e);
    // Return original candidates if re-ranking fails
    return candidates;
  }
}

/**
 * Fast pre-filter before cross-encoder
 * Removes obviously irrelevant candidates
 */
function preFilterCandidates(candidates, options = {}) {
  const {
    minVectorScore = 0.3,
    maxForReRanking = 20
  } = options;

  return candidates
    .filter(c => c.score >= minVectorScore)
    .slice(0, maxForReRanking);
}

/**
 * Two-stage retrieval pipeline
 * 1. Fast vector search (already done)
 * 2. Pre-filter low-quality matches
 * 3. Re-rank with cross-encoder
 */
async function twoStageRetrieval(sourceContent, sourceTitle, vectorCandidates, options = {}) {
  const {
    preFilterThreshold = 0.3,
    maxReRank = 15,
    finalTopK = 5
  } = options;

  // Stage 1: Pre-filter
  const preFiltered = preFilterCandidates(vectorCandidates, {
    minVectorScore: preFilterThreshold,
    maxForReRanking: maxReRank
  });

  if (preFiltered.length === 0) {
    return {
      results: [],
      stats: { vectorCandidates: vectorCandidates.length, preFiltered: 0, reRanked: 0 }
    };
  }

  // Stage 2: Re-rank with cross-encoder
  const reRanked = await reRankWithCrossEncoder(sourceContent, preFiltered, {
    sourceTitle,
    maxCandidates: maxReRank
  });

  return {
    results: reRanked.slice(0, finalTopK),
    stats: {
      vectorCandidates: vectorCandidates.length,
      preFiltered: preFiltered.length,
      reRanked: reRanked.length
    }
  };
}

/**
 * Batch re-rank for efficiency (groups similar requests)
 */
async function batchReRank(sourceArticles, candidateSets) {
  const results = [];

  // Process in parallel with rate limiting
  const batchSize = 3;
  for (let i = 0; i < sourceArticles.length; i += batchSize) {
    const batch = sourceArticles.slice(i, i + batchSize);
    const batchResults = await Promise.all(
      batch.map((source, j) =>
        reRankWithCrossEncoder(
          source.content,
          candidateSets[i + j],
          { sourceTitle: source.title }
        )
      )
    );
    results.push(...batchResults);

    // Small delay between batches to avoid rate limits
    if (i + batchSize < sourceArticles.length) {
      await new Promise(resolve => setTimeout(resolve, 500));
    }
  }

  return results;
}

module.exports = {
  reRankWithCrossEncoder,
  preFilterCandidates,
  twoStageRetrieval,
  batchReRank
};
