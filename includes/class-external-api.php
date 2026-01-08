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
            error_log('LendCity Sync Error: External API not configured');
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
            error_log('LendCity Sync Error: ' . $response->get_error_message() . ' - URL: ' . $url);
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($status_code >= 400) {
            $error_msg = $body['error'] ?? $body['message'] ?? 'API request failed';
            error_log('LendCity Sync Error: HTTP ' . $status_code . ' - ' . $error_msg . ' - URL: ' . $url);
            error_log('LendCity Sync Response: ' . substr($body_raw, 0, 500));
            return new WP_Error(
                'api_error',
                $error_msg,
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

        // Send minimal data - API will look up source article metadata from Pinecone
        $data = [
            'postId' => $post_id,
            'content' => $content,
            'title' => $post->post_title,
            // Source article metadata is fetched from Pinecone by the API
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
     * Sync article to external catalog (Pinecone)
     * Sends raw post data - Claude handles all analysis on Vercel side
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Result or error
     */
    public function sync_to_catalog($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Send raw data to Pinecone - Claude will analyze everything
        // Only isPillar is kept from WordPress (user-controlled setting)
        $data = [
            'postId' => $post_id,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'slug' => $post->post_name,
            'content' => $post->post_content,
            'contentType' => $post->post_type,
            'isPillar' => (bool) get_post_meta($post_id, '_lendcity_is_pillar', true),
            'publishedAt' => $post->post_date,
            'updatedAt' => $post->post_modified
            // All other metadata (cluster, funnel, persona, summary, etc.)
            // will be auto-generated by Claude on the Vercel API
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
        $table_name = $wpdb->prefix . 'lendcity_catalog';

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
     * Audit existing links in a post
     * Checks for broken, suboptimal, and missing links
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Audit results or error
     */
    public function audit_links($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Extract existing links from content
        $existing_links = $this->extract_links_from_content($post->post_content);

        // Get catalog data
        $catalog_entry = $this->get_catalog_entry($post_id);

        $data = [
            'postId' => $post_id,
            'content' => $post->post_content,
            'title' => $post->post_title,
            'existingLinks' => $existing_links,
            'topicCluster' => $catalog_entry['topic_cluster'] ?? '',
            'maxSuggestions' => 5
        ];

        return $this->request('api/link-audit', $data);
    }

    /**
     * Extract internal links from post content
     *
     * @param string $content Post content
     * @return array Array of links with anchor, url, targetId
     */
    private function extract_links_from_content($content) {
        $links = [];
        $site_url = home_url();

        // Match all anchor tags
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = $match[1];
            $anchor = strip_tags($match[2]);

            // Only process internal links
            if (strpos($url, $site_url) === 0 || strpos($url, '/') === 0) {
                // Try to get post ID from URL
                $target_id = url_to_postid($url);

                $links[] = [
                    'anchor' => $anchor,
                    'url' => $url,
                    'targetId' => $target_id ?: 0
                ];
            }
        }

        return $links;
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

    /**
     * Get Pinecone catalog stats
     * Returns count of vectorized pages, posts, clusters, etc.
     *
     * @return array|WP_Error Stats or error
     */
    public function get_catalog_stats() {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'External API not configured');
        }

        $url = trailingslashit($this->api_url) . 'api/catalog-stats';

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 400) {
            return new WP_Error(
                'api_error',
                $body['error'] ?? 'Failed to get catalog stats',
                ['status' => $status_code]
            );
        }

        return $body;
    }

    /**
     * Generate meta with full context (links, clusters, funnel, persona)
     * Enhanced version that passes all content structure data to Claude
     *
     * @param int $post_id Post ID
     * @param array $options Additional options including internalLinks, relatedClusters, etc.
     * @return array|WP_Error Meta data or error
     */
    public function generate_meta_with_context($post_id, $options = []) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // Get catalog data for this post
        $catalog_entry = $this->get_catalog_entry($post_id);

        // Get smart links stored for this post (outbound - links FROM this article)
        $smart_links = get_post_meta($post_id, '_lendcity_smart_links', true) ?: [];
        $internal_links = array_map(function($link) {
            // Get target post info for cluster context
            $target_id = $link['target_id'] ?? 0;
            $target_cluster = '';
            if ($target_id) {
                $target_cluster = get_post_meta($target_id, 'topic_cluster', true) ?: '';
            }
            return [
                'anchorText' => $link['anchor'] ?? '',
                'title' => get_the_title($target_id) ?: ($link['anchor'] ?? ''),
                'topicCluster' => $target_cluster,
                'url' => $link['url'] ?? ''
            ];
        }, $smart_links);

        // Get inbound links (links TO this article from other posts)
        $inbound_links = $this->get_inbound_links($post_id);

        $data = [
            'postId' => $post_id,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'summary' => $catalog_entry['summary'] ?? get_post_meta($post_id, 'summary', true) ?: '',
            'topicCluster' => $catalog_entry['topic_cluster'] ?? get_post_meta($post_id, 'topic_cluster', true) ?: '',
            'focusKeyword' => $options['focus_keyword'] ?? null,
            // Full context
            'internalLinks' => $internal_links,
            'inboundLinks' => $inbound_links,  // NEW: Links pointing TO this article
            'relatedClusters' => $catalog_entry['related_clusters'] ?? [],
            'funnelStage' => $catalog_entry['funnel_stage'] ?? get_post_meta($post_id, 'funnel_stage', true) ?: '',
            'targetPersona' => $catalog_entry['target_persona'] ?? get_post_meta($post_id, 'target_persona', true) ?: ''
        ];

        return $this->request('api/meta-generate', $data);
    }

    /**
     * Get inbound links - posts that link TO this article
     *
     * @param int $post_id Post ID to find inbound links for
     * @return array Array of inbound link data
     */
    private function get_inbound_links($post_id) {
        global $wpdb;

        $post_url = get_permalink($post_id);
        if (!$post_url) {
            return [];
        }

        // Query all posts with smart links that point to this post
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_lendcity_smart_links'
            AND p.post_status = 'publish'
            AND pm.meta_value LIKE %s
        ", '%' . $wpdb->esc_like('"target_post_id":' . $post_id) . '%'), ARRAY_A);

        $inbound_links = [];
        foreach ($results as $row) {
            $links = maybe_unserialize($row['meta_value']);
            if (!is_array($links)) continue;

            foreach ($links as $link) {
                $target_id = $link['target_post_id'] ?? ($link['target_id'] ?? 0);
                if ((int)$target_id === (int)$post_id) {
                    $source_id = $row['post_id'];
                    $source_cluster = get_post_meta($source_id, 'topic_cluster', true) ?: '';
                    $inbound_links[] = [
                        'sourcePostId' => $source_id,
                        'sourceTitle' => get_the_title($source_id),
                        'anchorText' => $link['anchor'] ?? '',
                        'sourceCluster' => $source_cluster
                    ];
                }
            }
        }

        return array_slice($inbound_links, 0, 10); // Limit to top 10 inbound links
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
 * AJAX handler for getting sync items (first step of batched sync)
 */
add_action('wp_ajax_lendcity_get_sync_items', 'lendcity_get_sync_items');

function lendcity_get_sync_items() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    // Get pillar pages first
    $pillar_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_query' => [
            ['key' => '_lendcity_is_pillar', 'value' => '1', 'compare' => '=']
        ]
    ]);

    // Get other pages
    $other_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields' => 'ids',
        'post__not_in' => !empty($pillar_pages) ? $pillar_pages : [0]
    ]);

    // Get all posts
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields' => 'ids'
    ]);

    // Return ordered list: pillars first, then pages, then posts
    $items = array_merge(
        array_map(function($id) { return ['id' => $id, 'type' => 'pillar']; }, $pillar_pages),
        array_map(function($id) { return ['id' => $id, 'type' => 'page']; }, $other_pages),
        array_map(function($id) { return ['id' => $id, 'type' => 'post']; }, $posts)
    );

    wp_send_json_success([
        'items' => $items,
        'total' => count($items),
        'pillars' => count($pillar_pages),
        'pages' => count($other_pages),
        'posts' => count($posts)
    ]);
}

