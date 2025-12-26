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
    private $catalog_cache = null;

    // SEO Enhancement meta keys
    private $priority_meta_key = '_lendcity_link_priority';
    private $keywords_meta_key = '_lendcity_target_keywords';

    // Database version for migrations
    const DB_VERSION = '4.1';
    const DB_VERSION_OPTION = 'lendcity_catalog_db_version';

    // Parallel processing settings
    private $parallel_batch_size = 5; // Posts per parallel request

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'lendcity_catalog';
        $this->api_key = get_option('lendcity_claude_api_key');

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
            'preferred_anchors' => "LONGTEXT"
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
     * Check if migration from wp_options is needed
     */
    public function needs_migration() {
        $old_catalog = get_option('lendcity_post_catalog', array());
        $table_count = $this->get_catalog_count();

        return !empty($old_catalog) && $table_count === 0;
    }

    /**
     * Migrate from old wp_options catalog to new database table
     */
    public function migrate_from_options() {
        global $wpdb;

        $old_catalog = get_option('lendcity_post_catalog', array());
        if (empty($old_catalog)) {
            return array('success' => true, 'migrated' => 0, 'message' => 'No data to migrate');
        }

        $migrated = 0;
        $errors = array();

        foreach ($old_catalog as $post_id => $entry) {
            $result = $this->insert_catalog_entry($post_id, $entry);
            if ($result) {
                $migrated++;
            } else {
                $errors[] = "Failed to migrate post $post_id";
            }
        }

        // Backup and remove old option
        if ($migrated > 0) {
            update_option('lendcity_post_catalog_backup_v2', $old_catalog);
            delete_option('lendcity_post_catalog');
            $this->log("Migrated $migrated catalog entries to database table");
        }

        return array(
            'success' => true,
            'migrated' => $migrated,
            'errors' => $errors
        );
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

        return $catalog;
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
     * Get catalog stats (fast count queries)
     */
    public function get_catalog_stats() {
        global $wpdb;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $posts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_page = 0");
        $pages = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_page = 1");
        $pillars = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_pillar_content = 1");
        $clusters = $wpdb->get_var("SELECT COUNT(DISTINCT topic_cluster) FROM {$this->table_name} WHERE topic_cluster IS NOT NULL");

        return array(
            'total' => intval($total),
            'posts' => intval($posts),
            'pages' => intval($pages),
            'pillars' => intval($pillars),
            'clusters' => intval($clusters)
        );
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
        $this->log('Cleared entire catalog');
    }

    /**
     * Clear the in-memory catalog cache
     */
    public function clear_catalog_cache() {
        $this->catalog_cache = null;
    }

    // =========================================================================
    // LOGGING HELPERS
    // =========================================================================

    private function debug_log($message) {
        if (get_option('lendcity_debug_mode', 'no') === 'yes') {
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

        // Create outgoing links
        $result = $this->create_links_from_source($post_id);
        $this->log('Auto-link result for post ' . $post_id . ' - ' .
            ($result['success'] ? 'Success: ' . ($result['links_created'] ?? 0) . ' links' : $result['message']));

        delete_transient($lock_key);
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

        // Get existing links
        $existing_links = get_post_meta($source_id, $this->link_meta_key, true) ?: array();
        $current_link_count = count($existing_links);

        if ($current_link_count >= 8) {
            return array('success' => false, 'message' => 'Source already has 8 links');
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

        $page_slots = max(0, 3 - $existing_page_links);
        $post_slots = max(0, 7 - ($current_link_count - $existing_page_links));

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

            if ($is_page && $page_links_added >= 3) continue;
            if (!$is_page && $post_links_added >= 7) continue;
            if (count($links_created) + $current_link_count >= 10) break;

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
     * Calculate relevance score between source and target using ALL v4.0 intelligence
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

        // Keyword overlap
        $source_keywords = $source['semantic_keywords'] ?? array();
        $target_keywords = $target['semantic_keywords'] ?? array();
        $keyword_overlap = count(array_intersect($source_keywords, $target_keywords));
        $score += min($keyword_overlap * 3, 15);

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
        // PERSONA MATCHING (0-30 points) - NEW v4.0
        // =================================================================

        $source_persona = $source['target_persona'] ?? 'general';
        $target_persona = $target['target_persona'] ?? 'general';

        if ($source_persona === $target_persona && $source_persona !== 'general') {
            $score += 30; // Same specific persona - very relevant!
        } elseif ($source_persona === 'general' || $target_persona === 'general') {
            $score += 10; // General content matches with anything
        }
        // Different specific personas = no bonus (avoid mixing investor/first-timer)

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

    /**
     * Build the intelligent linking prompt
     */
    private function build_linking_prompt($source, $source_entry, $source_content,
        $available_pages, $available_posts, $used_anchors, $page_slots, $post_slots) {

        $prompt = "You are an SEO expert creating internal links for a blog post.\n\n";
        $prompt .= "=== SOURCE POST ===\n";
        $prompt .= "Title: " . $source->post_title . "\n";
        $prompt .= "Topic Cluster: " . ($source_entry['topic_cluster'] ?? 'general') . "\n";
        $prompt .= "Funnel Stage: " . ($source_entry['funnel_stage'] ?? 'awareness') . "\n";
        $prompt .= "Difficulty: " . ($source_entry['difficulty_level'] ?? 'intermediate') . "\n";
        $prompt .= "Topics: " . implode(', ', $source_entry['main_topics'] ?? array()) . "\n";
        $prompt .= "Content:\n" . $source_content . "\n\n";

        if (!empty($used_anchors)) {
            $prompt .= "ALREADY USED ANCHORS (DO NOT USE):\n" . implode(', ', $used_anchors) . "\n\n";
        }

        $links_requested = array();

        if ($page_slots > 0 && !empty($available_pages)) {
            $prompt .= "=== AVAILABLE PAGES (max " . $page_slots . " links) ===\n";
            $count = 0;
            foreach ($available_pages as $id => $entry) {
                if ($count >= 8) break;
                $priority = $this->get_page_priority($id);
                $keywords = $this->get_page_keywords($id);
                $prompt .= "ID:" . $id . " | " . $entry['title'] . " | P:" . $priority;
                $prompt .= " | Cluster:" . ($entry['topic_cluster'] ?? 'none');
                if ($keywords) $prompt .= " | ANCHORS:" . $keywords;
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to $page_slots page links";
        }

        if ($post_slots > 0 && !empty($available_posts)) {
            $prompt .= "=== AVAILABLE POSTS (max " . $post_slots . " links) - SORTED BY RELEVANCE ===\n";
            $count = 0;
            foreach ($available_posts as $id => $entry) {
                if ($count >= 12) break;
                $prompt .= "ID:" . $id . " | " . $entry['title'];
                $prompt .= " | Cluster:" . ($entry['topic_cluster'] ?? 'none');
                $prompt .= " | Funnel:" . ($entry['funnel_stage'] ?? 'awareness');
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to $post_slots post links";
        }

        $prompt .= "=== TASK ===\n";
        $prompt .= "Find: " . implode(' + ', $links_requested) . "\n\n";
        $prompt .= "SMART LINKING RULES:\n";
        $prompt .= "1. Prefer targets in SAME or RELATED topic clusters\n";
        $prompt .= "2. Link to content that advances the reader's journey (awareness→consideration→decision)\n";
        $prompt .= "3. Anchor text: 2-5 words that EXIST in source content and describe target\n";
        $prompt .= "4. Each anchor must be UNIQUE - never duplicate\n";
        $prompt .= "5. Higher priority pages (P:4-5) should get links if relevant\n";
        $prompt .= "6. Spread links throughout article. Max 1 link per paragraph.\n";
        $prompt .= "7. Quality > quantity - skip if no good semantic match\n\n";
        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"target_id": 123, "anchor_text": "meaningful phrase", "is_page": true/false}, ...]\n';
        $prompt .= 'Return [] if no good opportunities.\n';

        return $prompt;
    }

    /**
     * REVERSED LOGIC: Find posts that should link TO a target page/post
     */
    public function create_links_to_target($target_id) {
        $target = get_post($target_id);
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }

        $target_entry = $this->get_catalog_entry($target_id);
        if (!$target_entry) {
            return array('success' => false, 'message' => 'Target not in catalog. Rebuild catalog.');
        }

        $catalog = $this->get_catalog();
        if (empty($catalog)) {
            return array('success' => false, 'message' => 'Catalog empty');
        }

        // Find eligible source posts
        $potential_sources = array();
        $used_anchors_by_post = array();

        foreach ($catalog as $id => $entry) {
            if ($id == $target_id) continue;
            if ($entry['is_page']) continue;
            if ($this->post_already_links_to($id, $target_entry['url'])) continue;

            $existing_links = get_post_meta($id, $this->link_meta_key, true) ?: array();
            if (count($existing_links) >= 8) continue;

            // Calculate relevance
            $entry['relevance_score'] = $this->calculate_relevance_score($entry, $target_entry);

            $used_anchors_by_post[$id] = array();
            foreach ($existing_links as $link) {
                $used_anchors_by_post[$id][] = strtolower($link['anchor']);
            }

            $potential_sources[$id] = $entry;
        }

        if (empty($potential_sources)) {
            return array('success' => false, 'message' => 'No eligible source posts');
        }

        // Sort by relevance
        uasort($potential_sources, function($a, $b) {
            return ($b['relevance_score'] ?? 0) - ($a['relevance_score'] ?? 0);
        });

        // Build prompt
        $prompt = "You are an SEO expert finding internal linking opportunities.\n\n";
        $prompt .= "=== TARGET PAGE ===\n";
        $prompt .= "Title: " . $target_entry['title'] . "\n";
        $prompt .= "URL: " . $target_entry['url'] . "\n";
        $prompt .= "Cluster: " . ($target_entry['topic_cluster'] ?? 'general') . "\n";
        $prompt .= "Topics: " . implode(', ', $target_entry['main_topics']) . "\n";
        $prompt .= "Summary: " . $target_entry['summary'] . "\n";
        $prompt .= "Good anchors: " . implode(', ', $target_entry['good_anchor_phrases']) . "\n\n";

        $prompt .= "=== SOURCE POSTS (sorted by relevance) ===\n";
        $count = 0;
        foreach ($potential_sources as $id => $entry) {
            if ($count >= 25) break;
            $prompt .= "ID:" . $id . " | " . $entry['title'] . "\n";
            $prompt .= "Cluster:" . ($entry['topic_cluster'] ?? 'none') . " | Topics:" . implode(', ', array_slice($entry['main_topics'], 0, 4)) . "\n";
            if (!empty($used_anchors_by_post[$id])) {
                $prompt .= "USED ANCHORS: " . implode(', ', $used_anchors_by_post[$id]) . "\n";
            }
            $prompt .= "\n";
            $count++;
        }

        $prompt .= "=== RULES ===\n";
        $prompt .= "1. Anchor MUST be COMPLETE, MEANINGFUL phrase describing target\n";
        $prompt .= "2. Good: 'real estate investing strategies', 'mortgage pre-approval'\n";
        $prompt .= "3. Bad: 'with the', 'investment with' (fragments - NEVER USE)\n";
        $prompt .= "4. Don't use anchors already used in a post\n";
        $prompt .= "5. Quality > quantity - skip if no good anchor\n\n";
        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"source_id": 123, "anchor_text": "meaningful phrase"}, ...]\n';

        $response = $this->call_claude_api($prompt, 1000);

        if (!$response['success']) {
            return array('success' => false, 'message' => 'API error: ' . $response['error']);
        }

        $suggestions = json_decode($response['text'], true);
        if (!$suggestions && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $suggestions = json_decode($matches[0], true);
        }

        if (!is_array($suggestions) || empty($suggestions)) {
            return array('success' => false, 'message' => 'No opportunities found');
        }

        // Insert links
        $links_created = array();
        $errors = array();

        foreach ($suggestions as $suggestion) {
            $source_id = intval($suggestion['source_id']);
            $anchor = sanitize_text_field($suggestion['anchor_text']);

            if (!$source_id || !$anchor) continue;

            $result = $this->insert_link_in_post($source_id, $anchor, $target_entry['url'], $target_id, $target_entry['is_page']);

            if ($result['success']) {
                $links_created[] = array(
                    'source_id' => $source_id,
                    'source_title' => get_the_title($source_id),
                    'anchor' => $anchor,
                    'target_url' => $target_entry['url']
                );
            } else {
                $errors[] = "Post $source_id: " . $result['message'];
            }
        }

        return array(
            'success' => true,
            'links_created' => count($links_created),
            'links' => $links_created,
            'errors' => $errors,
            'target_title' => $target_entry['title']
        );
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
                $existing_links = $this->get_post_links($id);
                if (!empty($existing_links)) {
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
            'batch_size' => 3
        );
        update_option($this->queue_status_option, $status, false);

        if (count($queue_ids) > 0 && !wp_next_scheduled('lendcity_process_link_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_link_queue');
            $this->debug_log('Scheduled queue cron for ' . count($queue_ids) . ' items');
        }

        return array('queued' => count($queue_ids), 'skipped' => $skipped);
    }

    /**
     * Process queue batch
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
            delete_transient($lock_key);
            return array('complete' => true);
        }

        $batch_size = isset($status['batch_size']) ? $status['batch_size'] : 3;
        $processed = 0;
        $links = 0;

        while ($processed < $batch_size && !empty($queue)) {
            $post_id = array_shift($queue);
            update_option($this->queue_option, $queue, false);

            $post_title = get_the_title($post_id);
            $status['current_post'] = $post_title;
            $status['last_activity'] = current_time('mysql');
            update_option($this->queue_status_option, $status, false);

            $result = $this->create_links_from_source($post_id);

            $status['processed']++;
            $processed++;

            if ($result['success']) {
                $count = isset($result['links_created']) ? $result['links_created'] : 0;
                $status['links_created'] += $count;
                $links += $count;
                $this->log('Queue: Processed ' . $post_id . ' - ' . $count . ' links');
            } else {
                $status['errors']++;
            }

            update_option($this->queue_status_option, $status, false);
        }

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
    }

    /**
     * Resume queue
     */
    public function resume_queue() {
        $status = get_option($this->queue_status_option, array());
        $status['state'] = 'running';
        unset($status['paused_at']);
        update_option($this->queue_status_option, $status, false);
        $this->trigger_background_process();
    }

    /**
     * Clear queue
     */
    public function clear_queue() {
        delete_option($this->queue_option);
        delete_option($this->queue_status_option);
        delete_option('lendcity_queue_token');
        wp_clear_scheduled_hook('lendcity_process_link_queue');
        wp_clear_scheduled_hook('lendcity_process_queue_batch');
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
        return $updated;
    }

    /**
     * Get all Claude links across site
     */
    public function get_all_site_links($limit = 0) {
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

                    if ($limit > 0 && count($all_links) >= $limit) {
                        return $all_links;
                    }
                }
            }
        }
        return $all_links;
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
     * Get link distribution stats
     */
    public function get_link_stats() {
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

        return array(
            'total_items' => count($catalog),
            'total_links' => count($all_links),
            'zero_links' => $zero_links,
            'pages_zero' => $pages_zero,
            'posts_zero' => $posts_zero,
            'one_to_three' => $one_to_three,
            'four_to_ten' => $four_to_ten,
            'over_ten' => $over_ten
        );
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
                'model' => 'claude-sonnet-4-20250514',
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
            $this->log('API Error: ' . $error);
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
                    'model' => 'claude-sonnet-4-20250514',
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
}
