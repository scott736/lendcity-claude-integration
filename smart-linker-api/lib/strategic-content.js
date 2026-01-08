/**
 * Claude-Powered Strategic Content Creation
 *
 * Analyzes transcripts and creates articles strategically
 * based on content gaps and linking opportunities.
 */

const { analyzeContentGaps, getContentSuggestions } = require('./content-gaps');
const { getAllArticles, querySimilar } = require('./pinecone');
const { generateEmbedding } = require('./embeddings');
const { getClient } = require('./claude');

/**
 * Analyze transcript for content opportunities
 */
async function analyzeTranscript(transcript, options = {}) {
  const {
    guestInfo = null,
    maxSuggestions = 3
  } = options;

  const client = getClient();
  const gaps = await analyzeContentGaps();
  const existingArticles = await getAllArticles();

  // Get recent articles for context
  const recentTitles = existingArticles
    .slice(0, 30)
    .map(a => {
      const meta = a.metadata || a;
      return `"${meta.title}" (${meta.topicCluster}/${meta.funnelStage})`;
    });

  // Format gaps for prompt
  const gapList = gaps.recommendations
    .slice(0, 10)
    .map(r => `- [${r.priority}] ${r.title}: ${r.description}`)
    .join('\n');

  const prompt = `You are a content strategist for a real estate investment education website (LendCity).

TRANSCRIPT TO ANALYZE:
${transcript.slice(0, 8000)}

${guestInfo ? `GUEST INFO: ${JSON.stringify(guestInfo)}` : ''}

CURRENT CONTENT GAPS (prioritized):
${gapList}

EXISTING ARTICLES (avoid duplication):
${recentTitles.join('\n')}

TASK: Analyze this transcript and identify ${maxSuggestions} article opportunities.

For each article, provide:
1. **Title** - SEO-optimized title
2. **Topic Cluster** - Which cluster it belongs to
3. **Funnel Stage** - awareness, consideration, or decision
4. **Target Persona** - investor, homebuyer, or general
5. **Gaps Filled** - Which content gaps this addresses
6. **Key Sections** - 4-6 main sections/H2s
7. **Internal Links** - 3-5 existing articles it should link to
8. **Keywords** - Primary and secondary keywords

Return JSON:
{
  "articles": [
    {
      "title": "...",
      "topicCluster": "...",
      "funnelStage": "...",
      "targetPersona": "...",
      "gapsFilled": ["gap1", "gap2"],
      "sections": ["Section 1", "Section 2"],
      "internalLinks": ["Article Title 1", "Article Title 2"],
      "keywords": {
        "primary": "...",
        "secondary": ["...", "..."]
      },
      "estimatedWordCount": 1500,
      "reasoning": "Why this article fills important gaps"
    }
  ],
  "additionalOpportunities": ["Spin-off idea 1", "Spin-off idea 2"]
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 2000,
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
      error: 'Failed to parse response',
      rawResponse: response.content[0].text
    };
  }
}

/**
 * Generate strategic article from transcript
 */
async function generateStrategicArticle(transcript, articleSpec, options = {}) {
  const client = getClient();

  const {
    title,
    topicCluster,
    funnelStage,
    targetPersona,
    sections,
    internalLinks,
    keywords
  } = articleSpec;

  // Find articles to link to
  const embedding = await generateEmbedding(transcript.slice(0, 4000));
  const similar = await querySimilar(embedding, { topK: 10 });

  const linkCandidates = similar.map(s => {
    const meta = s.metadata || s;
    return `- "${meta.title}" (${meta.url})`;
  }).join('\n');

  const prompt = `You are a real estate investment content expert writing for LendCity.

CONTEXT:
- Target Cluster: ${topicCluster}
- Funnel Stage: ${funnelStage}
- Target Persona: ${targetPersona}
- Primary Keyword: ${keywords?.primary || 'real estate investing'}

TRANSCRIPT SOURCE:
${transcript.slice(0, 6000)}

ARTICLE SECTIONS TO COVER:
${sections.map((s, i) => `${i + 1}. ${s}`).join('\n')}

ARTICLES TO LINK TO (include at least 3):
${linkCandidates}

TONE & STYLE:
${funnelStage === 'awareness' ? '- Educational, introductory, avoid jargon, explain concepts' : ''}
${funnelStage === 'consideration' ? '- Detailed, comparative, practical examples, pros/cons' : ''}
${funnelStage === 'decision' ? '- Action-oriented, specific steps, address objections, clear CTAs' : ''}
${targetPersona === 'investor' ? '- Focus on ROI, cash flow, portfolio growth, numbers' : ''}
${targetPersona === 'homebuyer' ? '- Focus on affordability, process, first steps, reassurance' : ''}
- Canadian market focus (mention provinces, Canadian regulations when relevant)

REQUIREMENTS:
1. Write comprehensive content based on the transcript
2. Include internal links naturally (use markdown: [anchor text](url))
3. Use the primary keyword in the first paragraph and H2s
4. Include practical examples from the transcript
5. Add a "Key Takeaways" section at the end
6. End with a relevant call-to-action

Write the article title as H1 followed by the full article content in markdown.`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 4000,
    messages: [{ role: 'user', content: prompt }]
  });

  const articleContent = response.content[0].text;

  return {
    title,
    content: articleContent,
    metadata: {
      topicCluster,
      funnelStage,
      targetPersona,
      keywords,
      sourceType: 'transcript',
      generatedAt: new Date().toISOString()
    }
  };
}

/**
 * Get proactive content suggestions
 */
async function getProactiveContentPlan(options = {}) {
  const { weeksAhead = 4 } = options;

  const gaps = await analyzeContentGaps();
  const suggestions = await getContentSuggestions({ maxSuggestions: 20 });

  // Prioritize and organize into a content calendar
  const plan = {
    immediate: [],    // This week
    shortTerm: [],    // 2-3 weeks
    strategic: []     // 4+ weeks
  };

  // High priority items go first
  const highPriority = suggestions.filter(s => s.priority === 'high');
  const mediumPriority = suggestions.filter(s => s.priority === 'medium');

  plan.immediate = highPriority.slice(0, 2).map(s => ({
    ...s,
    suggestedDeadline: 'This week',
    reason: 'High-priority content gap'
  }));

  plan.shortTerm = [
    ...highPriority.slice(2, 4),
    ...mediumPriority.slice(0, 2)
  ].map(s => ({
    ...s,
    suggestedDeadline: '2-3 weeks',
    reason: 'Medium-priority gap or cluster expansion'
  }));

  plan.strategic = mediumPriority.slice(2, 6).map(s => ({
    ...s,
    suggestedDeadline: '4+ weeks',
    reason: 'Strategic topic expansion'
  }));

  return {
    plan,
    summary: {
      totalSuggestions: suggestions.length,
      immediateItems: plan.immediate.length,
      gapsCovered: gaps.summary
    },
    topRecommendations: gaps.recommendations.slice(0, 3)
  };
}

/**
 * Dashboard data for content planning
 */
async function getContentDashboard() {
  const gaps = await analyzeContentGaps();
  const suggestions = await getContentSuggestions({ maxSuggestions: 10 });
  const articles = await getAllArticles();

  // Count by cluster
  const clusterCounts = {};
  for (const article of articles) {
    const cluster = article.metadata?.topicCluster || article.topicCluster || 'uncategorized';
    clusterCounts[cluster] = (clusterCounts[cluster] || 0) + 1;
  }

  // Count by funnel stage
  const funnelCounts = { awareness: 0, consideration: 0, decision: 0 };
  for (const article of articles) {
    const stage = article.metadata?.funnelStage || article.funnelStage;
    if (stage && funnelCounts[stage] !== undefined) {
      funnelCounts[stage]++;
    }
  }

  return {
    overview: {
      totalArticles: articles.length,
      totalClusters: Object.keys(clusterCounts).length,
      orphanedArticles: gaps.linkingOrphans.length,
      contentGaps: gaps.funnelGaps.length
    },
    distribution: {
      byCluster: clusterCounts,
      byFunnel: funnelCounts
    },
    actionItems: gaps.recommendations.slice(0, 5),
    contentSuggestions: suggestions.slice(0, 5),
    health: {
      funnelCompleteness: calculateFunnelCompleteness(gaps.funnelGaps, Object.keys(clusterCounts).length),
      linkingHealth: Math.round(((articles.length - gaps.linkingOrphans.length) / articles.length) * 100)
    }
  };
}

/**
 * Calculate funnel completeness percentage
 */
function calculateFunnelCompleteness(funnelGaps, totalClusters) {
  if (totalClusters === 0) return 0;
  const clustersWithGaps = funnelGaps.length;
  return Math.round(((totalClusters - clustersWithGaps) / totalClusters) * 100);
}

module.exports = {
  analyzeTranscript,
  generateStrategicArticle,
  getProactiveContentPlan,
  getContentDashboard
};
