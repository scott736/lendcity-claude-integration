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
 *
 * IMPORTANT: Supports two dismiss modes:
 * 1. persist: true (default) - Saves to Pinecone metadata, survives restarts
 *    Use for: Per-page ignore decisions (user explicitly doesn't want this link)
 *
 * 2. persist: false - Session-only, in-memory only
 *    Use for: Bulk audit ignore (temporary hide while auditing)
 *
 * This does NOT remove articles from Pinecone - it just marks them as
 * dismissed for the specific source article.
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
        targetIds,           // For bulk operations: array of target IDs
        action = 'dismiss',  // 'dismiss', 'restore', 'clear', or 'bulk_dismiss'
        reason = '',
        persist = true       // true = save to Pinecone, false = session only
      } = req.body;

      if (!sourceId) {
        return res.status(400).json({ error: 'sourceId is required' });
      }

      // Clear all dismissed opportunities for this source
      if (action === 'clear') {
        const result = await clearDismissedOpportunities(sourceId, persist);
        return res.status(200).json({
          success: result.success,
          action: 'clear',
          sourceId,
          clearedCount: result.clearedCount,
          persisted: persist,
          message: persist
            ? `Cleared ${result.clearedCount} dismissed opportunities (removed from Pinecone)`
            : `Cleared ${result.clearedCount} dismissed opportunities (session only)`
        });
      }

      // Bulk dismiss - dismiss multiple targets at once
      if (action === 'bulk_dismiss') {
        if (!targetIds || !Array.isArray(targetIds) || targetIds.length === 0) {
          return res.status(400).json({ error: 'targetIds array is required for bulk_dismiss action' });
        }

        const results = [];
        for (const tid of targetIds) {
          // For bulk operations, persist is typically false (session only)
          const result = await dismissOpportunity(sourceId, tid, reason || 'Bulk dismissed', persist);
          results.push({ targetId: tid, success: result.success });
        }

        const successCount = results.filter(r => r.success).length;

        return res.status(200).json({
          success: successCount > 0,
          action: 'bulk_dismiss',
          sourceId,
          dismissedCount: successCount,
          totalRequested: targetIds.length,
          persisted: persist,
          message: persist
            ? `Dismissed ${successCount}/${targetIds.length} opportunities (saved to Pinecone)`
            : `Dismissed ${successCount}/${targetIds.length} opportunities (session only - will reset on next page load)`,
          results
        });
      }

      // Single dismiss or restore requires targetId
      if (!targetId) {
        return res.status(400).json({ error: 'targetId is required for dismiss/restore actions' });
      }

      if (action === 'dismiss') {
        const result = await dismissOpportunity(sourceId, targetId, reason, persist);
        return res.status(200).json({
          success: result.success,
          action: 'dismiss',
          sourceId,
          targetId,
          dismissedAt: result.dismissedAt,
          persisted: persist,
          message: persist
            ? 'Opportunity dismissed and saved (will not appear again)'
            : 'Opportunity dismissed for this session only'
        });
      }

      if (action === 'restore') {
        const result = await restoreOpportunity(sourceId, targetId, persist);
        return res.status(200).json({
          success: result.success,
          action: 'restore',
          sourceId,
          targetId,
          persisted: persist,
          message: persist
            ? 'Opportunity restored (removed from dismissed list in Pinecone)'
            : 'Opportunity restored for this session'
        });
      }

      return res.status(400).json({
        error: 'Invalid action. Use dismiss, restore, bulk_dismiss, or clear',
        hint: 'For per-page dismiss, use action=dismiss with persist=true. For bulk audit, use action=bulk_dismiss with persist=false.'
      });
    }

    // DELETE - Same as clear
    if (req.method === 'DELETE') {
      const sourceId = parseInt(req.query.sourceId || req.body?.sourceId);
      const persist = req.query.persist !== 'false' && req.body?.persist !== false;

      if (!sourceId) {
        return res.status(400).json({ error: 'sourceId is required' });
      }

      const result = await clearDismissedOpportunities(sourceId, persist);
      return res.status(200).json({
        success: result.success,
        action: 'clear',
        sourceId,
        clearedCount: result.clearedCount,
        persisted: persist,
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
