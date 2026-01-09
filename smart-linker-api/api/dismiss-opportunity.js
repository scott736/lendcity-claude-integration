const {
  dismissOpportunity,
  restoreOpportunity,
  clearDismissedOpportunities,
  getDismissedOpportunities
} = require('../lib/seo-scoring');

/**
 * Dismiss Opportunity Endpoint
 * Manages dismissed/declined link opportunities
 *
 * POST /api/dismiss-opportunity - Dismiss or restore opportunities
 * GET /api/dismiss-opportunity?sourceId=123 - Get dismissed list
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
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
    // GET - List dismissed opportunities
    if (req.method === 'GET') {
      const sourceId = parseInt(req.query.sourceId);

      if (!sourceId) {
        return res.status(400).json({ error: 'sourceId query parameter is required' });
      }

      const dismissed = await getDismissedOpportunities(sourceId);

      return res.status(200).json({
        success: true,
        sourceId,
        dismissed,
        count: dismissed.length
      });
    }

    // POST - Dismiss or restore opportunity
    if (req.method === 'POST') {
      const {
        sourceId,
        targetId,
        action = 'dismiss',  // 'dismiss', 'restore', or 'clear'
        reason = ''
      } = req.body;

      if (!sourceId) {
        return res.status(400).json({ error: 'sourceId is required' });
      }

      // Clear all dismissed opportunities for this source
      if (action === 'clear') {
        const result = await clearDismissedOpportunities(sourceId);
        return res.status(200).json({
          success: result.success,
          action: 'clear',
          sourceId,
          clearedCount: result.clearedCount,
          message: `Cleared ${result.clearedCount} dismissed opportunities`
        });
      }

      // Dismiss or restore requires targetId
      if (!targetId) {
        return res.status(400).json({ error: 'targetId is required for dismiss/restore actions' });
      }

      if (action === 'dismiss') {
        const result = await dismissOpportunity(sourceId, targetId, reason);
        return res.status(200).json({
          success: result.success,
          action: 'dismiss',
          sourceId,
          targetId,
          dismissedAt: result.dismissedAt,
          message: `Opportunity dismissed successfully`
        });
      }

      if (action === 'restore') {
        const result = await restoreOpportunity(sourceId, targetId);
        return res.status(200).json({
          success: result.success,
          action: 'restore',
          sourceId,
          targetId,
          message: `Opportunity restored successfully`
        });
      }

      return res.status(400).json({ error: 'Invalid action. Use dismiss, restore, or clear' });
    }

    // DELETE - Same as clear
    if (req.method === 'DELETE') {
      const sourceId = parseInt(req.query.sourceId || req.body?.sourceId);

      if (!sourceId) {
        return res.status(400).json({ error: 'sourceId is required' });
      }

      const result = await clearDismissedOpportunities(sourceId);
      return res.status(200).json({
        success: result.success,
        action: 'clear',
        sourceId,
        clearedCount: result.clearedCount,
        message: `Cleared ${result.clearedCount} dismissed opportunities`
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Dismiss opportunity error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
