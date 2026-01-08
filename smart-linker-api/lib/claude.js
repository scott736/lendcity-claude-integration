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
 */
async function generateMeta(article, options = {}) {
  const client = getClient();
  const { focusKeyword = null } = options;

  const prompt = `You are an SEO expert writing meta titles and descriptions.

ARTICLE:
Title: ${article.title}
Summary: ${article.summary || 'N/A'}
Content Preview: ${(article.content || article.body || '').slice(0, 2000)}
Topic Cluster: ${article.topicCluster || 'N/A'}
${focusKeyword ? `Focus Keyword: ${focusKeyword}` : ''}

REQUIREMENTS:
- Meta title: 50-60 characters, include primary keyword near start
- Meta description: 150-160 characters, compelling, include call-to-action
- Canadian real estate focus (LendCity)

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
    return JSON.parse(text);
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
 */
async function analyzeContentForLinking(content, candidates, options = {}) {
  const client = getClient();
  const { maxLinks = 5, existingLinks = [] } = options;

  const candidateList = candidates.map((c, i) =>
    `${i + 1}. "${c.title}" (${c.url}) - ${c.topicCluster} - Score: ${c.score}`
  ).join('\n');

  const existingList = existingLinks.length > 0
    ? `\nEXISTING LINKS IN CONTENT:\n${existingLinks.join('\n')}`
    : '';

  const prompt = `You are an internal linking strategist for a real estate education website.

ARTICLE CONTENT:
${content.slice(0, 4000)}
${existingList}

CANDIDATE ARTICLES TO LINK (ranked by relevance):
${candidateList}

TASK: Select up to ${maxLinks} articles to link from this content.

For each selected link, provide:
1. The candidate number
2. Where in the content to place it (quote the surrounding text)
3. Suggested anchor text
4. Why this link adds value for the reader

Return JSON:
{
  "links": [
    {
      "candidateIndex": 1,
      "placement": "text snippet where link should go",
      "anchorText": "suggested anchor",
      "reasoning": "why this link"
    }
  ],
  "skipped": ["reason for not linking others"]
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 1000,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    // Handle potential markdown code blocks
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    console.error('Failed to parse Claude response:', e);
    // Return top candidates as fallback
    return {
      links: candidates.slice(0, maxLinks).map((c, i) => ({
        candidateIndex: i,
        placement: null,
        anchorText: c.title,
        reasoning: 'Fallback - high relevance score'
      })),
      skipped: ['Could not parse Claude analysis']
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

CONTENT:
${content.slice(0, 6000)}

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

CONTENT:
${content.slice(0, 4000)}

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

module.exports = {
  getClient,
  generateAnchorText,
  generateMeta,
  analyzeContentForLinking,
  generateSummary,
  extractKeywords
};
