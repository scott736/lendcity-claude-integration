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

        // Get catalog data if available
        $catalog_entry = $this->get_catalog_entry($post_id);

        $data = [
            'postId' => $post_id,
            'content' => $content,
            'title' => $post->post_title,
            'topicCluster' => $catalog_entry['topic_cluster'] ?? get_post_meta($post_id, 'topic_cluster', true) ?: '',
            'relatedClusters' => $catalog_entry['related_clusters'] ?? get_post_meta($post_id, 'related_clusters', true) ?: [],
            'funnelStage' => $catalog_entry['funnel_stage'] ?? get_post_meta($post_id, 'funnel_stage', true) ?: '',
            'targetPersona' => $catalog_entry['target_persona'] ?? get_post_meta($post_id, 'target_persona', true) ?: '',
            'difficultyLevel' => $catalog_entry['difficulty_level'] ?? 'intermediate',
            'maxLinks' => $options['max_links'] ?? 5,
            'minScore' => $options['min_score'] ?? 40,
            'useClaudeAnalysis' => $options['use_claude'] ?? true,
            'autoInsert' => $options['auto_insert'] ?? false
        ];

        return $this->request('api/smart-link', $data);
    }

    /**
     * Auto-link a post using the external API
     * Called when a post is published and external API is enabled
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Result or error
     */
    public function auto_link_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // First sync to Pinecone to ensure it's in the catalog
        $sync_result = $this->sync_to_catalog($post_id);
        if (is_wp_error($sync_result)) {
            error_log('LendCity: Failed to sync post ' . $post_id . ' before auto-linking: ' . $sync_result->get_error_message());
        }

        // Get link suggestions from external API
        // Max links is configurable via settings (default 5)
        $max_links = (int) get_option('lendcity_max_links_per_article', 5);
        $content = $post->post_content;
        $suggestions = $this->get_smart_links($post_id, $content, [
            'max_links' => $max_links,
            'min_score' => 50,
            'use_claude' => true,
            'auto_insert' => true
        ]);

        if (is_wp_error($suggestions)) {
            return $suggestions;
        }

        if (empty($suggestions['suggestions'])) {
            return ['success' => true, 'message' => 'No link opportunities found', 'links_created' => 0];
        }

        // Insert links into content
        $links_created = 0;
        $link_meta = get_post_meta($post_id, '_lendcity_smart_links', true) ?: [];
        $updated_content = $content;

        foreach ($suggestions['suggestions'] as $suggestion) {
            if (empty($suggestion['anchorText']) || empty($suggestion['targetUrl'])) {
                continue;
            }

            $anchor = $suggestion['anchorText'];
            $url = $suggestion['targetUrl'];

            // Skip if already linked to this URL
            $already_linked = false;
            foreach ($link_meta as $existing) {
                if ($existing['url'] === $url) {
                    $already_linked = true;
                    break;
                }
            }
            if ($already_linked) continue;

            // Find and replace anchor text with link (first occurrence only)
            $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*>)(?![^<]*<\/a>)/i';
            $replacement = '<a href="' . esc_url($url) . '">' . $anchor . '</a>';

            $new_content = preg_replace($pattern, $replacement, $updated_content, 1, $count);

            if ($count > 0) {
                $updated_content = $new_content;
                $link_meta[] = [
                    'anchor' => $anchor,
                    'url' => $url,
                    'target_id' => $suggestion['targetPostId'] ?? 0,
                    'score' => $suggestion['score'] ?? 0,
                    'created' => current_time('mysql')
                ];
                $links_created++;
            }
        }

        // Save updated content and link meta
        if ($links_created > 0) {
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $updated_content
            ]);
            update_post_meta($post_id, '_lendcity_smart_links', $link_meta);
        }

        return [
            'success' => true,
            'links_created' => $links_created,
            'suggestions_count' => count($suggestions['suggestions'])
        ];
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
        return;
    }

    // Auto-link if enabled (new content links OUT to existing content)
    $auto_link = get_option('lendcity_smart_linker_auto', 'yes');
    if ($auto_link === 'yes') {
        // Schedule auto-link to run after a short delay (allows sync to complete)
        wp_schedule_single_event(time() + 30, 'lendcity_external_auto_link', [$post_id]);
    }
}

