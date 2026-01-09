<?php
/**
 * External Smart Linker API Client
 *
 * v6.0: Optimized for speed with batch operations
 * All metadata now lives in Pinecone - WordPress only stores pillar flags
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
     * Batch timeout (longer for bulk operations)
     */
    private $batch_timeout = 120;

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
     * Get API URL
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Get API Key
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Make API request
     */
    private function request($endpoint, $data = [], $method = 'POST', $timeout = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'External API not configured');
        }

        $url = trailingslashit($this->api_url) . ltrim($endpoint, '/');

        $args = [
            'method' => $method,
            'timeout' => $timeout ?? $this->timeout,
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
     * v6.0: Simplified - no WordPress catalog lookup needed
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

        // v6.0: Only pass post meta (Pinecone has the full metadata)
        $data = [
            'postId' => $post_id,
            'content' => $content,
            'title' => $post->post_title,
            'topicCluster' => get_post_meta($post_id, 'topic_cluster', true) ?: '',
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

        if (empty($suggestions['links'])) {
            return ['success' => true, 'message' => 'No link opportunities found', 'links_created' => 0];
        }

        // Insert links into content
        $links_created = 0;
        $link_meta = get_post_meta($post_id, '_lendcity_smart_links', true) ?: [];
        $updated_content = $content;

        // RULE: Sort suggestions to prefer pages over posts
        $sorted_suggestions = $suggestions['links'];
        usort($sorted_suggestions, function($a, $b) {
            $a_is_page = isset($a['postType']) && $a['postType'] === 'page';
            $b_is_page = isset($b['postType']) && $b['postType'] === 'page';
            if ($a_is_page && !$b_is_page) return -1;
            if (!$a_is_page && $b_is_page) return 1;
            // If same type, sort by score
            return ($b['score'] ?? 0) - ($a['score'] ?? 0);
        });

        // Track which paragraphs already have links (for one-link-per-paragraph rule)
        $paragraphs_with_links = [];

        foreach ($sorted_suggestions as $suggestion) {
            if (empty($suggestion['anchorText']) || empty($suggestion['url'])) {
                continue;
            }

            $anchor = $suggestion['anchorText'];
            $url = $suggestion['url'];

            // Skip if already linked to this URL
            $already_linked = false;
            foreach ($link_meta as $existing) {
                if ($existing['url'] === $url) {
                    $already_linked = true;
                    break;
                }
            }
            if ($already_linked) continue;

            // RULE: One link per paragraph - find which paragraph contains this anchor
            // Split content into paragraphs and check if anchor's paragraph already has a link
            $anchor_position = stripos($updated_content, $anchor);
            if ($anchor_position !== false) {
                // Find the paragraph boundaries (look for </p> or double newlines)
                $para_start = strrpos(substr($updated_content, 0, $anchor_position), '<p');
                if ($para_start === false) {
                    $para_start = strrpos(substr($updated_content, 0, $anchor_position), "\n\n");
                }
                $para_start = $para_start !== false ? $para_start : 0;

                $para_end = strpos($updated_content, '</p>', $anchor_position);
                if ($para_end === false) {
                    $para_end = strpos($updated_content, "\n\n", $anchor_position);
                }
                $para_end = $para_end !== false ? $para_end : strlen($updated_content);

                // Create a unique key for this paragraph
                $para_key = $para_start . '-' . $para_end;

                // Check if this paragraph already has a link added in this session
                if (in_array($para_key, $paragraphs_with_links)) {
                    continue; // Skip - one link per paragraph rule
                }

                // Also check if the paragraph already contains any <a> tag
                $para_content = substr($updated_content, $para_start, $para_end - $para_start);
                if (preg_match('/<a\s+[^>]*href/i', $para_content)) {
                    $paragraphs_with_links[] = $para_key; // Mark as having a link
                    continue; // Skip - paragraph already has a link
                }
            }

            // Find and replace anchor text with link (first occurrence only)
            $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*>)(?![^<]*<\/a>)/i';
            $replacement = '<a href="' . esc_url($url) . '">' . $anchor . '</a>';

            $new_content = preg_replace($pattern, $replacement, $updated_content, 1, $count);

            if ($count > 0) {
                $updated_content = $new_content;
                $link_meta[] = [
                    'link_id' => uniqid('lnk_'),
                    'anchor' => $anchor,
                    'url' => $url,
                    'target_id' => $suggestion['postId'] ?? 0,
                    'score' => $suggestion['score'] ?? 0,
                    'created' => current_time('mysql')
                ];
                $links_created++;

                // Mark this paragraph as having a link
                if (isset($para_key)) {
                    $paragraphs_with_links[] = $para_key;
                }
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

        // Always generate SEO metadata if missing (runs with link generation for faster saves)
        $existing_title = get_post_meta($post_id, '_seopress_titles_title', true);
        $existing_desc = get_post_meta($post_id, '_seopress_titles_desc', true);
        $seo_generated = false;

        if (empty($existing_title) || empty($existing_desc)) {
            $meta_result = $this->generate_meta($post_id);

            if (!is_wp_error($meta_result) && is_array($meta_result)) {
                if (!empty($meta_result['title'])) {
                    update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($meta_result['title']));
                }
                if (!empty($meta_result['description'])) {
                    update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($meta_result['description']));
                }
                if (!empty($meta_result['focusKeyphrase'])) {
                    update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($meta_result['focusKeyphrase']));
                }
                $seo_generated = true;
            }
        }

        return [
            'success' => true,
            'links_created' => $links_created,
            'suggestions_count' => count($suggestions['links']),
            'seo_generated' => $seo_generated
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
            'topicCluster' => get_post_meta($post_id, 'topic_cluster', true),
            'focusKeyword' => $options['focus_keyword'] ?? null
        ];

        return $this->request('api/meta-generate', $data);
    }

    /**
     * Sync article to external catalog (Pinecone)
     * v6.0: Sends raw content - Pinecone/Claude generates metadata
     *
     * @param int $post_id Post ID
     * @return array|WP_Error Result or error
     */
    public function sync_to_catalog($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // v6.0: Send minimal data - Pinecone API will auto-analyze with Claude
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
        ];

        return $this->request('api/catalog-sync', $data);
    }

    /**
     * Batch sync multiple articles to Pinecone
     * v6.0: Much faster than individual syncs
     *
     * @param array $post_ids Array of post IDs to sync
     * @return array|WP_Error Results or error
     */
    public function batch_sync_to_catalog($post_ids) {
        if (empty($post_ids)) {
            return ['success' => true, 'processed' => 0];
        }

        // Build articles array
        $articles = [];
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;

            $articles[] = [
                'postId' => $post_id,
                'title' => $post->post_title,
                'url' => get_permalink($post_id),
                'slug' => $post->post_name,
                'content' => $post->post_content,
                'contentType' => $post->post_type,
                'isPillar' => (bool) get_post_meta($post_id, '_lendcity_is_pillar', true),
                'publishedAt' => $post->post_date,
                'updatedAt' => $post->post_modified
            ];
        }

        if (empty($articles)) {
            return ['success' => true, 'processed' => 0];
        }

        // Send batch request - Pinecone is the only data store
        return $this->request('api/catalog-sync-batch', ['articles' => $articles], 'POST', $this->batch_timeout);
    }

    /**
     * Delete article from Pinecone catalog
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

        $data = [
            'postId' => $post_id,
            'content' => $post->post_content,
            'title' => $post->post_title,
            'existingLinks' => $existing_links,
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
 * AJAX handler: Get list of all content to sync (for chunked processing)
 */
