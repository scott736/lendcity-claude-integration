const Anthropic = require('@anthropic-ai/sdk');

let anthropicClient = null;

/**
 * Initialize Anthropic client (singleton)
 */
function getClient() {
  if (!anthropicClient) {
    anthropicClient = new Anthropic({
      apiKey: process.env.ANTHROPIC_API_KEY
    });
  }
  return anthropicClient;
}

/**
 * Generate optimal anchor text for a link
 */
async function generateAnchorText(sourceContext, targetArticle, options = {}) {
  const client = getClient();
  const { maxAnchors = 3 } = options;

  const prompt = `You are an SEO expert selecting anchor text for internal links.

SOURCE ARTICLE CONTEXT:
${sourceContext}

TARGET ARTICLE TO LINK TO:
Title: ${targetArticle.title}
URL: ${targetArticle.url}
Summary: ${targetArticle.summary || 'N/A'}
Main Topics: ${(targetArticle.mainTopics || []).join(', ')}

TASK: Suggest ${maxAnchors} natural anchor text options that:
1. Flow naturally within the source context
2. Are descriptive but not keyword-stuffed
3. Give readers a clear idea of what they'll find
4. Vary in length (short phrase, medium phrase, longer descriptive)

Return JSON array of strings only, no explanation.
Example: ["BRRRR strategy", "buy rehab rent refinance method", "learn how the BRRRR approach works"]`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 200,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    return JSON.parse(text);
  } catch (e) {
    // Fallback to title-based anchor
    return [targetArticle.title];
  }
}

/**
 * Generate meta title and description for an article
 * Enhanced to consider full content structure: links, clusters, funnel, persona
 *
 * @param {Object} article - Article data (title, summary, content, topicCluster, etc.)
 * @param {Object} options - Options including focusKeyword, internalLinks, relatedClusters
 */
