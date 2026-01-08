const {
  extractQuestions,
  analyzeVoiceSearchReadiness,
  detectVoiceIntent,
  generateVoiceOptimizedSummary,
  generateVoiceFAQs,
  calculateVoiceSearchScore,
  findVoiceSearchOpportunities
} = require('../lib/voice-search');
const { getAllArticles } = require('../lib/pinecone');

/**
 * Voice Search Optimization Endpoint
 * Analyze and optimize content for voice search
 *
 * GET /api/voice-search - Find voice search opportunities
 * POST /api/voice-search - Analyze content for voice optimization
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
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
    if (req.method === 'GET') {
      // Find voice search opportunities across catalog
      const articles = await getAllArticles();
      const opportunities = findVoiceSearchOpportunities(articles);

      // Summary stats
      let totalVoiceScore = 0;
      for (const article of articles) {
        const score = calculateVoiceSearchScore(article);
        totalVoiceScore += score.score;
      }

      return res.status(200).json({
        success: true,
        opportunities: opportunities.slice(0, 20),
        summary: {
          totalArticles: articles.length,
          goodCandidates: opportunities.length,
          averageVoiceScore: Math.round(totalVoiceScore / articles.length),
          topClusters: getTopVoiceClusters(opportunities)
        }
      });
    }

    if (req.method === 'POST') {
      const { action, content, title, query, questions, answers } = req.body;

      // Analyze content for voice search readiness
      if (action === 'analyze' && content) {
        const analysis = analyzeVoiceSearchReadiness(content, title || '');
        return res.status(200).json({
          success: true,
          analysis
        });
      }

      // Extract questions from content
      if (action === 'extract-questions' && content) {
        const extractedQuestions = extractQuestions(content);
        return res.status(200).json({
          success: true,
          questions: extractedQuestions
        });
      }

      // Detect voice search intent
      if (action === 'detect-intent' && query) {
        const intent = detectVoiceIntent(query);
        return res.status(200).json({
          success: true,
          query,
          intent
        });
      }

      // Generate voice-optimized summary
      if (action === 'generate-summary' && content && title) {
        const summary = await generateVoiceOptimizedSummary(content, title);
        return res.status(200).json({
          success: true,
          summary
        });
      }

      // Generate FAQ schema for voice
      if (action === 'generate-faqs' && questions) {
        const faqs = generateVoiceFAQs(questions, answers || {});
        return res.status(200).json({
          success: true,
          faqs
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: [
          'analyze',
          'extract-questions',
          'detect-intent',
          'generate-summary',
          'generate-faqs'
        ]
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Voice search error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Get top clusters for voice search
 */
function getTopVoiceClusters(opportunities) {
  const clusterScores = {};

  for (const opp of opportunities) {
    const cluster = opp.cluster || 'uncategorized';
    if (!clusterScores[cluster]) {
      clusterScores[cluster] = { count: 0, totalScore: 0 };
    }
    clusterScores[cluster].count++;
    clusterScores[cluster].totalScore += opp.voiceScore;
  }

  return Object.entries(clusterScores)
    .map(([cluster, data]) => ({
      cluster,
      articleCount: data.count,
      averageScore: Math.round(data.totalScore / data.count)
    }))
    .sort((a, b) => b.articleCount - a.articleCount)
    .slice(0, 5);
}
