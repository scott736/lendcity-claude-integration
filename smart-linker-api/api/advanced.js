/**
 * Consolidated Advanced Features Endpoint
 * Combines: ab-testing, knowledge-graph, outbound-links, schema-org, seasonal-boosting, strategic-content
 *
 * POST /api/advanced
 * { "endpoint": "ab-testing|knowledge-graph|outbound-links|schema-org|seasonal-boosting|strategic-content", ...params }
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
          'ab-testing',
          'knowledge-graph',
          'outbound-links',
          'schema-org',
          'seasonal-boosting',
          'strategic-content'
        ]
      });
    }

    let handler;
    switch (endpoint) {
      case 'ab-testing':
        handler = require('../lib/ab-testing');
        if (params.action === 'create-from-template' && params.template) {
          const experiment = handler.createFromTemplate(params.template);
          return res.status(200).json({ success: true, experiment });
        }
        if (params.action === 'get-variant' && params.experimentId && params.userId) {
          const variant = handler.getVariant(params.experimentId, params.userId);
          return res.status(200).json({ success: true, variant });
        }
        if (params.action === 'results' && params.experimentId) {
          const results = handler.getExperimentResults(params.experimentId);
          return res.status(200).json({ success: true, results });
        }
        const experiments = handler.listExperiments();
        return res.status(200).json({ success: true, experiments });

      case 'knowledge-graph':
        handler = require('../lib/knowledge-graph');
        if (params.action === 'extract' && params.content) {
          const entities = await handler.extractEntities(params.content, params.title || '');
          return res.status(200).json({ success: true, entities });
        }
        if (params.action === 'suggest-links' && params.postId) {
          const suggestions = await handler.suggestEntityBasedLinks(params.postId);
          return res.status(200).json({ success: true, suggestions });
        }
        const stats = handler.getEntityStats();
        return res.status(200).json({ success: true, stats });

      case 'outbound-links':
        handler = require('../lib/outbound-link');
        if (params.action === 'suggest-sources' && params.topic) {
          const suggestions = await handler.suggestAuthoritativeSources(params.topic, params.cluster);
          return res.status(200).json({ success: true, suggestions });
        }
        if (params.postId) {
          const quality = handler.analyzeOutboundQuality(params.postId);
          return res.status(200).json({ success: true, quality });
        }
        const domainStats = handler.getOutboundDomainStats();
        return res.status(200).json({ success: true, domainStats });

      case 'schema-org':
        handler = require('../lib/schema-org');
        if (params.article) {
          const schema = params.action === 'auto' && params.content
            ? await handler.autoGenerateSchema(params.article, params.content)
            : handler.generateArticleSchema(params.article, params.options || {});
          return res.status(200).json({ success: true, schema, jsonLd: JSON.stringify(schema, null, 2) });
        }
        return res.status(400).json({ error: 'Article data required for schema generation' });

      case 'seasonal-boosting':
        handler = require('../lib/seasonal-boosting');
        if (params.candidates) {
          const boosted = handler.applySeasonalBoosting(params.candidates);
          return res.status(200).json({ success: true, candidates: boosted });
        }
        const currentTopics = handler.getCurrentSeasonalTopics();
        const suggestions = handler.getUpcomingSeasonalSuggestions(params.lookaheadMonths || 2);
        return res.status(200).json({ success: true, currentTopics, suggestions });

      case 'strategic-content':
        handler = require('../lib/strategic-content');
        if (params.action === 'analyze-transcript' && params.transcript) {
          const analysis = await handler.analyzeTranscript(params.transcript);
          return res.status(200).json({ success: true, analysis });
        }
        if (params.action === 'generate-outline' && params.topic) {
          const outline = await handler.generateArticleOutline(params.topic, params.cluster, params.funnel);
          return res.status(200).json({ success: true, outline });
        }
        return res.status(400).json({ error: 'Invalid action for strategic-content' });

      default:
        return res.status(400).json({ error: `Unknown endpoint: ${endpoint}` });
    }
  } catch (error) {
    console.error('Advanced features error:', error);
    return res.status(500).json({ error: 'Internal server error', message: error.message });
  }
};