async function generateMeta(article, options = {}) {
  const client = getClient();
  const {
    focusKeyword = null,
    internalLinks = [],      // Links FROM this article (outbound)
    inboundLinks = [],       // Links TO this article (from other posts)
    relatedClusters = [],    // Related topic clusters
    funnelStage = null,      // awareness, consideration, decision
    targetPersona = null     // new-investor, experienced-investor, etc.
  } = options;

  // Build outbound links context (links FROM this article)
  let linksContext = '';
  if (internalLinks.length > 0) {
    linksContext = `
OUTBOUND LINKS FROM THIS ARTICLE:
${internalLinks.map(l => `- "${l.anchorText}" → ${l.title} (${l.topicCluster || 'general'})`).join('\n')}
`;
  }

  // Build inbound links context (links TO this article)
  let inboundContext = '';
  if (inboundLinks.length > 0) {
    inboundContext = `
INBOUND LINKS TO THIS ARTICLE (how other articles refer to this one):
${inboundLinks.map(l => `- "${l.sourceTitle}" links here with anchor "${l.anchorText}" (${l.sourceCluster || 'general'})`).join('\n')}
`;
  }

  // Build content structure context
  let structureContext = '';
  if (relatedClusters.length > 0 || funnelStage || targetPersona) {
    structureContext = `
CONTENT STRUCTURE:
${relatedClusters.length > 0 ? `Related Clusters: ${relatedClusters.join(', ')}` : ''}
${funnelStage ? `Funnel Stage: ${funnelStage}` : ''}
${targetPersona ? `Target Persona: ${targetPersona}` : ''}
`;
  }

  const prompt = `You are an SEO expert writing meta titles and descriptions.

ARTICLE:
Title: ${article.title}
Summary: ${article.summary || 'N/A'}
Content Preview: ${(article.content || article.body || '').slice(0, 2000)}
Topic Cluster: ${article.topicCluster || 'N/A'}
${focusKeyword ? `Focus Keyword: ${focusKeyword}` : ''}
${linksContext}${inboundContext}${structureContext}
REQUIREMENTS:
- Meta title: 50-60 characters, include primary keyword near start
- Meta description: 150-160 characters, compelling, include call-to-action
- Canadian real estate focus (LendCity)
${internalLinks.length > 0 ? '- Reference the linked topics naturally if relevant to the meta description' : ''}
${inboundLinks.length > 0 ? '- Consider the inbound anchor text patterns - these show how other articles describe this content' : ''}
${inboundLinks.length >= 3 ? '- The most common inbound anchor phrases may indicate key terms to include in the meta title' : ''}
${funnelStage === 'awareness' ? '- Use educational, informative language for awareness-stage content' : ''}
${funnelStage === 'consideration' ? '- Use how-to, comparison language for consideration-stage content' : ''}
${funnelStage === 'decision' ? '- Use action-oriented, specific language for decision-stage content' : ''}
${targetPersona === 'new-investor' ? '- Speak to beginners exploring real estate investing' : ''}
${targetPersona === 'experienced-investor' ? '- Speak to seasoned investors looking to optimize' : ''}

Return JSON object:
{
  "metaTitle": "...",
  "metaDescription": "...",
  "reasoning": "brief explanation of choices"
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 300,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    return jsonMatch ? JSON.parse(jsonMatch[0]) : JSON.parse(text);
  } catch (e) {
    return {
      metaTitle: article.title.slice(0, 60),
      metaDescription: (article.summary || article.title).slice(0, 160),
      reasoning: 'Fallback - could not parse Claude response'
    };
  }
}

/**
 * Analyze content for smart linking decisions
 * IMPORTANT: Anchor text must be EXACT phrases that exist in the content
 */
async function analyzeContentForLinking(content, candidates, options = {}) {
  const client = getClient();
  const { maxLinks = 5, existingLinks = [] } = options;

  const candidateList = candidates.map((c, i) =>
    `${i}. "${c.title}" (${c.url}) - Topic: ${c.topicCluster} - Score: ${c.score}`
  ).join('\n');

  const existingList = existingLinks.length > 0
    ? `\nEXISTING LINKS ALREADY IN CONTENT (do not duplicate these):\n${existingLinks.map(l => `- "${l.anchor}" -> ${l.url}`).join('\n')}`
    : '';

  // Use full content for best phrase matching - no truncation
  const contentForAnalysis = content;

  const prompt = `You are an internal linking strategist for a real estate education website.

ARTICLE CONTENT TO ADD LINKS TO:
${contentForAnalysis}
${existingList}

CANDIDATE ARTICLES TO LINK TO (ranked by relevance score):
${candidateList}

TASK: Select up to ${maxLinks} articles to link from this content.

CRITICAL REQUIREMENT - ANCHOR TEXT MUST BE EXACT PHRASES:
- The anchorText MUST be an EXACT phrase that already exists VERBATIM in the article content above
- Do NOT create new text - ONLY use phrases already written in the content
- Find phrases (2-6 words) that naturally relate to each target article's topic
- Copy-paste the exact phrase from the content - spelling and capitalization must match exactly

For each link, provide:
1. candidateIndex: The index number (0-based) from the candidate list
2. anchorText: An EXACT phrase copied from the content above (must exist verbatim)
3. placement: The surrounding sentence or context where the phrase appears
4. reasoning: Why this link helps the reader

Return JSON:
{
  "links": [
    {
      "candidateIndex": 0,
      "anchorText": "exact phrase from content",
      "placement": "the sentence containing the phrase",
      "reasoning": "why this link adds value"
    }
  ],
  "skipped": ["reasons for not linking certain candidates"]
}

IMPORTANT: Before returning, verify each anchorText appears EXACTLY in the content above. If you cannot find a good matching phrase for a candidate, skip it.`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 1500,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    // Handle potential markdown code blocks
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    const parsed = jsonMatch ? JSON.parse(jsonMatch[0]) : JSON.parse(text);

    // Validate that anchor text actually exists in content (case-insensitive check)
    const validatedLinks = parsed.links.filter(link => {
      // Check if anchor exists in content (case-insensitive)
      const anchorLower = link.anchorText.toLowerCase();
      const contentLower = contentForAnalysis.toLowerCase();
      const exists = contentLower.includes(anchorLower);

      if (!exists) {
        console.warn(`Anchor text not found in content, skipping: "${link.anchorText}"`);
        return false;
      }
      return true;
    });

    console.log(`Validated ${validatedLinks.length}/${parsed.links.length} anchor texts exist in content`);

    return {
      links: validatedLinks,
      skipped: parsed.skipped || []
    };
  } catch (e) {
    console.error('Failed to parse Claude response:', e);
    // Return empty - don't use fallback titles as they won't be in content
    return {
      links: [],
      skipped: ['Could not parse Claude analysis - no links added']
    };
  }
}

/**
 * Generate article summary for catalog
 */
async function generateSummary(content, options = {}) {
  const client = getClient();
  const { maxLength = 300 } = options;

  const prompt = `Summarize this real estate article in ${maxLength} characters or less.
Focus on the main topic, key takeaways, and who it's for.

FULL ARTICLE CONTENT:
${content}

Return only the summary, no quotes or labels.`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 150,
    messages: [{ role: 'user', content: prompt }]
  });

  return response.content[0].text.trim();
}

/**
 * Extract semantic keywords and entities from content
 */
async function extractKeywords(content, options = {}) {
  const client = getClient();
  const { maxKeywords = 10 } = options;

  const prompt = `Extract the ${maxKeywords} most important keywords and entities from this real estate content.

FULL ARTICLE CONTENT:
${content}

Return JSON:
{
  "mainTopics": ["primary topic 1", "primary topic 2"],
  "semanticKeywords": ["keyword1", "keyword2", ...],
  "entities": ["specific names, places, strategies mentioned"],
  "readerIntent": "what the reader is trying to learn/do"
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 300,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    return {
      mainTopics: [],
      semanticKeywords: [],
      entities: [],
      readerIntent: 'unknown'
    };
  }
}

