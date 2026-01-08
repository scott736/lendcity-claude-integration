/**
 * Consolidated Analytics Endpoint
 * Combines: content-gaps, link-decay, quality-scoring, readability, topic-clustering, voice-search
 *
 * POST /api/analytics
 * { "endpoint": "content-gaps|link-decay|quality-scoring|readability|topic-clustering|voice-search", ...params }
 */

module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  try {
    const { endpoint, ...params } = req.body || {};

    if (!endpoint) {
      return res.status(400).json({
        error: 'Missing endpoint parameter',
        availableEndpoints: [
          'content-gaps',
          'link-decay',
          'quality-scoring',
          'readability',
          'topic-clustering',
          'voice-search'
        ]
      });
    }

    let handler;
    switch (endpoint) {
      case 'content-gaps':
        handler = require('../lib/content-gaps');
        const gaps = await handler.analyzeContentGaps();
        return res.status(200).json({ success: true, ...gaps });

      case 'link-decay':
        handler = require('../lib/link-decay');
        const decay = await handler.detectDecayingLinks();
        return res.status(200).json({ success: true, ...decay });

      case 'quality-scoring':
        handler = require('../lib/quality-scoring');
        if (params.postId) {
          const quality = await handler.analyzeContentQuality(params.postId, params.content);
          return res.status(200).json({ success: true, quality });
        }
        const allQuality = await handler.analyzeAllContentQuality();
        return res.status(200).json({ success: true, ...allQuality });

      case 'readability':
        handler = require('../lib/readability');
        if (params.content) {
          const analysis = handler.analyzeReadability(params.content);
          return res.status(200).json({ success: true, analysis });
        }
        return res.status(400).json({ error: 'Content required for readability analysis' });

      case 'topic-clustering':
        handler = require('../lib/topic-clustering');
        if (params.action === 'discover') {
          const clusters = await handler.discoverClusters(params);
          return res.status(200).json({ success: true, clusters });
        }
        if (params.action === 'suggest' && params.content) {
          const suggestion = await handler.suggestClusterForArticle(params.content, params.title || '');
          return res.status(200).json({ success: true, suggestion });
        }
        return res.status(400).json({ error: 'Invalid action for topic-clustering' });

      case 'voice-search':
        handler = require('../lib/voice-search');
        if (params.content) {
          const analysis = handler.analyzeVoiceSearchReadiness(params.content, params.title || '');
          return res.status(200).json({ success: true, analysis });
        }
        return res.status(400).json({ error: 'Content required for voice-search analysis' });

      default:
        return res.status(400).json({ error: `Unknown endpoint: ${endpoint}` });
    }
  } catch (error) {
    console.error('Analytics error:', error);
    return res.status(500).json({ error: 'Internal server error', message: error.message });
  }
};
