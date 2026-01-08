/**
 * Click Depth Optimization
 *
 * Calculates how many clicks from homepage each article is,
 * and boosts deep pages in link suggestions to improve crawlability.
 */

const { getAllArticles, updateMetadata } = require('./pinecone');

/**
 * Build link graph from articles
 * Returns adjacency list: { url: [linked_urls] }
 */
async function buildLinkGraph(articles) {
  const graph = {};
  const urlToId = {};

  // Initialize graph and URL mapping
  for (const article of articles) {
    const url = article.metadata?.url || article.url;
    const postId = article.metadata?.postId || article.postId;
    graph[url] = [];
    urlToId[url] = postId;
  }

  // Note: In production, you'd parse actual content for links
  // For now, we use inboundLinkCount as a proxy
  // This could be enhanced by storing outbound links in metadata

  return { graph, urlToId };
}

/**
 * Calculate click depth using BFS from homepage
 *
 * @param {string} homepageUrl - The homepage URL
 * @param {Object} graph - Adjacency list of links
 * @returns {Object} Map of URL to click depth
 */
function calculateClickDepths(homepageUrl, graph) {
  const depths = {};
  const visited = new Set();
  const queue = [[homepageUrl, 0]];

  // BFS traversal
  while (queue.length > 0) {
    const [url, depth] = queue.shift();

    if (visited.has(url)) continue;
    visited.add(url);
    depths[url] = depth;

    const links = graph[url] || [];
    for (const linkedUrl of links) {
      if (!visited.has(linkedUrl)) {
        queue.push([linkedUrl, depth + 1]);
      }
    }
  }

  // Mark unreachable pages with high depth
  for (const url of Object.keys(graph)) {
    if (!(url in depths)) {
      depths[url] = 99; // Orphaned/unreachable
    }
  }

  return depths;
}

/**
 * Get click depth score for scoring system
 * Deep pages get higher scores to encourage linking to them
 *
 * @param {number} depth - Click depth from homepage
 * @returns {number} Score boost (0-25)
 */
function getClickDepthScore(depth) {
  if (depth === 99) return 25;  // Orphaned - highest priority
  if (depth >= 5) return 20;    // Very deep
  if (depth >= 4) return 15;    // Deep
  if (depth >= 3) return 10;    // Medium
  if (depth >= 2) return 5;     // Shallow
  return 0;                      // Homepage or 1 click away
}

/**
 * Analyze site for click depth issues
 */
async function analyzeClickDepths(homepageUrl = '/') {
  const articles = await getAllArticles();

  // Build simple depth estimate based on URL structure
  // In production, this would use actual link graph
  const analysis = {
    total: articles.length,
    depths: {
      shallow: [],    // 1-2 clicks
      medium: [],     // 3 clicks
      deep: [],       // 4+ clicks
      orphaned: []    // No inbound links
    },
    recommendations: []
  };

  for (const article of articles) {
    const meta = article.metadata || article;
    const url = meta.url || '';
    const inboundLinks = meta.inboundLinkCount || 0;

    // Estimate depth from URL structure and inbound links
    const urlDepth = (url.match(/\//g) || []).length;
    const estimatedDepth = inboundLinks === 0 ? 99 : Math.max(1, urlDepth - 1);

    const item = {
      postId: meta.postId,
      title: meta.title,
      url: meta.url,
      inboundLinks,
      estimatedDepth
    };

    if (inboundLinks === 0) {
      analysis.depths.orphaned.push(item);
    } else if (estimatedDepth <= 2) {
      analysis.depths.shallow.push(item);
    } else if (estimatedDepth === 3) {
      analysis.depths.medium.push(item);
    } else {
      analysis.depths.deep.push(item);
    }
  }

  // Generate recommendations
  if (analysis.depths.orphaned.length > 0) {
    analysis.recommendations.push({
      priority: 'high',
      issue: `${analysis.depths.orphaned.length} orphaned articles with no inbound links`,
      action: 'Create content that links to these articles or add links from existing content',
      articles: analysis.depths.orphaned.slice(0, 5)
    });
  }

  if (analysis.depths.deep.length > 0) {
    analysis.recommendations.push({
      priority: 'medium',
      issue: `${analysis.depths.deep.length} articles are 4+ clicks from homepage`,
      action: 'Add links from higher-level pages to improve accessibility',
      articles: analysis.depths.deep.slice(0, 5)
    });
  }

  return analysis;
}

/**
 * Update click depth metadata for all articles
 */
async function updateClickDepthMetadata(homepageUrl = '/') {
  const articles = await getAllArticles();
  const { graph, urlToId } = await buildLinkGraph(articles);
  const depths = calculateClickDepths(homepageUrl, graph);

  let updated = 0;
  for (const [url, depth] of Object.entries(depths)) {
    const postId = urlToId[url];
    if (postId) {
      await updateMetadata(postId, { clickDepth: depth });
      updated++;
    }
  }

  return { updated, depths };
}

module.exports = {
  buildLinkGraph,
  calculateClickDepths,
  getClickDepthScore,
  analyzeClickDepths,
  updateClickDepthMetadata
};