/**
 * AJAX handler for syncing a single item
 */
add_action('wp_ajax_lendcity_sync_single_item', 'lendcity_sync_single_item');

function lendcity_sync_single_item() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'No post ID provided']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    $result = $api->sync_to_catalog($post_id);

    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'postId' => $post_id
        ]);
    }

    wp_send_json_success([
        'postId' => $post_id,
        'result' => $result
    ]);
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
        error_log('LendCity Bulk Sync: External API not configured - check Settings page');
        wp_send_json_error(['message' => 'External API not configured. Please check External Vector API settings.']);
    }

    // Log the API URL being used (without the key)
    $api_url = get_option('lendcity_external_api_url', '');
    error_log('LendCity Bulk Sync: Starting sync to ' . $api_url);

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

/**
 * AJAX handler for getting Pinecone catalog stats
 */
add_action('wp_ajax_lendcity_get_pinecone_stats', 'lendcity_get_pinecone_stats');

function lendcity_get_pinecone_stats() {
    check_ajax_referer('lendcity_claude_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured. Set up Vercel API URL and key in Settings.']);
    }

    $result = $api->get_catalog_stats();

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * AJAX handler for saving max links setting
 */
add_action('wp_ajax_lendcity_save_max_links', 'lendcity_save_max_links');

