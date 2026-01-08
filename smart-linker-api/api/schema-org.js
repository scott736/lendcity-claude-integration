const {
  generateArticleSchema,
  generateHowToSchema,
  generateFAQSchema,
  generateBreadcrumbSchema,
  autoGenerateSchema
} = require('../lib/schema-org');
const { getArticle } = require('../lib/pinecone');

/**
 * Schema.org Structured Data Endpoint
 * Generate JSON-LD structured data for articles
 *
 * GET /api/schema-org?postId=123 - Get schema for article
 * POST /api/schema-org - Generate schema for content
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
      const { postId } = req.query;

      if (!postId) {
        return res.status(400).json({ error: 'postId is required' });
      }

      // Get article from catalog
      const article = await getArticle(parseInt(postId));
      if (!article) {
        return res.status(404).json({ error: 'Article not found' });
      }

      // Generate article schema
      const schema = generateArticleSchema(article);
      return res.status(200).json({
        success: true,
        schema,
        jsonLd: JSON.stringify(schema, null, 2)
      });
    }

    if (req.method === 'POST') {
      const { action, article, content, steps, questions, breadcrumbs, options } = req.body;

      // Auto-generate appropriate schema
      if (action === 'auto' && article && content) {
        const schema = await autoGenerateSchema(article, content);
        return res.status(200).json({
          success: true,
          schema,
          jsonLd: JSON.stringify(schema, null, 2)
        });
      }

      // Generate article schema
      if (action === 'article' && article) {
        const schema = generateArticleSchema(article, options || {});
        return res.status(200).json({
          success: true,
          schema,
          jsonLd: JSON.stringify(schema, null, 2)
        });
      }

      // Generate how-to schema
      if (action === 'howto' && article && steps) {
        const schema = generateHowToSchema(article, steps);
        return res.status(200).json({
          success: true,
          schema,
          jsonLd: JSON.stringify(schema, null, 2)
        });
      }

      // Generate FAQ schema
      if (action === 'faq' && questions) {
        const schema = generateFAQSchema(questions);
        return res.status(200).json({
          success: true,
          schema,
          jsonLd: JSON.stringify(schema, null, 2)
        });
      }

      // Generate breadcrumb schema
      if (action === 'breadcrumb' && breadcrumbs) {
        const schema = generateBreadcrumbSchema(breadcrumbs);
        return res.status(200).json({
          success: true,
          schema,
          jsonLd: JSON.stringify(schema, null, 2)
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: ['auto', 'article', 'howto', 'faq', 'breadcrumb']
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Schema.org error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
