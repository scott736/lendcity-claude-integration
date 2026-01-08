/**
 * Content Gap Analysis
 *
 * Identifies missing content, weak topic coverage,
 * and opportunities for new articles.
 */

const { getAllArticles } = require('./pinecone');
const { CLUSTER_RELATIONSHIPS, FUNNEL_FLOW, PERSONA_COMPATIBILITY } = require('./scoring');

/**
 * Analyze content gaps across all dimensions
 */
async function analyzeContentGaps() {
  const articles = await getAllArticles();

  const gaps = {
    funnelGaps: [],
    clusterGaps: [],
    personaGaps: [],
    linkingOrphans: [],
    thinClusters: [],
    recommendations: []
  };

  // Build coverage maps
  const clusterCoverage = {};
  const funnelByCluster = {};
  const personaByCluster = {};

  for (const article of articles) {
    const meta = article.metadata || article;
    const cluster = meta.topicCluster;
    const funnel = meta.funnelStage;
    const persona = meta.targetPersona;
    const inboundLinks = meta.inboundLinkCount || 0;

    // Track cluster coverage
    if (cluster) {
      clusterCoverage[cluster] = (clusterCoverage[cluster] || 0) + 1;

      // Track funnel stages per cluster
      if (!funnelByCluster[cluster]) {
        funnelByCluster[cluster] = { awareness: 0, consideration: 0, decision: 0 };
      }
      if (funnel && funnelByCluster[cluster][funnel] !== undefined) {
        funnelByCluster[cluster][funnel]++;
      }

      // Track personas per cluster
      if (!personaByCluster[cluster]) {
        personaByCluster[cluster] = {};
      }
      if (persona) {
        personaByCluster[cluster][persona] = (personaByCluster[cluster][persona] || 0) + 1;
      }
    }

    // Find linking orphans
    if (inboundLinks < 2) {
      gaps.linkingOrphans.push({
        postId: meta.postId,
        title: meta.title,
        url: meta.url,
        cluster: meta.topicCluster,
        inboundLinks
      });
    }
  }

  // Analyze funnel gaps per cluster
  for (const [cluster, stages] of Object.entries(funnelByCluster)) {
    const missingStages = [];

    if (stages.awareness === 0) missingStages.push('awareness');
    if (stages.consideration === 0) missingStages.push('consideration');
    if (stages.decision === 0) missingStages.push('decision');

    if (missingStages.length > 0) {
      gaps.funnelGaps.push({
        cluster,
        missingStages,
        currentCoverage: stages,
        priority: missingStages.includes('decision') ? 'high' : 'medium',
        suggestion: `Create ${missingStages.join(', ')} content for "${cluster}"`
      });
    }
  }

  // Find thin clusters (less than 3 articles)
  for (const [cluster, count] of Object.entries(clusterCoverage)) {
    if (count < 3) {
      gaps.thinClusters.push({
        cluster,
        articleCount: count,
        suggestion: `Add ${3 - count} more articles to strengthen "${cluster}" topical authority`
      });
    }
  }

  // Find missing related cluster coverage
  for (const [cluster, relatedClusters] of Object.entries(CLUSTER_RELATIONSHIPS)) {
    if (clusterCoverage[cluster] && clusterCoverage[cluster] > 0) {
      const missingRelated = relatedClusters.filter(rc => !clusterCoverage[rc] || clusterCoverage[rc] === 0);
      if (missingRelated.length > 0) {
        gaps.clusterGaps.push({
          cluster,
          missingRelated,
          suggestion: `Add content for related topics: ${missingRelated.join(', ')}`
        });
      }
    }
  }

  // Generate prioritized recommendations
  gaps.recommendations = generateRecommendations(gaps);

  // Summary stats
  gaps.summary = {
    totalArticles: articles.length,
    totalClusters: Object.keys(clusterCoverage).length,
    orphanedArticles: gaps.linkingOrphans.length,
    funnelGapsCount: gaps.funnelGaps.length,
    thinClustersCount: gaps.thinClusters.length
  };

  return gaps;
}

/**
 * Generate prioritized recommendations from gaps
 */
