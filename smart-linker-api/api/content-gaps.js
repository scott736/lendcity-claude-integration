const { analyzeContentGaps, getContentSuggestions, findMissingLinks } = require('../lib/content-gaps');
const { analyzeClickDepths } = require('../lib/click-depth');

/**
 * Content Gap Analysis Endpoint
 * Analyzes content coverage and identifies gaps
 *
 * GET /api/content-gaps
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
      // Full gap analysis
      const gaps = await analyzeContentGaps();
      const clickDepth = await analyzeClickDepths();

      return res.status(200).json({
        success: true,
        gaps,
        clickDepth,
        generatedAt: new Date().toISOString()
      });
    }

    if (req.method === 'POST') {
      const { action, postId, maxSuggestions = 10 } = req.body;

      if (action === 'suggestions') {
        // Get content suggestions
        const suggestions = await getContentSuggestions({ maxSuggestions });
        return res.status(200).json({
          success: true,
          suggestions
        });
      }

      if (action === 'missing-links' && postId) {
        // Find articles that should link to target
        const missing = await findMissingLinks(postId);
        return res.status(200).json({
          success: true,
          ...missing
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['suggestions', 'missing-links']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Content gaps error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
