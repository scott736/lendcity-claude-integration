<?php
/**
 * External Smart Linker API Client
 *
 * Handles communication with the Vercel-hosted vector API.
 * This replaces local catalog queries with external API calls.
 *
 * @package LendCity_Claude_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class LendCity_External_API {

    /**
     * API base URL (set in WordPress settings)
     */
    private $api_url;

    /**
     * API secret key for authentication
     */
    private $api_key;

    /**
     * Request timeout in seconds
     */
    private $timeout = 30;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_url = get_option('lendcity_external_api_url', '');
        $this->api_key = get_option('lendcity_external_api_key', '');
    }

    /**
     * Check if external API is configured
     */
    public function is_configured() {
        return !empty($this->api_url) && !empty($this->api_key);
    }

    /**
     * Make API request
     */
    private function request($endpoint, $data = [], $method = 'POST') {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'External API not configured');
        }

        $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');

        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ]
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 400) {
            return new WP_Error(
                'api_error',
                $body['error'] ?? 'API request failed',
                ['status' => $status_code, 'response' => $body]
            );
        }

        return $body;
    }

    /**
     * Get smart link suggestions for content
     *
     * @param int $post_id Post ID
     * @param string $content HTML content
     * @param array $options Additional options
     * @return array|WP_Error Link suggestions or error
     */
    public function get_smart_links($post_id, $content, $options = []) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        $data = [
            'postId' => $post_id,
            'content' => $content,
            'title' => $post->post_title,
            'topicCluster' => get_post_meta($post_id, 'topic_cluster', true),
            'relatedClusters' => get_post_meta($post_id, 'related_clusters', true) ?: [],
            'funnelStage' => get_post_meta($post_id, 'funnel_stage', true),
            'targetPersona' => get_post_meta($post_id, 'target_persona', true),
            'maxLinks' => $options['max_links'] ?? 5,
            'minScore' => $options['min_score'] ?? 40,
            'useClaudeAnalysis' => $options['use_claude'] ?? true,
            'autoInsert' => $options['auto_insert'] ?? false
        ];

        return $this->request('api/smart-link', $data);
    }

    /**
     * Generate meta title and description
     *
     * @param int $post_id Post ID
     * @param array $options Additional options
     * @return array|WP_Error Meta data or error
     */
    public function generate_meta($post_id, $options = []) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        $data = [
            'postId' => $post_id,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'summary' => get_post_meta($post_id, 'summary', true),
            'topicCluster' => get_post_meta($post_id, 'topic_cluster', true),
            'focusKeyword' => $options['focus_keyword'] ?? null
        ];

        return $this->request('api/meta-generate', $data);
    }

    /**
     * Sync article to external catalog
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Result or error
     */
    public function sync_to_catalog($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Try to get data from WordPress catalog first (has Claude-analyzed metadata)
        $catalog_entry = $this->get_catalog_entry($post_id);

        // Build data array, preferring catalog data over post meta
        $data = [
            'postId' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'slug' => $post->post_name,
            'content' => $post->post_content,
            'contentType' => $post->post_type,
            'topicCluster' => $catalog_entry['topic_cluster'] ?? get_post_meta($post_id, 'topic_cluster', true) ?: '',
            'relatedClusters' => $catalog_entry['related_clusters'] ?? get_post_meta($post_id, 'related_clusters', true) ?: [],
            'funnelStage' => $catalog_entry['funnel_stage'] ?? get_post_meta($post_id, 'funnel_stage', true) ?: '',
            'targetPersona' => $catalog_entry['target_persona'] ?? get_post_meta($post_id, 'target_persona', true) ?: '',
            'difficultyLevel' => $catalog_entry['difficulty_level'] ?? get_post_meta($post_id, 'difficulty_level', true) ?: 'intermediate',
            'qualityScore' => (int) ($catalog_entry['content_quality_score'] ?? get_post_meta($post_id, 'content_quality_score', true) ?: 50),
            'contentLifespan' => $catalog_entry['content_lifespan'] ?? get_post_meta($post_id, 'content_lifespan', true) ?: 'evergreen',
            'isPillar' => (bool) ($catalog_entry['is_pillar_content'] ?? get_post_meta($post_id, '_lendcity_is_pillar', true) ?: false),
            'summary' => $catalog_entry['summary'] ?? get_post_meta($post_id, 'summary', true) ?: '',
            'mainTopics' => $catalog_entry['main_topics'] ?? [],
            'semanticKeywords' => $catalog_entry['semantic_keywords'] ?? [],
            'publishedAt' => $post->post_date,
            'updatedAt' => $post->post_modified
        ];

        return $this->request('api/catalog-sync', $data);
    }

    /**
     * Get catalog entry from WordPress database
     *
     * @param int $post_id Post ID
     * @return array|null Catalog entry or null if not found
     */
    private function get_catalog_entry($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lendcity_smart_linker_catalog';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE post_id = %d",
            $post_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return [
            'topic_cluster' => $row['topic_cluster'] ?? null,
            'related_clusters' => json_decode($row['related_clusters'] ?? '[]', true) ?: [],
            'funnel_stage' => $row['funnel_stage'] ?? null,
            'target_persona' => $row['target_persona'] ?? null,
            'difficulty_level' => $row['difficulty_level'] ?? 'intermediate',
            'content_quality_score' => (int) ($row['content_quality_score'] ?? 50),
            'content_lifespan' => $row['content_lifespan'] ?? 'evergreen',
            'is_pillar_content' => (bool) ($row['is_pillar_content'] ?? 0),
            'summary' => $row['summary'] ?? '',
            'main_topics' => json_decode($row['main_topics'] ?? '[]', true) ?: [],
            'semantic_keywords' => json_decode($row['semantic_keywords'] ?? '[]', true) ?: []
        ];
    }

    /**
     * Delete article from external catalog
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Result or error
     */
    public function delete_from_catalog($post_id) {
        return $this->request('api/catalog-sync', ['postId' => $post_id], 'DELETE');
    }

    /**
     * Check API health
     *
     * @return array|WP_Error Health status or error
     */
    public function health_check() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'External API not configured');
        }

        $url = trailingslashit($this->api_url) . 'api/health';

        $response = wp_remote_get($url, [
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

/**
 * Hook to sync articles on publish
 */
add_action('publish_post', 'lendcity_sync_on_publish', 20);
add_action('publish_page', 'lendcity_sync_on_publish', 20);

function lendcity_sync_on_publish($post_id) {
    // Check if external API is enabled
    if (!get_option('lendcity_use_external_api', false)) {
        return;
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        return;
    }

    // Sync to external catalog
    $result = $api->sync_to_catalog($post_id);

    if (is_wp_error($result)) {
        error_log('LendCity API sync failed: ' . $result->get_error_message());
    }
}

/**
 * Hook to remove articles on delete
 */
add_action('before_delete_post', 'lendcity_delete_on_remove');

function lendcity_delete_on_remove($post_id) {
    if (!get_option('lendcity_use_external_api', false)) {
        return;
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        return;
    }

    $api->delete_from_catalog($post_id);
}

/**
 * AJAX handler for bulk catalog sync
 */
add_action('wp_ajax_lendcity_bulk_sync_catalog', 'lendcity_bulk_sync_catalog');

function lendcity_bulk_sync_catalog() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    // Get all published posts and pages
    $posts = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    $results = [
        'total' => count($posts),
        'success' => 0,
        'failed' => 0,
        'errors' => []
    ];

    foreach ($posts as $post) {
        $result = $api->sync_to_catalog($post->ID);

        if (is_wp_error($result)) {
            $results['failed']++;
            $results['errors'][] = [
                'postId' => $post->ID,
                'title' => $post->post_title,
                'error' => $result->get_error_message()
            ];
        } else {
            $results['success']++;
        }
    }

    wp_send_json_success($results);
}
