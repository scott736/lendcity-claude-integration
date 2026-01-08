/**
 * Link Upgrade Analyzer
 *
 * Compares existing links to new vector-based suggestions
 * and determines if an upgrade is worthwhile.
 */

const { querySimilar, getArticle } = require('./pinecone');
const { generateEmbedding } = require('./embeddings');
const { calculateHybridScore } = require('./scoring');

// Minimum improvement threshold to trigger upgrade (percentage)
const UPGRADE_THRESHOLD = 15; // 15% better score required

// Weights for upgrade decision
const UPGRADE_FACTORS = {
  scoreImprovement: 0.4,      // How much better is the new score?
  semanticRelevance: 0.3,     // Is the new link more semantically relevant?
  linkFreshness: 0.15,        // Is existing link to outdated content?
  linkBalance: 0.15           // Does upgrade improve site-wide link distribution?
};

/**
 * Analyze existing links in an article
 */
function extractExistingLinks(content) {
  const linkRegex = /<a\s+[^>]*href=["']([^"']+)["'][^>]*>(.*?)<\/a>/gi;
  const links = [];
  let match;

  while ((match = linkRegex.exec(content)) !== null) {
    const url = match[1];
    const anchorText = match[2].replace(/<[^>]*>/g, '').trim();

    // Only internal links
    if (!url.startsWith('http') || url.includes('lendcity.ca')) {
      links.push({
        url,
        anchorText,
        position: match.index
      });
    }
  }

  return links;
}

/**
 * Get post ID from URL
 */
function extractPostIdFromUrl(url) {
  // Handle various URL formats
  const patterns = [
    /[?&]p=(\d+)/,           // ?p=123
    /\/(\d+)\/?$/,           // /123/
    /post_id=(\d+)/          // post_id=123
  ];

  for (const pattern of patterns) {
    const match = url.match(pattern);
    if (match) return parseInt(match[1]);
  }

  return null;
}

/**
 * Score an existing link
 */
async function scoreExistingLink(sourceArticle, targetUrl, anchorText) {
  const targetPostId = extractPostIdFromUrl(targetUrl);

  if (!targetPostId) {
    return { score: 0, reason: 'Cannot identify target article' };
  }

  const targetArticle = await getArticle(targetPostId);

  if (!targetArticle) {
    return { score: 0, reason: 'Target article not in catalog' };
  }

  // Calculate score using same system as new suggestions
  const score = calculateHybridScore(
    0.7, // Assume moderate vector similarity for existing link
    sourceArticle,
    targetArticle
  );

  return {
    score: score.finalScore,
    postId: targetPostId,
    title: targetArticle.title,
    details: score
  };
}

/**
 * Compare existing links to new vector suggestions
 */