function generateRecommendations(gaps) {
  const recommendations = [];

  // High priority: Decision stage gaps
  const decisionGaps = gaps.funnelGaps.filter(g => g.missingStages.includes('decision'));
  if (decisionGaps.length > 0) {
    recommendations.push({
      priority: 1,
      type: 'funnel_completion',
      title: 'Missing Decision-Stage Content',
      description: `${decisionGaps.length} clusters have no decision-stage content. Readers can't convert.`,
      action: 'Create how-to-choose or comparison articles',
      items: decisionGaps.slice(0, 5).map(g => ({
        cluster: g.cluster,
        suggestion: `"How to Choose the Right ${formatClusterName(g.cluster)} Approach"`
      }))
    });
  }

  // High priority: Orphaned content
  if (gaps.linkingOrphans.length > 5) {
    recommendations.push({
      priority: 2,
      type: 'linking_health',
      title: 'Orphaned Articles Need Links',
      description: `${gaps.linkingOrphans.length} articles have fewer than 2 inbound links.`,
      action: 'Run smart linker on related content or create bridge articles',
      items: gaps.linkingOrphans.slice(0, 5).map(o => ({
        title: o.title,
        cluster: o.cluster
      }))
    });
  }

  // Medium priority: Thin clusters
  if (gaps.thinClusters.length > 0) {
    recommendations.push({
      priority: 3,
      type: 'topical_authority',
      title: 'Thin Topic Clusters',
      description: `${gaps.thinClusters.length} clusters have fewer than 3 articles.`,
      action: 'Add more content to build topical authority',
      items: gaps.thinClusters.slice(0, 5)
    });
  }

  // Medium priority: Missing related topics
  if (gaps.clusterGaps.length > 0) {
    recommendations.push({
      priority: 4,
      type: 'topic_expansion',
      title: 'Missing Related Topics',
      description: 'Some topic clusters are missing related content.',
      action: 'Create content for related topics to strengthen internal linking',
      items: gaps.clusterGaps.slice(0, 5)
    });
  }

  return recommendations.sort((a, b) => a.priority - b.priority);
}

/**
 * Format cluster name for display
 */
function formatClusterName(cluster) {
  return cluster
    .split('-')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Get content suggestions based on gaps
 */
async function getContentSuggestions(options = {}) {
  const { maxSuggestions = 10 } = options;
  const gaps = await analyzeContentGaps();

  const suggestions = [];

  // Suggest decision-stage content
  for (const gap of gaps.funnelGaps) {
    if (gap.missingStages.includes('decision')) {
      suggestions.push({
        type: 'funnel_completion',
        priority: 'high',
        cluster: gap.cluster,
        funnelStage: 'decision',
        suggestedTitle: `How to Choose the Right ${formatClusterName(gap.cluster)} Strategy`,
        reasoning: `The ${gap.cluster} cluster has no decision-stage content`
      });
    }
  }

  // Suggest bridge content for orphans
  const orphansByCluster = {};
  for (const orphan of gaps.linkingOrphans) {
    const cluster = orphan.cluster || 'general';
    if (!orphansByCluster[cluster]) {
      orphansByCluster[cluster] = [];
    }
    orphansByCluster[cluster].push(orphan);
  }

  for (const [cluster, orphans] of Object.entries(orphansByCluster)) {
    if (orphans.length >= 2) {
      suggestions.push({
        type: 'bridge_content',
        priority: 'medium',
        cluster,
        suggestedTitle: `Complete Guide to ${formatClusterName(cluster)}`,
        reasoning: `Would naturally link to ${orphans.length} orphaned articles`,
        linkedArticles: orphans.slice(0, 3).map(o => o.title)
      });
    }
  }

  // Suggest cluster expansion
  for (const thin of gaps.thinClusters) {
    suggestions.push({
      type: 'cluster_expansion',
      priority: 'medium',
      cluster: thin.cluster,
      suggestedTitle: `${formatClusterName(thin.cluster)}: What You Need to Know`,
      reasoning: `Only ${thin.articleCount} articles in this cluster`
    });
  }

  return suggestions.slice(0, maxSuggestions);
}

/**
 * Find articles that should link to a target but don't
 */
async function findMissingLinks(targetPostId) {
  const articles = await getAllArticles();
  const target = articles.find(a => (a.metadata?.postId || a.postId) === targetPostId);

  if (!target) {
    return { error: 'Target article not found' };
  }

  const targetMeta = target.metadata || target;
  const candidates = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    if (meta.postId === targetPostId) continue;

    // Check if should link based on cluster relationship
    const sameCluster = meta.topicCluster === targetMeta.topicCluster;
    const relatedCluster = (meta.relatedClusters || []).includes(targetMeta.topicCluster) ||
      (targetMeta.relatedClusters || []).includes(meta.topicCluster);

    if (sameCluster || relatedCluster) {
      candidates.push({
        postId: meta.postId,
        title: meta.title,
        url: meta.url,
        cluster: meta.topicCluster,
        relationship: sameCluster ? 'same_cluster' : 'related_cluster'
      });
    }
  }

  return {
    target: {
      postId: targetMeta.postId,
      title: targetMeta.title,
      cluster: targetMeta.topicCluster
    },
    potentialLinkers: candidates,
    count: candidates.length
  };
}

module.exports = {
  analyzeContentGaps,
  getContentSuggestions,
  findMissingLinks,
  generateRecommendations,
  formatClusterName
};
