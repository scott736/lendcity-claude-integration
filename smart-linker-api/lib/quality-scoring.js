/**
 * Content Quality Scoring via Embedding Analysis
 *
 * Uses embedding patterns to detect content quality signals
 * without needing to read the full content.
 */

const { getAllArticles, querySimilar, updateMetadata } = require('./pinecone');
const { generateEmbedding, cosineSimilarity } = require('./embeddings');
const { getClient } = require('./claude');

/**
 * Quality indicators detected from embeddings and metadata
 */
const QUALITY_SIGNALS = {
  DEPTH: 'depth',           // Content comprehensiveness
  UNIQUENESS: 'uniqueness', // How different from other content
  COHERENCE: 'coherence',   // Internal consistency
  AUTHORITY: 'authority'    // Expertise signals
};

/**
 * Analyze content quality using embedding patterns
 */
async function analyzeContentQuality(postId, content = null) {
  const articles = await getAllArticles();
  const targetArticle = articles.find(a =>
    (a.metadata?.postId || a.postId) === postId
  );

  if (!targetArticle) {
    return { error: 'Article not found' };
  }

  const meta = targetArticle.metadata || targetArticle;
  const embedding = targetArticle.values;

  const scores = {
    depth: 0,
    uniqueness: 0,
    coherence: 0,
    authority: 0
  };

  // 1. Uniqueness: How different is this from similar content?
  if (embedding) {
    const similar = await querySimilar(embedding, {
      topK: 10,
      excludeIds: [postId]
    });

    if (similar.length > 0) {
      const avgSimilarity = similar.reduce((sum, s) => sum + s.score, 0) / similar.length;
      // Lower similarity = more unique (inverted score)
      scores.uniqueness = Math.round((1 - avgSimilarity) * 100);
    } else {
      scores.uniqueness = 100; // No similar content = very unique
    }
  }

  // 2. Depth: Estimate from metadata signals
  const wordCount = meta.wordCount || estimateWordCount(content);
  if (wordCount > 2500) scores.depth = 100;
  else if (wordCount > 1500) scores.depth = 80;
  else if (wordCount > 1000) scores.depth = 60;
  else if (wordCount > 500) scores.depth = 40;
  else scores.depth = 20;

  // 3. Authority: Based on inbound links and pillar status
  const inboundLinks = meta.inboundLinkCount || 0;
  const isPillar = meta.isPillar || false;

  if (isPillar) scores.authority = 90;
  else if (inboundLinks >= 10) scores.authority = 80;
  else if (inboundLinks >= 5) scores.authority = 60;
  else if (inboundLinks >= 2) scores.authority = 40;
  else scores.authority = 20;

  // 4. Coherence: Check if title/summary match cluster
  scores.coherence = meta.topicCluster ? 70 : 30;
  if (meta.mainTopics && meta.mainTopics.length > 0) {
    scores.coherence += 15;
  }
  if (meta.summary && meta.summary.length > 50) {
    scores.coherence += 15;
  }

  // Calculate overall quality score
  const overallScore = Math.round(
    (scores.depth * 0.3) +
    (scores.uniqueness * 0.25) +
    (scores.coherence * 0.25) +
    (scores.authority * 0.2)
  );

  return {
    postId,
    title: meta.title,
    overallScore,
    breakdown: scores,
    recommendations: generateQualityRecommendations(scores),
    tier: getQualityTier(overallScore)
  };
}

/**
 * Estimate word count from content
 */
function estimateWordCount(content) {
  if (!content) return 0;
  return content.split(/\s+/).filter(Boolean).length;
}

/**
 * Get quality tier from score
 */
function getQualityTier(score) {
  if (score >= 80) return { tier: 'A', label: 'Excellent' };
  if (score >= 60) return { tier: 'B', label: 'Good' };
  if (score >= 40) return { tier: 'C', label: 'Average' };
  if (score >= 20) return { tier: 'D', label: 'Below Average' };
  return { tier: 'F', label: 'Needs Improvement' };
}

/**
 * Generate recommendations based on quality scores
 */
function generateQualityRecommendations(scores) {
  const recommendations = [];

  if (scores.depth < 60) {
    recommendations.push({
      area: 'depth',
      priority: 'high',
      suggestion: 'Add more comprehensive content - aim for 1500+ words with detailed sections'
    });
  }

  if (scores.uniqueness < 50) {
    recommendations.push({
      area: 'uniqueness',
      priority: 'medium',
      suggestion: 'Content is similar to existing articles - add unique insights or angles'
    });
  }

  if (scores.coherence < 60) {
    recommendations.push({
      area: 'coherence',
      priority: 'medium',
      suggestion: 'Add topic cluster, summary, and main topics metadata for better organization'
    });
  }

  if (scores.authority < 40) {
    recommendations.push({
      area: 'authority',
      priority: 'low',
      suggestion: 'Build authority by getting more internal links from related content'
    });
  }

  return recommendations;
}

/**
 * Batch analyze all articles for quality
 */
async function analyzeAllContentQuality() {
  const articles = await getAllArticles();
  const results = {
    excellent: [],
    good: [],
    average: [],
    needsWork: []
  };

  for (const article of articles) {
    const meta = article.metadata || article;
    const analysis = await analyzeContentQuality(meta.postId);

    if (analysis.error) continue;

    const item = {
      postId: meta.postId,
      title: meta.title,
      score: analysis.overallScore,
      tier: analysis.tier,
      topIssue: analysis.recommendations[0]?.area || null
    };

    if (analysis.overallScore >= 80) results.excellent.push(item);
    else if (analysis.overallScore >= 60) results.good.push(item);
    else if (analysis.overallScore >= 40) results.average.push(item);
    else results.needsWork.push(item);
  }

  return {
    summary: {
      total: articles.length,
      excellent: results.excellent.length,
      good: results.good.length,
      average: results.average.length,
      needsWork: results.needsWork.length,
      averageScore: Math.round(
        [...results.excellent, ...results.good, ...results.average, ...results.needsWork]
          .reduce((sum, a) => sum + a.score, 0) / articles.length
      )
    },
    distribution: results,
    priorityFixes: results.needsWork.slice(0, 10)
  };
}

/**
 * Use Claude to analyze content quality in depth
 */
async function deepQualityAnalysis(content, title) {
  const client = getClient();

  const prompt = `Analyze this article's quality for SEO and user value.

TITLE: ${title}

CONTENT:
${content.slice(0, 6000)}

Score each dimension 0-100 and explain:
1. **Depth** - How comprehensive is the coverage?
2. **Accuracy** - Does it seem factually correct?
3. **Readability** - Is it well-written and easy to understand?
4. **Actionability** - Does it provide clear takeaways?
5. **E-E-A-T** - Does it demonstrate expertise and trustworthiness?

Return JSON:
{
  "scores": {
    "depth": 0,
    "accuracy": 0,
    "readability": 0,
    "actionability": 0,
    "eeat": 0
  },
  "overallScore": 0,
  "strengths": ["..."],
  "weaknesses": ["..."],
  "improvements": ["specific suggestion 1", "specific suggestion 2"]
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 800,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text;
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    return jsonMatch ? JSON.parse(jsonMatch[0]) : { error: 'Failed to parse' };
  } catch (e) {
    return { error: e.message };
  }
}

module.exports = {
  analyzeContentQuality,
  analyzeAllContentQuality,
  deepQualityAnalysis,
  getQualityTier,
  QUALITY_SIGNALS
};
