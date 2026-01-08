const {
  createExperiment,
  createFromTemplate,
  getVariant,
  trackImpression,
  trackClick,
  trackConversion,
  getExperimentResults,
  listExperiments,
  updateExperimentStatus,
  EXPERIMENT_TEMPLATES
} = require('../lib/ab-testing');

/**
 * A/B Testing Endpoint
 * Manage link strategy experiments
 *
 * GET /api/ab-testing - List experiments or get results
 * POST /api/ab-testing - Create experiments, track events, get variants
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
      const { experimentId } = req.query;

      if (experimentId) {
        // Get specific experiment results
        const results = getExperimentResults(experimentId);
        if (!results) {
          return res.status(404).json({ error: 'Experiment not found' });
        }
        return res.status(200).json({
          success: true,
          results
        });
      }

      // List all experiments
      const experiments = listExperiments();
      return res.status(200).json({
        success: true,
        experiments,
        templates: Object.keys(EXPERIMENT_TEMPLATES)
      });
    }

    if (req.method === 'POST') {
      const { action, experimentId, userId, variantId, template, config, status } = req.body;

      // Create experiment from template
      if (action === 'create-from-template' && template) {
        const experiment = createFromTemplate(template);
        if (!experiment) {
          return res.status(400).json({
            error: 'Invalid template',
            validTemplates: Object.keys(EXPERIMENT_TEMPLATES)
          });
        }
        return res.status(200).json({
          success: true,
          experiment
        });
      }

      // Create custom experiment
      if (action === 'create' && config) {
        const experiment = createExperiment(config);
        return res.status(200).json({
          success: true,
          experiment
        });
      }

      // Get variant for user
      if (action === 'get-variant' && experimentId && userId) {
        const variant = getVariant(experimentId, userId);
        return res.status(200).json({
          success: true,
          variant
        });
      }

      // Track impression
      if (action === 'track-impression' && experimentId && variantId) {
        trackImpression(experimentId, variantId);
        return res.status(200).json({ success: true });
      }

      // Track click
      if (action === 'track-click' && experimentId && variantId) {
        trackClick(experimentId, variantId);
        return res.status(200).json({ success: true });
      }

      // Track conversion
      if (action === 'track-conversion' && experimentId && variantId) {
        trackConversion(experimentId, variantId);
        return res.status(200).json({ success: true });
      }

      // Update experiment status
      if (action === 'update-status' && experimentId && status) {
        const experiment = updateExperimentStatus(experimentId, status);
        if (!experiment) {
          return res.status(404).json({ error: 'Experiment not found' });
        }
        return res.status(200).json({
          success: true,
          experiment
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: [
          'create-from-template',
          'create',
          'get-variant',
          'track-impression',
          'track-click',
          'track-conversion',
          'update-status'
        ]
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('A/B testing error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
