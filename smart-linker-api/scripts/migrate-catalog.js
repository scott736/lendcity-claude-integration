#!/usr/bin/env node
/**
 * WordPress Catalog to Pinecone Migration Script
 *
 * This script reads an exported JSON catalog from WordPress
 * and migrates it to Pinecone with vector embeddings.
 *
 * Usage:
 *   1. Export catalog from WordPress using the provided endpoint
 *   2. Run: node scripts/migrate-catalog.js --file=catalog-export.json
 *
 * Options:
 *   --file=<path>     Path to exported catalog JSON
 *   --batch=<size>    Batch size for processing (default: 10)
 *   --dry-run         Preview without writing to Pinecone
 */

require('dotenv').config();

const fs = require('fs');
const path = require('path');

// Import from parent directory
const { upsertArticle, getIndex } = require('../lib/pinecone');
const { generateArticleEmbedding } = require('../lib/embeddings');
const { generateSummary, extractKeywords } = require('../lib/claude');

// Parse command line arguments
const args = process.argv.slice(2).reduce((acc, arg) => {
  const [key, value] = arg.replace('--', '').split('=');
  acc[key] = value || true;
  return acc;
}, {});

const BATCH_SIZE = parseInt(args.batch) || 10;
const DRY_RUN = args['dry-run'] || false;
const INPUT_FILE = args.file;

async function main() {
  console.log('='.repeat(60));
  console.log('WordPress to Pinecone Migration');
  console.log('='.repeat(60));

  if (!INPUT_FILE) {
    console.error('\nError: No input file specified');
    console.log('\nUsage: node scripts/migrate-catalog.js --file=catalog-export.json');
    console.log('\nTo export from WordPress, add this endpoint to your plugin:');
    console.log(`
// Add to your WordPress plugin
add_action('rest_api_init', function() {
  register_rest_route('smart-linker/v1', '/export-catalog', array(
    'methods' => 'GET',
    'callback' => 'export_catalog_for_migration',
    'permission_callback' => function() {
      return current_user_can('manage_options');
    }
  ));
});

function export_catalog_for_migration() {
  global $wpdb;
  $table = $wpdb->prefix . 'smart_linker_catalog';
  $results = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
  return rest_ensure_response($results);
}
`);
    process.exit(1);
  }

  // Read catalog file
  console.log(`\nReading catalog from: ${INPUT_FILE}`);
  let catalog;
  try {
    const raw = fs.readFileSync(INPUT_FILE, 'utf8');
    catalog = JSON.parse(raw);
  } catch (error) {
    console.error(`Error reading file: ${error.message}`);
    process.exit(1);
  }

  console.log(`Found ${catalog.length} articles to migrate`);

  if (DRY_RUN) {
    console.log('\n[DRY RUN MODE - No data will be written]\n');
  }

  // Verify Pinecone connection
  try {
    const index = getIndex();
    console.log(`Connected to Pinecone index: ${process.env.PINECONE_INDEX}`);
  } catch (error) {
    console.error(`Pinecone connection failed: ${error.message}`);
    process.exit(1);
  }

  // Process in batches
  const batches = [];
  for (let i = 0; i < catalog.length; i += BATCH_SIZE) {
    batches.push(catalog.slice(i, i + BATCH_SIZE));
  }

  console.log(`\nProcessing ${batches.length} batches of ${BATCH_SIZE} articles each\n`);

  let processed = 0;
  let errors = 0;
  const startTime = Date.now();

  for (let batchIndex = 0; batchIndex < batches.length; batchIndex++) {
    const batch = batches[batchIndex];
    console.log(`Batch ${batchIndex + 1}/${batches.length}:`);

    for (const article of batch) {
      try {
        // Map WordPress catalog fields to our schema
        const mapped = mapWordPressArticle(article);

        // Generate embedding
        console.log(`  Processing: ${mapped.title.slice(0, 50)}...`);
        const embedding = await generateArticleEmbedding({
          title: mapped.title,
          summary: mapped.summary,
          body: mapped.content || ''
        });
        mapped.embedding = embedding;

        // Generate summary if missing
        if (!mapped.summary && mapped.content) {
          mapped.summary = await generateSummary(mapped.content);
        }

        // Extract keywords if missing
        if ((!mapped.mainTopics || mapped.mainTopics.length === 0) && mapped.content) {
          const keywords = await extractKeywords(mapped.content);
          mapped.mainTopics = keywords.mainTopics;
          mapped.semanticKeywords = keywords.semanticKeywords;
        }

        // Upsert to Pinecone
        if (!DRY_RUN) {
          await upsertArticle(mapped);
        }

        processed++;
        console.log(`    ✓ Done (${processed}/${catalog.length})`);

      } catch (error) {
        errors++;
        console.log(`    ✗ Error: ${error.message}`);
      }

      // Rate limiting - avoid API throttling
      await sleep(200);
    }

    // Pause between batches
    if (batchIndex < batches.length - 1) {
      console.log('  Pausing 2s before next batch...\n');
      await sleep(2000);
    }
  }

  const duration = ((Date.now() - startTime) / 1000).toFixed(1);

  console.log('\n' + '='.repeat(60));
  console.log('Migration Complete');
  console.log('='.repeat(60));
  console.log(`\nProcessed: ${processed}/${catalog.length} articles`);
  console.log(`Errors: ${errors}`);
  console.log(`Duration: ${duration}s`);

  if (DRY_RUN) {
    console.log('\n[DRY RUN - No data was written to Pinecone]');
  }
}

/**
 * Map WordPress catalog fields to Pinecone schema
 */
function mapWordPressArticle(wp) {
  return {
    postId: wp.post_id || wp.id,
    title: wp.title || '',
    url: wp.url || wp.permalink || '',
    slug: wp.slug || wp.url?.split('/').pop() || '',
    content: wp.content || wp.body || '',
    contentType: wp.content_type || wp.type || 'article',

    // Business rule fields
    topicCluster: wp.topic_cluster || wp.cluster || null,
    relatedClusters: parseJsonField(wp.related_clusters) || [],
    funnelStage: wp.funnel_stage || null,
    targetPersona: wp.target_persona || null,
    difficultyLevel: wp.difficulty_level || 'intermediate',

    // Quality signals
    qualityScore: parseInt(wp.content_quality_score) || parseInt(wp.quality_score) || 50,
    contentLifespan: wp.content_lifespan || 'evergreen',
    isPillar: wp.is_pillar === '1' || wp.is_pillar === true,

    // Catalog data
    summary: wp.summary || '',
    mainTopics: parseJsonField(wp.main_topics) || [],
    semanticKeywords: parseJsonField(wp.semantic_keywords) || [],
    anchorPhrases: parseJsonField(wp.anchor_phrases) || parseJsonField(wp.optimal_anchors) || [],
    inboundLinkCount: parseInt(wp.inbound_link_count) || 0,

    // Dates
    publishedAt: wp.published_at || wp.post_date || new Date().toISOString(),
    updatedAt: wp.updated_at || wp.post_modified || new Date().toISOString()
  };
}

/**
 * Parse JSON field that might be string or already parsed
 */
function parseJsonField(value) {
  if (!value) return null;
  if (Array.isArray(value)) return value;
  if (typeof value === 'string') {
    try {
      return JSON.parse(value);
    } catch (e) {
      // Might be comma-separated
      return value.split(',').map(s => s.trim()).filter(Boolean);
    }
  }
  return null;
}

/**
 * Sleep helper
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// Run migration
main().catch(error => {
  console.error('Migration failed:', error);
  process.exit(1);
});
