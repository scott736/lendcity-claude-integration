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
    private $catalog_option = 'lendcity_post_catalog';
    private $catalog_meta_option = 'lendcity_post_catalog_meta'; // NEW: Catalog metadata (indexes, stats)
    private $link_meta_key = '_lendcity_smart_links';
    private $original_content_meta = '_lendcity_original_content';
    private $queue_option = 'lendcity_smart_linker_queue';
    private $queue_status_option = 'lendcity_smart_linker_queue_status';
    private $catalog_cache = null; // In-memory cache to avoid repeated DB queries
    private $catalog_indexes = null; // In-memory topic/keyword indexes

    // SEO Enhancement meta keys
    private $priority_meta_key = '_lendcity_link_priority'; // 1-5, higher = more links
    private $keywords_meta_key = '_lendcity_target_keywords'; // comma-separated keywords for anchor text

    // Performance: Cached options to avoid repeated DB queries
    private $debug_mode = null;
    private $auto_link_enabled = null;

    // API retry settings
    private $max_retries = 3;
    private $retry_delays = array(2, 4, 8); // Exponential backoff in seconds

    public function __construct() {
        $this->api_key = get_option('lendcity_claude_api_key');
        // Cache frequently accessed options
        $this->debug_mode = get_option('lendcity_debug_mode', 'no') === 'yes';
        $this->auto_link_enabled = get_option('lendcity_smart_linker_auto', 'yes') === 'yes';
        
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
     * Uses cached value to avoid repeated DB queries
     */
    private function debug_log($message) {
        if ($this->debug_mode) {
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

        // Check if auto-linking is enabled (use cached value)
        if (!$this->auto_link_enabled) {
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
        
        // 1. Remove from catalog
        $catalog = $this->get_catalog();
        if (isset($catalog[$post_id])) {
            unset($catalog[$post_id]);
            update_option($this->catalog_option, $catalog);
            $this->clear_catalog_cache();
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
        
        // Add to catalog
        $entry = $this->build_single_post_catalog($post_id);
        if ($entry) {
            $catalog = $this->get_catalog();
            $catalog[$post_id] = $entry;
            update_option($this->catalog_option, $catalog);
            $this->clear_catalog_cache();
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

        // Get source entry for pre-filtering
        $source_entry = isset($catalog[$source_id]) ? $catalog[$source_id] : null;

        // PRE-FILTER: Score and rank post candidates by relevance
        if (!empty($available_posts) && $source_entry) {
            $available_posts = $this->prefilter_candidates($available_posts, $source_entry, 20);
        }

        // Get source post content for context - FULL article for proper link distribution
        $source_content = wp_strip_all_tags($source->post_content);
        if (strlen($source_content) > 10000) {
            $source_content = substr($source_content, 0, 10000) . '...';
        }

        $source_topics = $source_entry ? implode(', ', $source_entry['main_topics']) : '';

        // Build prompt with intelligence data
        $prompt = "You are an expert internal linking strategist for a mortgage/real estate website.\n\n";
        $prompt .= "=== SOURCE POST (we are adding links TO this post's content) ===\n";
        $prompt .= "Title: " . $source->post_title . "\n";
        $prompt .= "Topics: " . $source_topics . "\n";
        // Include intelligence fields if available
        if ($source_entry) {
            $prompt .= "Audience: " . (isset($source_entry['target_audience']) ? $source_entry['target_audience'] : 'all') . "\n";
            $prompt .= "Difficulty: " . (isset($source_entry['difficulty_level']) ? $source_entry['difficulty_level'] : 'intermediate') . "\n";
            $prompt .= "Funnel Stage: " . (isset($source_entry['funnel_stage']) ? $source_entry['funnel_stage'] : 'awareness') . "\n";
        }
        $prompt .= "Content Preview:\n" . $source_content . "\n\n";

        if (!empty($used_anchors)) {
            $prompt .= "ALREADY USED ANCHORS IN THIS POST (DO NOT USE THESE):\n";
            $prompt .= implode(', ', $used_anchors) . "\n\n";
        }

        $links_requested = array();

        // Page linking section - now with priority and keywords
        if ($page_slots > 0 && !empty($available_pages)) {
            $prompt .= "=== AVAILABLE PAGES (service pages - high value) ===\n";
            $count = 0;

            // Sort pages by priority (highest first)
            uasort($available_pages, function($a, $b) {
                $prio_a = $this->get_page_priority($a['post_id'] ?? 0);
                $prio_b = $this->get_page_priority($b['post_id'] ?? 0);
                return $prio_b - $prio_a;
            });

            foreach ($available_pages as $id => $entry) {
                if ($count >= 10) break;
                $priority = $this->get_page_priority($id);
                $keywords = $this->get_page_keywords($id);

                $prompt .= "ID:" . $id . " | " . $entry['title'] . " | Priority:" . $priority;
                if ($keywords) {
                    $prompt .= " | Preferred Anchors: " . $keywords;
                }
                $prompt .= "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to " . $page_slots . " page links";
        }

        // Post linking section - now with relevance scores
        if ($post_slots > 0 && !empty($available_posts)) {
            $prompt .= "=== AVAILABLE POSTS (ranked by relevance to source) ===\n";
            $count = 0;
            foreach ($available_posts as $id => $entry) {
                if ($count >= 15) break;
                $relevance = isset($entry['_relevance_score']) ? $entry['_relevance_score'] : 0;
                $prompt .= "ID:" . $id . " | Rel:" . $relevance . " | " . $entry['title'];
                $prompt .= " | " . (isset($entry['difficulty_level']) ? $entry['difficulty_level'] : 'intermediate') . "\n";
                $count++;
            }
            $prompt .= "\n";
            $links_requested[] = "Up to " . $post_slots . " post links";
        }

        $prompt .= "=== TASK ===\n";
        $prompt .= "Find: " . implode(' + ', $links_requested) . "\n\n";

        $prompt .= "SELECTION CRITERIA:\n";
        $prompt .= "- Prioritize higher relevance scores (Rel:)\n";
        $prompt .= "- Consider audience and difficulty alignment\n";
        $prompt .= "- Higher priority pages (Priority:4-5) should get links first\n";
        $prompt .= "- Use Preferred Anchors when listed for pages\n\n";

        $prompt .= "ANCHOR TEXT RULES (CRITICAL - violations will be rejected):\n";
        $prompt .= "1. MUST be a COMPLETE phrase that makes sense standalone (2-5 words)\n";
        $prompt .= "2. MUST describe the TARGET page topic\n";
        $prompt .= "3. GOOD: 'BRRRR investment strategy', 'mortgage pre-approval', 'rental property financing'\n";
        $prompt .= "4. BAD (will be rejected): 'with the', 'investment with', 'for your', 'the market'\n";
        $prompt .= "5. Do NOT start with: the, a, an, this, that, with, and, or, in, on, to\n";
        $prompt .= "6. EACH ANCHOR MUST BE UNIQUE in this article\n";
        $prompt .= "7. Spread links throughout (beginning, middle, end). Max 1 link per paragraph.\n\n";

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
        $skipped_anchors = 0;
        $page_links_added = $existing_page_links;
        $post_links_added = $current_link_count - $existing_page_links;
        $anchors_used_this_session = array(); // Track anchors used in this batch

        foreach ($suggestions as $suggestion) {
            $target_id = intval($suggestion['target_id']);
            $anchor = sanitize_text_field($suggestion['anchor_text']);
            $is_page = !empty($suggestion['is_page']);

            if (!$target_id || !$anchor) continue;

            // Validate anchor text quality before inserting
            if (!$this->validate_anchor_text($anchor)) {
                $this->debug_log("Rejected poor anchor text: '{$anchor}' for target {$target_id}");
                $skipped_anchors++;
                continue;
            }

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

        if ($skipped_anchors > 0) {
            $this->debug_log("Skipped {$skipped_anchors} links due to poor anchor text quality");
        }

        return array(
            'success' => true,
            'links_created' => count($links_created),
            'links' => $links_created,
            'errors' => $errors,
            'skipped_anchors' => $skipped_anchors
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

        $prompt .= "Respond with ONLY a JSON object. Be thorough and accurate:\n";
        $prompt .= "{\n";
        $prompt .= '  "summary": "4-5 sentence summary covering the main points, key advice, and unique insights",' . "\n";
        $prompt .= '  "main_topics": ["topic1", "topic2", "topic3", "topic4", "topic5", "topic6"],' . "\n";
        $prompt .= '  "semantic_keywords": ["keyword1", "keyword2", "...8-10 related terms, synonyms, and variations"],' . "\n";
        $prompt .= '  "entities": ["specific names, places, products, companies, or programs mentioned"],' . "\n";
        $prompt .= '  "content_themes": ["broader themes like investment, financing, Canadian real estate, etc"],' . "\n";
        $prompt .= '  "good_anchor_phrases": ["5-8 natural 2-5 word phrases that would make excellent anchor text for links TO this page"],' . "\n";
        // NEW: Intelligence fields for smarter linking
        $prompt .= '  "reader_intent": "educational OR transactional OR navigational - what is the reader trying to accomplish?",' . "\n";
        $prompt .= '  "difficulty_level": "beginner OR intermediate OR advanced - how sophisticated is this content?",' . "\n";
        $prompt .= '  "funnel_stage": "awareness OR consideration OR decision - where is this in the buyer journey?",' . "\n";
        $prompt .= '  "content_type": "how-to OR guide OR comparison OR case-study OR FAQ OR news OR service-page",' . "\n";
        $prompt .= '  "target_audience": "new investors OR experienced investors OR realtors OR homeowners OR all",' . "\n";
        $prompt .= '  "topic_cluster": "main topic cluster this belongs to, e.g. brrrr-strategy, mortgage-types, market-analysis",' . "\n";
        $prompt .= '  "is_pillar_content": true or false - is this comprehensive cornerstone content?' . "\n";
        $prompt .= "}\n";
        
        $response = $this->call_claude_api($prompt, 1200); // Increased for new fields

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
            // NEW: Intelligence fields for smarter linking
            'reader_intent' => isset($data['reader_intent']) ? strtolower($data['reader_intent']) : 'educational',
            'difficulty_level' => isset($data['difficulty_level']) ? strtolower($data['difficulty_level']) : 'intermediate',
            'funnel_stage' => isset($data['funnel_stage']) ? strtolower($data['funnel_stage']) : 'awareness',
            'content_type' => isset($data['content_type']) ? strtolower($data['content_type']) : 'guide',
            'target_audience' => isset($data['target_audience']) ? strtolower($data['target_audience']) : 'all',
            'topic_cluster' => isset($data['topic_cluster']) ? sanitize_title($data['topic_cluster']) : '',
            'is_pillar_content' => isset($data['is_pillar_content']) ? (bool)$data['is_pillar_content'] : false,
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
        $prompt .= '    "summary": "4-5 sentence summary covering the main points, key advice, and unique insights",' . "\n";
        $prompt .= '    "main_topics": ["6 main topics from throughout the article"],' . "\n";
        $prompt .= '    "semantic_keywords": ["8-10 related terms, synonyms, variations"],' . "\n";
        $prompt .= '    "entities": ["specific names, places, programs, products mentioned"],' . "\n";
        $prompt .= '    "content_themes": ["broader themes like investment, financing, Canadian real estate"],' . "\n";
        $prompt .= '    "good_anchor_phrases": ["5-8 natural 2-5 word phrases for anchor text"],' . "\n";
        // NEW: Intelligence fields
        $prompt .= '    "reader_intent": "educational/transactional/navigational",' . "\n";
        $prompt .= '    "difficulty_level": "beginner/intermediate/advanced",' . "\n";
        $prompt .= '    "funnel_stage": "awareness/consideration/decision",' . "\n";
        $prompt .= '    "content_type": "how-to/guide/comparison/case-study/FAQ/news/service-page",' . "\n";
        $prompt .= '    "target_audience": "new investors/experienced investors/realtors/homeowners/all",' . "\n";
        $prompt .= '    "topic_cluster": "main-topic-slug like brrrr-strategy or mortgage-types",' . "\n";
        $prompt .= '    "is_pillar_content": true/false' . "\n";
        $prompt .= "  }\n";
        $prompt .= "]\n";
        $prompt .= "Return one object per article in the same order. Analyze the ENTIRE content, not just the beginning.";
        
        // Large token limit for batch response (increased for new fields)
        $response = $this->call_claude_api($prompt, 6000);
        
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
                // NEW: Intelligence fields for smarter linking
                'reader_intent' => isset($data['reader_intent']) ? strtolower($data['reader_intent']) : 'educational',
                'difficulty_level' => isset($data['difficulty_level']) ? strtolower($data['difficulty_level']) : 'intermediate',
                'funnel_stage' => isset($data['funnel_stage']) ? strtolower($data['funnel_stage']) : 'awareness',
                'content_type' => isset($data['content_type']) ? strtolower($data['content_type']) : 'guide',
                'target_audience' => isset($data['target_audience']) ? strtolower($data['target_audience']) : 'all',
                'topic_cluster' => isset($data['topic_cluster']) ? sanitize_title($data['topic_cluster']) : '',
                'is_pillar_content' => isset($data['is_pillar_content']) ? (bool)$data['is_pillar_content'] : false,
                'updated_at' => current_time('mysql')
            );
        }

        error_log('LendCity Smart Linker: Batch cataloged ' . count($entries) . ' of ' . count($post_ids) . ' posts in single API call');
        
        return $entries;
    }
    
    /**
     * Get the current catalog
     */
    public function get_catalog() {
        if ($this->catalog_cache === null) {
            $this->catalog_cache = get_option($this->catalog_option, array());
        }
        return $this->catalog_cache;
    }
    
    /**
     * Clear the catalog cache (call after updating catalog)
     */
    public function clear_catalog_cache() {
        $this->catalog_cache = null;
    }
    
    /**
     * Get catalog stats
     */
    public function get_catalog_stats() {
        $catalog = $this->get_catalog();
        $posts = 0;
        $pages = 0;

        foreach ($catalog as $entry) {
            if (isset($entry['is_page']) && $entry['is_page']) {
                $pages++;
            } else {
                $posts++;
            }
        }

        return array('total' => count($catalog), 'posts' => $posts, 'pages' => $pages);
    }

    // ==================== INTELLIGENCE: PRE-FILTERING & SCORING ====================

    /**
     * Calculate relevance score between two catalog entries
     * Higher score = more relevant (better link candidate)
     * Uses topic overlap, keyword matching, audience compatibility, and funnel alignment
     */
    private function calculate_relevance_score($source_entry, $target_entry) {
        $score = 0;

        // Topic overlap (most important) - up to 30 points
        $source_topics = isset($source_entry['main_topics']) ? array_map('strtolower', $source_entry['main_topics']) : array();
        $target_topics = isset($target_entry['main_topics']) ? array_map('strtolower', $target_entry['main_topics']) : array();
        $topic_overlap = count(array_intersect($source_topics, $target_topics));
        $score += min($topic_overlap * 5, 30);

        // Keyword overlap - up to 20 points
        $source_keywords = isset($source_entry['semantic_keywords']) ? array_map('strtolower', $source_entry['semantic_keywords']) : array();
        $target_keywords = isset($target_entry['semantic_keywords']) ? array_map('strtolower', $target_entry['semantic_keywords']) : array();
        $keyword_overlap = count(array_intersect($source_keywords, $target_keywords));
        $score += min($keyword_overlap * 2, 20);

        // Theme overlap - up to 15 points
        $source_themes = isset($source_entry['content_themes']) ? array_map('strtolower', $source_entry['content_themes']) : array();
        $target_themes = isset($target_entry['content_themes']) ? array_map('strtolower', $target_entry['content_themes']) : array();
        $theme_overlap = count(array_intersect($source_themes, $target_themes));
        $score += min($theme_overlap * 5, 15);

        // Same topic cluster - 15 points bonus
        $source_cluster = isset($source_entry['topic_cluster']) ? $source_entry['topic_cluster'] : '';
        $target_cluster = isset($target_entry['topic_cluster']) ? $target_entry['topic_cluster'] : '';
        if (!empty($source_cluster) && $source_cluster === $target_cluster) {
            $score += 15;
        }

        // Audience compatibility - up to 10 points
        $source_audience = isset($source_entry['target_audience']) ? $source_entry['target_audience'] : 'all';
        $target_audience = isset($target_entry['target_audience']) ? $target_entry['target_audience'] : 'all';
        if ($source_audience === $target_audience || $source_audience === 'all' || $target_audience === 'all') {
            $score += 10;
        } elseif ($this->audiences_compatible($source_audience, $target_audience)) {
            $score += 5;
        }

        // Difficulty level flow (beginner -> intermediate -> advanced) - up to 10 points
        $source_level = isset($source_entry['difficulty_level']) ? $source_entry['difficulty_level'] : 'intermediate';
        $target_level = isset($target_entry['difficulty_level']) ? $target_entry['difficulty_level'] : 'intermediate';
        if ($this->difficulty_flow_valid($source_level, $target_level)) {
            $score += 10;
        }

        // Funnel stage progression - up to 10 points
        $source_funnel = isset($source_entry['funnel_stage']) ? $source_entry['funnel_stage'] : 'awareness';
        $target_funnel = isset($target_entry['funnel_stage']) ? $target_entry['funnel_stage'] : 'awareness';
        if ($this->funnel_flow_valid($source_funnel, $target_funnel)) {
            $score += 10;
        }

        // Bonus for linking to pillar content - 5 points
        if (isset($target_entry['is_pillar_content']) && $target_entry['is_pillar_content']) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Check if audiences are compatible for linking
     */
    private function audiences_compatible($source, $target) {
        // Define compatible audience pairs
        $compatible = array(
            'new investors' => array('all', 'homeowners'),
            'experienced investors' => array('all', 'realtors'),
            'realtors' => array('all', 'experienced investors'),
            'homeowners' => array('all', 'new investors'),
        );

        if (isset($compatible[$source]) && in_array($target, $compatible[$source])) {
            return true;
        }
        return false;
    }

    /**
     * Check if difficulty progression is valid (same or one level up)
     */
    private function difficulty_flow_valid($source, $target) {
        $levels = array('beginner' => 1, 'intermediate' => 2, 'advanced' => 3);
        $source_level = isset($levels[$source]) ? $levels[$source] : 2;
        $target_level = isset($levels[$target]) ? $levels[$target] : 2;

        // Valid: same level, or target is one level higher (reader progresses)
        return ($source_level === $target_level || $target_level === $source_level + 1);
    }

    /**
     * Check if funnel progression is valid
     */
    private function funnel_flow_valid($source, $target) {
        $stages = array('awareness' => 1, 'consideration' => 2, 'decision' => 3);
        $source_stage = isset($stages[$source]) ? $stages[$source] : 1;
        $target_stage = isset($stages[$target]) ? $stages[$target] : 1;

        // Valid: same stage, or target is next stage (moves user down funnel)
        return ($source_stage === $target_stage || $target_stage === $source_stage + 1);
    }

    /**
     * Pre-filter and score candidates before sending to Claude
     * Returns top N candidates sorted by relevance score
     */
    private function prefilter_candidates($candidates, $reference_entry, $max_candidates = 20) {
        $scored = array();

        foreach ($candidates as $id => $entry) {
            $score = $this->calculate_relevance_score($entry, $reference_entry);
            $scored[$id] = array(
                'entry' => $entry,
                'score' => $score
            );
        }

        // Sort by score descending
        uasort($scored, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        // Return top candidates
        $result = array();
        $count = 0;
        foreach ($scored as $id => $data) {
            if ($count >= $max_candidates) break;
            // Only include if score is above minimum threshold
            if ($data['score'] >= 10) {
                $result[$id] = $data['entry'];
                $result[$id]['_relevance_score'] = $data['score']; // Include score for prompt context
                $count++;
            }
        }

        $this->debug_log('Pre-filtered ' . count($candidates) . ' candidates down to ' . count($result) . ' (threshold: 10+)');

        return $result;
    }

    /**
     * Validate anchor text quality
     * Returns true if anchor is suitable, false if not
     */
    private function validate_anchor_text($anchor) {
        // Trim and normalize
        $anchor = trim($anchor);

        // Too short (less than 2 words typically)
        if (strlen($anchor) < 5) {
            return false;
        }

        // Too long (more than 8 words typically awkward)
        if (str_word_count($anchor) > 8) {
            return false;
        }

        // Starts with common bad patterns
        $bad_starts = array('the ', 'a ', 'an ', 'this ', 'that ', 'with ', 'and ', 'or ', 'in ', 'on ', 'to ');
        foreach ($bad_starts as $bad) {
            if (stripos($anchor, $bad) === 0) {
                return false;
            }
        }

        // Ends with bad patterns
        $bad_ends = array(' the', ' a', ' an', ' and', ' or', ' to', ' in', ' on', ' with');
        foreach ($bad_ends as $bad) {
            if (substr(strtolower($anchor), -strlen($bad)) === $bad) {
                return false;
            }
        }

        // Contains only stopwords
        $stopwords = array('the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'need', 'dare', 'ought', 'used', 'it', 'its');
        $words = preg_split('/\s+/', strtolower($anchor));
        $meaningful_words = array_diff($words, $stopwords);
        if (count($meaningful_words) < 1) {
            return false;
        }

        return true;
    }

    // ==================== END INTELLIGENCE HELPERS ====================

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

        // PRE-FILTER: Score and rank candidates by relevance before sending to Claude
        $filtered_sources = $this->prefilter_candidates($potential_sources, $target_entry, 25);

        if (empty($filtered_sources)) {
            return array('success' => false, 'message' => 'No relevant source posts found after filtering');
        }

        // Build prompt with intelligence data
        $prompt = "You are an expert internal linking strategist for a mortgage/real estate website.\n\n";
        $prompt .= "=== TARGET PAGE (we want links TO this page) ===\n";
        $prompt .= "Title: " . $target_entry['title'] . "\n";
        $prompt .= "URL: " . $target_entry['url'] . "\n";
        $prompt .= "Topics: " . implode(', ', $target_entry['main_topics']) . "\n";
        $prompt .= "Summary: " . $target_entry['summary'] . "\n";
        $prompt .= "Good anchor phrases: " . implode(', ', $target_entry['good_anchor_phrases']) . "\n";
        // Include intelligence fields
        $prompt .= "Reader Intent: " . (isset($target_entry['reader_intent']) ? $target_entry['reader_intent'] : 'educational') . "\n";
        $prompt .= "Difficulty: " . (isset($target_entry['difficulty_level']) ? $target_entry['difficulty_level'] : 'intermediate') . "\n";
        $prompt .= "Funnel Stage: " . (isset($target_entry['funnel_stage']) ? $target_entry['funnel_stage'] : 'awareness') . "\n";
        $prompt .= "Audience: " . (isset($target_entry['target_audience']) ? $target_entry['target_audience'] : 'all') . "\n";
        $prompt .= "Is Pillar Content: " . (isset($target_entry['is_pillar_content']) && $target_entry['is_pillar_content'] ? 'YES' : 'no') . "\n\n";

        $prompt .= "=== PRE-FILTERED SOURCE POSTS (ranked by relevance) ===\n";
        $count = 0;
        foreach ($filtered_sources as $id => $entry) {
            $relevance = isset($entry['_relevance_score']) ? $entry['_relevance_score'] : 0;
            $prompt .= "ID: " . $id . " | Relevance: " . $relevance . " | Title: " . $entry['title'] . "\n";
            $prompt .= "Topics: " . implode(', ', $entry['main_topics']) . "\n";
            $prompt .= "Audience: " . (isset($entry['target_audience']) ? $entry['target_audience'] : 'all');
            $prompt .= " | Difficulty: " . (isset($entry['difficulty_level']) ? $entry['difficulty_level'] : 'intermediate') . "\n";
            // Include existing anchors to avoid
            if (!empty($used_anchors_by_post[$id])) {
                $prompt .= "ALREADY USED ANCHORS (DO NOT USE): " . implode(', ', $used_anchors_by_post[$id]) . "\n";
            }
            $prompt .= "\n";
            $count++;
        }

        $prompt .= "=== TASK ===\n";
        $prompt .= "Select the BEST source posts to link to the target. Consider:\n";
        $prompt .= "- Topic relevance (shared topics/themes)\n";
        $prompt .= "- Audience alignment (same or compatible audiences)\n";
        $prompt .= "- User journey (awareness → consideration → decision progression)\n";
        $prompt .= "- Content difficulty progression (beginner → intermediate → advanced)\n\n";

        $prompt .= "ANCHOR TEXT RULES (CRITICAL - violations will be rejected):\n";
        $prompt .= "1. MUST be a COMPLETE phrase that makes sense standalone (2-5 words)\n";
        $prompt .= "2. MUST describe the TARGET page topic, not the source\n";
        $prompt .= "3. GOOD: 'BRRRR investment strategy', 'mortgage pre-approval', 'rental property financing'\n";
        $prompt .= "4. BAD (will be rejected): 'with the', 'investment with', 'for your', 'the market', 'this is'\n";
        $prompt .= "5. Do NOT start with: the, a, an, this, that, with, and, or, in, on, to\n";
        $prompt .= "6. Do NOT end with: the, a, an, and, or, to, in, on, with\n";
        $prompt .= "7. NEVER reuse anchors already used in that post\n\n";

        $prompt .= "QUALITY RULES:\n";
        $prompt .= "- Only suggest links with GENUINE topical relevance\n";
        $prompt .= "- Quality over quantity - skip if no good anchor exists\n";
        $prompt .= "- Maximum 15 links suggested\n\n";

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
        $skipped_anchors = 0;

        foreach ($suggestions as $suggestion) {
            $source_id = intval($suggestion['source_id']);
            $anchor = sanitize_text_field($suggestion['anchor_text']);

            if (!$source_id || !$anchor) continue;

            // Validate anchor text quality before inserting
            if (!$this->validate_anchor_text($anchor)) {
                $this->debug_log("Rejected poor anchor text: '{$anchor}' for source {$source_id}");
                $skipped_anchors++;
                continue;
            }

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
        
        if ($skipped_anchors > 0) {
            $this->debug_log("Skipped {$skipped_anchors} links due to poor anchor text quality");
        }

        return array(
            'success' => true,
            'links_created' => count($links_created),
            'links' => $links_created,
            'errors' => $errors,
            'skipped_anchors' => $skipped_anchors,
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
     * Call Claude API with retry logic and exponential backoff
     * Retries on network errors and rate limits (429)
     */
    private function call_claude_api($prompt, $max_tokens = 1000) {
        if (empty($this->api_key)) {
            return array('success' => false, 'error' => 'API key not set');
        }

        $last_error = '';

        for ($attempt = 0; $attempt <= $this->max_retries; $attempt++) {
            // Wait before retry (skip on first attempt)
            if ($attempt > 0) {
                $delay = isset($this->retry_delays[$attempt - 1]) ? $this->retry_delays[$attempt - 1] : 8;
                $this->debug_log("API retry attempt {$attempt} after {$delay}s delay");
                sleep($delay);
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

            // Network error - retry
            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $this->debug_log('API network error: ' . $last_error);
                continue; // Retry
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // Rate limited (429) or server error (5xx) - retry
            if ($response_code === 429 || $response_code >= 500) {
                $last_error = 'HTTP ' . $response_code . ' - ' . (isset($body['error']['message']) ? $body['error']['message'] : 'Server error');
                $this->debug_log('API rate limit or server error: ' . $last_error);
                continue; // Retry
            }

            // Client error (4xx except 429) - don't retry
            if ($response_code !== 200) {
                $error_msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $response_code;
                error_log('LendCity API: Error response - ' . $error_msg);
                return array('success' => false, 'error' => $error_msg);
            }

            // Validate response structure
            if (!isset($body['content'][0]['text'])) {
                error_log('LendCity API: Invalid response structure - ' . wp_remote_retrieve_body($response));
                return array('success' => false, 'error' => 'Invalid API response');
            }

            // Success!
            return array('success' => true, 'text' => $body['content'][0]['text']);
        }

        // All retries exhausted
        error_log('LendCity API: All retries exhausted - ' . $last_error);
        return array('success' => false, 'error' => 'API request failed after ' . ($this->max_retries + 1) . ' attempts: ' . $last_error);
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
