const { querySimilar, getArticle, getAllArticles } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { getRecommendations, filterByContentType, checkContentTypeLinking } = require('../lib/scoring');
const {
  refreshSEOCache,
  calculateSEOScore,
  getSitewideSEOMetrics,
  getAnchorDiversityScore,
  getReciprocalLinkScore,
  getDismissedOpportunities,
  filterDismissedOpportunities
} = require('../lib/seo-scoring');

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
 * 8. Exact match penalty - avoids over-optimization
 * 9. Word boundary matching - ensures clean word breaks
 * 10. Natural language preference - prefers readable anchors
 *
 * @param {string} content - HTML content of source article
 * @param {string} contentLower - Lowercase version for searching
 * @param {object} target - Target article with title, topicCluster, etc.
 * @param {Set} usedAnchors - Set of already-used anchor texts (lowercase)
 * @returns {{ text: string, context: string, position: string, score: number } | null}
 */
function findAnchorInContent(content, contentLower, target, usedAnchors = new Set()) {
  // Target title normalized for exact match detection
  const targetTitleLower = (target.title || '').toLowerCase().replace(/[^\w\s]/g, '').trim();
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

  // Apply SEO penalties and bonuses before sorting
  for (const candidate of candidates) {
    const candLower = candidate.text.toLowerCase().replace(/[^\w\s]/g, '').trim();

    // PENALTY: Exact match to target title (over-optimization risk)
    if (candLower === targetTitleLower) {
      candidate.score *= 0.6; // 40% penalty
      candidate.exactMatch = true;
    }

    // BONUS: Natural language (contains verbs/action words)
    const naturalWords = ['how', 'why', 'when', 'learn', 'discover', 'explore', 'understand', 'guide', 'about', 'benefits', 'advantages'];
    const hasNaturalFlow = naturalWords.some(w => candLower.includes(w));
    if (hasNaturalFlow) {
      candidate.score *= 1.2; // 20% bonus for natural language
      candidate.naturalLanguage = true;
    }

    // BONUS: Contains brand/location signals (more specific)
    const brandSignals = ['lendcity', 'ontario', 'toronto', 'canada', 'gta'];
    const hasBrandSignal = brandSignals.some(b => candLower.includes(b));
    if (hasBrandSignal) {
      candidate.score *= 1.15; // 15% bonus for brand/geo signals
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
      matchingWords: best.matchingWords,
      isExactMatch: best.exactMatch || false,
      isNaturalLanguage: best.naturalLanguage || false
    };
  }

  return null; // No suitable anchor found
}

/**
 * Calculate link density and provide SEO warnings
 * @param {string} content - HTML content
 * @param {number} existingLinks - Number of existing links
 * @returns {{ density: number, wordCount: number, warnings: string[] }}
 */
