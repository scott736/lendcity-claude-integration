# LendCity Smart Linker API

Vector-based internal linking API with hybrid scoring (vectors + business rules).

## Architecture

```
WordPress Plugin → This API → Pinecone (vectors) + Claude (anchors)
                              OpenAI (embeddings)
```

## Quick Start

### 1. Create Pinecone Index

1. Sign up at [pinecone.io](https://pinecone.io)
2. Create a new index:
   - Name: `lendcity-catalog`
   - Dimensions: `1536` (for text-embedding-3-small)
   - Metric: `cosine`
   - Starter plan works fine for 3k articles

### 2. Get API Keys

- **Pinecone**: Dashboard → API Keys
- **OpenAI**: platform.openai.com → API Keys
- **Anthropic**: console.anthropic.com → API Keys

### 3. Deploy to Vercel

```bash
# Clone and install
cd smart-linker-api
npm install

# Set up environment variables in Vercel:
# - PINECONE_API_KEY
# - PINECONE_INDEX (e.g., lendcity-catalog)
# - OPENAI_API_KEY
# - ANTHROPIC_API_KEY
# - API_SECRET_KEY (generate a random string)

# Deploy
npx vercel --prod
```

### 4. Migrate Existing Catalog

```bash
# Export from WordPress (add endpoint to plugin first - see script for details)
curl https://yoursite.com/wp-json/smart-linker/v1/export-catalog > catalog.json

# Run migration
cp .env.example .env  # Fill in your keys
npm run migrate -- --file=catalog.json
```

### 5. Update WordPress Plugin

Update your plugin to call the API instead of local processing:

```php
// In class-smart-linker.php, replace local catalog query with:
private function get_smart_links($post_id, $content) {
    $response = wp_remote_post('https://your-api.vercel.app/api/smart-link', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . SMART_LINKER_API_KEY
        ],
        'body' => json_encode([
            'postId' => $post_id,
            'content' => $content,
            'title' => get_the_title($post_id),
            'topicCluster' => get_post_meta($post_id, 'topic_cluster', true),
            'funnelStage' => get_post_meta($post_id, 'funnel_stage', true),
            'targetPersona' => get_post_meta($post_id, 'target_persona', true),
            'maxLinks' => 5
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['links'] ?? [];
}
```

## API Endpoints

### POST /api/smart-link

Generate internal link suggestions for content.

**Request:**
```json
{
  "postId": 123,
  "content": "<p>Article HTML content...</p>",
  "title": "Article Title",
  "topicCluster": "brrrr-strategy",
  "funnelStage": "consideration",
  "targetPersona": "investor",
  "maxLinks": 5,
  "useClaudeAnalysis": true
}
```

**Response:**
```json
{
  "success": true,
  "links": [
    {
      "postId": 456,
      "title": "BRRRR Strategy Complete Guide",
      "url": "/blog/brrrr-guide",
      "score": 87.5,
      "anchorText": "BRRRR method",
      "placement": "when discussing the refinance step",
      "reasoning": "Directly relevant to BRRRR discussion"
    }
  ],
  "stats": {
    "candidatesFound": 30,
    "passedScoring": 8,
    "linksGenerated": 5
  }
}
```

### POST /api/meta-generate

Generate SEO meta title and description.

**Request:**
```json
{
  "title": "Article Title",
  "content": "Article content...",
  "focusKeyword": "BRRRR strategy"
}
```

**Response:**
```json
{
  "success": true,
  "meta": {
    "title": "BRRRR Strategy: Complete Canadian Guide 2024",
    "description": "Learn the BRRRR method for Canadian real estate. Step-by-step guide to Buy, Rehab, Rent, Refinance, Repeat. Start building your portfolio today."
  },
  "keywords": {
    "main": ["BRRRR", "real estate investing"],
    "semantic": ["refinancing", "rental properties", "cash flow"]
  }
}
```

### POST /api/catalog-sync

Sync article to Pinecone catalog (called on publish).

**Request:**
```json
{
  "postId": 123,
  "title": "Article Title",
  "url": "/blog/article-slug",
  "content": "Full article content...",
  "topicCluster": "brrrr-strategy",
  "funnelStage": "consideration",
  "targetPersona": "investor"
}
```

### GET /api/health

Check API and service health.

## Scoring System

The hybrid scoring combines:

| Component | Weight | Description |
|-----------|--------|-------------|
| Vector Similarity | 40% | Semantic relevance via embeddings |
| Business Rules | 60% | Cluster, funnel, persona, quality |

### Business Rule Breakdown

| Rule | Points | Description |
|------|--------|-------------|
| Topic Cluster | 0-50 | Same/related cluster matching |
| Funnel Flow | 0-25 | Optimal funnel progression |
| Persona Match | -30 to +30 | Audience compatibility |
| Quality Score | 0-30 | Content quality signals |
| Freshness | 0-20 | Last update date |
| Link Balance | 0-25 | Favors under-linked content |
| Pillar Bonus | 0-15 | Bonus for pillar content |

## Costs

Estimated monthly costs for 500 articles, 100 link generations/day:

| Service | Free Tier | Estimated Cost |
|---------|-----------|----------------|
| Pinecone | 100k vectors | $0 |
| OpenAI Embeddings | - | ~$2-5/mo |
| Claude API | - | ~$5-10/mo |
| Vercel | 100k requests | $0 |

## Local Development

```bash
# Install dependencies
npm install

# Copy environment file
cp .env.example .env
# Fill in your API keys

# Run locally
npm run dev

# Test health endpoint
curl http://localhost:3000/api/health
```
