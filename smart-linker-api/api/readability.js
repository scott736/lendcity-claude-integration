const {
  analyzeReadability,
  calculateReadabilityCompatibility,
  filterByReadability,
  getReadabilityLevel
} = require('../lib/readability');
const { getAllArticles, getArticle } = require('../lib/pinecone');

/**
 * Readability Analysis Endpoint
 * Analyze content readability and match difficulty levels
 *
 * GET /api/readability - Analyze all articles' readability
 * POST /api/readability - Analyze content or filter by readability
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
      const { postId } = req.query;

      if (postId) {
        // Get readability for specific article
        const article = await getArticle(parseInt(postId));
        if (!article) {
          return res.status(404).json({ error: 'Article not found' });
        }

        return res.status(200).json({
          success: true,
          postId: parseInt(postId),
          title: article.title,
          difficultyLevel: article.difficultyLevel || 'unknown',
          readabilityLevel: getReadabilityLevel(article.difficultyLevel)
        });
      }

      // Get readability distribution for all articles
      const articles = await getAllArticles();
      const distribution = {
        beginner: 0,
        intermediate: 0,
        advanced: 0,
        unknown: 0
      };

      for (const article of articles) {
        const meta = article.metadata || article;
        const level = meta.difficultyLevel || 'unknown';
        distribution[level] = (distribution[level] || 0) + 1;
      }

      return res.status(200).json({
        success: true,
        totalArticles: articles.length,
        distribution,
        percentages: {
          beginner: ((distribution.beginner / articles.length) * 100).toFixed(1),
          intermediate: ((distribution.intermediate / articles.length) * 100).toFixed(1),
          advanced: ((distribution.advanced / articles.length) * 100).toFixed(1)
        }
      });
    }

    if (req.method === 'POST') {
      const { action, content, sourceLevel, candidates, maxLevelDifference } = req.body;

      // Analyze content readability
      if (action === 'analyze' && content) {
        const analysis = analyzeReadability(content);
        return res.status(200).json({
          success: true,
          analysis
        });
      }

      // Check readability compatibility
      if (action === 'compatibility' && sourceLevel) {
        const targetLevel = req.body.targetLevel;
        if (!targetLevel) {
          return res.status(400).json({ error: 'targetLevel is required' });
        }

        const compatibility = calculateReadabilityCompatibility(sourceLevel, targetLevel);
        return res.status(200).json({
          success: true,
          sourceLevel,
          targetLevel,
          ...compatibility
        });
      }

      // Filter candidates by readability
      if (action === 'filter' && sourceLevel && candidates) {
        const filtered = filterByReadability(candidates, sourceLevel, {
          maxLevelDifference: maxLevelDifference || 1
        });
        return res.status(200).json({
          success: true,
          filtered,
          removedCount: candidates.length - filtered.length
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['analyze', 'compatibility', 'filter']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Readability error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
