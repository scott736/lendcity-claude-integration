const { getAllArticles, getIndex } = require('../lib/pinecone');

/**
 * Catalog Stats Endpoint
 * Returns Pinecone catalog statistics - what's vectorized
 *
 * GET /api/catalog-stats
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  if (req.method !== 'GET') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // Verify API key
  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  try {
    // Get index stats from Pinecone
    const index = getIndex();
    const indexStats = await index.describeIndexStats();

    // Get all articles for detailed breakdown
    const articles = await getAllArticles();

    // Calculate stats by type
    const pageCount = articles.filter(a => a.metadata?.contentType === 'page').length;
    const postCount = articles.filter(a => a.metadata?.contentType === 'post' || !a.metadata?.contentType).length;
    const pillarCount = articles.filter(a => a.metadata?.isPillar === true).length;

    // Group by topic cluster
    const clusterCounts = {};
    articles.forEach(article => {
      const cluster = article.metadata?.topicCluster || 'uncategorized';
      clusterCounts[cluster] = (clusterCounts[cluster] || 0) + 1;
    });

    // Group by funnel stage
    const funnelCounts = {
      awareness: 0,
      consideration: 0,
      decision: 0,
      unknown: 0
    };
    articles.forEach(article => {
      const stage = article.metadata?.funnelStage || 'unknown';
      funnelCounts[stage] = (funnelCounts[stage] || 0) + 1;
    });

    // Group by persona
    const personaCounts = {};
    articles.forEach(article => {
      const persona = article.metadata?.targetPersona || 'general';
      personaCounts[persona] = (personaCounts[persona] || 0) + 1;
    });

    // Get list of all vectorized post IDs
    const vectorizedIds = articles.map(a => ({
      postId: a.metadata?.postId,
      title: a.metadata?.title,
      contentType: a.metadata?.contentType || 'post',
      topicCluster: a.metadata?.topicCluster,
      isPillar: a.metadata?.isPillar || false,
      updatedAt: a.metadata?.updatedAt
    }));

    return res.status(200).json({
      success: true,
      stats: {
        totalVectorized: indexStats.totalRecordCount || articles.length,
        pages: pageCount,
        posts: postCount,
        pillars: pillarCount,
        dimension: indexStats.dimension,
        indexFullness: indexStats.indexFullness
      },
      clusters: Object.entries(clusterCounts)
        .sort((a, b) => b[1] - a[1])
        .map(([name, count]) => ({ name, count })),
      funnelStages: funnelCounts,
      personas: Object.entries(personaCounts)
        .sort((a, b) => b[1] - a[1])
        .map(([name, count]) => ({ name, count })),
      articles: vectorizedIds.sort((a, b) =>
        (a.contentType === 'page' ? 0 : 1) - (b.contentType === 'page' ? 0 : 1)
      )
    });

  } catch (error) {
    console.error('Catalog stats error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