add_action('wp_ajax_lendcity_get_sync_list', 'lendcity_get_sync_list');

function lendcity_get_sync_list() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $items = [];

    // Get pillar pages first (they should sync first)
    $pillar_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query' => [
            ['key' => '_lendcity_is_pillar', 'value' => '1', 'compare' => '=']
        ]
    ]);

    foreach ($pillar_pages as $page) {
        $items[] = ['id' => $page->ID, 'type' => 'pillar', 'title' => $page->post_title];
    }

    $pillar_ids = wp_list_pluck($pillar_pages, 'ID');

    // Get other pages
    $other_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'post__not_in' => !empty($pillar_ids) ? $pillar_ids : [0]
    ]);

    foreach ($other_pages as $page) {
        $items[] = ['id' => $page->ID, 'type' => 'page', 'title' => $page->post_title];
    }

    // Get all posts
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    foreach ($posts as $post) {
        $items[] = ['id' => $post->ID, 'type' => 'post', 'title' => $post->post_title];
    }

    wp_send_json_success(['items' => $items, 'total' => count($items)]);
}

/**
 * AJAX handler: Sync a small chunk of posts (to avoid timeout)
 */
add_action('wp_ajax_lendcity_sync_chunk', 'lendcity_sync_chunk');

function lendcity_sync_chunk() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array)$_POST['post_ids']) : [];

    if (empty($post_ids)) {
        wp_send_json_error(['message' => 'No post IDs provided']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($post_ids as $post_id) {
        $result = $api->sync_to_catalog($post_id);

        if (is_wp_error($result)) {
            $failed++;
            $errors[] = ['postId' => $post_id, 'error' => $result->get_error_message()];
        } else {
            $success++;
            // Mark as synced for skip functionality
            update_post_meta($post_id, '_lendcity_pinecone_synced', '1');
            update_post_meta($post_id, '_lendcity_pinecone_synced_at', current_time('mysql'));
        }
    }

    wp_send_json_success([
        'success' => $success,
        'failed' => $failed,
        'errors' => $errors
    ]);
}

/**
 * AJAX handler for rebuilding catalog (pillars first, then content)
 * v6.0: Uses BATCH operations for much faster sync
 */
add_action('wp_ajax_lendcity_rebuild_catalog', 'lendcity_rebuild_catalog');
add_action('wp_ajax_lendcity_bulk_sync_catalog', 'lendcity_rebuild_catalog');

function lendcity_rebuild_catalog() {
    // Debug: Log that we reached this function
    error_log('LendCity: rebuild_catalog STARTED');

    check_ajax_referer('lendcity_bulk_sync', 'nonce');
    error_log('LendCity: nonce verified');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }
    error_log('LendCity: user authorized');

    // Increase limits for bulk operation
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    try {
        error_log('LendCity: creating API instance');
        $api = new LendCity_External_API();
        if (!$api->is_configured()) {
            wp_send_json_error(['message' => 'External API not configured']);
        }
        error_log('LendCity: API configured');
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'API init error: ' . $e->getMessage()]);
    }

    try {
        error_log('LendCity: Starting sync process');
        $results = [
            'pillars' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'pages' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'posts' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'errors' => []
        ];

        // STEP 1: Sync pillar pages FIRST (they define topic clusters)
        error_log('LendCity: Querying pillar pages');
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
        error_log('LendCity: Found ' . count($pillar_pages) . ' pillar pages');
        $pillar_ids = [];

        foreach ($pillar_pages as $page) {
            $pillar_ids[] = $page->ID;
            error_log('LendCity: Syncing pillar ' . $page->ID);
            $result = $api->sync_to_catalog($page->ID);

            if (is_wp_error($result)) {
                error_log('LendCity: Pillar sync failed - ' . $result->get_error_message());
                $results['pillars']['failed']++;
                $results['errors'][] = [
                    'type' => 'pillar',
                    'postId' => $page->ID,
                    'title' => $page->post_title,
                    'error' => $result->get_error_message()
                ];
            } else {
                error_log('LendCity: Pillar sync success');
                $results['pillars']['success']++;
            }
        }

        // STEP 2: Batch sync remaining pages (non-pillar)
        error_log('LendCity: Querying other pages');
        $other_pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'post__not_in' => !empty($pillar_ids) ? $pillar_ids : [0]
        ]);

        $results['pages']['total'] = count($other_pages);
        error_log('LendCity: Found ' . count($other_pages) . ' other pages');

        if (!empty($other_pages)) {
            $page_ids = wp_list_pluck($other_pages, 'ID');
            error_log('LendCity: Starting batch sync for pages');
            $batch_result = lendcity_batch_sync_with_retry($api, $page_ids);
            $results['pages']['success'] = $batch_result['success'];
            $results['pages']['failed'] = $batch_result['failed'];
            $results['errors'] = array_merge($results['errors'], $batch_result['errors']);
            error_log('LendCity: Pages batch done - success: ' . $batch_result['success'] . ', failed: ' . $batch_result['failed']);
        }

        // STEP 3: Batch sync all posts
        error_log('LendCity: Querying posts');
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1
        ]);

        $results['posts']['total'] = count($posts);
        error_log('LendCity: Found ' . count($posts) . ' posts');

        if (!empty($posts)) {
            $post_ids = wp_list_pluck($posts, 'ID');
            error_log('LendCity: Starting batch sync for posts');
            $batch_result = lendcity_batch_sync_with_retry($api, $post_ids);
            $results['posts']['success'] = $batch_result['success'];
            $results['posts']['failed'] = $batch_result['failed'];
            $results['errors'] = array_merge($results['errors'], $batch_result['errors']);
            error_log('LendCity: Posts batch done - success: ' . $batch_result['success'] . ', failed: ' . $batch_result['failed']);
        }

        // Calculate totals
        $results['total'] = $results['pillars']['total'] + $results['pages']['total'] + $results['posts']['total'];
        $results['success'] = $results['pillars']['success'] + $results['pages']['success'] + $results['posts']['success'];
        $results['failed'] = $results['pillars']['failed'] + $results['pages']['failed'] + $results['posts']['failed'];

        error_log('LendCity: Sync complete - total: ' . $results['total'] . ', success: ' . $results['success'] . ', failed: ' . $results['failed']);
        wp_send_json_success($results);

    } catch (Exception $e) {
        error_log('LendCity catalog sync error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Sync error: ' . $e->getMessage()]);
    } catch (Error $e) {
        error_log('LendCity catalog sync fatal: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Fatal error: ' . $e->getMessage()]);
    }
}

