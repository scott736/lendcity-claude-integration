const {
  calculateSeasonalScore,
  applySeasonalBoosting,
  getUpcomingSeasonalSuggestions,
  getCurrentSeasonalTopics,
  SEASONAL_CONTENT
} = require('../lib/seasonal-boosting');
const { getAllArticles } = require('../lib/pinecone');

/**
 * Seasonal Boosting Endpoint
 * Get seasonal content suggestions and apply boosting
 *
 * GET /api/seasonal-boosting - Get current seasonal topics and suggestions
 * POST /api/seasonal-boosting - Apply boosting to candidates
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
      const { lookaheadMonths } = req.query;

      // Get current seasonal context
      const currentTopics = getCurrentSeasonalTopics();
      const suggestions = getUpcomingSeasonalSuggestions(
        parseInt(lookaheadMonths) || 2
      );

      // Find existing articles that match seasonal topics
      const articles = await getAllArticles();
      const seasonalArticles = [];

      for (const article of articles) {
        const meta = article.metadata || article;
        const score = calculateSeasonalScore(meta);
        if (score.seasonalScore > 0) {
          seasonalArticles.push({
            postId: meta.postId,
            title: meta.title,
            cluster: meta.topicCluster,
            ...score
          });
        }
      }

      // Sort by seasonal score
      seasonalArticles.sort((a, b) => b.seasonalScore - a.seasonalScore);

      return res.status(200).json({
        success: true,
        currentMonth: new Date().getMonth() + 1,
        currentTopics,
        seasonalArticles: seasonalArticles.slice(0, 20),
        upcomingSuggestions: suggestions,
        calendar: SEASONAL_CONTENT
      });
    }

    if (req.method === 'POST') {
      const { action, candidates, article } = req.body;

      // Apply seasonal boosting to link candidates
      if (action === 'boost' && candidates) {
        const boosted = applySeasonalBoosting(candidates);
        return res.status(200).json({
          success: true,
          candidates: boosted
        });
      }

      // Calculate seasonal score for single article
      if (action === 'score' && article) {
        const score = calculateSeasonalScore(article);
        return res.status(200).json({
          success: true,
          ...score
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['boost', 'score']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Seasonal boosting error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
