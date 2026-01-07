# Current WordPress Smart Linker - Complete System Deep Dive

> **Purpose:** This document captures every detail of the current WordPress smart linker plugin so that the system can be accurately rebuilt in Next.js + Sanity.io without losing any functionality or business logic.
>
> **Use this document as the authoritative reference when building the new system.**

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Database Schema](#database-schema)
3. [Content Cataloging (Indexing)](#content-cataloging-indexing)
4. [Relevance Scoring Algorithm](#relevance-scoring-algorithm)
5. [Link Generation Process](#link-generation-process)
6. [SEO Metadata Generation](#seo-metadata-generation)
7. [Configuration & Settings](#configuration--settings)
8. [Business Rules & Domain Knowledge](#business-rules--domain-knowledge)
9. [Caching & Performance](#caching--performance)
10. [Background Processing](#background-processing)
11. [API Integration](#api-integration)
12. [Key Functions Reference](#key-functions-reference)

---

## System Overview

### What It Does

The LendCity Smart Linker is a WordPress plugin that:

1. **Catalogs content** - Analyzes every post/page using Claude AI to extract rich metadata
2. **Generates smart links** - Finds semantically relevant internal linking opportunities
3. **Inserts links** - Automatically adds links with appropriate anchor text
4. **Manages SEO metadata** - Auto-generates meta titles, descriptions, and focus keyphrases

### Architecture Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         CONTENT PUBLISHED                               │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      1. CATALOG BUILDING                                │
│                                                                         │
│  Post content → Claude API → Extract:                                   │
│  - Summary (5-6 sentences)                                              │
│  - Main topics (8-10 items)                                             │
│  - Semantic keywords (12-15 items)                                      │
│  - Entities (people, places, regulations)                               │
│  - Good anchor phrases (10-12 phrases)                                  │
│  - Funnel stage, difficulty, persona, cluster                           │
│  - Quality score, content format, lifespan                              │
│                                                                         │
│  → Store in wp_lendcity_catalog table                                   │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      2. LINK GENERATION                                 │
│                                                                         │
│  For source post:                                                       │
│  1. Load source entry from catalog                                      │
│  2. Load all candidate targets from catalog                             │
│  3. Calculate relevance score for each (300+ point system)              │
│  4. Sort by score, apply link limits                                    │
│  5. Build prompt with candidates for Claude                             │
│  6. Claude selects best targets + anchor text                           │
│  7. Insert links into post content                                      │
└─────────────────────────────────┬───────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                      3. SEO METADATA                                    │
│                                                                         │
│  After links are created:                                               │
│  - Generate SEO title (50-60 chars)                                     │
│  - Generate meta description (150-160 chars)                            │
│  - Extract focus keyphrase                                              │
│  - Store in SEOPress meta fields                                        │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Main Catalog Table: `wp_lendcity_catalog`

```sql
CREATE TABLE wp_lendcity_catalog (
    -- Primary identifiers
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id BIGINT UNSIGNED NOT NULL,

    -- Content classification
    post_type VARCHAR(20) NOT NULL DEFAULT 'post',
    is_page TINYINT(1) NOT NULL DEFAULT 0,

    -- Core content
    title VARCHAR(255) NOT NULL DEFAULT '',
    url VARCHAR(500) NOT NULL DEFAULT '',
    summary TEXT,                           -- 5-6 sentence summary from Claude

    -- Extracted metadata (stored as JSON strings)
    main_topics LONGTEXT,                   -- ["topic1", "topic2", ...]
    semantic_keywords LONGTEXT,             -- ["keyword1", "keyword2", ...]
    entities LONGTEXT,                      -- ["entity1", "entity2", ...]
    content_themes LONGTEXT,                -- ["theme1", "theme2", ...]
    good_anchor_phrases LONGTEXT,           -- ["phrase1", "phrase2", ...]

    -- Content intelligence
    reader_intent VARCHAR(20) DEFAULT 'educational',    -- educational|transactional|navigational
    difficulty_level VARCHAR(20) DEFAULT 'intermediate', -- beginner|intermediate|advanced
    funnel_stage VARCHAR(20) DEFAULT 'awareness',       -- awareness|consideration|decision
    topic_cluster VARCHAR(100) DEFAULT NULL,            -- main-topic-slug
    related_clusters LONGTEXT,                          -- ["cluster1", "cluster2", ...]

    -- Quality signals
    is_pillar_content TINYINT(1) NOT NULL DEFAULT 0,
    word_count INT UNSIGNED DEFAULT 0,
    content_quality_score TINYINT UNSIGNED DEFAULT 50,  -- 1-100

    -- Temporal metadata
    content_lifespan VARCHAR(20) DEFAULT 'evergreen',   -- evergreen|seasonal|time-sensitive|dated
    publish_season VARCHAR(30) DEFAULT NULL,            -- spring-market|tax-season|year-end|rate-change
    content_last_updated DATE DEFAULT NULL,
    freshness_score TINYINT UNSIGNED DEFAULT 100,       -- 1-100 (decays over time)

    -- Targeting
    target_regions LONGTEXT,                -- ["Ontario", "BC", ...]
    target_cities LONGTEXT,                 -- ["Toronto", "Vancouver", ...]
    target_persona VARCHAR(30) DEFAULT 'general', -- first-time-buyer|investor|realtor|refinancer|self-employed|general

    -- Link metrics
    inbound_link_count INT UNSIGNED DEFAULT 0,
    outbound_link_count INT UNSIGNED DEFAULT 0,
    link_gap_priority TINYINT UNSIGNED DEFAULT 50,      -- 0-100 (higher = needs more links)

    -- Conversion signals
    has_cta TINYINT(1) DEFAULT 0,
    has_calculator TINYINT(1) DEFAULT 0,
    has_lead_form TINYINT(1) DEFAULT 0,
    monetization_value TINYINT UNSIGNED DEFAULT 5,      -- 1-10 (10 = service page)

    -- Content format
    content_format VARCHAR(30) DEFAULT 'other',         -- guide|how-to|list|case-study|news|faq|comparison|calculator|landing-page|other

    -- Link preferences
    must_link_to LONGTEXT,                  -- Post IDs that MUST be linked
    never_link_to LONGTEXT,                 -- Post IDs that should NEVER be linked
    preferred_anchors LONGTEXT,             -- Preferred anchor texts

    -- Timestamps
    updated_at DATETIME NOT NULL,

    -- Indexes
    PRIMARY KEY (id),
    UNIQUE KEY idx_post_id (post_id),
    KEY idx_topic_cluster (topic_cluster),
    KEY idx_persona (target_persona),
    KEY idx_quality_score (content_quality_score),
    KEY idx_freshness_score (freshness_score),
    KEY idx_funnel_stage (funnel_stage),
    KEY idx_post_type (post_type),
    KEY idx_link_priority (link_gap_priority),
    FULLTEXT INDEX ft_summary_title (summary, title)
);
```

### Post Meta Storage

Links are stored in post meta:

```php
// Meta key: _lendcity_smart_links
// Value: Serialized array of links

[
    [
        'link_id' => 'cl_123_456_1704067200',  // cl_source_target_timestamp
        'anchor' => 'BRRRR strategy',
        'url' => 'https://lendcity.ca/brrrr-guide/',
        'target_post_id' => 456,
        'is_page' => true,
        'added_at' => '2024-01-01 12:00:00'
    ],
    // ... more links
]

// Meta key: _lendcity_original_content
// Value: gzcompress'd original post content (for restoration)

// Meta key: _lendcity_link_priority
// Value: 1-5 (manual priority override for pages)

// Meta key: _lendcity_target_keywords
// Value: String of target keywords for this page
```

### Options Storage

```php
// WordPress options used:

'lendcity_claude_api_key'           // Claude API key
'lendcity_smart_linker_auto'        // Auto-link on publish (yes/no)
'lendcity_auto_seo_metadata'        // Auto-generate SEO (yes/no)
'lendcity_debug_mode'               // Debug logging (yes/no)
'lendcity_smart_linker_queue'       // Array of post IDs to process
'lendcity_smart_linker_queue_status' // Queue status object
'lendcity_catalog_db_version'       // Current DB schema version
'lendcity_keyword_frequency'        // TF-IDF frequency map
'lendcity_synonym_map'              // Synonym groupings
'lendcity_anchor_usage_stats'       // Anchor text usage counts
```

---

## Content Cataloging (Indexing)

### Catalog Prompt Template

This exact prompt is sent to Claude to analyze each post:

```
Analyze this [SERVICE PAGE/blog post] for a Canadian mortgage/real estate investing website (LendCity). Extract COMPREHENSIVE metadata for intelligent internal linking.

TITLE: [Post Title]
URL: [Post URL]
PUBLISHED: [Publish Date]

CONTENT:
[Post content, max 12,000 characters]

NOTE: [If page] This is a HIGH-VALUE SERVICE PAGE. Analyze based on title and URL if content is minimal.

Respond with ONLY a JSON object containing ALL fields:
{
  "summary": "5-6 sentence comprehensive summary covering main points, key advice, unique insights, and target audience",
  "main_topics": ["8-10 specific topics covered"],
  "semantic_keywords": ["12-15 related terms, synonyms, long-tail search phrases"],
  "entities": ["names, cities, provinces, products, programs, companies, regulations mentioned"],
  "content_themes": ["broader themes: investment, financing, Canadian real estate, etc"],
  "good_anchor_phrases": ["10-12 natural 2-5 word phrases for linking TO this content"],
  "reader_intent": "educational|transactional|navigational",
  "difficulty_level": "beginner|intermediate|advanced",
  "funnel_stage": "awareness|consideration|decision",
  "topic_cluster": "main-topic-slug",
  "related_clusters": ["other relevant topic clusters"],
  "is_pillar_content": true/false,
  "content_quality_score": 1-100,
  "content_lifespan": "evergreen|seasonal|time-sensitive|dated",
  "publish_season": "spring-market|tax-season|year-end|rate-change|null",
  "target_regions": ["Ontario", "BC", "Alberta", "National", etc],
  "target_cities": ["Toronto", "Vancouver", "Calgary", etc or empty if national],
  "target_persona": "first-time-buyer|investor|realtor|refinancer|self-employed|general",
  "has_cta": true/false (has clear call-to-action?),
  "has_calculator": true/false (has mortgage/investment calculator?),
  "has_lead_form": true/false (has contact form or lead capture?),
  "monetization_value": 1-10 (business value: 10=service page, 1=general info),
  "content_format": "guide|how-to|list|case-study|news|faq|comparison|calculator|landing-page|other"
}

=== GUIDELINES ===
TOPIC CLUSTERS (use consistent slugs):
- brrrr-strategy, rental-investing, mortgage-types, first-time-buyers
- refinancing, investment-properties, market-analysis, tax-strategies
- property-management, credit-repair, self-employed-mortgages, pre-approval
- down-payment, closing-costs, real-estate-agents, home-buying-process

PERSONA HINTS:
- first-time-buyer: FTHB, first home, saving for down payment, pre-approval
- investor: BRRRR, rental, ROI, cash flow, portfolio, multi-family
- realtor: agent tips, client advice, market insights for agents
- refinancer: refinance, equity, HELOC, debt consolidation
- self-employed: business owners, stated income, tax returns, BFS

LIFESPAN:
- evergreen: timeless advice (how to qualify, what is BRRRR)
- seasonal: spring market, tax season, year-end planning
- time-sensitive: rate announcements, policy changes (good for 1-2 years)
- dated: mentions specific years/rates that will become stale
```

### Catalog Entry Structure

After Claude responds, this structure is stored:

```javascript
{
  // Core fields
  post_id: 123,
  type: 'post',                           // 'post' or 'page'
  is_page: false,
  title: 'Complete BRRRR Strategy Guide for Canadian Investors',
  url: 'https://lendcity.ca/brrrr-strategy-guide/',
  summary: '5-6 sentence summary from Claude...',

  // Extracted arrays
  main_topics: ['BRRRR strategy', 'rental investing', 'refinancing', ...],
  semantic_keywords: ['buy rehab rent refinance repeat', 'cash flow investing', ...],
  entities: ['CMHC', 'Ontario', 'Toronto', 'BRRRR', ...],
  content_themes: ['real estate investing', 'financing strategies', ...],
  good_anchor_phrases: ['BRRRR strategy', 'buy rehab rent refinance', 'BRRRR method', ...],

  // Intelligence fields
  reader_intent: 'educational',
  difficulty_level: 'intermediate',
  funnel_stage: 'consideration',
  topic_cluster: 'brrrr-strategy',
  related_clusters: ['refinancing', 'rental-investing', 'investment-properties'],
  is_pillar_content: true,
  word_count: 2500,
  content_quality_score: 85,

  // Temporal
  content_lifespan: 'evergreen',
  publish_season: null,
  freshness_score: 95,

  // Targeting
  target_regions: ['Ontario', 'BC', 'Alberta'],
  target_cities: [],                       // Empty = national
  target_persona: 'investor',

  // Conversion
  has_cta: true,
  has_calculator: false,
  has_lead_form: true,
  monetization_value: 8,

  // Format
  content_format: 'guide',

  // Link metrics (updated dynamically)
  inbound_link_count: 12,
  outbound_link_count: 5,
  link_gap_priority: 30,                   // Low = well-linked

  updated_at: '2024-01-15 10:30:00'
}
```

---

## Relevance Scoring Algorithm

### Overview

The scoring system calculates a relevance score between a SOURCE post and potential TARGET posts. Higher score = better link match.

**Maximum theoretical score: ~300+ points**

### Complete Scoring Breakdown

```javascript
function calculateRelevanceScore(source, target) {
  let score = 0;

  // ═══════════════════════════════════════════════════════════════════
  // TOPIC & CLUSTER MATCHING (0-50 points)
  // ═══════════════════════════════════════════════════════════════════

  // Same topic cluster
  if (source.topic_cluster === target.topic_cluster) {
    score += 30;
  }

  // Related clusters
  if (source.related_clusters?.includes(target.topic_cluster)) {
    score += 20;
  }

  // Topic overlap (max 25 points)
  const topicOverlap = countArrayOverlap(source.main_topics, target.main_topics);
  score += Math.min(topicOverlap * 5, 25);

  // ═══════════════════════════════════════════════════════════════════
  // TF-IDF WEIGHTED KEYWORD MATCHING (0-30 points)
  // ═══════════════════════════════════════════════════════════════════

  // Rare keywords across the catalog are worth more
  const keywordFrequency = getKeywordFrequencyIndex();
  const totalDocs = getCatalogSize();
  let tfidfScore = 0;

  const keywordOverlap = getArrayOverlap(source.semantic_keywords, target.semantic_keywords);
  for (const keyword of keywordOverlap) {
    const docFrequency = keywordFrequency[keyword.toLowerCase()] || 1;
    const idf = Math.log(totalDocs / docFrequency);
    tfidfScore += idf * 2;  // Scale factor
  }
  score += Math.min(tfidfScore, 30);

  // ═══════════════════════════════════════════════════════════════════
  // SYNONYM-AWARE MATCHING (0-20 points)
  // ═══════════════════════════════════════════════════════════════════

  // Domain-specific synonyms (see SYNONYMS section below)
  let synonymScore = 0;
  const matched = [];

  for (const srcKw of source.semantic_keywords) {
    const srcSynonyms = getSynonyms(srcKw);  // Includes the keyword itself
    for (const tgtKw of target.semantic_keywords) {
      if (srcSynonyms.includes(tgtKw.toLowerCase()) && !matched.includes(tgtKw.toLowerCase())) {
        synonymScore += 4;
        matched.push(tgtKw.toLowerCase());
      }
    }
  }
  score += Math.min(synonymScore, 20);

  // ═══════════════════════════════════════════════════════════════════
  // ENTITY-TO-CLUSTER MATCHING (0-45 points)
  // ═══════════════════════════════════════════════════════════════════

  // When source mentions entities that map to target's cluster
  const entityClusterMap = {
    'brrrr': 'brrrr-strategy',
    'heloc': 'refinancing',
    'refinance': 'refinancing',
    'first-time': 'first-time-buyers',
    'fthb': 'first-time-buyers',
    'rental': 'rental-investing',
    'investment property': 'investment-properties',
    'cash flow': 'rental-investing',
    'pre-approval': 'pre-approval',
    'down payment': 'down-payment',
    'closing costs': 'closing-costs',
    'mortgage broker': 'mortgage-types',
    'credit score': 'credit-repair',
    'self-employed': 'self-employed-mortgages'
  };

  for (const entity of source.entities) {
    for (const [keyword, cluster] of Object.entries(entityClusterMap)) {
      if (entity.toLowerCase().includes(keyword) && cluster === target.topic_cluster) {
        score += 25;  // Strong entity-cluster match
        break;
      }
    }
  }

  // Direct entity overlap (max 20 points)
  const entityOverlap = countArrayOverlap(source.entities, target.entities);
  score += Math.min(entityOverlap * 8, 20);

  // ═══════════════════════════════════════════════════════════════════
  // FUNNEL PROGRESSION (0-25 points)
  // ═══════════════════════════════════════════════════════════════════

  const funnelOrder = { 'awareness': 1, 'consideration': 2, 'decision': 3 };
  const sourceFunnel = funnelOrder[source.funnel_stage] || 2;
  const targetFunnel = funnelOrder[target.funnel_stage] || 2;

  if (targetFunnel === sourceFunnel + 1) {
    score += 25;  // Next stage - advancing the reader! (BEST)
  } else if (targetFunnel === sourceFunnel) {
    score += 15;  // Same stage - reinforcing content
  } else if (Math.abs(sourceFunnel - targetFunnel) === 1) {
    score += 10;  // Adjacent stage
  }

  // ═══════════════════════════════════════════════════════════════════
  // DIFFICULTY PROGRESSION (0-15 points)
  // ═══════════════════════════════════════════════════════════════════

  const diffOrder = { 'beginner': 1, 'intermediate': 2, 'advanced': 3 };
  const sourceDiff = diffOrder[source.difficulty_level] || 2;
  const targetDiff = diffOrder[target.difficulty_level] || 2;

  if (targetDiff === sourceDiff + 1) {
    score += 15;  // Next level up - good progression
  } else if (targetDiff === sourceDiff) {
    score += 10;  // Same level
  } else if (targetDiff === sourceDiff - 1) {
    score += 5;   // Simpler content (for clarification links)
  }

  // ═══════════════════════════════════════════════════════════════════
  // PERSONA MATCHING (-30 to +30 points)
  // ═══════════════════════════════════════════════════════════════════

  const personaConflicts = {
    'investor': ['first-time-buyer'],
    'first-time-buyer': ['investor'],
    'realtor': ['first-time-buyer']
  };

  if (personaConflicts[source.target_persona]?.includes(target.target_persona)) {
    score -= 30;  // PENALTY: Don't link investor content to first-time-buyer
  } else if (source.target_persona === target.target_persona && source.target_persona !== 'general') {
    score += 30;  // Same specific persona - very relevant!
  } else if (source.target_persona === 'general' || target.target_persona === 'general') {
    score += 10;  // General content matches with anything
  }

  // ═══════════════════════════════════════════════════════════════════
  // GEOGRAPHIC MATCHING (0-20 points)
  // ═══════════════════════════════════════════════════════════════════

  if (source.target_regions?.length && target.target_regions?.length) {
    if (source.target_regions.includes('National') || target.target_regions.includes('National')) {
      score += 10;  // National content is broadly relevant
    }
    const regionOverlap = countArrayOverlap(source.target_regions, target.target_regions);
    if (regionOverlap > 0) {
      score += Math.min(regionOverlap * 10, 20);
    }
  } else {
    score += 5;  // One is unspecified, assume compatible
  }

  // ═══════════════════════════════════════════════════════════════════
  // CONTENT LIFESPAN MATCHING (-10 to +15 points)
  // ═══════════════════════════════════════════════════════════════════

  if (source.content_lifespan === 'evergreen' && target.content_lifespan === 'evergreen') {
    score += 15;  // Evergreen to evergreen is ideal
  } else if (source.content_lifespan === 'evergreen' && target.content_lifespan === 'dated') {
    score -= 10;  // PENALTY: Don't link evergreen to dated content
  } else if (target.content_lifespan === 'evergreen') {
    score += 10;  // Always good to link TO evergreen content
  }

  // ═══════════════════════════════════════════════════════════════════
  // QUALITY & VALUE SIGNALS (0-30 points)
  // ═══════════════════════════════════════════════════════════════════

  // Pillar content bonus
  if (target.is_pillar_content) {
    score += 20;
  }

  // Quality score (0-10 points)
  score += (target.content_quality_score || 50) / 10;

  // Freshness bonus (0-10 points)
  score += (target.freshness_score || 50) / 10;

  // Monetization value (0-10 points)
  score += target.monetization_value || 5;

  // ═══════════════════════════════════════════════════════════════════
  // CONVERSION SIGNALS (0-15 points)
  // ═══════════════════════════════════════════════════════════════════

  if (target.has_cta) score += 5;
  if (target.has_calculator) score += 5;
  if (target.has_lead_form) score += 5;

  // ═══════════════════════════════════════════════════════════════════
  // LINK GAP PRIORITY (0-15 points)
  // ═══════════════════════════════════════════════════════════════════

  // Prioritize content that needs links (orphaned content)
  const gap = target.link_gap_priority || 50;
  score += (gap / 100) * 15;

  // ═══════════════════════════════════════════════════════════════════
  // DEEP PAGE PRIORITY (0-25 points)
  // ═══════════════════════════════════════════════════════════════════

  const inbound = target.inbound_link_count || 0;
  if (inbound === 0) {
    score += 25;  // Orphaned page - high priority!
  } else if (inbound <= 2) {
    score += 15;  // Very few links
  } else if (inbound <= 5) {
    score += 5;
  }

  // ═══════════════════════════════════════════════════════════════════
  // CONTENT FORMAT FLOW (0-10 points)
  // ═══════════════════════════════════════════════════════════════════

  const formatFlow = {
    'how-to': ['guide', 'calculator', 'comparison'],
    'guide': ['how-to', 'case-study', 'faq'],
    'list': ['guide', 'comparison', 'how-to'],
    'case-study': ['guide', 'how-to', 'landing-page'],
    'faq': ['guide', 'how-to', 'landing-page'],
    'comparison': ['calculator', 'landing-page', 'guide']
  };

  if (formatFlow[source.content_format]?.includes(target.content_format)) {
    score += 10;  // Natural content format progression
  }

  // ═══════════════════════════════════════════════════════════════════
  // ENSURE NON-NEGATIVE
  // ═══════════════════════════════════════════════════════════════════

  return Math.max(0, score);
}
```

### Synonym Map (Domain-Specific)

```javascript
const SYNONYMS = {
  'mortgage': ['home loan', 'loan', 'financing'],
  'real estate': ['property', 'properties', 'real-estate'],
  'investing': ['investment', 'investments', 'invest'],
  'refinance': ['refinancing', 'refi'],
  'rental': ['rent', 'rentals', 'renting'],
  'rate': ['rates', 'interest rate', 'interest rates'],
  'buyer': ['buyers', 'purchaser', 'purchasers'],
  'home': ['house', 'property', 'residence'],
  'down payment': ['downpayment', 'down-payment'],
  'pre-approval': ['preapproval', 'pre approval'],
  'cash flow': ['cashflow', 'cash-flow'],
  'ROI': ['return on investment', 'returns'],
  'BRRRR': ['buy rehab rent refinance repeat', 'brrrr strategy'],
  'HELOC': ['home equity line of credit', 'home equity']
};
```

### Anchor Diversity Penalties

```javascript
function getAnchorDiversityPenalty(anchorText, targetId) {
  const usage = getAnchorUsageStats();
  const key = `${anchorText.toLowerCase()}_${targetId}`;
  const count = usage[key] || 0;

  if (count > 10) return -15;
  if (count > 5) return -10;
  if (count > 3) return -5;
  return 0;
}
```

---

## Link Generation Process

### Link Limits by Word Count

```javascript
const LINK_LIMITS = {
  short: {    // < 800 words
    maxTotal: 4,
    maxPages: 2,
    maxPosts: 2
  },
  medium: {   // 800-1500 words
    maxTotal: 7,
    maxPages: 3,
    maxPosts: 4
  },
  long: {     // > 1500 words
    maxTotal: 10,
    maxPages: 3,
    maxPosts: 7
  }
};

function getLinkLimits(wordCount) {
  if (wordCount < 800) return LINK_LIMITS.short;
  if (wordCount < 1500) return LINK_LIMITS.medium;
  return LINK_LIMITS.long;
}
```

### Link Generation Prompt

This prompt is sent to Claude with candidates:

```
You are an expert SEO strategist creating semantically relevant internal links.

=== FULL SITE CATALOG (X pages, Y posts) ===
Use this to understand site architecture. Items marked [✓] are already linked from this post.
PRIORITIZE: Low Inbound# pages need links! High Priority pages (P4, P5) are important.

SERVICE PAGES:
ID|Title|Inbound#|Pri
---------------------------------------------
123|BRRRR Strategy Guide|12|P5
456|First-Time Buyer Checklist|3|P4[✓]
...

BLOG POSTS:
ID|Title|Inbound#
----------------------------------------
789|How to Calculate Cash Flow|5
...

=== SOURCE POST (adding links TO this post) ===
Title: [Source Title]
Topic Cluster: [cluster]
Funnel Stage: [stage]
Persona: [persona]
Topics: [topic1, topic2, ...]
Content:
[Post content]

=== ALREADY USED ANCHORS (NEVER USE THESE) ===
[anchor1, anchor2, ...]

=== ALL SERVICE PAGES (X pages, select up to N links) ===
★ TARGET KEYWORDS are top priority - use these as anchors when found in content!

ID:123|BRRRR Strategy Guide|P5|In:12 ★BRRRR strategy [BRRRR method, buy rehab rent refinance]
...

=== ALL BLOG POSTS (X posts, select up to N links) ===

ID:789|How to Calculate Cash Flow|In:5 [cash flow calculation, ROI analysis]
...

=== TASK ===
Find: Up to N page links + Up to M post links

=== LINKING RULES (v12.2.1) ===
1. ★ TARGET KEYWORDS ARE TOP PRIORITY: If a page has TARGET KEYWORDS, use those as anchor text when they appear in content!
2. PRIORITIZE ORPHANS: Pages with Inbound# < 5 NEED links - prioritize them
3. RESPECT PRIORITY: P5 pages are most important, P1 least important
4. CLUSTER INTEGRITY: Link within same/related topic clusters for topical authority
5. PERSONA ALIGNMENT: Match reader personas (don't link investor→first-time-buyer)
6. FUNNEL PROGRESSION: Link awareness→consideration→decision naturally
7. DISTRIBUTE EVENLY: Spread links throughout the ENTIRE article
8. MAX 1 LINK PER PARAGRAPH: Never add multiple links to same paragraph
9. ANCHOR DIVERSITY: Vary anchor text across the site
10. QUALITY > QUANTITY: Fewer good links beats many weak links

ANCHOR TEXT RULES:
- MUST be an EXACT phrase from the source content (2-4 words ideal)
- Use 'Anchors' suggestions when they appear naturally in content
- Must read naturally in sentence context

Respond with ONLY a JSON array:
[{"target_id": 123, "anchor_text": "exact phrase from content", "is_page": true/false, "paragraph_hint": "first few words of paragraph"}, ...]
Return [] if no good semantic opportunities exist.
```

### Link Insertion

Links are inserted into post content with data attributes:

```html
<a href="https://lendcity.ca/brrrr-guide/"
   data-claude-link="1"
   data-link-id="cl_123_456_1704067200">
   BRRRR strategy
</a>
```

The original content is stored (gzcompressed) for restoration.

---

## SEO Metadata Generation

### SEO Prompt Template

```
Generate SEO metadata for this article on a Canadian mortgage website.

TITLE: [Post Title]
SUMMARY: [Summary from catalog]
TOPIC: [Topic cluster]
PERSONA: [Target persona]
CONTENT PREVIEW: [First 500 chars]

Generate:
1. SEO Title (50-60 chars, keyword near start, compelling)
2. Meta Description (150-160 chars, includes value prop and CTA)
3. Focus Keyphrase (2-4 words, primary target keyword)

Respond as JSON:
{
  "title": "...",
  "description": "...",
  "focus_keyphrase": "..."
}
```

### SEO Fields (SEOPress Integration)

```php
// Stored in post meta:
'_seopress_titles_title'       // SEO title
'_seopress_titles_desc'        // Meta description
'_seopress_analysis_target_kw' // Focus keyphrase
```

---

## Configuration & Settings

### Page Priority System

Manual priority override for pages (1-5 scale):

```
P5 = Most important service pages (should get most links)
P4 = High value pages
P3 = Standard pages (default)
P2 = Lower priority
P1 = Least important
```

### Target Keywords

Manual target keyword assignment for pages. When these keywords appear in content, they should be used as anchor text linking to that page.

---

## Business Rules & Domain Knowledge

### Topic Clusters (Predefined)

```javascript
const TOPIC_CLUSTERS = [
  'brrrr-strategy',
  'rental-investing',
  'mortgage-types',
  'first-time-buyers',
  'refinancing',
  'investment-properties',
  'market-analysis',
  'tax-strategies',
  'property-management',
  'credit-repair',
  'self-employed-mortgages',
  'pre-approval',
  'down-payment',
  'closing-costs',
  'real-estate-agents',
  'home-buying-process'
];
```

### Persona Definitions

```javascript
const PERSONAS = {
  'first-time-buyer': {
    keywords: ['FTHB', 'first home', 'saving for down payment', 'pre-approval'],
    conflicts: ['investor']
  },
  'investor': {
    keywords: ['BRRRR', 'rental', 'ROI', 'cash flow', 'portfolio', 'multi-family'],
    conflicts: ['first-time-buyer']
  },
  'realtor': {
    keywords: ['agent tips', 'client advice', 'market insights for agents'],
    conflicts: ['first-time-buyer']
  },
  'refinancer': {
    keywords: ['refinance', 'equity', 'HELOC', 'debt consolidation'],
    conflicts: []
  },
  'self-employed': {
    keywords: ['business owners', 'stated income', 'tax returns', 'BFS'],
    conflicts: []
  },
  'general': {
    keywords: [],
    conflicts: []
  }
};
```

### Content Lifespan Rules

```javascript
const LIFESPAN_RULES = {
  'evergreen': {
    description: 'Timeless advice (how to qualify, what is BRRRR)',
    linkPreference: 'evergreen',  // Prefer linking to other evergreen
    penalty: false
  },
  'seasonal': {
    description: 'Spring market, tax season, year-end planning',
    linkPreference: 'any',
    penalty: false
  },
  'time-sensitive': {
    description: 'Rate announcements, policy changes (good for 1-2 years)',
    linkPreference: 'evergreen',
    penalty: false
  },
  'dated': {
    description: 'Mentions specific years/rates that will become stale',
    linkPreference: 'evergreen',
    penalty: true  // Penalty when linking FROM evergreen TO dated
  }
};
```

---

## Caching & Performance

### In-Memory Caches

```php
private $catalog_cache = null;  // Full catalog in memory
private $links_cache = null;    // All site links in memory

// Cleared between requests
// Preloaded before batch operations
```

### Transient Caches (5-minute TTL)

```php
'lendcity_catalog_stats'  // Count stats (total, posts, pages, pillars, clusters)
'lendcity_link_stats'     // Link distribution metrics
```

### Memory Optimizations

- Content compression: Original content stored with gzcompress (60-80% savings)
- Streaming queries: Fetch minimal columns, no JSON fields for catalog table views
- Garbage collection: `gc_collect_cycles()` between batch items
- Cache flush: `wp_cache_flush()` between posts in batch operations

---

## Background Processing

### Queue System

```php
// Queue storage
'lendcity_smart_linker_queue' => [123, 456, 789, ...]  // Post IDs to process
'lendcity_smart_linker_queue_status' => [
    'running' => true,
    'current_index' => 5,
    'total' => 50,
    'started_at' => '2024-01-15 10:00:00',
    'last_processed' => '2024-01-15 10:05:00'
]
```

### Database Locking

```sql
-- Prevent race conditions with MySQL locks
SELECT GET_LOCK('lendcity_queue_process', 0);  -- Acquire
SELECT RELEASE_LOCK('lendcity_queue_process'); -- Release
SELECT IS_FREE_LOCK('lendcity_queue_process'); -- Check
```

### Parallel Processing

- Batch size: 5 posts per parallel request
- Delay: 0.5s between parallel chunks (rate limiting)
- Uses `curl_multi` for concurrent API calls

---

## API Integration

### Claude API Call

```php
function call_claude_api($prompt, $max_tokens = 1500) {
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->api_key,
            'anthropic-version' => '2023-06-01'
        ],
        'body' => json_encode([
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => $max_tokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        'timeout' => 60
    ]);

    // Parse response...
    return [
        'success' => true,
        'text' => $response_text
    ];
}
```

---

## Key Functions Reference

### Core Functions

| Function | Purpose | Location |
|----------|---------|----------|
| `build_single_post_catalog()` | Index single post with Claude | Line 1449 |
| `build_batch_catalog()` | Index multiple posts in one API call | Line 1628 |
| `calculate_relevance_score()` | 300+ point scoring algorithm | Line 1950 |
| `create_links_from_source()` | Generate outgoing links from a post | Line 1777 |
| `build_linking_prompt()` | Construct prompt with full site catalog | Line 2481 |
| `insert_link_in_post()` | Insert link HTML into post content | - |
| `get_link_suggestions()` | Get suggestions for linking TO a target | Line 2594 |

### Scoring Helper Functions

| Function | Purpose |
|----------|---------|
| `get_tfidf_keyword_score()` | TF-IDF weighted keyword matching |
| `get_synonym_keyword_score()` | Synonym-aware keyword overlap |
| `get_entity_cluster_score()` | Entity to cluster mapping |
| `get_persona_conflict_penalty()` | Persona conflict detection |
| `get_deep_page_bonus()` | Orphan page prioritization |
| `get_anchor_diversity_penalty()` | Penalize overused anchors |

### Utility Functions

| Function | Purpose |
|----------|---------|
| `get_catalog()` | Retrieve full catalog |
| `get_catalog_entry()` | Get single entry by post ID |
| `insert_catalog_entry()` | Insert/update catalog entry |
| `get_page_priority()` | Get manual page priority |
| `get_page_keywords()` | Get target keywords for page |
| `build_keyword_frequency_index()` | Build TF-IDF index |
| `build_synonym_map()` | Build synonym lookup |
| `get_link_gaps()` | Find orphaned content |
| `get_link_stats()` | Link distribution analysis |

---

## Migration Checklist

When building the Next.js + Sanity.io system, ensure you port:

- [ ] All 15+ scoring categories in `calculate_relevance_score()`
- [ ] Persona conflict rules
- [ ] Topic cluster definitions
- [ ] Entity-to-cluster mapping
- [ ] Synonym map
- [ ] Link limits by word count
- [ ] TF-IDF keyword weighting
- [ ] Content lifespan rules
- [ ] Format flow rules
- [ ] Priority system (P1-P5)
- [ ] Target keyword feature
- [ ] Anchor diversity tracking
- [ ] Link gap detection
- [ ] SEO metadata generation

---

*Document Version: 1.0*
*Generated: January 2025*
*Source: LendCity Claude Integration WordPress Plugin v12.4.0*