function analyzeLinkDensity(content, existingLinks) {
  const plainText = content.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  const wordCount = plainText.split(/\s+/).length;
  const density = existingLinks / (wordCount / 100); // Links per 100 words

  const warnings = [];

  // SEO best practice: 2-3 internal links per 1000 words is optimal
  // More than 5 per 1000 words is over-optimized
  if (density > 0.5) {
    warnings.push(`High link density: ${density.toFixed(2)} links per 100 words. Consider reducing.`);
  }

  if (existingLinks > 10 && wordCount < 1500) {
    warnings.push(`Too many links (${existingLinks}) for content length (${wordCount} words).`);
  }

  if (existingLinks === 0 && wordCount > 500) {
    warnings.push('No internal links found. Add 2-3 relevant internal links.');
  }

  return { density, wordCount, warnings };
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
      contentType = 'post',  // 'page' or 'post' - affects what can be suggested
      maxSuggestions = 5,
      includeSEOMetrics = true
    } = req.body;

    if (!content) {
      return res.status(400).json({ error: 'content is required' });
    }

    // Refresh SEO cache for accurate scoring
    if (includeSEOMetrics) {
      await refreshSEOCache();
    }

    const sourceType = contentType.toLowerCase();

    const audit = {
      existing: {
        total: existingLinks.length,
        valid: [],
        broken: [],
        suboptimal: [],
        contentTypeViolations: []  // Pages linking to posts
      },
      suggestions: {
        upgrades: [],    // Better targets for existing anchors
        missing: [],     // New links that should be added
        redundant: []    // Links that might be removed
      },
      seo: {
        anchorDiversity: [],
        reciprocalLinks: [],
        recommendations: []
      },
      stats: {}
    };

    // For pages, we skip suggestions entirely but still audit existing links
    const isPage = sourceType === 'page';
    if (isPage) {
      audit.note = 'Page content - link suggestions disabled. Only auditing existing links for violations.';
    }

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

      const targetType = (targetArticle.contentType || 'post').toLowerCase();

      // CHECK CONTENT TYPE RULES: Pages should never link to posts
      const contentTypeCheck = checkContentTypeLinking(sourceType, targetType);
      if (!contentTypeCheck.allowed) {
        audit.existing.contentTypeViolations.push({
          ...link,
          sourceType,
          targetType,
          target: {
            title: targetArticle.title,
            url: targetArticle.url,
            contentType: targetType
          },
          issue: contentTypeCheck.reason,
          action: 'REMOVE this link - pages should not link to posts',
          severity: 'error'
        });
        continue; // Don't process further
      }

      // SEO Analysis: Anchor diversity
      if (includeSEOMetrics) {
        const diversityScore = getAnchorDiversityScore(link.anchor, link.targetId);
        if (diversityScore.usage > 3) {
          audit.seo.anchorDiversity.push({
            anchor: link.anchor,
            target: targetArticle.title,
            usage: diversityScore.usage,
            recommendation: diversityScore.recommendation
          });
        }

        // Check for reciprocal links
        const reciprocalCheck = getReciprocalLinkScore(postId, link.targetId);
        if (reciprocalCheck.isReciprocal) {
          audit.seo.reciprocalLinks.push({
            source: postId,
            target: link.targetId,
            targetTitle: targetArticle.title,
            recommendation: reciprocalCheck.recommendation
          });
        }
      }

      // Check if there's a better target for this anchor text
      const anchorEmbedding = await generateEmbedding(link.anchor);
      let betterMatches = await querySimilar(anchorEmbedding, {
        topK: 10,
        excludeIds: [postId, link.targetId]
      });

      // Filter by content type - if source is page, only suggest pages
      betterMatches = filterByContentType(betterMatches, sourceType);

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
            qualityScore: currentScore,
            contentType: targetType
          },
          betterOptions: betterOptions.slice(0, 2).map(opt => ({
            postId: opt.metadata.postId,
            title: opt.metadata.title,
            url: opt.metadata.url,
            qualityScore: opt.metadata.qualityScore || 50,
            contentType: opt.metadata.contentType || 'post',
            similarity: Math.round(opt.score * 100)
          }))
        });
      } else {
        audit.existing.valid.push({
          ...link,
          target: {
            title: targetArticle.title,
            qualityScore: currentScore,
            topicCluster: targetArticle.topicCluster,
            contentType: targetType
          },
          status: 'optimal'
        });
      }
    }

    // Step 2: Find missing link opportunities (only where anchor text exists in content)
    // SKIP for pages - they are manually managed
    if (!isPage) {
      const contentText = extractBodyText(content);
      const contentLower = content.toLowerCase();
      const contentEmbedding = await generateEmbedding(`${title || ''} ${contentText}`);

      // Get all candidate articles
      const excludeIds = [postId, ...existingLinks.map(l => l.targetId)];
      let candidates = await querySimilar(contentEmbedding, {
        topK: 50, // Get more candidates for filtering
        excludeIds
      });

      // Filter by content type - posts can link to pages and posts
      candidates = filterByContentType(candidates, sourceType);

      if (candidates.length > 0) {
        // Score candidates
        const sourceArticle = { postId, title, topicCluster, contentType: sourceType };
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

            // Build SEO-focused reason with quality signals
            let reason = '';
            const signals = [];

            if (anchor.type === 'sentence') {
              reason = `Full sentence in ${anchor.position}`;
            } else if (anchor.type === 'phrase') {
              reason = `Phrase match in ${anchor.position}`;
            } else {
              reason = `Contextual match in ${anchor.position}`;
            }

            // Add quality signals
            if (anchor.isNaturalLanguage) signals.push('✓ Natural language');
            if (anchor.isExactMatch) signals.push('⚠️ Exact match');
            if (anchor.position === 'intro') signals.push('★ Intro placement');
            if (anchor.position === 'conclusion') signals.push('★ Conclusion');
            if (anchor.matchingWords.length >= 2) signals.push(`${anchor.matchingWords.length} keywords`);

            if (signals.length > 0) {
              reason += ` (${signals.join(', ')})`;
            }

            // Calculate SEO score for this opportunity
            let seoData = null;
            if (includeSEOMetrics) {
              seoData = await calculateSEOScore({
                sourceId: postId,
                sourceType,
                targetId: rec.candidate.postId,
                targetType: rec.candidate.contentType || 'post',
                target: rec.candidate,
                anchorText: anchor.text,
                content,
                existingLinks
              });
            }

            opportunitiesWithAnchors.push({
              postId: rec.candidate.postId,
              title: rec.candidate.title,
              url: rec.candidate.url,
              topicCluster: rec.candidate.topicCluster,
              contentType: rec.candidate.contentType || 'post',
              score: rec.totalScore,
              anchorText: anchor.text,
              anchorContext: anchor.context,
              anchorPosition: anchor.position,
              anchorType: anchor.type,
              anchorScore: anchor.score,
              matchingWords: anchor.matchingWords,
              isExactMatch: anchor.isExactMatch,
              isNaturalLanguage: anchor.isNaturalLanguage,
              reason,
              seo: seoData ? {
                score: seoData.totalSEOScore,
                breakdown: seoData.breakdown
              } : null
            });

            // Stop once we have enough
            if (opportunitiesWithAnchors.length >= maxSuggestions) break;
          }
        }

        // Sort by combined score (relevance + SEO)
        if (includeSEOMetrics) {
          opportunitiesWithAnchors.sort((a, b) => {
            const aTotal = a.score + (a.seo?.score || 0) * 0.3;
            const bTotal = b.score + (b.seo?.score || 0) * 0.3;
            return bTotal - aTotal;
          });
        }

        // Load and filter out dismissed opportunities
        await getDismissedOpportunities(postId); // Populates cache
        const filteredOpportunities = filterDismissedOpportunities(postId, opportunitiesWithAnchors);

        // Track how many were filtered out
        audit.suggestions.dismissedCount = opportunitiesWithAnchors.length - filteredOpportunities.length;
        audit.suggestions.missing = filteredOpportunities;
      }
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

    // Analyze link density for SEO warnings
    const densityAnalysis = analyzeLinkDensity(content, existingLinks.length);

    // Get site-wide SEO metrics
    let sitewideMetrics = null;
    if (includeSEOMetrics) {
      sitewideMetrics = await getSitewideSEOMetrics();
    }

    // Generate SEO recommendations
    if (includeSEOMetrics) {
      // Anchor diversity warnings
      if (audit.seo.anchorDiversity.length > 0) {
        audit.seo.recommendations.push({
          type: 'anchor_diversity',
          severity: 'warning',
          message: `${audit.seo.anchorDiversity.length} anchors are overused. Consider varying anchor text.`,
          details: audit.seo.anchorDiversity
        });
      }

      // Reciprocal link warnings
      if (audit.seo.reciprocalLinks.length > 0) {
        audit.seo.recommendations.push({
          type: 'reciprocal_links',
          severity: 'info',
          message: `${audit.seo.reciprocalLinks.length} reciprocal links detected. Consider one-way linking.`,
          details: audit.seo.reciprocalLinks
        });
      }

      // Content type violation errors
      if (audit.existing.contentTypeViolations.length > 0) {
        audit.seo.recommendations.push({
          type: 'content_type_violation',
          severity: 'error',
          message: `${audit.existing.contentTypeViolations.length} invalid links: pages should not link to posts. Remove these links.`,
          details: audit.existing.contentTypeViolations
        });
      }
    }

    // Calculate stats with SEO insights
    audit.stats = {
      totalLinks: existingLinks.length,
      validLinks: audit.existing.valid.length,
      brokenLinks: audit.existing.broken.length,
      suboptimalLinks: audit.existing.suboptimal.length,
      contentTypeViolations: audit.existing.contentTypeViolations.length,
      missingOpportunities: audit.suggestions.missing.length,
      dismissedOpportunities: audit.suggestions.dismissedCount || 0,  // Excluded from count
      healthScore: existingLinks.length > 0
        ? Math.round((audit.existing.valid.length / existingLinks.length) * 100)
        : 100,
      // SEO metrics
      contentType: sourceType,
      wordCount: densityAnalysis.wordCount,
      linkDensity: Math.round(densityAnalysis.density * 100) / 100,
      seoWarnings: [
        ...densityAnalysis.warnings,
        ...(audit.existing.contentTypeViolations.length > 0
          ? [`CRITICAL: ${audit.existing.contentTypeViolations.length} page-to-post links found - remove these`]
          : [])
      ],
      // Site-wide health
      sitewideHealth: sitewideMetrics?.health || null
    };

    return res.status(200).json({
      success: true,
      postId,
      contentType: sourceType,
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
