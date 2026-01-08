const { checkArticleDecay, checkAllArticlesForDecay, findDecayedLinks } = require('../lib/link-decay');

/**
 * Link Decay Detection Endpoint
 * Detects stale content and potentially outdated links
 *
 * GET /api/link-decay
 * POST /api/link-decay (for single article check)
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
      // Check all articles for decay
      const decayReport = await checkAllArticlesForDecay();
      const decayedLinks = await findDecayedLinks();

      return res.status(200).json({
        success: true,
        report: decayReport,
        potentialLinkIssues: decayedLinks,
        generatedAt: new Date().toISOString()
      });
    }

    if (req.method === 'POST') {
      const { postId, content } = req.body;

      if (!postId) {
        return res.status(400).json({
          error: 'postId is required'
        });
      }

      // Check single article
      const analysis = await checkArticleDecay(postId, content || '');

      return res.status(200).json({
        success: true,
        analysis
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Link decay error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
