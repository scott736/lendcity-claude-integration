<?php
/**
 * Catalog Database Class v1.0
 *
 * ============================================================================
 * PERFORMANCE OPTIMIZATION NOTES (for future reference)
 * ============================================================================
 *
 * This class replaces the old serialized wp_options storage with a dedicated
 * database table. Key improvements:
 *
 * 1. SCALABILITY: Each post gets its own row instead of one giant serialized
 *    array. This means:
 *    - Loading 1 entry = 1 small query (not loading 2MB of data)
 *    - Updating 1 entry = 1 small UPDATE (not rewriting 2MB)
 *    - Works efficiently with 1000+ posts
 *
 * 2. INDEXING: The table has indexes on post_id for fast lookups:
 *    - get_entry($post_id) is O(1) via index
 *    - Bulk operations use batch queries
 *
 * 3. IN-MEMORY CACHE: Within a single request, entries are cached in
 *    $this->cache to avoid repeated database queries. The cache is:
 *    - Populated on first access
 *    - Updated on write operations
 *    - Cleared via clear_cache() if needed
 *
 * 4. JSON STORAGE: Array fields (topics, keywords, etc.) are stored as JSON
 *    in the database. This is more efficient than PHP serialize() and is
 *    natively supported by MySQL.
 *
 * MIGRATION: On first use, existing data from wp_options is automatically
 * migrated to the new table. The old option is kept as backup but can be
 * deleted after confirming migration success.
 *
 * TABLE STRUCTURE:
 * - id: Auto-increment primary key
 * - post_id: WordPress post ID (indexed, unique)
 * - post_type: 'post' or 'page'
 * - is_page: Boolean for quick filtering
 * - title: Post title (for quick access without JOIN)
 * - url: Full permalink (for quick access without computation)
 * - summary: AI-generated summary (longtext)
 * - main_topics: JSON array
 * - semantic_keywords: JSON array
 * - entities: JSON array
 * - content_themes: JSON array
 * - good_anchor_phrases: JSON array
 * - updated_at: Timestamp of last update
 *
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

class LendCity_Catalog_DB {

    /**
     * Table name (with WordPress prefix)
     */
    private $table_name;

    /**
     * In-memory cache for current request
     * Structure: array( post_id => entry_array, ... )
     *
     * NOTE: This cache persists only for the current PHP request.
     * Each page load starts with an empty cache.
     */
    private $cache = array();

    /**
     * Flag to track if full catalog has been loaded into cache
     */
    private $full_cache_loaded = false;

    /**
     * Old option name for migration
     */
    private $legacy_option = 'lendcity_post_catalog';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'lendcity_catalog';

        // Ensure table exists on first use
        $this->maybe_create_table();
    }

    /**
     * Create the database table if it doesn't exist
     *
     * IMPORTANT: This uses dbDelta which is WordPress's safe way to create/update
     * tables. It will:
     * - Create the table if it doesn't exist
     * - Add missing columns if table exists but schema changed
     * - NOT drop existing data
     */
    public function maybe_create_table() {
        global $wpdb;

        // Check if table exists (cached check for performance)
        $table_exists = get_transient('lendcity_catalog_table_exists');
        if ($table_exists === 'yes') {
            return;
        }

        // Actually check the database
        $table_exists_check = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
        );

        if ($table_exists_check === $this->table_name) {
            set_transient('lendcity_catalog_table_exists', 'yes', HOUR_IN_SECONDS);

            // Check if migration needed
            $this->maybe_migrate_from_options();
            return;
        }

        // Create the table
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(20) NOT NULL DEFAULT 'post',
            is_page tinyint(1) NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL DEFAULT '',
            url varchar(500) NOT NULL DEFAULT '',
            summary longtext,
            main_topics longtext,
            semantic_keywords longtext,
            entities longtext,
            content_themes longtext,
            good_anchor_phrases longtext,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY is_page (is_page),
            KEY post_type (post_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Cache that table exists
        set_transient('lendcity_catalog_table_exists', 'yes', HOUR_IN_SECONDS);

        // Migrate existing data from options
        $this->maybe_migrate_from_options();

        error_log('LendCity Catalog DB: Created table ' . $this->table_name);
    }

    /**
     * Migrate data from old wp_options storage to new table
     *
     * This runs once on first use after table creation. It:
     * 1. Loads the old serialized catalog from wp_options
     * 2. Inserts each entry into the new table
     * 3. Keeps the old option as backup (lendcity_post_catalog_backup)
     */
    private function maybe_migrate_from_options() {
        // Check if already migrated
        if (get_option('lendcity_catalog_migrated', 'no') === 'yes') {
            return;
        }

        $old_catalog = get_option($this->legacy_option, array());
        if (empty($old_catalog) || !is_array($old_catalog)) {
            // No data to migrate
            update_option('lendcity_catalog_migrated', 'yes');
            return;
        }

        error_log('LendCity Catalog DB: Migrating ' . count($old_catalog) . ' entries from wp_options');

        $migrated = 0;
        foreach ($old_catalog as $post_id => $entry) {
            if ($this->save_entry($post_id, $entry)) {
                $migrated++;
            }
        }

        // Backup old data and mark as migrated
        update_option('lendcity_post_catalog_backup', $old_catalog);
        update_option('lendcity_catalog_migrated', 'yes');

        // Note: We keep the old option for now as backup
        // It can be deleted manually later: delete_option('lendcity_post_catalog');

        error_log('LendCity Catalog DB: Migration complete. Migrated ' . $migrated . ' of ' . count($old_catalog) . ' entries');
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * Get a single catalog entry by post ID
     *
     * Uses in-memory cache to avoid repeated queries within same request.
     *
     * @param int $post_id The WordPress post ID
     * @return array|null The catalog entry or null if not found
     */
    public function get_entry($post_id) {
        $post_id = intval($post_id);

        // Check cache first
        if (isset($this->cache[$post_id])) {
            return $this->cache[$post_id];
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE post_id = %d",
                $post_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        // Convert DB row to entry format
        $entry = $this->row_to_entry($row);

        // Cache it
        $this->cache[$post_id] = $entry;

        return $entry;
    }

    /**
     * Save a catalog entry (insert or update)
     *
     * @param int $post_id The WordPress post ID
     * @param array $entry The catalog entry data
     * @return bool Success status
     */
    public function save_entry($post_id, $entry) {
        global $wpdb;
        $post_id = intval($post_id);

        // Prepare data for database
        $data = array(
            'post_id' => $post_id,
            'post_type' => isset($entry['type']) ? $entry['type'] : 'post',
            'is_page' => !empty($entry['is_page']) ? 1 : 0,
            'title' => isset($entry['title']) ? $entry['title'] : '',
            'url' => isset($entry['url']) ? $entry['url'] : '',
            'summary' => isset($entry['summary']) ? $entry['summary'] : '',
            'main_topics' => wp_json_encode(isset($entry['main_topics']) ? $entry['main_topics'] : array()),
            'semantic_keywords' => wp_json_encode(isset($entry['semantic_keywords']) ? $entry['semantic_keywords'] : array()),
            'entities' => wp_json_encode(isset($entry['entities']) ? $entry['entities'] : array()),
            'content_themes' => wp_json_encode(isset($entry['content_themes']) ? $entry['content_themes'] : array()),
            'good_anchor_phrases' => wp_json_encode(isset($entry['good_anchor_phrases']) ? $entry['good_anchor_phrases'] : array()),
            'updated_at' => current_time('mysql'),
        );

        $formats = array('%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        // Check if entry exists
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->table_name} WHERE post_id = %d", $post_id)
        );

        if ($exists) {
            // Update existing
            $result = $wpdb->update(
                $this->table_name,
                $data,
                array('post_id' => $post_id),
                $formats,
                array('%d')
            );
        } else {
            // Insert new
            $result = $wpdb->insert($this->table_name, $data, $formats);
        }

        if ($result !== false) {
            // Update cache
            $entry['post_id'] = $post_id;
            $entry['updated_at'] = $data['updated_at'];
            $this->cache[$post_id] = $entry;
            return true;
        }

        return false;
    }

    /**
     * Delete a catalog entry
     *
     * @param int $post_id The WordPress post ID
     * @return bool Success status
     */
    public function delete_entry($post_id) {
        global $wpdb;
        $post_id = intval($post_id);

        $result = $wpdb->delete(
            $this->table_name,
            array('post_id' => $post_id),
            array('%d')
        );

        // Remove from cache
        unset($this->cache[$post_id]);

        return $result !== false;
    }

    /**
     * Get all catalog entries
     *
     * Returns the full catalog as an associative array keyed by post_id.
     * This loads all entries into the cache for the current request.
     *
     * NOTE: For very large catalogs (1000+), consider using get_entries_paginated()
     *
     * @return array Associative array of post_id => entry
     */
    public function get_all_entries() {
        // If full catalog already loaded, return from cache
        if ($this->full_cache_loaded) {
            return $this->cache;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY post_id",
            ARRAY_A
        );

        // Clear and rebuild cache
        $this->cache = array();

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $post_id = intval($row['post_id']);
                $this->cache[$post_id] = $this->row_to_entry($row);
            }
        }

        $this->full_cache_loaded = true;

        return $this->cache;
    }

    /**
     * Get entries paginated (for large catalogs)
     *
     * @param int $limit Number of entries to return
     * @param int $offset Starting position
     * @return array Array of entries
     */
    public function get_entries_paginated($limit = 100, $offset = 0) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY post_id LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        $entries = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $post_id = intval($row['post_id']);
                $entry = $this->row_to_entry($row);
                $entries[$post_id] = $entry;

                // Also add to cache
                $this->cache[$post_id] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Get only pages from catalog
     *
     * @return array Associative array of page entries
     */
    public function get_pages() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE is_page = 1 ORDER BY title",
            ARRAY_A
        );

        $entries = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $post_id = intval($row['post_id']);
                $entry = $this->row_to_entry($row);
                $entries[$post_id] = $entry;
                $this->cache[$post_id] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Get only posts (non-pages) from catalog
     *
     * @return array Associative array of post entries
     */
    public function get_posts() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE is_page = 0 ORDER BY updated_at DESC",
            ARRAY_A
        );

        $entries = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $post_id = intval($row['post_id']);
                $entry = $this->row_to_entry($row);
                $entries[$post_id] = $entry;
                $this->cache[$post_id] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Get catalog statistics (fast, uses COUNT queries)
     *
     * @return array Stats array with total, posts, pages counts
     */
    public function get_stats() {
        global $wpdb;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $pages = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE is_page = 1");

        return array(
            'total' => intval($total),
            'pages' => intval($pages),
            'posts' => intval($total) - intval($pages)
        );
    }

    /**
     * Clear the entire catalog
     *
     * @return bool Success status
     */
    public function clear_all() {
        global $wpdb;

        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");

        // Clear cache
        $this->cache = array();
        $this->full_cache_loaded = false;

        return $result !== false;
    }

    /**
     * Check if an entry exists
     *
     * @param int $post_id The WordPress post ID
     * @return bool Whether entry exists
     */
    public function entry_exists($post_id) {
        // Check cache first
        if (isset($this->cache[$post_id])) {
            return true;
        }

        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT 1 FROM {$this->table_name} WHERE post_id = %d", intval($post_id))
        );

        return $exists !== null;
    }

    /**
     * Get multiple entries by IDs (batch query)
     *
     * More efficient than multiple get_entry() calls for bulk operations.
     *
     * @param array $post_ids Array of post IDs
     * @return array Associative array of post_id => entry
     */
    public function get_entries_by_ids($post_ids) {
        if (empty($post_ids)) {
            return array();
        }

        // Check which IDs are already cached
        $cached = array();
        $needed = array();

        foreach ($post_ids as $post_id) {
            $post_id = intval($post_id);
            if (isset($this->cache[$post_id])) {
                $cached[$post_id] = $this->cache[$post_id];
            } else {
                $needed[] = $post_id;
            }
        }

        // If all cached, return early
        if (empty($needed)) {
            return $cached;
        }

        // Fetch missing entries
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($needed), '%d'));
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE post_id IN ($placeholders)",
            ...$needed
        );

        $rows = $wpdb->get_results($query, ARRAY_A);

        $fetched = array();
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $post_id = intval($row['post_id']);
                $entry = $this->row_to_entry($row);
                $fetched[$post_id] = $entry;
                $this->cache[$post_id] = $entry;
            }
        }

        return array_replace($cached, $fetched);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Convert a database row to a catalog entry array
     *
     * @param array $row Database row
     * @return array Catalog entry
     */
    private function row_to_entry($row) {
        return array(
            'post_id' => intval($row['post_id']),
            'type' => $row['post_type'],
            'is_page' => $row['is_page'] == 1,
            'title' => $row['title'],
            'url' => $row['url'],
            'summary' => $row['summary'],
            'main_topics' => json_decode($row['main_topics'], true) ?: array(),
            'semantic_keywords' => json_decode($row['semantic_keywords'], true) ?: array(),
            'entities' => json_decode($row['entities'], true) ?: array(),
            'content_themes' => json_decode($row['content_themes'], true) ?: array(),
            'good_anchor_phrases' => json_decode($row['good_anchor_phrases'], true) ?: array(),
            'updated_at' => $row['updated_at'],
        );
    }

    /**
     * Clear the in-memory cache
     *
     * Call this if you need to force fresh data from database.
     */
    public function clear_cache() {
        $this->cache = array();
        $this->full_cache_loaded = false;
    }

    /**
     * Get table name (for debugging)
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Check if table exists
     */
    public function table_exists() {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
        ) === $this->table_name;
    }
}
