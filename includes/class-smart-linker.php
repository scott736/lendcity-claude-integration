<?php
/**
 * Smart Linker Class v2.2 - Optimized
 * Claude-powered internal linking system
 * 
 * REVERSED LOGIC:
 * - Select a TARGET page/post you want links TO
 * - Claude finds all posts that should link TO your target
 * - Inserts links in those source posts pointing to your target
 * 
 * Features:
 * - Catalog of all posts AND pages with AI summaries
 * - Background queue processing for bulk operations (scales to 1000+ posts)
 * - "Trust AI" one-click bulk linking
 * - Full link management and URL updates
 * - Debug mode toggle for cleaner logs
 */

if (!defined('ABSPATH')) {
    exit;
}

class LendCity_Smart_Linker {

    private $api_key;
    private $link_meta_key = '_lendcity_smart_links';
    private $original_content_meta = '_lendcity_original_content';
    private $queue_option = 'lendcity_smart_linker_queue';
    private $queue_status_option = 'lendcity_smart_linker_queue_status';

    /**
     * Catalog Database instance
     *
     * PERFORMANCE NOTE: This replaces the old serialized wp_options storage.
     * The LendCity_Catalog_DB class stores each post as a separate row with
     * proper indexing, enabling fast lookups and updates without loading
     * the entire catalog into memory.
     *
     * @var LendCity_Catalog_DB
     */
    private $catalog_db = null;

    // SEO Enhancement meta keys
    private $priority_meta_key = '_lendcity_link_priority'; // 1-5, higher = more links
    private $keywords_meta_key = '_lendcity_target_keywords'; // comma-separated keywords for anchor text

    public function __construct() {
        $this->api_key = get_option('lendcity_claude_api_key');

        // Initialize the catalog database handler
        // This creates the table if needed and handles migration from wp_options
        $this->catalog_db = new LendCity_Catalog_DB();
        
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
    
    /**
     * Debug logging helper - only logs if debug mode is enabled
     */
    private function debug_log($message) {
        if (get_option('lendcity_debug_mode', 'no') === 'yes') {
            error_log('LendCity Smart Linker: ' . $message);
        }
    }
    
    /**
     * Always log - for important messages (errors, success)
     */
    private function log($message) {
        error_log('LendCity Smart Linker: ' . $message);
    }
    
    /**
     * Auto-catalog and auto-link when a post is published
     * Schedules background processing to avoid blocking the save and rate limits
     */
    public function on_post_publish($new_status, $old_status, $post) {
        // Only trigger when transitioning TO publish
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        
        // Only for posts (pages are manually managed)
        if ($post->post_type !== 'post') {
            return;
        }
        
        // Check if auto-linking is enabled
        if (get_option('lendcity_smart_linker_auto', 'yes') !== 'yes') {
            return;
        }
        
        // Check if API key exists
        if (empty($this->api_key)) {
            return;
        }
        
        error_log('LendCity Smart Linker: Scheduling auto-link for new post ' . $post->ID . ' - ' . $post->post_title);
        
        // Schedule background processing in 60 seconds (avoids blocking save, respects rate limits)
        wp_schedule_single_event(time() + 60, 'lendcity_auto_link_new_post', array($post->ID));
    }
    
    /**
     * Handle post deletion - remove from catalog and clean up links
     */
    public function on_post_delete($post_id) {
        $this->cleanup_deleted_post($post_id);
    }
    
    /**
     * Handle post trash - remove from catalog and clean up links
     */
    public function on_post_trash($post_id) {
        $this->cleanup_deleted_post($post_id);
    }
    
    /**
     * Clean up when a post is deleted or trashed
     * 1. Remove from catalog
     * 2. Remove all links pointing TO this post from other posts
     * 3. Clean up link meta
     */
    private function cleanup_deleted_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        // Only handle posts and pages
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }
        
        $this->debug_log('Cleaning up deleted post ' . $post_id . ' - ' . $post->post_title);

        // 1. Remove from catalog (using optimized database table)
        if ($this->catalog_db->entry_exists($post_id)) {
            $this->catalog_db->delete_entry($post_id);
            $this->debug_log('Removed post ' . $post_id . ' from catalog');
        }
        
        // 2. Get the URL of the deleted post to find links pointing to it
        $deleted_url = get_permalink($post_id);
        if (!$deleted_url) {
            $deleted_url = home_url('/?p=' . $post_id); // Fallback
        }
        
        // 3. Find and remove all links pointing TO this post
        $links_removed = $this->remove_links_to_post($post_id, $deleted_url);
        
        if ($links_removed > 0) {
            $this->log('Removed ' . $links_removed . ' links pointing to deleted post ' . $post_id);
        }
        
