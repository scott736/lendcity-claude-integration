const { getClient: getPinecone } = require('../lib/pinecone');
const { getClient: getOpenAI } = require('../lib/embeddings');
const { getClient: getClaude } = require('../lib/claude');

/**
 * Health Check Endpoint
 * Verifies all service connections are working
 *
 * GET /api/health
 */
module.exports = async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');

  if (req.method !== 'GET') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  const health = {
    status: 'ok',
    timestamp: new Date().toISOString(),
    services: {}
  };

  // Check Pinecone
  try {
    const pinecone = getPinecone();
    health.services.pinecone = {
      status: 'ok',
      index: process.env.PINECONE_INDEX
    };
  } catch (error) {
    health.services.pinecone = {
      status: 'error',
      message: error.message
    };
    health.status = 'degraded';
  }

  // Check OpenAI
  try {
    const openai = getOpenAI();
    health.services.openai = { status: 'ok' };
  } catch (error) {
    health.services.openai = {
      status: 'error',
      message: error.message
    };
    health.status = 'degraded';
  }

  // Check Claude
  try {
    const claude = getClaude();
    health.services.claude = { status: 'ok' };
  } catch (error) {
    health.services.claude = {
      status: 'error',
      message: error.message
    };
    health.status = 'degraded';
  }

  // Check API key is configured
  health.services.auth = {
    status: process.env.API_SECRET_KEY ? 'ok' : 'warning',
    message: process.env.API_SECRET_KEY ? 'API key configured' : 'No API key set'
  };

  const statusCode = health.status === 'ok' ? 200 : 503;
  return res.status(statusCode).json(health);
};