/**
 * Helper: Batch sync with chunking and retry logic
 */
function lendcity_batch_sync_with_retry($api, $post_ids, $batch_size = 20) {
    $results = ['success' => 0, 'failed' => 0, 'errors' => []];

    // Process in batches
    $batches = array_chunk($post_ids, $batch_size);

    foreach ($batches as $batch) {
        $result = $api->batch_sync_to_catalog($batch);

        if (is_wp_error($result)) {
            // Batch failed - fall back to individual syncs
            foreach ($batch as $post_id) {
                $single_result = $api->sync_to_catalog($post_id);
                if (is_wp_error($single_result)) {
                    $results['failed']++;
                    $post = get_post($post_id);
                    $results['errors'][] = [
                        'type' => 'post',
                        'postId' => $post_id,
                        'title' => $post ? $post->post_title : 'Unknown',
                        'error' => $single_result->get_error_message()
                    ];
                } else {
                    $results['success']++;
                }
            }
        } else {
            $results['success'] += $result['succeeded'] ?? 0;
            $results['failed'] += $result['failed'] ?? 0;

            // Collect errors from batch result
            if (!empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    if ($detail['status'] === 'failed') {
                        $post = get_post($detail['postId']);
                        $results['errors'][] = [
                            'type' => 'post',
                            'postId' => $detail['postId'],
                            'title' => $post ? $post->post_title : 'Unknown',
                            'error' => $detail['error'] ?? 'Unknown error'
                        ];
                    }
                }
            }
        }
    }

    return $results;
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

