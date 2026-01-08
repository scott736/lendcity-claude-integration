const {
  registerOutboundLink,
  checkAllLinks,
  getOutboundLinks,
  analyzeOutboundQuality,
  suggestAuthoritativeSources,
  getOutboundDomainStats,
  findArticlesNeedingOutboundLinks,
  TRUSTED_DOMAINS
} = require('../lib/outbound-link');
const { getAllArticles } = require('../lib/pinecone');

/**
 * Outbound Link Management Endpoint
 * Track, analyze, and optimize outbound links
 *
 * GET /api/outbound-links - Get outbound link stats
 * POST /api/outbound-links - Register links, analyze quality, get suggestions
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

      if (postId) {
        // Get outbound links for specific article
        const links = getOutboundLinks(parseInt(postId));
        const quality = analyzeOutboundQuality(parseInt(postId));

        return res.status(200).json({
          success: true,
          postId: parseInt(postId),
          links,
          quality
        });
      }

      // Get overall outbound link statistics
      const domainStats = getOutboundDomainStats();
      const articles = await getAllArticles();
      const needingLinks = findArticlesNeedingOutboundLinks(articles);

      return res.status(200).json({
        success: true,
        domainStats,
        articlesNeedingLinks: needingLinks.slice(0, 20),
        trustedDomains: TRUSTED_DOMAINS
      });
    }

    if (req.method === 'POST') {
      const { action, postId, link, links, topic, cluster } = req.body;

      // Register single outbound link
      if (action === 'register' && postId && link) {
        const registered = registerOutboundLink(postId, link);
        return res.status(200).json({
          success: true,
          link: registered
        });
      }

      // Register multiple outbound links
      if (action === 'register-batch' && postId && links) {
        const registered = links.map(l => registerOutboundLink(postId, l));
        return res.status(200).json({
          success: true,
          links: registered
        });
      }

      // Check all registered links for health
      if (action === 'check-health') {
        const results = await checkAllLinks();
        return res.status(200).json({
          success: true,
          ...results
        });
      }

      // Analyze outbound quality for article
      if (action === 'analyze' && postId) {
        const quality = analyzeOutboundQuality(postId);
        return res.status(200).json({
          success: true,
          quality
        });
      }

      // Suggest authoritative sources for a topic
      if (action === 'suggest-sources' && topic) {
        const suggestions = await suggestAuthoritativeSources(topic, cluster);
        return res.status(200).json({
          success: true,
          suggestions
        });
      }

      // Find articles needing outbound links
      if (action === 'find-needing-links') {
        const articles = await getAllArticles();
        const needing = findArticlesNeedingOutboundLinks(articles);
        return res.status(200).json({
          success: true,
          articles: needing
        });
      }

      return res.status(400).json({
        error: 'Invalid action',
        validActions: [
          'register',
          'register-batch',
          'check-health',
          'analyze',
          'suggest-sources',
          'find-needing-links'
        ]
      });
    }

    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Outbound links error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