async function analyzeUpgradePotential(postId, content, options = {}) {
  const {
    threshold = UPGRADE_THRESHOLD,
    maxSuggestions = 10
  } = options;

  // Get source article
  const sourceArticle = await getArticle(postId);
  if (!sourceArticle) {
    return { error: 'Source article not found in catalog' };
  }

  // Extract existing links
  const existingLinks = extractExistingLinks(content);

  if (existingLinks.length === 0) {
    return {
      recommendation: 'ADD_NEW',
      reason: 'No existing internal links found',
      suggestFullLinking: true
    };
  }

  // Score existing links
  const existingScores = [];
  for (const link of existingLinks) {
    const score = await scoreExistingLink(sourceArticle, link.url, link.anchorText);
    existingScores.push({
      ...link,
      ...score
    });
  }

  const avgExistingScore = existingScores.reduce((sum, l) => sum + l.score, 0) / existingScores.length;

  // Get new vector-based suggestions
  const embedding = await generateEmbedding(`${sourceArticle.title} ${content.substring(0, 2000)}`);

  const vectorResults = await querySimilar(embedding, maxSuggestions + existingLinks.length, {
    filter: {
      postId: { $ne: postId }
    }
  });

  // Score new suggestions
  const newSuggestions = vectorResults.matches
    .filter(match => !existingLinks.some(el => extractPostIdFromUrl(el.url) === match.metadata.postId))
    .map(match => {
      const score = calculateHybridScore(
        match.score,
        sourceArticle,
        match.metadata
      );
      return {
        postId: match.metadata.postId,
        title: match.metadata.title,
        url: match.metadata.url,
        vectorSimilarity: match.score,
        finalScore: score.finalScore,
        details: score
      };
    })
    .sort((a, b) => b.finalScore - a.finalScore)
    .slice(0, maxSuggestions);

  const avgNewScore = newSuggestions.length > 0
    ? newSuggestions.reduce((sum, s) => sum + s.finalScore, 0) / newSuggestions.length
    : 0;

  // Calculate improvement potential
  const improvementPercent = avgExistingScore > 0
    ? ((avgNewScore - avgExistingScore) / avgExistingScore) * 100
    : 100;

  // Find specific upgrade opportunities
  const upgradeOpportunities = [];

  for (const existing of existingScores) {
    // Find better alternatives for this link
    const betterAlternatives = newSuggestions.filter(
      s => s.finalScore > existing.score * (1 + threshold / 100)
    );

    if (betterAlternatives.length > 0) {
      upgradeOpportunities.push({
        currentLink: {
          url: existing.url,
          anchorText: existing.anchorText,
          score: existing.score,
          title: existing.title
        },
        betterAlternative: betterAlternatives[0],
        improvementPercent: ((betterAlternatives[0].finalScore - existing.score) / existing.score) * 100
      });
    }
  }

  // Determine recommendation
  let recommendation;
  let reason;

  if (improvementPercent >= threshold && upgradeOpportunities.length > 0) {
    recommendation = 'UPGRADE';
    reason = `Found ${upgradeOpportunities.length} links that can be improved by ${Math.round(improvementPercent)}%`;
  } else if (newSuggestions.length > existingLinks.length) {
    recommendation = 'ADD_MORE';
    reason = `Current links are good, but ${newSuggestions.length - existingLinks.length} additional links could be added`;
  } else {
    recommendation = 'KEEP';
    reason = `Existing links are optimal (only ${Math.round(improvementPercent)}% potential improvement)`;
  }

  return {
    postId,
    recommendation,
    reason,
    threshold,
    stats: {
      existingLinkCount: existingLinks.length,
      avgExistingScore: Math.round(avgExistingScore),
      avgNewScore: Math.round(avgNewScore),
      improvementPercent: Math.round(improvementPercent)
    },
    existingLinks: existingScores,
    newSuggestions: newSuggestions.slice(0, 5),
    upgradeOpportunities,
    shouldUpgrade: recommendation === 'UPGRADE'
  };
}

/**
 * Batch analyze multiple articles for upgrade potential
 */
async function batchAnalyzeUpgrades(articles, options = {}) {
  const results = {
    upgrade: [],
    addMore: [],
    keep: [],
    errors: []
  };

  for (const article of articles) {
    try {
      const analysis = await analyzeUpgradePotential(
        article.postId,
        article.content,
        options
      );

      if (analysis.error) {
        results.errors.push({ postId: article.postId, error: analysis.error });
      } else if (analysis.recommendation === 'UPGRADE') {
        results.upgrade.push(analysis);
      } else if (analysis.recommendation === 'ADD_MORE') {
        results.addMore.push(analysis);
      } else {
        results.keep.push(analysis);
      }
    } catch (error) {
      results.errors.push({ postId: article.postId, error: error.message });
    }
  }

  return {
    summary: {
      total: articles.length,
      needsUpgrade: results.upgrade.length,
      canAddMore: results.addMore.length,
      optimal: results.keep.length,
      errors: results.errors.length
    },
    ...results
  };
}

/**
 * Auto-upgrade links if improvement meets threshold
 */
async function autoUpgradeLinks(postId, content, options = {}) {
  const analysis = await analyzeUpgradePotential(postId, content, options);

  if (!analysis.shouldUpgrade) {
    return {
      upgraded: false,
      reason: analysis.reason,
      analysis
    };
  }

  // Return upgrade plan (actual replacement done by WordPress)
  return {
    upgraded: true,
    reason: analysis.reason,
    changes: analysis.upgradeOpportunities.map(opp => ({
      remove: {
        url: opp.currentLink.url,
        anchorText: opp.currentLink.anchorText
      },
      add: {
        postId: opp.betterAlternative.postId,
        title: opp.betterAlternative.title,
        url: opp.betterAlternative.url,
        score: opp.betterAlternative.finalScore
      },
      improvement: `${Math.round(opp.improvementPercent)}% better`
    })),
    analysis
  };
}

module.exports = {
  UPGRADE_THRESHOLD,
  extractExistingLinks,
  scoreExistingLink,
  analyzeUpgradePotential,
  batchAnalyzeUpgrades,
  autoUpgradeLinks
};
