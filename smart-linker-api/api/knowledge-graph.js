const {
  extractEntities,
  addEntity,
  buildKnowledgeGraph,
  suggestEntityBasedLinks,
  getEntityStats,
  findRelatedByEntity
} = require('../lib/knowledge-graph');

/**
 * Knowledge Graph / Entity Linking Endpoint
 * Extract entities and suggest links based on shared entities
 *
 * GET /api/knowledge-graph - Get entity statistics
 * POST /api/knowledge-graph - Extract entities, build graph, suggest links
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
      // Get entity statistics
      const stats = getEntityStats();
      return res.status(200).json({
        success: true,
        stats
      });
    }

    if (req.method === 'POST') {
      const { action, content, title, postId, entity, entityType } = req.body;

      // Extract entities from content
      if (action === 'extract' && content) {
        const entities = await extractEntities(content, title || '');
        return res.status(200).json({
          success: true,
          entities
        });
      }

      // Add entity to graph
      if (action === 'add-entity' && entity) {
        const added = addEntity(entity);
        return res.status(200).json({
          success: true,
          entity: added
        });
      }

      // Build knowledge graph from all articles
      if (action === 'build') {
        const graph = await buildKnowledgeGraph();
        return res.status(200).json({
          success: true,
          graph
        });
      }

      // Suggest links based on entities
      if (action === 'suggest-links' && postId) {
        const suggestions = await suggestEntityBasedLinks(parseInt(postId));
        return res.status(200).json({
          success: true,
          suggestions
        });
      }

      // Find related articles by entity type
      if (action === 'find-related' && entityType) {
        const related = findRelatedByEntity(entityType);
        return res.status(200).json({
          success: true,
          related
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['extract', 'add-entity', 'build', 'suggest-links', 'find-related']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Knowledge graph error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
