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

        foreach ($suggestions['links'] as $suggestion) {
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

            // Find and replace anchor text with link (first occurrence only)
            $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*>)(?![^<]*<\/a>)/i';
            $replacement = '<a href="' . esc_url($url) . '">' . $anchor . '</a>';

            $new_content = preg_replace($pattern, $replacement, $updated_content, 1, $count);

            if ($count > 0) {
                $updated_content = $new_content;
                $link_meta[] = [
                    'anchor' => $anchor,
                    'url' => $url,
                    'target_id' => $suggestion['postId'] ?? 0,
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
            'suggestions_count' => count($suggestions['links'])
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