/**
 * AJAX handler: Get list of all posts for chunked audit
 */
add_action('wp_ajax_lendcity_get_audit_list', 'lendcity_get_audit_list');

function lendcity_get_audit_list() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $items = [];

    // Get all published posts and pages
    $posts = get_posts([
        'post_type' => ['post', 'page'],
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    foreach ($posts as $post) {
        $items[] = ['id' => $post->ID, 'title' => $post->post_title];
    }

    wp_send_json_success(['items' => $items, 'total' => count($items)]);
}

/**
 * AJAX handler: Audit a small chunk of posts (to avoid timeout)
 */
add_action('wp_ajax_lendcity_audit_chunk', 'lendcity_audit_chunk');

function lendcity_audit_chunk() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array)$_POST['post_ids']) : [];

    if (empty($post_ids)) {
        wp_send_json_error(['message' => 'No post IDs provided']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'External API not configured']);
    }

    $results = [
        'audited' => 0,
        'failed' => 0,
        'errors' => [],
        'stats' => [
            'totalLinks' => 0,
            'brokenLinks' => 0,
            'suboptimalLinks' => 0,
            'missingOpportunities' => 0
        ],
        'issues' => []
    ];

    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            $results['failed']++;
            $results['errors'][] = ['postId' => $post_id, 'error' => 'Post not found'];
            continue;
        }

        $audit = $api->audit_links($post_id);

        if (is_wp_error($audit)) {
            $results['failed']++;
            $results['errors'][] = ['postId' => $post_id, 'error' => $audit->get_error_message()];
            continue;
        }

        $results['audited']++;

        // Collect issues for this post
        $post_issues = [];
        $post_stats = [
            'totalLinks' => 0,
            'brokenLinks' => 0,
            'suboptimalLinks' => 0,
            'missingOpportunities' => 0
        ];

        // Aggregate stats
        if (isset($audit['audit']['stats'])) {
            $stats = $audit['audit']['stats'];
            $post_stats['totalLinks'] = $stats['totalLinks'] ?? 0;
            $post_stats['brokenLinks'] = $stats['brokenLinks'] ?? 0;
            $post_stats['suboptimalLinks'] = $stats['suboptimalLinks'] ?? 0;
            $post_stats['missingOpportunities'] = $stats['missingOpportunities'] ?? 0;

            $results['stats']['totalLinks'] += $post_stats['totalLinks'];
            $results['stats']['brokenLinks'] += $post_stats['brokenLinks'];
            $results['stats']['suboptimalLinks'] += $post_stats['suboptimalLinks'];
            $results['stats']['missingOpportunities'] += $post_stats['missingOpportunities'];
        }

        // Collect issues
        if (isset($audit['audit'])) {
            $a = $audit['audit'];

            // Broken links
            if (!empty($a['existing']['broken'])) {
                foreach ($a['existing']['broken'] as $broken) {
                    $issue = [
                        'type' => 'broken',
                        'postId' => $post_id,
                        'postTitle' => $post->post_title,
                        'anchor' => $broken['anchor'],
                        'url' => $broken['url'],
                        'issue' => $broken['issue']
                    ];
                    $results['issues'][] = $issue;
                    $post_issues[] = $issue;
                }
            }

            // Suboptimal links
            if (!empty($a['existing']['suboptimal'])) {
                foreach ($a['existing']['suboptimal'] as $sub) {
                    $issue = [
                        'type' => 'suboptimal',
                        'postId' => $post_id,
                        'postTitle' => $post->post_title,
                        'anchor' => $sub['anchor'],
                        'currentTarget' => $sub['currentTarget']['title'] ?? '',
                        'currentUrl' => $sub['currentTarget']['url'] ?? '',
                        'betterOption' => $sub['betterOptions'][0]['title'] ?? '',
                        'betterUrl' => $sub['betterOptions'][0]['url'] ?? ''
                    ];
                    $results['issues'][] = $issue;
                    $post_issues[] = $issue;
                }
            }

            // Missing opportunities (suggested new links - only where anchor text was found)
            if (!empty($a['suggestions']['missing'])) {
                foreach ($a['suggestions']['missing'] as $missing) {
                    $issue = [
                        'type' => 'missing',
                        'postId' => $post_id,
                        'postTitle' => $post->post_title,
                        'targetPostId' => $missing['postId'] ?? 0,
                        'targetTitle' => $missing['title'] ?? '',
                        'targetUrl' => $missing['url'] ?? '',
                        'topicCluster' => $missing['topicCluster'] ?? '',
                        'score' => $missing['score'] ?? 0,
                        'anchorText' => $missing['anchorText'] ?? '',
                        'anchorContext' => $missing['anchorContext'] ?? '',
                        'reason' => $missing['reason'] ?? ''
                    ];
                    $results['issues'][] = $issue;
                    $post_issues[] = $issue;
                }
            }
        }

        // Cache per-post audit results
        update_post_meta($post_id, '_lendcity_audit_cache', [
            'stats' => $post_stats,
            'issues' => $post_issues,
            'audited_at' => current_time('mysql'),
            'post_modified' => $post->post_modified
        ]);
    }

    // Update global audit cache
    lendcity_update_audit_cache();

    wp_send_json_success($results);
}

