const {
  analyzeTranscript,
  generateStrategicArticle,
  getProactiveContentPlan,
  getContentDashboard
} = require('../lib/strategic-content');

/**
 * Strategic Content Creation Endpoint
 * Claude-powered content planning and generation
 *
 * POST /api/strategic-content
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
      // Return content dashboard
      const dashboard = await getContentDashboard();
      return res.status(200).json({
        success: true,
        dashboard
      });
    }

    if (req.method === 'POST') {
      const { action, transcript, articleSpec, guestInfo, maxSuggestions = 3 } = req.body;

      // Analyze transcript for content opportunities
      if (action === 'analyze') {
        if (!transcript) {
          return res.status(400).json({ error: 'transcript is required' });
        }

        const analysis = await analyzeTranscript(transcript, {
          guestInfo,
          maxSuggestions
        });

        return res.status(200).json({
          success: true,
          analysis
        });
      }

      // Generate strategic article from transcript
      if (action === 'generate') {
        if (!transcript || !articleSpec) {
          return res.status(400).json({
            error: 'transcript and articleSpec are required'
          });
        }

        const article = await generateStrategicArticle(transcript, articleSpec);

        return res.status(200).json({
          success: true,
          article
        });
      }

      // Get proactive content plan
      if (action === 'plan') {
        const plan = await getProactiveContentPlan();
        return res.status(200).json({
          success: true,
          plan
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['analyze', 'generate', 'plan']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Strategic content error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
