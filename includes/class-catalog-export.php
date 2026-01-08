<?php
/**
 * Catalog Export for Migration
 *
 * Provides REST endpoints to export the WordPress catalog
 * for migration to the external Pinecone database.
 *
 * @package LendCity_Claude_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class LendCity_Catalog_Export {

    /**
     * Initialize the export endpoints
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route('smart-linker/v1', '/export-catalog', [
            'methods' => 'GET',
            'callback' => [$this, 'export_catalog'],
            'permission_callback' => [$this, 'check_admin_permission']
        ]);

        register_rest_route('smart-linker/v1', '/export-catalog/count', [
            'methods' => 'GET',
            'callback' => [$this, 'get_catalog_count'],
            'permission_callback' => [$this, 'check_admin_permission']
        ]);

        register_rest_route('smart-linker/v1', '/export-catalog/batch', [
            'methods' => 'GET',
            'callback' => [$this, 'export_catalog_batch'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'offset' => [
                    'default' => 0,
                    'sanitize_callback' => 'absint'
                ],
                'limit' => [
                    'default' => 50,
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
    }

    /**
     * Check if user has admin permissions
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Export entire catalog
     */
    public function export_catalog() {
        global $wpdb;

        $table = $wpdb->prefix . 'smart_linker_catalog';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return new WP_Error('no_catalog', 'Catalog table not found', ['status' => 404]);
        }

        $results = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);

        // Enrich with post metadata
        foreach ($results as &$row) {
            $post_id = $row['post_id'];
            $post = get_post($post_id);

            if ($post) {
                $row['content'] = $post->post_content;
                $row['post_date'] = $post->post_date;
                $row['post_modified'] = $post->post_modified;
            }
        }

        return rest_ensure_response($results);
    }

    /**
     * Get catalog count
     */
    public function get_catalog_count() {
        global $wpdb;

        $table = $wpdb->prefix . 'smart_linker_catalog';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return new WP_Error('no_catalog', 'Catalog table not found', ['status' => 404]);
        }

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return rest_ensure_response([
            'count' => (int) $count,
            'table' => $table
        ]);
    }

    /**
     * Export catalog in batches (for large catalogs)
     */
    public function export_catalog_batch($request) {
        global $wpdb;

        $offset = $request->get_param('offset');
        $limit = min($request->get_param('limit'), 100); // Max 100 per batch

        $table = $wpdb->prefix . 'smart_linker_catalog';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return new WP_Error('no_catalog', 'Catalog table not found', ['status' => 404]);
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY post_id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // Enrich with post content
        foreach ($results as &$row) {
            $post = get_post($row['post_id']);
            if ($post) {
                $row['content'] = $post->post_content;
                $row['post_date'] = $post->post_date;
                $row['post_modified'] = $post->post_modified;
            }
        }

        return rest_ensure_response([
            'total' => (int) $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < $total,
            'next_offset' => $offset + $limit,
            'data' => $results
        ]);
    }
}

// Initialize
new LendCity_Catalog_Export();
