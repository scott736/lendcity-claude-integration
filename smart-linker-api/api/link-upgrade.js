const {
  analyzeUpgradePotential,
  batchAnalyzeUpgrades,
  autoUpgradeLinks,
  UPGRADE_THRESHOLD
} = require('../lib/link-upgrade');

/**
 * Link Upgrade Endpoint
 * Analyzes existing links and recommends upgrades
 *
 * POST /api/link-upgrade - Analyze single article or batch
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  // Verify API key
  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  try {
    const { action, postId, content, articles, threshold, autoApply } = req.body;

    // Analyze single article
    if (action === 'analyze' && postId && content) {
      const analysis = await analyzeUpgradePotential(postId, content, {
        threshold: threshold || UPGRADE_THRESHOLD
      });

      return res.status(200).json({
        success: true,
        ...analysis
      });
    }

    // Auto-upgrade single article (returns upgrade plan)
    if (action === 'auto-upgrade' && postId && content) {
      const result = await autoUpgradeLinks(postId, content, {
        threshold: threshold || UPGRADE_THRESHOLD
      });

      return res.status(200).json({
        success: true,
        ...result
      });
    }

    // Batch analyze multiple articles
    if (action === 'batch-analyze' && articles && Array.isArray(articles)) {
      const results = await batchAnalyzeUpgrades(articles, {
        threshold: threshold || UPGRADE_THRESHOLD
      });

      return res.status(200).json({
        success: true,
        ...results
      });
    }

    return res.status(400).json({
      error: 'Invalid action or missing parameters',
      validActions: [
        'analyze (requires postId, content)',
        'auto-upgrade (requires postId, content)',
        'batch-analyze (requires articles array)'
      ],
      defaultThreshold: UPGRADE_THRESHOLD
    });

  } catch (error) {
    console.error('Link upgrade error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
