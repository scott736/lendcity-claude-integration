/**
 * Semantic Enrichment Module v1.0
 *
 * Advanced semantic analysis for smarter internal linking:
 * 1. LSI Keywords extraction - semantically related terms
 * 2. Section-level embeddings - H2-based content chunking
 * 3. Linkable moments detection - natural link insertion points
 * 4. Multi-vector representations - title, summary, content, entity embeddings
 * 5. Content structure analysis - tables, FAQs, videos, etc.
 * 6. Pre-computed anchor phrase candidates
 * 7. Enhanced topical authority scoring
 * 8. Complete E-E-A-T analysis
 */

const { generateEmbedding, generateEmbeddings, cleanForEmbedding } = require('./embeddings');
const { getClient } = require('./claude');

// ============================================================================
// LSI KEYWORDS EXTRACTION
// ============================================================================

/**
 * Real estate domain LSI keyword mappings
 * Maps core concepts to semantically related terms that Google associates together
 */
const LSI_KEYWORD_MAP = {
  // Investment strategies
  'brrrr': ['buy rehab rent refinance repeat', 'house hacking', 'forced appreciation', 'value-add investing', 'renovation strategy', 'cash-out refinance', 'arv', 'after repair value', 'sweat equity'],
  'flip': ['fix and flip', 'house flipping', 'wholesale', 'assignment contract', 'arv calculation', 'renovation budget', 'holding costs', 'profit margin', 'exit strategy'],
  'rental': ['passive income', 'cash flow', 'tenant screening', 'property management', 'vacancy rate', 'cap rate', 'noi', 'gross rent multiplier', 'operating expenses'],
  'wholesale': ['assignment fee', 'motivated sellers', 'cash buyers', 'double close', 'bird dog', 'earnest money', 'purchase agreement', 'title company'],

  // Financing
  'mortgage': ['amortization', 'principal', 'interest rate', 'down payment', 'closing costs', 'pre-approval', 'debt-to-income', 'credit score', 'loan-to-value'],
  'private-lending': ['hard money', 'bridge loan', 'alternative financing', 'loan origination', 'interest-only', 'balloon payment', 'collateral', 'promissory note'],
  'refinance': ['cash-out', 'rate-and-term', 'equity', 'appraisal', 'seasoning period', 'ltv ratio', 'closing costs', 'break-even point'],

  // Property types
  'multi-family': ['duplex', 'triplex', 'fourplex', 'apartment building', 'unit mix', 'common areas', 'laundry income', 'house hacking'],
  'single-family': ['detached home', 'sfh', 'residential property', 'starter home', 'turnkey rental', 'appreciation potential'],
  'commercial': ['retail', 'office space', 'industrial', 'mixed-use', 'nnn lease', 'cam charges', 'tenant improvements'],

  // Metrics & analysis
  'cash-flow': ['monthly income', 'operating expenses', 'net operating income', 'debt service', 'dscr', 'positive cash flow', 'break-even'],
  'cap-rate': ['capitalization rate', 'noi', 'property value', 'market cap rate', 'return on investment', 'yield'],
  'roi': ['return on investment', 'cash-on-cash', 'irr', 'annual return', 'equity multiple', 'profit margin'],

  // Canadian specific
  'canada': ['canadian real estate', 'cmhc', 'ontario', 'toronto', 'vancouver', 'alberta', 'foreign buyer', 'stress test', 'land transfer tax'],
  'ontario': ['toronto', 'gta', 'hamilton', 'ottawa', 'land transfer tax', 'ontario landlord tenant act', 'ltb'],

  // Process & strategy
  'due-diligence': ['property inspection', 'title search', 'environmental assessment', 'zoning verification', 'comparable analysis', 'rent survey'],
  'negotiation': ['purchase price', 'seller concessions', 'earnest money', 'contingencies', 'closing date', 'counter offer'],
  'property-management': ['tenant relations', 'maintenance', 'rent collection', 'eviction process', 'lease agreement', 'security deposit']
};

/**
 * Extract LSI keywords for content based on detected topics
 * @param {string} content - Article content
 * @param {Array} mainTopics - Main topics extracted from content
 * @param {string} topicCluster - Topic cluster name
 * @returns {Array} LSI keywords related to the content
 */
function extractLSIKeywords(content, mainTopics = [], topicCluster = '') {
  const contentLower = content.toLowerCase();
  const lsiKeywords = new Set();

  // Check main topics against LSI map
  for (const topic of mainTopics) {
    const topicKey = topic.toLowerCase().replace(/\s+/g, '-');
    if (LSI_KEYWORD_MAP[topicKey]) {
      LSI_KEYWORD_MAP[topicKey].forEach(kw => lsiKeywords.add(kw));
    }
  }

  // Check topic cluster
  if (topicCluster && LSI_KEYWORD_MAP[topicCluster]) {
    LSI_KEYWORD_MAP[topicCluster].forEach(kw => lsiKeywords.add(kw));
  }

  // Scan content for LSI triggers
  for (const [key, relatedTerms] of Object.entries(LSI_KEYWORD_MAP)) {
    const keyPattern = key.replace(/-/g, '[\\s-]?');
    if (new RegExp(keyPattern, 'i').test(contentLower)) {
      relatedTerms.forEach(kw => lsiKeywords.add(kw));
    }
  }

  return Array.from(lsiKeywords).slice(0, 25); // Limit to 25 LSI keywords
}