function lendcity_save_max_links() {
    check_ajax_referer('lendcity_claude_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $max_links = isset($_POST['max_links']) ? intval($_POST['max_links']) : 5;
    $max_links = max(1, min(20, $max_links)); // Clamp between 1-20

    update_option('lendcity_max_links_per_article', $max_links);

    wp_send_json_success(['max_links' => $max_links]);
}

/**
 * AJAX handler for saving auto-meta after linking setting
 */
add_action('wp_ajax_lendcity_save_auto_meta_setting', 'lendcity_save_auto_meta_setting');

function lendcity_save_auto_meta_setting() {
    check_ajax_referer('lendcity_claude_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'yes' ? 'yes' : 'no';

    update_option('lendcity_auto_meta_after_linking', $enabled);

    wp_send_json_success(['enabled' => $enabled]);
}

/**
 * AJAX handler for auditing links in a single post
 */
add_action('wp_ajax_lendcity_audit_post_links', 'lendcity_audit_post_links');

function lendcity_audit_post_links() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error(['message' => 'Post ID required']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    $result = $api->audit_links($post_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success($result);
}

/**
 * AJAX handler for bulk link audit across all posts
 */
add_action('wp_ajax_lendcity_bulk_audit_links', 'lendcity_bulk_audit_links');

function lendcity_bulk_audit_links() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    // Get all published posts
    $posts = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    $results = [
        'total' => count($posts),
        'audited' => 0,
        'issues' => [],
        'summary' => [
            'totalLinks' => 0,
            'brokenLinks' => 0,
            'suboptimalLinks' => 0,
            'missingOpportunities' => 0
        ]
    ];

    foreach ($posts as $post) {
        $audit = $api->audit_links($post->ID);

        if (is_wp_error($audit)) {
            continue;
        }

        $results['audited']++;

        // Aggregate stats
        if (isset($audit['audit']['stats'])) {
            $stats = $audit['audit']['stats'];
            $results['summary']['totalLinks'] += $stats['totalLinks'] ?? 0;
            $results['summary']['brokenLinks'] += $stats['brokenLinks'] ?? 0;
            $results['summary']['suboptimalLinks'] += $stats['suboptimalLinks'] ?? 0;
            $results['summary']['missingOpportunities'] += $stats['missingOpportunities'] ?? 0;
        }

        // Collect issues
        if (isset($audit['audit'])) {
            $a = $audit['audit'];

            // Broken links
            if (!empty($a['existing']['broken'])) {
                foreach ($a['existing']['broken'] as $broken) {
                    $results['issues'][] = [
                        'type' => 'broken',
                        'postId' => $post->ID,
                        'postTitle' => $post->post_title,
                        'anchor' => $broken['anchor'],
                        'url' => $broken['url'],
                        'issue' => $broken['issue']
                    ];
                }
            }

            // Suboptimal links
            if (!empty($a['existing']['suboptimal'])) {
                foreach ($a['existing']['suboptimal'] as $sub) {
                    $results['issues'][] = [
                        'type' => 'suboptimal',
                        'postId' => $post->ID,
                        'postTitle' => $post->post_title,
                        'anchor' => $sub['anchor'],
                        'currentTarget' => $sub['currentTarget']['title'] ?? '',
                        'betterOption' => $sub['betterOptions'][0]['title'] ?? ''
                    ];
                }
            }
        }
    }

    // Calculate overall health score
    if ($results['summary']['totalLinks'] > 0) {
        $healthyLinks = $results['summary']['totalLinks'] - $results['summary']['brokenLinks'] - $results['summary']['suboptimalLinks'];
        $results['summary']['overallHealthScore'] = round(($healthyLinks / $results['summary']['totalLinks']) * 100);
    } else {
        $results['summary']['overallHealthScore'] = 100;
    }

    wp_send_json_success($results);
}
