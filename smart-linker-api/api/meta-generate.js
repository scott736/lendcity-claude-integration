const { generateMeta, extractKeywords } = require('../lib/claude');
const { querySimilar } = require('../lib/pinecone');
const { generateEmbedding, extractBodyText } = require('../lib/embeddings');

/**
 * Meta Generate Endpoint
 * Generates SEO meta titles and descriptions
 *
 * POST /api/meta-generate
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
      title,
      content,
      summary = null,
      topicCluster = null,
      focusKeyword = null,
      // Options
      includeRelatedKeywords = true,
      linkAwareMeta = true
    } = req.body;

    // Validate required fields
    if (!title || !content) {
      return res.status(400).json({
        error: 'Missing required fields',
        required: ['title', 'content']
      });
    }

    // Extract keywords if focus keyword not provided
    let keywords = { mainTopics: [], semanticKeywords: [] };
    let derivedFocusKeyword = focusKeyword;

    if (!focusKeyword || includeRelatedKeywords) {
      keywords = await extractKeywords(content);
      if (!derivedFocusKeyword && keywords.mainTopics.length > 0) {
        derivedFocusKeyword = keywords.mainTopics[0];
      }
    }

    // Get related articles for link-aware meta (optional)
    let relatedArticles = [];
    if (linkAwareMeta) {
      const contentText = extractBodyText(content);
      const embedding = await generateEmbedding(`${title} ${contentText}`);
      const similar = await querySimilar(embedding, {
        topK: 5,
        excludeIds: postId ? [postId] : []
      });
      relatedArticles = similar.map(s => ({
        title: s.metadata?.title,
        cluster: s.metadata?.topicCluster
      }));
    }

    // Build article context for Claude
    const articleContext = {
      title,
      summary: summary || '',
      content,
      topicCluster: topicCluster || keywords.mainTopics[0] || 'general'
    };

    // Generate meta with Claude
    const meta = await generateMeta(articleContext, {
      focusKeyword: derivedFocusKeyword
    });

    // Build response
    const response = {
      success: true,
      meta: {
        title: meta.metaTitle,
        description: meta.metaDescription
      },
      reasoning: meta.reasoning,
      focusKeyword: derivedFocusKeyword,
      keywords: {
        main: keywords.mainTopics,
        semantic: keywords.semanticKeywords
      }
    };

    // Add related content context if link-aware
    if (linkAwareMeta && relatedArticles.length > 0) {
      response.relatedContent = relatedArticles;
      response.linkSuggestion = `Consider linking to: "${relatedArticles[0]?.title}"`;
    }

    return res.status(200).json(response);

  } catch (error) {
    console.error('Meta generate error:', error);
    return res.status(500).json({
      error: 'Internal server error',
      message: error.message
    });
  }
};
