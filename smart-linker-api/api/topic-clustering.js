const { discoverClusters, suggestClusterForArticle, findMisclusteredArticles, getClusterAnalytics } = require('../lib/topic-clustering');

/**
 * Topic Clustering Endpoint
 * Auto-discover and manage topic clusters
 *
 * GET /api/topic-clustering - Get cluster analytics
 * POST /api/topic-clustering - Discover clusters or suggest cluster for content
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
      // Get cluster analytics
      const analytics = await getClusterAnalytics();
      return res.status(200).json({
        success: true,
        analytics
      });
    }

    if (req.method === 'POST') {
      const { action, content, title, numClusters, threshold } = req.body;

      if (action === 'discover') {
        // Discover clusters from existing content
        const clusters = await discoverClusters({
          numClusters: numClusters || 8,
          minClusterSize: 3
        });
        return res.status(200).json({
          success: true,
          clusters
        });
      }

      if (action === 'suggest' && content) {
        // Suggest cluster for new content
        const suggestion = await suggestClusterForArticle(content, title || '');
        return res.status(200).json({
          success: true,
          suggestion
        });
      }

      if (action === 'misclustered') {
        // Find potentially misclustered articles
        const misclustered = await findMisclusteredArticles(threshold || 0.5);
        return res.status(200).json({
          success: true,
          misclustered
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['discover', 'suggest', 'misclustered']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Topic clustering error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
