const { analyzeContentQuality, analyzeAllContentQuality, deepQualityAnalysis } = require('../lib/quality-scoring');

/**
 * Quality Scoring Endpoint
 * Analyze content quality via embeddings and Claude
 *
 * GET /api/quality-scoring - Analyze all articles
 * POST /api/quality-scoring - Analyze specific content
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
      // Analyze all content quality
      const analysis = await analyzeAllContentQuality();
      return res.status(200).json({
        success: true,
        ...analysis
      });
    }

    if (req.method === 'POST') {
      const { action, postId, content, title } = req.body;

      if (action === 'analyze' && postId) {
        // Analyze specific article by ID
        const quality = await analyzeContentQuality(postId, content);
        return res.status(200).json({
          success: true,
          quality
        });
      }

      if (action === 'deep' && content) {
        // Deep Claude-powered quality analysis
        const analysis = await deepQualityAnalysis(content, title || '');
        return res.status(200).json({
          success: true,
          analysis
        });
      }

      return res.status(400).json({
        error: 'Invalid action or missing parameters',
        validActions: ['analyze (requires postId)', 'deep (requires content)']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Quality scoring error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