/**
 * Use Claude to extract advanced LSI keywords specific to the content
 */
async function extractLSIKeywordsWithAI(content, title) {
  const client = getClient();

  const prompt = `You are an SEO expert analyzing real estate investment content for semantic relationships.

ARTICLE TITLE: ${title}

CONTENT PREVIEW:
${content.slice(0, 4000)}

TASK: Extract LSI (Latent Semantic Indexing) keywords - terms that Google semantically associates with this content's main topics.

Focus on:
1. Related concepts Google expects to see alongside the main topics
2. Synonyms and variations of key terms
3. Co-occurring terminology in the real estate investment niche
4. Question-based phrases users might search
5. Long-tail variations

Return JSON:
{
  "lsiKeywords": ["keyword1", "keyword2", ...],
  "semanticNeighbors": {
    "primaryTopic": ["related term 1", "related term 2"],
    "secondaryTopic": ["related term 1", "related term 2"]
  },
  "questionKeywords": ["how to...", "what is...", "why should..."]
}

Return 15-25 LSI keywords total. Return ONLY valid JSON.`;

  try {
    const response = await client.messages.create({
      model: 'claude-sonnet-4-20250514',
      max_tokens: 600,
      messages: [{ role: 'user', content: prompt }]
    });

    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    console.error('LSI extraction failed:', e.message);
    return {
      lsiKeywords: extractLSIKeywords(content, [], ''),
      semanticNeighbors: {},
      questionKeywords: []
    };
  }
}

// ============================================================================
// SECTION-LEVEL EMBEDDINGS
// ============================================================================

/**
 * Extract sections from HTML content based on H2 headers
 * @param {string} html - HTML content
 * @returns {Array} Sections with headers and content
 */
function extractSections(html) {
  const sections = [];

  // Match H2 headers and their content
  const h2Pattern = /<h2[^>]*>(.*?)<\/h2>([\s\S]*?)(?=<h2|$)/gi;
  let match;
  let sectionIndex = 0;

  // Get intro content before first H2
  const introMatch = html.match(/^([\s\S]*?)(?=<h2)/i);
  if (introMatch && introMatch[1].trim().length > 100) {
    const introText = cleanForEmbedding(introMatch[1]);
    if (introText.length > 50) {
      sections.push({
        index: sectionIndex++,
        header: 'Introduction',
        content: introText,
        type: 'intro'
      });
    }
  }

  // Extract H2 sections
  while ((match = h2Pattern.exec(html)) !== null) {
    const header = match[1].replace(/<[^>]*>/g, '').trim();
    const content = cleanForEmbedding(match[2]);

    if (content.length > 50) {
      sections.push({
        index: sectionIndex++,
        header,
        content,
        type: 'section'
      });
    }
  }

  // If no H2s found, try H3s
  if (sections.length <= 1) {
    const h3Pattern = /<h3[^>]*>(.*?)<\/h3>([\s\S]*?)(?=<h3|<h2|$)/gi;
    while ((match = h3Pattern.exec(html)) !== null) {
      const header = match[1].replace(/<[^>]*>/g, '').trim();
      const content = cleanForEmbedding(match[2]);

      if (content.length > 50) {
        sections.push({
          index: sectionIndex++,
          header,
          content,
          type: 'subsection'
        });
      }
    }
  }

  return sections;
}

/**
 * Generate embeddings for each section
 * @param {Array} sections - Sections extracted from content
 * @returns {Array} Sections with embeddings
 */
async function generateSectionEmbeddings(sections) {
  if (!sections || sections.length === 0) return [];

  // Prepare texts for batch embedding
  const texts = sections.map(s => `${s.header}\n\n${s.content.slice(0, 2000)}`);

  try {
    const embeddings = await generateEmbeddings(texts);

    return sections.map((section, i) => ({
      ...section,
      embedding: embeddings[i],
      embeddingPreview: embeddings[i].slice(0, 5) // First 5 dims for debugging
    }));
  } catch (error) {
    console.error('Section embedding generation failed:', error.message);
    return sections;
  }
}

// ============================================================================
// LINKABLE MOMENTS DETECTION
// ============================================================================

/**
 * Patterns that indicate natural link insertion opportunities
 */