/**
 * Update the global audit cache by aggregating all per-post caches
 */
function lendcity_update_audit_cache() {
    global $wpdb;

    $cached_stats = [
        'totalLinks' => 0,
        'brokenLinks' => 0,
        'suboptimalLinks' => 0,
        'missingOpportunities' => 0
    ];
    $cached_issues = [];
    $posts_audited = 0;
    $posts_stale = 0;

    // Get all posts with audit cache
    $posts_with_cache = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_modified, pm.meta_value
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE pm.meta_key = '_lendcity_audit_cache'
         AND p.post_status = 'publish'
         AND p.post_type IN ('post', 'page')"
    );

    foreach ($posts_with_cache as $row) {
        $cache = maybe_unserialize($row->meta_value);
        if (!$cache) continue;

        // Check if cache is stale (post modified after audit)
        $is_stale = strtotime($row->post_modified) > strtotime($cache['post_modified'] ?? $cache['audited_at']);

        if ($is_stale) {
            $posts_stale++;
        }

        $posts_audited++;

        // Aggregate stats
        if (!empty($cache['stats'])) {
            $cached_stats['totalLinks'] += $cache['stats']['totalLinks'] ?? 0;
            $cached_stats['brokenLinks'] += $cache['stats']['brokenLinks'] ?? 0;
            $cached_stats['suboptimalLinks'] += $cache['stats']['suboptimalLinks'] ?? 0;
            $cached_stats['missingOpportunities'] += $cache['stats']['missingOpportunities'] ?? 0;
        }

        // Collect issues (update post title in case it changed)
        if (!empty($cache['issues'])) {
            foreach ($cache['issues'] as $issue) {
                $issue['postTitle'] = $row->post_title;
                $issue['isStale'] = $is_stale;
                $cached_issues[] = $issue;
            }
        }
    }

    // Get total publishable posts count
    $total_posts = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ('post', 'page')"
    );

    // Save global cache
    update_option('lendcity_audit_cache', [
        'stats' => $cached_stats,
        'issues' => $cached_issues,
        'posts_audited' => $posts_audited,
        'posts_total' => (int) $total_posts,
        'posts_stale' => $posts_stale,
        'updated_at' => current_time('mysql')
    ], false);
}

/**
 * AJAX handler: Get cached audit results
 */
add_action('wp_ajax_lendcity_get_audit_cache', 'lendcity_get_audit_cache');

function lendcity_get_audit_cache() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $cache = get_option('lendcity_audit_cache', null);

    if (!$cache) {
        wp_send_json_success([
            'has_cache' => false,
            'message' => 'No audit data cached yet. Run an audit to populate.'
        ]);
    }

    wp_send_json_success([
        'has_cache' => true,
        'stats' => $cache['stats'],
        'issues' => $cache['issues'],
        'posts_audited' => $cache['posts_audited'],
        'posts_total' => $cache['posts_total'],
        'posts_stale' => $cache['posts_stale'],
        'updated_at' => $cache['updated_at']
    ]);
}

/**
 * AJAX handler: Get list of posts needing audit (new or modified since last audit)
 */
add_action('wp_ajax_lendcity_get_stale_posts', 'lendcity_get_stale_posts');

