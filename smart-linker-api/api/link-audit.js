const { querySimilar, getArticle, getAllArticles } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { getRecommendations } = require('../lib/scoring');

/**
 * SEO-optimized anchor text finder (Expert Level)
 *
 * Smart linking strategies:
 * 1. Avoids generic phrases that could match multiple targets
 * 2. Requires distinctive words from the target title
 * 3. Prevents duplicate anchors across opportunities
 * 4. Supports full sentence anchors for natural reading
 * 5. Prefers anchors in intro/conclusion (higher SEO value)
 * 6. Semantic partial matching - finds phrases with multiple target words
 * 7. Position-based scoring for optimal link placement
 *
 * @param {string} content - HTML content of source article
 * @param {string} contentLower - Lowercase version for searching
 * @param {object} target - Target article with title, topicCluster, etc.
 * @param {Set} usedAnchors - Set of already-used anchor texts (lowercase)
 * @returns {{ text: string, context: string, position: string, score: number } | null}
 */
function findAnchorInContent(content, contentLower, target, usedAnchors = new Set()) {
  // Generic phrases that match too many pages - BLACKLIST
  const genericPhrases = new Set([
    'mortgage financing', 'real estate', 'investment property', 'property investment',
    'lending options', 'loan options', 'financing options', 'mortgage options',
    'property loans', 'real estate loans', 'investment loans', 'home loans',
    'mortgage rates', 'interest rates', 'loan rates', 'best rates',
    'how to get', 'guide to', 'tips for', 'what is', 'how does',
    'learn more', 'find out', 'get started', 'apply now',
    'property financing', 'real estate financing', 'investment financing',
    'mortgage lender', 'lending company', 'loan provider', 'property management',
    'investment strategy', 'financing guide', 'loan guide', 'mortgage guide'
  ]);

  // Stopwords for distinctive word detection
  const stopwords = new Set([
    'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
    'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'been',
    'be', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
    'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'dare', 'ought',
    'used', 'get', 'your', 'our', 'their', 'its', 'his', 'her', 'my',
    'this', 'that', 'these', 'those', 'what', 'which', 'who', 'whom', 'how',
    'when', 'where', 'why', 'all', 'each', 'every', 'both', 'few', 'more',
    'most', 'other', 'some', 'such', 'only', 'own', 'same', 'than', 'too',
    'very', 'just', 'also', 'now', 'here', 'there', 'then', 'once', 'about',
    'mortgage', 'financing', 'property', 'investment', 'loan', 'lending', 'real', 'estate'
  ]);

  // Extract distinctive words from target title (THE KEY to specificity)
  const distinctiveWords = [];
  if (target.title) {
    const words = target.title.toLowerCase().replace(/[^\w\s]/g, '').split(/\s+/);
    for (const word of words) {
      if (word.length >= 4 && !stopwords.has(word)) {
        distinctiveWords.push(word);
      }
    }
  }

  // If no distinctive words found, this target is too generic - skip it
  if (distinctiveWords.length === 0) {
    return null;
  }

  // Remove existing links and strip HTML for searching
  const contentWithoutLinks = content.replace(/<a[^>]*>.*?<\/a>/gi, '');
  const plainText = contentWithoutLinks.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  const plainLower = plainText.toLowerCase();

  // Determine content length for position scoring
  const contentLength = plainText.length;
  const introEnd = Math.min(contentLength * 0.2, 500); // First 20% or 500 chars
  const conclusionStart = contentLength * 0.8; // Last 20%

  // All candidate anchors with scores
  const candidates = [];

  // STRATEGY 1: Find sentences containing multiple distinctive words
  const sentences = plainText.split(/(?<=[.!?])\s+/).filter(s => s.length > 20 && s.length < 150);

  for (const sentence of sentences) {
    const sentenceLower = sentence.toLowerCase();

    // Count how many distinctive words appear in this sentence
    const matchingWords = distinctiveWords.filter(w => sentenceLower.includes(w));

    if (matchingWords.length >= 2) {
      // Good candidate! Check if already used
      const normalizedSentence = sentenceLower.trim();
      if (usedAnchors.has(normalizedSentence)) continue;

      // Find position in content
      const pos = plainLower.indexOf(sentenceLower);

      // Calculate position score (intro/conclusion = higher)
      let positionScore = 1;
      let positionLabel = 'body';
      if (pos < introEnd) {
        positionScore = 1.5;
        positionLabel = 'intro';
      } else if (pos > conclusionStart) {
        positionScore = 1.3;
        positionLabel = 'conclusion';
      }

      // Calculate distinctiveness score
      const distinctScore = matchingWords.length / distinctiveWords.length;

      candidates.push({
        text: sentence.trim(),
        type: 'sentence',
        position: positionLabel,
        score: distinctScore * positionScore * 100,
        matchingWords
      });
    }
  }

  // STRATEGY 2: Find exact n-gram phrases from title (3+ words)
  if (target.title) {
    const titleWords = target.title
      .replace(/[^\w\s]/g, '')
      .split(/\s+/)
      .filter(w => w.length > 2);

    for (let len = Math.min(6, titleWords.length); len >= 3; len--) {
      for (let i = 0; i <= titleWords.length - len; i++) {
        const phrase = titleWords.slice(i, i + len).join(' ');
        const phraseLower = phrase.toLowerCase();

        if (phrase.length < 12) continue;
        if (genericPhrases.has(phraseLower)) continue;
        if (usedAnchors.has(phraseLower)) continue;

        // Must contain at least one distinctive word
        const hasDistinctive = distinctiveWords.some(w => phraseLower.includes(w));
        if (!hasDistinctive) continue;

        const pos = plainLower.indexOf(phraseLower);
        if (pos === -1) continue;

        // Position scoring
        let positionScore = 1;
        let positionLabel = 'body';
        if (pos < introEnd) {
          positionScore = 1.5;
          positionLabel = 'intro';
        } else if (pos > conclusionStart) {
          positionScore = 1.3;
          positionLabel = 'conclusion';
        }

        // Longer phrases are more specific = higher score
        const lengthBonus = len / 3;

        candidates.push({
          text: plainText.substring(pos, pos + phrase.length),
          type: 'phrase',
          position: positionLabel,
          score: 80 * positionScore * lengthBonus,
          matchingWords: distinctiveWords.filter(w => phraseLower.includes(w))
        });
      }
    }
  }

  // STRATEGY 3: Find contextual phrases with distinctive words nearby
  for (const distinctWord of distinctiveWords) {
    const regex = new RegExp(`\\b[\\w\\s]{0,30}${distinctWord}[\\w\\s]{0,30}\\b`, 'gi');
    let match;
    while ((match = regex.exec(plainText)) !== null) {
      const phrase = match[0].trim();
      const phraseLower = phrase.toLowerCase();

      if (phrase.length < 15 || phrase.length > 80) continue;
      if (usedAnchors.has(phraseLower)) continue;

      // Count distinctive words in this phrase
      const matchingWords = distinctiveWords.filter(w => phraseLower.includes(w));
      if (matchingWords.length < 1) continue;

      // Skip if too generic
      let isGeneric = false;
      for (const gen of genericPhrases) {
        if (phraseLower.includes(gen)) {
          isGeneric = true;
          break;
        }
      }
      if (isGeneric) continue;

      const pos = match.index;
      let positionScore = 1;
      let positionLabel = 'body';
      if (pos < introEnd) {
        positionScore = 1.5;
        positionLabel = 'intro';
      } else if (pos > conclusionStart) {
        positionScore = 1.3;
        positionLabel = 'conclusion';
      }

      candidates.push({
        text: phrase,
        type: 'contextual',
        position: positionLabel,
        score: 60 * positionScore * matchingWords.length,
        matchingWords
      });
    }
  }

  // Sort candidates by score (highest first)
  candidates.sort((a, b) => b.score - a.score);

  // Return best candidate
  if (candidates.length > 0) {
    const best = candidates[0];

    // Get context around the anchor
    const pos = plainLower.indexOf(best.text.toLowerCase());
    const contextStart = Math.max(0, pos - 30);
    const contextEnd = Math.min(plainText.length, pos + best.text.length + 30);
    let context = plainText.substring(contextStart, contextEnd);
    if (contextStart > 0) context = '...' + context;
    if (contextEnd < plainText.length) context = context + '...';

    return {
      text: best.text,
      context: context,
      position: best.position,
      score: Math.round(best.score),
      type: best.type,
      matchingWords: best.matchingWords
    };
  }

  return null; // No suitable anchor found
}

