<?php
/**
 * Smart Linker Class v3.0 - Database-Backed Scalable Architecture
 * Claude-powered internal linking system with custom database table
 *
 * REVERSED LOGIC:
 * - Select a TARGET page/post you want links TO
 * - Claude finds all posts that should link TO your target
 * - Inserts links in those source posts pointing to your target
 *
 * Features:
 * - Custom MySQL database table for 10,000+ post scalability
 * - Enriched catalog with intent, difficulty, funnel stage, topic clusters
 * - FULLTEXT search for semantic matching
 * - Indexed queries for fast lookups
 * - Background queue processing for bulk operations
 * - Full link management and URL updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class LendCity_Smart_Linker {

    private $api_key;
    private $table_name;
    private $link_meta_key = '_lendcity_smart_links';
    private $original_content_meta = '_lendcity_original_content';
    private $queue_option = 'lendcity_smart_linker_queue';
    private $queue_status_option = 'lendcity_smart_linker_queue_status';
    private $meta_queue_option = 'lendcity_meta_queue';
    private $meta_queue_status_option = 'lendcity_meta_queue_status';
    private $keyword_ownership_option = 'lendcity_keyword_ownership';

    // v12.0 Background processing queues
    private $catalog_queue_option = 'lendcity_catalog_queue';
    private $catalog_queue_status_option = 'lendcity_catalog_queue_status';
    private $ownership_queue_option = 'lendcity_ownership_queue';
    private $ownership_queue_status_option = 'lendcity_ownership_queue_status';
    private $catalog_cache = null;
    private $links_cache = null; // In-memory cache for get_all_site_links

    // SEO Enhancement meta keys
    private $priority_meta_key = '_lendcity_link_priority';
    private $keywords_meta_key = '_lendcity_target_keywords';

    // Database version for migrations
    const DB_VERSION = '5.0';
    const DB_VERSION_OPTION = 'lendcity_catalog_db_version';

    // v5.0 Semantic Enhancement Options
    private $anchor_usage_option = 'lendcity_anchor_usage_stats';
    private $keyword_frequency_option = 'lendcity_keyword_frequency';
    private $synonym_map_option = 'lendcity_synonym_map';

    // Parallel processing settings
    private $parallel_batch_size = 5; // Posts per parallel request

    // Cached debug mode flag (avoids repeated get_option calls)
    private $debug_mode = null;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'lendcity_catalog';
        $this->api_key = get_option('lendcity_claude_api_key');

        // Cache debug mode setting (called 20+ times per request otherwise)
        $this->debug_mode = get_option('lendcity_debug_mode', 'no') === 'yes';

        // Check if database needs to be created/upgraded
        $this->maybe_create_table();

        // Hook into post publish to auto-catalog and auto-link
        add_action('transition_post_status', array($this, 'on_post_publish'), 10, 3);

        // Hook into post delete to remove from catalog and clean up links
        add_action('before_delete_post', array($this, 'on_post_delete'));
        add_action('trashed_post', array($this, 'on_post_trash'));

        // Background processing hooks
        add_action('lendcity_process_link_queue', array($this, 'process_queue_batch'));
        add_action('lendcity_process_queue_batch', array($this, 'process_queue_batch'));
        add_action('lendcity_auto_link_new_post', array($this, 'process_new_post_auto_link'));
        add_action('lendcity_process_meta_queue', array($this, 'process_meta_queue_batch'));

        // v12.0 Background queue processing hooks (runs without browser)
        add_action('lendcity_process_catalog_queue', array($this, 'process_catalog_queue_batch'));
        add_action('lendcity_process_ownership_queue', array($this, 'process_ownership_queue_batch'));

        // Loopback processing (non-cron)
        add_action('wp_ajax_lendcity_background_process', array($this, 'ajax_background_process'));
        add_action('wp_ajax_nopriv_lendcity_background_process', array($this, 'ajax_background_process'));
    }

    // =========================================================================
    // DATABASE TABLE MANAGEMENT
    // =========================================================================

    /**
     * Create or upgrade the catalog database table
     */
    public function maybe_create_table() {
        $installed_version = get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            $this->create_table();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Create the catalog table - uses direct SQL to avoid dbDelta parsing issues
     */
    private function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;

        if (!$table_exists) {
            $sql = "CREATE TABLE {$this->table_name} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL,
                post_type VARCHAR(20) NOT NULL DEFAULT 'post',
                is_page TINYINT(1) NOT NULL DEFAULT 0,
                title VARCHAR(255) NOT NULL DEFAULT '',
                url VARCHAR(500) NOT NULL DEFAULT '',
                summary TEXT,
                main_topics LONGTEXT,
                semantic_keywords LONGTEXT,
                entities LONGTEXT,
                content_themes LONGTEXT,
                good_anchor_phrases LONGTEXT,
                reader_intent VARCHAR(20) DEFAULT 'educational',
                difficulty_level VARCHAR(20) DEFAULT 'intermediate',
                funnel_stage VARCHAR(20) DEFAULT 'awareness',
                topic_cluster VARCHAR(100) DEFAULT NULL,
                related_clusters LONGTEXT,
                is_pillar_content TINYINT(1) NOT NULL DEFAULT 0,
                word_count INT UNSIGNED DEFAULT 0,
                content_quality_score TINYINT UNSIGNED DEFAULT 50,
                content_lifespan VARCHAR(20) DEFAULT 'evergreen',
                publish_season VARCHAR(30) DEFAULT NULL,
                target_regions LONGTEXT,
                target_cities LONGTEXT,
                target_persona VARCHAR(30) DEFAULT 'general',
                content_last_updated DATE DEFAULT NULL,
                freshness_score TINYINT UNSIGNED DEFAULT 100,
                inbound_link_count INT UNSIGNED DEFAULT 0,
                outbound_link_count INT UNSIGNED DEFAULT 0,
                link_gap_priority TINYINT UNSIGNED DEFAULT 50,
                has_cta TINYINT(1) DEFAULT 0,
                has_calculator TINYINT(1) DEFAULT 0,
                has_lead_form TINYINT(1) DEFAULT 0,
                monetization_value TINYINT UNSIGNED DEFAULT 5,
                content_format VARCHAR(30) DEFAULT 'other',
                must_link_to LONGTEXT,
                never_link_to LONGTEXT,
                preferred_anchors LONGTEXT,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idx_post_id (post_id),
                KEY idx_topic_cluster (topic_cluster),
                KEY idx_persona (target_persona)
            ) $charset_collate";

            $result = $wpdb->query($sql);

            if ($wpdb->last_error) {
                $this->log('Table creation error: ' . $wpdb->last_error);
            } else {
                $this->log('Created catalog database table v' . self::DB_VERSION);
            }
        } else {
            $this->maybe_upgrade_table();
        }
    }

    /**
     * Add missing columns to existing table (for v4 upgrades)
     */
    private function maybe_upgrade_table() {
        global $wpdb;

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}", 0);

        $v4_columns = array(
            'content_lifespan' => "VARCHAR(20) DEFAULT 'evergreen'",
            'publish_season' => "VARCHAR(30) DEFAULT NULL",
            'target_regions' => "LONGTEXT",
            'target_cities' => "LONGTEXT",
            'target_persona' => "VARCHAR(30) DEFAULT 'general'",
            'content_last_updated' => "DATE DEFAULT NULL",
            'freshness_score' => "TINYINT UNSIGNED DEFAULT 100",
            'inbound_link_count' => "INT UNSIGNED DEFAULT 0",
            'outbound_link_count' => "INT UNSIGNED DEFAULT 0",
            'link_gap_priority' => "TINYINT UNSIGNED DEFAULT 50",
            'has_cta' => "TINYINT(1) DEFAULT 0",
            'has_calculator' => "TINYINT(1) DEFAULT 0",
            'has_lead_form' => "TINYINT(1) DEFAULT 0",
            'monetization_value' => "TINYINT UNSIGNED DEFAULT 5",
            'content_format' => "VARCHAR(30) DEFAULT 'other'",
            'must_link_to' => "LONGTEXT",
            'never_link_to' => "LONGTEXT",
            'preferred_anchors' => "LONGTEXT",
            // v5.0 Semantic Enhancement columns
            'embedding_hash' => "VARCHAR(64) DEFAULT NULL",
            'primary_entities' => "LONGTEXT",
            'reciprocal_links' => "LONGTEXT"
        );

        $added = 0;
        foreach ($v4_columns as $col => $def) {
            if (!in_array($col, $columns)) {
                $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN {$col} {$def}");
                $added++;
            }
        }

        if ($added > 0) {
            $this->log("Added {$added} new v4 columns to catalog table");
        }
    }

    /**
     * Insert or update a catalog entry in the database
     */
    public function insert_catalog_entry($post_id, $entry) {
        global $wpdb;

        // Get post modified date for freshness
        $post = get_post($post_id);
        $last_modified = $post ? $post->post_modified : current_time('mysql');

        $data = array(
            // Core fields
            'post_id' => intval($post_id),
            'post_type' => isset($entry['type']) ? $entry['type'] : 'post',
            'is_page' => isset($entry['is_page']) ? (int)$entry['is_page'] : 0,
            'title' => isset($entry['title']) ? $entry['title'] : '',
            'url' => isset($entry['url']) ? $entry['url'] : '',
            'summary' => isset($entry['summary']) ? $entry['summary'] : '',
            'main_topics' => isset($entry['main_topics']) ? wp_json_encode($entry['main_topics']) : '[]',
            'semantic_keywords' => isset($entry['semantic_keywords']) ? wp_json_encode($entry['semantic_keywords']) : '[]',
            'entities' => isset($entry['entities']) ? wp_json_encode($entry['entities']) : '[]',
            'content_themes' => isset($entry['content_themes']) ? wp_json_encode($entry['content_themes']) : '[]',
            'good_anchor_phrases' => isset($entry['good_anchor_phrases']) ? wp_json_encode($entry['good_anchor_phrases']) : '[]',

            // v3.0 Intelligence fields
            'reader_intent' => isset($entry['reader_intent']) ? $entry['reader_intent'] : 'educational',
            'difficulty_level' => isset($entry['difficulty_level']) ? $entry['difficulty_level'] : 'intermediate',
            'funnel_stage' => isset($entry['funnel_stage']) ? $entry['funnel_stage'] : 'awareness',
            'topic_cluster' => isset($entry['topic_cluster']) ? $entry['topic_cluster'] : null,
            'related_clusters' => isset($entry['related_clusters']) ? wp_json_encode($entry['related_clusters']) : '[]',
            'is_pillar_content' => isset($entry['is_pillar_content']) ? (int)$entry['is_pillar_content'] : 0,
            'word_count' => isset($entry['word_count']) ? intval($entry['word_count']) : 0,
            'content_quality_score' => isset($entry['content_quality_score']) ? intval($entry['content_quality_score']) : 50,

            // v4.0 Seasonal/Evergreen
            'content_lifespan' => isset($entry['content_lifespan']) ? $entry['content_lifespan'] : 'evergreen',
            'publish_season' => isset($entry['publish_season']) ? $entry['publish_season'] : null,

            // v4.0 Geographic
            'target_regions' => isset($entry['target_regions']) ? wp_json_encode($entry['target_regions']) : '[]',
            'target_cities' => isset($entry['target_cities']) ? wp_json_encode($entry['target_cities']) : '[]',

            // v4.0 Persona
            'target_persona' => isset($entry['target_persona']) ? $entry['target_persona'] : 'general',

            // v4.0 Freshness
            'content_last_updated' => date('Y-m-d', strtotime($last_modified)),
            'freshness_score' => $this->calculate_freshness_score($last_modified),

            // v4.0 Link Velocity (calculated separately)
            'inbound_link_count' => isset($entry['inbound_link_count']) ? intval($entry['inbound_link_count']) : 0,
            'outbound_link_count' => isset($entry['outbound_link_count']) ? intval($entry['outbound_link_count']) : 0,
            'link_gap_priority' => isset($entry['link_gap_priority']) ? intval($entry['link_gap_priority']) : 50,

            // v4.0 Conversion Signals
            'has_cta' => isset($entry['has_cta']) ? (int)$entry['has_cta'] : 0,
            'has_calculator' => isset($entry['has_calculator']) ? (int)$entry['has_calculator'] : 0,
            'has_lead_form' => isset($entry['has_lead_form']) ? (int)$entry['has_lead_form'] : 0,
            'monetization_value' => isset($entry['monetization_value']) ? intval($entry['monetization_value']) : 5,

            // v4.0 Content Format
            'content_format' => isset($entry['content_format']) ? $entry['content_format'] : 'other',

            // v4.0 Admin Overrides
            'must_link_to' => isset($entry['must_link_to']) ? wp_json_encode($entry['must_link_to']) : '[]',
            'never_link_to' => isset($entry['never_link_to']) ? wp_json_encode($entry['never_link_to']) : '[]',
            'preferred_anchors' => isset($entry['preferred_anchors']) ? wp_json_encode($entry['preferred_anchors']) : '[]',

            'updated_at' => isset($entry['updated_at']) ? $entry['updated_at'] : current_time('mysql')
        );

        // Check if entry exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE post_id = %d",
            $post_id
        ));

        if ($existing) {
            $result = $wpdb->update($this->table_name, $data, array('post_id' => $post_id));
        } else {
            $result = $wpdb->insert($this->table_name, $data);
        }

        $this->clear_catalog_cache();
        return $result !== false;
    }

    /**
     * Calculate freshness score based on last modified date (0-100)
     */
    private function calculate_freshness_score($last_modified) {
        $days_old = (time() - strtotime($last_modified)) / 86400;

        if ($days_old < 30) return 100;
        if ($days_old < 90) return 85;
        if ($days_old < 180) return 70;
        if ($days_old < 365) return 50;
        if ($days_old < 730) return 30;
        return 10;
    }

    // =========================================================================
    // CATALOG QUERY METHODS (Fast Database Queries)
    // =========================================================================

    /**
     * Get catalog entry by post ID
     */
    public function get_catalog_entry($post_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE post_id = %d",
            $post_id
        ), ARRAY_A);

        if (!$row) return null;

        return $this->hydrate_catalog_entry($row);
    }

    /**
     * Get all catalog entries (with optional filters)
     */
    public function get_catalog($filters = array()) {
        global $wpdb;

        // Use cache if available and no filters specified (batch processing optimization)
        if (empty($filters) && $this->catalog_cache !== null) {
            return $this->catalog_cache;
        }

        // Build query with filters
        $where = array('1=1');
        $params = array();

        if (isset($filters['is_page'])) {
            $where[] = 'is_page = %d';
            $params[] = (int)$filters['is_page'];
        }

        if (isset($filters['topic_cluster'])) {
            $where[] = 'topic_cluster = %s';
            $params[] = $filters['topic_cluster'];
        }

        if (isset($filters['difficulty_level'])) {
            $where[] = 'difficulty_level = %s';
            $params[] = $filters['difficulty_level'];
        }

        if (isset($filters['funnel_stage'])) {
            $where[] = 'funnel_stage = %s';
            $params[] = $filters['funnel_stage'];
        }

        if (isset($filters['reader_intent'])) {
            $where[] = 'reader_intent = %s';
            $params[] = $filters['reader_intent'];
        }

        if (isset($filters['is_pillar_content'])) {
            $where[] = 'is_pillar_content = %d';
            $params[] = (int)$filters['is_pillar_content'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$this->table_name} WHERE $where_clause ORDER BY updated_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Convert to keyed array by post_id for backward compatibility
        $catalog = array();
        foreach ($rows as $row) {
            $catalog[$row['post_id']] = $this->hydrate_catalog_entry($row);
        }

        // Cache if no filters (for batch processing reuse)
        if (empty($filters)) {
            $this->catalog_cache = $catalog;
        }

        return $catalog;
    }

    /**
     * Pre-load catalog into memory cache (call before batch processing)
     */
    public function preload_catalog_cache() {
        $this->catalog_cache = null; // Clear existing cache
        return $this->get_catalog(); // This will populate the cache
    }

    /**
     * Get catalog entries by topic cluster (fast indexed query)
     */
    public function get_by_topic_cluster($cluster, $limit = 50) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE topic_cluster = %s
             ORDER BY is_pillar_content DESC, content_quality_score DESC
             LIMIT %d",
            $cluster, $limit
        ), ARRAY_A);

        $catalog = array();
        foreach ($rows as $row) {
            $catalog[$row['post_id']] = $this->hydrate_catalog_entry($row);
        }
        return $catalog;
    }

    /**
     * Get catalog entries by funnel stage (fast indexed query)
     */
    public function get_by_funnel_stage($stage, $limit = 50) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE funnel_stage = %s
             ORDER BY content_quality_score DESC
             LIMIT %d",
            $stage, $limit
        ), ARRAY_A);

        $catalog = array();
        foreach ($rows as $row) {
            $catalog[$row['post_id']] = $this->hydrate_catalog_entry($row);
        }
        return $catalog;
    }

    /**
     * Search catalog using FULLTEXT (fast semantic matching)
     */
    public function search_catalog($search_term, $limit = 20) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *, MATCH(summary) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
             FROM {$this->table_name}
             WHERE MATCH(summary) AGAINST(%s IN NATURAL LANGUAGE MODE)
             ORDER BY relevance DESC
             LIMIT %d",
            $search_term, $search_term, $limit
        ), ARRAY_A);

        $catalog = array();
        foreach ($rows as $row) {
            $catalog[$row['post_id']] = $this->hydrate_catalog_entry($row);
        }
        return $catalog;
    }

    /**
     * Get related content by matching clusters and topics
     */
    public function get_related_content($post_id, $limit = 10) {
        global $wpdb;

        $entry = $this->get_catalog_entry($post_id);
        if (!$entry) return array();

        $cluster = $entry['topic_cluster'];
        $funnel = $entry['funnel_stage'];

        // Get content from same cluster or adjacent funnel stages
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE post_id != %d
             AND (topic_cluster = %s OR funnel_stage = %s)
             ORDER BY
                CASE WHEN topic_cluster = %s THEN 0 ELSE 1 END,
                content_quality_score DESC
             LIMIT %d",
            $post_id, $cluster, $funnel, $cluster, $limit
        ), ARRAY_A);

        $catalog = array();
        foreach ($rows as $row) {
            $catalog[$row['post_id']] = $this->hydrate_catalog_entry($row);
        }
        return $catalog;
    }

    /**
     * Get all unique topic clusters
     */
    public function get_all_clusters() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT DISTINCT topic_cluster FROM {$this->table_name}
             WHERE topic_cluster IS NOT NULL AND topic_cluster != ''
             ORDER BY topic_cluster"
        );
    }

    /**
     * Get pillar content for a cluster
     */
    public function get_pillar_for_cluster($cluster) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE topic_cluster = %s AND is_pillar_content = 1
             LIMIT 1",
            $cluster
        ), ARRAY_A);

        return $row ? $this->hydrate_catalog_entry($row) : null;
    }

    /**
     * Hydrate a database row into a catalog entry array
     */
    private function hydrate_catalog_entry($row) {
        return array(
            // Core fields
            'post_id' => intval($row['post_id']),
            'type' => $row['post_type'],
            'is_page' => (bool)$row['is_page'],
            'title' => $row['title'],
            'url' => $row['url'],
            'summary' => $row['summary'],
            'main_topics' => json_decode($row['main_topics'], true) ?: array(),
            'semantic_keywords' => json_decode($row['semantic_keywords'], true) ?: array(),
            'entities' => json_decode($row['entities'], true) ?: array(),
            'content_themes' => json_decode($row['content_themes'], true) ?: array(),
            'good_anchor_phrases' => json_decode($row['good_anchor_phrases'], true) ?: array(),

            // v3.0 Intelligence
            'reader_intent' => $row['reader_intent'] ?? 'educational',
            'difficulty_level' => $row['difficulty_level'] ?? 'intermediate',
            'funnel_stage' => $row['funnel_stage'] ?? 'awareness',
            'topic_cluster' => $row['topic_cluster'] ?? null,
            'related_clusters' => json_decode($row['related_clusters'] ?? '[]', true) ?: array(),
            'is_pillar_content' => (bool)($row['is_pillar_content'] ?? 0),
            'word_count' => intval($row['word_count'] ?? 0),
            'content_quality_score' => intval($row['content_quality_score'] ?? 50),

            // v4.0 Seasonal/Evergreen
            'content_lifespan' => $row['content_lifespan'] ?? 'evergreen',
            'publish_season' => $row['publish_season'] ?? null,

            // v4.0 Geographic
            'target_regions' => json_decode($row['target_regions'] ?? '[]', true) ?: array(),
            'target_cities' => json_decode($row['target_cities'] ?? '[]', true) ?: array(),

            // v4.0 Persona
            'target_persona' => $row['target_persona'] ?? 'general',

            // v4.0 Freshness
            'content_last_updated' => $row['content_last_updated'] ?? null,
            'freshness_score' => intval($row['freshness_score'] ?? 100),

            // v4.0 Link Velocity
            'inbound_link_count' => intval($row['inbound_link_count'] ?? 0),
            'outbound_link_count' => intval($row['outbound_link_count'] ?? 0),
            'link_gap_priority' => intval($row['link_gap_priority'] ?? 50),

            // v4.0 Conversion
            'has_cta' => (bool)($row['has_cta'] ?? 0),
            'has_calculator' => (bool)($row['has_calculator'] ?? 0),
            'has_lead_form' => (bool)($row['has_lead_form'] ?? 0),
            'monetization_value' => intval($row['monetization_value'] ?? 5),

            // v4.0 Format
            'content_format' => $row['content_format'] ?? 'other',

            // v4.0 Admin Overrides
            'must_link_to' => json_decode($row['must_link_to'] ?? '[]', true) ?: array(),
            'never_link_to' => json_decode($row['never_link_to'] ?? '[]', true) ?: array(),
            'preferred_anchors' => json_decode($row['preferred_anchors'] ?? '[]', true) ?: array(),

            'updated_at' => $row['updated_at']
        );
    }

    /**
     * Get catalog stats (single optimized query with transient caching)
     */
    public function get_catalog_stats() {
        // Check transient cache first (5 minute cache)
        $cached = get_transient('lendcity_catalog_stats');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;

        // Single query with conditional counts (5 queries → 1)
        $row = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_page = 0 THEN 1 ELSE 0 END) as posts,
                SUM(CASE WHEN is_page = 1 THEN 1 ELSE 0 END) as pages,
                SUM(CASE WHEN is_pillar_content = 1 THEN 1 ELSE 0 END) as pillars,
                COUNT(DISTINCT CASE WHEN topic_cluster IS NOT NULL THEN topic_cluster END) as clusters
            FROM {$this->table_name}
        ");

        $stats = array(
            'total' => intval($row->total ?? 0),
            'posts' => intval($row->posts ?? 0),
            'pages' => intval($row->pages ?? 0),
            'pillars' => intval($row->pillars ?? 0),
            'clusters' => intval($row->clusters ?? 0)
        );

        // Cache for 5 minutes
        set_transient('lendcity_catalog_stats', $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * Get catalog count (very fast)
     */
    public function get_catalog_count() {
        global $wpdb;
        return intval($wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"));
    }

    /**
     * Delete catalog entry
     */
    public function delete_catalog_entry($post_id) {
        global $wpdb;
        $result = $wpdb->delete($this->table_name, array('post_id' => $post_id));
        $this->clear_catalog_cache();
        return $result !== false;
    }

    /**
     * Clear entire catalog
     */
    public function clear_catalog() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        $this->clear_catalog_cache();
        $this->clear_semantic_indexes(); // v5.0
        $this->log('Cleared entire catalog');
    }

    /**
     * Clear the in-memory catalog cache and transients
     */
    public function clear_catalog_cache() {
        $this->catalog_cache = null;
        delete_transient('lendcity_catalog_stats');
        delete_transient('lendcity_link_stats');
    }

    /**
     * v5.0: Clear all semantic enhancement indexes
     */
    public function clear_semantic_indexes() {
        delete_option($this->keyword_frequency_option);
        delete_option($this->synonym_map_option);
        delete_option($this->anchor_usage_option);
        $this->debug_log('Cleared v5.0 semantic indexes');
    }

    /**
     * v5.0: Build all semantic enhancement indexes
     * Call this after catalog rebuild for optimal linking
     */
    public function build_semantic_indexes() {
        $this->debug_log('Building v5.0 semantic indexes...');

        // Build TF-IDF keyword frequency index
        $this->build_keyword_frequency_index();

        // Build synonym map
        $this->build_synonym_map();

        $this->debug_log('Completed building v5.0 semantic indexes');

        return array(
            'success' => true,
            'message' => 'Built TF-IDF and synonym indexes'
        );
    }

    // =========================================================================
    // LOGGING HELPERS
    // =========================================================================

    /**
     * Debug logging - only logs if debug mode is enabled
     * Uses cached $this->debug_mode to avoid repeated get_option() calls
     */
    private function debug_log($message) {
        if ($this->debug_mode) {
            error_log('LendCity Smart Linker: ' . $message);
        }
    }

    private function log($message) {
        error_log('LendCity Smart Linker: ' . $message);
    }

    // =========================================================================
    // POST LIFECYCLE HOOKS
    // =========================================================================

    /**
     * Auto-catalog and auto-link when a post is published
     */
    public function on_post_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        if ($post->post_type !== 'post') {
            return;
        }

        if (get_option('lendcity_smart_linker_auto', 'yes') !== 'yes') {
            return;
        }

        if (empty($this->api_key)) {
            return;
        }

        $this->log('Scheduling auto-link for new post ' . $post->ID . ' - ' . $post->post_title);
        wp_schedule_single_event(time() + 60, 'lendcity_auto_link_new_post', array($post->ID));
    }

    /**
     * Handle post deletion
     */
    public function on_post_delete($post_id) {
        $this->cleanup_deleted_post($post_id);
    }

    /**
     * Handle post trash
     */
    public function on_post_trash($post_id) {
        $this->cleanup_deleted_post($post_id);
    }

    /**
     * Clean up when a post is deleted or trashed
     */
    private function cleanup_deleted_post($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        $this->debug_log('Cleaning up deleted post ' . $post_id . ' - ' . $post->post_title);

        // Remove from catalog database
        $this->delete_catalog_entry($post_id);

        // Get URL for link cleanup
        $deleted_url = get_permalink($post_id);
        if (!$deleted_url) {
            $deleted_url = home_url('/?p=' . $post_id);
        }

        // Remove all links pointing TO this post
        $links_removed = $this->remove_links_to_post($post_id, $deleted_url);

        if ($links_removed > 0) {
            $this->log('Removed ' . $links_removed . ' links pointing to deleted post ' . $post_id);
        }

        // Clean up link meta
        delete_post_meta($post_id, $this->link_meta_key);
        delete_post_meta($post_id, $this->original_content_meta);
    }

    /**
     * Remove all links pointing to a specific post
     */
    private function remove_links_to_post($target_post_id, $target_url) {
        global $wpdb;

        $links_removed = 0;

        $posts_with_links = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            WHERE pm.meta_key = %s",
            $this->link_meta_key
        ));

        foreach ($posts_with_links as $row) {
            $source_post_id = $row->post_id;
            $links = maybe_unserialize($row->meta_value);

            if (!is_array($links)) continue;

            $links_to_remove = array();
            foreach ($links as $index => $link) {
                if (isset($link['target_post_id']) && intval($link['target_post_id']) === intval($target_post_id)) {
                    $links_to_remove[] = $link;
                    unset($links[$index]);
                }
            }

            if (empty($links_to_remove)) continue;

            $post = get_post($source_post_id);
            if (!$post) continue;

            $content = $post->post_content;
            $original_content = $content;

            foreach ($links_to_remove as $link) {
                if (isset($link['link_id'])) {
                    $pattern = '/<a[^>]*data-link-id="' . preg_quote($link['link_id'], '/') . '"[^>]*>([^<]*)<\/a>/i';
                    $content = preg_replace($pattern, '$1', $content);
                }
            }

            if ($content !== $original_content) {
                wp_update_post(array('ID' => $source_post_id, 'post_content' => $content));
                $links_removed += count($links_to_remove);
            }

            $links = array_values($links);
            if (empty($links)) {
                delete_post_meta($source_post_id, $this->link_meta_key);
            } else {
                update_post_meta($source_post_id, $this->link_meta_key, $links);
            }
        }

        return $links_removed;
    }

    // =========================================================================
    // CATALOG BUILDING (AI-Powered with Enriched Metadata)
    // =========================================================================

    /**
     * Background task: Catalog and link a newly published post
     */
    public function process_new_post_auto_link($post_id) {
        $lock_key = 'lendcity_processing_' . $post_id;
        if (get_transient($lock_key)) {
            $this->debug_log('Post ' . $post_id . ' already being processed - skipping');
            return;
        }
        set_transient($lock_key, true, 300);

        clean_post_cache($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            delete_transient($lock_key);
            return;
        }

        $existing_links = get_post_meta($post_id, $this->link_meta_key, true);
        if (!empty($existing_links) && is_array($existing_links) && count($existing_links) > 0) {
            delete_transient($lock_key);
            return;
        }

        // Build catalog entry for this post
        $entry = $this->build_single_post_catalog($post_id);
        if ($entry) {
            $this->insert_catalog_entry($post_id, $entry);
            $this->debug_log('Added post ' . $post_id . ' to catalog');
        }

        // Create outgoing links using ownership map (faster, prevents duplicates)
        $result = $this->create_links_using_ownership($post_id, true); // Falls back to API if needed
        $this->log('Auto-link result for post ' . $post_id . ' - ' .
            ($result['success'] ? 'Success: ' . ($result['links_created'] ?? 0) . ' links' : $result['message']));

        // Auto-generate SEO metadata if enabled and post doesn't have it
        $auto_seo = get_option('lendcity_auto_seo_metadata', 'yes');
        if ($auto_seo === 'yes') {
            $existing_title = get_post_meta($post_id, '_seopress_titles_title', true);
            $existing_desc = get_post_meta($post_id, '_seopress_titles_desc', true);

            if (empty($existing_title) || empty($existing_desc)) {
                $this->debug_log('Generating SEO metadata for new post ' . $post_id);
                $meta_result = $this->generate_smart_metadata($post_id);

                if (!is_wp_error($meta_result)) {
                    if (!empty($meta_result['title'])) {
                        update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($meta_result['title']));
                    }
                    if (!empty($meta_result['description'])) {
                        update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($meta_result['description']));
                    }
                    if (!empty($meta_result['focus_keyphrase'])) {
                        update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($meta_result['focus_keyphrase']));
                    }
                    $this->log('Auto SEO metadata generated for post ' . $post_id);
                } else {
                    $this->log('Failed to generate SEO metadata for post ' . $post_id . ': ' . $meta_result->get_error_message());
                }
            }
        }

        delete_transient($lock_key);
    }

    /**
     * v12.0: Build and save a single catalog entry (for background processing)
     * Returns success/failure for queue processing
     */
    public function build_single_catalog_entry($post_id) {
        $entry = $this->build_single_post_catalog($post_id);

        if ($entry && is_array($entry) && isset($entry['post_id'])) {
            $this->insert_catalog_entry($post_id, $entry);
            return array('success' => true, 'entry' => $entry);
        }

        return array('success' => false, 'message' => 'Failed to build catalog entry');
    }

    /**
     * Build catalog for a single post or page with ENRICHED metadata
     */
    public function build_single_post_catalog($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }

        // Get content
        $content = wp_strip_all_tags($post->post_content);

        if (strlen($content) < 100) {
            $rendered = apply_filters('the_content', $post->post_content);
            $content = wp_strip_all_tags($rendered);
        }

        if (strlen($content) < 50) {
            $content = "Page title: " . $post->post_title . ". URL slug: " . $post->post_name;
        }

        $word_count = str_word_count($content);

        // Truncate for API
        if (strlen($content) > 12000) {
            $content = substr($content, 0, 12000) . '...';
        }

        $is_page = ($post->post_type === 'page');

        // Enhanced prompt for richer catalog data
        $prompt = $this->build_catalog_prompt($post, $content, $is_page);

        $response = $this->call_claude_api($prompt, 1200);

        if (!$response['success']) {
            $this->log('API failed for post ' . $post_id . ' - ' . ($response['error'] ?? 'unknown'));
            return false;
        }

        $data = json_decode($response['text'], true);
        if (!$data && preg_match('/\{.*\}/s', $response['text'], $matches)) {
            $data = json_decode($matches[0], true);
        }

        if (!$data) {
            $this->log('Failed to parse catalog for post ' . $post_id);
            return false;
        }

        $this->log('Successfully cataloged ' . $post->post_type . ' ' . $post_id . ' - ' . $post->post_title);

        return array(
            // Core fields
            'post_id' => $post_id,
            'type' => $post->post_type,
            'is_page' => $is_page,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'summary' => $data['summary'] ?? '',
            'main_topics' => $data['main_topics'] ?? array(),
            'semantic_keywords' => $data['semantic_keywords'] ?? array(),
            'entities' => $data['entities'] ?? array(),
            'content_themes' => $data['content_themes'] ?? array(),
            'good_anchor_phrases' => $data['good_anchor_phrases'] ?? array(),

            // v3.0 Intelligence
            'reader_intent' => $data['reader_intent'] ?? 'educational',
            'difficulty_level' => $data['difficulty_level'] ?? 'intermediate',
            'funnel_stage' => $data['funnel_stage'] ?? 'awareness',
            'topic_cluster' => $data['topic_cluster'] ?? null,
            'related_clusters' => $data['related_clusters'] ?? array(),
            'is_pillar_content' => (bool)($data['is_pillar_content'] ?? false),
            'word_count' => $word_count,
            'content_quality_score' => intval($data['content_quality_score'] ?? 50),

            // v4.0 Seasonal/Evergreen
            'content_lifespan' => $data['content_lifespan'] ?? 'evergreen',
            'publish_season' => $data['publish_season'] ?? null,

            // v4.0 Geographic
            'target_regions' => $data['target_regions'] ?? array(),
            'target_cities' => $data['target_cities'] ?? array(),

            // v4.0 Persona
            'target_persona' => $data['target_persona'] ?? 'general',

            // v4.0 Conversion signals
            'has_cta' => (bool)($data['has_cta'] ?? false),
            'has_calculator' => (bool)($data['has_calculator'] ?? false),
            'has_lead_form' => (bool)($data['has_lead_form'] ?? false),
            'monetization_value' => intval($data['monetization_value'] ?? 5),

            // v4.0 Content format
            'content_format' => $data['content_format'] ?? 'other',

            'updated_at' => current_time('mysql')
        );
    }

    /**
     * Build the enhanced catalog prompt for Claude
     */
    private function build_catalog_prompt($post, $content, $is_page) {
        $prompt = "Analyze this " . ($is_page ? "SERVICE PAGE" : "blog post") . " for a Canadian mortgage/real estate investing website (LendCity). Extract COMPREHENSIVE metadata for intelligent internal linking.\n\n";
        $prompt .= "TITLE: " . $post->post_title . "\n";
        $prompt .= "URL: " . get_permalink($post->ID) . "\n";
        $prompt .= "PUBLISHED: " . $post->post_date . "\n\n";
        $prompt .= "CONTENT:\n" . $content . "\n\n";

        if ($is_page) {
            $prompt .= "NOTE: This is a HIGH-VALUE SERVICE PAGE. Analyze based on title and URL if content is minimal.\n\n";
        }

        $prompt .= "Respond with ONLY a JSON object containing ALL fields:\n";
        $prompt .= "{\n";

        // Core content analysis
        $prompt .= '  "summary": "5-6 sentence comprehensive summary covering main points, key advice, unique insights, and target audience",' . "\n";
        $prompt .= '  "main_topics": ["8-10 specific topics covered"],' . "\n";
        $prompt .= '  "semantic_keywords": ["12-15 related terms, synonyms, long-tail search phrases"],' . "\n";
        $prompt .= '  "entities": ["names, cities, provinces, products, programs, companies, regulations mentioned"],' . "\n";
        $prompt .= '  "content_themes": ["broader themes: investment, financing, Canadian real estate, etc"],' . "\n";
        $prompt .= '  "good_anchor_phrases": ["10-12 natural 2-5 word phrases for linking TO this content"],' . "\n";

        // v3.0 Intelligence
        $prompt .= '  "reader_intent": "educational|transactional|navigational",' . "\n";
        $prompt .= '  "difficulty_level": "beginner|intermediate|advanced",' . "\n";
        $prompt .= '  "funnel_stage": "awareness|consideration|decision",' . "\n";
        $prompt .= '  "topic_cluster": "main-topic-slug",' . "\n";
        $prompt .= '  "related_clusters": ["other relevant topic clusters"],' . "\n";
        $prompt .= '  "is_pillar_content": true/false,' . "\n";
        $prompt .= '  "content_quality_score": 1-100,' . "\n";

        // v4.0 NEW: Seasonal/Evergreen
        $prompt .= '  "content_lifespan": "evergreen|seasonal|time-sensitive|dated",' . "\n";
        $prompt .= '  "publish_season": "spring-market|tax-season|year-end|rate-change|null",' . "\n";

        // v4.0 NEW: Geographic
        $prompt .= '  "target_regions": ["Ontario", "BC", "Alberta", "National", etc],' . "\n";
        $prompt .= '  "target_cities": ["Toronto", "Vancouver", "Calgary", etc or empty if national],' . "\n";

        // v4.0 NEW: Audience Persona
        $prompt .= '  "target_persona": "first-time-buyer|investor|realtor|refinancer|self-employed|general",' . "\n";

        // v4.0 NEW: Conversion signals
        $prompt .= '  "has_cta": true/false (has clear call-to-action?),' . "\n";
        $prompt .= '  "has_calculator": true/false (has mortgage/investment calculator?),' . "\n";
        $prompt .= '  "has_lead_form": true/false (has contact form or lead capture?),' . "\n";
        $prompt .= '  "monetization_value": 1-10 (business value: 10=service page, 1=general info),' . "\n";

        // v4.0 NEW: Content format
        $prompt .= '  "content_format": "guide|how-to|list|case-study|news|faq|comparison|calculator|landing-page|other"' . "\n";

        $prompt .= "}\n\n";

        $prompt .= "=== GUIDELINES ===\n";
        $prompt .= "TOPIC CLUSTERS (use consistent slugs):\n";
        $prompt .= "- brrrr-strategy, rental-investing, mortgage-types, first-time-buyers\n";
        $prompt .= "- refinancing, investment-properties, market-analysis, tax-strategies\n";
        $prompt .= "- property-management, credit-repair, self-employed-mortgages, pre-approval\n";
        $prompt .= "- down-payment, closing-costs, real-estate-agents, home-buying-process\n\n";

        $prompt .= "PERSONA HINTS:\n";
        $prompt .= "- first-time-buyer: FTHB, first home, saving for down payment, pre-approval\n";
        $prompt .= "- investor: BRRRR, rental, ROI, cash flow, portfolio, multi-family\n";
        $prompt .= "- realtor: agent tips, client advice, market insights for agents\n";
        $prompt .= "- refinancer: refinance, equity, HELOC, debt consolidation\n";
        $prompt .= "- self-employed: business owners, stated income, tax returns, BFS\n\n";

        $prompt .= "LIFESPAN:\n";
        $prompt .= "- evergreen: timeless advice (how to qualify, what is BRRRR)\n";
        $prompt .= "- seasonal: spring market, tax season, year-end planning\n";
        $prompt .= "- time-sensitive: rate announcements, policy changes (good for 1-2 years)\n";
        $prompt .= "- dated: mentions specific years/rates that will become stale\n";

        return $prompt;
    }

    /**
     * Build catalog for multiple posts in a SINGLE API call (batch mode)
     */
    public function build_batch_catalog($post_ids) {
        $posts_data = array();

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') continue;

            $content = wp_strip_all_tags($post->post_content);

            if (strlen($content) < 100) {
                $rendered = apply_filters('the_content', $post->post_content);
                $content = wp_strip_all_tags($rendered);
            }

            if (strlen($content) < 50) {
                $content = "Page title: " . $post->post_title . ". URL slug: " . $post->post_name;
            }

            if (strlen($content) > 8000) {
                $content = substr($content, 0, 8000) . '...';
            }

            $posts_data[] = array(
                'id' => $post_id,
                'title' => $post->post_title,
                'url' => get_permalink($post_id),
                'type' => $post->post_type,
                'is_page' => ($post->post_type === 'page'),
                'content' => $content,
                'word_count' => str_word_count($content)
            );
        }

        if (empty($posts_data)) return array();

        // Build batch prompt
        $prompt = "Analyze these " . count($posts_data) . " posts/pages for a Canadian mortgage/real estate website. Extract comprehensive metadata for internal linking.\n\n";

        foreach ($posts_data as $index => $pdata) {
            $prompt .= "=== ARTICLE " . ($index + 1) . " (ID: " . $pdata['id'] . ") ===\n";
            $prompt .= "Type: " . ($pdata['is_page'] ? "SERVICE PAGE" : "Blog Post") . "\n";
            $prompt .= "Title: " . $pdata['title'] . "\n";
            $prompt .= "Content:\n" . $pdata['content'] . "\n\n";
        }

        $prompt .= "For EACH article, respond with a JSON array. Each object must have ALL these fields:\n";
        $prompt .= "[\n  {\n";
        $prompt .= '    "id": POST_ID_NUMBER,' . "\n";
        $prompt .= '    "summary": "5-6 sentence summary",' . "\n";
        $prompt .= '    "main_topics": ["8-10 topics"],' . "\n";
        $prompt .= '    "semantic_keywords": ["12-15 keywords"],' . "\n";
        $prompt .= '    "entities": ["names, places, programs"],' . "\n";
        $prompt .= '    "content_themes": ["broader themes"],' . "\n";
        $prompt .= '    "good_anchor_phrases": ["10-12 link phrases"],' . "\n";
        $prompt .= '    "reader_intent": "educational|transactional|navigational",' . "\n";
        $prompt .= '    "difficulty_level": "beginner|intermediate|advanced",' . "\n";
        $prompt .= '    "funnel_stage": "awareness|consideration|decision",' . "\n";
        $prompt .= '    "topic_cluster": "main-topic-slug",' . "\n";
        $prompt .= '    "related_clusters": ["related clusters"],' . "\n";
        $prompt .= '    "is_pillar_content": true/false,' . "\n";
        $prompt .= '    "content_quality_score": 1-100' . "\n";
        $prompt .= "  }\n]\n";

        $response = $this->call_claude_api($prompt, 5000);

        if (!$response['success']) {
            // Fallback to individual processing
            $entries = array();
            foreach ($post_ids as $post_id) {
                $entry = $this->build_single_post_catalog($post_id);
                if ($entry) {
                    $this->insert_catalog_entry($post_id, $entry);
                    $entries[$post_id] = $entry;
                }
            }
            return $entries;
        }

        $results = json_decode($response['text'], true);
        if (!$results && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $results = json_decode($matches[0], true);
        }

        if (!is_array($results)) {
            // Fallback
            $entries = array();
            foreach ($post_ids as $post_id) {
                $entry = $this->build_single_post_catalog($post_id);
                if ($entry) {
                    $this->insert_catalog_entry($post_id, $entry);
                    $entries[$post_id] = $entry;
                }
            }
            return $entries;
        }

        // Build and save catalog entries
        $entries = array();
        foreach ($results as $data) {
            $post_id = isset($data['id']) ? intval($data['id']) : 0;
            if (!$post_id) continue;

            $pdata = null;
            foreach ($posts_data as $pd) {
                if ($pd['id'] == $post_id) {
                    $pdata = $pd;
                    break;
                }
            }
            if (!$pdata) continue;

            $entry = array(
                'post_id' => $post_id,
                'type' => $pdata['type'],
                'is_page' => $pdata['is_page'],
                'title' => $pdata['title'],
                'url' => $pdata['url'],
                'summary' => isset($data['summary']) ? $data['summary'] : '',
                'main_topics' => isset($data['main_topics']) ? $data['main_topics'] : array(),
                'semantic_keywords' => isset($data['semantic_keywords']) ? $data['semantic_keywords'] : array(),
                'entities' => isset($data['entities']) ? $data['entities'] : array(),
                'content_themes' => isset($data['content_themes']) ? $data['content_themes'] : array(),
                'good_anchor_phrases' => isset($data['good_anchor_phrases']) ? $data['good_anchor_phrases'] : array(),
                'reader_intent' => isset($data['reader_intent']) ? $data['reader_intent'] : 'educational',
                'difficulty_level' => isset($data['difficulty_level']) ? $data['difficulty_level'] : 'intermediate',
                'funnel_stage' => isset($data['funnel_stage']) ? $data['funnel_stage'] : 'awareness',
                'topic_cluster' => isset($data['topic_cluster']) ? $data['topic_cluster'] : null,
                'related_clusters' => isset($data['related_clusters']) ? $data['related_clusters'] : array(),
                'is_pillar_content' => isset($data['is_pillar_content']) ? (bool)$data['is_pillar_content'] : false,
                'word_count' => $pdata['word_count'],
                'content_quality_score' => isset($data['content_quality_score']) ? intval($data['content_quality_score']) : 50,
                'updated_at' => current_time('mysql')
            );

            $this->insert_catalog_entry($post_id, $entry);
            $entries[$post_id] = $entry;
        }

        $this->log('Batch cataloged ' . count($entries) . ' of ' . count($post_ids) . ' posts');
        return $entries;
    }

    // =========================================================================
    // INTELLIGENT LINKING (Uses Enriched Catalog Data)
    // =========================================================================

    /**
     * Create outgoing links using the Global Keyword Ownership map
     * This is the preferred method - fast (no API calls) and prevents duplicates by design
     *
     * @param int $source_id The source post ID
     * @param bool $fallback_to_api If true, falls back to Claude API if no owned keywords found
     * @return array Result with links created
     */
    public function create_links_using_ownership($source_id, $fallback_to_api = true) {
        $source = get_post($source_id);
        if (!$source || $source->post_status !== 'publish') {
            return array('success' => false, 'message' => 'Source post not found');
        }

        // Check if ownership map exists
        $ownership_stats = $this->get_keyword_ownership_stats();
        if (!$ownership_stats['has_map']) {
            $this->debug_log("No ownership map - building first...");
            $this->build_keyword_ownership_map();
        }

        // Get source content
        $source_content = wp_strip_all_tags($source->post_content);

        // Get existing links to avoid duplicates
        $existing_links = get_post_meta($source_id, $this->link_meta_key, true) ?: array();
        $used_anchors = array();
        $linked_urls = array();

        foreach ($existing_links as $link) {
            $used_anchors[] = strtolower($link['anchor']);
            $linked_urls[] = $link['url'];
        }

        // Dynamic link limits based on word count
        $word_count = str_word_count($source_content);
        if ($word_count < 800) {
            $max_links = 4;
        } elseif ($word_count < 1500) {
            $max_links = 7;
        } else {
            $max_links = 10;
        }

        $available_slots = $max_links - count($existing_links);
        if ($available_slots <= 0) {
            return array('success' => false, 'message' => 'Max links reached');
        }

        // Find owned keywords in the source content
        $matches = $this->find_owned_keywords_in_content($source_content, $source_id, $available_slots + 5);

        if (empty($matches) && $fallback_to_api) {
            $this->debug_log("No owned keywords found in post $source_id - falling back to API");
            return $this->create_links_from_source($source_id);
        }

        if (empty($matches)) {
            return array('success' => true, 'message' => 'No owned keywords found', 'links_created' => 0);
        }

        // Insert links for matched keywords
        $links_created = array();
        $errors = array();

        foreach ($matches as $match) {
            if (count($links_created) >= $available_slots) break;

            // Use matched_text (actual text found) if available, otherwise use canonical anchor
            $anchor = isset($match['matched_text']) ? $match['matched_text'] : $match['anchor'];
            $anchor_lower = strtolower($anchor);

            // Skip if anchor already used
            if (in_array($anchor_lower, $used_anchors)) {
                continue;
            }

            // Skip if URL already linked
            if (in_array($match['target_url'], $linked_urls)) {
                continue;
            }

            // Get target info
            $target_entry = $this->get_catalog_entry($match['target_post_id']);
            $is_page = $target_entry ? $target_entry['is_page'] : false;

            $result = $this->insert_link_in_post(
                $source_id,
                $anchor,
                $match['target_url'],
                $match['target_post_id'],
                $is_page
            );

            if ($result['success']) {
                $links_created[] = array(
                    'target_id' => $match['target_post_id'],
                    'anchor' => $anchor,
                    'url' => $match['target_url']
                );
                $used_anchors[] = $anchor_lower;
                $linked_urls[] = $match['target_url'];
                $this->debug_log("Created ownership-based link: '$anchor' -> {$match['target_url']}");
            } else {
                $errors[] = $result['message'];
            }
        }

        return array(
            'success' => true,
            'message' => 'Created ' . count($links_created) . ' links using ownership map',
            'links_created' => count($links_created),
            'links' => $links_created,
            'errors' => $errors
        );
    }

    /**
     * Create outgoing links FROM a source post using intelligent matching
     */
    public function create_links_from_source($source_id) {
        $source = get_post($source_id);
        if (!$source || $source->post_status !== 'publish') {
            return array('success' => false, 'message' => 'Source post not found');
        }

        $source_entry = $this->get_catalog_entry($source_id);
        if (!$source_entry) {
            return array('success' => false, 'message' => 'Source not in catalog');
        }

        // Dynamic link limits based on word count
        $word_count = isset($source_entry['word_count']) ? intval($source_entry['word_count']) : 0;
        if ($word_count == 0) {
            // Fallback: estimate from content
            $word_count = str_word_count(wp_strip_all_tags($source->post_content));
        }

        // Set limits based on article length
        if ($word_count < 800) {
            $max_total = 4;
            $max_pages = 2;
            $max_posts = 2;
        } elseif ($word_count < 1500) {
            $max_total = 7;
            $max_pages = 3;
            $max_posts = 4;
        } else {
            $max_total = 10;
            $max_pages = 3;
            $max_posts = 7;
        }

        // Get existing links
        $existing_links = get_post_meta($source_id, $this->link_meta_key, true) ?: array();
        $current_link_count = count($existing_links);

        if ($current_link_count >= $max_total) {
            return array('success' => false, 'message' => 'Source already has ' . $max_total . ' links (max for ' . $word_count . ' words)');
        }

        // Get used anchors and linked URLs
        $existing_page_links = 0;
        $used_anchors = array();
        $linked_urls = array();

        foreach ($existing_links as $link) {
            $used_anchors[] = strtolower($link['anchor']);
            $linked_urls[] = $link['url'];
            if (!empty($link['is_page'])) $existing_page_links++;
        }

        $page_slots = max(0, $max_pages - $existing_page_links);
        $post_slots = max(0, $max_posts - ($current_link_count - $existing_page_links));

        if ($page_slots == 0 && $post_slots <= 0) {
            return array('success' => false, 'message' => 'No link slots available');
        }

        // INTELLIGENT MATCHING: Get related content from same/related clusters
        $catalog = $this->get_catalog();

        // Filter and score potential targets
        $available_pages = array();
        $available_posts = array();

        foreach ($catalog as $id => $entry) {
            if ($id == $source_id) continue;
            if (in_array($entry['url'], $linked_urls)) continue;

            // Calculate relevance score
            $score = $this->calculate_relevance_score($source_entry, $entry);
            $entry['relevance_score'] = $score;

            if ($entry['is_page']) {
                $available_pages[$id] = $entry;
            } else {
                $available_posts[$id] = $entry;
            }
        }

        // Sort by relevance score
        uasort($available_pages, function($a, $b) {
            $prio_a = $this->get_page_priority($a['post_id']);
            $prio_b = $this->get_page_priority($b['post_id']);
            if ($prio_a !== $prio_b) return $prio_b - $prio_a;
            return ($b['relevance_score'] ?? 0) - ($a['relevance_score'] ?? 0);
        });

        uasort($available_posts, function($a, $b) {
            return ($b['relevance_score'] ?? 0) - ($a['relevance_score'] ?? 0);
        });

        // Get source content
        $source_content = wp_strip_all_tags($source->post_content);
        if (strlen($source_content) > 10000) {
            $source_content = substr($source_content, 0, 10000) . '...';
        }

        // Build prompt with enriched context
        $prompt = $this->build_linking_prompt($source, $source_entry, $source_content,
            $available_pages, $available_posts, $used_anchors, $page_slots, $post_slots);

        $response = $this->call_claude_api($prompt, 1500);

        if (!$response['success']) {
            return array('success' => false, 'message' => 'API error: ' . $response['error']);
        }

        $suggestions = json_decode($response['text'], true);
        if (!$suggestions && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $suggestions = json_decode($matches[0], true);
        }

        if (!is_array($suggestions) || empty($suggestions)) {
            return array('success' => true, 'message' => 'No relevant opportunities', 'links_created' => 0);
        }

        // Insert links
        $links_created = array();
        $errors = array();
        $page_links_added = $existing_page_links;
        $post_links_added = $current_link_count - $existing_page_links;
        $anchors_used = array();

        foreach ($suggestions as $suggestion) {
            $target_id = intval($suggestion['target_id']);
            $anchor = sanitize_text_field($suggestion['anchor_text']);
            $is_page = !empty($suggestion['is_page']);

            if (!$target_id || !$anchor) continue;

            $anchor_lower = strtolower(trim($anchor));
            if (in_array($anchor_lower, $used_anchors) || in_array($anchor_lower, $anchors_used)) {
                continue;
            }

            if ($is_page && $page_links_added >= $max_pages) continue;
            if (!$is_page && $post_links_added >= $max_posts) continue;
            if (count($links_created) + $current_link_count >= $max_total) break;

            $target_entry = $this->get_catalog_entry($target_id);
            if (!$target_entry) continue;

            $result = $this->insert_link_in_post($source_id, $anchor, $target_entry['url'], $target_id, $is_page);

            if ($result['success']) {
                $links_created[] = array(
                    'target_id' => $target_id,
                    'target_title' => $target_entry['title'],
                    'anchor' => $anchor,
                    'is_page' => $is_page
                );
                $anchors_used[] = $anchor_lower;
                if ($is_page) $page_links_added++;
                else $post_links_added++;
            } else {
                $errors[] = $result['message'];
            }
        }

        return array(
            'success' => true,
            'links_created' => count($links_created),
            'links' => $links_created,
            'errors' => $errors
        );
    }

    /**
     * Calculate relevance score between source and target using v5.0 semantic intelligence
     * Higher score = better link match
     */
    private function calculate_relevance_score($source, $target) {
        $score = 0;

        // =================================================================
        // TOPIC & CLUSTER MATCHING (0-50 points)
        // =================================================================

        // Same topic cluster = high relevance
        if ($source['topic_cluster'] && $source['topic_cluster'] === $target['topic_cluster']) {
            $score += 30;
        }

        // Related clusters
        if (!empty($source['related_clusters']) && in_array($target['topic_cluster'], $source['related_clusters'])) {
            $score += 20;
        }

        // Topic overlap
        $source_topics = $source['main_topics'] ?? array();
        $target_topics = $target['main_topics'] ?? array();
        $overlap = count(array_intersect($source_topics, $target_topics));
        $score += min($overlap * 5, 25);

        // =================================================================
        // v5.0 TF-IDF WEIGHTED KEYWORD MATCHING (0-30 points)
        // =================================================================

        $source_keywords = $source['semantic_keywords'] ?? array();
        $target_keywords = $target['semantic_keywords'] ?? array();

        // TF-IDF weighted scoring (rare keywords worth more)
        $score += $this->get_tfidf_keyword_score($source_keywords, $target_keywords);

        // Synonym-aware matching (additional points)
        $score += $this->get_synonym_keyword_score($source_keywords, $target_keywords);

        // =================================================================
        // v5.0 ENTITY-TO-CLUSTER MATCHING (0-45 points)
        // =================================================================

        $source_entities = $source['entities'] ?? array();
        $target_entities = $target['entities'] ?? array();
        $target_cluster = $target['topic_cluster'] ?? '';

        $score += $this->get_entity_cluster_score($source_entities, $target_cluster, $target_entities);

        // =================================================================
        // FUNNEL PROGRESSION (0-25 points)
        // =================================================================

        $funnel_order = array('awareness' => 1, 'consideration' => 2, 'decision' => 3);
        $source_funnel = $funnel_order[$source['funnel_stage']] ?? 2;
        $target_funnel = $funnel_order[$target['funnel_stage']] ?? 2;

        // Same stage or progressive flow (awareness→consideration→decision)
        if ($target_funnel === $source_funnel) {
            $score += 15; // Same stage - reinforcing content
        } elseif ($target_funnel === $source_funnel + 1) {
            $score += 25; // Next stage - advancing the reader!
        } elseif (abs($source_funnel - $target_funnel) === 1) {
            $score += 10; // Adjacent stage
        }

        // =================================================================
        // DIFFICULTY PROGRESSION (0-15 points)
        // =================================================================

        $diff_order = array('beginner' => 1, 'intermediate' => 2, 'advanced' => 3);
        $source_diff = $diff_order[$source['difficulty_level']] ?? 2;
        $target_diff = $diff_order[$target['difficulty_level']] ?? 2;

        if ($target_diff === $source_diff) {
            $score += 10; // Same level
        } elseif ($target_diff === $source_diff + 1) {
            $score += 15; // Next level up - good progression
        } elseif ($target_diff === $source_diff - 1) {
            $score += 5; // Simpler content (for clarification)
        }

        // =================================================================
        // PERSONA MATCHING (0-30 points) + v5.0 CONFLICT DETECTION
        // =================================================================

        $source_persona = $source['target_persona'] ?? 'general';
        $target_persona = $target['target_persona'] ?? 'general';

        if ($source_persona === $target_persona && $source_persona !== 'general') {
            $score += 30; // Same specific persona - very relevant!
        } elseif ($source_persona === 'general' || $target_persona === 'general') {
            $score += 10; // General content matches with anything
        }

        // v5.0: Check for persona conflicts (negative matching)
        $score += $this->get_persona_conflict_penalty($source_persona, $target_persona);

        // =================================================================
        // GEOGRAPHIC MATCHING (0-20 points) - NEW v4.0
        // =================================================================

        $source_regions = $source['target_regions'] ?? array();
        $target_regions = $target['target_regions'] ?? array();

        if (!empty($source_regions) && !empty($target_regions)) {
            if (in_array('National', $source_regions) || in_array('National', $target_regions)) {
                $score += 10; // National content is broadly relevant
            }
            $region_overlap = count(array_intersect($source_regions, $target_regions));
            if ($region_overlap > 0) {
                $score += min($region_overlap * 10, 20); // Same regions
            }
        } elseif (empty($source_regions) || empty($target_regions)) {
            $score += 5; // One is unspecified, assume compatible
        }

        // =================================================================
        // CONTENT LIFESPAN MATCHING (0-15 points) - NEW v4.0
        // =================================================================

        $source_lifespan = $source['content_lifespan'] ?? 'evergreen';
        $target_lifespan = $target['content_lifespan'] ?? 'evergreen';

        // Prefer linking evergreen to evergreen
        if ($source_lifespan === 'evergreen' && $target_lifespan === 'evergreen') {
            $score += 15;
        } elseif ($source_lifespan === 'evergreen' && $target_lifespan === 'dated') {
            $score -= 10; // Penalty: don't link evergreen to dated content
        } elseif ($target_lifespan === 'evergreen') {
            $score += 10; // Always good to link TO evergreen content
        }

        // =================================================================
        // QUALITY & VALUE SIGNALS (0-30 points)
        // =================================================================

        // Pillar content gets bonus
        if (!empty($target['is_pillar_content'])) {
            $score += 20;
        }

        // Quality score (0-10 points)
        $score += ($target['content_quality_score'] ?? 50) / 10;

        // Freshness bonus (0-10 points) - NEW v4.0
        $score += ($target['freshness_score'] ?? 50) / 10;

        // Monetization value (0-10 points) - NEW v4.0
        // Prioritize linking to money pages
        $score += ($target['monetization_value'] ?? 5);

        // =================================================================
        // CONVERSION SIGNALS (0-15 points) - NEW v4.0
        // =================================================================

        // Bonus for linking to pages with CTAs/forms (conversion-focused)
        if (!empty($target['has_cta'])) $score += 5;
        if (!empty($target['has_calculator'])) $score += 5;
        if (!empty($target['has_lead_form'])) $score += 5;

        // =================================================================
        // LINK GAP PRIORITY (0-15 points) - NEW v4.0
        // =================================================================

        // Prioritize content that needs links (orphaned content)
        $gap = $target['link_gap_priority'] ?? 50;
        $score += ($gap / 100) * 15;

        // =================================================================
        // v5.0 DEEP PAGE PRIORITY (0-25 points)
        // =================================================================

        // Prioritize linking to orphaned/low-inbound-link pages
        $score += $this->get_deep_page_bonus($target);

        // =================================================================
        // CONTENT FORMAT MATCHING (0-10 points) - NEW v4.0
        // =================================================================

        $format_flow = array(
            'how-to' => array('guide', 'calculator', 'comparison'),
            'guide' => array('how-to', 'case-study', 'faq'),
            'list' => array('guide', 'comparison', 'how-to'),
            'case-study' => array('guide', 'how-to', 'landing-page'),
            'faq' => array('guide', 'how-to', 'landing-page'),
            'comparison' => array('calculator', 'landing-page', 'guide'),
        );

        $source_format = $source['content_format'] ?? 'other';
        $target_format = $target['content_format'] ?? 'other';

        if (isset($format_flow[$source_format]) && in_array($target_format, $format_flow[$source_format])) {
            $score += 10; // Natural content format progression
        }

        return max(0, $score); // Never negative
    }

    // =========================================================================
    // v5.0 SEMANTIC ENHANCEMENT METHODS
    // =========================================================================

    /**
     * Get TF-IDF weighted keyword score
     * Rare keywords across the catalog are more valuable for matching
     */
    private function get_tfidf_keyword_score($source_keywords, $target_keywords) {
        $frequency = get_option($this->keyword_frequency_option, array());
        $total_docs = max(1, count($this->get_catalog()));
        $weighted_score = 0;

        $overlap = array_intersect($source_keywords, $target_keywords);
        foreach ($overlap as $keyword) {
            $keyword_lower = strtolower($keyword);
            $doc_frequency = isset($frequency[$keyword_lower]) ? $frequency[$keyword_lower] : 1;
            // IDF = log(total_docs / doc_frequency)
            $idf = log($total_docs / max(1, $doc_frequency));
            $weighted_score += $idf * 2; // Scale factor
        }

        return min($weighted_score, 30); // Cap at 30 points
    }

    /**
     * Build keyword frequency index for TF-IDF weighting
     * Call this after catalog rebuild
     */
    public function build_keyword_frequency_index() {
        $catalog = $this->get_catalog();
        $frequency = array();

        foreach ($catalog as $entry) {
            $keywords = $entry['semantic_keywords'] ?? array();
            foreach ($keywords as $kw) {
                $kw_lower = strtolower($kw);
                if (!isset($frequency[$kw_lower])) {
                    $frequency[$kw_lower] = 0;
                }
                $frequency[$kw_lower]++;
            }
        }

        update_option($this->keyword_frequency_option, $frequency, false);
        $this->debug_log("Built keyword frequency index: " . count($frequency) . " unique keywords");
        return $frequency;
    }

    /**
     * Get entity-to-cluster relevance score
     * Strong bonus when source mentions entities that match target's cluster
     */
    private function get_entity_cluster_score($source_entities, $target_cluster, $target_entities) {
        $score = 0;
        $source_entities = array_map('strtolower', $source_entities ?? array());
        $target_entities = array_map('strtolower', $target_entities ?? array());
        $target_cluster = strtolower($target_cluster ?? '');

        // Entity-to-cluster mapping for mortgage/real estate domain
        $entity_clusters = array(
            'brrrr' => 'brrrr-strategy',
            'heloc' => 'refinancing',
            'refinance' => 'refinancing',
            'first-time' => 'first-time-buyers',
            'fthb' => 'first-time-buyers',
            'rental' => 'rental-investing',
            'investment property' => 'investment-properties',
            'cash flow' => 'rental-investing',
            'pre-approval' => 'pre-approval',
            'down payment' => 'down-payment',
            'closing costs' => 'closing-costs',
            'mortgage broker' => 'mortgage-types',
            'credit score' => 'credit-repair',
            'self-employed' => 'self-employed-mortgages',
        );

        // Check if source entities map to target cluster
        foreach ($source_entities as $entity) {
            foreach ($entity_clusters as $keyword => $cluster) {
                if (stripos($entity, $keyword) !== false && $cluster === $target_cluster) {
                    $score += 25; // Strong entity-cluster match
                    break 2;
                }
            }
        }

        // Direct entity overlap
        $entity_overlap = count(array_intersect($source_entities, $target_entities));
        $score += min($entity_overlap * 8, 20);

        return $score;
    }

    /**
     * Check for reciprocal links (A→B and B→A)
     * Returns penalty score if reciprocal link exists
     */
    private function check_reciprocal_link($source_id, $target_id) {
        // Check if target already links back to source
        $target_links = get_post_meta($target_id, $this->link_meta_key, true) ?: array();
        $source_url = get_permalink($source_id);

        foreach ($target_links as $link) {
            if ($link['url'] === $source_url) {
                return -20; // Penalty for reciprocal link
            }
        }
        return 0;
    }

    /**
     * Get anchor diversity penalty
     * Penalize anchors that are overused across the site
     */
    private function get_anchor_diversity_penalty($anchor_text, $target_id) {
        $usage = get_option($this->anchor_usage_option, array());
        $key = strtolower($anchor_text) . '_' . $target_id;

        $count = isset($usage[$key]) ? $usage[$key] : 0;
        if ($count > 10) return -15;
        if ($count > 5) return -10;
        if ($count > 3) return -5;
        return 0;
    }

    /**
     * Track anchor usage for diversity scoring
     */
    public function track_anchor_usage($anchor_text, $target_id) {
        $usage = get_option($this->anchor_usage_option, array());
        $key = strtolower($anchor_text) . '_' . $target_id;

        if (!isset($usage[$key])) {
            $usage[$key] = 0;
        }
        $usage[$key]++;

        update_option($this->anchor_usage_option, $usage, false);
    }

    /**
     * Check if anchor length is optimal (2-4 words)
     * Returns bonus for good length, penalty for bad
     */
    private function get_anchor_length_score($anchor_text) {
        $word_count = str_word_count($anchor_text);
        if ($word_count >= 2 && $word_count <= 4) {
            return 10; // Optimal length
        } elseif ($word_count === 1) {
            return -10; // Too short
        } elseif ($word_count > 6) {
            return -5; // Too long
        }
        return 0;
    }

    /**
     * Check for persona conflicts (negative matching)
     * Returns penalty if personas should not be linked
     */
    private function get_persona_conflict_penalty($source_persona, $target_persona) {
        // Personas that should NOT link to each other
        $conflicts = array(
            'investor' => array('first-time-buyer'),
            'first-time-buyer' => array('investor'),
            'realtor' => array('first-time-buyer'), // Realtors talk to realtors
        );

        if (isset($conflicts[$source_persona]) && in_array($target_persona, $conflicts[$source_persona])) {
            return -30; // Strong penalty
        }
        return 0;
    }

    /**
     * Get deep page priority bonus
     * Prioritize linking to orphaned/low-visibility pages
     */
    private function get_deep_page_bonus($target) {
        $inbound = $target['inbound_link_count'] ?? 0;

        if ($inbound === 0) {
            return 25; // Orphaned page - high priority
        } elseif ($inbound <= 2) {
            return 15; // Very few links
        } elseif ($inbound <= 5) {
            return 5;
        }
        return 0;
    }

    /**
     * Get synonyms for a keyword
     */
    private function get_keyword_synonyms($keyword) {
        // Mortgage/real estate domain synonyms
        $synonyms = array(
            'mortgage' => array('home loan', 'loan', 'financing'),
            'real estate' => array('property', 'properties', 'real-estate'),
            'investing' => array('investment', 'investments', 'invest'),
            'refinance' => array('refinancing', 'refi'),
            'rental' => array('rent', 'rentals', 'renting'),
            'rate' => array('rates', 'interest rate', 'interest rates'),
            'buyer' => array('buyers', 'purchaser', 'purchasers'),
            'home' => array('house', 'property', 'residence'),
            'down payment' => array('downpayment', 'down-payment'),
            'pre-approval' => array('preapproval', 'pre approval'),
            'cash flow' => array('cashflow', 'cash-flow'),
            'ROI' => array('return on investment', 'returns'),
            'BRRRR' => array('buy rehab rent refinance repeat', 'brrrr strategy'),
            'HELOC' => array('home equity line of credit', 'home equity'),
        );

        $kw_lower = strtolower($keyword);
        if (isset($synonyms[$kw_lower])) {
            return $synonyms[$kw_lower];
        }

        // Check if keyword is a synonym value
        foreach ($synonyms as $primary => $syns) {
            if (in_array($kw_lower, array_map('strtolower', $syns))) {
                return array_merge(array($primary), $syns);
            }
        }

        return array();
    }

    /**
     * Get synonym-aware keyword overlap score
     */
    private function get_synonym_keyword_score($source_keywords, $target_keywords) {
        $score = 0;
        $matched = array();

        foreach ($source_keywords as $src_kw) {
            $src_lower = strtolower($src_kw);
            $src_synonyms = array_merge(array($src_lower), array_map('strtolower', $this->get_keyword_synonyms($src_kw)));

            foreach ($target_keywords as $tgt_kw) {
                $tgt_lower = strtolower($tgt_kw);
                if (in_array($tgt_lower, $src_synonyms) && !in_array($tgt_lower, $matched)) {
                    $score += 4;
                    $matched[] = $tgt_lower;
                }
            }
        }

        return min($score, 20);
    }

    /**
     * Build synonym map from catalog (for ownership map)
     */
    public function build_synonym_map() {
        $catalog = $this->get_catalog();
        $synonyms = array();

        foreach ($catalog as $entry) {
            $keywords = $entry['semantic_keywords'] ?? array();
            foreach ($keywords as $kw) {
                $kw_lower = strtolower($kw);
                $syns = $this->get_keyword_synonyms($kw);
                if (!empty($syns)) {
                    $synonyms[$kw_lower] = $syns;
                }
            }
        }

        update_option($this->synonym_map_option, $synonyms, false);
        $this->debug_log("Built synonym map: " . count($synonyms) . " keyword groups");
        return $synonyms;
    }

    /**
     * v12.2: Build compact catalog table for full site awareness
     * Shows ALL pages/posts in a condensed format so Claude can make strategic decisions
     */
    private function build_compact_catalog_table($source_id, $linked_urls = array()) {
        $catalog = $this->get_catalog();

        $pages_table = "ID|Title|Cluster|Persona|Funnel|Inbound#|Priority\n";
        $pages_table .= str_repeat("-", 80) . "\n";

        $posts_table = "ID|Title|Cluster|Persona|Funnel|Inbound#\n";
        $posts_table .= str_repeat("-", 70) . "\n";

        $page_count = 0;
        $post_count = 0;

        foreach ($catalog as $id => $entry) {
            if ($id == $source_id) continue;

            // Mark already-linked items
            $linked_marker = in_array($entry['url'], $linked_urls) ? '[LINKED]' : '';

            $title = substr($entry['title'], 0, 40);
            if (strlen($entry['title']) > 40) $title .= '...';

            $cluster = substr($entry['topic_cluster'] ?? 'general', 0, 15);
            $persona = substr($entry['target_persona'] ?? 'general', 0, 12);
            $funnel = substr($entry['funnel_stage'] ?? 'awareness', 0, 10);
            $inbound = $entry['inbound_link_count'] ?? 0;

            if ($entry['is_page']) {
                $priority = $this->get_page_priority($id);
                $pages_table .= "{$id}|{$title}|{$cluster}|{$persona}|{$funnel}|{$inbound}|P{$priority}{$linked_marker}\n";
                $page_count++;
            } else {
                $posts_table .= "{$id}|{$title}|{$cluster}|{$persona}|{$funnel}|{$inbound}{$linked_marker}\n";
                $post_count++;
            }
        }

        return array(
            'pages' => $pages_table,
            'posts' => $posts_table,
            'page_count' => $page_count,
            'post_count' => $post_count
        );
    }

    /**
     * Build the intelligent linking prompt - v12.2 Full Catalog Intelligence
     * Now includes full site catalog awareness + detailed candidates
     */
    private function build_linking_prompt($source, $source_entry, $source_content,
        $available_pages, $available_posts, $used_anchors, $page_slots, $post_slots) {

        // v12.2: Get linked URLs to mark in catalog
        $existing_links = get_post_meta($source->ID, $this->link_meta_key, true) ?: array();
        $linked_urls = array_column($existing_links, 'url');

        // v12.2: Build full catalog awareness
        $compact_catalog = $this->build_compact_catalog_table($source->ID, $linked_urls);

        $prompt = "You are an expert SEO strategist creating semantically relevant internal links.\n\n";

        // v12.2: FULL SITE CATALOG - Claude sees the entire site structure
        $prompt .= "=== FULL SITE CATALOG ({$compact_catalog['page_count']} pages, {$compact_catalog['post_count']} posts) ===\n";
        $prompt .= "Use this to understand site architecture. Items marked [LINKED] are already linked from this post.\n";
        $prompt .= "PRIORITIZE: Low Inbound# pages need links! High Priority pages (P4, P5) are important.\n\n";

        $prompt .= "SERVICE PAGES:\n";
        $prompt .= $compact_catalog['pages'] . "\n";

        $prompt .= "BLOG POSTS:\n";
        $prompt .= $compact_catalog['posts'] . "\n";

        // Source post info
        $prompt .= "=== SOURCE POST (adding links TO this post) ===\n";
        $prompt .= "Title: " . $source->post_title . "\n";
        $prompt .= "Topic Cluster: " . ($source_entry['topic_cluster'] ?? 'general') . "\n";
        $prompt .= "Funnel Stage: " . ($source_entry['funnel_stage'] ?? 'awareness') . "\n";
        $prompt .= "Persona: " . ($source_entry['target_persona'] ?? 'general') . "\n";
        $prompt .= "Topics: " . implode(', ', $source_entry['main_topics'] ?? array()) . "\n";
        $prompt .= "Content:\n" . $source_content . "\n\n";

        if (!empty($used_anchors)) {
            $prompt .= "=== ALREADY USED ANCHORS (NEVER USE THESE) ===\n" . implode(', ', $used_anchors) . "\n\n";
        }

        // v12.2: Show DETAILED info for top candidates (for anchor selection)
        $links_requested = array();

        if ($page_slots > 0 && !empty($available_pages)) {
            $prompt .= "=== TOP PAGE CANDIDATES WITH ANCHOR SUGGESTIONS (max " . $page_slots . " links) ===\n";
            $count = 0;
            foreach ($available_pages as $id => $entry) {
                if ($count >= 25) break; // Increased from 15 to 25
                $priority = $this->get_page_priority($id);
                $inbound = $entry['inbound_link_count'] ?? 0;
                $prompt .= "\nID:" . $id . " | " . $entry['title'] . " | P" . $priority . " | Inbound:" . $inbound;

                // Add anchor phrases for selection
                $anchor_phrases = $entry['good_anchor_phrases'] ?? array();
                if (!empty($anchor_phrases)) {
                    $prompt .= "\n  Anchors: " . implode(', ', array_slice($anchor_phrases, 0, 6));
                }
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to $page_slots page links";
        }

        if ($post_slots > 0 && !empty($available_posts)) {
            $prompt .= "=== TOP POST CANDIDATES WITH ANCHOR SUGGESTIONS (max " . $post_slots . " links) ===\n";
            $count = 0;
            foreach ($available_posts as $id => $entry) {
                if ($count >= 30) break; // Increased from 20 to 30
                $inbound = $entry['inbound_link_count'] ?? 0;
                $prompt .= "\nID:" . $id . " | " . $entry['title'] . " | Inbound:" . $inbound;

                // Add anchor phrases for selection
                $anchor_phrases = $entry['good_anchor_phrases'] ?? array();
                if (!empty($anchor_phrases)) {
                    $prompt .= "\n  Anchors: " . implode(', ', array_slice($anchor_phrases, 0, 5));
                }
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to $post_slots post links";
        }

        $prompt .= "=== TASK ===\n";
        $prompt .= "Find: " . implode(' + ', $links_requested) . "\n\n";

        $prompt .= "=== v12.2 FULL CATALOG LINKING RULES ===\n";
        $prompt .= "1. SITE-WIDE STRATEGY: You can see ALL pages/posts - make strategic choices\n";
        $prompt .= "2. PRIORITIZE ORPHANS: Pages with Inbound# < 5 NEED links - prioritize them\n";
        $prompt .= "3. RESPECT PRIORITY: P5 pages are most important, P1 least important\n";
        $prompt .= "4. CLUSTER INTEGRITY: Link within same/related topic clusters for topical authority\n";
        $prompt .= "5. PERSONA ALIGNMENT: Match reader personas (don't link investor→first-time-buyer)\n";
        $prompt .= "6. FUNNEL PROGRESSION: Link awareness→consideration→decision naturally\n";
        $prompt .= "7. DISTRIBUTE EVENLY: Spread links throughout the ENTIRE article\n";
        $prompt .= "8. MAX 1 LINK PER PARAGRAPH: Never add multiple links to same paragraph\n";
        $prompt .= "9. ANCHOR DIVERSITY: Vary anchor text across the site\n";
        $prompt .= "10. QUALITY > QUANTITY: Fewer good links beats many weak links\n\n";

        $prompt .= "ANCHOR TEXT RULES:\n";
        $prompt .= "- MUST be an EXACT phrase from the source content (2-4 words ideal)\n";
        $prompt .= "- Use 'Anchors' suggestions when they appear naturally in content\n";
        $prompt .= "- Must read naturally in sentence context\n\n";

        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"target_id": 123, "anchor_text": "exact phrase from content", "is_page": true/false, "paragraph_hint": "first few words of paragraph"}, ...]\n';
        $prompt .= 'Return [] if no good semantic opportunities exist.\n';

        return $prompt;
    }

    /**
     * Get link suggestions WITHOUT inserting (review mode)
     */
    public function get_link_suggestions($target_id) {
        $target = get_post($target_id);
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }

        $target_entry = $this->get_catalog_entry($target_id);
        if (!$target_entry) {
            return array('success' => false, 'message' => 'Target not in catalog');
        }

        $catalog = $this->get_catalog();
        $potential_sources = array();
        $used_anchors_by_post = array();

        foreach ($catalog as $id => $entry) {
            if ($id == $target_id) continue;
            if ($entry['is_page']) continue;
            if ($this->post_already_links_to($id, $target_entry['url'])) continue;

            $existing_links = get_post_meta($id, $this->link_meta_key, true) ?: array();
            if (count($existing_links) >= 8) continue;

            $entry['relevance_score'] = $this->calculate_relevance_score($entry, $target_entry);

            $used_anchors_by_post[$id] = array();
            foreach ($existing_links as $link) {
                $used_anchors_by_post[$id][] = strtolower($link['anchor']);
            }

            $potential_sources[$id] = $entry;
        }

        if (empty($potential_sources)) {
            return array('suggestions' => array(), 'message' => 'No eligible sources');
        }

        uasort($potential_sources, function($a, $b) {
            return ($b['relevance_score'] ?? 0) - ($a['relevance_score'] ?? 0);
        });

        $prompt = "You are an SEO expert finding internal linking opportunities.\n\n";
        $prompt .= "=== TARGET PAGE ===\n";
        $prompt .= "Title: " . $target_entry['title'] . "\n";
        $prompt .= "Cluster: " . ($target_entry['topic_cluster'] ?? 'general') . "\n";
        $prompt .= "Topics: " . implode(', ', $target_entry['main_topics']) . "\n";
        $prompt .= "Good anchors: " . implode(', ', $target_entry['good_anchor_phrases']) . "\n\n";

        $prompt .= "=== SOURCE POSTS ===\n";
        $count = 0;
        foreach ($potential_sources as $id => $entry) {
            if ($count >= 25) break;
            $prompt .= "ID:" . $id . " | " . $entry['title'] . " | Cluster:" . ($entry['topic_cluster'] ?? 'none') . "\n";
            if (!empty($used_anchors_by_post[$id])) {
                $prompt .= "USED: " . implode(', ', $used_anchors_by_post[$id]) . "\n";
            }
            $count++;
        }

        $prompt .= "\n=== RULES ===\n";
        $prompt .= "1. Anchor = COMPLETE meaningful phrase\n";
        $prompt .= "2. Don't duplicate anchors\n";
        $prompt .= "3. Skip if no good match\n\n";
        $prompt .= "Respond with ONLY JSON array:\n";
        $prompt .= '[{"source_id": 123, "anchor_text": "phrase"}, ...]\n';

        $response = $this->call_claude_api($prompt, 1000);

        if (!$response['success']) {
            return array('suggestions' => array(), 'error' => $response['error']);
        }

        $suggestions = json_decode($response['text'], true);
        if (!$suggestions && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $suggestions = json_decode($matches[0], true);
        }

        if (!is_array($suggestions)) {
            return array('suggestions' => array());
        }

        foreach ($suggestions as &$s) {
            $s['source_title'] = get_the_title($s['source_id']);
        }

        return array('suggestions' => $suggestions, 'target_title' => $target_entry['title']);
    }

    /**
     * Insert user-approved links
     */
    public function insert_approved_links($target_id, $links) {
        $target = get_post($target_id);
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }

        $target_entry = $this->get_catalog_entry($target_id);
        if (!$target_entry) {
            return array('success' => false, 'message' => 'Target not in catalog');
        }

        $inserted = 0;
        $errors = array();

        foreach ($links as $link) {
            $source_id = intval($link['source_id']);
            $anchor = sanitize_text_field($link['anchor_text']);

            if (!$source_id || !$anchor) continue;

            $result = $this->insert_link_in_post($source_id, $anchor, $target_entry['url'], $target_id, $target_entry['is_page']);

            if ($result['success']) {
                $inserted++;
            } else {
                $errors[] = "Post $source_id: " . $result['message'];
            }
        }

        return array('success' => true, 'inserted' => $inserted, 'errors' => $errors);
    }

    // =========================================================================
    // LINK INSERTION
    // =========================================================================

    /**
     * Insert a single link into a post's content
     */
    private function insert_link_in_post($post_id, $anchor_text, $target_url, $target_id, $is_page = false) {
        clean_post_cache($post_id);
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found');
        }

        $existing_links = get_post_meta($post_id, $this->link_meta_key, true) ?: array();
        if (count($existing_links) >= 8) {
            return array('success' => false, 'message' => 'Max 8 links reached');
        }

        $anchor_lower = strtolower($anchor_text);
        foreach ($existing_links as $link) {
            if (strtolower($link['anchor']) === $anchor_lower) {
                return array('success' => false, 'message' => 'Anchor already used');
            }
        }

        $content = $post->post_content;

        // Store original
        $original = get_post_meta($post_id, $this->original_content_meta, true);
        if (empty($original)) {
            update_post_meta($post_id, $this->original_content_meta, $content);
        }

        // Find anchor in content
        $pattern = '/(?<![<\/a-zA-Z])(' . preg_quote($anchor_text, '/') . ')(?![^<]*<\/a>)(?![a-zA-Z])/i';

        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $alt_result = $this->find_alternative_anchor($post_id, $content, $target_url, $target_id, $is_page);
            if ($alt_result['success']) return $alt_result;
            return array('success' => false, 'message' => 'Anchor text not found');
        }

        // Check paragraph doesn't already have a Claude link
        $match_position = $matches[0][1];
        if ($this->paragraph_has_claude_link($content, $match_position)) {
            return array('success' => false, 'message' => 'Paragraph already has a link');
        }

        // Generate unique link ID
        $link_id = 'cl_' . $post_id . '_' . $target_id . '_' . time();

        // Create link HTML
        $link_html = '<a href="' . esc_url($target_url) . '" data-claude-link="1" data-link-id="' . $link_id . '">' . '$1' . '</a>';

        // Replace first occurrence
        $new_content = preg_replace($pattern, $link_html, $content, 1);

        if ($new_content === $content) {
            return array('success' => false, 'message' => 'Failed to insert link');
        }

        wp_update_post(array('ID' => $post_id, 'post_content' => $new_content));

        // Track link
        $links = get_post_meta($post_id, $this->link_meta_key, true) ?: array();
        $links[] = array(
            'link_id' => $link_id,
            'anchor' => $anchor_text,
            'url' => $target_url,
            'target_post_id' => $target_id,
            'is_page' => $is_page,
            'added_at' => current_time('mysql')
        );
        update_post_meta($post_id, $this->link_meta_key, $links);
        $this->clear_links_cache();

        // v5.0: Track anchor usage for diversity scoring
        $this->track_anchor_usage($anchor_text, $target_id);

        $this->debug_log("Inserted link to {$target_url} in post {$post_id}");

        return array('success' => true, 'link_id' => $link_id);
    }

    /**
     * Find alternative anchor text using Claude
     */
    private function find_alternative_anchor($post_id, $content, $target_url, $target_id, $is_page) {
        $target = get_post($target_id);
        if (!$target) return array('success' => false, 'message' => 'Target not found');

        $content_preview = substr(wp_strip_all_tags($content), 0, 2000);

        $prompt = "Find a natural phrase in this content to use as anchor text.\n\n";
        $prompt .= "TARGET: " . $target->post_title . " (" . $target_url . ")\n\n";
        $prompt .= "CONTENT:\n" . $content_preview . "\n\n";
        $prompt .= "Find a 2-5 word phrase that:\n";
        $prompt .= "1. Actually exists in the content\n";
        $prompt .= "2. Is relevant to the target\n";
        $prompt .= "3. Is NOT inside an <a> tag\n";
        $prompt .= "4. Would make natural anchor text\n\n";
        $prompt .= "Respond with ONLY the exact phrase. If none found: NONE\n";

        $response = $this->call_claude_api($prompt, 100);

        if (!$response['success']) return array('success' => false, 'message' => 'API error');

        $anchor = trim($response['text']);

        if ($anchor === 'NONE' || strlen($anchor) < 3 || strlen($anchor) > 50) {
            return array('success' => false, 'message' => 'No suitable anchor');
        }

        return $this->insert_link_in_post($post_id, $anchor, $target_url, $target_id, $is_page);
    }

    /**
     * Check if paragraph already has a Claude link
     */
    private function paragraph_has_claude_link($content, $position) {
        $para_start = 0;
        $search_back = strrpos(substr($content, 0, $position), '<p');
        if ($search_back !== false) {
            $para_start = $search_back;
        } else {
            foreach (array('<div', '<li', "\n\n") as $delim) {
                $found = strrpos(substr($content, 0, $position), $delim);
                if ($found !== false && $found > $para_start) {
                    $para_start = $found;
                }
            }
        }

        $para_end = strlen($content);
        $search_forward = strpos($content, '</p>', $position);
        if ($search_forward !== false) {
            $para_end = $search_forward + 4;
        } else {
            foreach (array('</div>', '</li>', "\n\n") as $delim) {
                $found = strpos($content, $delim, $position);
                if ($found !== false && $found < $para_end) {
                    $para_end = $found;
                }
            }
        }

        $paragraph = substr($content, $para_start, $para_end - $para_start);
        return strpos($paragraph, 'data-claude-link="1"') !== false;
    }

    /**
     * Check if post already links to URL
     */
    private function post_already_links_to($post_id, $url) {
        $post = get_post($post_id);
        if (!$post) return true;
        return strpos($post->post_content, $url) !== false;
    }

    // =========================================================================
    // QUEUE SYSTEM (Background Processing)
    // =========================================================================

    /**
     * Initialize bulk queue
     */
    public function init_bulk_queue($skip_with_links = true) {
        $catalog = $this->get_catalog();
        $queue_ids = array();
        $skipped = 0;

        foreach ($catalog as $id => $entry) {
            if ($entry['is_page']) continue;

            if ($skip_with_links) {
                // Check meta key first (fast)
                $existing_links = $this->get_post_links($id);
                if (!empty($existing_links)) {
                    $skipped++;
                    continue;
                }

                // Also check actual content for claude links (fallback)
                $post = get_post($id);
                if ($post && strpos($post->post_content, 'data-claude-link="1"') !== false) {
                    $skipped++;
                    continue;
                }
            }

            $queue_ids[] = $id;
        }

        update_option($this->queue_option, $queue_ids, false);

        $status = array(
            'state' => 'running',
            'total' => count($queue_ids),
            'processed' => 0,
            'links_created' => 0,
            'errors' => 0,
            'skipped' => $skipped,
            'current_post' => '',
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'batch_size' => 15,  // Increased from 3 for faster processing
            'fast_mode' => true  // Use keyword ownership matching (no API calls)
        );
        update_option($this->queue_status_option, $status, false);

        // Pre-build keyword ownership map for fast linking (avoids building mid-process)
        $ownership_stats = $this->get_keyword_ownership_stats();
        if (!$ownership_stats['has_map']) {
            $this->debug_log('Pre-building keyword ownership map before queue processing...');
            $this->build_keyword_ownership_map();
        }

        if (count($queue_ids) > 0 && !wp_next_scheduled('lendcity_process_link_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_link_queue');
            $this->debug_log('Scheduled queue cron for ' . count($queue_ids) . ' items (fast mode)');
        }

        return array('queued' => count($queue_ids), 'skipped' => $skipped);
    }

    /**
     * Process queue batch - FAST MODE
     * Uses keyword ownership matching instead of Claude API for 30x speed improvement
     */
    public function process_queue_batch() {
        $lock_key = 'lendcity_queue_processing';
        if (get_transient($lock_key)) {
            return array('complete' => false, 'message' => 'Already processing');
        }
        set_transient($lock_key, true, 120);

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $queue = get_option($this->queue_option, array());
        $status = get_option($this->queue_status_option, array());

        if (empty($queue) || (isset($status['state']) && $status['state'] === 'paused')) {
            if (empty($queue) && isset($status['state']) && $status['state'] === 'running') {
                $status['state'] = 'complete';
                $status['completed_at'] = current_time('mysql');
                update_option($this->queue_status_option, $status, false);
            }
            $this->clear_catalog_cache(); // Clean up
            delete_transient($lock_key);
            return array('complete' => true);
        }

        // Pre-load catalog into memory cache (avoids reloading for each post)
        $this->preload_catalog_cache();

        $batch_size = isset($status['batch_size']) ? $status['batch_size'] : 15;
        $fast_mode = isset($status['fast_mode']) ? $status['fast_mode'] : true;
        $processed = 0;
        $links = 0;

        while ($processed < $batch_size && !empty($queue)) {
            $post_id = array_shift($queue);
            update_option($this->queue_option, $queue, false);

            $post_title = get_the_title($post_id);
            $status['current_post'] = $post_title;
            $status['last_activity'] = current_time('mysql');
            update_option($this->queue_status_option, $status, false);

            // HYBRID MODE: Fast keyword matching first, API fallback if 0 links found
            // This gives speed (most posts) + quality (posts without keyword matches)
            if ($fast_mode) {
                $result = $this->create_links_using_ownership($post_id, false);
                $count = isset($result['links_created']) ? $result['links_created'] : 0;

                // Fallback to API if fast mode found nothing
                if ($count === 0 && $result['success']) {
                    $this->log('Queue: No keyword matches for ' . $post_id . ' - trying API...');
                    $result = $this->create_links_from_source($post_id);
                    $count = isset($result['links_created']) ? $result['links_created'] : 0;
                    $mode = 'api-fallback';
                } else {
                    $mode = 'fast';
                }
            } else {
                $result = $this->create_links_from_source($post_id);
                $count = isset($result['links_created']) ? $result['links_created'] : 0;
                $mode = 'api';
            }

            $status['processed']++;
            $processed++;

            if ($result['success']) {
                $status['links_created'] += $count;
                $links += $count;
                $this->log('Queue: Processed ' . $post_id . ' - ' . $count . ' links (' . $mode . ')');
            } else {
                $status['errors']++;
            }

            update_option($this->queue_status_option, $status, false);
        }

        $this->clear_catalog_cache(); // Clean up memory
        delete_transient($lock_key);

        if (empty($queue)) {
            $status['state'] = 'complete';
            $status['completed_at'] = current_time('mysql');
            $status['current_post'] = '';
            update_option($this->queue_status_option, $status, false);
            wp_clear_scheduled_hook('lendcity_process_link_queue');
            $this->log('Queue complete');
            return array('complete' => true, 'processed' => $processed, 'links' => $links);
        }

        return array('complete' => false, 'remaining' => count($queue), 'processed' => $processed, 'links' => $links);
    }

    /**
     * AJAX handler for background processing
     */
    public function ajax_background_process() {
        $token = get_option('lendcity_queue_token', '');
        if (empty($_POST['token']) || $_POST['token'] !== $token) {
            wp_die('Invalid token');
        }
        $result = $this->process_queue_batch();
        wp_send_json($result);
    }

    /**
     * Trigger background processing
     */
    public function trigger_background_process() {
        $token = get_option('lendcity_queue_token', '');
        if (empty($token)) {
            $token = wp_generate_password(32, false);
            update_option('lendcity_queue_token', $token, false);
        }

        wp_remote_post(admin_url('admin-ajax.php'), array(
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'body' => array('action' => 'lendcity_background_process', 'token' => $token)
        ));
    }

    /**
     * Get queue status
     */
    public function get_queue_status() {
        $queue = get_option($this->queue_option, array());
        $status = get_option($this->queue_status_option, array());

        if (empty($status)) {
            $pending = is_array($queue) ? count($queue) : 0;
            return array(
                'total' => $pending,
                'pending' => $pending,
                'processing' => 0,
                'complete' => 0,
                'error' => 0
            );
        }

        $remaining = is_array($queue) ? count($queue) : 0;

        return array(
            'state' => $status['state'] ?? 'idle',
            'total' => $status['total'] ?? 0,
            'processed' => $status['processed'] ?? 0,
            'remaining' => $remaining,
            'links_created' => $status['links_created'] ?? 0,
            'errors' => $status['errors'] ?? 0,
            'skipped' => $status['skipped'] ?? 0,
            'current_post' => $status['current_post'] ?? '',
            'started_at' => $status['started_at'] ?? '',
            'last_activity' => $status['last_activity'] ?? '',
            'completed_at' => $status['completed_at'] ?? '',
            'pending' => $remaining,
            'processing' => ($status['state'] ?? '') === 'running' ? 1 : 0,
            'complete' => $status['processed'] ?? 0,
            'error' => $status['errors'] ?? 0
        );
    }

    /**
     * Pause queue
     */
    public function pause_queue() {
        $status = get_option($this->queue_status_option, array());
        $status['state'] = 'paused';
        $status['paused_at'] = current_time('mysql');
        update_option($this->queue_status_option, $status, false);

        // Stop the cron from running while paused
        wp_clear_scheduled_hook('lendcity_process_link_queue');
        wp_clear_scheduled_hook('lendcity_process_queue_batch');

        // Clear processing lock
        delete_transient('lendcity_queue_processing');

        $this->debug_log('Queue paused - cron cleared');
    }

    /**
     * Resume queue
     */
    public function resume_queue() {
        $status = get_option($this->queue_status_option, array());
        $status['state'] = 'running';
        unset($status['paused_at']);
        update_option($this->queue_status_option, $status, false);

        // Reschedule the cron
        if (!wp_next_scheduled('lendcity_process_link_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_link_queue');
        }

        $this->trigger_background_process();
        $this->debug_log('Queue resumed');
    }

    /**
     * Clear queue (full stop)
     */
    public function clear_queue() {
        delete_option($this->queue_option);
        delete_option($this->queue_status_option);
        delete_option('lendcity_queue_token');
        wp_clear_scheduled_hook('lendcity_process_link_queue');
        wp_clear_scheduled_hook('lendcity_process_queue_batch');

        // Clear processing lock
        delete_transient('lendcity_queue_processing');

        // Clear catalog cache
        $this->clear_catalog_cache();

        $this->debug_log('Queue cleared completely');
    }

    // =========================================================================
    // v12.0 BACKGROUND QUEUE PROCESSING (Runs without browser)
    // =========================================================================

    /**
     * Initialize catalog build queue for background processing
     */
    public function init_catalog_queue($post_types = array('post', 'page')) {
        // Get all published posts/pages
        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        $posts = get_posts($args);

        if (empty($posts)) {
            return array('success' => false, 'message' => 'No posts found');
        }

        // Save queue
        update_option($this->catalog_queue_option, $posts, false);

        // Initialize status
        $status = array(
            'state' => 'running',
            'total' => count($posts),
            'processed' => 0,
            'errors' => 0,
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'current_post' => ''
        );
        update_option($this->catalog_queue_status_option, $status, false);

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('lendcity_process_catalog_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_catalog_queue');
        }

        $this->debug_log('Catalog queue initialized: ' . count($posts) . ' posts');

        return array('success' => true, 'total' => count($posts));
    }

    /**
     * Process catalog queue batch - runs via WP Cron (no browser needed)
     */
    public function process_catalog_queue_batch() {
        $lock_key = 'lendcity_catalog_queue_processing';
        if (get_transient($lock_key)) {
            return array('complete' => false, 'message' => 'Already processing');
        }
        set_transient($lock_key, true, 180); // 3 minute lock

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $queue = get_option($this->catalog_queue_option, array());
        $status = get_option($this->catalog_queue_status_option, array());

        if (empty($queue)) {
            // Queue complete
            if (isset($status['state']) && $status['state'] === 'running') {
                $status['state'] = 'complete';
                $status['completed_at'] = current_time('mysql');
                update_option($this->catalog_queue_status_option, $status, false);

                // Build semantic indexes after catalog complete
                $this->build_semantic_indexes();
            }
            wp_clear_scheduled_hook('lendcity_process_catalog_queue');
            delete_transient($lock_key);
            $this->debug_log('Catalog queue complete');
            return array('complete' => true);
        }

        // Process batch of 3 posts (balance speed vs API limits)
        $batch_size = 3;
        $processed = 0;

        while ($processed < $batch_size && !empty($queue)) {
            $post_id = array_shift($queue);
            update_option($this->catalog_queue_option, $queue, false);

            $post = get_post($post_id);
            if (!$post) {
                $status['errors']++;
                continue;
            }

            $status['current_post'] = $post->post_title;
            $status['last_activity'] = current_time('mysql');
            update_option($this->catalog_queue_status_option, $status, false);

            // Build catalog entry for this post
            $result = $this->build_single_catalog_entry($post_id);

            if ($result['success']) {
                $status['processed']++;
                $this->debug_log('Catalog: Added ' . $post->post_title);
            } else {
                $status['errors']++;
                $this->debug_log('Catalog: Failed ' . $post->post_title . ' - ' . ($result['message'] ?? 'Unknown error'));
            }

            update_option($this->catalog_queue_status_option, $status, false);
            $processed++;

            // Small delay to avoid API rate limits
            usleep(500000); // 0.5 second
        }

        delete_transient($lock_key);

        if (empty($queue)) {
            $status['state'] = 'complete';
            $status['completed_at'] = current_time('mysql');
            $status['current_post'] = '';
            update_option($this->catalog_queue_status_option, $status, false);
            wp_clear_scheduled_hook('lendcity_process_catalog_queue');

            // Build semantic indexes
            $this->build_semantic_indexes();

            $this->debug_log('Catalog queue complete - ' . $status['processed'] . ' entries');
            return array('complete' => true, 'processed' => $status['processed']);
        }

        return array('complete' => false, 'remaining' => count($queue), 'processed' => $processed);
    }

    /**
     * Get catalog queue status
     */
    public function get_catalog_queue_status() {
        $queue = get_option($this->catalog_queue_option, array());
        $status = get_option($this->catalog_queue_status_option, array());

        return array(
            'state' => $status['state'] ?? 'idle',
            'total' => $status['total'] ?? 0,
            'processed' => $status['processed'] ?? 0,
            'remaining' => is_array($queue) ? count($queue) : 0,
            'errors' => $status['errors'] ?? 0,
            'current_post' => $status['current_post'] ?? '',
            'started_at' => $status['started_at'] ?? '',
            'last_activity' => $status['last_activity'] ?? '',
            'completed_at' => $status['completed_at'] ?? ''
        );
    }

    /**
     * Clear catalog queue
     */
    public function clear_catalog_queue() {
        delete_option($this->catalog_queue_option);
        delete_option($this->catalog_queue_status_option);
        wp_clear_scheduled_hook('lendcity_process_catalog_queue');
        delete_transient('lendcity_catalog_queue_processing');
        $this->debug_log('Catalog queue cleared');
    }

    /**
     * Initialize ownership map queue for background processing
     */
    public function init_ownership_queue() {
        // Get all catalog entries
        $catalog = $this->get_catalog();

        if (empty($catalog)) {
            return array('success' => false, 'message' => 'Catalog is empty - build catalog first');
        }

        $post_ids = array_keys($catalog);

        // Save queue
        update_option($this->ownership_queue_option, $post_ids, false);

        // Clear existing ownership map
        delete_option($this->keyword_ownership_option);

        // Initialize status
        $status = array(
            'state' => 'running',
            'total' => count($post_ids),
            'processed' => 0,
            'keywords_found' => 0,
            'errors' => 0,
            'started_at' => current_time('mysql'),
            'last_activity' => current_time('mysql'),
            'current_post' => ''
        );
        update_option($this->ownership_queue_status_option, $status, false);

        // Schedule cron
        if (!wp_next_scheduled('lendcity_process_ownership_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_ownership_queue');
        }

        $this->debug_log('Ownership queue initialized: ' . count($post_ids) . ' posts');

        return array('success' => true, 'total' => count($post_ids));
    }

    /**
     * Process ownership queue batch - runs via WP Cron
     */
    public function process_ownership_queue_batch() {
        $lock_key = 'lendcity_ownership_queue_processing';
        if (get_transient($lock_key)) {
            return array('complete' => false, 'message' => 'Already processing');
        }
        set_transient($lock_key, true, 120);

        $queue = get_option($this->ownership_queue_option, array());
        $status = get_option($this->ownership_queue_status_option, array());

        if (empty($queue)) {
            if (isset($status['state']) && $status['state'] === 'running') {
                $status['state'] = 'complete';
                $status['completed_at'] = current_time('mysql');
                update_option($this->ownership_queue_status_option, $status, false);
            }
            wp_clear_scheduled_hook('lendcity_process_ownership_queue');
            delete_transient($lock_key);
            $this->debug_log('Ownership queue complete');
            return array('complete' => true);
        }

        // Process batch of 10 posts (ownership is faster than catalog)
        $batch_size = 10;
        $processed = 0;
        $keywords_added = 0;

        $ownership = get_option($this->keyword_ownership_option, array());

        while ($processed < $batch_size && !empty($queue)) {
            $post_id = array_shift($queue);
            update_option($this->ownership_queue_option, $queue, false);

            $entry = $this->get_catalog_entry($post_id);
            if (!$entry) {
                $status['errors']++;
                continue;
            }

            $status['current_post'] = $entry['title'];
            $status['last_activity'] = current_time('mysql');
            update_option($this->ownership_queue_status_option, $status, false);

            // Extract keywords from catalog entry
            $anchor_phrases = $entry['good_anchor_phrases'] ?? array();
            $keywords = $entry['semantic_keywords'] ?? array();
            $all_phrases = array_merge($anchor_phrases, $keywords);

            foreach ($all_phrases as $phrase) {
                $phrase_lower = strtolower(trim($phrase));
                $word_count = str_word_count($phrase_lower);

                // Only 2-5 word phrases
                if ($word_count < 2 || $word_count > 5) continue;

                if (!isset($ownership[$phrase_lower])) {
                    $ownership[$phrase_lower] = array(
                        'owner_post_id' => $post_id,
                        'owner_url' => $entry['url'],
                        'owner_title' => $entry['title'],
                        'score' => 100
                    );
                    $keywords_added++;
                }
            }

            $status['processed']++;
            $status['keywords_found'] = count($ownership);
            $processed++;
        }

        // Save ownership map
        update_option($this->keyword_ownership_option, $ownership, false);
        update_option($this->ownership_queue_status_option, $status, false);
        delete_transient($lock_key);

        if (empty($queue)) {
            $status['state'] = 'complete';
            $status['completed_at'] = current_time('mysql');
            $status['current_post'] = '';
            update_option($this->ownership_queue_status_option, $status, false);
            wp_clear_scheduled_hook('lendcity_process_ownership_queue');
            $this->debug_log('Ownership queue complete - ' . count($ownership) . ' keywords');
            return array('complete' => true, 'keywords' => count($ownership));
        }

        return array('complete' => false, 'remaining' => count($queue), 'processed' => $processed);
    }

    /**
     * Get ownership queue status
     */
    public function get_ownership_queue_status() {
        $queue = get_option($this->ownership_queue_option, array());
        $status = get_option($this->ownership_queue_status_option, array());

        return array(
            'state' => $status['state'] ?? 'idle',
            'total' => $status['total'] ?? 0,
            'processed' => $status['processed'] ?? 0,
            'remaining' => is_array($queue) ? count($queue) : 0,
            'keywords_found' => $status['keywords_found'] ?? 0,
            'errors' => $status['errors'] ?? 0,
            'current_post' => $status['current_post'] ?? '',
            'started_at' => $status['started_at'] ?? '',
            'last_activity' => $status['last_activity'] ?? '',
            'completed_at' => $status['completed_at'] ?? ''
        );
    }

    /**
     * Clear ownership queue
     */
    public function clear_ownership_queue() {
        delete_option($this->ownership_queue_option);
        delete_option($this->ownership_queue_status_option);
        wp_clear_scheduled_hook('lendcity_process_ownership_queue');
        delete_transient('lendcity_ownership_queue_processing');
        $this->debug_log('Ownership queue cleared');
    }

    /**
     * Get meta queue status (for consistency)
     */
    public function get_meta_queue_status() {
        $queue = get_option($this->meta_queue_option, array());
        $status = get_option($this->meta_queue_status_option, array());

        return array(
            'state' => $status['state'] ?? 'idle',
            'total' => $status['total'] ?? 0,
            'processed' => $status['processed'] ?? 0,
            'remaining' => is_array($queue) ? count($queue) : 0,
            'errors' => $status['errors'] ?? 0,
            'current_post' => $status['current_post'] ?? '',
            'started_at' => $status['started_at'] ?? '',
            'last_activity' => $status['last_activity'] ?? '',
            'completed_at' => $status['completed_at'] ?? ''
        );
    }

    // =========================================================================
    // LINK MANAGEMENT
    // =========================================================================

    /**
     * Get all smart links for a post
     */
    public function get_post_links($post_id) {
        return get_post_meta($post_id, $this->link_meta_key, true) ?: array();
    }

    /**
     * Remove a single link
     */
    public function remove_link($post_id, $link_id) {
        $post = get_post($post_id);
        if (!$post) return false;

        $content = $post->post_content;
        $new_content = $content;

        // Try to remove by data-link-id
        $pattern = '/<a\s[^>]*data-link-id="' . preg_quote($link_id, '/') . '"[^>]*>(.*?)<\/a>/is';
        $new_content = preg_replace($pattern, '$1', $content);

        // Fallback methods
        if ($new_content === $content) {
            $links = $this->get_post_links($post_id);
            foreach ($links as $link) {
                if (isset($link['link_id']) && $link['link_id'] === $link_id) {
                    $url = $link['url'] ?? '';
                    if ($url) {
                        $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*href="' . preg_quote($url, '/') . '"[^>]*>(.*?)<\/a>/is';
                        $new_content = preg_replace($pattern, '$1', $content, 1);
                    }
                    break;
                }
            }
        }

        if ($new_content !== $content) {
            wp_update_post(array('ID' => $post_id, 'post_content' => $new_content));

            $links = $this->get_post_links($post_id);
            $links = array_filter($links, function($l) use ($link_id) {
                return !isset($l['link_id']) || $l['link_id'] !== $link_id;
            });
            update_post_meta($post_id, $this->link_meta_key, array_values($links));
            $this->clear_links_cache();
            return true;
        }

        // Last resort
        $links = $this->get_post_links($post_id);
        $original_count = count($links);
        $links = array_filter($links, function($l) use ($link_id) {
            return !isset($l['link_id']) || $l['link_id'] !== $link_id;
        });

        if (count($links) < $original_count) {
            update_post_meta($post_id, $this->link_meta_key, array_values($links));
            $this->clear_links_cache();
            return true;
        }

        return false;
    }

    /**
     * Remove ALL Claude links from a post
     */
    public function remove_all_links($post_id) {
        $post = get_post($post_id);
        if (!$post) return false;

        $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*>(.*?)<\/a>/is';
        $new_content = preg_replace($pattern, '$1', $post->post_content);

        wp_update_post(array('ID' => $post_id, 'post_content' => $new_content));
        delete_post_meta($post_id, $this->link_meta_key);
        $this->clear_links_cache();
        return true;
    }

    /**
     * Delete ALL Claude links from entire site
     */
    public function delete_all_site_links() {
        global $wpdb;

        $posts = $wpdb->get_results("
            SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_content LIKE '%data-claude-link=\"1\"%'
            AND post_status IN ('publish', 'draft', 'future')
        ");

        $deleted = 0;
        $posts_affected = 0;

        foreach ($posts as $post) {
            preg_match_all('/data-claude-link="1"/', $post->post_content, $matches);
            $link_count = count($matches[0]);

            $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*>(.*?)<\/a>/is';
            $new_content = preg_replace($pattern, '$1', $post->post_content);

            if ($new_content !== $post->post_content) {
                wp_update_post(array('ID' => $post->ID, 'post_content' => $new_content));
                delete_post_meta($post->ID, $this->link_meta_key);
                $deleted += $link_count;
                $posts_affected++;
            }
        }

        $this->clear_links_cache();
        return array('deleted' => $deleted, 'posts_affected' => $posts_affected);
    }

    /**
     * Change target URL of a single link
     */
    public function change_single_link_target($source_id, $link_id, $old_url, $new_url) {
        $post = get_post($source_id);
        if (!$post) return array('success' => false, 'message' => 'Post not found');

        $pattern = '/(<a\s[^>]*data-link-id="' . preg_quote($link_id, '/') . '"[^>]*href=")[^"]*(")/is';
        $new_content = preg_replace($pattern, '${1}' . esc_url($new_url) . '${2}', $post->post_content);

        if ($new_content === $post->post_content && !empty($old_url)) {
            $new_content = preg_replace(
                '/(<a\s[^>]*data-claude-link="1"[^>]*href=")' . preg_quote($old_url, '/') . '(")/is',
                '${1}' . esc_url($new_url) . '${2}',
                $post->post_content, 1
            );
        }

        if ($new_content !== $post->post_content) {
            wp_update_post(array('ID' => $source_id, 'post_content' => $new_content));
        }

        $links = $this->get_post_links($source_id);
        foreach ($links as &$link) {
            if ($link['link_id'] === $link_id || $link['url'] === $old_url) {
                $link['url'] = $new_url;
                $new_post = url_to_postid($new_url);
                if ($new_post) {
                    $link['is_page'] = (get_post_type($new_post) === 'page');
                    $link['target_post_id'] = $new_post;
                }
                break;
            }
        }
        update_post_meta($source_id, $this->link_meta_key, $links);
        $this->clear_links_cache();

        return array('success' => true);
    }

    /**
     * Update URL across all posts
     */
    public function update_url_across_site($old_url, $new_url) {
        global $wpdb;

        $posts = $wpdb->get_results("
            SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_content LIKE '%data-claude-link=\"1\"%'
            AND post_status IN ('publish', 'draft', 'future')
        ");

        $updated = 0;
        foreach ($posts as $post) {
            $new_content = str_replace('href="' . $old_url . '"', 'href="' . $new_url . '"', $post->post_content);
            if ($new_content !== $post->post_content) {
                wp_update_post(array('ID' => $post->ID, 'post_content' => $new_content));

                $links = $this->get_post_links($post->ID);
                foreach ($links as &$link) {
                    if ($link['url'] === $old_url) $link['url'] = $new_url;
                }
                update_post_meta($post->ID, $this->link_meta_key, $links);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->clear_links_cache();
        }
        return $updated;
    }

    /**
     * Get all Claude links across site (with in-memory caching)
     */
    public function get_all_site_links($limit = 0) {
        // Use in-memory cache if available (avoids duplicate queries in same request)
        if ($this->links_cache !== null) {
            if ($limit > 0) {
                return array_slice($this->links_cache, 0, $limit);
            }
            return $this->links_cache;
        }

        global $wpdb;

        $results = $wpdb->get_results("
            SELECT pm.post_id, pm.meta_value, p.post_date
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '{$this->link_meta_key}'
            ORDER BY p.post_date DESC
        ");

        $all_links = array();
        $post_titles_cache = array();

        foreach ($results as $row) {
            $links = maybe_unserialize($row->meta_value);
            if (is_array($links)) {
                if (!isset($post_titles_cache[$row->post_id])) {
                    $post_titles_cache[$row->post_id] = get_the_title($row->post_id);
                }

                foreach ($links as $link) {
                    $link['source_post_id'] = $row->post_id;
                    $link['source_post_title'] = $post_titles_cache[$row->post_id];
                    $link['post_date'] = $row->post_date;
                    $all_links[] = $link;
                }
            }
        }

        // Cache for subsequent calls in same request
        $this->links_cache = $all_links;

        if ($limit > 0) {
            return array_slice($all_links, 0, $limit);
        }
        return $all_links;
    }

    /**
     * Clear links cache (call after modifying links)
     */
    public function clear_links_cache() {
        $this->links_cache = null;
        delete_transient('lendcity_link_stats');
    }

    /**
     * Get links in batches directly from database (memory efficient)
     * Returns links from posts in batches, not from a full array
     *
     * @param int $batch_size Number of posts to process per batch
     * @param int $offset Post offset for pagination
     * @return array Array with 'links' and 'has_more' flag
     */
    public function get_links_batch($batch_size = 50, $offset = 0) {
        global $wpdb;

        // Get posts with links in batches
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, pm.meta_value, p.post_title
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = %s
            ORDER BY pm.post_id ASC
            LIMIT %d OFFSET %d
        ", $this->link_meta_key, $batch_size, $offset));

        $links = array();
        foreach ($results as $row) {
            $post_links = maybe_unserialize($row->meta_value);
            if (is_array($post_links)) {
                foreach ($post_links as $link) {
                    $link['source_id'] = $row->post_id;
                    $link['source_title'] = $row->post_title;
                    $links[] = $link;
                }
            }
        }

        // Check if there are more posts
        $has_more = count($results) === $batch_size;

        return array(
            'links' => $links,
            'has_more' => $has_more,
            'next_offset' => $offset + $batch_size
        );
    }

    /**
     * Get total number of posts with links (for progress calculation)
     */
    public function get_posts_with_links_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $this->link_meta_key
        ));
    }

    /**
     * Get total link count
     */
    public function get_total_link_count() {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '{$this->link_meta_key}'
        ");

        $count = 0;
        foreach ($results as $row) {
            $links = maybe_unserialize($row->meta_value);
            if (is_array($links)) $count += count($links);
        }
        return $count;
    }

    // =========================================================================
    // SEO ENHANCEMENT FUNCTIONS
    // =========================================================================

    /**
     * Get/Set priority for a page
     */
    public function get_page_priority($post_id) {
        return intval(get_post_meta($post_id, $this->priority_meta_key, true)) ?: 3;
    }

    public function set_page_priority($post_id, $priority) {
        $priority = max(1, min(5, intval($priority)));
        update_post_meta($post_id, $this->priority_meta_key, $priority);
        return $priority;
    }

    /**
     * Get/Set target keywords
     */
    public function get_page_keywords($post_id) {
        return get_post_meta($post_id, $this->keywords_meta_key, true) ?: '';
    }

    public function set_page_keywords($post_id, $keywords) {
        $keywords = sanitize_text_field($keywords);
        update_post_meta($post_id, $this->keywords_meta_key, $keywords);
        return $keywords;
    }

    /**
     * Get all priority pages
     */
    public function get_priority_pages() {
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        $result = array();
        foreach ($pages as $page) {
            $result[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'url' => get_permalink($page->ID),
                'priority' => $this->get_page_priority($page->ID),
                'keywords' => $this->get_page_keywords($page->ID),
                'inbound_links' => $this->count_inbound_links($page->ID)
            );
        }

        usort($result, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });

        return $result;
    }

    /**
     * Count inbound links
     */
    public function count_inbound_links($target_post_id) {
        $target_url = get_permalink($target_post_id);
        $target_path = str_replace(home_url(), '', $target_url);

        $count = 0;
        $all_links = $this->get_all_site_links(1000);

        foreach ($all_links as $link) {
            $link_path = str_replace(home_url(), '', $link['url']);
            if ($link_path === $target_path || $link['url'] === $target_url) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Link Gap Analysis
     */
    public function get_link_gaps($min_links = 0, $max_links = 2) {
        $catalog = $this->get_catalog();
        $all_links = $this->get_all_site_links(2000);

        $inbound_counts = array();
        foreach ($all_links as $link) {
            $url = $link['url'];
            if (!isset($inbound_counts[$url])) $inbound_counts[$url] = 0;
            $inbound_counts[$url]++;
        }

        $gaps = array();
        foreach ($catalog as $post_id => $item) {
            $url = $item['url'];
            $count = isset($inbound_counts[$url]) ? $inbound_counts[$url] : 0;

            if ($count >= $min_links && $count <= $max_links) {
                $gaps[] = array(
                    'id' => $item['post_id'],
                    'title' => $item['title'],
                    'url' => $url,
                    'type' => $item['is_page'] ? 'page' : 'post',
                    'inbound_links' => $count,
                    'priority' => $item['is_page'] ? $this->get_page_priority($item['post_id']) : 0,
                    'keywords' => $item['is_page'] ? $this->get_page_keywords($item['post_id']) : '',
                    'topic_cluster' => $item['topic_cluster'] ?? ''
                );
            }
        }

        usort($gaps, function($a, $b) {
            if ($a['inbound_links'] !== $b['inbound_links']) {
                return $a['inbound_links'] - $b['inbound_links'];
            }
            return $b['priority'] - $a['priority'];
        });

        return $gaps;
    }

    /**
     * Get link distribution stats (with transient caching)
     */
    public function get_link_stats() {
        // Check transient cache first (5 minute cache)
        $cached = get_transient('lendcity_link_stats');
        if ($cached !== false) {
            return $cached;
        }

        $catalog = $this->get_catalog();
        $all_links = $this->get_all_site_links(2000);

        $inbound_counts = array();
        foreach ($all_links as $link) {
            $url = $link['url'];
            if (!isset($inbound_counts[$url])) $inbound_counts[$url] = 0;
            $inbound_counts[$url]++;
        }

        $zero_links = 0;
        $one_to_three = 0;
        $four_to_ten = 0;
        $over_ten = 0;
        $pages_zero = 0;
        $posts_zero = 0;

        foreach ($catalog as $item) {
            $count = isset($inbound_counts[$item['url']]) ? $inbound_counts[$item['url']] : 0;

            if ($count === 0) {
                $zero_links++;
                if ($item['is_page']) $pages_zero++;
                else $posts_zero++;
            } elseif ($count <= 3) {
                $one_to_three++;
            } elseif ($count <= 10) {
                $four_to_ten++;
            } else {
                $over_ten++;
            }
        }

        $stats = array(
            'total_items' => count($catalog),
            'total_links' => count($all_links),
            'zero_links' => $zero_links,
            'pages_zero' => $pages_zero,
            'posts_zero' => $posts_zero,
            'one_to_three' => $one_to_three,
            'four_to_ten' => $four_to_ten,
            'over_ten' => $over_ten
        );

        // Cache for 5 minutes
        set_transient('lendcity_link_stats', $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    // =========================================================================
    // CLAUDE API
    // =========================================================================

    private function call_claude_api($prompt, $max_tokens = 1000) {
        if (empty($this->api_key)) {
            return array('success' => false, 'error' => 'API key not set');
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => '2023-06-01'
            ),
            'body' => json_encode(array(
                'model' => 'claude-opus-4-5-20251101',
                'max_tokens' => $max_tokens,
                'messages' => array(array('role' => 'user', 'content' => $prompt))
            ))
        ));

        if (is_wp_error($response)) {
            $this->log('API WP Error: ' . $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
            $this->log('API Error: ' . $error . ' | Full response: ' . wp_remote_retrieve_body($response));
            return array('success' => false, 'error' => $error);
        }

        if (!isset($body['content'][0]['text'])) {
            $this->log('API Invalid response');
            return array('success' => false, 'error' => 'Invalid response');
        }

        return array('success' => true, 'text' => $body['content'][0]['text']);
    }

    // =========================================================================
    // PARALLEL API PROCESSING (v4.0 - 3-5x FASTER catalog building)
    // =========================================================================

    /**
     * Build catalog entries in PARALLEL using curl_multi
     * Processes multiple posts simultaneously for much faster catalog building
     *
     * @param array $post_ids Array of post IDs to catalog
     * @param int $concurrent Number of concurrent API requests (default 3)
     * @return array Cataloged entries
     */
    public function build_parallel_catalog($post_ids, $concurrent = 3) {
        if (empty($this->api_key)) {
            $this->log('Parallel catalog: No API key');
            return array();
        }

        $entries = array();
        $chunks = array_chunk($post_ids, $concurrent);
        $total_chunks = count($chunks);
        $chunk_num = 0;

        foreach ($chunks as $chunk) {
            $chunk_num++;
            $this->log("Parallel catalog: Processing chunk $chunk_num of $total_chunks (" . count($chunk) . " posts)");

            $results = $this->call_claude_api_parallel($chunk);

            foreach ($results as $post_id => $result) {
                if ($result && isset($result['post_id'])) {
                    $this->insert_catalog_entry($post_id, $result);
                    $entries[$post_id] = $result;
                }
            }

            // Small delay between chunks to avoid rate limits
            if ($chunk_num < $total_chunks) {
                usleep(500000); // 0.5 second
            }
        }

        $this->log('Parallel catalog complete: ' . count($entries) . ' entries created');
        return $entries;
    }

    /**
     * Make parallel API calls using curl_multi
     */
    private function call_claude_api_parallel($post_ids) {
        $multi_handle = curl_multi_init();
        $curl_handles = array();
        $posts_data = array();

        // Prepare requests for each post
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') continue;

            $content = wp_strip_all_tags($post->post_content);
            if (strlen($content) < 100) {
                $rendered = apply_filters('the_content', $post->post_content);
                $content = wp_strip_all_tags($rendered);
            }
            if (strlen($content) < 50) {
                $content = "Page title: " . $post->post_title . ". URL slug: " . $post->post_name;
            }
            if (strlen($content) > 12000) {
                $content = substr($content, 0, 12000) . '...';
            }

            $is_page = ($post->post_type === 'page');
            $prompt = $this->build_catalog_prompt($post, $content, $is_page);

            $posts_data[$post_id] = array(
                'post' => $post,
                'is_page' => $is_page,
                'word_count' => str_word_count($content)
            );

            // Create curl handle
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'x-api-key: ' . $this->api_key,
                    'anthropic-version: 2023-06-01'
                ),
                CURLOPT_POSTFIELDS => json_encode(array(
                    'model' => 'claude-opus-4-5-20251101',
                    'max_tokens' => 1500,
                    'messages' => array(array('role' => 'user', 'content' => $prompt))
                ))
            ));

            curl_multi_add_handle($multi_handle, $ch);
            $curl_handles[$post_id] = $ch;
        }

        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);

        // Collect results
        $results = array();
        foreach ($curl_handles as $post_id => $ch) {
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);

            if ($http_code !== 200) {
                $this->log("Parallel API error for post $post_id: HTTP $http_code");
                continue;
            }

            $body = json_decode($response, true);
            if (!isset($body['content'][0]['text'])) {
                $this->log("Parallel API invalid response for post $post_id");
                continue;
            }

            $text = $body['content'][0]['text'];
            $data = json_decode($text, true);
            if (!$data && preg_match('/\{.*\}/s', $text, $matches)) {
                $data = json_decode($matches[0], true);
            }

            if (!$data) {
                $this->log("Failed to parse catalog JSON for post $post_id");
                continue;
            }

            $pdata = $posts_data[$post_id];
            $post = $pdata['post'];

            $results[$post_id] = array(
                // Core fields
                'post_id' => $post_id,
                'type' => $post->post_type,
                'is_page' => $pdata['is_page'],
                'title' => $post->post_title,
                'url' => get_permalink($post_id),
                'summary' => $data['summary'] ?? '',
                'main_topics' => $data['main_topics'] ?? array(),
                'semantic_keywords' => $data['semantic_keywords'] ?? array(),
                'entities' => $data['entities'] ?? array(),
                'content_themes' => $data['content_themes'] ?? array(),
                'good_anchor_phrases' => $data['good_anchor_phrases'] ?? array(),

                // v3.0 Intelligence
                'reader_intent' => $data['reader_intent'] ?? 'educational',
                'difficulty_level' => $data['difficulty_level'] ?? 'intermediate',
                'funnel_stage' => $data['funnel_stage'] ?? 'awareness',
                'topic_cluster' => $data['topic_cluster'] ?? null,
                'related_clusters' => $data['related_clusters'] ?? array(),
                'is_pillar_content' => (bool)($data['is_pillar_content'] ?? false),
                'word_count' => $pdata['word_count'],
                'content_quality_score' => intval($data['content_quality_score'] ?? 50),

                // v4.0 NEW fields
                'content_lifespan' => $data['content_lifespan'] ?? 'evergreen',
                'publish_season' => $data['publish_season'] ?? null,
                'target_regions' => $data['target_regions'] ?? array(),
                'target_cities' => $data['target_cities'] ?? array(),
                'target_persona' => $data['target_persona'] ?? 'general',
                'has_cta' => (bool)($data['has_cta'] ?? false),
                'has_calculator' => (bool)($data['has_calculator'] ?? false),
                'has_lead_form' => (bool)($data['has_lead_form'] ?? false),
                'monetization_value' => intval($data['monetization_value'] ?? 5),
                'content_format' => $data['content_format'] ?? 'other',

                'updated_at' => current_time('mysql')
            );

            $this->log("Parallel: Cataloged post $post_id - " . $post->post_title);
        }

        curl_multi_close($multi_handle);

        return $results;
    }

    /**
     * Update link counts for all catalog entries (run periodically)
     */
    public function update_link_counts() {
        global $wpdb;

        $all_links = $this->get_all_site_links(5000);

        // Count inbound links per URL
        $inbound_counts = array();
        $outbound_counts = array();

        foreach ($all_links as $link) {
            $url = $link['url'];
            $source_id = $link['source_post_id'];

            if (!isset($inbound_counts[$url])) $inbound_counts[$url] = 0;
            $inbound_counts[$url]++;

            if (!isset($outbound_counts[$source_id])) $outbound_counts[$source_id] = 0;
            $outbound_counts[$source_id]++;
        }

        // Update catalog entries
        $catalog = $this->get_catalog();
        foreach ($catalog as $post_id => $entry) {
            $inbound = isset($inbound_counts[$entry['url']]) ? $inbound_counts[$entry['url']] : 0;
            $outbound = isset($outbound_counts[$post_id]) ? $outbound_counts[$post_id] : 0;

            // Calculate link gap priority (higher = needs more links)
            $gap_priority = 50;
            if ($inbound == 0) $gap_priority = 100;
            elseif ($inbound <= 2) $gap_priority = 80;
            elseif ($inbound <= 5) $gap_priority = 60;
            elseif ($inbound > 10) $gap_priority = 20;

            // Boost priority for high-value pages
            if ($entry['is_page'] || $entry['monetization_value'] >= 8) {
                $gap_priority = min(100, $gap_priority + 20);
            }

            $wpdb->update(
                $this->table_name,
                array(
                    'inbound_link_count' => $inbound,
                    'outbound_link_count' => $outbound,
                    'link_gap_priority' => $gap_priority
                ),
                array('post_id' => $post_id)
            );
        }

        $this->log('Updated link counts for ' . count($catalog) . ' catalog entries');
    }

    // =========================================================================
    // SMART METADATA GENERATION v2 - Runs AFTER Linking Phase
    // =========================================================================

    /**
     * Generate smart SEO metadata using catalog data + inbound link analysis
     * This should be run AFTER linking is complete to get full anchor text data
     *
     * @param int $post_id The post ID to generate metadata for
     * @return array|WP_Error Metadata result or error
     */
    public function generate_smart_metadata($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('not_found', 'Post not found');
        }

        // 1. Get catalog entry for this post (enriched data)
        $catalog_entry = $this->get_catalog_entry($post_id);

        // 2. Get all inbound link anchor texts (what other posts use to link TO this)
        $inbound_anchors = $this->get_inbound_anchors($post_id);

        // 3. Get outbound anchors (what this post links to - contextual)
        $outbound_links = get_post_meta($post_id, $this->link_meta_key, true) ?: array();
        $outbound_anchors = array();
        foreach ($outbound_links as $link) {
            if (!empty($link['anchor'])) {
                $outbound_anchors[] = $link['anchor'];
            }
        }

        // 4. Get target keywords (for both pages AND posts)
        $target_keywords = get_post_meta($post_id, $this->keywords_meta_key, true);

        // 5. Get existing site tags for consistency
        $existing_tags = get_tags(array('hide_empty' => false, 'number' => 100));
        $existing_tag_names = array_column($existing_tags ?: array(), 'name');

        // 6. Content preview
        $content = wp_strip_all_tags($post->post_content);
        $content = substr($content, 0, 1500);

        // Build comprehensive prompt
        $prompt = $this->build_smart_metadata_prompt(
            $post,
            $catalog_entry,
            $inbound_anchors,
            $outbound_anchors,
            $target_keywords,
            $existing_tag_names,
            $content
        );

        // Call Claude API
        $api = new LendCity_Claude_API();
        $response = $api->simple_completion($prompt, 800);

        if (!$response) {
            return new WP_Error('api_error', 'API request failed');
        }

        // Parse response
        $result = json_decode($response, true);
        if (!$result && preg_match('/\{.*\}/s', $response, $matches)) {
            $result = json_decode($matches[0], true);
        }

        if (!$result || !isset($result['title'])) {
            return new WP_Error('parse_error', 'Invalid API response: ' . substr($response, 0, 200));
        }

        return array(
            'title' => $result['title'] ?? '',
            'description' => $result['description'] ?? '',
            'tags' => $result['tags'] ?? array(),
            'focus_keyphrase' => $result['focus_keyphrase'] ?? '',
            'reasoning' => $result['reasoning'] ?? '',
            'catalog_used' => !empty($catalog_entry),
            'inbound_anchors_count' => count($inbound_anchors),
            'outbound_anchors_count' => count($outbound_anchors)
        );
    }

    /**
     * Get all anchor texts from links pointing TO this post
     *
     * @param int $post_id Target post ID
     * @return array List of anchor texts
     */
    public function get_inbound_anchors($post_id) {
        $post_url = get_permalink($post_id);
        $all_links = $this->get_all_site_links(5000);

        $inbound_anchors = array();
        foreach ($all_links as $link) {
            if ($link['url'] === $post_url && !empty($link['anchor'])) {
                $inbound_anchors[] = $link['anchor'];
            }
        }

        return $inbound_anchors;
    }

    /**
     * Build the smart metadata prompt using all available data
     */
    private function build_smart_metadata_prompt($post, $catalog_entry, $inbound_anchors, $outbound_anchors, $target_keywords, $existing_tags, $content) {
        $type_label = $post->post_type === 'page' ? 'PAGE' : 'ARTICLE';

        $prompt = "Generate highly optimized SEO metadata for this mortgage/real estate {$type_label}.\n\n";
        $prompt .= "=== CONTENT INFO ===\n";
        $prompt .= "Title: {$post->post_title}\n";
        $prompt .= "Type: {$type_label}\n";
        $prompt .= "Content Preview: {$content}\n\n";

        // Add enriched catalog data if available
        if (!empty($catalog_entry)) {
            $prompt .= "=== SEMANTIC ANALYSIS (from our content catalog) ===\n";

            if (!empty($catalog_entry['summary'])) {
                $prompt .= "Summary: {$catalog_entry['summary']}\n";
            }
            if (!empty($catalog_entry['topic_cluster'])) {
                $prompt .= "Topic Cluster: {$catalog_entry['topic_cluster']}\n";
            }
            if (!empty($catalog_entry['funnel_stage'])) {
                $prompt .= "Funnel Stage: {$catalog_entry['funnel_stage']} (awareness/consideration/decision)\n";
            }
            if (!empty($catalog_entry['reader_intent'])) {
                $prompt .= "Reader Intent: {$catalog_entry['reader_intent']}\n";
            }
            if (!empty($catalog_entry['target_persona'])) {
                $prompt .= "Target Persona: {$catalog_entry['target_persona']}\n";
            }
            if (!empty($catalog_entry['difficulty_level'])) {
                $prompt .= "Difficulty Level: {$catalog_entry['difficulty_level']}\n";
            }
            if (!empty($catalog_entry['content_format'])) {
                $prompt .= "Content Format: {$catalog_entry['content_format']}\n";
            }
            if (!empty($catalog_entry['content_lifespan'])) {
                $prompt .= "Content Lifespan: {$catalog_entry['content_lifespan']}\n";
            }

            // Array fields (already decoded by hydrate_catalog_entry)
            if (!empty($catalog_entry['main_topics']) && is_array($catalog_entry['main_topics'])) {
                $prompt .= "Main Topics: " . implode(', ', $catalog_entry['main_topics']) . "\n";
            }
            if (!empty($catalog_entry['semantic_keywords']) && is_array($catalog_entry['semantic_keywords'])) {
                $prompt .= "Semantic Keywords: " . implode(', ', array_slice($catalog_entry['semantic_keywords'], 0, 15)) . "\n";
            }
            if (!empty($catalog_entry['target_regions']) && is_array($catalog_entry['target_regions'])) {
                $prompt .= "Target Regions: " . implode(', ', $catalog_entry['target_regions']) . "\n";
            }
            $prompt .= "\n";
        }

        // Add inbound link anchors (CRITICAL - this shows what others think this page is about)
        if (!empty($inbound_anchors)) {
            $unique_anchors = array_unique($inbound_anchors);
            $anchor_counts = array_count_values($inbound_anchors);
            arsort($anchor_counts);

            $prompt .= "=== INBOUND LINK SIGNALS (how other content links TO this) ===\n";
            $prompt .= "These anchor texts reveal what other content thinks this page is about:\n";

            $top_anchors = array_slice($anchor_counts, 0, 10, true);
            foreach ($top_anchors as $anchor => $count) {
                $prompt .= "- \"{$anchor}\" (used {$count}x)\n";
            }
            $prompt .= "\n";
        }

        // Add outbound link context
        if (!empty($outbound_anchors)) {
            $prompt .= "=== OUTBOUND LINKS (what this content links to) ===\n";
            $prompt .= implode(', ', array_unique($outbound_anchors)) . "\n\n";
        }

        // Add target keywords for pages
        if (!empty($target_keywords)) {
            $prompt .= "=== PRIORITY KEYWORDS (manually specified) ===\n";
            $prompt .= $target_keywords . "\n\n";
        }

        // Add existing tags
        if (!empty($existing_tags)) {
            $prompt .= "=== EXISTING SITE TAGS (prefer these when relevant) ===\n";
            $prompt .= implode(', ', array_slice($existing_tags, 0, 50)) . "\n\n";
        }

        // Instructions
        $prompt .= "=== INSTRUCTIONS ===\n";
        $prompt .= "Generate SEO metadata that:\n";
        $prompt .= "1. SEO Title (50-60 chars): Must include the most-used inbound anchor phrases when available\n";
        $prompt .= "2. Meta Description (150-160 chars): Compelling description incorporating semantic keywords\n";
        $prompt .= "3. Tags (8 max): Mix of existing site tags + new tags from semantic keywords\n";
        $prompt .= "4. Focus Keyphrase: The primary keyword this page should rank for\n\n";

        $prompt .= "CRITICAL RULES:\n";
        $prompt .= "- If inbound anchors exist, they reveal WHAT USERS ARE LOOKING FOR - prioritize these!\n";
        $prompt .= "- Match the funnel stage: awareness=educational, consideration=comparative, decision=action-oriented\n";
        $prompt .= "- Match the persona voice: first_time_buyer=simple, investor=ROI-focused, professional=technical\n";
        $prompt .= "- Keep everything EVERGREEN - no dates or time-sensitive references\n";
        $prompt .= "- For Canadian real estate: include 'Canada' or 'Canadian' naturally\n\n";

        $prompt .= "Return ONLY valid JSON:\n";
        $prompt .= '{"title":"...","description":"...","tags":["tag1","tag2"],"focus_keyphrase":"...","reasoning":"brief explanation of choices"}' . "\n";

        return $prompt;
    }

    /**
     * Bulk generate smart metadata for all posts with links
     * Returns list of post IDs that need processing
     *
     * @param bool $only_linked Only include posts that have inbound or outbound links
     * @return array List of post IDs to process
     */
    public function get_posts_for_smart_metadata($only_linked = true) {
        $posts_to_process = array();

        if ($only_linked) {
            // Get all posts with outbound links
            $posts_with_outbound = get_posts(array(
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'meta_query' => array(
                    array(
                        'key' => $this->link_meta_key,
                        'compare' => 'EXISTS'
                    )
                ),
                'fields' => 'ids'
            ));

            // Get all posts/pages with inbound links
            $all_links = $this->get_all_site_links(5000);
            $posts_with_inbound = array();
            foreach ($all_links as $link) {
                $linked_post_id = url_to_postid($link['url']);
                if ($linked_post_id) {
                    $posts_with_inbound[$linked_post_id] = true;
                }
            }

            // Combine unique IDs
            $posts_to_process = array_unique(array_merge(
                $posts_with_outbound,
                array_keys($posts_with_inbound)
            ));
        } else {
            // All published posts and pages
            $posts_to_process = get_posts(array(
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
        }

        return $posts_to_process;
    }

    // =========================================================================
    // METADATA QUEUE SYSTEM - Persistent background processing
    // =========================================================================

    /**
     * Initialize metadata queue for background processing
     *
     * @param array $post_ids List of post IDs to process
     * @param bool $skip_existing Skip posts with existing SEO metadata
     * @return array Queue status
     */
    public function init_meta_queue($post_ids, $skip_existing = false) {
        if (empty($post_ids)) {
            return array('success' => false, 'message' => 'No posts to process');
        }

        // Filter out posts with existing metadata if requested
        if ($skip_existing) {
            $post_ids = array_filter($post_ids, function($post_id) {
                $title = get_post_meta($post_id, '_seopress_titles_title', true);
                $desc = get_post_meta($post_id, '_seopress_titles_desc', true);
                return empty($title) && empty($desc);
            });
            $post_ids = array_values($post_ids);
        }

        if (empty($post_ids)) {
            return array('success' => false, 'message' => 'All posts already have SEO metadata');
        }

        $queue_data = array(
            'pending' => $post_ids,
            'completed' => array(),
            'failed' => array(),
            'total' => count($post_ids),
            'started_at' => current_time('mysql'),
            'skip_existing' => $skip_existing
        );

        update_option($this->meta_queue_option, $queue_data);
        update_option($this->meta_queue_status_option, 'running');

        // Schedule first batch
        if (!wp_next_scheduled('lendcity_process_meta_queue')) {
            wp_schedule_single_event(time() + 2, 'lendcity_process_meta_queue');
        }

        return array(
            'success' => true,
            'total' => count($post_ids),
            'message' => 'Meta queue initialized with ' . count($post_ids) . ' posts'
        );
    }

    /**
     * Process a batch of metadata queue items
     */
    public function process_meta_queue_batch() {
        $queue = get_option($this->meta_queue_option, array());
        $status = get_option($this->meta_queue_status_option, 'idle');

        if ($status !== 'running' || empty($queue['pending'])) {
            update_option($this->meta_queue_status_option, 'idle');
            return;
        }

        // Process batch of 3 posts per cron run
        $batch_size = 3;
        $batch = array_splice($queue['pending'], 0, $batch_size);

        foreach ($batch as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $queue['failed'][] = array('id' => $post_id, 'error' => 'Post not found');
                continue;
            }

            // Generate smart metadata
            $result = $this->generate_smart_metadata($post_id);

            if (is_wp_error($result)) {
                $queue['failed'][] = array('id' => $post_id, 'error' => $result->get_error_message());
            } elseif (isset($result['error'])) {
                $queue['failed'][] = array('id' => $post_id, 'error' => $result['error']);
            } else {
                // Apply metadata
                if (!empty($result['title'])) {
                    update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($result['title']));
                }
                if (!empty($result['description'])) {
                    update_post_meta($post_id, '_seopress_titles_desc', sanitize_textarea_field($result['description']));
                }
                if (!empty($result['focus_keyphrase'])) {
                    update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($result['focus_keyphrase']));
                }
                if (!empty($result['tags']) && is_array($result['tags'])) {
                    wp_set_post_tags($post_id, $result['tags'], false);
                }

                $queue['completed'][] = $post_id;
            }
        }

        // Update queue
        update_option($this->meta_queue_option, $queue);

        // Schedule next batch if more items pending
        if (!empty($queue['pending'])) {
            wp_schedule_single_event(time() + 3, 'lendcity_process_meta_queue');
        } else {
            update_option($this->meta_queue_status_option, 'completed');
        }
    }

    /**
     * Get current metadata queue status
     *
     * @return array Queue status with counts
     */
    public function get_meta_queue_status() {
        $queue = get_option($this->meta_queue_option, array());
        $status = get_option($this->meta_queue_status_option, 'idle');

        if (empty($queue)) {
            return array(
                'status' => 'idle',
                'pending' => 0,
                'completed' => 0,
                'failed' => 0,
                'total' => 0,
                'percent' => 0
            );
        }

        $pending = count($queue['pending'] ?? array());
        $completed = count($queue['completed'] ?? array());
        $failed = count($queue['failed'] ?? array());
        $total = $queue['total'] ?? 0;

        return array(
            'status' => $status,
            'pending' => $pending,
            'completed' => $completed,
            'failed' => $failed,
            'failed_details' => $queue['failed'] ?? array(),
            'total' => $total,
            'percent' => $total > 0 ? round(($completed + $failed) / $total * 100) : 0,
            'started_at' => $queue['started_at'] ?? ''
        );
    }

    /**
     * Clear metadata queue
     */
    public function clear_meta_queue() {
        delete_option($this->meta_queue_option);
        update_option($this->meta_queue_status_option, 'idle');
        wp_clear_scheduled_hook('lendcity_process_meta_queue');
        return array('success' => true);
    }

    // =========================================================================
    // SEO HEALTH MONITOR - Paginated scanning
    // =========================================================================

    private $seo_health_scan_option = 'lendcity_seo_health_scan';

    /**
     * Start or continue SEO health scan (paginated)
     * Processes links in batches to avoid memory exhaustion
     *
     * @param bool $reset Start fresh scan
     * @return array Scan status with progress
     */
    public function scan_seo_health_batch($reset = false) {
        $scan_data = get_option($this->seo_health_scan_option, array());

        // Initialize or reset scan
        if ($reset || empty($scan_data)) {
            $total_posts = $this->get_posts_with_links_count();
            $scan_data = array(
                'status' => 'scanning',
                'offset' => 0,
                'total_posts' => $total_posts,
                'anchors_by_url' => array(), // url => array of anchors
                'processed_posts' => 0
            );
        }

        if ($scan_data['status'] === 'complete') {
            return $this->get_seo_health_results($scan_data);
        }

        // Process one batch (50 posts at a time)
        $batch = $this->get_links_batch(50, $scan_data['offset']);

        foreach ($batch['links'] as $link) {
            $url = $link['url'];
            $anchor = strtolower(trim($link['anchor']));

            if (!isset($scan_data['anchors_by_url'][$url])) {
                $scan_data['anchors_by_url'][$url] = array();
            }
            $scan_data['anchors_by_url'][$url][] = $anchor;
        }

        $scan_data['offset'] = $batch['next_offset'];
        $scan_data['processed_posts'] += 50;

        // Check if complete
        if (!$batch['has_more']) {
            $scan_data['status'] = 'complete';
        }

        update_option($this->seo_health_scan_option, $scan_data);

        // Return progress or final results
        if ($scan_data['status'] === 'complete') {
            return $this->get_seo_health_results($scan_data);
        }

        $percent = $scan_data['total_posts'] > 0
            ? min(99, round(($scan_data['processed_posts'] / $scan_data['total_posts']) * 100))
            : 0;

        return array(
            'status' => 'scanning',
            'percent' => $percent,
            'processed' => $scan_data['processed_posts'],
            'total' => $scan_data['total_posts']
        );
    }

    /**
     * Process scan data into final SEO health results
     */
    private function get_seo_health_results($scan_data) {
        $issues = array();

        foreach ($scan_data['anchors_by_url'] as $url => $anchors) {
            $post_id = url_to_postid($url);
            if (!$post_id) continue;

            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') continue;

            // Get current SEO data
            $current_title = get_post_meta($post_id, '_seopress_titles_title', true);
            $current_desc = get_post_meta($post_id, '_seopress_titles_desc', true);
            $current_focus = get_post_meta($post_id, '_seopress_analysis_target_kw', true);

            // Analyze anchor patterns
            $anchor_counts = array_count_values($anchors);
            arsort($anchor_counts);
            $top_anchors = array_slice($anchor_counts, 0, 5, true);
            $most_used_anchor = key($top_anchors);
            $anchor_count = current($top_anchors);

            // Check if most-used anchor is missing from SEO title
            $title_lower = strtolower($current_title);
            $missing_from_title = !empty($most_used_anchor) &&
                                   $anchor_count >= 2 &&
                                   strpos($title_lower, $most_used_anchor) === false;

            // Check for SEO gaps
            $has_issue = false;
            $issue_types = array();
            $suggestions = array();

            // Issue: High-frequency anchor not in title
            if ($missing_from_title && $anchor_count >= 3) {
                $has_issue = true;
                $issue_types[] = 'anchor_not_in_title';
                $suggestions[] = "Add '{$most_used_anchor}' to SEO title (used {$anchor_count}x in inbound links)";
            }

            // Issue: No SEO title set but has inbound links
            if (empty($current_title) && count($anchors) >= 2) {
                $has_issue = true;
                $issue_types[] = 'missing_seo_title';
                $suggestions[] = "Create SEO title based on anchor patterns: " . implode(', ', array_keys($top_anchors));
            }

            // Issue: No meta description but significant inbound links
            if (empty($current_desc) && count($anchors) >= 3) {
                $has_issue = true;
                $issue_types[] = 'missing_meta_desc';
                $suggestions[] = "Add meta description incorporating top anchor themes";
            }

            // Issue: Focus keyword doesn't match anchor patterns
            if (!empty($current_focus) && !empty($most_used_anchor) && $anchor_count >= 3) {
                if (strpos(strtolower($current_focus), $most_used_anchor) === false &&
                    strpos($most_used_anchor, strtolower($current_focus)) === false) {
                    $has_issue = true;
                    $issue_types[] = 'focus_mismatch';
                    $suggestions[] = "Consider updating focus keyphrase to '{$most_used_anchor}' (most-used anchor)";
                }
            }

            if ($has_issue) {
                $issues[] = array(
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                    'post_type' => $post->post_type,
                    'url' => $url,
                    'inbound_link_count' => count($anchors),
                    'top_anchors' => $top_anchors,
                    'current_seo' => array(
                        'title' => $current_title,
                        'description' => $current_desc,
                        'focus_keyphrase' => $current_focus
                    ),
                    'issue_types' => $issue_types,
                    'suggestions' => $suggestions,
                    'severity' => count($issue_types) >= 2 ? 'high' : 'medium'
                );
            }
        }

        // Sort by severity and link count
        usort($issues, function($a, $b) {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'high' ? -1 : 1;
            }
            return $b['inbound_link_count'] - $a['inbound_link_count'];
        });

        return array(
            'status' => 'complete',
            'percent' => 100,
            'issues' => $issues,
            'count' => count($issues)
        );
    }

    /**
     * Clear SEO health scan data
     */
    public function clear_seo_health_scan() {
        delete_option($this->seo_health_scan_option);
        return array('success' => true);
    }

    /**
     * Get SEO health issues (wrapper for compatibility)
     */
    public function get_seo_health_issues() {
        $scan_data = get_option($this->seo_health_scan_option, array());
        if (!empty($scan_data) && $scan_data['status'] === 'complete') {
            $results = $this->get_seo_health_results($scan_data);
            return $results['issues'];
        }
        return array();
    }

    /**
     * Auto-fix SEO for a specific post based on health analysis
     *
     * @param int $post_id Post ID to fix
     * @return array Result with changes made
     */
    public function auto_fix_seo($post_id) {
        $result = $this->generate_smart_metadata($post_id);

        if (is_wp_error($result)) {
            return array('success' => false, 'error' => $result->get_error_message());
        }

        if (isset($result['error'])) {
            return array('success' => false, 'error' => $result['error']);
        }

        $changes = array();

        if (!empty($result['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($result['title']));
            $changes[] = 'title';
        }
        if (!empty($result['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', sanitize_textarea_field($result['description']));
            $changes[] = 'description';
        }
        if (!empty($result['focus_keyphrase'])) {
            update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($result['focus_keyphrase']));
            $changes[] = 'focus_keyphrase';
        }
        if (!empty($result['tags']) && is_array($result['tags'])) {
            wp_set_post_tags($post_id, $result['tags'], false);
            $changes[] = 'tags';
        }

        return array(
            'success' => true,
            'post_id' => $post_id,
            'changes' => $changes,
            'new_seo' => $result
        );
    }

    // =========================================================================
    // GLOBAL KEYWORD OWNERSHIP SYSTEM
    // =========================================================================
    // Scans all pages once, determines which page should "own" each keyword,
    // then uses this map for all linking decisions. Prevents duplicate anchors
    // by design since each keyword only links to one designated page.

    /**
     * Build the global keyword ownership map from catalog data
     * Uses existing good_anchor_phrases - no API calls needed
     *
     * Scoring algorithm for ownership (PAGES GET PRIORITY):
     * - is_page: +100 points (PAGES WIN over posts)
     * - is_pillar_content: +30 points (pillar pages are authoritative)
     * - content_quality_score: 0-100 points
     * - monetization_value * 5: 0-50 points (high-value pages priority)
     * - link_gap_priority: 0-100 points (pages needing links get priority)
     * - has_cta or has_lead_form: +10 points (conversion pages)
     *
     * @param bool $force_rebuild Force rebuild even if map exists
     * @return array Ownership map with stats
     */
    public function build_keyword_ownership_map($force_rebuild = false) {
        $existing = get_option($this->keyword_ownership_option, array());

        if (!$force_rebuild && !empty($existing['map']) && !empty($existing['built_at'])) {
            // Return existing if less than 24 hours old
            $age = time() - strtotime($existing['built_at']);
            if ($age < 86400) {
                return $existing;
            }
        }

        $this->debug_log("Building global keyword ownership map...");

        // Get all catalog entries in batches to avoid memory issues
        global $wpdb;
        $batch_size = 100;
        $offset = 0;

        // keyword_normalized => array('post_id' => X, 'url' => Y, 'score' => Z, 'anchor' => original)
        $keyword_map = array();
        $total_keywords = 0;
        $pages_processed = 0;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, url, title, good_anchor_phrases, main_topics, is_page, is_pillar_content,
                        content_quality_score, monetization_value, link_gap_priority,
                        has_cta, has_lead_form
                 FROM {$this->table_name}
                 ORDER BY post_id ASC
                 LIMIT %d OFFSET %d",
                $batch_size, $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $pages_processed++;
                $post_id = intval($row['post_id']);
                $url = $row['url'];
                $title = $row['title'] ?? '';

                // Calculate page authority score
                // PAGES GET TOP PRIORITY (+100 bonus) so they win keyword ownership over posts
                $score = 0;
                $score += $row['is_page'] ? 100 : 0;  // Pages get major priority
                $score += $row['is_pillar_content'] ? 30 : 0;
                $score += intval($row['content_quality_score'] ?? 50);
                $score += intval($row['monetization_value'] ?? 5) * 5;
                $score += intval($row['link_gap_priority'] ?? 50);
                $score += ($row['has_cta'] || $row['has_lead_form']) ? 10 : 0;

                // Collect all potential keywords from multiple sources
                $all_keywords = array();

                // 1. good_anchor_phrases (primary source)
                $anchors = json_decode($row['good_anchor_phrases'] ?? '[]', true) ?: array();
                foreach ($anchors as $anchor) {
                    if (!empty($anchor) && is_string($anchor)) {
                        $all_keywords[] = $anchor;
                    }
                }

                // 2. main_topics (additional keywords)
                $topics = json_decode($row['main_topics'] ?? '[]', true) ?: array();
                foreach ($topics as $topic) {
                    if (!empty($topic) && is_string($topic)) {
                        $all_keywords[] = $topic;
                    }
                }

                // 3. Page title (clean it up - remove site name, pipes, dashes at end)
                if (!empty($title)) {
                    $clean_title = preg_replace('/\s*[\|\-–—]\s*[^|\-–—]+$/', '', $title);
                    $clean_title = trim($clean_title);
                    if (strlen($clean_title) > 10 && strlen($clean_title) < 80) {
                        $all_keywords[] = $clean_title;
                    }
                }

                // 4. Target keywords from post meta (user-defined, HIGHEST priority)
                // These get a +1000 score bonus to ALWAYS win ownership
                $target_keywords = get_post_meta($post_id, $this->keywords_meta_key, true);
                $target_keyword_list = array();
                if (!empty($target_keywords)) {
                    // Target keywords can be comma-separated or newline-separated
                    $tk_list = preg_split('/[,\n]+/', $target_keywords);
                    foreach ($tk_list as $tk) {
                        $tk = trim($tk);
                        if (!empty($tk) && strlen($tk) >= 3) {
                            $target_keyword_list[] = $tk;
                        }
                    }
                }

                // Process regular keywords (from catalog)
                foreach ($all_keywords as $anchor) {
                    // Normalize: lowercase, trim, single spaces
                    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $anchor)));

                    // Skip if too short (less than 2 words) or too long (more than 8 words)
                    $word_count = str_word_count($normalized);
                    if ($word_count < 2 || $word_count > 8) continue;

                    // Skip common filler phrases
                    if (in_array($normalized, array('read more', 'click here', 'learn more', 'find out', 'get started'))) continue;

                    $total_keywords++;

                    // Check if this keyword already has an owner
                    if (isset($keyword_map[$normalized])) {
                        // Higher score wins ownership
                        if ($score > $keyword_map[$normalized]['score']) {
                            $keyword_map[$normalized] = array(
                                'post_id' => $post_id,
                                'url' => $url,
                                'score' => $score,
                                'anchor' => $anchor // Original case preserved
                            );
                        }
                    } else {
                        $keyword_map[$normalized] = array(
                            'post_id' => $post_id,
                            'url' => $url,
                            'score' => $score,
                            'anchor' => $anchor
                        );
                    }
                }

                // Process TARGET keywords with MASSIVE score bonus (+1000)
                // These ALWAYS override other ownership because user explicitly set them
                foreach ($target_keyword_list as $anchor) {
                    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $anchor)));

                    // Allow single words for target keywords (user explicitly set them)
                    if (strlen($normalized) < 2) continue;

                    $target_score = $score + 1000; // Massive bonus to always win

                    $total_keywords++;

                    // Target keywords ALWAYS win (check score but they have +1000)
                    if (isset($keyword_map[$normalized])) {
                        if ($target_score > $keyword_map[$normalized]['score']) {
                            $keyword_map[$normalized] = array(
                                'post_id' => $post_id,
                                'url' => $url,
                                'score' => $target_score,
                                'anchor' => $anchor,
                                'is_target_keyword' => true
                            );
                        }
                    } else {
                        $keyword_map[$normalized] = array(
                            'post_id' => $post_id,
                            'url' => $url,
                            'score' => $target_score,
                            'anchor' => $anchor,
                            'is_target_keyword' => true
                        );
                    }
                }

                // Memory cleanup
                unset($anchors, $topics, $all_keywords, $target_keyword_list);
            }

            $offset += $batch_size;
            unset($rows);

        } while (true);

        // Store the map
        $ownership_data = array(
            'map' => $keyword_map,
            'built_at' => current_time('mysql'),
            'stats' => array(
                'total_keywords' => count($keyword_map),
                'total_phrases_scanned' => $total_keywords,
                'pages_processed' => $pages_processed
            )
        );

        update_option($this->keyword_ownership_option, $ownership_data, false);

        $this->debug_log("Keyword ownership map built: " . count($keyword_map) . " unique keywords from {$pages_processed} pages");

        return $ownership_data;
    }

    /**
     * Get the owner of a specific keyword/phrase
     * Returns null if no owner found
     *
     * @param string $keyword The keyword to look up
     * @return array|null Owner data or null
     */
    public function get_keyword_owner($keyword) {
        $ownership = get_option($this->keyword_ownership_option, array());

        if (empty($ownership['map'])) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $keyword)));

        return $ownership['map'][$normalized] ?? null;
    }

    /**
     * Generate semantic variations of a keyword for fuzzy matching
     * Handles plurals, common suffixes, and word form variations
     *
     * @param string $keyword The keyword to generate variations for
     * @return array Array of variations including the original
     */
    private function generate_keyword_variations($keyword) {
        $variations = array($keyword);
        $words = explode(' ', $keyword);
        $last_word = end($words);

        // Generate variations of the last word (most common for plurals)
        $last_word_variations = array($last_word);

        // Plural/singular variations
        if (substr($last_word, -1) === 's' && strlen($last_word) > 3) {
            // Remove 's' for singular
            $last_word_variations[] = substr($last_word, 0, -1);
            // Handle 'ies' -> 'y' (properties -> property)
            if (substr($last_word, -3) === 'ies') {
                $last_word_variations[] = substr($last_word, 0, -3) . 'y';
            }
            // Handle 'es' -> '' (taxes -> tax)
            if (substr($last_word, -2) === 'es') {
                $last_word_variations[] = substr($last_word, 0, -2);
            }
        } else {
            // Add 's' for plural
            $last_word_variations[] = $last_word . 's';
            // Handle 'y' -> 'ies' (property -> properties)
            if (substr($last_word, -1) === 'y' && strlen($last_word) > 2) {
                $last_word_variations[] = substr($last_word, 0, -1) . 'ies';
            }
        }

        // Common suffix variations
        // -ing / -ment / -ion variations
        if (substr($last_word, -3) === 'ing') {
            $base = substr($last_word, 0, -3);
            $last_word_variations[] = $base; // invest
            $last_word_variations[] = $base . 'ment'; // investment
            $last_word_variations[] = $base . 'or'; // investor
            $last_word_variations[] = $base . 'er'; // invester
        } elseif (substr($last_word, -4) === 'ment') {
            $base = substr($last_word, 0, -4);
            $last_word_variations[] = $base; // invest
            $last_word_variations[] = $base . 'ing'; // investing
            $last_word_variations[] = $base . 'or'; // investor
        } elseif (substr($last_word, -2) === 'or' || substr($last_word, -2) === 'er') {
            $base = substr($last_word, 0, -2);
            $last_word_variations[] = $base; // invest
            $last_word_variations[] = $base . 'ing'; // investing
            $last_word_variations[] = $base . 'ment'; // investment
        }

        // -tion / -sion variations
        if (substr($last_word, -4) === 'tion' || substr($last_word, -4) === 'sion') {
            $base = substr($last_word, 0, -4);
            $last_word_variations[] = $base . 'te'; // calculate
            $last_word_variations[] = $base . 'ting'; // calculating
        }

        // Build full phrase variations
        foreach ($last_word_variations as $variant) {
            if ($variant !== $last_word) {
                $words_copy = $words;
                $words_copy[count($words_copy) - 1] = $variant;
                $variations[] = implode(' ', $words_copy);
            }
        }

        return array_unique($variations);
    }

    /**
     * Find matching keywords in text content with SEMANTIC/FUZZY matching
     * Returns keywords found in the text that have owners (excluding self)
     *
     * @param string $content The text to search
     * @param int $exclude_post_id Post ID to exclude (don't link to self)
     * @param int $limit Max keywords to return
     * @return array Matching keywords with their owners
     */
    public function find_owned_keywords_in_content($content, $exclude_post_id = 0, $limit = 8) {
        $ownership = get_option($this->keyword_ownership_option, array());

        if (empty($ownership['map'])) {
            return array();
        }

        $content_lower = strtolower($content);
        $matches = array();
        $matched_urls = array(); // Prevent duplicate URLs

        // Sort keywords by length (longer first) to match longer phrases before shorter ones
        $keywords = $ownership['map'];
        uksort($keywords, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($keywords as $normalized => $owner) {
            // Skip if this keyword points to the current post
            if ($owner['post_id'] == $exclude_post_id) {
                continue;
            }

            // Skip if we already matched this URL (avoid duplicate links)
            if (in_array($owner['url'], $matched_urls)) {
                continue;
            }

            // Generate semantic variations of the keyword
            $variations = $this->generate_keyword_variations($normalized);

            // Check if any variation exists in content
            $found_variation = null;
            foreach ($variations as $variant) {
                if (preg_match('/\b' . preg_quote($variant, '/') . '\b/i', $content_lower, $match)) {
                    $found_variation = $match[0]; // Use the actual matched text
                    break;
                }
            }

            if ($found_variation !== null) {
                $matches[] = array(
                    'keyword' => $normalized,
                    'anchor' => $owner['anchor'], // Use the canonical anchor from ownership
                    'matched_text' => $found_variation, // What was actually found
                    'target_url' => $owner['url'],
                    'target_post_id' => $owner['post_id'],
                    'score' => $owner['score']
                );
                $matched_urls[] = $owner['url'];

                if (count($matches) >= $limit) {
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Get keyword ownership statistics
     *
     * @return array Stats about the ownership map
     */
    public function get_keyword_ownership_stats() {
        $ownership = get_option($this->keyword_ownership_option, array());

        if (empty($ownership['map'])) {
            return array(
                'has_map' => false,
                'total_keywords' => 0,
                'pages_with_keywords' => 0,
                'built_at' => null
            );
        }

        // Count unique pages that own keywords
        $pages = array();
        foreach ($ownership['map'] as $data) {
            $pages[$data['post_id']] = true;
        }

        return array(
            'has_map' => true,
            'total_keywords' => count($ownership['map']),
            'pages_with_keywords' => count($pages),
            'built_at' => $ownership['built_at'] ?? null,
            'stats' => $ownership['stats'] ?? array()
        );
    }

    /**
     * Get keywords owned by a specific post
     *
     * @param int $post_id The post ID
     * @return array Keywords owned by this post
     */
    public function get_keywords_for_post($post_id) {
        $ownership = get_option($this->keyword_ownership_option, array());

        if (empty($ownership['map'])) {
            return array();
        }

        $post_keywords = array();
        foreach ($ownership['map'] as $normalized => $data) {
            if ($data['post_id'] == $post_id) {
                $post_keywords[] = array(
                    'keyword' => $normalized,
                    'anchor' => $data['anchor'],
                    'score' => $data['score']
                );
            }
        }

        // Sort by score descending
        usort($post_keywords, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return $post_keywords;
    }

    /**
     * Clear the keyword ownership map
     */
    public function clear_keyword_ownership_map() {
        delete_option($this->keyword_ownership_option);
        $this->debug_log("Keyword ownership map cleared");
    }

    /**
     * Get paginated view of keyword ownership for admin UI
     *
     * @param int $page Page number (1-based)
     * @param int $per_page Items per page
     * @param string $search Optional search term
     * @return array Paginated data
     */
    public function get_keyword_ownership_paginated($page = 1, $per_page = 50, $search = '') {
        $ownership = get_option($this->keyword_ownership_option, array());

        if (empty($ownership['map'])) {
            return array(
                'items' => array(),
                'total' => 0,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => 0
            );
        }

        $items = array();
        foreach ($ownership['map'] as $normalized => $data) {
            // Apply search filter
            if (!empty($search)) {
                if (stripos($normalized, $search) === false && stripos($data['anchor'], $search) === false) {
                    continue;
                }
            }

            $items[] = array(
                'keyword' => $normalized,
                'anchor' => $data['anchor'],
                'post_id' => $data['post_id'],
                'url' => $data['url'],
                'score' => $data['score']
            );
        }

        // Sort by score descending
        usort($items, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        $total = count($items);
        $total_pages = ceil($total / $per_page);
        $offset = ($page - 1) * $per_page;

        return array(
            'items' => array_slice($items, $offset, $per_page),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => $total_pages
        );
    }
}