function lendcity_get_stale_posts() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    global $wpdb;

    // Get posts that either have no cache or are stale
    $stale_posts = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_modified,
                pm.meta_value as audit_cache
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lendcity_audit_cache'
         WHERE p.post_status = 'publish'
         AND p.post_type IN ('post', 'page')
         HAVING audit_cache IS NULL
            OR p.post_modified > SUBSTRING_INDEX(SUBSTRING_INDEX(audit_cache, '\"post_modified\";s:', -1), '\"', 2)"
    );

    $items = [];
    foreach ($stale_posts as $post) {
        $items[] = [
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'modified' => $post->post_modified
        ];
    }

    wp_send_json_success([
        'items' => $items,
        'count' => count($items)
    ]);
}

/**
 * Schedule background audit when a post is saved
 */
add_action('save_post', 'lendcity_schedule_post_audit', 100, 2);

function lendcity_schedule_post_audit($post_id, $post) {
    // Skip autosaves, revisions, and non-published posts
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if ($post->post_status !== 'publish') return;
    if (!in_array($post->post_type, ['post', 'page'])) return;

    // Clear any existing scheduled audit for this post
    wp_clear_scheduled_hook('lendcity_background_audit_post', [$post_id]);

    // Schedule audit in 30 seconds
    wp_schedule_single_event(time() + 30, 'lendcity_background_audit_post', [$post_id]);

    // Mark the post's audit cache as stale
    $cache = get_post_meta($post_id, '_lendcity_audit_cache', true);
    if ($cache) {
        $cache['is_stale'] = true;
        update_post_meta($post_id, '_lendcity_audit_cache', $cache);
    }
}

/**
 * Background audit handler for a single post
 */
add_action('lendcity_background_audit_post', 'lendcity_run_background_audit');

function lendcity_run_background_audit($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    $api = new LendCity_External_API();
    if (!$api->is_configured()) return;

    $audit = $api->audit_links($post_id);
    if (is_wp_error($audit)) {
        error_log('LendCity background audit failed for post ' . $post_id . ': ' . $audit->get_error_message());
        return;
    }

    // Process and cache the audit results
    $post_issues = [];
    $post_stats = [
        'totalLinks' => 0,
        'brokenLinks' => 0,
        'suboptimalLinks' => 0,
        'missingOpportunities' => 0
    ];

    if (isset($audit['audit']['stats'])) {
        $stats = $audit['audit']['stats'];
        $post_stats['totalLinks'] = $stats['totalLinks'] ?? 0;
        $post_stats['brokenLinks'] = $stats['brokenLinks'] ?? 0;
        $post_stats['suboptimalLinks'] = $stats['suboptimalLinks'] ?? 0;
        $post_stats['missingOpportunities'] = $stats['missingOpportunities'] ?? 0;
    }

    if (isset($audit['audit'])) {
        $a = $audit['audit'];

        if (!empty($a['existing']['broken'])) {
            foreach ($a['existing']['broken'] as $broken) {
                $post_issues[] = [
                    'type' => 'broken',
                    'postId' => $post_id,
                    'postTitle' => $post->post_title,
                    'anchor' => $broken['anchor'],
                    'url' => $broken['url'],
                    'issue' => $broken['issue']
                ];
            }
        }

        if (!empty($a['existing']['suboptimal'])) {
            foreach ($a['existing']['suboptimal'] as $sub) {
                $post_issues[] = [
                    'type' => 'suboptimal',
                    'postId' => $post_id,
                    'postTitle' => $post->post_title,
                    'anchor' => $sub['anchor'],
                    'currentTarget' => $sub['currentTarget']['title'] ?? '',
                    'currentUrl' => $sub['currentTarget']['url'] ?? '',
                    'betterOption' => $sub['betterOptions'][0]['title'] ?? '',
                    'betterUrl' => $sub['betterOptions'][0]['url'] ?? ''
                ];
            }
        }

        if (!empty($a['suggestions']['missing'])) {
            foreach ($a['suggestions']['missing'] as $missing) {
                $post_issues[] = [
                    'type' => 'missing',
                    'postId' => $post_id,
                    'postTitle' => $post->post_title,
                    'targetPostId' => $missing['postId'] ?? 0,
                    'targetTitle' => $missing['title'] ?? '',
                    'targetUrl' => $missing['url'] ?? '',
                    'topicCluster' => $missing['topicCluster'] ?? '',
                    'score' => $missing['score'] ?? 0,
                    'reason' => $missing['reason'] ?? ''
                ];
            }
        }
    }

    // Save per-post cache
    update_post_meta($post_id, '_lendcity_audit_cache', [
        'stats' => $post_stats,
        'issues' => $post_issues,
        'audited_at' => current_time('mysql'),
        'post_modified' => $post->post_modified,
        'is_stale' => false
    ]);

    // Update global cache
    lendcity_update_audit_cache();
}

/**
 * AJAX handler: Fix a link issue (remove broken link or swap to better target)
 */
add_action('wp_ajax_lendcity_fix_link', 'lendcity_fix_link');