        // 4. Clean up any link meta on the deleted post itself
        delete_post_meta($post_id, $this->link_meta_key);
        delete_post_meta($post_id, $this->original_content_meta);
    }
    
    /**
     * Remove all links pointing to a specific post from other posts
     */
    private function remove_links_to_post($target_post_id, $target_url) {
        global $wpdb;
        
        $links_removed = 0;
        
        // Find all posts that have links to this target
        $posts_with_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.post_id, pm.meta_value 
                FROM {$wpdb->postmeta} pm 
                WHERE pm.meta_key = %s",
                $this->link_meta_key
            )
        );
        
        foreach ($posts_with_links as $row) {
            $source_post_id = $row->post_id;
            $links = maybe_unserialize($row->meta_value);
            
            if (!is_array($links)) {
                continue;
            }
            
            // Check if any links point to our target
            $links_to_remove = array();
            foreach ($links as $index => $link) {
                if (isset($link['target_post_id']) && intval($link['target_post_id']) === intval($target_post_id)) {
                    $links_to_remove[] = $link;
                    unset($links[$index]);
                }
            }
            
            if (empty($links_to_remove)) {
                continue;
            }
            
            // Remove the actual link HTML from post content
            $post = get_post($source_post_id);
            if (!$post) {
                continue;
            }
            
            $content = $post->post_content;
            $original_content = $content;
            
            foreach ($links_to_remove as $link) {
                if (isset($link['link_id'])) {
                    // Remove by link_id attribute
                    $pattern = '/<a[^>]*data-link-id="' . preg_quote($link['link_id'], '/') . '"[^>]*>([^<]*)<\/a>/i';
                    $content = preg_replace($pattern, '$1', $content);
                }
            }
            
            // If content changed, update the post
            if ($content !== $original_content) {
                wp_update_post(array(
                    'ID' => $source_post_id,
                    'post_content' => $content
                ));
                $links_removed += count($links_to_remove);
                $this->debug_log('Removed ' . count($links_to_remove) . ' link(s) from post ' . $source_post_id);
            }
            
            // Update the links meta (re-index array)
            $links = array_values($links);
            if (empty($links)) {
                delete_post_meta($source_post_id, $this->link_meta_key);
            } else {
                update_post_meta($source_post_id, $this->link_meta_key, $links);
            }
        }
        
        return $links_removed;
    }
    
    /**
     * Background task: Catalog and link a newly published post
     */
    public function process_new_post_auto_link($post_id) {
        // Prevent double processing with a transient lock
        $lock_key = 'lendcity_processing_' . $post_id;
        if (get_transient($lock_key)) {
            $this->debug_log('Post ' . $post_id . ' already being processed - skipping duplicate');
            return;
        }
        set_transient($lock_key, true, 300); // 5 minute lock
        
        // Clear cache to ensure we get fresh post data
        clean_post_cache($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            $this->debug_log('Auto-link skipped - post ' . $post_id . ' not found or not published');
            delete_transient($lock_key);
            return;
        }
        
        // Check if already has links (already processed)
        $existing_links = get_post_meta($post_id, $this->link_meta_key, true);
        $this->debug_log('Post ' . $post_id . ' - existing_links: ' . (is_array($existing_links) ? count($existing_links) . ' links' : 'none'));
        if (!empty($existing_links) && is_array($existing_links) && count($existing_links) > 0) {
            $this->debug_log('Post ' . $post_id . ' already has links - skipping');
            delete_transient($lock_key);
            return;
        }
        
        $this->debug_log('Processing auto-link for post ' . $post_id);
        
        // Add to catalog (using optimized database table)
        $entry = $this->build_single_post_catalog($post_id);
        if ($entry) {
            $this->catalog_db->save_entry($post_id, $entry);
            $this->debug_log('Added post ' . $post_id . ' to catalog');
        }
        
        // Create outgoing links FROM this new post
        $result = $this->create_links_from_source($post_id);
        $this->log('Auto-link result for post ' . $post_id . ' - ' . ($result['success'] ? 'Success: ' . ($result['links_created'] ?? 0) . ' links' : $result['message']));
        
        // Release lock
        delete_transient($lock_key);
    }
    
    /**
     * Create outgoing links FROM a source post
     * Priority: 1 page link (if relevant) + up to 7 post links
     * No duplicate anchor text allowed
     */
    public function create_links_from_source($source_id) {
        $source = get_post($source_id);
        if (!$source || $source->post_status !== 'publish') {
            return array('success' => false, 'message' => 'Source post not found or not published');
        }
        
        $catalog = $this->get_catalog();
        if (empty($catalog)) {
            return array('success' => false, 'message' => 'Catalog not built');
        }
        
        // Get existing links from this source
        $existing_links = get_post_meta($source_id, $this->link_meta_key, true) ?: array();
        $current_link_count = count($existing_links);
        
        $this->debug_log("Post {$source_id} - existing links: {$current_link_count}");
        
        if ($current_link_count >= 8) {
            return array('success' => false, 'message' => 'Source post already has 8 links');
        }
        
        // Check how many page links and post links already exist
        $existing_page_links = 0;
        $used_anchors = array();
        $linked_urls = array();
        
        foreach ($existing_links as $link) {
            $used_anchors[] = strtolower($link['anchor']);
            $linked_urls[] = $link['url'];
            if (!empty($link['is_page'])) {
                $existing_page_links++;
            }
        }
        
        // Determine slots available - 3 page links max, 7 post links max
        $max_page_links = 3;
        $max_post_links = 7;
        
        $page_slots = max(0, $max_page_links - $existing_page_links);
        $post_slots = max(0, $max_post_links - ($current_link_count - $existing_page_links));
        
        if ($page_slots == 0 && $post_slots <= 0) {
            return array('success' => false, 'message' => 'No link slots available');
        }
        
        // Separate pages and posts from catalog
        $available_pages = array();
        $available_posts = array();
        
        foreach ($catalog as $id => $entry) {
            if ($id == $source_id) continue;
            if (in_array($entry['url'], $linked_urls)) continue;
            
            if (isset($entry['is_page']) && $entry['is_page']) {
                $available_pages[$id] = $entry;
            } else {
                $available_posts[$id] = $entry;
            }
        }
        
        // Get source post content for context - FULL article for proper link distribution
        $source_content = wp_strip_all_tags($source->post_content);
        if (strlen($source_content) > 10000) {
            $source_content = substr($source_content, 0, 10000) . '...';
        }
        
        $source_entry = isset($catalog[$source_id]) ? $catalog[$source_id] : null;
        $source_topics = $source_entry ? implode(', ', $source_entry['main_topics']) : '';
        
        // Build prompt
        $prompt = "You are an SEO expert creating internal links for a blog post.\n\n";
        $prompt .= "=== SOURCE POST (we are adding links TO this post's content) ===\n";
        $prompt .= "Title: " . $source->post_title . "\n";
        $prompt .= "Topics: " . $source_topics . "\n";
        $prompt .= "Content Preview:\n" . $source_content . "\n\n";
        
        if (!empty($used_anchors)) {
            $prompt .= "ALREADY USED ANCHORS IN THIS POST (DO NOT USE THESE):\n";
            $prompt .= implode(', ', $used_anchors) . "\n\n";
        }
        
        $links_requested = array();
        
        // Page linking section - now with priority and keywords
        if ($page_slots > 0 && !empty($available_pages)) {
            $prompt .= "=== AVAILABLE PAGES ===\n";
            $count = 0;
            
            // Sort pages by priority (highest first)
            uasort($available_pages, function($a, $b) {
                $prio_a = $this->get_page_priority($a['post_id'] ?? 0);
                $prio_b = $this->get_page_priority($b['post_id'] ?? 0);
                return $prio_b - $prio_a;
            });
            
            foreach ($available_pages as $id => $entry) {
                if ($count >= 10) break; // Reduced from 15
                $priority = $this->get_page_priority($id);
                $keywords = $this->get_page_keywords($id);
                
                $prompt .= "ID:" . $id . " | " . $entry['title'] . " | P:" . $priority;
                if ($keywords) {
                    $prompt .= " | ANCHORS: " . $keywords;
                }
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to " . $page_slots . " page links";
        }
        
        // Post linking section
        if ($post_slots > 0 && !empty($available_posts)) {
            $prompt .= "=== AVAILABLE POSTS ===\n";
            $count = 0;
            foreach ($available_posts as $id => $entry) {
                if ($count >= 15) break; // Reduced from 30
                $prompt .= "ID:" . $id . " | " . $entry['title'] . "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to " . $post_slots . " post links";
        }
        
        $prompt .= "=== TASK ===\n";
        $prompt .= "Find: " . implode(' + ', $links_requested) . "\n\n";
        $prompt .= "RULES:\n";
        $prompt .= "1. Anchor text: 2-5 words that exist in source content and describe the target\n";
        $prompt .= "2. EACH ANCHOR MUST BE UNIQUE - never use the same anchor text twice in one article\n";
        $prompt .= "3. Higher priority pages (P:4-5) preferred. Use ANCHORS keywords if listed.\n";
        $prompt .= "4. Spread links throughout article (beginning, middle, end). Max 1 link per paragraph.\n";
        $prompt .= "5. Quality over quantity - skip if no good match.\n";
        $prompt .= "6. If you can't find a meaningful anchor phrase, DO NOT force a bad one - skip that target\n\n";
        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"target_id": 123, "anchor_text": "meaningful descriptive phrase", "is_page": true/false}, ...]\n';
        $prompt .= 'Return empty array [] if no good opportunities.\n';
        
        $response = $this->call_claude_api($prompt, 1500);
        
        if (!$response['success']) {
            return array('success' => false, 'message' => 'API error: ' . $response['error']);
        }
        
        $suggestions = json_decode($response['text'], true);
        if (!$suggestions && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $suggestions = json_decode($matches[0], true);
        }
        
        if (!is_array($suggestions) || empty($suggestions)) {
            return array('success' => true, 'message' => 'No relevant linking opportunities found', 'links_created' => 0);
        }
        
        // Insert links
        $links_created = array();
        $errors = array();
        $page_links_added = $existing_page_links;
        $post_links_added = $current_link_count - $existing_page_links;
        $anchors_used_this_session = array(); // Track anchors used in this batch
        
        foreach ($suggestions as $suggestion) {
            $target_id = intval($suggestion['target_id']);
            $anchor = sanitize_text_field($suggestion['anchor_text']);
            $is_page = !empty($suggestion['is_page']);
            
            if (!$target_id || !$anchor) continue;
            
            // Check for duplicate anchor text (case-insensitive)
            $anchor_lower = strtolower(trim($anchor));
            if (in_array($anchor_lower, $used_anchors) || in_array($anchor_lower, $anchors_used_this_session)) {
                $errors[] = "Skipped duplicate anchor: '{$anchor}'";
                continue;
            }
            
            // Enforce 3 page max, 7 post max
            if ($is_page && $page_links_added >= 3) continue;
            if (!$is_page && $post_links_added >= 7) continue;
            
            // Check we're not over total limit (10 = 3 pages + 7 posts)
            if (count($links_created) + $current_link_count >= 10) break;
            
            $target_entry = isset($catalog[$target_id]) ? $catalog[$target_id] : null;
            if (!$target_entry) continue;
            
            $result = $this->insert_link_in_post($source_id, $anchor, $target_entry['url'], $target_id, $is_page);
            
            if ($result['success']) {
                $links_created[] = array(
                    'target_id' => $target_id,
                    'target_title' => $target_entry['title'],
                    'anchor' => $anchor,
                    'is_page' => $is_page
                );
                $anchors_used_this_session[] = $anchor_lower; // Track this anchor
                if ($is_page) {
                    $page_links_added++;
                } else {
                    $post_links_added++;
                }
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
     * Build catalog for a single post or page
     */
    public function build_single_post_catalog($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            error_log('LendCity Smart Linker: Post ' . $post_id . ' not found or not published');
            return false;
        }
        
        // Get content - try rendered content first for page builders
        $content = wp_strip_all_tags($post->post_content);
        
        // If content is very short, try to get rendered content
        if (strlen($content) < 100) {
            // Try getting the rendered/filtered content
            $rendered = apply_filters('the_content', $post->post_content);
            $content = wp_strip_all_tags($rendered);
        }
        
        // For pages with minimal content, use title and URL context
        if (strlen($content) < 50) {
            $content = "Page title: " . $post->post_title . ". URL slug: " . $post->post_name;
            error_log('LendCity Smart Linker: Post ' . $post_id . ' has minimal content, using title/URL');
        }
        
        // Full article - up to 10000 chars for comprehensive analysis
        if (strlen($content) > 10000) {
            $content = substr($content, 0, 10000) . '...';
        }
        
        $is_page = ($post->post_type === 'page');
        
        $prompt = "Analyze this " . ($is_page ? "SERVICE PAGE" : "blog post") . " for internal linking purposes.\n\n";
        $prompt .= "TITLE: " . $post->post_title . "\n";
        $prompt .= "URL: " . get_permalink($post_id) . "\n\n";
        $prompt .= "CONTENT:\n" . $content . "\n\n";
        
        if ($is_page) {
            $prompt .= "NOTE: This is a HIGH-VALUE SERVICE PAGE for a mortgage business. Even if content is minimal, analyze based on title and URL.\n\n";
        }
        
        $prompt .= "Respond with ONLY a JSON object:\n";
        $prompt .= "{\n";
        $prompt .= '  "summary": "4-5 sentence summary covering the main points, key advice, and unique insights from the content",' . "\n";
        $prompt .= '  "main_topics": ["topic1", "topic2", "topic3", "topic4", "topic5", "topic6"],' . "\n";
        $prompt .= '  "semantic_keywords": ["keyword1", "keyword2", "...8-10 related terms, synonyms, and variations"],' . "\n";
        $prompt .= '  "entities": ["specific names, places, products, companies, or programs mentioned"],' . "\n";
        $prompt .= '  "content_themes": ["broader themes like investment, financing, Canadian real estate, etc"],' . "\n";
        $prompt .= '  "good_anchor_phrases": ["natural phrases other posts might use to link here"]' . "\n";
        $prompt .= "}\n";
        
        $response = $this->call_claude_api($prompt, 800);
        
        if (!$response['success']) {
            error_log('LendCity Smart Linker: API failed for post ' . $post_id . ' - ' . ($response['error'] ?? 'unknown error'));
            return false;
        }
        
        $data = json_decode($response['text'], true);
        if (!$data && preg_match('/\{.*\}/s', $response['text'], $matches)) {
            $data = json_decode($matches[0], true);
        }
        
        if (!$data) {
            error_log('LendCity Smart Linker: Failed to parse catalog for post ' . $post_id . ' - Response: ' . substr($response['text'], 0, 200));
            return false;
        }
        
        error_log('LendCity Smart Linker: Successfully cataloged ' . $post->post_type . ' ' . $post_id . ' - ' . $post->post_title);
        
        return array(
            'post_id' => $post_id,
            'type' => $post->post_type,
            'is_page' => $is_page,
            'title' => $post->post_title,
            'url' => get_permalink($post_id),
            'summary' => isset($data['summary']) ? $data['summary'] : '',
            'main_topics' => isset($data['main_topics']) ? $data['main_topics'] : array(),
            'semantic_keywords' => isset($data['semantic_keywords']) ? $data['semantic_keywords'] : array(),
            'entities' => isset($data['entities']) ? $data['entities'] : array(),
            'content_themes' => isset($data['content_themes']) ? $data['content_themes'] : array(),
            'good_anchor_phrases' => isset($data['good_anchor_phrases']) ? $data['good_anchor_phrases'] : array(),
            'updated_at' => current_time('mysql')
        );
    }
    
    /**
     * Build catalog for multiple posts in a SINGLE API call
     * Sends up to 10 full articles (14k chars each) for analysis
     */
    public function build_batch_catalog($post_ids) {
        $posts_data = array();
        
        // Gather all post data first
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            
            $content = wp_strip_all_tags($post->post_content);
            
            if (strlen($content) < 100) {
                $rendered = apply_filters('the_content', $post->post_content);
                $content = wp_strip_all_tags($rendered);
            }
            
            if (strlen($content) < 50) {
                $content = "Page title: " . $post->post_title . ". URL slug: " . $post->post_name;
            }
            
            // Full content - up to 10000 chars per post (balanced for rate limits)
            if (strlen($content) > 10000) {
                $content = substr($content, 0, 10000) . '...';
            }
            
            $posts_data[] = array(
                'id' => $post_id,
                'title' => $post->post_title,
                'url' => get_permalink($post_id),
                'type' => $post->post_type,
                'is_page' => ($post->post_type === 'page'),
                'content' => $content
            );
        }
        
        if (empty($posts_data)) {
            return array();
        }
        
        // Build single prompt for all posts
        $prompt = "Analyze these " . count($posts_data) . " posts/pages for internal linking purposes. This is for a mortgage business website.\n\n";
        
        foreach ($posts_data as $index => $pdata) {
            $prompt .= "=== ARTICLE " . ($index + 1) . " (ID: " . $pdata['id'] . ") ===\n";
            $prompt .= "Type: " . ($pdata['is_page'] ? "SERVICE PAGE" : "Blog Post") . "\n";
            $prompt .= "Title: " . $pdata['title'] . "\n";
            $prompt .= "Content:\n" . $pdata['content'] . "\n\n";
        }
        
        $prompt .= "For EACH article, analyze the FULL content and respond with a JSON array:\n";
        $prompt .= "[\n";
        $prompt .= "  {\n";
        $prompt .= '    "id": POST_ID_NUMBER,' . "\n";
        $prompt .= '    "summary": "4-5 sentence summary covering the main points, key advice, and unique insights from the article",' . "\n";
        $prompt .= '    "main_topics": ["6 main topics from throughout the article"],' . "\n";
        $prompt .= '    "semantic_keywords": ["8-10 related terms, synonyms, variations found anywhere in content"],' . "\n";
        $prompt .= '    "entities": ["specific names, places, programs, products mentioned"],' . "\n";
        $prompt .= '    "content_themes": ["broader themes like investment, financing, Canadian real estate"],' . "\n";
        $prompt .= '    "good_anchor_phrases": ["natural phrases from any part of article that other posts could use to link here"]' . "\n";
        $prompt .= "  }\n";
        $prompt .= "]\n";
        $prompt .= "Return one object per article in the same order. Analyze the ENTIRE content, not just the beginning.";
        
        // Large token limit for batch response
        $response = $this->call_claude_api($prompt, 4000);
        
        if (!$response['success']) {
            error_log('LendCity Smart Linker: Batch API failed - ' . ($response['error'] ?? 'unknown error'));
            // Fallback to individual processing
            $entries = array();
            foreach ($post_ids as $post_id) {
                $entry = $this->build_single_post_catalog($post_id);
                if ($entry) $entries[$post_id] = $entry;
            }
            return $entries;
        }
        
        // Parse JSON array response
        $results = json_decode($response['text'], true);
        if (!$results && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $results = json_decode($matches[0], true);
        }
        
        if (!is_array($results)) {
            error_log('LendCity Smart Linker: Failed to parse batch response, falling back to individual');
            $entries = array();
            foreach ($post_ids as $post_id) {
                $entry = $this->build_single_post_catalog($post_id);
                if ($entry) $entries[$post_id] = $entry;
            }
            return $entries;
        }
        
        // Build catalog entries
        $entries = array();
        foreach ($results as $data) {
            $post_id = isset($data['id']) ? intval($data['id']) : 0;
            if (!$post_id) continue;
            
            // Find original post data
            $pdata = null;
            foreach ($posts_data as $pd) {
                if ($pd['id'] == $post_id) {
                    $pdata = $pd;
                    break;
                }
            }
            
            if (!$pdata) continue;
            
            $entries[$post_id] = array(
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
                'updated_at' => current_time('mysql')
            );
        }
        
        error_log('LendCity Smart Linker: Batch cataloged ' . count($entries) . ' of ' . count($post_ids) . ' posts in single API call');
        
        return $entries;
    }
    
    /**
     * Get the current catalog
     *
     * PERFORMANCE NOTE: Now uses LendCity_Catalog_DB which stores entries in a
     * dedicated database table with proper indexing. The in-memory cache within
     * the DB class ensures we don't hit the database multiple times per request.
     *
     * For partial access (single entry), use $this->catalog_db->get_entry($post_id)
     * instead of loading the full catalog.
     *
     * @return array Associative array of post_id => catalog entry
     */
    public function get_catalog() {
        return $this->catalog_db->get_all_entries();
    }

    /**
     * Clear the catalog cache (call after updating catalog)
     *
     * NOTE: The new database class handles caching internally. This method now
     * delegates to the DB class's cache clearing mechanism.
     */
    public function clear_catalog_cache() {
        $this->catalog_db->clear_cache();
    }

    /**
     * Get catalog stats
     *
     * PERFORMANCE NOTE: Now uses optimized COUNT queries instead of loading
     * the entire catalog into memory.
     *
     * @return array Stats with 'total', 'posts', and 'pages' counts
     */
    public function get_catalog_stats() {
        return $this->catalog_db->get_stats();
    }
    
    /**
     * REVERSED LOGIC: Find posts that should link TO a target page/post
     * and insert links in those source posts
     * No limit on incoming links - targets can receive unlimited links
     * But source posts max out at 8 outgoing links
     */
    public function create_links_to_target($target_id) {
        $target = get_post($target_id);
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }
        
        $catalog = $this->get_catalog();
        if (empty($catalog)) {
            return array('success' => false, 'message' => 'Catalog not built');
        }
        
        $target_entry = isset($catalog[$target_id]) ? $catalog[$target_id] : null;
        if (!$target_entry) {
            return array('success' => false, 'message' => 'Target not in catalog. Please rebuild catalog.');
        }
        
        // Get all POSTS (not pages) that could link to this target
        $potential_sources = array();
        $used_anchors_by_post = array(); // Track existing anchors per post
        
        foreach ($catalog as $id => $entry) {
            // Skip the target itself
            if ($id == $target_id) continue;
            // Skip pages - only posts can be sources
            if (isset($entry['is_page']) && $entry['is_page']) continue;
            // Skip posts that already link to target
            if ($this->post_already_links_to($id, $target_entry['url'])) continue;
            // Skip posts that already have 8 outgoing links
            $existing_links = get_post_meta($id, $this->link_meta_key, true) ?: array();
            if (count($existing_links) >= 8) continue;
            
            // Collect existing anchors for this post (to avoid duplicates)
            $used_anchors_by_post[$id] = array();
            foreach ($existing_links as $link) {
                $used_anchors_by_post[$id][] = strtolower($link['anchor']);
            }
            
            $potential_sources[$id] = $entry;
        }
        
        if (empty($potential_sources)) {
            return array('success' => false, 'message' => 'No eligible source posts found (all may have 8 links already)');
        }
        
        // Build prompt to find best sources and anchor text
        $prompt = "You are an SEO expert finding internal linking opportunities.\n\n";
        $prompt .= "=== TARGET PAGE (we want links TO this page) ===\n";
        $prompt .= "Title: " . $target_entry['title'] . "\n";
        $prompt .= "URL: " . $target_entry['url'] . "\n";
        $prompt .= "Topics: " . implode(', ', $target_entry['main_topics']) . "\n";
        $prompt .= "Summary: " . $target_entry['summary'] . "\n";
        $prompt .= "Good anchor phrases: " . implode(', ', $target_entry['good_anchor_phrases']) . "\n\n";
        
        $prompt .= "=== POTENTIAL SOURCE POSTS (posts that could link TO the target) ===\n";
        $count = 0;
        foreach ($potential_sources as $id => $entry) {
            if ($count >= 30) break; // Limit for prompt size
            $prompt .= "ID: " . $id . " | Title: " . $entry['title'] . "\n";
            $prompt .= "Topics: " . implode(', ', $entry['main_topics']) . "\n";
            // Include existing anchors to avoid
            if (!empty($used_anchors_by_post[$id])) {
                $prompt .= "ALREADY USED ANCHORS (DO NOT USE THESE): " . implode(', ', $used_anchors_by_post[$id]) . "\n";
            }
            $prompt .= "\n";
            $count++;
        }
        
        $prompt .= "=== TASK ===\n";
        $prompt .= "Select source posts that have genuine topical relevance to link to the target.\n";
        $prompt .= "For each, suggest anchor text that would naturally fit in that post.\n\n";
        $prompt .= "ANCHOR TEXT RULES (CRITICAL):\n";
        $prompt .= "1. Anchor text MUST be a COMPLETE, MEANINGFUL phrase (not fragments like 'investment with' or 'the property')\n";
        $prompt .= "2. Anchor text should DESCRIBE what the TARGET page is about\n";
        $prompt .= "3. Good examples: 'real estate investing strategies', 'mortgage pre-approval process', 'Ontario rental properties'\n";
        $prompt .= "4. BAD examples: 'with the', 'investment with', 'for your', 'the market' (meaningless fragments)\n";
        $prompt .= "5. 2-5 words that make sense as a standalone description of the target\n";
        $prompt .= "6. CRITICAL: Do NOT use anchor text already used in a post (check ALREADY USED ANCHORS)\n";
        $prompt .= "7. Each anchor text must be UNIQUE\n\n";
        $prompt .= "QUALITY RULES:\n";
        $prompt .= "1. Only select posts with genuine topical relevance - don't force irrelevant links\n";
        $prompt .= "2. Quality over quantity - skip posts if no GOOD anchor phrase exists\n";
        $prompt .= "3. If you can't find a meaningful anchor, DO NOT suggest that link\n\n";
        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"source_id": 123, "anchor_text": "meaningful descriptive phrase"}, ...]\n';
        
        $response = $this->call_claude_api($prompt, 1000);
        
        if (!$response['success']) {
            return array('success' => false, 'message' => 'API error: ' . $response['error']);
        }
        
        $suggestions = json_decode($response['text'], true);
        if (!$suggestions && preg_match('/\[.*\]/s', $response['text'], $matches)) {
            $suggestions = json_decode($matches[0], true);
        }
        
        if (!is_array($suggestions) || empty($suggestions)) {
            return array('success' => false, 'message' => 'No linking opportunities found');
        }
        
        // Now insert links in each suggested source post
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
     * Get link suggestions WITHOUT inserting (for review mode)
     */
    public function get_link_suggestions($target_id) {
        $target = get_post($target_id);
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }
        
        $catalog = $this->get_catalog();
        if (empty($catalog)) {
            return array('success' => false, 'message' => 'Catalog not built');
        }
        
        $target_entry = isset($catalog[$target_id]) ? $catalog[$target_id] : null;
        if (!$target_entry) {
            return array('success' => false, 'message' => 'Target not in catalog');
        }
        
        // Get eligible source posts
        $potential_sources = array();
        $used_anchors_by_post = array();
        
        foreach ($catalog as $id => $entry) {
            if ($id == $target_id) continue;
            if (isset($entry['is_page']) && $entry['is_page']) continue;
            if ($this->post_already_links_to($id, $target_entry['url'])) continue;
            $existing_links = get_post_meta($id, $this->link_meta_key, true) ?: array();
            if (count($existing_links) >= 8) continue;
            
            // Collect existing anchors
            $used_anchors_by_post[$id] = array();
            foreach ($existing_links as $link) {
                $used_anchors_by_post[$id][] = strtolower($link['anchor']);
            }
            
            $potential_sources[$id] = $entry;
        }
        
        if (empty($potential_sources)) {
            return array('suggestions' => array(), 'message' => 'No eligible sources');
        }
        
        // Ask Claude for suggestions
        $prompt = "You are an SEO expert finding internal linking opportunities.\n\n";
        $prompt .= "=== TARGET PAGE ===\n";
        $prompt .= "Title: " . $target_entry['title'] . "\n";
        $prompt .= "URL: " . $target_entry['url'] . "\n";
        $prompt .= "Topics: " . implode(', ', $target_entry['main_topics']) . "\n";
        $prompt .= "Good anchor phrases: " . implode(', ', $target_entry['good_anchor_phrases']) . "\n\n";
        
        $prompt .= "=== POTENTIAL SOURCE POSTS ===\n";
        $count = 0;
        foreach ($potential_sources as $id => $entry) {
            if ($count >= 30) break;
            $prompt .= "ID: " . $id . " | Title: " . $entry['title'] . "\n";
            $prompt .= "Topics: " . implode(', ', $entry['main_topics']) . "\n";
            if (!empty($used_anchors_by_post[$id])) {
                $prompt .= "ALREADY USED ANCHORS (DO NOT USE): " . implode(', ', $used_anchors_by_post[$id]) . "\n";
            }
            $prompt .= "\n";
            $count++;
        }
        
        $prompt .= "=== ANCHOR TEXT RULES (CRITICAL) ===\n";
        $prompt .= "1. Anchor MUST be a COMPLETE, MEANINGFUL phrase - NOT fragments like 'investment with' or 'the property'\n";
        $prompt .= "2. Anchor should DESCRIBE what the TARGET page is about\n";
        $prompt .= "3. Good: 'real estate investing strategies', 'mortgage pre-approval', 'rental property financing'\n";
        $prompt .= "4. Bad: 'with the', 'investment with', 'for your' (meaningless fragments - NEVER USE THESE)\n";
        $prompt .= "5. Do NOT use anchor text already used in a post\n";
        $prompt .= "6. Quality over quantity - skip if no GOOD anchor exists\n\n";
        $prompt .= "Respond with ONLY a JSON array:\n";
        $prompt .= '[{"source_id": 123, "anchor_text": "meaningful descriptive phrase"}, ...]\n';
        
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
        
        // Add source titles
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
        
        $catalog = $this->get_catalog();
        $target_entry = isset($catalog[$target_id]) ? $catalog[$target_id] : null;
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
        
        return array(
            'success' => true,
            'inserted' => $inserted,
            'errors' => $errors
        );
    }
    
    /**
     * Insert a single link into a post's content
     * Max 8 outgoing links per post
     * No duplicate anchor text allowed
     */
    private function insert_link_in_post($post_id, $anchor_text, $target_url, $target_id, $is_page = false) {
        // Clear cache to get fresh post content
        clean_post_cache($post_id);
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found');
        }
        
        // Check max outgoing links (8 per post)
        $existing_links = get_post_meta($post_id, $this->link_meta_key, true) ?: array();
        if (count($existing_links) >= 8) {
            return array('success' => false, 'message' => 'Post already has 8 outgoing links (max reached)');
        }
        
        // Check for duplicate anchor text
        $anchor_lower = strtolower($anchor_text);
        foreach ($existing_links as $link) {
            if (strtolower($link['anchor']) === $anchor_lower) {
                return array('success' => false, 'message' => 'Anchor text "' . $anchor_text . '" already used in this post');
            }
        }
        
        $content = $post->post_content;
        
        // Store original if not already
        $original = get_post_meta($post_id, $this->original_content_meta, true);
        if (empty($original)) {
            update_post_meta($post_id, $this->original_content_meta, $content);
        }
        
        // Find anchor text in content (case-insensitive)
        $pattern = '/(?<![<\/a-zA-Z])(' . preg_quote($anchor_text, '/') . ')(?![^<]*<\/a>)(?![a-zA-Z])/i';
        
        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Anchor text not found - ask Claude to find similar phrase
            $alt_result = $this->find_alternative_anchor($post_id, $content, $target_url, $target_id, $is_page);
            if ($alt_result['success']) {
                return $alt_result;
            }
            return array('success' => false, 'message' => 'Anchor text not found in post');
        }
        
        // Check if this paragraph already has a Claude link
        $match_position = $matches[0][1];
        $paragraph_has_link = $this->paragraph_has_claude_link($content, $match_position);
        
        if ($paragraph_has_link) {
            return array('success' => false, 'message' => 'Paragraph already contains a link - skipping to maintain 1 link per paragraph');
        }
        
        // Generate unique link ID
        $link_id = 'cl_' . $post_id . '_' . $target_id . '_' . time();
        
        // Create the link HTML
        $link_html = '<a href="' . esc_url($target_url) . '" data-claude-link="1" data-link-id="' . $link_id . '">' . '$1' . '</a>';
        
        // Replace first occurrence only
        $new_content = preg_replace($pattern, $link_html, $content, 1);
        
        if ($new_content === $content) {
            return array('success' => false, 'message' => 'Failed to insert link');
        }
        
        // Update post (wp_kses filter allows iframes now)
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content
        ));
        
        // Track the link
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
        if (!$target) {
            return array('success' => false, 'message' => 'Target not found');
        }
        
        // Truncate content for prompt
        $content_preview = substr(wp_strip_all_tags($content), 0, 2000);
        
        $prompt = "Find a natural phrase in this post content to use as anchor text for a link.\n\n";
        $prompt .= "TARGET PAGE: " . $target->post_title . " (" . $target_url . ")\n\n";
        $prompt .= "SOURCE POST CONTENT:\n" . $content_preview . "\n\n";
        $prompt .= "Find a 2-5 word phrase that:\n";
        $prompt .= "1. Actually exists in the content above\n";
        $prompt .= "2. Is relevant to the target page topic\n";
        $prompt .= "3. Is NOT already inside an <a> tag\n";
        $prompt .= "4. Would make natural anchor text\n\n";
        $prompt .= "Respond with ONLY the exact phrase found, nothing else. If none found, respond: NONE\n";
        
        $response = $this->call_claude_api($prompt, 100);
        
        if (!$response['success']) {
            return array('success' => false, 'message' => 'API error');
        }
        
        $anchor = trim($response['text']);
        
        if ($anchor === 'NONE' || strlen($anchor) < 3 || strlen($anchor) > 50) {
            return array('success' => false, 'message' => 'No suitable anchor found');
        }
        
        // Try to insert with this anchor
        return $this->insert_link_in_post($post_id, $anchor, $target_url, $target_id, $is_page);
    }
    
    /**
     * Check if the paragraph containing a position already has a Claude link
     */
    private function paragraph_has_claude_link($content, $position) {
        // Find the paragraph boundaries around this position
        // Look for </p>, </div>, </li>, or double newlines as paragraph breaks
        
        // Find start of paragraph (look backwards for opening tag or start)
        $para_start = 0;
        $search_back = strrpos(substr($content, 0, $position), '<p');
        if ($search_back !== false) {
            $para_start = $search_back;
        } else {
            // Try other block elements
            foreach (array('<div', '<li', "\n\n") as $delim) {
                $found = strrpos(substr($content, 0, $position), $delim);
                if ($found !== false && $found > $para_start) {
                    $para_start = $found;
                }
            }
        }
        
        // Find end of paragraph (look forwards for closing tag)
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
        
        // Extract the paragraph
        $paragraph = substr($content, $para_start, $para_end - $para_start);
        
        // Check if it already contains a Claude link
        return strpos($paragraph, 'data-claude-link="1"') !== false;
    }
    
    /**
     * Check if post already links to a URL
     */
    private function post_already_links_to($post_id, $url) {
        $post = get_post($post_id);
        if (!$post) return true;
        
        return strpos($post->post_content, $url) !== false;
    }
    
    // =========================================================================
    // QUEUE SYSTEM v2.0 - Scales to 1000+ posts
    // =========================================================================
    
    /**
     * Initialize the queue with all posts for linking
     * Stores as lightweight array of IDs (not full objects)
     */
    public function init_bulk_queue($skip_with_links = true) {
        $catalog = $this->get_catalog();
        $queue_ids = array();
        $skipped = 0;
        
        foreach ($catalog as $id => $entry) {
            // Only queue POSTS (not pages)
            if (isset($entry['is_page']) && $entry['is_page']) {
                continue;
            }
            
            // Skip posts that already have links?
            if ($skip_with_links) {
                $existing_links = $this->get_post_links($id);
                if (!empty($existing_links)) {
                    $skipped++;
                    continue;
                }
            }
            
            $queue_ids[] = $id;
        }
        
        // Store lightweight queue (just IDs)
        update_option($this->queue_option, $queue_ids, false);
        
        // Initialize status
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
            'batch_size' => 3, // Process 3 posts per batch cycle
        );
        update_option($this->queue_status_option, $status, false);
        
        // Schedule cron to process queue if there are items
        if (count($queue_ids) > 0 && !wp_next_scheduled('lendcity_process_link_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lendcity_process_link_queue');
            $this->debug_log('Scheduled link queue cron for ' . count($queue_ids) . ' items');
        }
        
        return array(
            'queued' => count($queue_ids),
            'skipped' => $skipped
        );
    }
    
    /**
     * Process a batch of items from the queue
     * Designed to run in background via loopback or cron
     */
    public function process_queue_batch() {
        // Prevent concurrent processing with a transient lock
        $lock_key = 'lendcity_queue_processing';
        if (get_transient($lock_key)) {
            $this->debug_log('Queue already being processed by another instance - skipping');
            return array('complete' => false, 'message' => 'Already processing');
        }
        set_transient($lock_key, true, 120); // 2 minute lock
        
        // Increase time limit for batch processing
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        
        $queue = get_option($this->queue_option, array());
        $status = get_option($this->queue_status_option, array());
        
        // Check if paused or empty
        if (empty($queue) || (isset($status['state']) && $status['state'] === 'paused')) {
            if (empty($queue) && isset($status['state']) && $status['state'] === 'running') {
                $status['state'] = 'complete';
                $status['completed_at'] = current_time('mysql');
                update_option($this->queue_status_option, $status, false);
            }
            delete_transient($lock_key);
            return array('complete' => true);
        }
        
        $batch_size = isset($status['batch_size']) ? $status['batch_size'] : 5;
        $processed_this_batch = 0;
        $links_this_batch = 0;
        
        while ($processed_this_batch < $batch_size && !empty($queue)) {
            // Get next ID
            $post_id = array_shift($queue);
            
            // Save updated queue IMMEDIATELY to prevent duplicate processing
            update_option($this->queue_option, $queue, false);
            
            // Update status
            $post_title = get_the_title($post_id);
            $status['current_post'] = $post_title;
            $status['last_activity'] = current_time('mysql');
            update_option($this->queue_status_option, $status, false);
            
            // Process this post
            $result = $this->create_links_from_source($post_id);
            
            // Update stats
            $status['processed']++;
            $processed_this_batch++;
            
            if ($result['success']) {
                $links_created = isset($result['links_created']) ? $result['links_created'] : 0;
                $status['links_created'] += $links_created;
                $links_this_batch += $links_created;
                
                $this->log('Queue: Processed post ' . $post_id . ' - ' . $links_created . ' links');
            } else {
                $status['errors']++;
                $this->debug_log('Queue: Error on post ' . $post_id . ' - ' . ($result['message'] ?? 'unknown error'));
            }
            
            // Save updated status
            update_option($this->queue_status_option, $status, false);
        }
        
        // Release lock
        delete_transient($lock_key);
        
        // Check if complete
        if (empty($queue)) {
            $status['state'] = 'complete';
            $status['completed_at'] = current_time('mysql');
            $status['current_post'] = '';
            update_option($this->queue_status_option, $status, false);
            
            // Unschedule the cron since queue is empty
            wp_clear_scheduled_hook('lendcity_process_link_queue');
            $this->log('Queue complete - unscheduled cron');
            
            return array(
                'complete' => true,
                'processed' => $processed_this_batch,
                'links' => $links_this_batch
            );
        }
        
        return array(
            'complete' => false,
            'remaining' => count($queue),
            'processed' => $processed_this_batch,
            'links' => $links_this_batch
        );
    }
    
    /**
     * AJAX handler for background processing
     * Uses loopback technique - doesn't require cron
     */
    public function ajax_background_process() {
        // Verify request
        $token = get_option('lendcity_queue_token', '');
        if (empty($_POST['token']) || $_POST['token'] !== $token) {
            wp_die('Invalid token');
        }
        
        // Process a batch
        $result = $this->process_queue_batch();
        
        wp_send_json($result);
    }
    
    /**
     * Trigger background processing via loopback
     * Non-blocking - returns immediately
     */
    public function trigger_background_process() {
        // Generate/get security token
        $token = get_option('lendcity_queue_token', '');
        if (empty($token)) {
            $token = wp_generate_password(32, false);
            update_option('lendcity_queue_token', $token, false);
        }
        
        // Fire loopback request (non-blocking)
        $url = admin_url('admin-ajax.php');
        $args = array(
            'timeout' => 0.01, // Don't wait for response
            'blocking' => false,
            'sslverify' => false,
            'body' => array(
                'action' => 'lendcity_background_process',
                'token' => $token
            )
        );
        
        wp_remote_post($url, $args);
    }
    
    /**
     * Get queue status (fast - reads from option)
     */
    public function get_queue_status() {
        $queue = get_option($this->queue_option, array());
        $status = get_option($this->queue_status_option, array());
        
        // Legacy support
        if (empty($status)) {
            $pending = 0;
            $complete = 0;
            $error = 0;
            
            if (is_array($queue)) {
                foreach ($queue as $item) {
                    if (is_array($item)) {
                        // Old format
                        switch ($item['status'] ?? 'pending') {
                            case 'pending': $pending++; break;
                            case 'complete': $complete++; break;
                            case 'error': $error++; break;
                        }
                    } else {
                        // New format (just ID)
                        $pending++;
                    }
                }
            }
            
            return array(
                'total' => count($queue),
                'pending' => $pending,
                'processing' => 0,
                'complete' => $complete,
                'error' => $error,
                'items' => $queue
            );
        }
        
        // New format
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
            // Legacy compatibility
            'pending' => $remaining,
            'processing' => ($status['state'] ?? '') === 'running' ? 1 : 0,
            'complete' => $status['processed'] ?? 0,
            'error' => $status['errors'] ?? 0,
        );
    }
    
    /**
     * Pause the queue
     */
    public function pause_queue() {
        $status = get_option($this->queue_status_option, array());
        $status['state'] = 'paused';
        $status['paused_at'] = current_time('mysql');
        update_option($this->queue_status_option, $status, false);
    }
    
    /**
     * Resume the queue
     */
    public function resume_queue() {
        $status = get_option($this->queue_status_option, array());
        $status['state'] = 'running';
        unset($status['paused_at']);
        update_option($this->queue_status_option, $status, false);
        
        // Trigger processing
        $this->trigger_background_process();
    }
    
    /**
     * Clear the queue completely
     */
    public function clear_queue() {
        delete_option($this->queue_option);
        delete_option($this->queue_status_option);
        delete_option('lendcity_queue_token');
        wp_clear_scheduled_hook('lendcity_process_link_queue');
        wp_clear_scheduled_hook('lendcity_process_queue_batch');
    }
    
    /**
     * Add single item to queue (for auto-linking on publish)
     */
    public function add_to_queue($post_id, $action = 'create_links_from_source') {
        // For single items, just process directly (faster than queue)
        if ($action === 'create_links_from_source') {
            // Schedule via cron to avoid blocking the publish action
            wp_schedule_single_event(time() + 10, 'lendcity_process_single_post', array($post_id));
        }
    }
    
    /**
     * Get all smart links for a post
     */
    public function get_post_links($post_id) {
        return get_post_meta($post_id, $this->link_meta_key, true) ?: array();
    }
    
    /**
     * Remove a single link from a post
     */
    public function remove_link($post_id, $link_id) {
        $post = get_post($post_id);
        if (!$post) return false;
        
        $content = $post->post_content;
        $new_content = $content;
        
        // Method 1: Try to remove by data-link-id
        $pattern = '/<a\s[^>]*data-link-id="' . preg_quote($link_id, '/') . '"[^>]*>(.*?)<\/a>/is';
        $new_content = preg_replace($pattern, '$1', $content);
        
        // Method 2: If that didn't work, try to find the link in meta and remove by URL
        if ($new_content === $content) {
            $links = $this->get_post_links($post_id);
            foreach ($links as $link) {
                if (isset($link['link_id']) && $link['link_id'] === $link_id) {
                    // Try removing by URL
                    $url = $link['url'] ?? '';
                    if ($url) {
                        $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*href="' . preg_quote($url, '/') . '"[^>]*>(.*?)<\/a>/is';
                        $new_content = preg_replace($pattern, '$1', $content, 1);
                    }
                    break;
                }
            }
        }
        
        // Method 3: If still no match, try a broader pattern with data-claude-link
        if ($new_content === $content) {
            // Get the anchor text from meta
            $links = $this->get_post_links($post_id);
            foreach ($links as $link) {
                if (isset($link['link_id']) && $link['link_id'] === $link_id && isset($link['anchor'])) {
                    $anchor = preg_quote($link['anchor'], '/');
                    $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*>' . $anchor . '<\/a>/is';
                    $new_content = preg_replace($pattern, $link['anchor'], $content, 1);
                    break;
                }
            }
        }
        
        if ($new_content !== $content) {
            wp_update_post(array('ID' => $post_id, 'post_content' => $new_content));
            
            // Remove from meta
            $links = $this->get_post_links($post_id);
            $links = array_filter($links, function($l) use ($link_id) { 
                return !isset($l['link_id']) || $l['link_id'] !== $link_id; 
            });
            update_post_meta($post_id, $this->link_meta_key, array_values($links));
            
            return true;
        }
        
        // Last resort: Just remove from meta even if content didn't change
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
        
        // Get all posts with Claude links
        $posts = $wpdb->get_results("
            SELECT ID, post_content FROM {$wpdb->posts} 
            WHERE post_content LIKE '%data-claude-link=\"1\"%'
            AND post_status IN ('publish', 'draft', 'future')
        ");
        
        $deleted = 0;
        $posts_affected = 0;
        
        foreach ($posts as $post) {
            // Count links in this post
            preg_match_all('/data-claude-link="1"/', $post->post_content, $matches);
            $link_count = count($matches[0]);
            
            // Remove all Claude links
            $pattern = '/<a\s[^>]*data-claude-link="1"[^>]*>(.*?)<\/a>/is';
            $new_content = preg_replace($pattern, '$1', $post->post_content);
            
            if ($new_content !== $post->post_content) {
                wp_update_post(array('ID' => $post->ID, 'post_content' => $new_content));
                delete_post_meta($post->ID, $this->link_meta_key);
                $deleted += $link_count;
                $posts_affected++;
            }
        }
        
        return array(
            'deleted' => $deleted,
            'posts_affected' => $posts_affected
        );
    }
    
    /**
     * Change the target URL of a single link
     */
    public function change_single_link_target($source_id, $link_id, $old_url, $new_url) {
        $post = get_post($source_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found');
        }
        
        // Try to update in content using link_id
        $pattern = '/(<a\s[^>]*data-link-id="' . preg_quote($link_id, '/') . '"[^>]*href=")[^"]*(")/is';
        $new_content = preg_replace($pattern, '${1}' . esc_url($new_url) . '${2}', $post->post_content);
        
        // If that didn't work, try matching by old_url
        if ($new_content === $post->post_content && !empty($old_url)) {
            $new_content = preg_replace(
                '/(<a\s[^>]*data-claude-link="1"[^>]*href=")' . preg_quote($old_url, '/') . '(")/is',
                '${1}' . esc_url($new_url) . '${2}',
                $post->post_content,
                1 // Only replace first occurrence
            );
        }
        
        // Save even if content didn't change (maybe just updating meta)
        if ($new_content !== $post->post_content) {
            wp_update_post(array('ID' => $source_id, 'post_content' => $new_content));
        }
        
        // Update in meta
        $links = $this->get_post_links($source_id);
        foreach ($links as &$link) {
            if ($link['link_id'] === $link_id || $link['url'] === $old_url) {
                $link['url'] = $new_url;
                // Update is_page flag based on new target
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
     * Get all Claude links across site (with optional limit)
     */
    public function get_all_site_links($limit = 0) {
        global $wpdb;
        
        // Order by post date descending (most recent posts first)
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
                // Cache post titles to avoid repeated queries
                if (!isset($post_titles_cache[$row->post_id])) {
                    $post_titles_cache[$row->post_id] = get_the_title($row->post_id);
                }
                
                foreach ($links as $link) {
                    $link['source_post_id'] = $row->post_id;
                    $link['source_post_title'] = $post_titles_cache[$row->post_id];
                    $link['post_date'] = $row->post_date;
                    $all_links[] = $link;
                    
                    // Check limit
                    if ($limit > 0 && count($all_links) >= $limit) {
                        return $all_links;
                    }
                }
            }
        }
        return $all_links;
    }
    
    /**
     * Get total link count (fast)
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
            if (is_array($links)) {
                $count += count($links);
            }
        }
        return $count;
    }
    
    /**
     * Call Claude API
     */
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
            error_log('LendCity API: WP Error - ' . $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($response_code !== 200) {
            $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $response_code;
            error_log('LendCity API: Error response - ' . $error_msg);
            return array('success' => false, 'error' => $error_msg);
        }
        
        if (!isset($body['content'][0]['text'])) {
            error_log('LendCity API: Invalid response structure - ' . wp_remote_retrieve_body($response));
            return array('success' => false, 'error' => 'Invalid API response');
        }
        
        return array('success' => true, 'text' => $body['content'][0]['text']);
    }
    
    // ==================== SEO ENHANCEMENT FUNCTIONS ====================
    
    /**
     * Get/Set priority for a page (1-5, higher = more links)
     */
    public function get_page_priority($post_id) {
        return intval(get_post_meta($post_id, $this->priority_meta_key, true)) ?: 3; // Default 3 (normal)
    }
    
    public function set_page_priority($post_id, $priority) {
        $priority = max(1, min(5, intval($priority)));
        update_post_meta($post_id, $this->priority_meta_key, $priority);
        return $priority;
    }
    
    /**
     * Get/Set target keywords for a page (used for anchor text)
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
     * Get all priority pages with their settings
     */
    public function get_priority_pages() {
        global $wpdb;
        
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));
        
        $result = array();
        foreach ($pages as $page) {
            $priority = $this->get_page_priority($page->ID);
            $keywords = $this->get_page_keywords($page->ID);
            $inbound_links = $this->count_inbound_links($page->ID);
            
            $result[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'url' => get_permalink($page->ID),
                'priority' => $priority,
                'keywords' => $keywords,
                'inbound_links' => $inbound_links
            );
        }
        
        // Sort by priority descending
        usort($result, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        return $result;
    }
    
    /**
     * Count inbound internal links to a post/page
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
     * Link Gap Analysis - Find posts/pages with few or no inbound links
     */
    public function get_link_gaps($min_links = 0, $max_links = 2) {
        $catalog = $this->get_catalog();
        $all_links = $this->get_all_site_links(2000);
        
        // Count inbound links for each URL
        $inbound_counts = array();
        foreach ($all_links as $link) {
            $url = $link['url'];
            if (!isset($inbound_counts[$url])) {
                $inbound_counts[$url] = 0;
            }
            $inbound_counts[$url]++;
        }
        
        // Find items with link gaps
        $gaps = array();
        foreach ($catalog as $post_id => $item) {
            $url = $item['url'];
            $count = isset($inbound_counts[$url]) ? $inbound_counts[$url] : 0;
            
            if ($count >= $min_links && $count <= $max_links) {
                $item_id = isset($item['post_id']) ? $item['post_id'] : $post_id;
                $gaps[] = array(
                    'id' => $item_id,
                    'title' => $item['title'],
                    'url' => $url,
                    'type' => !empty($item['is_page']) ? 'page' : 'post',
                    'inbound_links' => $count,
                    'priority' => !empty($item['is_page']) ? $this->get_page_priority($item_id) : 0,
                    'keywords' => !empty($item['is_page']) ? $this->get_page_keywords($item_id) : ''
                );
            }
        }
        
        // Sort by inbound_links ascending (worst first), then by priority descending
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
        
        // Count inbound links per URL
        $inbound_counts = array();
        foreach ($all_links as $link) {
            $url = $link['url'];
            if (!isset($inbound_counts[$url])) {
                $inbound_counts[$url] = 0;
            }
            $inbound_counts[$url]++;
        }
        
        // Calculate stats
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
    
    /**
     * Get max links allowed based on priority
     * Priority 1 = 1 link, Priority 5 = 5 links per source post
     */
    public function get_max_links_for_priority($priority) {
        return max(1, min(5, intval($priority)));
    }
}
