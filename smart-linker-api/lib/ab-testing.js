/**
 * A/B Testing Framework for Link Strategies
 *
 * Test different linking approaches and measure effectiveness.
 */

const crypto = require('crypto');

// Active experiments storage (in production, use database)
const experiments = new Map();

/**
 * Experiment configuration
 */
const DEFAULT_EXPERIMENT = {
  id: null,
  name: '',
  description: '',
  variants: [],
  trafficAllocation: {}, // { variantId: percentage }
  status: 'draft', // draft, running, paused, completed
  startDate: null,
  endDate: null,
  metrics: {
    impressions: {},
    clicks: {},
    conversions: {}
  }
};

/**
 * Create a new A/B test experiment
 */
function createExperiment(config) {
  const experiment = {
    ...DEFAULT_EXPERIMENT,
    ...config,
    id: config.id || crypto.randomUUID(),
    createdAt: new Date().toISOString()
  };

  // Initialize metrics for each variant
  experiment.variants.forEach(v => {
    experiment.metrics.impressions[v.id] = 0;
    experiment.metrics.clicks[v.id] = 0;
    experiment.metrics.conversions[v.id] = 0;
  });

  experiments.set(experiment.id, experiment);
  return experiment;
}

/**
 * Get variant for a user/session
 * Uses consistent hashing so same user always gets same variant
 */
function getVariant(experimentId, userId) {
  const experiment = experiments.get(experimentId);
  if (!experiment || experiment.status !== 'running') {
    return null;
  }

  // Consistent hash based on experiment + user
  const hash = crypto
    .createHash('md5')
    .update(`${experimentId}-${userId}`)
    .digest('hex');

  const hashValue = parseInt(hash.slice(0, 8), 16) / 0xffffffff;

  // Determine variant based on traffic allocation
  let cumulative = 0;
  for (const variant of experiment.variants) {
    cumulative += (experiment.trafficAllocation[variant.id] || 0) / 100;
    if (hashValue <= cumulative) {
      return variant;
    }
  }

  // Fallback to control
  return experiment.variants[0];
}

/**
 * Apply variant settings to link generation
 */
function applyVariantSettings(baseOptions, variant) {
  if (!variant || !variant.settings) return baseOptions;

  return {
    ...baseOptions,
    ...variant.settings
  };
}

/**
 * Track impression (link shown)
 */
function trackImpression(experimentId, variantId) {
  const experiment = experiments.get(experimentId);
  if (!experiment) return;

  if (!experiment.metrics.impressions[variantId]) {
    experiment.metrics.impressions[variantId] = 0;
  }
  experiment.metrics.impressions[variantId]++;
}

/**
 * Track click (link clicked)
 */
function trackClick(experimentId, variantId) {
  const experiment = experiments.get(experimentId);
  if (!experiment) return;

  if (!experiment.metrics.clicks[variantId]) {
    experiment.metrics.clicks[variantId] = 0;
  }
  experiment.metrics.clicks[variantId]++;
}

/**
 * Track conversion (goal completed)
 */
function trackConversion(experimentId, variantId) {
  const experiment = experiments.get(experimentId);
  if (!experiment) return;

  if (!experiment.metrics.conversions[variantId]) {
    experiment.metrics.conversions[variantId] = 0;
  }
  experiment.metrics.conversions[variantId]++;
}

/**
 * Calculate experiment results
 */
function getExperimentResults(experimentId) {
  const experiment = experiments.get(experimentId);
  if (!experiment) return null;

  const results = {
    experimentId,
    name: experiment.name,
    status: experiment.status,
    variants: []
  };

  for (const variant of experiment.variants) {
    const impressions = experiment.metrics.impressions[variant.id] || 0;
    const clicks = experiment.metrics.clicks[variant.id] || 0;
    const conversions = experiment.metrics.conversions[variant.id] || 0;

    results.variants.push({
      id: variant.id,
      name: variant.name,
      impressions,
      clicks,
      conversions,
      ctr: impressions > 0 ? (clicks / impressions * 100).toFixed(2) : 0,
      conversionRate: clicks > 0 ? (conversions / clicks * 100).toFixed(2) : 0
    });
  }

  // Determine winner
  if (experiment.status === 'completed' || results.variants.every(v => v.impressions >= 100)) {
    const winner = results.variants.reduce((best, current) => {
      return parseFloat(current.conversionRate) > parseFloat(best.conversionRate) ? current : best;
    });
    results.winner = winner.id;
    results.confidence = calculateConfidence(results.variants);
  }

  return results;
}

