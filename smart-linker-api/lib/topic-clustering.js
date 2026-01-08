/**
 * Automatic Topic Clustering
 *
 * Uses vector embeddings to automatically discover and assign
 * topic clusters to content.
 */

const { getAllArticles, updateMetadata } = require('./pinecone');
const { generateEmbedding, cosineSimilarity } = require('./embeddings');

/**
 * Discover topic clusters from existing content using k-means-like approach
 */
async function discoverClusters(options = {}) {
  const {
    minClusterSize = 3,
    similarityThreshold = 0.75
  } = options;

  const articles = await getAllArticles();

  // Group articles by existing cluster
  const existingClusters = {};
  const unclustered = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    const cluster = meta.topicCluster;

    if (cluster && cluster !== 'uncategorized') {
      if (!existingClusters[cluster]) {
        existingClusters[cluster] = [];
      }
      existingClusters[cluster].push({
        postId: meta.postId,
        title: meta.title,
        embedding: article.values // Vector from Pinecone
      });
    } else {
      unclustered.push({
        postId: meta.postId,
        title: meta.title,
        embedding: article.values
      });
    }
  }

  // Calculate cluster centroids (average embedding)
  const clusterCentroids = {};
  for (const [cluster, members] of Object.entries(existingClusters)) {
    if (members.length >= minClusterSize && members[0].embedding) {
      const dims = members[0].embedding.length;
      const centroid = new Array(dims).fill(0);

      for (const member of members) {
        if (member.embedding) {
          for (let i = 0; i < dims; i++) {
            centroid[i] += member.embedding[i] / members.length;
          }
        }
      }
      clusterCentroids[cluster] = centroid;
    }
  }

  // Suggest clusters for unclustered articles
  const suggestions = [];
  for (const article of unclustered) {
    if (!article.embedding) continue;

    let bestCluster = null;
    let bestSimilarity = 0;

    for (const [cluster, centroid] of Object.entries(clusterCentroids)) {
      const similarity = cosineSimilarity(article.embedding, centroid);
      if (similarity > bestSimilarity && similarity >= similarityThreshold) {
        bestSimilarity = similarity;
        bestCluster = cluster;
      }
    }

    suggestions.push({
      postId: article.postId,
      title: article.title,
      suggestedCluster: bestCluster,
      confidence: bestSimilarity,
      needsManualReview: !bestCluster || bestSimilarity < 0.8
    });
  }

  return {
    existingClusters: Object.keys(existingClusters).map(c => ({
      name: c,
      articleCount: existingClusters[c].length
    })),
    unclusteredCount: unclustered.length,
    suggestions: suggestions.sort((a, b) => b.confidence - a.confidence),
    clusterHealth: {
      totalClusters: Object.keys(existingClusters).length,
      avgClusterSize: Object.values(existingClusters).reduce((sum, c) => sum + c.length, 0) /
                      Math.max(Object.keys(existingClusters).length, 1)
    }
  };
}

/**
 * Suggest cluster for a single article based on content
 */
async function suggestClusterForArticle(content, title) {
  const embedding = await generateEmbedding(`${title} ${content.slice(0, 4000)}`);
  const articles = await getAllArticles();

  // Build cluster centroids
  const clusterEmbeddings = {};
  for (const article of articles) {
    const meta = article.metadata || article;
    const cluster = meta.topicCluster;
    if (cluster && article.values) {
      if (!clusterEmbeddings[cluster]) {
        clusterEmbeddings[cluster] = [];
      }
      clusterEmbeddings[cluster].push(article.values);
    }
  }

  // Calculate similarities to each cluster
  const clusterScores = [];
  for (const [cluster, embeddings] of Object.entries(clusterEmbeddings)) {
    // Average similarity to cluster members
    let totalSim = 0;
    for (const emb of embeddings) {
      totalSim += cosineSimilarity(embedding, emb);
    }
    const avgSim = totalSim / embeddings.length;
    clusterScores.push({ cluster, similarity: avgSim, memberCount: embeddings.length });
  }

  clusterScores.sort((a, b) => b.similarity - a.similarity);

  return {
    topSuggestion: clusterScores[0] || null,
    alternatives: clusterScores.slice(1, 4),
    confidence: clusterScores[0]?.similarity || 0
  };
}

/**
 * Find articles that might be misclustered
 */
async function findMisclusteredArticles(threshold = 0.5) {
  const clusters = await discoverClusters();
  const articles = await getAllArticles();

  const potentialIssues = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    const currentCluster = meta.topicCluster;

    if (!currentCluster || !article.values) continue;

    // Check similarity to current cluster vs others
    const suggestion = await suggestClusterForArticle('', meta.title);

    if (suggestion.topSuggestion &&
        suggestion.topSuggestion.cluster !== currentCluster &&
        suggestion.topSuggestion.similarity > threshold) {
      potentialIssues.push({
        postId: meta.postId,
        title: meta.title,
        currentCluster,
        suggestedCluster: suggestion.topSuggestion.cluster,
        confidence: suggestion.topSuggestion.similarity
      });
    }
  }

  return potentialIssues.sort((a, b) => b.confidence - a.confidence);
}

module.exports = {
  discoverClusters,
  suggestClusterForArticle,
  findMisclusteredArticles
};
