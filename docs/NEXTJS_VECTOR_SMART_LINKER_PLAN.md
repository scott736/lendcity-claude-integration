# Vector-Based Hybrid Smart Linker for Next.js + Sanity.io

> **Status:** Planning Phase
> **Target Scale:** 2,000 - 3,000+ articles
> **Current System:** WordPress plugin with rule-based scoring + Claude API

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Detailed System Design](#detailed-system-design)
3. [Business Rules to Port](#business-rules-to-port)
4. [Vector System Specifications](#vector-system-specifications)
5. [Additional Improvements](#additional-improvements)
6. [Technology Recommendations](#technology-recommendations)
7. [Migration Strategy](#migration-strategy)
8. [Notes & Decisions](#notes--decisions)

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                              SANITY.IO CMS                                   │
│                                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────────┐   │
│  │    Articles     │  │   Podcasts      │  │   Landing Pages             │   │
│  │                 │  │                 │  │                             │   │
│  │  - title        │  │  - title        │  │  - title                    │   │
│  │  - body         │  │  - transcript   │  │  - sections                 │   │
│  │  - summary      │  │  - summary      │  │  - summary                  │   │
│  │  - metadata     │  │  - metadata     │  │  - metadata                 │   │
│  └────────┬────────┘  └────────┬────────┘  └─────────────┬───────────────┘   │
│           │                    │                         │                   │
│           └────────────────────┼─────────────────────────┘                   │
│                                │                                             │
│                         Webhook on publish                                   │
└────────────────────────────────┼─────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                         EMBEDDING PIPELINE                                   │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐     │
│  │  1. Receive content via webhook                                     │     │
│  │  2. Extract text: title + summary + body (first 8000 chars)         │     │
│  │  3. Generate embedding via OpenAI/Voyage API                        │     │
│  │  4. Store vector + metadata in vector database                      │     │
│  │  5. Optionally: Generate anchor phrases via Claude (one-time)       │     │
│  └─────────────────────────────────────────────────────────────────────┘     │
│                                                                              │
└────────────────────────────────┼─────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                          VECTOR DATABASE                                     │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐     │
│  │  Index: "lendcity-articles"                                         │     │
│  │                                                                     │     │
│  │  Each vector record:                                                │     │
│  │  {                                                                  │     │
│  │    id: "article-{sanityId}",                                        │     │
│  │    values: [0.023, -0.041, ...],  // 1536 or 3072 dimensions        │     │
│  │    metadata: {                                                      │     │
│  │      sanityId: "abc123",                                            │     │
│  │      slug: "brrrr-strategy-complete-guide",                         │     │
│  │      title: "Complete BRRRR Strategy Guide",                        │     │
│  │      url: "/blog/brrrr-strategy-complete-guide",                    │     │
│  │      contentType: "article",  // article, podcast, page             │     │
│  │      funnelStage: "consideration",                                  │     │
│  │      targetPersona: "investor",                                     │     │
│  │      difficultyLevel: "intermediate",                               │     │
│  │      topicCluster: "brrrr-strategy",                                │     │
│  │      relatedClusters: ["financing", "rental-properties"],           │     │
│  │      isPillar: true,                                                │     │
│  │      qualityScore: 85,                                              │     │
│  │      freshnessScore: 90,                                            │     │
│  │      contentLifespan: "evergreen",                                  │     │
│  │      hasCta: true,                                                  │     │
│  │      hasCalculator: false,                                          │     │
│  │      anchorPhrases: ["BRRRR method", "buy rehab rent refinance"],   │     │
│  │      inboundLinkCount: 12,                                          │     │
│  │      publishedAt: "2024-03-15",                                     │     │
│  │      updatedAt: "2024-06-20"                                        │     │
│  │    }                                                                │     │
│  │  }                                                                  │     │
│  └─────────────────────────────────────────────────────────────────────┘     │
│                                                                              │
└────────────────────────────────┼─────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                      SMART LINKER SERVICE                                    │
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐     │
│  │                                                                     │     │
│  │  INPUT: Article content + metadata                                  │     │
│  │                                                                     │     │
│  │  STEP 1: CONTENT CHUNKING                                           │     │
│  │  ├── Split article into paragraphs/sections                         │     │
│  │  ├── Identify key phrases in each chunk                             │     │
│  │  └── Skip chunks that are too short or non-linkable                 │     │
│  │                                                                     │     │
│  │  STEP 2: VECTOR SEARCH (per chunk)                                  │     │
│  │  ├── Generate embedding for chunk                                   │     │
│  │  ├── Query vector DB for top 30 similar articles                    │     │
│  │  ├── Filter: exclude self, already-linked                           │     │
│  │  └── Return candidates with similarity scores                       │     │
│  │                                                                     │     │
│  │  STEP 3: BUSINESS RULE SCORING                                      │     │
│  │  ├── Funnel progression scoring                                     │     │
│  │  ├── Persona conflict filtering                                     │     │
│  │  ├── Difficulty progression scoring                                 │     │
│  │  ├── Topic cluster relevance                                        │     │
│  │  ├── Pillar content boosting                                        │     │
│  │  ├── Quality/freshness scoring                                      │     │
│  │  ├── Link gap prioritization                                        │     │
│  │  └── Anchor diversity penalties                                     │     │
│  │                                                                     │     │
│  │  STEP 4: ANCHOR TEXT SELECTION                                      │     │
│  │  ├── Match chunk text against target's anchorPhrases                │     │
│  │  ├── Find natural phrase boundaries                                 │     │
│  │  └── Ensure anchor isn't already used in article                    │     │
│  │                                                                     │     │
│  │  STEP 5: LINK LIMIT ENFORCEMENT                                     │     │
│  │  ├── Word count based limits (4-10 links)                           │     │
│  │  ├── Balance pages vs posts                                         │     │
│  │  └── Ensure even distribution through content                       │     │
│  │                                                                     │     │
│  │  OUTPUT: Array of link recommendations                              │     │
│  │  [                                                                  │     │
│  │    {                                                                │     │
│  │      paragraphIndex: 3,                                             │     │
│  │      anchorText: "BRRRR strategy",                                  │     │
│  │      targetUrl: "/blog/brrrr-strategy-complete-guide",              │     │
│  │      targetTitle: "Complete BRRRR Strategy Guide",                  │     │
│  │      confidence: 0.92,                                              │     │
│  │      reasoning: "semantic match + funnel progression"               │     │
│  │    },                                                               │     │
│  │    ...                                                              │     │
│  │  ]                                                                  │     │
│  │                                                                     │     │
│  └─────────────────────────────────────────────────────────────────────┘     │
│                                                                              │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## Detailed System Design

### 1. Sanity.io Schema Extensions

```javascript
// schemas/article.js - Fields needed for smart linking

export default {
  name: 'article',
  title: 'Article',
  type: 'document',
  fields: [
    // Core content
    { name: 'title', type: 'string', validation: Rule => Rule.required() },
    { name: 'slug', type: 'slug', options: { source: 'title' } },
    { name: 'body', type: 'portableText' },
    { name: 'summary', type: 'text', rows: 4,
      description: 'Used for SEO and vector embedding' },

    // === SMART LINKER METADATA ===

    // Funnel & Journey
    {
      name: 'funnelStage',
      type: 'string',
      options: {
        list: [
          { title: 'Awareness', value: 'awareness' },
          { title: 'Consideration', value: 'consideration' },
          { title: 'Decision', value: 'decision' }
        ]
      }
    },

    // Target Audience
    {
      name: 'targetPersona',
      type: 'string',
      options: {
        list: [
          { title: 'Real Estate Investor', value: 'investor' },
          { title: 'First-Time Buyer', value: 'first-time-buyer' },
          { title: 'Realtor/Agent', value: 'realtor' },
          { title: 'General', value: 'general' }
        ]
      }
    },

    // Content Classification
    {
      name: 'difficultyLevel',
      type: 'string',
      options: {
        list: [
          { title: 'Beginner', value: 'beginner' },
          { title: 'Intermediate', value: 'intermediate' },
          { title: 'Advanced', value: 'advanced' }
        ]
      }
    },

    // Topic Clustering
    {
      name: 'topicCluster',
      type: 'string',
      options: {
        list: [
          { title: 'BRRRR Strategy', value: 'brrrr-strategy' },
          { title: 'Financing', value: 'financing' },
          { title: 'Refinancing', value: 'refinancing' },
          { title: 'Rental Properties', value: 'rental-properties' },
          { title: 'First-Time Buying', value: 'first-time-buying' },
          { title: 'Market Analysis', value: 'market-analysis' },
          // Add more as needed
        ]
      }
    },
    {
      name: 'relatedClusters',
      type: 'array',
      of: [{ type: 'string' }],
      options: {
        list: [
          // Same list as topicCluster
        ]
      }
    },

    // Content Quality Signals
    { name: 'isPillar', type: 'boolean', initialValue: false },
    { name: 'qualityScore', type: 'number',
      validation: Rule => Rule.min(0).max(100) },
    {
      name: 'contentLifespan',
      type: 'string',
      options: {
        list: [
          { title: 'Evergreen', value: 'evergreen' },
          { title: 'Seasonal', value: 'seasonal' },
          { title: 'Time-Sensitive', value: 'time-sensitive' }
        ]
      }
    },

    // Conversion Elements
    { name: 'hasCta', type: 'boolean', initialValue: false },
    { name: 'hasCalculator', type: 'boolean', initialValue: false },
    { name: 'hasLeadForm', type: 'boolean', initialValue: false },

    // Geographic Targeting
    {
      name: 'targetRegions',
      type: 'array',
      of: [{ type: 'string' }],
      description: 'e.g., Ontario, British Columbia'
    },
    {
      name: 'targetCities',
      type: 'array',
      of: [{ type: 'string' }],
      description: 'e.g., Toronto, Vancouver'
    },

    // Linking Preferences
    {
      name: 'anchorPhrases',
      type: 'array',
      of: [{ type: 'string' }],
      description: 'Natural phrases to use when linking TO this article'
    },
    {
      name: 'mustLinkTo',
      type: 'array',
      of: [{ type: 'reference', to: [{ type: 'article' }] }],
      description: 'Articles that MUST be linked from this one'
    },
    {
      name: 'neverLinkTo',
      type: 'array',
      of: [{ type: 'reference', to: [{ type: 'article' }] }],
      description: 'Articles that should NEVER be linked from this one'
    }
  ]
}
```

### 2. Webhook Handler (Next.js API Route)

```javascript
// app/api/webhooks/sanity/route.js

import { generateEmbedding } from '@/lib/embeddings';
import { upsertVector } from '@/lib/vector-db';
import { sanityClient } from '@/lib/sanity';

export async function POST(request) {
  const body = await request.json();

  // Verify webhook signature (important for security)
  // ...

  const { _type, _id, operation } = body;

  if (operation === 'delete') {
    await deleteVector(`${_type}-${_id}`);
    return Response.json({ success: true });
  }

  // Fetch full document from Sanity
  const doc = await sanityClient.fetch(
    `*[_id == $id][0]`,
    { id: _id }
  );

  // Generate embedding text
  const embeddingText = [
    doc.title,
    doc.summary || '',
    extractPlainText(doc.body).slice(0, 8000)
  ].join('\n\n');

  // Generate embedding
  const embedding = await generateEmbedding(embeddingText);

  // Prepare metadata
  const metadata = {
    sanityId: _id,
    slug: doc.slug?.current,
    title: doc.title,
    url: `/${_type}/${doc.slug?.current}`,
    contentType: _type,
    funnelStage: doc.funnelStage || 'awareness',
    targetPersona: doc.targetPersona || 'general',
    difficultyLevel: doc.difficultyLevel || 'beginner',
    topicCluster: doc.topicCluster || 'general',
    relatedClusters: doc.relatedClusters || [],
    isPillar: doc.isPillar || false,
    qualityScore: doc.qualityScore || 50,
    contentLifespan: doc.contentLifespan || 'evergreen',
    hasCta: doc.hasCta || false,
    hasCalculator: doc.hasCalculator || false,
    anchorPhrases: doc.anchorPhrases || [],
    publishedAt: doc.publishedAt,
    updatedAt: doc._updatedAt
  };

  // Upsert to vector database
  await upsertVector({
    id: `${_type}-${_id}`,
    values: embedding,
    metadata
  });

  return Response.json({ success: true });
}
```

### 3. Smart Linker Core Logic

```javascript
// lib/smart-linker.js

import { generateEmbedding } from './embeddings';
import { queryVectors } from './vector-db';

// Configuration
const LINK_LIMITS = {
  short: { maxLinks: 4, maxPages: 2, maxPosts: 2 },      // < 800 words
  medium: { maxLinks: 7, maxPages: 3, maxPosts: 4 },     // 800-1500 words
  long: { maxLinks: 10, maxPages: 3, maxPosts: 7 }       // > 1500 words
};

const PERSONA_CONFLICTS = {
  'investor': ['first-time-buyer'],
  'realtor': ['first-time-buyer'],
  'first-time-buyer': ['investor', 'realtor']
};

const FUNNEL_ORDER = ['awareness', 'consideration', 'decision'];
const DIFFICULTY_ORDER = ['beginner', 'intermediate', 'advanced'];

export async function generateSmartLinks(article, paragraphs) {
  const links = [];
  const usedTargets = new Set();
  const usedAnchors = new Set();

  // Determine link limits based on word count
  const wordCount = paragraphs.reduce((sum, p) => sum + p.wordCount, 0);
  const limits = wordCount < 800 ? LINK_LIMITS.short
               : wordCount < 1500 ? LINK_LIMITS.medium
               : LINK_LIMITS.long;

  // Process each paragraph
  for (const paragraph of paragraphs) {
    if (links.length >= limits.maxLinks) break;
    if (paragraph.wordCount < 30) continue; // Skip short paragraphs

    // Step 1: Vector search
    const embedding = await generateEmbedding(paragraph.text);
    const candidates = await queryVectors({
      vector: embedding,
      topK: 30,
      filter: {
        sanityId: { $ne: article.sanityId }
      }
    });

    // Step 2: Score with business rules
    const scored = candidates.map(candidate => ({
      ...candidate,
      finalScore: calculateHybridScore(article, candidate, paragraph, usedAnchors)
    }));

    // Step 3: Select best match
    const sorted = scored
      .filter(c => c.finalScore > 50) // Minimum threshold
      .filter(c => !usedTargets.has(c.metadata.sanityId))
      .sort((a, b) => b.finalScore - a.finalScore);

    if (sorted.length === 0) continue;

    const best = sorted[0];

    // Step 4: Find anchor text
    const anchor = findBestAnchor(paragraph.text, best.metadata.anchorPhrases, usedAnchors);
    if (!anchor) continue;

    // Record the link
    links.push({
      paragraphIndex: paragraph.index,
      anchorText: anchor,
      targetUrl: best.metadata.url,
      targetSlug: best.metadata.slug,
      targetTitle: best.metadata.title,
      confidence: best.finalScore / 100,
      vectorSimilarity: best.score,
      isPage: best.metadata.contentType === 'page'
    });

    usedTargets.add(best.metadata.sanityId);
    usedAnchors.add(anchor.toLowerCase());
  }

  // Enforce page/post balance
  return balanceLinks(links, limits);
}

function calculateHybridScore(source, candidate, paragraph, usedAnchors) {
  // Start with vector similarity (0-1 scaled to 0-50)
  let score = candidate.score * 50;

  const meta = candidate.metadata;

  // === FUNNEL PROGRESSION (0-25 points) ===
  const sourceFunnel = FUNNEL_ORDER.indexOf(source.funnelStage);
  const targetFunnel = FUNNEL_ORDER.indexOf(meta.funnelStage);
  if (targetFunnel === sourceFunnel + 1) score += 25;      // Next stage
  else if (targetFunnel === sourceFunnel) score += 15;     // Same stage
  else if (Math.abs(targetFunnel - sourceFunnel) === 1) score += 10; // Adjacent

  // === PERSONA MATCHING (-50 to +30 points) ===
  const conflicts = PERSONA_CONFLICTS[source.targetPersona] || [];
  if (conflicts.includes(meta.targetPersona)) {
    score -= 50; // Heavy penalty for conflicts
  } else if (source.targetPersona === meta.targetPersona) {
    score += 30; // Same persona bonus
  } else if (meta.targetPersona === 'general') {
    score += 10; // General content is always okay
  }

  // === DIFFICULTY PROGRESSION (0-15 points) ===
  const sourceDiff = DIFFICULTY_ORDER.indexOf(source.difficultyLevel);
  const targetDiff = DIFFICULTY_ORDER.indexOf(meta.difficultyLevel);
  if (targetDiff === sourceDiff + 1) score += 15;          // Next level
  else if (targetDiff === sourceDiff) score += 10;         // Same level
  else if (targetDiff < sourceDiff) score += 5;            // Easier content

  // === TOPIC CLUSTER (0-30 points) ===
  if (source.topicCluster === meta.topicCluster) {
    score += 30; // Same cluster
  } else if (source.relatedClusters?.includes(meta.topicCluster)) {
    score += 20; // Related cluster
  } else if (meta.relatedClusters?.includes(source.topicCluster)) {
    score += 20; // Related cluster (reverse)
  }

  // === PILLAR CONTENT BOOST (0-20 points) ===
  if (meta.isPillar) score += 20;

  // === QUALITY SCORE (0-10 points) ===
  score += (meta.qualityScore || 50) / 10;

  // === CONTENT LIFESPAN (0-15 points, with penalties) ===
  if (source.contentLifespan === 'evergreen' && meta.contentLifespan === 'evergreen') {
    score += 15;
  } else if (source.contentLifespan === 'evergreen' && meta.contentLifespan === 'time-sensitive') {
    score -= 10; // Penalty: evergreen linking to dated content
  }

  // === CONVERSION SIGNALS (0-15 points) ===
  if (meta.hasCta) score += 5;
  if (meta.hasCalculator) score += 5;
  if (meta.hasLeadForm) score += 5;

  // === ANCHOR DIVERSITY PENALTY ===
  // Check if any of the target's anchor phrases are already used
  const availableAnchors = (meta.anchorPhrases || [])
    .filter(a => !usedAnchors.has(a.toLowerCase()));
  if (availableAnchors.length === 0) {
    score -= 20; // Penalty if no fresh anchors available
  }

  // === GEOGRAPHIC MATCHING (0-20 points) ===
  if (source.targetRegions?.some(r => meta.targetRegions?.includes(r))) {
    score += 15;
  }
  if (source.targetCities?.some(c => meta.targetCities?.includes(c))) {
    score += 5;
  }

  return Math.max(0, score);
}

function findBestAnchor(paragraphText, anchorPhrases, usedAnchors) {
  const textLower = paragraphText.toLowerCase();

  // Sort anchor phrases by length (prefer longer, more specific phrases)
  const sorted = [...(anchorPhrases || [])]
    .filter(phrase => !usedAnchors.has(phrase.toLowerCase()))
    .sort((a, b) => b.length - a.length);

  for (const phrase of sorted) {
    const phraseLower = phrase.toLowerCase();
    const index = textLower.indexOf(phraseLower);

    if (index !== -1) {
      // Extract the actual text (preserving original case)
      return paragraphText.slice(index, index + phrase.length);
    }
  }

  return null; // No matching anchor found
}

function balanceLinks(links, limits) {
  const pages = links.filter(l => l.isPage);
  const posts = links.filter(l => !l.isPage);

  const selectedPages = pages.slice(0, limits.maxPages);
  const selectedPosts = posts.slice(0, limits.maxPosts);

  return [...selectedPages, ...selectedPosts]
    .sort((a, b) => a.paragraphIndex - b.paragraphIndex)
    .slice(0, limits.maxLinks);
}
```

---

## Business Rules to Port

These rules from the current WordPress plugin should be carried over:

### Scoring Categories

| Category | Points | Status | Notes |
|----------|--------|--------|-------|
| Vector similarity | 0-50 | NEW | Replaces keyword/TF-IDF matching |
| Topic cluster match | 0-30 | PORT | Same cluster +30, related +20 |
| Funnel progression | 0-25 | PORT | Next stage +25, same +15 |
| Persona matching | -50 to +30 | PORT | Conflicts -50, match +30 |
| Difficulty progression | 0-15 | PORT | Next level +15 |
| Pillar content boost | 0-20 | PORT | Pillar articles +20 |
| Quality score | 0-10 | PORT | Based on quality score field |
| Content lifespan | -10 to +15 | PORT | Evergreen-to-evergreen +15 |
| Conversion signals | 0-15 | PORT | CTA +5, calculator +5, lead form +5 |
| Geographic matching | 0-20 | PORT | Region +15, city +5 |
| Anchor diversity | -20 to 0 | PORT | Penalty for overused anchors |
| Link gap priority | 0-25 | CONSIDER | Orphaned pages priority |

### Persona Conflict Rules

```
investor ←✗→ first-time-buyer
realtor  ←✗→ first-time-buyer
```

### Link Limits by Word Count

| Word Count | Max Links | Max Pages | Max Posts |
|------------|-----------|-----------|-----------|
| < 800 | 4 | 2 | 2 |
| 800-1500 | 7 | 3 | 4 |
| > 1500 | 10 | 3 | 7 |

---

## Vector System Specifications

### Embedding Model Comparison

| Model | Dimensions | Quality | Cost/1M tokens | Recommendation |
|-------|------------|---------|----------------|----------------|
| OpenAI text-embedding-3-small | 1536 | Good | $0.02 | Budget option |
| OpenAI text-embedding-3-large | 3072 | Excellent | $0.13 | Best quality |
| Voyage voyage-3 | 1024 | Excellent | $0.06 | Best for retrieval |
| Cohere embed-v3 | 1024 | Great | $0.10 | Good multilingual |

**NOTES:**
<!-- Add your notes about embedding model preference here -->


### Vector Database Comparison

| Database | Free Tier | Pros | Cons |
|----------|-----------|------|------|
| Pinecone | 100k vectors | Easiest setup, great docs | Vendor lock-in |
| Supabase pgvector | 500MB | PostgreSQL, own your data | Slower at scale |
| Qdrant Cloud | 1GB | Best price/performance | Newer platform |
| Weaviate Cloud | 100k vectors | Hybrid search built-in | More complex |

**NOTES:**
<!-- Add your notes about vector database preference here -->


### Estimated Costs (3,000 articles)

| Component | One-Time | Monthly |
|-----------|----------|---------|
| Initial embeddings | ~$5-15 | - |
| Re-embeddings (updates) | - | ~$1-3 |
| Vector DB hosting | - | $0 (free tier) |
| Query costs | - | ~$0.50-2 |
| **Total** | ~$15 | ~$5 |

Compare to current: ~$50-150/month in Claude API calls for linking

---

## Additional Improvements

Beyond basic linking, the vector system enables these features:

### 1. Related Articles Widget

```javascript
// Get 5 most similar articles for "You might also like" section
async function getRelatedArticles(articleId, limit = 5) {
  const article = await getArticleEmbedding(articleId);

  return queryVectors({
    vector: article.embedding,
    topK: limit + 1,
    filter: { sanityId: { $ne: articleId } }
  });
}
```

**Benefits:**
- No manual curation needed
- Always semantically relevant
- Considers full content, not just tags

**NOTES:**
<!-- Add your thoughts on related articles feature -->


### 2. Smart Search

```javascript
// Semantic search across all content
async function smartSearch(query, filters = {}) {
  const queryEmbedding = await generateEmbedding(query);

  return queryVectors({
    vector: queryEmbedding,
    topK: 20,
    filter: filters // Can filter by persona, funnel stage, etc.
  });
}
```

**Benefits:**
- "How do I finance my first rental?" finds BRRRR content even without exact keyword match
- Understands intent, not just keywords
- Can combine with filters (show only beginner content, etc.)

**NOTES:**
<!-- Add your thoughts on smart search feature -->


### 3. Content Gap Analysis

```javascript
// Find topics that aren't well covered
async function findContentGaps() {
  // Cluster all articles by similarity
  const clusters = await clusterArticles();

  // Find sparse areas in the embedding space
  const gaps = identifySparseRegions(clusters);

  // Suggest new article topics based on gaps
  return gaps.map(gap => ({
    suggestedTopic: gap.nearestTopics,
    existingRelated: gap.nearestArticles,
    coverage: gap.density
  }));
}
```

**Benefits:**
- Data-driven content strategy
- Find what competitors cover that you don't
- Identify thin content areas

**NOTES:**
<!-- Add your thoughts on content gap analysis -->


### 4. Automatic Topic Clustering

```javascript
// Auto-generate topic clusters from content
async function autoCluster() {
  const allVectors = await getAllVectors();

  // Use k-means or HDBSCAN to find natural clusters
  const clusters = clusterVectors(allVectors);

  // Name clusters based on common terms in each
  return clusters.map(cluster => ({
    name: extractClusterName(cluster),
    articles: cluster.members,
    centroid: cluster.centroid
  }));
}
```

**Benefits:**
- Topics emerge from content naturally
- No manual tagging needed
- Discovers unexpected topic relationships

**NOTES:**
<!-- Add your thoughts on automatic clustering -->


### 5. Personalized Recommendations

```javascript
// Based on user reading history
async function getPersonalizedRecs(userId) {
  const userHistory = await getUserReadHistory(userId);

  // Create user "taste" embedding from their reading
  const tasteVector = averageEmbeddings(userHistory.map(h => h.embedding));

  // Find content matching their taste they haven't read
  return queryVectors({
    vector: tasteVector,
    topK: 10,
    filter: {
      sanityId: { $nin: userHistory.map(h => h.id) }
    }
  });
}
```

**Benefits:**
- Netflix-style recommendations for content
- Increases engagement and time on site
- No collaborative filtering needed (works with sparse data)

**NOTES:**
<!-- Add your thoughts on personalization -->


### 6. Duplicate/Similar Content Detection

```javascript
// Find potentially duplicate or cannibalizing content
async function findDuplicates(threshold = 0.95) {
  const allVectors = await getAllVectors();

  const duplicates = [];
  for (const article of allVectors) {
    const similar = await queryVectors({
      vector: article.values,
      topK: 5,
      filter: { sanityId: { $ne: article.id } }
    });

    const tooSimilar = similar.filter(s => s.score > threshold);
    if (tooSimilar.length > 0) {
      duplicates.push({ article, similar: tooSimilar });
    }
  }

  return duplicates;
}
```

**Benefits:**
- Find keyword cannibalization issues
- Identify content to merge or differentiate
- SEO cleanup

**NOTES:**
<!-- Add your thoughts on duplicate detection -->


### 7. Content Quality Scoring via Embedding Analysis

```javascript
// Analyze content quality based on embedding characteristics
async function analyzeContentQuality(articleId) {
  const article = await getArticle(articleId);
  const embedding = article.embedding;

  // Find distance to cluster centroid (topical focus)
  const clusterCentroid = await getClusterCentroid(article.topicCluster);
  const topicalFocus = cosineSimilarity(embedding, clusterCentroid);

  // Find uniqueness (distance from all other content)
  const nearestNeighbors = await queryVectors({ vector: embedding, topK: 5 });
  const uniqueness = 1 - average(nearestNeighbors.map(n => n.score));

  return {
    topicalFocus,  // How well it fits its cluster
    uniqueness,    // How different it is from other content
    coverage: nearestNeighbors.length  // How many similar articles exist
  };
}
```

**NOTES:**
<!-- Add your thoughts on quality scoring -->


### 8. Multi-Language Support

```javascript
// Vectors work across languages (with multilingual models)
async function findTranslationMatches(articleId, targetLanguage) {
  const article = await getArticle(articleId);

  return queryVectors({
    vector: article.embedding,
    topK: 10,
    filter: { language: targetLanguage }
  });
}
```

**Benefits:**
- Link between English and French content naturally
- No translation of keywords needed
- Embeddings capture meaning across languages

**NOTES:**
<!-- Add your thoughts on multi-language support -->


---

## Technology Recommendations

### Recommended Stack

| Layer | Recommendation | Alternatives |
|-------|----------------|--------------|
| CMS | Sanity.io | Contentful, Strapi |
| Frontend | Next.js (App Router) | Remix, Astro |
| Embeddings | OpenAI text-embedding-3-large | Voyage voyage-3 |
| Vector DB | Pinecone (starter) | Qdrant (scale) |
| Hosting | Vercel | Netlify, Railway |
| Caching | Vercel KV or Upstash Redis | - |

### Why This Stack

1. **Sanity.io**
   - Excellent webhook support
   - GROQ queries are powerful
   - Real-time collaboration
   - Customizable studio

2. **Next.js App Router**
   - Server components reduce client JS
   - API routes for webhooks
   - ISR for static + fresh content
   - Vercel integration

3. **Pinecone**
   - Zero ops vector database
   - Metadata filtering built-in
   - Free tier is generous (100k vectors = ~30k articles)
   - Excellent documentation

**NOTES:**
<!-- Add your technology preferences/concerns here -->


---

## Migration Strategy

### Phase 1: Parallel Run
1. Export current WordPress catalog to JSON
2. Generate embeddings for all articles
3. Build Next.js prototype with vector linking
4. Compare link suggestions side-by-side

### Phase 2: Sanity Migration
1. Set up Sanity schema with smart linker fields
2. Migrate content from WordPress
3. Preserve existing metadata (funnel, persona, etc.)
4. Generate fresh embeddings

### Phase 3: Cutover
1. Deploy Next.js site
2. Enable Sanity webhooks for auto-embedding
3. Retire WordPress

### Data Export Needed from WordPress

```sql
-- Export catalog data
SELECT
  post_id, title, url, summary,
  main_topics, semantic_keywords, entities,
  reader_intent, difficulty_level, funnel_stage,
  topic_cluster, related_clusters,
  target_persona, content_quality_score,
  content_lifespan, target_regions, target_cities,
  good_anchor_phrases, is_pillar
FROM wp_lendcity_catalog;
```

**NOTES:**
<!-- Add your migration concerns/questions here -->


---

## Notes & Decisions

### Open Questions

1. **Build vs runtime linking?**
   - Build time: Links baked into static pages, fastest load
   - Runtime: Fresh links on every request, more compute
   - Hybrid: ISR with periodic revalidation?

   **Decision:**
   <!-- Record your decision here -->

2. **Embedding model choice?**
   - OpenAI: Most convenient, good quality
   - Voyage: Better retrieval performance, slightly cheaper
   - Self-hosted: Maximum control, more ops work

   **Decision:**
   <!-- Record your decision here -->

3. **Vector database choice?**
   - Pinecone: Easiest, good free tier
   - Supabase pgvector: Own your data, PostgreSQL familiar
   - Qdrant: Best performance per dollar

   **Decision:**
   <!-- Record your decision here -->

4. **When to generate anchor phrases?**
   - Option A: Claude generates on publish (one-time cost)
   - Option B: Extract from content automatically (no AI cost)
   - Option C: Manual curation in Sanity

   **Decision:**
   <!-- Record your decision here -->

5. **Link insertion approach?**
   - Option A: Portable Text custom marks (Sanity native)
   - Option B: Post-processing at render time
   - Option C: Pre-computed and stored

   **Decision:**
   <!-- Record your decision here -->

---

### Session Notes

<!-- Add dated notes from planning sessions below -->

#### [Date: ____]

**Discussed:**

**Decided:**

**Action Items:**

---

#### [Date: ____]

**Discussed:**

**Decided:**

**Action Items:**

---

### Cost Estimates

| Scenario | Monthly Cost Estimate |
|----------|-----------------------|
| 3,000 articles, 100 updates/month | ~$5-10 |
| 3,000 articles, 500 updates/month | ~$15-25 |
| 10,000 articles, 500 updates/month | ~$30-50 |

Current WordPress plugin estimate: $50-150/month

**NOTES:**
<!-- Add your budget considerations here -->


---

### Implementation Priority

| Feature | Priority | Complexity | Notes |
|---------|----------|------------|-------|
| Core smart linking | P0 | High | Must have |
| Related articles widget | P1 | Low | Quick win |
| Smart search | P1 | Medium | User-facing value |
| Content gap analysis | P2 | Medium | Strategy tool |
| Duplicate detection | P2 | Low | SEO cleanup |
| Auto-clustering | P3 | High | Nice to have |
| Personalization | P3 | High | Needs user tracking |

**NOTES:**
<!-- Add your priority thoughts here -->


---

## Appendix

### Useful Resources

- [Pinecone Documentation](https://docs.pinecone.io/)
- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [Sanity Webhooks](https://www.sanity.io/docs/webhooks)
- [Next.js App Router](https://nextjs.org/docs/app)
- [Voyage AI](https://www.voyageai.com/)

### Glossary

- **Embedding**: A vector representation of text that captures semantic meaning
- **Vector similarity**: How close two embeddings are (cosine similarity)
- **Funnel stage**: Where content fits in buyer journey
- **Pillar content**: Cornerstone articles that other content links to
- **Anchor phrase**: The clickable text in a hyperlink

---

*Last updated: [Date]*
*Version: 0.1 (Planning)*