/**
 * Auto-analyze article to detect metadata (cluster, funnel, persona, etc.)
 * Uses pillar pages as topic cluster definitions when available.
 * If no pillar matches, Claude creates a new cluster name automatically.
 *
 * @param {string} title - Article title
 * @param {string} content - Article content
 * @param {Array} pillarPages - Array of pillar pages with keywords (optional)
 */
async function autoAnalyzeArticle(title, content, pillarPages = []) {
  const client = getClient();

  // Build pillar context if available
  let pillarContext = '';
  let clusterInstruction = '';

  if (pillarPages && pillarPages.length > 0) {
    pillarContext = `
EXISTING TOPIC CLUSTERS (defined by pillar pages):
${pillarPages.map((p, i) => `${i + 1}. "${p.topicCluster}" - Pillar: "${p.title}"
   Keywords: ${[...(p.mainTopics || []), ...(p.semanticKeywords || [])].slice(0, 10).join(', ')}`).join('\n')}
`;
    clusterInstruction = `"topicCluster": "Match to the most relevant existing cluster above, OR if the content doesn't fit any cluster well, create a new descriptive cluster name (lowercase, hyphenated, e.g., 'multi-family-investing')"`;
  } else {
    clusterInstruction = `"topicCluster": "Create a descriptive cluster name for this content (lowercase, hyphenated, e.g., 'brrrr-strategy', 'private-lending', 'market-analysis')"`;
  }

  const prompt = `You are analyzing a real estate investment education article for a Canadian audience.

TITLE: ${title}

FULL CONTENT:
${content}
${pillarContext}
Analyze this article and return JSON with:

{
  ${clusterInstruction},
  "relatedClusters": ["1-2 related clusters that this content also touches on"],
  "funnelStage": "one of: awareness (educational, what-is), consideration (how-to, comparison), decision (specific tactics, case studies)",
  "targetPersona": "one of: new-investor, experienced-investor, private-lender, rent-to-own-buyer, general",
  "difficultyLevel": "one of: beginner, intermediate, advanced",
  "contentLifespan": "one of: evergreen, timely, seasonal",
  "isPillar": false,
  "qualityScore": 1-100 (based on depth, actionability, uniqueness),
  "matchedPillarId": null or postId of matched pillar if applicable
}

IMPORTANT:
- Posts are NEVER pillar content (isPillar must be false for posts)
- If content matches an existing pillar cluster, use that cluster name exactly
- If content doesn't fit existing clusters well, create a new descriptive cluster name
- New clusters should be lowercase, hyphenated, descriptive (e.g., 'joint-ventures', 'vacation-rentals')

Return ONLY valid JSON, no explanation.`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 400,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    // Return defaults if parsing fails
    return {
      topicCluster: 'general',
      relatedClusters: [],
      funnelStage: 'awareness',
      targetPersona: 'general',
      difficultyLevel: 'intermediate',
      contentLifespan: 'evergreen',
      isPillar: false,
      qualityScore: 50,
      matchedPillarId: null
    };
  }
}

module.exports = {
  getClient,
  generateAnchorText,
  generateMeta,
  analyzeContentForLinking,
  generateSummary,
  extractKeywords,
  autoAnalyzeArticle
};