/**
 * Simple statistical confidence calculation
 */
function calculateConfidence(variants) {
  if (variants.length < 2) return 0;

  const control = variants[0];
  const treatment = variants[1];

  // Simple z-test approximation
  const p1 = control.conversions / Math.max(control.clicks, 1);
  const p2 = treatment.conversions / Math.max(treatment.clicks, 1);
  const n1 = control.clicks || 1;
  const n2 = treatment.clicks || 1;

  const pooledP = (control.conversions + treatment.conversions) / (n1 + n2);
  const se = Math.sqrt(pooledP * (1 - pooledP) * (1/n1 + 1/n2));

  if (se === 0) return 0;

  const z = Math.abs(p2 - p1) / se;

  // Convert z-score to confidence percentage
  if (z >= 2.58) return 99;
  if (z >= 1.96) return 95;
  if (z >= 1.65) return 90;
  if (z >= 1.28) return 80;
  return Math.round(z * 30);
}

/**
 * Pre-built experiment templates
 */
const EXPERIMENT_TEMPLATES = {
  maxLinks: {
    name: 'Optimal Link Count',
    description: 'Test different numbers of internal links per article',
    variants: [
      { id: 'control', name: '3 Links', settings: { maxLinks: 3 } },
      { id: 'treatment_a', name: '5 Links', settings: { maxLinks: 5 } },
      { id: 'treatment_b', name: '7 Links', settings: { maxLinks: 7 } }
    ],
    trafficAllocation: { control: 34, treatment_a: 33, treatment_b: 33 }
  },
  strictSilo: {
    name: 'Silo Enforcement',
    description: 'Test strict vs relaxed topic silos',
    variants: [
      { id: 'control', name: 'Relaxed Silos', settings: { strictSilo: false } },
      { id: 'treatment', name: 'Strict Silos', settings: { strictSilo: true } }
    ],
    trafficAllocation: { control: 50, treatment: 50 }
  },
  claudeAnalysis: {
    name: 'Claude Analysis Impact',
    description: 'Test Claude-powered vs algorithmic anchor selection',
    variants: [
      { id: 'control', name: 'Algorithm Only', settings: { useClaudeAnalysis: false } },
      { id: 'treatment', name: 'Claude Analysis', settings: { useClaudeAnalysis: true } }
    ],
    trafficAllocation: { control: 50, treatment: 50 }
  }
};

/**
 * Create experiment from template
 */
function createFromTemplate(templateName) {
  const template = EXPERIMENT_TEMPLATES[templateName];
  if (!template) return null;

  return createExperiment({
    ...template,
    status: 'draft'
  });
}

/**
 * List all experiments
 */
function listExperiments() {
  return Array.from(experiments.values()).map(e => ({
    id: e.id,
    name: e.name,
    status: e.status,
    variantCount: e.variants.length,
    createdAt: e.createdAt
  }));
}

/**
 * Update experiment status
 */
function updateExperimentStatus(experimentId, status) {
  const experiment = experiments.get(experimentId);
  if (!experiment) return null;

  experiment.status = status;
  if (status === 'running') {
    experiment.startDate = new Date().toISOString();
  } else if (status === 'completed') {
    experiment.endDate = new Date().toISOString();
  }

  return experiment;
}

module.exports = {
  createExperiment,
  createFromTemplate,
  getVariant,
  applyVariantSettings,
  trackImpression,
  trackClick,
  trackConversion,
  getExperimentResults,
  listExperiments,
  updateExperimentStatus,
  EXPERIMENT_TEMPLATES
};