function lendcity_fix_link() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $action_type = isset($_POST['fix_type']) ? sanitize_text_field($_POST['fix_type']) : '';
    $anchor = isset($_POST['anchor']) ? wp_unslash($_POST['anchor']) : '';
    $old_url = isset($_POST['old_url']) ? esc_url_raw($_POST['old_url']) : '';
    $new_url = isset($_POST['new_url']) ? esc_url_raw($_POST['new_url']) : '';

    if (!$post_id || !$action_type || !$anchor) {
        wp_send_json_error(['message' => 'Missing required parameters']);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Post not found']);
    }

    $content = $post->post_content;
    $updated = false;

    if ($action_type === 'remove_broken') {
        // Remove the broken link but keep the anchor text
        // Match: <a href="old_url"...>anchor</a> and replace with just anchor
        $pattern = '/<a\s+[^>]*href=["\']' . preg_quote($old_url, '/') . '["\'][^>]*>' . preg_quote($anchor, '/') . '<\/a>/i';
        $new_content = preg_replace($pattern, $anchor, $content);

        if ($new_content !== $content) {
            $updated = true;
            $content = $new_content;
        }
    } elseif ($action_type === 'swap_link') {
        // Swap the link to a better target
        if (!$new_url) {
            wp_send_json_error(['message' => 'New URL required for swap']);
        }

        // Match the anchor link and replace the href
        $pattern = '/(<a\s+[^>]*href=["\'])' . preg_quote($old_url, '/') . '(["\'][^>]*>' . preg_quote($anchor, '/') . '<\/a>)/i';
        $replacement = '${1}' . $new_url . '${2}';
        $new_content = preg_replace($pattern, $replacement, $content);

        if ($new_content !== $content) {
            $updated = true;
            $content = $new_content;
        }
    } elseif ($action_type === 'ignore') {
        // Just mark as acknowledged - no changes needed
        wp_send_json_success(['message' => 'Issue ignored', 'changed' => false]);
    } else {
        wp_send_json_error(['message' => 'Unknown action type']);
    }

    if ($updated) {
        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $content
        ], true);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'Failed to update post: ' . $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Link fixed successfully', 'changed' => true]);
    } else {
        wp_send_json_error(['message' => 'Could not find the link to fix. The content may have changed.']);
    }
}

/**
 * AJAX handler: Accept a missed link opportunity
 * Uses pre-identified anchor text from audit - NO Claude API call needed!
 */
add_action('wp_ajax_lendcity_accept_opportunity', 'lendcity_accept_opportunity');

function lendcity_accept_opportunity() {
    check_ajax_referer('lendcity_link_audit', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $target_post_id = isset($_POST['target_post_id']) ? intval($_POST['target_post_id']) : 0;
    $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';
    $target_title = isset($_POST['target_title']) ? sanitize_text_field($_POST['target_title']) : '';
    $anchor_text = isset($_POST['anchor_text']) ? wp_unslash($_POST['anchor_text']) : '';

    if (!$post_id || !$target_url) {
        wp_send_json_error(['message' => 'Missing required parameters']);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(['message' => 'Source post not found']);
    }

    $content = $post->post_content;
    $link_meta = get_post_meta($post_id, '_lendcity_smart_links', true) ?: [];

    // Check if already linked to this URL
    foreach ($link_meta as $existing) {
        if ($existing['url'] === $target_url) {
            wp_send_json_error(['message' => 'This link already exists in the post']);
        }
    }

    // If anchor text was provided from audit, use it directly (no API call!)
    if (!empty($anchor_text)) {
        // Verify the anchor text exists in content and is not already linked
        $pattern = '/(?<!["\'>])(' . preg_quote($anchor_text, '/') . ')(?![^<]*>)(?![^<]*<\/a>)/i';
        $replacement = '<a href="' . esc_url($target_url) . '">$1</a>';

        $new_content = preg_replace($pattern, $replacement, $content, 1, $count);

        if ($count > 0) {
            // Success! Update the post
            $link_meta[] = [
                'link_id' => uniqid('lnk_'),
                'anchor' => $anchor_text,
                'url' => $target_url,
                'target_id' => $target_post_id,
                'score' => 75,
                'created' => current_time('mysql'),
                'source' => 'audit_opportunity'
            ];

            wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content
            ]);
            update_post_meta($post_id, '_lendcity_smart_links', $link_meta);

            // Update audit cache to remove this opportunity
            lendcity_update_audit_cache();

            wp_send_json_success([
                'message' => 'Link added: "' . $anchor_text . '" → ' . $target_title,
                'anchor_used' => $anchor_text
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Anchor text "' . $anchor_text . '" not found or already linked. The content may have changed since the audit.'
            ]);
        }
        return;
    }

    // Fallback: No anchor provided, try to find one from target title
    $title_words = explode(' ', $target_title);
    $potential_anchors = [];

    // Generate potential anchor texts from title (longer phrases first)
    for ($len = min(5, count($title_words)); $len >= 2; $len--) {
        for ($i = 0; $i <= count($title_words) - $len; $i++) {
            $phrase = implode(' ', array_slice($title_words, $i, $len));
            if (strlen($phrase) >= 8) {
                $potential_anchors[] = $phrase;
            }
        }
    }

    foreach ($potential_anchors as $anchor) {
        $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*>)(?![^<]*<\/a>)/i';
        $replacement = '<a href="' . esc_url($target_url) . '">$1</a>';

        $new_content = preg_replace($pattern, $replacement, $content, 1, $count);

        if ($count > 0) {
            $link_meta[] = [
                'link_id' => uniqid('lnk_'),
                'anchor' => $anchor,
                'url' => $target_url,
                'target_id' => $target_post_id,
                'score' => 50,
                'created' => current_time('mysql'),
                'source' => 'audit_opportunity_fallback'
            ];

            wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content
            ]);
            update_post_meta($post_id, '_lendcity_smart_links', $link_meta);

            lendcity_update_audit_cache();

            wp_send_json_success([
                'message' => 'Link added using anchor: "' . $anchor . '"',
                'anchor_used' => $anchor
            ]);
        }
    }

    wp_send_json_error(['message' => 'Could not find suitable anchor text in the post for this link']);
}