/**
 * Hook for external API auto-linking
 */
add_action('lendcity_external_auto_link', 'lendcity_run_external_auto_link');

function lendcity_run_external_auto_link($post_id) {
    if (!get_option('lendcity_use_external_api', false)) {
        return;
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        return;
    }

    $result = $api->auto_link_post($post_id);

    if (is_wp_error($result)) {
        error_log('LendCity external auto-link failed for post ' . $post_id . ': ' . $result->get_error_message());
    } else {
        error_log('LendCity external auto-link for post ' . $post_id . ': ' . ($result['links_created'] ?? 0) . ' links created');
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
 * AJAX handler for rebuilding catalog (pillars first, then content)
 * This ensures pillar pages are in Pinecone before posts get cluster-matched
 */
add_action('wp_ajax_lendcity_rebuild_catalog', 'lendcity_rebuild_catalog');
add_action('wp_ajax_lendcity_bulk_sync_catalog', 'lendcity_rebuild_catalog'); // Alias for compatibility

function lendcity_rebuild_catalog() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    $results = [
        'pillars' => ['total' => 0, 'success' => 0, 'failed' => 0],
        'pages' => ['total' => 0, 'success' => 0, 'failed' => 0],
        'posts' => ['total' => 0, 'success' => 0, 'failed' => 0],
        'errors' => []
    ];

    // STEP 1: Sync pillar pages FIRST (they define topic clusters)
    $pillar_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_lendcity_is_pillar',
                'value' => '1',
                'compare' => '='
            ]
        ]
    ]);

    $results['pillars']['total'] = count($pillar_pages);
    $pillar_ids = [];

    foreach ($pillar_pages as $page) {
        $pillar_ids[] = $page->ID;
        $result = $api->sync_to_catalog($page->ID);

        if (is_wp_error($result)) {
            $results['pillars']['failed']++;
            $results['errors'][] = [
                'type' => 'pillar',
                'postId' => $page->ID,
                'title' => $page->post_title,
                'error' => $result->get_error_message()
            ];
        } else {
            $results['pillars']['success']++;
        }
    }

    // STEP 2: Sync remaining pages (non-pillar)
    $other_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'post__not_in' => !empty($pillar_ids) ? $pillar_ids : [0]
    ]);

    $results['pages']['total'] = count($other_pages);

    foreach ($other_pages as $page) {
        $result = $api->sync_to_catalog($page->ID);

        if (is_wp_error($result)) {
            $results['pages']['failed']++;
            $results['errors'][] = [
                'type' => 'page',
                'postId' => $page->ID,
                'title' => $page->post_title,
                'error' => $result->get_error_message()
            ];
        } else {
            $results['pages']['success']++;
        }
    }

    // STEP 3: Sync all posts (they get matched to pillar clusters)
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    $results['posts']['total'] = count($posts);

    foreach ($posts as $post) {
        $result = $api->sync_to_catalog($post->ID);

        if (is_wp_error($result)) {
            $results['posts']['failed']++;
            $results['errors'][] = [
                'type' => 'post',
                'postId' => $post->ID,
                'title' => $post->post_title,
                'error' => $result->get_error_message()
            ];
        } else {
            $results['posts']['success']++;
        }
    }

    // Calculate totals
    $results['total'] = $results['pillars']['total'] + $results['pages']['total'] + $results['posts']['total'];
    $results['success'] = $results['pillars']['success'] + $results['pages']['success'] + $results['posts']['success'];
    $results['failed'] = $results['pillars']['failed'] + $results['pages']['failed'] + $results['posts']['failed'];

    wp_send_json_success($results);
}