/**
 * Link Audit Endpoint
 * Analyzes existing links in content and suggests improvements
 *
 * POST /api/link-audit
 */
module.exports = async function handler(req, res) {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', process.env.ALLOWED_ORIGIN || '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  // Verify API key
  const apiKey = req.headers['authorization']?.replace('Bearer ', '');
  if (apiKey !== process.env.API_SECRET_KEY) {
    return res.status(401).json({ error: 'Unauthorized' });
  }

  try {
    const {
      postId,
      content,
      title,
      existingLinks = [], // Array of { anchor, url, targetId }
      topicCluster,
      maxSuggestions = 5
    } = req.body;

    if (!content) {
      return res.status(400).json({ error: 'content is required' });
    }

    const audit = {
      existing: {
        total: existingLinks.length,
        valid: [],
        broken: [],
        suboptimal: []
      },
      suggestions: {
        upgrades: [],    // Better targets for existing anchors
        missing: [],     // New links that should be added
        redundant: []    // Links that might be removed
      },
      stats: {}
    };

    // Step 1: Validate existing links against Pinecone catalog
    for (const link of existingLinks) {
      const targetArticle = await getArticle(link.targetId);

      if (!targetArticle) {
        // Link points to article not in catalog (might be deleted or external)
        audit.existing.broken.push({
          ...link,
          issue: 'Target article not found in catalog',
          action: 'Consider removing or updating this link'
        });
        continue;
      }

      // Check if there's a better target for this anchor text
      const anchorEmbedding = await generateEmbedding(link.anchor);
      const betterMatches = await querySimilar(anchorEmbedding, {
        topK: 5,
        excludeIds: [postId, link.targetId]
      });

      // Score the current target vs alternatives
      const currentScore = targetArticle.qualityScore || 50;
      const betterOptions = betterMatches.filter(match => {
        const matchScore = match.metadata?.qualityScore || 50;
        const similarity = match.score || 0;
        // Better if: higher quality AND good semantic match
        return matchScore > currentScore && similarity > 0.7;
      });

      if (betterOptions.length > 0) {
        audit.existing.suboptimal.push({
          ...link,
          currentTarget: {
            title: targetArticle.title,
            url: targetArticle.url,
            qualityScore: currentScore
          },
          betterOptions: betterOptions.slice(0, 2).map(opt => ({
            postId: opt.metadata.postId,
            title: opt.metadata.title,
            url: opt.metadata.url,
            qualityScore: opt.metadata.qualityScore || 50,
            similarity: Math.round(opt.score * 100)
          }))
        });
      } else {
        audit.existing.valid.push({
          ...link,
          target: {
            title: targetArticle.title,
            qualityScore: currentScore,
            topicCluster: targetArticle.topicCluster
          },
          status: 'optimal'
        });
      }
    }

    // Step 2: Find missing link opportunities (only where anchor text exists in content)
    const contentText = extractBodyText(content);
    const contentLower = content.toLowerCase();
    const contentEmbedding = await generateEmbedding(`${title || ''} ${contentText}`);

    // Get all candidate articles
    const excludeIds = [postId, ...existingLinks.map(l => l.targetId)];
    const candidates = await querySimilar(contentEmbedding, {
      topK: 30, // Get more candidates since we'll filter by anchor availability
      excludeIds
    });

    if (candidates.length > 0) {
      // Score candidates
      const sourceArticle = { postId, title, topicCluster };
      const { recommendations } = getRecommendations(
        sourceArticle,
        candidates,
        { minScore: 40, maxResults: 50 } // Lower threshold, more results - we'll filter by anchor
      );

      // Filter to only opportunities where anchor text exists in content
      const opportunitiesWithAnchors = [];
      const usedAnchors = new Set(); // Track used anchors to prevent duplicates

      for (const rec of recommendations) {
        const anchor = findAnchorInContent(content, contentLower, rec.candidate, usedAnchors);
        if (anchor) {
          // Add to used set to prevent reuse
          usedAnchors.add(anchor.text.toLowerCase());

          // Build SEO-focused reason based on anchor type
          let reason = '';
          if (anchor.type === 'sentence') {
            reason = `Full sentence in ${anchor.position} contains: ${anchor.matchingWords.join(', ')}`;
          } else if (anchor.type === 'phrase') {
            reason = `Specific phrase match in ${anchor.position}`;
          } else {
            reason = `Contextual match with: ${anchor.matchingWords.join(', ')}`;
          }

          opportunitiesWithAnchors.push({
            postId: rec.candidate.postId,
            title: rec.candidate.title,
            url: rec.candidate.url,
            topicCluster: rec.candidate.topicCluster,
            score: rec.totalScore,
            anchorText: anchor.text,
            anchorContext: anchor.context,
            anchorPosition: anchor.position,
            anchorType: anchor.type,
            anchorScore: anchor.score,
            matchingWords: anchor.matchingWords,
            reason
          });

          // Stop once we have enough
          if (opportunitiesWithAnchors.length >= maxSuggestions) break;
        }
      }

      audit.suggestions.missing = opportunitiesWithAnchors;
    }

    // Step 3: Check for redundant links (multiple links to same cluster)
    const clusterCounts = {};
    for (const link of audit.existing.valid) {
      const cluster = link.target?.topicCluster || 'unknown';
      clusterCounts[cluster] = (clusterCounts[cluster] || 0) + 1;
    }

    for (const [cluster, count] of Object.entries(clusterCounts)) {
      if (count > 2) {
        audit.suggestions.redundant.push({
          cluster,
          count,
          suggestion: `Consider reducing links to "${cluster}" cluster (currently ${count})`
        });
      }
    }

    // Calculate stats
    audit.stats = {
      totalLinks: existingLinks.length,
      validLinks: audit.existing.valid.length,
      brokenLinks: audit.existing.broken.length,
      suboptimalLinks: audit.existing.suboptimal.length,
      missingOpportunities: audit.suggestions.missing.length,
      healthScore: existingLinks.length > 0
        ? Math.round((audit.existing.valid.length / existingLinks.length) * 100)
        : 100
    };

    return res.status(200).json({
      success: true,
      postId,
      audit
    });

  } catch (error) {
    console.error('Link audit error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