/**
 * AJAX handler: Get Pinecone catalog stats
 */
add_action('wp_ajax_lendcity_get_pinecone_stats', 'lendcity_get_pinecone_stats');

function lendcity_get_pinecone_stats() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $api = new LendCity_External_API();
    if (!$api->is_configured()) {
        wp_send_json_error(['message' => 'API not configured']);
    }

    // Get stats from Pinecone via our API
    $response = wp_remote_get(
        rtrim($api->get_api_url(), '/') . '/api/stats',
        [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api->get_api_key(),
                'Content-Type' => 'application/json'
            ]
        ]
    );

    if (is_wp_error($response)) {
        // Fall back to counting WordPress posts with sync meta
        $synced_count = 0;
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_lendcity_pinecone_synced',
                    'value' => '1'
                ]
            ]
        ]);
        $synced_count = count($posts);

        $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;

        wp_send_json_success([
            'catalogued' => $synced_count,
            'total_wp' => $total_posts,
            'source' => 'wordpress_meta'
        ]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['vectorCount'])) {
        $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;

        wp_send_json_success([
            'catalogued' => $body['vectorCount'],
            'total_wp' => $total_posts,
            'source' => 'pinecone'
        ]);
    } else {
        // Fall back to WordPress meta count
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => '_lendcity_pinecone_synced',
                    'value' => '1'
                ]
            ]
        ]);

        $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;

        wp_send_json_success([
            'catalogued' => count($posts),
            'total_wp' => $total_posts,
            'source' => 'wordpress_meta'
        ]);
    }
}

/**
 * AJAX handler: Get sync list with optional skip for already synced
 */
add_action('wp_ajax_lendcity_get_sync_list_filtered', 'lendcity_get_sync_list_filtered');

function lendcity_get_sync_list_filtered() {
    check_ajax_referer('lendcity_bulk_sync', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    $skip_synced = isset($_POST['skip_synced']) && $_POST['skip_synced'] === 'true';

    $items = [];

    // Get pillar pages first
    $pillar_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_lendcity_is_pillar',
                'value' => '1'
            ]
        ]
    ]);

    foreach ($pillar_pages as $page) {
        if ($skip_synced && get_post_meta($page->ID, '_lendcity_pinecone_synced', true) === '1') {
            continue;
        }
        $items[] = ['id' => $page->ID, 'title' => $page->post_title, 'type' => 'pillar'];
    }

    // Get other pages
    $pillar_ids = wp_list_pluck($pillar_pages, 'ID');
    $other_pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'numberposts' => -1,
        'post__not_in' => !empty($pillar_ids) ? $pillar_ids : [0]
    ]);

    foreach ($other_pages as $page) {
        if ($skip_synced && get_post_meta($page->ID, '_lendcity_pinecone_synced', true) === '1') {
            continue;
        }
        $items[] = ['id' => $page->ID, 'title' => $page->post_title, 'type' => 'page'];
    }

    // Get posts
    $posts = get_posts([
        'post_type' => 'post',
        'post_status' => 'publish',
        'numberposts' => -1
    ]);

    foreach ($posts as $post) {
        if ($skip_synced && get_post_meta($post->ID, '_lendcity_pinecone_synced', true) === '1') {
            continue;
        }
        $items[] = ['id' => $post->ID, 'title' => $post->post_title, 'type' => 'post'];
    }

    wp_send_json_success([
        'items' => $items,
        'total' => count($items),
        'skipped_synced' => $skip_synced
    ]);
}
