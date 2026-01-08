const { querySimilar, getArticle, incrementInboundLinks } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');
const { analyzeContentForLinking, generateAnchorText } = require('../lib/claude');
const { getRecommendations } = require('../lib/scoring');

/**
 * Smart Link Endpoint
 * Main endpoint for generating internal link suggestions
 *
 * POST /api/smart-link
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
      // Source article metadata for scoring
      topicCluster,
      relatedClusters = [],
      funnelStage,
      targetPersona,
      // Options
      maxLinks = 5,
      minScore = 40,
      excludeIds = [],
      useClaudeAnalysis = true,
      autoInsert = false
    } = req.body;

    // Validate required fields
    if (!content) {
      return res.status(400).json({
        error: 'Missing required field: content'
      });
    }

    // Build source article metadata
    const sourceArticle = {
      postId,
      title,
      topicCluster,
      relatedClusters,
      funnelStage,
      targetPersona
    };

    // Step 1: Generate embedding for source content
    const contentText = extractBodyText(content);
    const embedding = await generateEmbedding(`${title || ''} ${contentText}`);

    // Step 2: Query Pinecone for similar articles
    const excludeList = [...excludeIds];
    if (postId) excludeList.push(postId);

    const candidates = await querySimilar(embedding, {
      topK: 30, // Get more candidates for scoring
      excludeIds: excludeList
    });

    if (candidates.length === 0) {
      return res.status(200).json({
        success: true,
        links: [],
        message: 'No candidate articles found'
      });
    }

    // Step 3: Apply hybrid scoring (vectors + business rules)
    const { recommendations, totalCandidates, passedFilter, averageScore } = getRecommendations(
      sourceArticle,
      candidates,
      { minScore, maxResults: maxLinks * 2 } // Get extra for Claude to filter
    );

    if (recommendations.length === 0) {
      return res.status(200).json({
        success: true,
        links: [],
        message: 'No articles passed scoring threshold',
        debug: { totalCandidates, minScore }
      });
    }

    // Step 4: Claude analysis for placement and anchors (optional)
    let finalLinks = [];

    if (useClaudeAnalysis && recommendations.length > 0) {
      // Get existing links in content
      const existingLinks = extractExistingLinks(content);

      // Ask Claude to analyze and select best placements
      const analysis = await analyzeContentForLinking(
        content,
        recommendations.map(r => ({
          ...r.candidate,
          score: r.totalScore
        })),
        { maxLinks, existingLinks }
      );

      // Map Claude's selections back to full recommendation data
      finalLinks = analysis.links.map(link => {
        const rec = recommendations[link.candidateIndex];
        return {
          postId: rec.candidate.postId,
          title: rec.candidate.title,
          url: rec.candidate.url,
          topicCluster: rec.candidate.topicCluster,
          score: rec.totalScore,
          scoreBreakdown: rec.breakdown,
          anchorText: link.anchorText,
          placement: link.placement,
          reasoning: link.reasoning
        };
      });
    } else {
      // Without Claude, just use top recommendations with title as anchor
      finalLinks = recommendations.slice(0, maxLinks).map(rec => ({
        postId: rec.candidate.postId,
        title: rec.candidate.title,
        url: rec.candidate.url,
        topicCluster: rec.candidate.topicCluster,
        score: rec.totalScore,
        scoreBreakdown: rec.breakdown,
        anchorText: rec.candidate.title,
        placement: null,
        reasoning: 'Top-scored by hybrid algorithm'
      }));
    }

    // Step 5: Generate content with links inserted (if autoInsert)
    let linkedContent = null;
    if (autoInsert && finalLinks.length > 0) {
      linkedContent = insertLinksIntoContent(content, finalLinks);

      // Update inbound link counts
      for (const link of finalLinks) {
        await incrementInboundLinks(link.postId);
      }
    }

    return res.status(200).json({
      success: true,
      links: finalLinks,
      linkedContent,
      stats: {
        candidatesFound: totalCandidates,
        passedScoring: passedFilter,
        averageScore,
        linksGenerated: finalLinks.length
      }
    });

  } catch (error) {
    console.error('Smart link error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};

/**
 * Extract existing links from content
 */
function extractExistingLinks(content) {
  const links = [];
  const regex = /<a[^>]+href=["']([^"']+)["'][^>]*>([^<]+)<\/a>/gi;
  let match;

  while ((match = regex.exec(content)) !== null) {
    links.push({
      url: match[1],
      anchor: match[2]
    });
  }

  return links;
}

/**
 * Insert links into content at suggested placements
 */
function insertLinksIntoContent(content, links) {
  let result = content;

  for (const link of links) {
    if (!link.placement) continue;

    // Find the placement text in content
    const placementText = link.placement;
    const anchorText = link.anchorText;

    // Check if anchor text exists in the placement context
    if (placementText.includes(anchorText)) {
      // Replace just the anchor text with a link
      const linkedText = placementText.replace(
        anchorText,
        `<a href="${link.url}">${anchorText}</a>`
      );
      result = result.replace(placementText, linkedText);
    } else {
      // Try to find a natural insertion point
      // Look for the first sentence that contains related keywords
      const sentences = result.split(/(?<=[.!?])\s+/);
      for (let i = 0; i < sentences.length; i++) {
        const sentence = sentences[i];
        // Simple heuristic: if sentence relates to the topic, append link
        if (sentence.toLowerCase().includes(link.topicCluster?.replace(/-/g, ' ') || '')) {
          sentences[i] = sentence + ` For more details, see <a href="${link.url}">${anchorText}</a>.`;
          break;
        }
      }
      result = sentences.join(' ');
    }
  }

  return result;
}