const LINKABLE_PATTERNS = [
  // Explicit references
  { pattern: /as (?:we|I) (?:discussed|covered|explained|mentioned) (?:in|earlier)/gi, type: 'back_reference' },
  { pattern: /(?:learn|read|discover|find out) more about/gi, type: 'forward_reference' },
  { pattern: /for (?:more|detailed|complete) (?:information|details|guidance)/gi, type: 'more_info' },
  { pattern: /(?:check out|see|visit|refer to) (?:our|the|this)/gi, type: 'explicit_reference' },

  // Definitional opportunities
  { pattern: /(?:what is|what are|what's) (?:a |an |the )?[\w\s-]+\?/gi, type: 'definition' },
  { pattern: /(?:known as|called|referred to as|also known as)/gi, type: 'terminology' },

  // Process/how-to indicators
  { pattern: /(?:the |a )?(?:process|steps|method|strategy|approach) (?:of|for|to)/gi, type: 'process' },
  { pattern: /how to [\w\s]+/gi, type: 'how_to' },

  // Comparison opportunities
  { pattern: /(?:compared to|versus|vs\.?|unlike|similar to|different from)/gi, type: 'comparison' },
  { pattern: /(?:pros and cons|advantages and disadvantages|benefits and drawbacks)/gi, type: 'comparison_list' },

  // Topic mentions
  { pattern: /(?:brrrr|house hack|flip|wholesale|private lend|cash flow|cap rate)/gi, type: 'topic_mention' },

  // Transition phrases
  { pattern: /(?:another (?:option|approach|strategy|method)|alternatively)/gi, type: 'alternative' },
  { pattern: /(?:first|second|third|finally|next|then),? (?:you|we|investors)/gi, type: 'sequential' }
];

/**
 * Detect linkable moments in content
 * @param {string} content - Article content
 * @returns {Array} Linkable moments with position and context
 */
function detectLinkableMoments(content) {
  const moments = [];
  const plainText = content.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();

  for (const { pattern, type } of LINKABLE_PATTERNS) {
    let match;
    const regex = new RegExp(pattern.source, pattern.flags);

    while ((match = regex.exec(plainText)) !== null) {
      // Get surrounding context (sentence)
      const start = Math.max(0, plainText.lastIndexOf('.', match.index) + 1);
      const end = plainText.indexOf('.', match.index + match[0].length);
      const sentence = plainText.slice(start, end > 0 ? end + 1 : undefined).trim();

      // Calculate position percentage
      const positionPercent = Math.round((match.index / plainText.length) * 100);

      moments.push({
        type,
        matchedText: match[0],
        sentence,
        position: match.index,
        positionPercent,
        priority: getLinkablePriority(type, positionPercent)
      });
    }
  }

  // Sort by priority (highest first) and deduplicate overlapping
  return deduplicateMoments(moments.sort((a, b) => b.priority - a.priority));
}

/**
 * Calculate priority score for linkable moment
 */
function getLinkablePriority(type, positionPercent) {
  // Type-based priority
  const typePriority = {
    'forward_reference': 100,
    'more_info': 95,
    'explicit_reference': 90,
    'definition': 85,
    'topic_mention': 80,
    'how_to': 75,
    'comparison': 70,
    'process': 65,
    'back_reference': 60,
    'terminology': 55,
    'alternative': 50,
    'comparison_list': 45,
    'sequential': 40
  };

  let priority = typePriority[type] || 50;

  // Position bonus (earlier = better for SEO)
  if (positionPercent <= 10) priority += 20;
  else if (positionPercent <= 25) priority += 15;
  else if (positionPercent <= 50) priority += 10;
  else if (positionPercent >= 90) priority -= 10;

  return priority;
}

/**
 * Remove overlapping linkable moments
 */
function deduplicateMoments(moments) {
  const unique = [];
  const usedRanges = [];

  for (const moment of moments) {
    const start = moment.position;
    const end = start + moment.matchedText.length;

    // Check if overlaps with existing
    const overlaps = usedRanges.some(([s, e]) =>
      (start >= s && start <= e) || (end >= s && end <= e)
    );

    if (!overlaps) {
      unique.push(moment);
      usedRanges.push([start, end]);
    }
  }

  return unique.slice(0, 20); // Limit to 20 moments
}

/**
 * Use Claude to identify high-quality linkable passages
 */
async function detectLinkableMomentsWithAI(content, title) {
  const client = getClient();

  const prompt = `You are analyzing real estate content to find natural link insertion points.

ARTICLE: "${title}"

CONTENT:
${content.slice(0, 6000)}

TASK: Identify 5-10 "linkable moments" - phrases or sentences where an internal link would:
1. Feel natural to the reader
2. Provide additional value
3. Not disrupt the reading flow
4. Connect to related concepts

For each moment, identify:
1. The exact phrase that could become anchor text (2-6 words)
2. What topic this phrase could link TO
3. Why this is a good link opportunity

Return JSON:
{
  "linkableMoments": [
    {
      "anchorPhrase": "exact phrase from content",
      "suggestedLinkTopic": "what article to link to",
      "context": "the full sentence containing the phrase",
      "reason": "why this is a good link opportunity",
      "priority": 1-10 (10 = best)
    }
  ]
}

IMPORTANT: anchorPhrase MUST be exact text from the content. Return ONLY valid JSON.`;

  try {
    const response = await client.messages.create({
      model: 'claude-sonnet-4-20250514',
      max_tokens: 1000,
      messages: [{ role: 'user', content: prompt }]
    });

    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    console.error('AI linkable moment detection failed:', e.message);
    return {
      linkableMoments: detectLinkableMoments(content).map(m => ({
        anchorPhrase: m.matchedText,
        suggestedLinkTopic: m.type,
        context: m.sentence,
        reason: `Pattern match: ${m.type}`,
        priority: Math.round(m.priority / 10)
      }))
    };
  }
}

// ============================================================================
// MULTI-VECTOR REPRESENTATIONS
// ============================================================================

/**
 * Generate multiple embeddings for different aspects of an article
 * @param {Object} article - Article with title, summary, content
 * @returns {Object} Multi-vector embeddings
 */
async function generateMultiVectorEmbeddings(article) {
  const { title, summary, content, mainTopics = [], semanticKeywords = [] } = article;

  // Prepare different text representations
  const titleText = title;
  const summaryText = summary || '';
  const contentText = cleanForEmbedding(content || '').slice(0, 8000);
  const conceptsText = [...mainTopics, ...semanticKeywords].join(', ');

  // Generate embeddings in parallel where possible
  const textsToEmbed = [
    titleText,
    summaryText || titleText, // Fallback to title if no summary
    contentText || titleText,
    conceptsText || titleText
  ].filter(t => t && t.length > 0);

  try {
    const embeddings = await generateEmbeddings(textsToEmbed);

    return {
      titleEmbedding: embeddings[0],
      summaryEmbedding: embeddings[1],
      contentEmbedding: embeddings[2],
      conceptEmbedding: embeddings[3] || embeddings[0],
      // Combined weighted embedding (useful for general matching)
      combinedEmbedding: combineEmbeddings([
        { embedding: embeddings[0], weight: 0.3 },  // Title
        { embedding: embeddings[1], weight: 0.2 },  // Summary
        { embedding: embeddings[2], weight: 0.4 },  // Content
        { embedding: embeddings[3] || embeddings[0], weight: 0.1 }  // Concepts
      ])
    };
  } catch (error) {
    console.error('Multi-vector embedding failed:', error.message);
    // Fallback to single embedding
    const singleEmbedding = await generateEmbedding(`${title}\n\n${contentText}`);
    return {
      titleEmbedding: singleEmbedding,
      summaryEmbedding: singleEmbedding,
      contentEmbedding: singleEmbedding,
      conceptEmbedding: singleEmbedding,
      combinedEmbedding: singleEmbedding
    };
  }
}

/**
 * Combine multiple embeddings with weights
 */
function combineEmbeddings(weightedEmbeddings) {
  if (!weightedEmbeddings || weightedEmbeddings.length === 0) return null;

  const dimension = weightedEmbeddings[0].embedding.length;
  const combined = new Array(dimension).fill(0);

  for (const { embedding, weight } of weightedEmbeddings) {
    for (let i = 0; i < dimension; i++) {
      combined[i] += embedding[i] * weight;
    }
  }

  // Normalize
  const norm = Math.sqrt(combined.reduce((sum, v) => sum + v * v, 0));
  return combined.map(v => v / norm);
}

// ============================================================================
// CONTENT STRUCTURE ANALYSIS
// ============================================================================

/**
 * Analyze content structure for rich elements
 * @param {string} html - HTML content
 * @returns {Object} Structure analysis
 */
function analyzeContentStructure(html) {
  const structure = {
    // Tables
    hasTable: /<table[\s>]/i.test(html),
    tableCount: (html.match(/<table[\s>]/gi) || []).length,

    // FAQs (common patterns)
    hasFaq: /(?:faq|frequently asked|common questions)/i.test(html) ||
            /<(?:dl|details)[^>]*>/i.test(html) ||
            (html.match(/<h[23][^>]*>\s*(?:Q:|Question:|\d+\.)/gi) || []).length >= 3,
    faqCount: (html.match(/<h[23][^>]*>.*?\?.*?<\/h[23]>/gi) || []).length,

    // Videos
    hasVideo: /(?:youtube|vimeo|wistia|video|iframe[^>]*(?:youtube|player))/i.test(html),
    videoCount: (html.match(/(?:<iframe[^>]*(?:youtube|vimeo)|<video)/gi) || []).length,

    // Images
    hasImages: /<img[\s>]/i.test(html),
    imageCount: (html.match(/<img[\s>]/gi) || []).length,

    // Lists
    hasLists: /<(?:ul|ol)[\s>]/i.test(html),
    listCount: (html.match(/<(?:ul|ol)[\s>]/gi) || []).length,

    // Calculators/interactive
    hasCalculator: /(?:calculator|calc|compute|estimate)/i.test(html) &&
                   /<(?:form|input|button)/i.test(html),

    // Code blocks
    hasCode: /<(?:pre|code)[\s>]/i.test(html),

    // Blockquotes/testimonials
    hasQuotes: /<blockquote[\s>]/i.test(html),
    quoteCount: (html.match(/<blockquote[\s>]/gi) || []).length,

    // Headers structure
    h2Count: (html.match(/<h2[\s>]/gi) || []).length,
    h3Count: (html.match(/<h3[\s>]/gi) || []).length,

    // Callouts/highlights
    hasCallouts: /(?:callout|highlight|notice|alert|tip|warning)/i.test(html),

    // Downloads/resources
    hasDownloads: /(?:download|pdf|spreadsheet|template|checklist)/i.test(html) &&
                  /href=["'][^"']*\.(?:pdf|xlsx?|docx?)/i.test(html),

    // Schema markup
    hasSchema: /itemtype=["'].*schema\.org/i.test(html) ||
               /<script[^>]*type=["']application\/ld\+json/i.test(html)
  };

  // Calculate structure score (0-100)
  structure.structureScore = calculateStructureScore(structure);

  // Determine content format
  structure.contentFormat = determineContentFormat(structure);

  return structure;
}

/**
 * Calculate overall structure score
 */
function calculateStructureScore(structure) {
  let score = 50; // Base score

  // Positive signals
  if (structure.hasTable) score += 10;
  if (structure.hasFaq) score += 15;
  if (structure.hasVideo) score += 10;
  if (structure.hasImages && structure.imageCount >= 3) score += 5;
  if (structure.hasLists && structure.listCount >= 2) score += 5;
  if (structure.hasCalculator) score += 15;
  if (structure.hasQuotes) score += 5;
  if (structure.h2Count >= 3) score += 5;
  if (structure.h3Count >= 5) score += 3;
  if (structure.hasCallouts) score += 3;
  if (structure.hasDownloads) score += 7;
  if (structure.hasSchema) score += 7;

  return Math.min(100, score);
}

/**
 * Determine primary content format
 */
function determineContentFormat(structure) {
  if (structure.hasFaq && structure.faqCount >= 5) return 'faq';
  if (structure.hasVideo && structure.videoCount >= 1) return 'video-guide';
  if (structure.hasTable && structure.tableCount >= 2) return 'comparison';
  if (structure.hasCalculator) return 'tool';
  if (structure.listCount >= 5) return 'listicle';
  if (structure.h2Count >= 5) return 'comprehensive-guide';
  if (structure.hasDownloads) return 'resource';
  return 'standard-article';
}

// ============================================================================
// CONTENT COMPREHENSIVENESS SCORING
// ============================================================================

/**
 * Calculate comprehensive content quality score
 * Goes beyond word count to evaluate depth and coverage
 */
function calculateComprehensiveness(content, structure, mainTopics = []) {
  const plainText = cleanForEmbedding(content);
  const wordCount = plainText.split(/\s+/).length;

  const scores = {
    // Length depth (0-25)
    lengthScore: wordCount >= 3000 ? 25 :
                 wordCount >= 2000 ? 22 :
                 wordCount >= 1500 ? 18 :
                 wordCount >= 1000 ? 14 :
                 wordCount >= 500 ? 10 : 5,

    // Structure depth (0-25)
    structureScore: Math.min(25, Math.round(structure.structureScore / 4)),

    // Topic coverage (0-25)
    topicCoverage: calculateTopicCoverage(plainText, mainTopics),

    // Actionability (0-15)
    actionability: calculateActionability(plainText),

    // Evidence/examples (0-10)
    evidenceScore: calculateEvidenceScore(plainText)
  };

  const totalScore = Object.values(scores).reduce((a, b) => a + b, 0);

  return {
    totalScore: Math.min(100, totalScore),
    wordCount,
    breakdown: scores,
    level: totalScore >= 80 ? 'comprehensive' :
           totalScore >= 60 ? 'thorough' :
           totalScore >= 40 ? 'adequate' : 'basic'
  };
}

/**
 * Calculate topic coverage score
 */
function calculateTopicCoverage(text, mainTopics) {
  if (!mainTopics || mainTopics.length === 0) return 15;

  const textLower = text.toLowerCase();
  let covered = 0;

  for (const topic of mainTopics) {
    const topicLower = topic.toLowerCase();
    // Count mentions (topic should appear multiple times for good coverage)
    const mentions = (textLower.match(new RegExp(topicLower.replace(/[-\s]/g, '[-\\s]?'), 'g')) || []).length;
    if (mentions >= 3) covered += 2;
    else if (mentions >= 1) covered += 1;
  }

  return Math.min(25, Math.round((covered / (mainTopics.length * 2)) * 25));
}

/**
 * Calculate actionability score (how actionable is the content)
 */
function calculateActionability(text) {
  const textLower = text.toLowerCase();
  let score = 0;

  // Action indicators
  const actionPatterns = [
    /step \d|first,|second,|third,|finally,/gi,
    /how to|guide to|tutorial/gi,
    /you (?:can|should|need to|must|will)/gi,
    /download|template|checklist|worksheet/gi,
    /example:|for instance:|such as:/gi,
    /pro tip|tip:|note:|important:/gi
  ];

  for (const pattern of actionPatterns) {
    const matches = (text.match(pattern) || []).length;
    score += Math.min(3, matches);
  }

  return Math.min(15, score);
}

/**
 * Calculate evidence/examples score
 */
function calculateEvidenceScore(text) {
  const textLower = text.toLowerCase();
  let score = 0;

  // Evidence indicators
  if (/according to|research shows|study found|data from/i.test(text)) score += 3;
  if (/example|case study|scenario|real-world/i.test(text)) score += 3;
  if (/\$[\d,]+|\d+%|roi of \d/i.test(text)) score += 2; // Numbers/stats
  if (/testimonial|review|feedback/i.test(text)) score += 2;

  return Math.min(10, score);
}

// ============================================================================
// PRE-COMPUTED ANCHOR PHRASE CANDIDATES
// ============================================================================

/**
 * Extract potential anchor phrases from content
 * These are phrases that would make good anchor text for links TO this article
 */
async function extractAnchorPhrases(content, title, mainTopics = []) {
  const client = getClient();

  const prompt = `You are an SEO expert identifying anchor text candidates.

ARTICLE TITLE: ${title}
MAIN TOPICS: ${mainTopics.join(', ')}

CONTENT PREVIEW:
${content.slice(0, 3000)}

TASK: Identify 10-15 phrases that would make excellent anchor text for links pointing TO this article from other articles.

Good anchor phrases:
1. Are 2-6 words long
2. Describe what the article is about
3. Include relevant keywords naturally
4. Would make sense in various contexts
5. Vary in style (exact topic, partial match, natural phrases)

Return JSON:
{
  "anchorPhrases": [
    {
      "phrase": "the phrase",
      "type": "exact_match|partial_match|branded|natural",
      "keywordDensity": "high|medium|low"
    }
  ],
  "primaryAnchor": "the single best anchor phrase for this article"
}

Return ONLY valid JSON.`;

  try {
    const response = await client.messages.create({
      model: 'claude-sonnet-4-20250514',
      max_tokens: 600,
      messages: [{ role: 'user', content: prompt }]
    });

    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      return JSON.parse(jsonMatch[0]);
    }
    return JSON.parse(text);
  } catch (e) {
    console.error('Anchor phrase extraction failed:', e.message);
    // Fallback to simple extraction
    return {
      anchorPhrases: [
        { phrase: title, type: 'exact_match', keywordDensity: 'high' },
        ...mainTopics.slice(0, 5).map(t => ({
          phrase: t,
          type: 'partial_match',
          keywordDensity: 'medium'
        }))
      ],
      primaryAnchor: title
    };
  }
}

// ============================================================================
// ENHANCED TOPICAL AUTHORITY
// ============================================================================

/**
 * Calculate topical authority score for an article within its cluster
 */
function calculateTopicalAuthority(article, clusterArticles = [], linkGraph = {}) {
  const scores = {
    // Content depth in topic (0-30)
    contentDepth: 0,
    // Internal link hub score (0-25)
    linkHubScore: 0,
    // Topic coverage breadth (0-20)
    coverageBreadth: 0,
    // Pillar status bonus (0-15)
    pillarBonus: 0,
    // Recency in topic (0-10)
    recencyScore: 0
  };

  // Content depth
  const wordCount = (article.content || '').split(/\s+/).length;
  scores.contentDepth = wordCount >= 3000 ? 30 :
                        wordCount >= 2000 ? 25 :
                        wordCount >= 1500 ? 20 :
                        wordCount >= 1000 ? 15 : 10;

  // Link hub score (links to/from within cluster)
  const articleId = article.postId;
  const outboundInCluster = (linkGraph[articleId] || [])
    .filter(tid => clusterArticles.some(a => a.postId === tid)).length;
  const inboundInCluster = Object.entries(linkGraph)
    .filter(([sid, targets]) =>
      clusterArticles.some(a => a.postId === parseInt(sid)) &&
      targets.includes(articleId)
    ).length;

  scores.linkHubScore = Math.min(25, (outboundInCluster + inboundInCluster * 2) * 3);

  // Coverage breadth (how many subtopics covered)
  const subtopics = article.mainTopics || [];
  scores.coverageBreadth = Math.min(20, subtopics.length * 3);

  // Pillar bonus
  if (article.isPillar) scores.pillarBonus = 15;
  else if (article.contentType === 'page') scores.pillarBonus = 10;

  // Recency
  const updatedAt = new Date(article.updatedAt || article.publishedAt);
  const daysSinceUpdate = (Date.now() - updatedAt) / (1000 * 60 * 60 * 24);
  scores.recencyScore = daysSinceUpdate <= 30 ? 10 :
                        daysSinceUpdate <= 90 ? 8 :
                        daysSinceUpdate <= 180 ? 5 : 2;

  const totalScore = Object.values(scores).reduce((a, b) => a + b, 0);

  return {
    totalScore: Math.min(100, totalScore),
    breakdown: scores,
    level: totalScore >= 80 ? 'authority' :
           totalScore >= 60 ? 'expert' :
           totalScore >= 40 ? 'contributor' : 'basic'
  };
}

// ============================================================================
// ENHANCED E-E-A-T ANALYSIS
// ============================================================================

/**
 * Comprehensive E-E-A-T analysis for content
 */
function analyzeEEAT(content, article = {}) {
  const html = content || '';
  const plainText = cleanForEmbedding(html);

  const signals = {
    // Experience signals (0-25)
    experience: {
      hasFirstPerson: /\b(I|we|my|our|I've|we've)\b.*(?:invest|property|rental|bought|sold)/i.test(plainText),
      hasCaseStudy: /case study|real example|my experience|client story/i.test(plainText),
      hasSpecificNumbers: /\$[\d,]+(?:,\d{3})*|\d+%\s*(?:return|roi|cash|yield)/i.test(plainText),
      hasTimeline: /\b(in \d{4}|last year|over \d+ (?:years|months)|since \d{4})\b/i.test(plainText),
      score: 0
    },

    // Expertise signals (0-25)
    expertise: {
      hasTechnicalTerms: countTechnicalTerms(plainText) >= 5,
      hasDepthAnalysis: plainText.split(/\s+/).length >= 1500,
      hasMethodology: /step-by-step|methodology|framework|process|strategy/i.test(plainText),
      hasCalculations: /calculate|formula|equation|roi =|cap rate =|\bNOI\b/i.test(plainText),
      score: 0
    },

    // Authoritativeness signals (0-25)
    authoritativeness: {
      hasCitations: /according to|source:|cited from|reference:/i.test(plainText),
      hasExternalLinks: /<a[^>]*href=["']https?:\/\/(?!.*lendcity)/i.test(html),
      hasCredentials: /certified|licensed|cpa|cfa|realtor|broker|years of experience/i.test(plainText),
      hasAwards: /award|recognition|featured in|as seen in/i.test(plainText),
      score: 0
    },

    // Trustworthiness signals (0-25)
    trustworthiness: {
      hasDisclosure: /disclosure|disclaimer|affiliate|sponsored|not financial advice/i.test(plainText),
      hasContactInfo: /contact us|email|phone|address|schedule a call/i.test(html),
      hasPrivacyMention: /privacy|terms|conditions/i.test(html),
      hasLastUpdated: /updated|last modified|reviewed on/i.test(plainText) || !!article.updatedAt,
      hasAuthor: /by\s+[A-Z][a-z]+|author:|written by/i.test(html),
      score: 0
    }
  };

  // Calculate scores
  signals.experience.score = [
    signals.experience.hasFirstPerson,
    signals.experience.hasCaseStudy,
    signals.experience.hasSpecificNumbers,
    signals.experience.hasTimeline
  ].filter(Boolean).length * 6 + 1;

  signals.expertise.score = [
    signals.expertise.hasTechnicalTerms,
    signals.expertise.hasDepthAnalysis,
    signals.expertise.hasMethodology,
    signals.expertise.hasCalculations
  ].filter(Boolean).length * 6 + 1;

  signals.authoritativeness.score = [
    signals.authoritativeness.hasCitations,
    signals.authoritativeness.hasExternalLinks,
    signals.authoritativeness.hasCredentials,
    signals.authoritativeness.hasAwards
  ].filter(Boolean).length * 6 + 1;

  signals.trustworthiness.score = [
    signals.trustworthiness.hasDisclosure,
    signals.trustworthiness.hasContactInfo,
    signals.trustworthiness.hasPrivacyMention,
    signals.trustworthiness.hasLastUpdated,
    signals.trustworthiness.hasAuthor
  ].filter(Boolean).length * 5;

  const totalScore = signals.experience.score +
                     signals.expertise.score +
                     signals.authoritativeness.score +
                     signals.trustworthiness.score;

  return {
    totalScore: Math.min(100, totalScore),
    breakdown: {
      experience: signals.experience.score,
      expertise: signals.expertise.score,
      authoritativeness: signals.authoritativeness.score,
      trustworthiness: signals.trustworthiness.score
    },
    signals,
    level: totalScore >= 80 ? 'excellent' :
           totalScore >= 60 ? 'good' :
           totalScore >= 40 ? 'adequate' : 'needs_improvement',
    recommendations: generateEEATRecommendations(signals)
  };
}

/**
 * Count technical real estate terms
 */
function countTechnicalTerms(text) {
  const technicalTerms = [
    'cap rate', 'noi', 'gross rent multiplier', 'cash-on-cash', 'dscr',
    'ltv', 'arv', 'amortization', 'equity', 'appreciation', 'depreciation',
    'passive income', 'operating expenses', 'vacancy rate', 'tenant screening',
    'due diligence', 'closing costs', 'escrow', 'title insurance'
  ];

  const textLower = text.toLowerCase();
  return technicalTerms.filter(term => textLower.includes(term)).length;
}

/**
 * Generate E-E-A-T improvement recommendations
 */
function generateEEATRecommendations(signals) {
  const recs = [];

  if (!signals.experience.hasFirstPerson && !signals.experience.hasCaseStudy) {
    recs.push('Add personal experience or case studies to demonstrate real-world application');
  }
  if (!signals.expertise.hasCalculations) {
    recs.push('Include specific calculations or formulas to demonstrate expertise');
  }
  if (!signals.authoritativeness.hasCitations) {
    recs.push('Add citations to authoritative sources to build credibility');
  }
  if (!signals.trustworthiness.hasAuthor) {
    recs.push('Add author byline with credentials');
  }
  if (!signals.trustworthiness.hasLastUpdated) {
    recs.push('Display last updated date to show content freshness');
  }

  return recs;
}

// ============================================================================
// MAIN ENRICHMENT FUNCTION
// ============================================================================

/**
 * Perform complete semantic enrichment on an article
 * @param {Object} article - Article with title, content, etc.
 * @param {Object} options - Enrichment options
 * @returns {Object} Enriched article data
 */
async function enrichArticle(article, options = {}) {
  const {
    generateSectionEmbed = true,
    generateMultiVector = true,
    extractLSI = true,
    detectLinkable = true,
    analyzeStructure = true,
    analyzeEEATSignals = true,
    extractAnchors = true,
    useAI = true
  } = options;

  const enriched = {};
  const startTime = Date.now();

  try {
    // 1. Content structure analysis (fast, no API)
    if (analyzeStructure) {
      enriched.contentStructure = analyzeContentStructure(article.content || '');
      enriched.comprehensiveness = calculateComprehensiveness(
        article.content || '',
        enriched.contentStructure,
        article.mainTopics
      );
    }

    // 2. LSI Keywords
    if (extractLSI) {
      if (useAI) {
        const lsiResult = await extractLSIKeywordsWithAI(article.content || '', article.title);
        enriched.lsiKeywords = lsiResult.lsiKeywords;
        enriched.semanticNeighbors = lsiResult.semanticNeighbors;
        enriched.questionKeywords = lsiResult.questionKeywords;
      } else {
        enriched.lsiKeywords = extractLSIKeywords(
          article.content || '',
          article.mainTopics,
          article.topicCluster
        );
      }
    }

    // 3. Linkable moments detection
    if (detectLinkable) {
      if (useAI) {
        const linkableResult = await detectLinkableMomentsWithAI(article.content || '', article.title);
        enriched.linkableMoments = linkableResult.linkableMoments;
      } else {
        enriched.linkableMoments = detectLinkableMoments(article.content || '').map(m => ({
          anchorPhrase: m.matchedText,
          suggestedLinkTopic: m.type,
          context: m.sentence,
          priority: Math.round(m.priority / 10)
        }));
      }
    }

    // 4. Section-level embeddings
    if (generateSectionEmbed) {
      const sections = extractSections(article.content || '');
      enriched.sections = await generateSectionEmbeddings(sections);
      enriched.sectionCount = sections.length;
      enriched.h2Topics = sections
        .filter(s => s.type === 'section')
        .map(s => s.header);
    }

    // 5. Multi-vector embeddings
    if (generateMultiVector) {
      enriched.multiVectorEmbeddings = await generateMultiVectorEmbeddings(article);
    }

    // 6. E-E-A-T analysis
    if (analyzeEEATSignals) {
      enriched.eeatAnalysis = analyzeEEAT(article.content || '', article);
    }

    // 7. Pre-computed anchor phrases
    if (extractAnchors) {
      const anchorResult = await extractAnchorPhrases(
        article.content || '',
        article.title,
        article.mainTopics
      );
      enriched.anchorPhrases = anchorResult.anchorPhrases;
      enriched.primaryAnchor = anchorResult.primaryAnchor;
    }

    enriched.enrichmentTime = Date.now() - startTime;
    enriched.enrichedAt = new Date().toISOString();

  } catch (error) {
    console.error('Semantic enrichment failed:', error.message);
    enriched.error = error.message;
  }

  return enriched;
}

/**
 * Light enrichment for batch processing (faster, less API calls)
 */
async function enrichArticleLight(article) {
  return enrichArticle(article, {
    generateSectionEmbed: false,
    generateMultiVector: false,
    extractLSI: true,
    detectLinkable: false,
    analyzeStructure: true,
    analyzeEEATSignals: true,
    extractAnchors: false,
    useAI: false
  });
}

// ============================================================================
// EXPORTS
// ============================================================================

module.exports = {
  // LSI Keywords
  extractLSIKeywords,
  extractLSIKeywordsWithAI,
  LSI_KEYWORD_MAP,

  // Section embeddings
  extractSections,
  generateSectionEmbeddings,

  // Linkable moments
  detectLinkableMoments,
  detectLinkableMomentsWithAI,
  LINKABLE_PATTERNS,

  // Multi-vector
  generateMultiVectorEmbeddings,
  combineEmbeddings,

  // Content structure
  analyzeContentStructure,
  calculateStructureScore,
  determineContentFormat,

  // Comprehensiveness
  calculateComprehensiveness,

  // Anchor phrases
  extractAnchorPhrases,

  // Topical authority
  calculateTopicalAuthority,

  // E-E-A-T
  analyzeEEAT,
  generateEEATRecommendations,

  // Main functions
  enrichArticle,
  enrichArticleLight
};
