<?php
/**
 * Plugin Name: LendCity Tools
 * Plugin URI: https://lendcity.ca
 * Description: AI-powered Smart Linker, Article Scheduler, and Bulk Metadata
 * Version: 11.7.14
 * Author: LendCity Mortgages
 * Author URI: https://lendcity.ca
 * License: GPL v2 or later
 * Text Domain: lendcity-claude
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LENDCITY_CLAUDE_VERSION', '11.7.14');
define('LENDCITY_CLAUDE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LENDCITY_CLAUDE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Register custom cron schedules early
add_filter('cron_schedules', 'lendcity_claude_cron_schedules');
function lendcity_claude_cron_schedules($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute')
        );
    }
    if (!isset($schedules['every_15_minutes'])) {
        $schedules['every_15_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes')
        );
    }
    // Dynamic article frequency schedule (matches publishing frequency setting)
    $frequency_days = get_option('lendcity_article_frequency', 3);
    $schedules['lendcity_article_frequency'] = array(
        'interval' => $frequency_days * DAY_IN_SECONDS,
        'display' => sprintf(__('Every %d days (Article Frequency)'), $frequency_days)
    );
    return $schedules;
}

// Activation hook - runs when plugin is activated
register_activation_hook(__FILE__, 'lendcity_claude_activate');
function lendcity_claude_activate() {
    // Clear any old cron jobs that might be lingering
    lendcity_claude_clear_all_crons();

    // Create/upgrade the catalog database table
    require_once LENDCITY_CLAUDE_PLUGIN_DIR . 'includes/class-smart-linker.php';
    $smart_linker = new LendCity_Smart_Linker();
    $smart_linker->maybe_create_table();

    // Clean up old wp_options catalog (v10 and earlier) - don't migrate, new catalog is smarter
    $old_catalog = get_option('lendcity_post_catalog', array());
    if (!empty($old_catalog)) {
        // Backup before deleting
        update_option('lendcity_post_catalog_backup_v10', $old_catalog);
        delete_option('lendcity_post_catalog');
        delete_option('lendcity_post_catalog_built_at');
        error_log('LendCity: Cleaned up old wp_options catalog. Please rebuild catalog for v11 enriched data.');
    }

    // Schedule fresh crons (NOT link queue - that's scheduled dynamically when needed)
    if (!wp_next_scheduled('lendcity_auto_schedule_articles')) {
        wp_schedule_event(time(), 'lendcity_article_frequency', 'lendcity_auto_schedule_articles');
    }
    // Podcast publishing is now handled via Transistor webhooks (no cron needed)
}

// Deactivation hook - runs when plugin is deactivated
register_deactivation_hook(__FILE__, 'lendcity_claude_deactivate');
function lendcity_claude_deactivate() {
    lendcity_claude_clear_all_crons();
}

// Allow iframes in post content (WordPress strips them by default)
add_filter('wp_kses_allowed_html', 'lendcity_allow_iframes_in_posts', 10, 2);
function lendcity_allow_iframes_in_posts($allowed_tags, $context) {
    if ($context === 'post') {
        $allowed_tags['iframe'] = array(
            'src' => true,
            'width' => true,
            'height' => true,
            'frameborder' => true,
            'scrolling' => true,
            'seamless' => true,
            'loading' => true,
            'allow' => true,
            'allowfullscreen' => true,
            'class' => true,
            'id' => true,
            'style' => true,
        );
    }
    return $allowed_tags;
}

// Helper function to clear all plugin crons
function lendcity_claude_clear_all_crons() {
    // Get the full cron array
    $cron = _get_cron_array();
    if (empty($cron)) {
        return;
    }
    
    // All hooks that belong to this plugin (including old/renamed ones)
    $plugin_hooks = array(
        'lendcity_process_link_queue',
        'lendcity_auto_schedule_articles', 
        'lendcity_check_podcasts',
        'lendcity_auto_link_post',
    );
    
    // Loop through entire cron array and remove ALL events for our hooks
    foreach ($cron as $timestamp => $hooks) {
        foreach ($hooks as $hook => $events) {
            // Check if this hook belongs to our plugin
            if (in_array($hook, $plugin_hooks) || strpos($hook, 'lendcity_') === 0) {
                foreach ($events as $key => $event) {
                    wp_unschedule_event($timestamp, $hook, $event['args']);
                }
            }
        }
    }
    
    // Also use wp_clear_scheduled_hook for good measure
    foreach ($plugin_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    
    if (get_option('lendcity_debug_mode', 'no') === 'yes') {
        error_log('LendCity: Cleared all plugin cron jobs');
    }
}

/**
 * Debug logging helper - only logs if debug mode is enabled
 */
function lendcity_debug_log($message) {
    if (get_option('lendcity_debug_mode', 'no') === 'yes') {
        error_log('LendCity: ' . $message);
    }
}

/**
 * Always log - for important messages (errors, success)
 */
function lendcity_log($message) {
    error_log('LendCity: ' . $message);
}

// Include required files
require_once LENDCITY_CLAUDE_PLUGIN_DIR . 'includes/class-claude-api.php';
require_once LENDCITY_CLAUDE_PLUGIN_DIR . 'includes/class-smart-linker.php';

class LendCity_Claude_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_upgrade_notice'));
        add_action('wp_ajax_lendcity_dismiss_v11_notice', array($this, 'ajax_dismiss_v11_notice'));
        add_action('wp_ajax_lendcity_get_catalog_stats', array($this, 'ajax_get_catalog_stats'));

        // Smart Linker AJAX
        add_action('wp_ajax_lendcity_get_all_content_ids', array($this, 'ajax_get_all_content_ids'));
        add_action('wp_ajax_lendcity_build_single_catalog', array($this, 'ajax_build_single_catalog'));
        add_action('wp_ajax_lendcity_build_catalog_batch', array($this, 'ajax_build_catalog_batch'));
        add_action('wp_ajax_lendcity_build_parallel_catalog', array($this, 'ajax_build_parallel_catalog'));
        add_action('wp_ajax_lendcity_update_link_counts', array($this, 'ajax_update_link_counts'));
        add_action('wp_ajax_lendcity_clear_catalog', array($this, 'ajax_clear_catalog'));
        add_action('wp_ajax_lendcity_get_link_suggestions', array($this, 'ajax_get_link_suggestions'));
        add_action('wp_ajax_lendcity_insert_approved_links', array($this, 'ajax_insert_approved_links'));
        add_action('wp_ajax_lendcity_queue_all_linking', array($this, 'ajax_queue_all_linking'));
        add_action('wp_ajax_lendcity_clear_link_queue', array($this, 'ajax_clear_link_queue'));
        add_action('wp_ajax_lendcity_get_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_lendcity_remove_single_link', array($this, 'ajax_remove_single_link'));
        add_action('wp_ajax_lendcity_remove_all_smart_links', array($this, 'ajax_remove_all_smart_links'));
        add_action('wp_ajax_lendcity_update_smart_link_urls', array($this, 'ajax_update_smart_link_urls'));
        add_action('wp_ajax_lendcity_change_link_target', array($this, 'ajax_change_link_target'));
        add_action('wp_ajax_lendcity_delete_all_site_links', array($this, 'ajax_delete_all_site_links'));
        add_action('wp_ajax_lendcity_process_single_source', array($this, 'ajax_process_single_source'));
        add_action('wp_ajax_lendcity_process_queue_now', array($this, 'ajax_process_queue_now'));
        
        // New background queue handlers
        add_action('wp_ajax_lendcity_init_bulk_queue', array($this, 'ajax_init_bulk_queue'));
        add_action('wp_ajax_lendcity_process_queue_batch', array($this, 'ajax_process_queue_batch'));
        add_action('wp_ajax_lendcity_pause_queue', array($this, 'ajax_pause_queue'));
        add_action('wp_ajax_lendcity_resume_queue', array($this, 'ajax_resume_queue'));
        
        // SEO Enhancement AJAX
        add_action('wp_ajax_lendcity_save_page_seo', array($this, 'ajax_save_page_seo'));
        add_action('wp_ajax_lendcity_get_link_gaps', array($this, 'ajax_get_link_gaps'));
        add_action('wp_ajax_lendcity_get_link_stats', array($this, 'ajax_get_link_stats'));
        add_action('wp_ajax_lendcity_get_links_page', array($this, 'ajax_get_links_page'));
        
        // Metadata AJAX (used by Smart Linker)
        add_action('wp_ajax_lendcity_generate_metadata_from_links', array($this, 'ajax_generate_metadata_from_links'));

        // Smart Metadata v2 AJAX (runs AFTER linking)
        add_action('wp_ajax_lendcity_generate_smart_metadata', array($this, 'ajax_generate_smart_metadata'));
        add_action('wp_ajax_lendcity_get_smart_metadata_posts', array($this, 'ajax_get_smart_metadata_posts'));
        add_action('wp_ajax_lendcity_bulk_smart_metadata', array($this, 'ajax_bulk_smart_metadata'));

        // Meta Queue AJAX (persistent background processing)
        add_action('wp_ajax_lendcity_init_meta_queue', array($this, 'ajax_init_meta_queue'));
        add_action('wp_ajax_lendcity_get_meta_queue_status', array($this, 'ajax_get_meta_queue_status'));
        add_action('wp_ajax_lendcity_clear_meta_queue', array($this, 'ajax_clear_meta_queue'));

        // SEO Health Monitor AJAX
        add_action('wp_ajax_lendcity_get_seo_health_issues', array($this, 'ajax_get_seo_health_issues'));
        add_action('wp_ajax_lendcity_auto_fix_seo', array($this, 'ajax_auto_fix_seo'));

        // Keyword Ownership AJAX
        add_action('wp_ajax_lendcity_build_keyword_ownership', array($this, 'ajax_build_keyword_ownership'));
        add_action('wp_ajax_lendcity_get_keyword_ownership_stats', array($this, 'ajax_get_keyword_ownership_stats'));
        add_action('wp_ajax_lendcity_get_keyword_ownership_list', array($this, 'ajax_get_keyword_ownership_list'));
        add_action('wp_ajax_lendcity_clear_keyword_ownership', array($this, 'ajax_clear_keyword_ownership'));

        // Article Scheduler AJAX
        add_action('wp_ajax_lendcity_process_article', array($this, 'ajax_process_article'));
        add_action('wp_ajax_lendcity_schedule_all_articles', array($this, 'ajax_schedule_all_articles'));
        add_action('wp_ajax_lendcity_delete_queued_file', array($this, 'ajax_delete_queued_file'));
        add_action('wp_ajax_lendcity_add_unsplash_image', array($this, 'ajax_add_unsplash_image'));
        add_action('wp_ajax_lendcity_replace_unsplash_image', array($this, 'ajax_replace_unsplash_image'));
        add_action('wp_ajax_lendcity_run_auto_scheduler', array($this, 'ajax_run_auto_scheduler'));
        add_action('wp_ajax_lendcity_run_auto_scheduler_single', array($this, 'ajax_run_auto_scheduler_single'));
        
        // Settings AJAX
        add_action('wp_ajax_lendcity_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_lendcity_test_tinypng', array($this, 'ajax_test_tinypng'));
        
        // Podcast AJAX (Backfill only - new episodes handled via Transistor webhook)
        add_action('wp_ajax_lendcity_scan_transistor_embeds', array($this, 'ajax_scan_transistor_embeds'));
        add_action('wp_ajax_lendcity_backfill_podcast_episodes', array($this, 'ajax_backfill_podcast_episodes'));
        add_action('wp_ajax_lendcity_get_podcast_debug_log', array($this, 'ajax_get_podcast_debug_log'));
        
        // Smart Linker cron
        add_action('lendcity_process_link_queue', array($this, 'cron_process_link_queue'));
        
        // Auto-schedule cron (maintains minimum scheduled posts)
        add_action('lendcity_auto_schedule_articles', array($this, 'cron_auto_schedule_articles'));
        add_action('init', array($this, 'setup_auto_schedule_cron'));

        // Transistor Webhook REST API
        add_action('rest_api_init', array($this, 'register_transistor_webhook'));

        // Clean up stale crons on admin init (runs once per version)
        add_action('admin_init', array($this, 'maybe_cleanup_stale_crons'));

        // Save show mappings on settings save
        add_action('admin_init', array($this, 'save_show_mappings'));

        // Webhook secret regeneration AJAX
        add_action('wp_ajax_lendcity_regenerate_webhook_secret', array($this, 'ajax_regenerate_webhook_secret'));

        // Initialize Smart Linker
        new LendCity_Smart_Linker();
    }
    
    /**
     * Clean up stale crons if version changed
     */
    public function maybe_cleanup_stale_crons() {
        $last_version = get_option('lendcity_claude_last_version', '');
        if ($last_version !== LENDCITY_CLAUDE_VERSION) {
            // Version changed - clear old crons and reschedule
            lendcity_claude_clear_all_crons();

            // Clear any stale queue status (prevents auto-run after plugin update)
            delete_option('lendcity_smart_linker_queue');
            delete_option('lendcity_smart_linker_queue_status');

            // Reschedule (NOT link queue - that's scheduled dynamically)
            if (!wp_next_scheduled('lendcity_auto_schedule_articles')) {
                wp_schedule_event(time(), 'lendcity_article_frequency', 'lendcity_auto_schedule_articles');
            }
            // Podcast publishing handled via Transistor webhooks (no cron)

            update_option('lendcity_claude_last_version', LENDCITY_CLAUDE_VERSION);
            lendcity_debug_log('Cleaned up stale crons and queue for version ' . LENDCITY_CLAUDE_VERSION);
        }
    }
    
    public function register_settings() {
        register_setting('lendcity_claude_settings', 'lendcity_claude_api_key');
        register_setting('lendcity_claude_settings', 'lendcity_unsplash_api_key');
        register_setting('lendcity_claude_settings', 'lendcity_tinypng_api_key');
        register_setting('lendcity_claude_settings', 'lendcity_smart_linker_auto');
        register_setting('lendcity_claude_settings', 'lendcity_debug_mode');

        // Podcast webhook settings
        register_setting('lendcity_claude_settings', 'lendcity_transistor_webhook_secret');
        register_setting('lendcity_claude_settings', 'lendcity_transistor_api_key');
        register_setting('lendcity_claude_settings', 'lendcity_transistor_shows'); // JSON: show_id => category mapping
        register_setting('lendcity_claude_settings', 'lendcity_show_id_1');
        register_setting('lendcity_claude_settings', 'lendcity_show_category_1');
        register_setting('lendcity_claude_settings', 'lendcity_show_id_2');
        register_setting('lendcity_claude_settings', 'lendcity_show_category_2');
    }

    /**
     * Save show mappings when settings are saved
     */
    public function save_show_mappings() {
        // Only run on settings save (check for settings page and proper nonce)
        if (!isset($_POST['option_page']) || $_POST['option_page'] !== 'lendcity_claude_settings') {
            return;
        }

        // Verify the settings nonce (WordPress adds this automatically)
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lendcity_claude_settings-options')) {
            return;
        }

        // Check user permissions
        if (!current_user_can('manage_options')) {
            return;
        }

        // Build and save show mappings
        $shows = array();
        if (!empty($_POST['lendcity_show_id_1']) && !empty($_POST['lendcity_show_category_1'])) {
            $shows[sanitize_text_field($_POST['lendcity_show_id_1'])] = sanitize_text_field($_POST['lendcity_show_category_1']);
        }
        if (!empty($_POST['lendcity_show_id_2']) && !empty($_POST['lendcity_show_category_2'])) {
            $shows[sanitize_text_field($_POST['lendcity_show_id_2'])] = sanitize_text_field($_POST['lendcity_show_category_2']);
        }
        if (!empty($shows)) {
            update_option('lendcity_transistor_shows', json_encode($shows));
        }
    }
    
    /**
     * Show admin notice for v11 upgrade - rebuild catalog for enriched metadata
     */
    public function show_upgrade_notice() {
        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'lendcity') === false) {
            return;
        }

        // Check if user dismissed the notice
        if (get_option('lendcity_v11_notice_dismissed', false)) {
            return;
        }

        // Check if catalog is empty (new install or needs rebuild)
        $smart_linker = new LendCity_Smart_Linker();
        $catalog_count = $smart_linker->get_catalog_count();

        if ($catalog_count === 0) {
            ?>
            <div class="notice notice-info is-dismissible" id="lendcity-v11-notice">
                <p><strong>LendCity Tools v11.0 - Scalable Database Catalog</strong></p>
                <p>This version features a new high-performance database-backed catalog with enriched metadata for smarter linking:</p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong>Topic Clusters</strong> - Group related content together</li>
                    <li><strong>Funnel Stages</strong> - Awareness, Consideration, Decision</li>
                    <li><strong>Difficulty Levels</strong> - Beginner, Intermediate, Advanced</li>
                    <li><strong>Reader Intent</strong> - Educational, Transactional, Navigational</li>
                    <li><strong>Quality Scores</strong> - Prioritize high-quality content</li>
                </ul>
                <p><a href="<?php echo admin_url('admin.php?page=lendcity-claude-smart-linker'); ?>" class="button button-primary">Go to Smart Linker to Build Catalog</a>
                <button type="button" class="button" onclick="jQuery.post(ajaxurl, {action: 'lendcity_dismiss_v11_notice', nonce: '<?php echo wp_create_nonce('lendcity_claude_nonce'); ?>'}, function(){ jQuery('#lendcity-v11-notice').fadeOut(); });">Dismiss</button></p>
            </div>
            <?php
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'LendCity Tools',
            'LendCity Tools',
            'manage_options',
            'lendcity-claude',
            array($this, 'smart_linker_page'),
            'dashicons-admin-generic',
            30
        );

        add_submenu_page(
            'lendcity-claude',
            'Smart Linker',
            'Smart Linker',
            'manage_options',
            'lendcity-claude',
            array($this, 'smart_linker_page')
        );
        
        add_submenu_page(
            'lendcity-claude',
            'Article Scheduler',
            'Article Scheduler',
            'manage_options',
            'lendcity-claude-article-scheduler',
            array($this, 'article_scheduler_page')
        );
        
        add_submenu_page(
            'lendcity-claude',
            'Podcast Publisher',
            'Podcast Publisher',
            'manage_options',
            'lendcity-claude-podcast',
            array($this, 'podcast_publisher_page')
        );
        
        add_submenu_page(
            'lendcity-claude',
            'Settings',
            'Settings',
            'manage_options',
            'lendcity-claude-settings',
            array($this, 'settings_page')
        );
    }
    
    // ==================== PAGE RENDERS ====================

    public function smart_linker_page() {
        include LENDCITY_CLAUDE_PLUGIN_DIR . 'admin/views/smart-linker-page.php';
    }
    
    public function article_scheduler_page() {
        include LENDCITY_CLAUDE_PLUGIN_DIR . 'admin/views/article-scheduler-page.php';
    }
    
    public function podcast_publisher_page() {
        include LENDCITY_CLAUDE_PLUGIN_DIR . 'admin/views/podcast-publisher-page.php';
    }
    
    public function settings_page() {
        include LENDCITY_CLAUDE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
    
    // ==================== SMART LINKER AJAX ====================
    
    public function ajax_get_all_content_ids() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        wp_send_json_success(array('ids' => $posts));
    }

    /**
     * Dismiss the v11 upgrade notice
     */
    public function ajax_dismiss_v11_notice() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        update_option('lendcity_v11_notice_dismissed', true);
        wp_send_json_success();
    }

    /**
     * Get catalog stats for the new database-backed catalog
     */
    public function ajax_get_catalog_stats() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $stats = $smart_linker->get_catalog_stats();
        $clusters = $smart_linker->get_all_clusters();

        wp_send_json_success(array(
            'stats' => $stats,
            'clusters' => $clusters
        ));
    }

    public function ajax_build_single_catalog() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $post_id = intval($_POST['post_id']);
        $smart_linker = new LendCity_Smart_Linker();
        $entry = $smart_linker->build_single_post_catalog($post_id);

        if ($entry && is_array($entry) && isset($entry['post_id'])) {
            // Save to database table (v11 scalable catalog)
            $smart_linker->insert_catalog_entry($post_id, $entry);

            wp_send_json_success(array(
                'post_id' => $post_id,
                'title' => $entry['title'],
                'topic_cluster' => $entry['topic_cluster'] ?? '',
                'funnel_stage' => $entry['funnel_stage'] ?? '',
                'difficulty_level' => $entry['difficulty_level'] ?? ''
            ));
        } else {
            wp_send_json_error('Failed to build catalog entry');
        }
    }
    
    /**
     * Build catalog entries in batches - SINGLE API call per batch
     */
    public function ajax_build_catalog_batch() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : array();

        if (empty($post_ids)) {
            wp_send_json_error('No post IDs provided');
        }

        $smart_linker = new LendCity_Smart_Linker();

        // Use batch function - saves directly to database table (v11 scalable catalog)
        $entries = $smart_linker->build_batch_catalog($post_ids);

        wp_send_json_success(array(
            'processed' => count($post_ids),
            'success' => count($entries),
            'results' => array_keys($entries)
        ));
    }

    /**
     * Build catalog in PARALLEL - 3-5x faster using concurrent API calls
     */
    public function ajax_build_parallel_catalog() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Increase time limit for parallel processing
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : array();
        $concurrent = isset($_POST['concurrent']) ? min(5, max(1, intval($_POST['concurrent']))) : 3;

        if (empty($post_ids)) {
            wp_send_json_error('No post IDs provided');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $entries = $smart_linker->build_parallel_catalog($post_ids, $concurrent);

        wp_send_json_success(array(
            'processed' => count($post_ids),
            'success' => count($entries),
            'concurrent' => $concurrent,
            'results' => array_keys($entries)
        ));
    }

    /**
     * Update link counts for all catalog entries
     */
    public function ajax_update_link_counts() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->update_link_counts();

        wp_send_json_success(array('message' => 'Link counts updated'));
    }

    /**
     * Clear the entire catalog
     */
    public function ajax_clear_catalog() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Clear the new database table
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->clear_catalog();

        // Also clean up any legacy wp_options catalog
        delete_option('lendcity_post_catalog');
        delete_option('lendcity_post_catalog_built_at');

        wp_send_json_success(array('message' => 'Catalog cleared'));
    }

    public function ajax_get_link_suggestions() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $target_id = intval($_POST['target_id']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->get_link_suggestions($target_id);
        
        wp_send_json_success($result);
    }
    
    public function ajax_insert_approved_links() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $target_id = intval($_POST['target_id']);
        $links = json_decode(stripslashes($_POST['links']), true);
        
        if (!is_array($links)) {
            wp_send_json_error('Invalid links data');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->insert_approved_links($target_id, $links);
        
        wp_send_json_success($result);
    }
    
    public function ajax_queue_all_linking() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->queue_all_for_linking();
        
        wp_send_json_success($result);
    }
    
    public function ajax_clear_link_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->clear_queue();
        
        wp_send_json_success();
    }
    
    public function ajax_get_queue_status() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        
        $smart_linker = new LendCity_Smart_Linker();
        $status = $smart_linker->get_queue_status();
        
        wp_send_json_success($status);
    }
    
    public function ajax_remove_single_link() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        $link_id = sanitize_text_field($_POST['link_id']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->remove_link($post_id, $link_id);
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to remove link');
        }
    }
    
    public function ajax_remove_all_smart_links() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->remove_all_links($post_id);
        
        wp_send_json_success();
    }
    
    public function ajax_update_smart_link_urls() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $old_url = esc_url_raw($_POST['old_url']);
        $new_url = esc_url_raw($_POST['new_url']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $updated = $smart_linker->update_url_across_site($old_url, $new_url);
        
        wp_send_json_success(array('updated_count' => $updated));
    }
    
    public function ajax_change_link_target() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $source_id = intval($_POST['source_id']);
        $link_id = sanitize_text_field($_POST['link_id']);
        $old_url = esc_url_raw($_POST['old_url']);
        $new_url = esc_url_raw($_POST['new_url']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->change_single_link_target($source_id, $link_id, $old_url, $new_url);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_delete_all_site_links() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->delete_all_site_links();
        
        wp_send_json_success($result);
    }
    
    public function ajax_process_single_source() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $source_id = intval($_POST['source_id']);
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->create_links_from_source($source_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_process_queue_now() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->process_queue_batch();
        
        wp_send_json_success($result);
    }
    
    /**
     * Initialize bulk queue (for 1000+ posts)
     */
    public function ajax_init_bulk_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $skip_with_links = isset($_POST['skip_with_links']) ? ($_POST['skip_with_links'] === 'true' || $_POST['skip_with_links'] === '1') : true;
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->init_bulk_queue($skip_with_links);
        
        wp_send_json_success($result);
    }
    
    /**
     * Process a batch of queue items
     */
    public function ajax_process_queue_batch() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Increase time limit for batch processing
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->process_queue_batch();
        
        wp_send_json_success($result);
    }
    
    /**
     * Pause the queue
     */
    public function ajax_pause_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->pause_queue();
        
        wp_send_json_success();
    }
    
    /**
     * Resume the queue
     */
    public function ajax_resume_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->resume_queue();
        
        wp_send_json_success();
    }
    
    public function cron_process_link_queue() {
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->process_queue_batch();
    }
    
    // ==================== SEO ENHANCEMENT AJAX ====================
    
    public function ajax_save_page_seo() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $page_id = intval($_POST['page_id']);
        $priority = intval($_POST['priority']);
        $keywords = sanitize_text_field($_POST['keywords'] ?? '');
        
        if (!$page_id) {
            wp_send_json_error('Invalid page ID');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->set_page_priority($page_id, $priority);
        $smart_linker->set_page_keywords($page_id, $keywords);
        
        wp_send_json_success(array(
            'page_id' => $page_id,
            'priority' => $priority,
            'keywords' => $keywords
        ));
    }
    
    public function ajax_get_link_gaps() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $max_links = isset($_POST['max_links']) ? intval($_POST['max_links']) : 2;
        
        $smart_linker = new LendCity_Smart_Linker();
        $gaps = $smart_linker->get_link_gaps(0, $max_links);
        
        wp_send_json_success(array('gaps' => $gaps));
    }
    
    public function ajax_get_link_stats() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $smart_linker = new LendCity_Smart_Linker();
        $stats = $smart_linker->get_link_stats();
        
        wp_send_json_success($stats);
    }
    
    /**
     * Get paginated links for the table
     */
    public function ajax_get_links_page() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $offset = intval($_POST['offset'] ?? 0);
        $limit = intval($_POST['limit'] ?? 50);
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        $smart_linker = new LendCity_Smart_Linker();
        $all_links = $smart_linker->get_all_site_links(2000); // Get all for filtering
        
        // Filter by search if provided
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $all_links = array_filter($all_links, function($link) use ($search_lower) {
                return strpos(strtolower($link['source_post_title']), $search_lower) !== false ||
                       strpos(strtolower($link['anchor']), $search_lower) !== false ||
                       strpos(strtolower($link['url']), $search_lower) !== false;
            });
            $all_links = array_values($all_links); // Re-index
        }
        
        $total = count($all_links);
        $links = array_slice($all_links, $offset, $limit);
        
        // Get all pages and posts for the edit dropdown
        $all_pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $all_posts_list = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC'));
        
        // Build HTML
        $html = '';
        foreach ($links as $link) {
            $edit_link = get_edit_post_link($link['source_post_id']);
            $is_page = !empty($link['is_page']) ? 'page' : 'post';
            $target_display = str_replace(home_url(), '', $link['url']);
            
            $html .= '<tr data-link-id="' . esc_attr($link['link_id']) . '" 
                data-source-id="' . esc_attr($link['source_post_id']) . '" 
                data-current-url="' . esc_attr($link['url']) . '"
                data-source-title="' . esc_attr(strtolower($link['source_post_title'])) . '"
                data-anchor="' . esc_attr(strtolower($link['anchor'])) . '"
                data-target="' . esc_attr(strtolower($target_display)) . '"
                data-type="' . $is_page . '">';
            $html .= '<td><a href="' . esc_url($edit_link) . '" target="_blank">' . esc_html($link['source_post_title']) . '</a></td>';
            $html .= '<td><code>' . esc_html($link['anchor']) . '</code></td>';
            
            // Target cell with view link and edit dropdown
            $html .= '<td class="target-cell">';
            $html .= '<span class="target-display" style="cursor: pointer; color: #2271b1;" title="Click to change destination">' . esc_html($target_display) . '</span>';
            $html .= '<a href="' . esc_url($link['url']) . '" target="_blank" title="View target page" class="view-target-link" style="margin-left: 8px; text-decoration: none; font-size: 16px;">🔗</a>';
            
            // Edit dropdown
            $html .= '<div class="target-edit" style="display: none; margin-top: 8px;">';
            $html .= '<select class="target-select" style="width: 100%;">';
            $html .= '<option value="">— Select new target —</option>';
            $html .= '<optgroup label="Pages">';
            foreach ($all_pages as $p) {
                $permalink = get_permalink($p->ID);
                $selected = ($permalink === $link['url']) ? ' selected' : '';
                $html .= '<option value="' . esc_attr($permalink) . '"' . $selected . '>' . esc_html($p->post_title) . '</option>';
            }
            $html .= '</optgroup>';
            $html .= '<optgroup label="Posts">';
            foreach ($all_posts_list as $p) {
                $permalink = get_permalink($p->ID);
                $selected = ($permalink === $link['url']) ? ' selected' : '';
                $html .= '<option value="' . esc_attr($permalink) . '"' . $selected . '>' . esc_html($p->post_title) . '</option>';
            }
            $html .= '</optgroup>';
            $html .= '</select>';
            $html .= '<div style="margin-top: 5px;">';
            $html .= '<button type="button" class="button button-primary save-target">Save</button>';
            $html .= '<button type="button" class="button cancel-target">Cancel</button>';
            $html .= '</div></div></td>';
            
            $html .= '<td>' . ($is_page === 'page' ? 'Page' : 'Post') . '</td>';
            $html .= '<td><button type="button" class="button delete-single-link" style="color: #dc3545; padding: 2px 8px;">✕</button></td>';
            $html .= '</tr>';
        }
        
        wp_send_json_success(array(
            'html' => $html,
            'total' => $total,
            'showing' => count($links)
        ));
    }
    
    /**
     * Setup the auto-schedule cron based on publishing frequency
     */
    public function setup_auto_schedule_cron() {
        // Schedule if not already scheduled (uses lendcity_article_frequency registered globally)
        if (!wp_next_scheduled('lendcity_auto_schedule_articles')) {
            wp_schedule_event(time(), 'lendcity_article_frequency', 'lendcity_auto_schedule_articles');
        }
    }
    
    /**
     * Auto-schedule articles to maintain minimum scheduled posts
     */
    public function cron_auto_schedule_articles() {
        $min_scheduled = get_option('lendcity_min_scheduled_posts', 20);
        
        // Count current scheduled posts
        $scheduled_posts = get_posts(array(
            'post_status' => 'future',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        $current_scheduled = count($scheduled_posts);
        
        // If we have enough, do nothing
        if ($current_scheduled >= $min_scheduled) {
            error_log('LendCity Auto-Schedule: Already have ' . $current_scheduled . ' scheduled posts (min: ' . $min_scheduled . ')');
            return 0;
        }
        
        // Calculate how many we need to add
        $needed = $min_scheduled - $current_scheduled;
        
        // Get queued files
        $queue_dir = wp_upload_dir()['basedir'] . '/lendcity-article-queue';
        $queued_files = glob($queue_dir . '/*.docx');
        
        if (empty($queued_files)) {
            error_log('LendCity Auto-Schedule: No queued files available');
            return 0;
        }
        
        // Shuffle for random selection
        shuffle($queued_files);
        
        // Process up to $needed files
        $processed = 0;
        foreach ($queued_files as $file_path) {
            if ($processed >= $needed) break;
            
            $result = $this->process_article_file($file_path);
            if ($result['success']) {
                $processed++;
                lendcity_log('Auto-Schedule: Processed ' . basename($file_path) . ' - Post ID: ' . $result['post_id']);
            } else {
                lendcity_log('Auto-Schedule: Failed to process ' . basename($file_path) . ' - ' . ($result['error'] ?? 'Unknown error'));
            }
        }
        
        lendcity_debug_log('Auto-Schedule: Processed ' . $processed . ' articles. Now have ' . ($current_scheduled + $processed) . ' scheduled posts.');
        
        return $processed;
    }
    
    /**
     * AJAX handler for manual auto-scheduler trigger
     */
    public function ajax_run_auto_scheduler() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $processed = $this->cron_auto_schedule_articles();
        
        wp_send_json_success(array(
            'processed' => $processed,
            'message' => 'Processed ' . $processed . ' articles'
        ));
    }
    
    /**
     * AJAX handler for processing a single article from queue (for manual auto-scheduler)
     */
    public function ajax_run_auto_scheduler_single() {
        // Extend PHP execution time for long API calls
        @set_time_limit(300);
        
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get queued files
        $queue_dir = wp_upload_dir()['basedir'] . '/lendcity-article-queue';
        $queued_files = glob($queue_dir . '/*.docx');
        
        if (empty($queued_files)) {
            wp_send_json_error('No queued files');
        }
        
        // Sort alphabetically for consistency
        sort($queued_files);
        
        // Get the first file
        $file_path = $queued_files[0];
        $file_name = basename($file_path);
        
        error_log('LendCity Auto-Scheduler: Processing ' . $file_name);
        
        // Process it
        $result = $this->process_article_file($file_path);
        
        if ($result['success']) {
            error_log('LendCity Auto-Scheduler: Success - Post ID ' . $result['post_id']);
            wp_send_json_success(array(
                'post_id' => $result['post_id'],
                'title' => $result['title'] ?? $file_name
            ));
        } else {
            error_log('LendCity Auto-Scheduler: Failed - ' . ($result['error'] ?? 'Unknown error'));
            
            // If it was a skip (empty file deleted), return success so it continues to next
            if (!empty($result['skip'])) {
                wp_send_json_success(array(
                    'skipped' => true,
                    'message' => $result['error']
                ));
            } else {
                wp_send_json_error($result['error'] ?? 'Processing failed');
            }
        }
    }
    
    /**
     * Process a single article file (used by both manual and auto-schedule)
     */
    private function process_article_file($file_path) {
        if (!file_exists($file_path)) {
            return array('success' => false, 'error' => 'File not found', 'skip' => true);
        }
        
        $file_name = basename($file_path);
        
        // Read DOCX content
        require_once LENDCITY_CLAUDE_PLUGIN_DIR . 'includes/docx-reader.php';
        $docx_text = lendcity_extract_docx_text($file_path);
        
        if (empty($docx_text) || strlen(trim($docx_text)) < 100) {
            // Delete empty/invalid file and skip
            error_log('LendCity: Deleting empty/invalid file: ' . $file_name);
            @unlink($file_path);
            return array('success' => false, 'error' => 'Empty or invalid document - deleted from queue', 'skip' => true);
        }
        
        // Get settings
        $publish_frequency = get_option('lendcity_article_frequency', 3);
        $publish_time = get_option('lendcity_article_publish_time', '06:00');
        $default_category = get_option('lendcity_claude_default_category', 1);
        $timezone = get_option('timezone_string') ?: 'America/Toronto';
        
        // Get existing tags and categories
        $existing_tags = get_tags(array('hide_empty' => false));
        $existing_tag_names = array();
        foreach ($existing_tags as $tag) {
            $existing_tag_names[] = $tag->name;
        }
        $existing_tags_list = !empty($existing_tag_names) ? implode(', ', $existing_tag_names) : 'None yet';
        
        $existing_categories = get_categories(array('hide_empty' => false));
        $existing_cat_names = array();
        foreach ($existing_categories as $cat) {
            $existing_cat_names[] = $cat->name;
        }
        $existing_cats_list = implode(', ', $existing_cat_names);
        
        // Send to Claude for processing
        $api = new LendCity_Claude_API();
        
        $prompt = "You are a friendly content writer for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
        $prompt .= "CRITICAL: COMPLETELY REWRITE the content below. Do NOT copy it word-for-word.\n\n";
        
        $prompt .= "WRITING STYLE (VERY IMPORTANT):\n";
        $prompt .= "- Write like a friendly expert explaining things to a regular person\n";
        $prompt .= "- Use simple, everyday words - avoid fancy or complex vocabulary\n";
        $prompt .= "- Keep sentences short and punchy\n";
        $prompt .= "- NO corporate buzzwords or jargon\n";
        $prompt .= "- NO phrases like 'navigate', 'leverage', 'utilize', 'optimize', 'streamline', 'robust', 'comprehensive'\n";
        $prompt .= "- NO filler phrases like 'It's important to note', 'In today's market', 'When it comes to'\n";
        $prompt .= "- Sound like a real person talking, not a robot or marketing brochure\n";
        $prompt .= "- Be direct and get to the point\n";
        $prompt .= "- Use 'you' and 'your' to speak directly to the reader\n\n";
        
        $prompt .= "TASKS:\n";
        $prompt .= "1. Create a NEW catchy title (50-60 chars) - simple words\n";
        $prompt .= "2. Meta description (150-160 chars)\n";
        $prompt .= "3. REWRITE content as HTML (h2, h3, p, lists)\n";
        $prompt .= "4. Select 8 tags\n";
        $prompt .= "5. Select best category\n";
        $prompt .= "6. Image search words (2-4 words)\n";
        $prompt .= "7. Create 6-8 FAQs\n\n";
        
        $prompt .= "RULES: 100% original, no copied sentences, remove dates/years, explain simply.\n\n";
        
        $prompt .= "EXISTING CATEGORIES: " . $existing_cats_list . "\n";
        $prompt .= "EXISTING TAGS: " . $existing_tags_list . "\n\n";
        
        $prompt .= "FAQ: 6-8 Q&As, short answers (2-3 sentences), put in faqs array NOT in content.\n\n";
        
        $prompt .= "SOURCE MATERIAL TO REWRITE:\n{$docx_text}\n\n";
        $prompt .= "Return as JSON:\n";
        $prompt .= '{"title":"NEW simple title","description":"...","content":"<p>Rewritten HTML</p>","category":"Category Name","tags":["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8"],"image_search":"search terms","faqs":[{"question":"Q1?","answer":"A1"},{"question":"Q2?","answer":"A2"}]}';
        
        $response = $api->generate_content($prompt);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        // Parse response
        $article_data = json_decode($response, true);
        if (!$article_data && preg_match('/\{.*\}/s', $response, $matches)) {
            $article_data = json_decode($matches[0], true);
        }
        
        if (!$article_data || empty($article_data['content'])) {
            return array('success' => false, 'error' => 'Failed to parse AI response');
        }
        
        // Calculate publish date
        $last_scheduled = get_posts(array(
            'post_status' => 'future',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (!empty($last_scheduled)) {
            $base_date = new DateTime($last_scheduled[0]->post_date, new DateTimeZone($timezone));
        } else {
            $base_date = new DateTime('now', new DateTimeZone($timezone));
        }
        
        $base_date->modify('+' . $publish_frequency . ' days');
        $base_date->setTime((int)substr($publish_time, 0, 2), (int)substr($publish_time, 3, 2));
        $publish_date = $base_date->format('Y-m-d H:i:s');
        
        // Handle category
        $category_id = $default_category;
        if (!empty($article_data['category'])) {
            $cat = get_term_by('name', $article_data['category'], 'category');
            if ($cat) {
                $category_id = $cat->term_id;
            } else {
                $new_cat = wp_insert_term($article_data['category'], 'category');
                if (!is_wp_error($new_cat)) {
                    $category_id = $new_cat['term_id'];
                }
            }
        }
        
        // Build SEOPress FAQ block if FAQs provided
        $faq_block = '';
        if (!empty($article_data['faqs']) && is_array($article_data['faqs'])) {
            $faq_block = $this->build_seopress_faq_block($article_data['faqs']);
        }
        
        // Convert HTML content to Gutenberg blocks
        $block_content = $this->convert_html_to_blocks($article_data['content'] ?? '');
        
        // Insert CTA button before second H2
        $block_content = $this->insert_cta_buttons($block_content);
        
        // The synced pattern reference for the "Book Strategy Call" button
        $cta_button = '<!-- wp:block {"ref":1470} /-->';
        
        // Add CTA button before FAQ, then FAQ heading and block at the end
        if (!empty($faq_block)) {
            $faq_heading = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Frequently Asked Questions</h2>' . "\n" . '<!-- /wp:heading -->';
            $block_content .= "\n\n" . $cta_button . "\n\n" . $faq_heading . "\n\n" . $faq_block;
        }
        
        // Create post
        $post_data = array(
            'post_title' => $article_data['title'] ?? pathinfo($file_name, PATHINFO_FILENAME),
            'post_content' => $block_content,
            'post_status' => 'future',
            'post_date' => $publish_date,
            'post_date_gmt' => get_gmt_from_date($publish_date),
            'post_category' => array($category_id),
            'post_author' => 1
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return array('success' => false, 'error' => $post_id->get_error_message());
        }
        
        // Save metadata
        if (!empty($article_data['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', $article_data['title']);
        }
        if (!empty($article_data['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', $article_data['description']);
        }
        if (!empty($article_data['tags'])) {
            wp_set_post_tags($post_id, $article_data['tags']);
        }
        
        // Fetch featured image from Unsplash
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (!empty($unsplash_key) && !empty($article_data['image_search'])) {
            $this->fetch_unsplash_featured_image(
                $post_id,
                $article_data['image_search'],
                $article_data['title'] ?? basename($file_name, '.docx'),
                $unsplash_key
            );
        }
        
        // Delete the source file
        unlink($file_path);
        
        return array('success' => true, 'post_id' => $post_id);
    }
    
    /**
     * Register Transistor webhook REST API endpoint
     */
    public function register_transistor_webhook() {
        register_rest_route('lendcity/v1', '/transistor-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_transistor_webhook'),
            'permission_callback' => '__return_true', // Public endpoint, verified by secret
        ));
    }

    /**
     * Handle incoming Transistor webhook for episode_published events
     * Webhook URL: https://yoursite.com/wp-json/lendcity/v1/transistor-webhook?key=YOUR_SECRET
     */
    public function handle_transistor_webhook($request) {
        // Verify webhook secret
        $provided_key = $request->get_param('key');
        $stored_secret = get_option('lendcity_transistor_webhook_secret', '');

        if (empty($stored_secret)) {
            // Generate a secret if none exists
            $stored_secret = wp_generate_password(32, false);
            update_option('lendcity_transistor_webhook_secret', $stored_secret);
        }

        if ($provided_key !== $stored_secret) {
            lendcity_log('Transistor Webhook: Invalid secret key');
            return new WP_REST_Response(array('error' => 'Invalid key'), 403);
        }

        // Get webhook payload
        $body = $request->get_body();
        $data = json_decode($body, true);

        if (empty($data)) {
            lendcity_log('Transistor Webhook: Empty or invalid JSON payload');
            return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }

        lendcity_log('Transistor Webhook: Received - ' . json_encode($data));

        // Extract episode data from webhook
        // Transistor webhook format: { "event": "episode_published", "data": { "id": "...", "attributes": {...} } }
        $event = $data['event'] ?? '';
        if ($event !== 'episode_published') {
            lendcity_log('Transistor Webhook: Ignoring event type: ' . $event);
            return new WP_REST_Response(array('message' => 'Event ignored'), 200);
        }

        $episode_data = $data['data'] ?? array();
        $attributes = $episode_data['attributes'] ?? array();

        // Get the share_id from the episode data
        $share_id = $attributes['share_id'] ?? '';
        $episode_title = $attributes['title'] ?? 'Untitled Episode';
        $show_id = $episode_data['relationships']['show']['data']['id'] ?? '';

        if (empty($share_id)) {
            // Try to extract from share_url
            $share_url = $attributes['share_url'] ?? '';
            if (preg_match('/share\.transistor\.fm\/s\/([a-z0-9]+)/i', $share_url, $matches)) {
                $share_id = $matches[1];
            }
        }

        if (empty($share_id)) {
            lendcity_log('Transistor Webhook: No share_id found in payload');
            return new WP_REST_Response(array('error' => 'No share_id found'), 400);
        }

        // Get category from show mapping
        $shows_config = get_option('lendcity_transistor_shows', '{}');
        $shows = json_decode($shows_config, true) ?: array();
        $category_name = $shows[$show_id] ?? 'Podcast';

        // Check if already processed
        $processed = get_option('lendcity_processed_podcast_episodes', array());
        foreach ($processed as $ep) {
            if (isset($ep['share_id']) && $ep['share_id'] === $share_id) {
                lendcity_log('Transistor Webhook: Episode already processed - ' . $episode_title);
                return new WP_REST_Response(array('message' => 'Already processed'), 200);
            }
        }

        lendcity_log('Transistor Webhook: Processing new episode - ' . $episode_title);

        // Process the episode using existing function
        $result = $this->process_episode_from_share_id($share_id, $category_name, $episode_title);

        if ($result['success']) {
            // Record as processed
            $processed[] = array(
                'share_id' => $share_id,
                'title' => $episode_title,
                'post_id' => $result['post_id'],
                'date' => current_time('mysql'),
                'show_id' => $show_id
            );
            update_option('lendcity_processed_podcast_episodes', $processed);

            lendcity_log('Transistor Webhook: Successfully created post #' . $result['post_id']);

            return new WP_REST_Response(array(
                'success' => true,
                'post_id' => $result['post_id'],
                'message' => 'Episode processed successfully'
            ), 200);
        } else {
            lendcity_log('Transistor Webhook: Failed - ' . ($result['error'] ?? 'Unknown error'));
            return new WP_REST_Response(array(
                'error' => $result['error'] ?? 'Processing failed'
            ), 500);
        }
    }

    /**
     * Get the webhook URL for display in admin
     */
    public function get_transistor_webhook_url() {
        $secret = get_option('lendcity_transistor_webhook_secret', '');
        if (empty($secret)) {
            $secret = wp_generate_password(32, false);
            update_option('lendcity_transistor_webhook_secret', $secret);
        }
        return rest_url('lendcity/v1/transistor-webhook') . '?key=' . $secret;
    }

    /**
     * AJAX: Regenerate webhook secret
     */
    public function ajax_regenerate_webhook_secret() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $new_secret = wp_generate_password(32, false);
        update_option('lendcity_transistor_webhook_secret', $new_secret);

        lendcity_log('Transistor webhook secret regenerated');

        wp_send_json_success(array(
            'message' => 'Webhook secret regenerated',
            'url' => rest_url('lendcity/v1/transistor-webhook') . '?key=' . $new_secret
        ));
    }

    /**
     * Process a single podcast episode into a blog post
     */
    private function process_podcast_episode($item, $xml, $category_name) {
        // Register podcast namespace
        $namespaces = $xml->getNamespaces(true);
        
        // Extract episode data
        $episode_title = (string)$item->title;
        $episode_link = (string)$item->link;
        $episode_description = '';
        
        // Get content:encoded or description
        if (isset($namespaces['content'])) {
            $content = $item->children($namespaces['content']);
            if (isset($content->encoded)) {
                $episode_description = (string)$content->encoded;
            }
        }
        if (empty($episode_description)) {
            $episode_description = (string)$item->description;
        }
        
        // Get transcript URL
        $transcript_url = '';
        $share_id = '';
        if (isset($namespaces['podcast'])) {
            $podcast_ns = $item->children($namespaces['podcast']);
            foreach ($podcast_ns->transcript as $transcript) {
                $attrs = $transcript->attributes();
                if (isset($attrs['type']) && (string)$attrs['type'] === 'text/plain') {
                    $transcript_url = (string)$attrs['url'];
                    // Extract share ID from URL like https://share.transistor.fm/s/1a255985/transcription.txt
                    if (preg_match('/share\.transistor\.fm\/s\/([a-z0-9]+)\//', $transcript_url, $id_matches)) {
                        $share_id = $id_matches[1];
                    }
                    break;
                }
            }
        }
        
        if (empty($transcript_url) || empty($share_id)) {
            return array('success' => false, 'error' => 'No transcript URL found');
        }
        
        // Store share_id for later use (in case variable gets clobbered)
        $embed_share_id = $share_id;
        
        // Log the share_id for debugging
        error_log("LendCity Podcast: Processing episode with share_id: {$embed_share_id}, transcript_url: {$transcript_url}");
        
        // Fetch transcript
        $transcript_response = wp_remote_get($transcript_url, array('timeout' => 30));
        if (is_wp_error($transcript_response)) {
            return array('success' => false, 'error' => 'Failed to fetch transcript');
        }
        $transcript = wp_remote_retrieve_body($transcript_response);
        
        if (empty($transcript) || strlen($transcript) < 500) {
            return array('success' => false, 'error' => 'Transcript too short or empty');
        }
        
        // If transcript is very long, summarize it first to capture full meaning
        $max_transcript_length = 15000;
        if (strlen($transcript) > $max_transcript_length) {
            $api = new LendCity_Claude_API();
            
            $summary_prompt = "You are summarizing a podcast transcript for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
            $summary_prompt .= "Create a detailed summary that captures ALL the key points, advice, strategies, and insights from this episode. ";
            $summary_prompt .= "Include specific numbers, tips, and actionable advice mentioned. ";
            $summary_prompt .= "The summary should be comprehensive enough that someone could write a full article from it without missing important content.\n\n";
            $summary_prompt .= "TRANSCRIPT:\n{$transcript}\n\n";
            $summary_prompt .= "Provide a detailed summary (aim for 2000-3000 words) covering all main topics discussed:";
            
            $summary_response = $api->generate_content($summary_prompt);
            
            if (!is_wp_error($summary_response) && !empty($summary_response)) {
                $transcript = $summary_response;
            }
        }
        
        // Get existing tags
        $existing_tags = get_tags(array('hide_empty' => false));
        $existing_tag_names = array();
        foreach ($existing_tags as $tag) {
            $existing_tag_names[] = $tag->name;
        }
        $existing_tags_list = !empty($existing_tag_names) ? implode(', ', $existing_tag_names) : 'None yet';
        
        // Send to Claude for processing
        $api = new LendCity_Claude_API();
        
        $prompt = "You are a friendly content writer for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
        $prompt .= "You have a podcast transcript below. Your job is to turn it into a blog article (~1000 words).\n\n";
        
        $prompt .= "WRITING STYLE (VERY IMPORTANT):\n";
        $prompt .= "- Write like a friendly expert explaining things to a regular person\n";
        $prompt .= "- Use simple, everyday words - avoid fancy or complex vocabulary\n";
        $prompt .= "- Keep sentences short and punchy\n";
        $prompt .= "- NO corporate buzzwords or jargon\n";
        $prompt .= "- NO phrases like 'navigate', 'leverage', 'utilize', 'optimize', 'streamline', 'robust', 'comprehensive'\n";
        $prompt .= "- NO filler phrases like 'It's important to note', 'In today's market', 'When it comes to'\n";
        $prompt .= "- Sound like a real person talking, not a robot or marketing brochure\n";
        $prompt .= "- Be direct and get to the point\n";
        $prompt .= "- Use 'you' and 'your' to speak directly to the reader\n\n";
        
        $prompt .= "TASKS:\n";
        $prompt .= "1. Create a NEW catchy SEO title (50-60 chars) - NOT the episode title, create something fresh. NEVER include years (2024, 2025, etc) in the title.\n";
        $prompt .= "2. Create a meta description (150-160 chars)\n";
        $prompt .= "3. Write a ~1000 word article based on the transcript - NOT a summary, but a standalone article\n";
        $prompt .= "4. Select exactly 8 tags\n";
        $prompt .= "5. Suggest image search words (2-4 words) for Unsplash\n";
        $prompt .= "6. Create 6-8 FAQ questions and answers\n\n";
        
        $prompt .= "ARTICLE RULES:\n";
        $prompt .= "- The article should be useful on its own, even without listening to the podcast\n";
        $prompt .= "- Cover the main points from the transcript\n";
        $prompt .= "- Use proper HTML: <h2>, <h3>, <p>, <ul>, <li> tags\n";
        $prompt .= "- NEVER include years (2024, 2025, etc) in titles or content - keep it evergreen\n";
        $prompt .= "- Don't mention 'this episode' or 'the podcast' in the article\n\n";
        
        $prompt .= "EXISTING TAGS (reuse when relevant): " . $existing_tags_list . "\n\n";
        
        $prompt .= "PODCAST EPISODE TITLE (for context only): {$episode_title}\n\n";
        $prompt .= "TRANSCRIPT:\n{$transcript}\n\n";
        
        $prompt .= "Return as JSON:\n";
        $prompt .= '{"title":"NEW SEO title","description":"meta desc","content":"<p>Article HTML</p>","tags":["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8"],"image_search":"search terms","faqs":[{"question":"Q1?","answer":"A1"},{"question":"Q2?","answer":"A2"}]}';
        
        $response = $api->generate_content($prompt, 8000);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        // Parse response
        $article_data = json_decode($response, true);
        if (!$article_data && preg_match('/\{.*\}/s', $response, $matches)) {
            $article_data = json_decode($matches[0], true);
        }
        
        if (!$article_data || empty($article_data['content'])) {
            return array('success' => false, 'error' => 'Failed to parse AI response');
        }
        
        // Get or create category - try by name first, then by slug
        $category = get_term_by('name', $category_name, 'category');
        if (!$category) {
            // Try by slug (convert name to slug format)
            $slug = sanitize_title($category_name);
            $category = get_term_by('slug', $slug, 'category');
        }
        if (!$category) {
            $new_cat = wp_insert_term($category_name, 'category');
            $category_id = is_wp_error($new_cat) ? 1 : $new_cat['term_id'];
        } else {
            $category_id = $category->term_id;
        }
        
        // Build Transistor.FM embed
        lendcity_debug_log("Building embed with share_id: {$embed_share_id}");
        $embed_block = '<!-- wp:html -->' . "\n";
        $embed_block .= '<iframe src="https://share.transistor.fm/e/' . esc_attr($embed_share_id) . '" width="100%" height="180" frameborder="0" scrolling="no" seamless="true"></iframe>' . "\n";
        $embed_block .= '<!-- /wp:html -->';
        
        // Build FAQ block
        $faq_block = '';
        if (!empty($article_data['faqs']) && is_array($article_data['faqs'])) {
            $faq_block = $this->build_seopress_faq_block($article_data['faqs']);
        }
        
        // Convert HTML content to Gutenberg blocks
        $article_blocks = $this->convert_html_to_blocks($article_data['content']);
        
        // Insert CTA button before second H2
        $article_blocks = $this->insert_cta_buttons($article_blocks);
        
        // The synced pattern reference for the "Book Strategy Call" button
        $cta_button = '<!-- wp:block {"ref":1470} /-->';
        
        // Build final content: Embed first, then blank line, then article, then FAQ
        $final_content = $embed_block . "\n\n";
        $final_content .= '<!-- wp:paragraph -->' . "\n" . '<p></p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n"; // Blank line spacer
        $final_content .= $article_blocks;
        
        // Add CTA button before FAQ, then FAQ at end
        if (!empty($faq_block)) {
            $faq_heading = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Frequently Asked Questions</h2>' . "\n" . '<!-- /wp:heading -->';
            $final_content .= "\n\n" . $cta_button . "\n\n" . $faq_heading . "\n\n" . $faq_block;
        }
        
        // Create post - PUBLISH IMMEDIATELY
        $post_data = array(
            'post_title' => $article_data['title'] ?? $episode_title,
            'post_content' => $final_content,
            'post_status' => 'publish', // Publish immediately!
            'post_category' => array($category_id),
            'post_author' => 1
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return array('success' => false, 'error' => $post_id->get_error_message());
        }
        
        lendcity_log("Podcast: Created post #{$post_id}");
        
        // Save SEO metadata
        if (!empty($article_data['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', $article_data['title']);
        }
        if (!empty($article_data['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', $article_data['description']);
        }
        if (!empty($article_data['tags'])) {
            wp_set_post_tags($post_id, $article_data['tags']);
        }
        
        // Fetch featured image from Unsplash
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (!empty($unsplash_key) && !empty($article_data['image_search'])) {
            $this->fetch_unsplash_featured_image(
                $post_id,
                $article_data['image_search'],
                $article_data['title'] ?? $episode_title,
                $unsplash_key
            );
        }
        
        // Run Smart Linker on the new post
        $auto_link = get_option('lendcity_smart_linker_auto', 'yes');
        if ($auto_link === 'yes') {
            // Schedule linking for 60 seconds later
            wp_schedule_single_event(time() + 60, 'lendcity_process_link_queue', array(array($post_id)));
        }
        
        return array('success' => true, 'post_id' => $post_id, 'title' => $article_data['title'] ?? $episode_title);
    }

    /**
     * AJAX: Scan posts for Transistor embeds
     */
    public function ajax_scan_transistor_embeds() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Step 1: Fetch ALL episodes from both RSS feeds
        $all_rss_episodes = array();
        
        // Podcast 1
        $podcast1_rss = get_option('lendcity_podcast1_rss', '');
        if (!empty($podcast1_rss)) {
            $episodes1 = $this->fetch_all_rss_episodes($podcast1_rss, 1);
            $all_rss_episodes = array_merge($all_rss_episodes, $episodes1);
        }
        
        // Podcast 2
        $podcast2_rss = get_option('lendcity_podcast2_rss', '');
        if (!empty($podcast2_rss)) {
            $episodes2 = $this->fetch_all_rss_episodes($podcast2_rss, 2);
            $all_rss_episodes = array_merge($all_rss_episodes, $episodes2);
        }
        
        if (empty($all_rss_episodes)) {
            wp_send_json_error('Could not fetch episodes from RSS feeds. Check your RSS URLs in settings.');
        }
        
        // Step 2: Get all share IDs that already have embeds in posts (these are PROCESSED)
        global $wpdb;
        $posts = $wpdb->get_results("
            SELECT ID, post_title, post_content 
            FROM {$wpdb->posts} 
            WHERE post_status IN ('publish', 'future', 'draft') 
            AND post_type = 'post'
            AND post_content LIKE '%transistor.fm%'
        ");
        
        $existing_share_ids = array();
        foreach ($posts as $post) {
            if (preg_match('/share\.transistor\.fm\/e\/([a-z0-9]+)/i', $post->post_content, $matches)) {
                $existing_share_ids[$matches[1]] = array(
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title
                );
            }
        }
        
        // Step 3: Compare RSS episodes to existing embeds
        $episodes = array();
        $unprocessed_count = 0;
        
        foreach ($all_rss_episodes as $ep) {
            $share_id = $ep['share_id'];
            $is_processed = isset($existing_share_ids[$share_id]);
            
            if (!$is_processed) {
                $unprocessed_count++;
            }
            
            $episodes[] = array(
                'share_id' => $share_id,
                'title' => $ep['title'],
                'podcast' => $ep['podcast'],
                'guid' => $ep['guid'],
                'processed' => $is_processed,
                'existing_post_id' => $is_processed ? $existing_share_ids[$share_id]['post_id'] : null,
                'existing_post_title' => $is_processed ? $existing_share_ids[$share_id]['post_title'] : null
            );
        }
        
        wp_send_json_success(array(
            'total' => count($episodes),
            'unprocessed_count' => $unprocessed_count,
            'episodes' => $episodes
        ));
    }
    
    /**
     * Fetch all episodes from an RSS feed
     */
    private function fetch_all_rss_episodes($rss_url, $podcast_num) {
        $episodes = array();
        
        $response = wp_remote_get($rss_url, array(
            'timeout' => 30,
            'headers' => array(
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache'
            )
        ));
        
        if (is_wp_error($response)) {
            return $episodes;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if ($xml === false) {
            return $episodes;
        }
        
        $namespaces = $xml->getNamespaces(true);
        
        foreach ($xml->channel->item as $item) {
            $title = (string)$item->title;
            $guid = (string)$item->guid;
            $share_id = '';
            
            // Try to get share ID from transcript URL
            if (isset($namespaces['podcast'])) {
                $podcast_ns = $item->children($namespaces['podcast']);
                foreach ($podcast_ns->transcript as $transcript) {
                    $attrs = $transcript->attributes();
                    if (isset($attrs['url'])) {
                        $transcript_url = (string)$attrs['url'];
                        if (preg_match('/share\.transistor\.fm\/s\/([a-z0-9]+)\//', $transcript_url, $matches)) {
                            $share_id = $matches[1];
                            break;
                        }
                    }
                }
            }
            
            // If no share ID from transcript, try to extract from enclosure or link
            if (empty($share_id)) {
                $link = (string)$item->link;
                if (preg_match('/transistor\.fm.*\/([a-z0-9]+)/', $link, $matches)) {
                    $share_id = $matches[1];
                }
            }
            
            if (!empty($share_id)) {
                $episodes[] = array(
                    'share_id' => $share_id,
                    'title' => $title,
                    'guid' => $guid,
                    'podcast' => $podcast_num
                );
            }
        }
        
        return $episodes;
    }
    
    /**
     * AJAX: Backfill podcast episodes - create articles for RSS episodes that don't have posts yet
     */
    public function ajax_backfill_podcast_episodes() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Increase time limit for long processing
        set_time_limit(600);
        
        // Step 1: Fetch ALL episodes from both RSS feeds
        $all_rss_episodes = array();
        
        $podcast1_rss = get_option('lendcity_podcast1_rss', '');
        $podcast1_category = get_option('lendcity_podcast1_category', 'Podcast');
        if (!empty($podcast1_rss)) {
            $episodes1 = $this->fetch_all_rss_episodes($podcast1_rss, 1);
            foreach ($episodes1 as &$ep) {
                $ep['category'] = $podcast1_category;
            }
            $all_rss_episodes = array_merge($all_rss_episodes, $episodes1);
        }
        
        $podcast2_rss = get_option('lendcity_podcast2_rss', '');
        $podcast2_category = get_option('lendcity_podcast2_category', 'Podcast');
        if (!empty($podcast2_rss)) {
            $episodes2 = $this->fetch_all_rss_episodes($podcast2_rss, 2);
            foreach ($episodes2 as &$ep) {
                $ep['category'] = $podcast2_category;
            }
            $all_rss_episodes = array_merge($all_rss_episodes, $episodes2);
        }
        
        if (empty($all_rss_episodes)) {
            wp_send_json_error('Could not fetch episodes from RSS feeds');
        }
        
        // Step 2: Get all share IDs that already have embeds in posts
        global $wpdb;
        $posts = $wpdb->get_results("
            SELECT ID, post_content 
            FROM {$wpdb->posts} 
            WHERE post_status IN ('publish', 'future', 'draft') 
            AND post_type = 'post'
            AND post_content LIKE '%transistor.fm%'
        ");
        
        $existing_share_ids = array();
        foreach ($posts as $post) {
            if (preg_match('/share\.transistor\.fm\/e\/([a-z0-9]+)/i', $post->post_content, $matches)) {
                $existing_share_ids[] = $matches[1];
            }
        }
        
        // Step 3: Process episodes that DON'T have embeds yet
        $processed = get_option('lendcity_processed_podcast_episodes', array());
        $processed_count = 0;
        $errors = array();
        
        foreach ($all_rss_episodes as $episode) {
            $share_id = $episode['share_id'];
            
            // Skip if this episode already has an embed in a post
            if (in_array($share_id, $existing_share_ids)) {
                continue;
            }
            
            // Process this episode - create new article
            $result = $this->process_episode_from_share_id($share_id, $episode['category'], $episode['title']);
            
            if ($result['success']) {
                // Record as processed
                $processed[] = array(
                    'share_id' => $share_id,
                    'guid' => $episode['guid'],
                    'title' => $result['title'],
                    'post_id' => $result['post_id'],
                    'date' => current_time('mysql'),
                    'podcast' => $episode['podcast']
                );
                $existing_share_ids[] = $share_id; // Don't process again in same run
                $processed_count++;
            } else {
                $errors[] = $episode['title'] . ': ' . ($result['error'] ?? 'Unknown error');
            }
        }
        
        // Save updated processed list
        update_option('lendcity_processed_podcast_episodes', $processed);
        
        $message = "Created {$processed_count} new article(s).";
        if (!empty($errors)) {
            $message .= ' Errors: ' . implode(', ', array_slice($errors, 0, 3));
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'processed' => $processed_count,
            'errors' => $errors
        ));
    }
    
    /**
     * Process episode from share ID (for backfill)
     */
    private function process_episode_from_share_id($share_id, $category_name, $source_title = '') {
        // Fetch transcript from Transistor
        $transcript_url = "https://share.transistor.fm/s/{$share_id}/transcription.txt";
        $transcript_response = wp_remote_get($transcript_url, array('timeout' => 30));
        
        if (is_wp_error($transcript_response)) {
            return array('success' => false, 'error' => 'Failed to fetch transcript: ' . $transcript_response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($transcript_response);
        if ($http_code !== 200) {
            return array('success' => false, 'error' => 'Transcript not found (HTTP ' . $http_code . ')');
        }
        
        $transcript = wp_remote_retrieve_body($transcript_response);
        
        if (strlen($transcript) < 500) {
            return array('success' => false, 'error' => 'Transcript too short (' . strlen($transcript) . ' chars)');
        }
        
        // If transcript is very long, summarize it first to capture full meaning
        $max_transcript_length = 15000;
        if (strlen($transcript) > $max_transcript_length) {
            $api = new LendCity_Claude_API();
            
            $summary_prompt = "You are summarizing a podcast transcript for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
            $summary_prompt .= "Create a detailed summary that captures ALL the key points, advice, strategies, and insights from this episode. ";
            $summary_prompt .= "Include specific numbers, tips, and actionable advice mentioned. ";
            $summary_prompt .= "The summary should be comprehensive enough that someone could write a full article from it without missing important content.\n\n";
            $summary_prompt .= "TRANSCRIPT:\n{$transcript}\n\n";
            $summary_prompt .= "Provide a detailed summary (aim for 2000-3000 words) covering all main topics discussed:";
            
            $summary_response = $api->generate_content($summary_prompt);
            
            if (is_wp_error($summary_response)) {
                return array('success' => false, 'error' => 'Failed to summarize transcript: ' . $summary_response->get_error_message());
            }
            
            if (!empty($summary_response)) {
                $transcript = $summary_response;
            }
        }
        
        // Build embed block
        $embed_block = '<!-- wp:html -->' . "\n";
        $embed_block .= '<iframe src="https://share.transistor.fm/e/' . esc_attr($share_id) . '" width="100%" height="180" frameborder="0" scrolling="no" seamless="true"></iframe>' . "\n";
        $embed_block .= '<!-- /wp:html -->';
        
        // Get existing tags
        $existing_tags = get_tags(array('hide_empty' => false));
        $existing_tag_names = array();
        foreach ($existing_tags as $tag) {
            $existing_tag_names[] = $tag->name;
        }
        $existing_tags_list = !empty($existing_tag_names) ? implode(', ', $existing_tag_names) : 'None yet';
        
        // Send to Claude for processing
        $api = new LendCity_Claude_API();
        
        $prompt = "You are a friendly content writer for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
        $prompt .= "Below is content from a podcast episode. Your job is to create a NEW, engaging blog article based on this content.\n\n";
        
        $prompt .= "WRITING STYLE (VERY IMPORTANT):\n";
        $prompt .= "- Write like a friendly expert explaining things to a regular person\n";
        $prompt .= "- Use simple, everyday words - avoid fancy or complex vocabulary\n";
        $prompt .= "- Keep sentences short and punchy\n";
        $prompt .= "- NO corporate buzzwords or jargon\n";
        $prompt .= "- NO phrases like 'navigate', 'leverage', 'utilize', 'optimize', 'streamline', 'robust', 'comprehensive'\n";
        $prompt .= "- NO filler phrases like 'It's important to note', 'In today's market', 'When it comes to'\n";
        $prompt .= "- Sound like a real person talking, not a robot or marketing brochure\n";
        $prompt .= "- Be direct and get to the point\n";
        $prompt .= "- Use 'you' and 'your' to speak directly to the reader\n\n";
        
        $prompt .= "TASKS:\n";
        $prompt .= "1. Create a NEW catchy SEO title (50-60 chars) - NOT the episode title, make it article-focused. NEVER include years (2024, 2025, etc) in the title.\n";
        $prompt .= "2. Create a meta description (150-160 chars)\n";
        $prompt .= "3. Write a ~1000 word article as HTML with headings (h2, h3), paragraphs, lists. NEVER include years - keep it evergreen.\n";
        $prompt .= "4. Select exactly 8 tags from the existing tags below\n";
        $prompt .= "5. Suggest image search words (2-4 words)\n";
        $prompt .= "6. Create 6-8 FAQ questions and answers\n\n";
        
        $prompt .= "EXISTING TAGS: " . $existing_tags_list . "\n\n";
        
        $prompt .= "PODCAST CONTENT:\n{$transcript}\n\n";
        $prompt .= "IMPORTANT: Return ONLY valid JSON, no other text. Format:\n";
        $prompt .= '{"title":"SEO article title","description":"meta description","content":"<p>Article HTML content</p>","tags":["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8"],"image_search":"search terms","faqs":[{"question":"Q1?","answer":"A1"},{"question":"Q2?","answer":"A2"}]}';
        
        $response = $api->generate_content($prompt);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => 'Claude API error: ' . $response->get_error_message());
        }
        
        if (empty($response)) {
            return array('success' => false, 'error' => 'Empty response from Claude');
        }
        
        // Parse JSON response - try to find JSON in the response
        preg_match('/\{[\s\S]*\}/', $response, $matches);
        if (empty($matches)) {
            // Log first 500 chars of response for debugging
            $preview = substr($response, 0, 500);
            return array('success' => false, 'error' => 'No JSON found in response. Preview: ' . $preview);
        }
        
        $article_data = json_decode($matches[0], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'error' => 'JSON parse error: ' . json_last_error_msg());
        }
        
        // Handle category - try by name first, then by slug
        $category_id = 1;
        $existing_cat = get_term_by('name', $category_name, 'category');
        if (!$existing_cat) {
            // Try by slug (convert name to slug format)
            $slug = sanitize_title($category_name);
            $existing_cat = get_term_by('slug', $slug, 'category');
        }
        if ($existing_cat) {
            $category_id = $existing_cat->term_id;
        } else {
            $new_cat = wp_insert_term($category_name, 'category');
            if (!is_wp_error($new_cat)) {
                $category_id = $new_cat['term_id'];
            }
        }
        
        // Build FAQ block
        $faq_block = '';
        if (!empty($article_data['faqs']) && is_array($article_data['faqs'])) {
            $faq_block = $this->build_seopress_faq_block($article_data['faqs']);
        }
        
        // Convert HTML content to Gutenberg blocks
        $article_blocks = $this->convert_html_to_blocks($article_data['content']);
        
        // Insert CTA button before second H2
        $article_blocks = $this->insert_cta_buttons($article_blocks);
        
        // The synced pattern reference for the "Book Strategy Call" button
        $cta_button = '<!-- wp:block {"ref":1470} /-->';
        
        // Build final content
        $final_content = $embed_block . "\n\n";
        $final_content .= '<!-- wp:paragraph -->' . "\n" . '<p></p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
        $final_content .= $article_blocks;
        
        // Add CTA button before FAQ
        if (!empty($faq_block)) {
            $faq_heading = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Frequently Asked Questions</h2>' . "\n" . '<!-- /wp:heading -->';
            $final_content .= "\n\n" . $cta_button . "\n\n" . $faq_heading . "\n\n" . $faq_block;
        }
        
        // Create post
        $post_data = array(
            'post_title' => $article_data['title'] ?? 'Podcast Episode',
            'post_content' => $final_content,
            'post_status' => 'publish',
            'post_category' => array($category_id),
            'post_author' => 1
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return array('success' => false, 'error' => $post_id->get_error_message());
        }
        
        // Save SEO metadata
        if (!empty($article_data['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', $article_data['title']);
        }
        if (!empty($article_data['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', $article_data['description']);
        }
        if (!empty($article_data['tags'])) {
            wp_set_post_tags($post_id, $article_data['tags']);
        }
        
        // Fetch featured image from Unsplash
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (!empty($unsplash_key) && !empty($article_data['image_search'])) {
            $this->fetch_unsplash_featured_image(
                $post_id,
                $article_data['image_search'],
                $article_data['title'] ?? 'Podcast Episode',
                $unsplash_key
            );
        }
        
        // Run Smart Linker
        $auto_link = get_option('lendcity_smart_linker_auto', 'yes');
        if ($auto_link === 'yes') {
            wp_schedule_single_event(time() + 60, 'lendcity_process_link_queue', array(array($post_id)));
        }
        
        return array('success' => true, 'post_id' => $post_id, 'title' => $article_data['title'] ?? 'Podcast Episode');
    }
    
    /**
     * AJAX: Get podcast debug log
     */
    public function ajax_get_podcast_debug_log() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Try to read WordPress debug.log
        $log_file = WP_CONTENT_DIR . '/debug.log';
        
        if (!file_exists($log_file)) {
            wp_send_json_success('Debug log not found. Enable WP_DEBUG_LOG in wp-config.php to see logs.');
        }
        
        // Read last 100 lines and filter for podcast-related entries
        $lines = file($log_file);
        $podcast_lines = array();
        
        foreach (array_slice($lines, -500) as $line) {
            if (stripos($line, 'LendCity Podcast') !== false || stripos($line, 'podcast') !== false) {
                $podcast_lines[] = trim($line);
            }
        }
        
        if (empty($podcast_lines)) {
            wp_send_json_success("No podcast-related log entries found in last 500 lines.\n\nMake sure WP_DEBUG and WP_DEBUG_LOG are enabled in wp-config.php.");
        }
        
        // Return last 50 podcast-related lines
        $output = implode("\n", array_slice($podcast_lines, -50));
        wp_send_json_success($output);
    }
    
    /**
     * Generate metadata using link keywords, manual keywords, or content analysis
     */
    public function ajax_generate_metadata_from_links() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        $manual_keywords = sanitize_text_field($_POST['manual_keywords'] ?? '');
        $post = get_post($post_id);
        
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        // Get BEFORE values
        $before_title = get_post_meta($post_id, '_seopress_titles_title', true) ?: '';
        $before_desc = get_post_meta($post_id, '_seopress_titles_desc', true) ?: '';
        $before_tags = wp_get_post_tags($post_id, array('fields' => 'names'));
        $before_tags_str = !empty($before_tags) ? implode(', ', $before_tags) : '';
        
        $keyword_source = '';
        $all_keywords = array();
        
        // Priority 1: Manual keywords if provided
        if (!empty($manual_keywords)) {
            $all_keywords = array_map('trim', explode(',', $manual_keywords));
            $keyword_source = 'Manual keywords';
        } else {
            // Priority 2: Link keywords
            $smart_linker = new LendCity_Smart_Linker();
            
            // Get outgoing links FROM this post/page
            $outgoing_links = get_post_meta($post_id, '_lendcity_smart_links', true) ?: array();
            $outgoing_anchors = array();
            foreach ($outgoing_links as $link) {
                if (!empty($link['anchor'])) {
                    $outgoing_anchors[] = $link['anchor'];
                }
            }
            
            // Get incoming links TO this post/page
            $all_links = $smart_linker->get_all_site_links(2000);
            $post_url = get_permalink($post_id);
            $incoming_anchors = array();
            foreach ($all_links as $link) {
                if ($link['url'] === $post_url && !empty($link['anchor'])) {
                    $incoming_anchors[] = $link['anchor'];
                }
            }
            
            // Get priority page keywords if this is a page
            $target_keywords = '';
            if ($post->post_type === 'page') {
                $target_keywords = get_post_meta($post_id, '_lendcity_target_keywords', true);
            }
            
            // Combine all keyword signals
            $all_keywords = array_merge($outgoing_anchors, $incoming_anchors);
            if (!empty($target_keywords)) {
                $all_keywords = array_merge($all_keywords, array_map('trim', explode(',', $target_keywords)));
            }
            
            if (!empty($all_keywords)) {
                $keyword_source = 'Link anchors (' . count($outgoing_anchors) . ' outgoing, ' . count($incoming_anchors) . ' incoming)';
            }
        }
        
        $all_keywords = array_unique($all_keywords);
        
        // Get content preview
        $content = wp_strip_all_tags($post->post_content);
        $content = substr($content, 0, 2000);
        
        // Get existing tags on the site for reference
        $existing_tags = get_tags(array('hide_empty' => false, 'number' => 100));
        $existing_tag_names = array();
        foreach ($existing_tags as $tag) {
            $existing_tag_names[] = $tag->name;
        }
        
        $api = new LendCity_Claude_API();
        
        $type_label = $post->post_type === 'page' ? 'PAGE' : 'ARTICLE';
        $prompt = "Generate SEO metadata for this mortgage/real estate {$type_label}.\n\n";
        $prompt .= "{$type_label} TITLE: " . $post->post_title . "\n";
        $prompt .= "CONTENT PREVIEW: " . $content . "\n\n";
        
        if (!empty($all_keywords)) {
            $prompt .= "KEYWORD SIGNALS (use these to inform your metadata):\n";
            $prompt .= implode(', ', $all_keywords) . "\n\n";
        } else {
            $keyword_source = 'Content analysis (no links or manual keywords)';
            $prompt .= "NOTE: No keyword signals provided - analyze the content to determine best keywords.\n\n";
        }
        
        $prompt .= "EXISTING SITE TAGS (prefer these when relevant): " . implode(', ', array_slice($existing_tag_names, 0, 50)) . "\n\n";
        $prompt .= "INSTRUCTIONS:\n";
        $prompt .= "1. Create SEO title (50-60 chars) - incorporate key terms from the keywords if provided\n";
        $prompt .= "2. Create meta description (150-160 chars) - naturally include the keyword signals\n";
        $prompt .= "3. Select 8 tags - prioritize existing tags, use keywords for new ones if needed\n";
        $prompt .= "4. Keep everything EVERGREEN - no dates or time references\n\n";
        $prompt .= "Return ONLY JSON: {\"title\":\"...\",\"description\":\"...\",\"tags\":[\"tag1\",\"tag2\",...]}\n";
        
        $response = $api->simple_completion($prompt, 500);
        
        if (!$response) {
            wp_send_json_error('API request failed');
        }
        
        $result = json_decode($response, true);
        if (!$result && preg_match('/\{.*\}/s', $response, $matches)) {
            $result = json_decode($matches[0], true);
        }
        
        if (!$result || !isset($result['title'])) {
            wp_send_json_error('Invalid API response: ' . substr($response, 0, 200));
        }
        
        // Auto-save the metadata
        if (!empty($result['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($result['title']));
        }
        if (!empty($result['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($result['description']));
        }
        if (!empty($result['tags']) && is_array($result['tags'])) {
            wp_set_post_tags($post_id, $result['tags'], false);
        }
        
        wp_send_json_success(array(
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'keyword_source' => $keyword_source,
            'before' => array(
                'title' => $before_title,
                'description' => $before_desc,
                'tags' => $before_tags_str
            ),
            'after' => array(
                'title' => $result['title'] ?? '',
                'description' => $result['description'] ?? '',
                'tags' => is_array($result['tags']) ? implode(', ', $result['tags']) : ''
            )
        ));
    }

    // ==================== SMART METADATA v2 AJAX ====================
    // These handlers run AFTER linking phase for optimal SEO metadata

    /**
     * Generate smart metadata for a single post using catalog + link data
     */
    public function ajax_generate_smart_metadata() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Post not found');
        }

        // Get BEFORE values
        $before_title = get_post_meta($post_id, '_seopress_titles_title', true) ?: '';
        $before_desc = get_post_meta($post_id, '_seopress_titles_desc', true) ?: '';
        $before_tags = wp_get_post_tags($post_id, array('fields' => 'names'));
        $before_tags_str = !empty($before_tags) ? implode(', ', $before_tags) : '';
        $before_keyphrase = get_post_meta($post_id, '_seopress_analysis_target_kw', true) ?: '';

        // Generate smart metadata using the new method
        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->generate_smart_metadata($post_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Save the metadata
        if (!empty($result['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($result['title']));
        }
        if (!empty($result['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($result['description']));
        }
        if (!empty($result['tags']) && is_array($result['tags'])) {
            wp_set_post_tags($post_id, $result['tags'], false);
        }
        if (!empty($result['focus_keyphrase'])) {
            update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($result['focus_keyphrase']));
        }

        wp_send_json_success(array(
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'data_sources' => array(
                'catalog_used' => $result['catalog_used'],
                'inbound_anchors' => $result['inbound_anchors_count'],
                'outbound_anchors' => $result['outbound_anchors_count']
            ),
            'reasoning' => $result['reasoning'] ?? '',
            'before' => array(
                'title' => $before_title,
                'description' => $before_desc,
                'tags' => $before_tags_str,
                'focus_keyphrase' => $before_keyphrase
            ),
            'after' => array(
                'title' => $result['title'] ?? '',
                'description' => $result['description'] ?? '',
                'tags' => is_array($result['tags']) ? implode(', ', $result['tags']) : '',
                'focus_keyphrase' => $result['focus_keyphrase'] ?? ''
            )
        ));
    }

    /**
     * Get list of posts that need smart metadata generation
     */
    public function ajax_get_smart_metadata_posts() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $only_linked = isset($_POST['only_linked']) ? (bool) $_POST['only_linked'] : true;
        $skip_existing = isset($_POST['skip_existing']) ? ($_POST['skip_existing'] === 'true' || $_POST['skip_existing'] === '1') : true;

        $smart_linker = new LendCity_Smart_Linker();
        $post_ids = $smart_linker->get_posts_for_smart_metadata($only_linked);

        // Get post details and optionally filter by existing meta
        $posts = array();
        $skipped = 0;
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;

            // Skip posts with existing SEO meta if requested
            if ($skip_existing) {
                $existing_title = get_post_meta($post_id, '_seopress_titles_title', true);
                $existing_desc = get_post_meta($post_id, '_seopress_titles_desc', true);
                if (!empty($existing_title) && !empty($existing_desc)) {
                    $skipped++;
                    continue;
                }
            }

            $posts[] = array(
                'id' => $post_id,
                'title' => $post->post_title,
                'type' => $post->post_type
            );
        }

        wp_send_json_success(array(
            'posts' => $posts,
            'total' => count($posts),
            'skipped' => $skipped
        ));
    }

    /**
     * Process a batch of posts for smart metadata (for bulk processing)
     */
    public function ajax_bulk_smart_metadata() {
        @set_time_limit(120);
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();

        if (empty($post_ids)) {
            wp_send_json_error('No post IDs provided');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $results = array();
        $success_count = 0;
        $error_count = 0;

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $results[] = array('id' => $post_id, 'status' => 'error', 'message' => 'Post not found');
                $error_count++;
                continue;
            }

            $result = $smart_linker->generate_smart_metadata($post_id);

            if (is_wp_error($result)) {
                $results[] = array('id' => $post_id, 'title' => $post->post_title, 'status' => 'error', 'message' => $result->get_error_message());
                $error_count++;
                continue;
            }

            // Save the metadata
            if (!empty($result['title'])) {
                update_post_meta($post_id, '_seopress_titles_title', sanitize_text_field($result['title']));
            }
            if (!empty($result['description'])) {
                update_post_meta($post_id, '_seopress_titles_desc', sanitize_text_field($result['description']));
            }
            if (!empty($result['tags']) && is_array($result['tags'])) {
                wp_set_post_tags($post_id, $result['tags'], false);
            }
            if (!empty($result['focus_keyphrase'])) {
                update_post_meta($post_id, '_seopress_analysis_target_kw', sanitize_text_field($result['focus_keyphrase']));
            }

            $results[] = array(
                'id' => $post_id,
                'title' => $post->post_title,
                'status' => 'success',
                'data' => array(
                    'seo_title' => $result['title'],
                    'focus_keyphrase' => $result['focus_keyphrase']
                )
            );
            $success_count++;

            // Brief pause between API calls
            usleep(500000); // 0.5 second
        }

        wp_send_json_success(array(
            'results' => $results,
            'success' => $success_count,
            'errors' => $error_count
        ));
    }

    // ==================== META QUEUE AJAX (Persistent Background) ====================

    /**
     * Initialize metadata queue for background processing
     */
    public function ajax_init_meta_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $skip_existing = isset($_POST['skip_existing']) && $_POST['skip_existing'] === 'true';
        $only_linked = isset($_POST['only_linked']) && $_POST['only_linked'] === 'true';

        $smart_linker = new LendCity_Smart_Linker();

        // Get posts to process
        $post_ids = $smart_linker->get_posts_for_smart_metadata($only_linked);

        if (empty($post_ids)) {
            wp_send_json_error('No posts found to process');
        }

        $result = $smart_linker->init_meta_queue($post_ids, $skip_existing);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Get current metadata queue status
     */
    public function ajax_get_meta_queue_status() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $status = $smart_linker->get_meta_queue_status();

        wp_send_json_success($status);
    }

    /**
     * Clear metadata queue
     */
    public function ajax_clear_meta_queue() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->clear_meta_queue();

        wp_send_json_success($result);
    }

    // ==================== SEO HEALTH MONITOR AJAX ====================

    /**
     * Scan SEO health issues (paginated)
     */
    public function ajax_get_seo_health_issues() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $reset = isset($_POST['reset']) && $_POST['reset'] === 'true';

        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->scan_seo_health_batch($reset);

        wp_send_json_success($result);
    }

    /**
     * Auto-fix SEO for a specific post
     */
    public function ajax_auto_fix_seo() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->auto_fix_seo($post_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    // ==================== KEYWORD OWNERSHIP AJAX ====================

    /**
     * Build the keyword ownership map
     */
    public function ajax_build_keyword_ownership() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $force = isset($_POST['force']) && $_POST['force'] === 'true';

        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->build_keyword_ownership_map($force);

        wp_send_json_success(array(
            'stats' => $result['stats'] ?? array(),
            'built_at' => $result['built_at'] ?? null,
            'total_keywords' => count($result['map'] ?? array())
        ));
    }

    /**
     * Get keyword ownership stats
     */
    public function ajax_get_keyword_ownership_stats() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $stats = $smart_linker->get_keyword_ownership_stats();

        wp_send_json_success($stats);
    }

    /**
     * Get paginated keyword ownership list
     */
    public function ajax_get_keyword_ownership_list() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 50);
        $search = sanitize_text_field($_POST['search'] ?? '');

        $smart_linker = new LendCity_Smart_Linker();
        $result = $smart_linker->get_keyword_ownership_paginated($page, $per_page, $search);

        wp_send_json_success($result);
    }

    /**
     * Clear keyword ownership map
     */
    public function ajax_clear_keyword_ownership() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $smart_linker = new LendCity_Smart_Linker();
        $smart_linker->clear_keyword_ownership_map();

        wp_send_json_success(array('message' => 'Keyword ownership map cleared'));
    }

    // ==================== ARTICLE SCHEDULER AJAX ====================

    public function ajax_process_article() {
        // Extend PHP execution time for long API calls
        @set_time_limit(300);
        
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $file_name = sanitize_file_name($_POST['file_name']);
        $publish_date = sanitize_text_field($_POST['publish_date'] ?? '');
        
        $queue_dir = wp_upload_dir()['basedir'] . '/lendcity-article-queue';
        $file_path = $queue_dir . '/' . $file_name;
        
        if (!file_exists($file_path)) {
            wp_send_json_error('File not found');
        }
        
        // Read DOCX content
        require_once LENDCITY_CLAUDE_PLUGIN_DIR . 'includes/docx-reader.php';
        $docx_text = lendcity_extract_docx_text($file_path);
        
        if (empty($docx_text)) {
            wp_send_json_error('Could not read file content');
        }
        
        // Get all existing tags from the site
        $existing_tags = get_tags(array('hide_empty' => false));
        $existing_tag_names = array();
        foreach ($existing_tags as $tag) {
            $existing_tag_names[] = $tag->name;
        }
        $existing_tags_list = !empty($existing_tag_names) ? implode(', ', $existing_tag_names) : 'None yet';
        
        // Get all existing categories from the site
        $existing_categories = get_categories(array('hide_empty' => false));
        $existing_cat_names = array();
        foreach ($existing_categories as $cat) {
            $existing_cat_names[] = $cat->name;
        }
        $existing_cats_list = implode(', ', $existing_cat_names);
        
        // Send to Claude for processing
        $api = new LendCity_Claude_API();
        
        $prompt = "You are a friendly content writer for LendCity Mortgages (Canadian mortgage brokerage for investment properties).\n\n";
        $prompt .= "CRITICAL: COMPLETELY REWRITE the content below. Do NOT copy it word-for-word.\n\n";
        
        $prompt .= "WRITING STYLE (VERY IMPORTANT):\n";
        $prompt .= "- Write like a friendly expert explaining things to a regular person\n";
        $prompt .= "- Use simple, everyday words - avoid fancy or complex vocabulary\n";
        $prompt .= "- Keep sentences short and punchy\n";
        $prompt .= "- NO corporate buzzwords or jargon\n";
        $prompt .= "- NO phrases like 'navigate', 'leverage', 'utilize', 'optimize', 'streamline', 'robust', 'comprehensive'\n";
        $prompt .= "- NO filler phrases like 'It's important to note', 'In today's market', 'When it comes to'\n";
        $prompt .= "- Sound like a real person talking, not a robot or marketing brochure\n";
        $prompt .= "- Be direct and get to the point\n";
        $prompt .= "- Use 'you' and 'your' to speak directly to the reader\n\n";
        
        $prompt .= "TASKS:\n";
        $prompt .= "1. Create a NEW catchy title (50-60 chars) - different from the original, simple words\n";
        $prompt .= "2. Create a meta description (150-160 chars)\n";
        $prompt .= "3. REWRITE content as HTML with headings (h2, h3), paragraphs, lists\n";
        $prompt .= "4. Select exactly 8 tags\n";
        $prompt .= "5. Select the BEST category\n";
        $prompt .= "6. Suggest image search words (2-4 words)\n";
        $prompt .= "7. Create 6-8 FAQ questions and answers\n\n";
        
        $prompt .= "REWRITING RULES:\n";
        $prompt .= "- 100% original - no copied sentences\n";
        $prompt .= "- Keep the same information but say it differently\n";
        $prompt .= "- Remove any dates or years - keep it evergreen\n";
        $prompt .= "- If something is confusing, explain it simply\n\n";
        
        $prompt .= "EXISTING CATEGORIES: " . $existing_cats_list . "\n";
        $prompt .= "EXISTING TAGS: " . $existing_tags_list . "\n\n";
        
        $prompt .= "FAQ: Create 6-8 Q&As. Keep answers short (2-3 sentences). Put in faqs array, NOT in content.\n\n";
        
        $prompt .= "SOURCE MATERIAL TO REWRITE:\n{$docx_text}\n\n";
        $prompt .= "Return as JSON:\n";
        $prompt .= '{"title":"NEW original title","description":"...","content":"<p>Completely rewritten HTML content</p>","category":"Category Name","tags":["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8"],"image_search":"search terms","faqs":[{"question":"Q1?","answer":"A1"},{"question":"Q2?","answer":"A2"}]}';
        
        $response = $api->generate_content($prompt);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        // Parse JSON response
        preg_match('/\{[\s\S]*\}/', $response, $matches);
        if (empty($matches)) {
            wp_send_json_error('Could not parse response');
        }
        
        $article_data = json_decode($matches[0], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON response');
        }
        
        // Handle category - find existing or create new
        $category_id = 1; // Default to Uncategorized
        if (!empty($article_data['category'])) {
            $cat_name = sanitize_text_field($article_data['category']);
            
            // Check if category exists
            $existing_cat = get_term_by('name', $cat_name, 'category');
            if ($existing_cat) {
                $category_id = $existing_cat->term_id;
            } else {
                // Create new category
                $new_cat = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($new_cat)) {
                    $category_id = $new_cat['term_id'];
                }
            }
        }
        
        // Build SEOPress FAQ block if FAQs provided
        $faq_block = '';
        if (!empty($article_data['faqs']) && is_array($article_data['faqs'])) {
            $faq_block = $this->build_seopress_faq_block($article_data['faqs']);
        }
        
        // Convert HTML content to Gutenberg blocks
        $block_content = $this->convert_html_to_blocks($article_data['content'] ?? $docx_text);
        
        // Insert CTA button before second H2
        $block_content = $this->insert_cta_buttons($block_content);
        
        // The synced pattern reference for the "Book Strategy Call" button
        $cta_button = '<!-- wp:block {"ref":1470} /-->';
        
        // Add CTA button before FAQ, then FAQ heading and block at the end
        if (!empty($faq_block)) {
            $faq_heading = '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Frequently Asked Questions</h2>' . "\n" . '<!-- /wp:heading -->';
            $block_content .= "\n\n" . $cta_button . "\n\n" . $faq_heading . "\n\n" . $faq_block;
        }
        
        // Create the post
        $post_data = array(
            'post_title' => $article_data['title'] ?? basename($file_name, '.docx'),
            'post_content' => $block_content,
            'post_status' => $publish_date ? 'future' : 'draft',
            'post_date' => $publish_date ?: current_time('mysql'),
            'post_type' => 'post',
            'post_category' => array($category_id)
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            wp_send_json_error($post_id->get_error_message());
        }
        
        // Save metadata
        if (!empty($article_data['title'])) {
            update_post_meta($post_id, '_seopress_titles_title', $article_data['title']);
        }
        if (!empty($article_data['description'])) {
            update_post_meta($post_id, '_seopress_titles_desc', $article_data['description']);
        }
        if (!empty($article_data['tags'])) {
            wp_set_post_tags($post_id, $article_data['tags']);
        }
        
        // Fetch and set featured image from Unsplash
        $featured_image_result = null;
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (!empty($unsplash_key) && !empty($article_data['image_search'])) {
            $featured_image_result = $this->fetch_unsplash_featured_image(
                $post_id,
                $article_data['image_search'],
                $article_data['title'] ?? basename($file_name, '.docx'),
                $unsplash_key
            );
        }
        
        // Delete the source file
        unlink($file_path);
        
        wp_send_json_success(array(
            'post_id' => $post_id,
            'edit_link' => get_edit_post_link($post_id, 'raw'),
            'featured_image' => $featured_image_result
        ));
    }
    
    /**
     * Build a proper SEOPress FAQ (legacy) block from FAQ data
     * Uses the exact format: <!-- wp:wpseopress/faq-block {...} /-->
     */
    private function build_seopress_faq_block($faqs) {
        if (empty($faqs) || !is_array($faqs)) {
            return '';
        }
        
        // Build FAQs array for the block attributes
        // Clean and escape content to prevent JSON/block parsing issues
        $faqs_attr = array();
        foreach ($faqs as $faq) {
            if (!empty($faq['question']) && !empty($faq['answer'])) {
                // Strip HTML and clean up the text
                $question = wp_strip_all_tags($faq['question']);
                $answer = wp_strip_all_tags($faq['answer']);
                
                // Remove any characters that could break JSON or block parsing
                $question = str_replace(array("\r", "\n", "\t"), ' ', $question);
                $answer = str_replace(array("\r", "\n", "\t"), ' ', $answer);
                
                // Normalize whitespace
                $question = preg_replace('/\s+/', ' ', trim($question));
                $answer = preg_replace('/\s+/', ' ', trim($answer));
                
                $faqs_attr[] = array(
                    'question' => $question,
                    'answer' => $answer,
                    'image' => ''
                );
            }
        }
        
        if (empty($faqs_attr)) {
            return '';
        }
        
        // Create the block attributes matching SEOPress FAQ (legacy) format
        $block_attrs = array(
            'faqs' => $faqs_attr,
            'titleWrapper' => 'h6',
            'showFAQScheme' => true,
            'showAccordion' => true
        );
        
        // Create the self-closing WordPress block comment format
        // Format: <!-- wp:wpseopress/faq-block {"faqs":[...],...} /-->
        $json = wp_json_encode($block_attrs);
        
        if ($json === false) {
            // JSON encoding failed, return empty
            error_log('LendCity FAQ block: JSON encoding failed');
            return '';
        }
        
        $block = '<!-- wp:wpseopress/faq-block ' . $json . ' /-->';
        
        return $block;
    }
    
    /**
     * Convert HTML content to Gutenberg blocks
     * Wraps paragraphs, headings, and lists in proper block format
     */
    private function convert_html_to_blocks($html) {
        if (empty($html)) {
            return '';
        }
        
        $blocks = '';
        
        // Split content by major block elements
        // First, normalize the HTML
        $html = trim($html);
        
        // Convert headings to blocks
        $html = preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/is', function($matches) {
            $content = strip_tags($matches[2]);
            return '<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">' . $content . '</h2>' . "\n" . '<!-- /wp:heading -->' . "\n\n";
        }, $html);
        
        $html = preg_replace_callback('/<h3([^>]*)>(.*?)<\/h3>/is', function($matches) {
            $content = strip_tags($matches[2]);
            return '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading">' . $content . '</h3>' . "\n" . '<!-- /wp:heading -->' . "\n\n";
        }, $html);
        
        $html = preg_replace_callback('/<h4([^>]*)>(.*?)<\/h4>/is', function($matches) {
            $content = strip_tags($matches[2]);
            return '<!-- wp:heading {"level":4} -->' . "\n" . '<h4 class="wp-block-heading">' . $content . '</h4>' . "\n" . '<!-- /wp:heading -->' . "\n\n";
        }, $html);
        
        // Convert unordered lists to blocks
        $html = preg_replace_callback('/<ul([^>]*)>(.*?)<\/ul>/is', function($matches) {
            $list_content = $matches[2];
            // Clean up list items
            $list_content = preg_replace('/<li([^>]*)>/i', '<li>', $list_content);
            return '<!-- wp:list -->' . "\n" . '<ul class="wp-block-list">' . $list_content . '</ul>' . "\n" . '<!-- /wp:list -->' . "\n\n";
        }, $html);
        
        // Convert ordered lists to blocks
        $html = preg_replace_callback('/<ol([^>]*)>(.*?)<\/ol>/is', function($matches) {
            $list_content = $matches[2];
            $list_content = preg_replace('/<li([^>]*)>/i', '<li>', $list_content);
            return '<!-- wp:list {"ordered":true} -->' . "\n" . '<ol class="wp-block-list">' . $list_content . '</ol>' . "\n" . '<!-- /wp:list -->' . "\n\n";
        }, $html);
        
        // Convert paragraphs to blocks
        $html = preg_replace_callback('/<p([^>]*)>(.*?)<\/p>/is', function($matches) {
            $content = $matches[2];
            // Keep basic inline formatting (strong, em, a)
            $content = strip_tags($content, '<strong><b><em><i><a>');
            if (empty(trim($content))) {
                return '';
            }
            return '<!-- wp:paragraph -->' . "\n" . '<p>' . $content . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
        }, $html);
        
        // Clean up any remaining loose text and wrap in paragraphs
        // Split by double newlines for any remaining content
        $parts = preg_split('/\n\n+/', $html);
        $result = '';
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            
            // If it already has block comments, keep as is
            if (strpos($part, '<!-- wp:') !== false) {
                $result .= $part . "\n\n";
            } else if (!empty($part) && !preg_match('/^</', $part)) {
                // Plain text - wrap in paragraph block
                $result .= '<!-- wp:paragraph -->' . "\n" . '<p>' . $part . '</p>' . "\n" . '<!-- /wp:paragraph -->' . "\n\n";
            } else {
                $result .= $part . "\n\n";
            }
        }
        
        return trim($result);
    }
    
    /**
     * Insert CTA button pattern into content
     * Places button before the 2nd H2 heading
     */
    private function insert_cta_buttons($content) {
        // The synced pattern reference for the "Book Strategy Call" button
        $cta_button = '<!-- wp:block {"ref":1470} /-->';
        
        // Find all H2 heading blocks
        $pattern = '/<!-- wp:heading -->\s*<h2 class="wp-block-heading">/';
        
        // Find position of second H2
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        
        // If we have at least 2 H2s, insert before the second one
        if (isset($matches[0][1])) {
            $second_h2_pos = $matches[0][1][1];
            $content = substr($content, 0, $second_h2_pos) . $cta_button . "\n\n" . substr($content, $second_h2_pos);
        } else if (isset($matches[0][0])) {
            // Only 1 H2, insert after the first paragraph instead
            $first_para_end = strpos($content, '<!-- /wp:paragraph -->');
            if ($first_para_end !== false) {
                $insert_pos = $first_para_end + strlen('<!-- /wp:paragraph -->');
                $content = substr($content, 0, $insert_pos) . "\n\n" . $cta_button . substr($content, $insert_pos);
            }
        }
        
        return $content;
    }
    
    /**
     * Fetch image from Unsplash, compress with TinyPNG, and set as featured image
     * Tracks used images to prevent duplicates
     */
    private function fetch_unsplash_featured_image($post_id, $search_query, $seo_title, $api_key) {
        // Get list of already used Unsplash photo IDs
        $used_photos = get_option('lendcity_used_unsplash_photos', array());
        
        // Search Unsplash - get more results to find unused ones
        $search_url = 'https://api.unsplash.com/search/photos?' . http_build_query(array(
            'query' => $search_query,
            'per_page' => 15, // Get 15 results to find an unused one
            'orientation' => 'landscape'
        ));
        
        $response = wp_remote_get($search_url, array(
            'headers' => array(
                'Authorization' => 'Client-ID ' . $api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('LendCity Unsplash: Search failed - ' . $response->get_error_message());
            return array('success' => false, 'error' => 'Search failed');
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['results'])) {
            error_log('LendCity Unsplash: No results for "' . $search_query . '"');
            return array('success' => false, 'error' => 'No images found');
        }
        
        // Find first unused photo
        $photo = null;
        foreach ($body['results'] as $result) {
            $photo_id = $result['id'];
            if (!in_array($photo_id, $used_photos)) {
                $photo = $result;
                break;
            }
        }
        
        // If all photos are used, just use the first one (better than failing)
        if (!$photo) {
            error_log('LendCity Unsplash: All top results already used for "' . $search_query . '", using first result');
            $photo = $body['results'][0];
        }
        
        $photo_id = $photo['id'];
        // Use 'full' for highest quality, or custom width parameter
        $image_url = $photo['urls']['raw'] . '&w=1920&h=1080&fit=crop&crop=entropy'; // Crop to exact 1920x1080
        $photographer = $photo['user']['name'] ?? 'Unknown';
        $unsplash_link = $photo['links']['html'] ?? '';
        
        // Track this photo as used
        $used_photos[] = $photo_id;
        update_option('lendcity_used_unsplash_photos', $used_photos);
        
        // Download the image
        $image_response = wp_remote_get($image_url, array('timeout' => 60));
        if (is_wp_error($image_response)) {
            error_log('LendCity Unsplash: Download failed');
            return array('success' => false, 'error' => 'Download failed');
        }
        
        $image_data = wp_remote_retrieve_body($image_response);
        
        if (empty($image_data) || strlen($image_data) < 1000) {
            error_log('LendCity Unsplash: Downloaded image too small or empty - ' . strlen($image_data) . ' bytes');
            return array('success' => false, 'error' => 'Downloaded image is empty or corrupted');
        }
        
        error_log('LendCity Unsplash: Downloaded ' . strlen($image_data) . ' bytes');
        
        // Create filename from SEO title
        $safe_filename = sanitize_title($seo_title);
        $safe_filename = substr($safe_filename, 0, 50); // Limit length
        
        if (empty($safe_filename)) {
            $safe_filename = 'unsplash-' . $photo_id;
        }
        
        // Get upload directory
        $upload_dir = wp_upload_dir();
        
        if (!empty($upload_dir['error'])) {
            error_log('LendCity Unsplash: Upload dir error - ' . $upload_dir['error']);
            return array('success' => false, 'error' => 'Upload directory error: ' . $upload_dir['error']);
        }
        
        $temp_file = $upload_dir['path'] . '/' . $safe_filename . '-original.jpg';
        
        // Save temp file
        $saved = file_put_contents($temp_file, $image_data);
        
        if ($saved === false) {
            error_log('LendCity Unsplash: Failed to save temp file to ' . $temp_file);
            return array('success' => false, 'error' => 'Failed to save temp file');
        }
        
        error_log('LendCity Unsplash: Saved temp file ' . $temp_file . ' (' . $saved . ' bytes)');
        
        // Verify/enforce 1920x1080 dimensions using GD before compression
        $this->ensure_image_dimensions($temp_file, 1920, 1080);
        
        // Try TinyPNG compression first
        $tinypng_result = $this->compress_with_tinypng($temp_file);
        
        if ($tinypng_result['success']) {
            // TinyPNG worked! Use the compressed file
            $file_path = $tinypng_result['path'];
            $file_type = ($tinypng_result['format'] === 'webp') ? 'image/webp' : 'image/jpeg';
            
            // Clean up original temp file
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            error_log('LendCity: TinyPNG compressed image - saved ' . $tinypng_result['savings'] . '%');
        } else {
            // Fall back to GD conversion
            error_log('LendCity: TinyPNG failed, using GD fallback - ' . ($tinypng_result['error'] ?? 'unknown'));
            
            $webp_file = $upload_dir['path'] . '/' . $safe_filename . '.webp';
            $converted = false;
            
            if (function_exists('imagecreatefromjpeg') && function_exists('imagewebp')) {
                $image = @imagecreatefromjpeg($temp_file);
                if (!$image) {
                    $image = @imagecreatefrompng($temp_file);
                }
                
                if ($image) {
                    $orig_width = imagesx($image);
                    $orig_height = imagesy($image);
                    
                    // Resize to max 1920 width
                    $max_width = 1920;
                    if ($orig_width > $max_width) {
                        $new_width = $max_width;
                        $new_height = intval($orig_height * ($max_width / $orig_width));
                        
                        $resized = imagecreatetruecolor($new_width, $new_height);
                        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
                        imagedestroy($image);
                        $image = $resized;
                    }
                    
                    // Save as WebP with 85% quality
                    imagewebp($image, $webp_file, 85);
                    imagedestroy($image);
                    $converted = true;
                }
            }
            
            // Clean up temp file
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            // Determine final file
            if ($converted && file_exists($webp_file)) {
                $file_path = $webp_file;
                $file_type = 'image/webp';
            } else {
                // Last fallback: use original as jpg
                $final_file = $upload_dir['path'] . '/' . $safe_filename . '.jpg';
                file_put_contents($final_file, $image_data);
                $file_path = $final_file;
                $file_type = 'image/jpeg';
            }
        }
        
        // Create attachment - NO caption/attribution
        $attachment = array(
            'guid' => $upload_dir['url'] . '/' . basename($file_path),
            'post_mime_type' => $file_type,
            'post_title' => $seo_title,
            'post_content' => '',
            'post_status' => 'inherit',
            'post_excerpt' => '' // No caption
        );
        
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        
        if (is_wp_error($attach_id)) {
            error_log('LendCity Unsplash: Attachment creation failed - ' . $attach_id->get_error_message());
            return array('success' => false, 'error' => 'Attachment failed: ' . $attach_id->get_error_message());
        }
        
        if (!$attach_id) {
            error_log('LendCity Unsplash: Attachment creation returned 0');
            return array('success' => false, 'error' => 'Attachment creation failed');
        }
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Set alt text (just the SEO title, no attribution)
        update_post_meta($attach_id, '_wp_attachment_image_alt', $seo_title);
        
        // Set as featured image
        set_post_thumbnail($post_id, $attach_id);
        
        error_log('LendCity: Featured image set for post ' . $post_id . ' - ' . basename($file_path));
        
        return array(
            'success' => true,
            'attachment_id' => $attach_id,
            'filename' => basename($file_path),
            'photographer' => $photographer,
            'compressed' => isset($tinypng_result['success']) && $tinypng_result['success'],
            'savings' => isset($tinypng_result['savings']) ? $tinypng_result['savings'] : 0
        );
    }
    
    public function ajax_schedule_all_articles() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        // This would process all queued articles - simplified for now
        wp_send_json_success(array('message' => 'Use individual processing'));
    }
    
    public function ajax_delete_queued_file() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $file_name = sanitize_file_name($_POST['file_name']);
        $queue_dir = wp_upload_dir()['basedir'] . '/lendcity-article-queue';
        $file_path = $queue_dir . '/' . $file_name;
        
        if (file_exists($file_path) && unlink($file_path)) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Could not delete file');
        }
    }
    
    /**
     * Add Unsplash featured image to an existing post
     */
    public function ajax_add_unsplash_image() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        // Check if already has featured image
        if (has_post_thumbnail($post_id)) {
            wp_send_json_error('Post already has a featured image');
        }
        
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (empty($unsplash_key)) {
            wp_send_json_error('Unsplash API key not configured');
        }
        
        // Use Claude to generate a search query based on the post title
        $api = new LendCity_Claude_API();
        $prompt = "Generate a simple 2-3 word Unsplash search query for finding a relevant featured image for this blog post:\n\n";
        $prompt .= "Title: " . $post->post_title . "\n\n";
        $prompt .= "Return ONLY the search terms, nothing else. Example: 'real estate investment' or 'mortgage documents'";
        
        $search_query = $api->simple_completion($prompt, 50);
        $search_query = trim($search_query, '"\'');
        
        if (empty($search_query)) {
            // Fallback: use first few words of title
            $search_query = implode(' ', array_slice(explode(' ', $post->post_title), 0, 3));
        }
        
        // Get SEO title or use post title
        $seo_title = get_post_meta($post_id, '_seopress_titles_title', true);
        if (empty($seo_title)) {
            $seo_title = $post->post_title;
        }
        
        // Fetch and set the image
        $result = $this->fetch_unsplash_featured_image($post_id, $search_query, $seo_title, $unsplash_key);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Image added successfully',
                'thumb_url' => get_the_post_thumbnail_url($post_id, 'thumbnail'),
                'photographer' => $result['photographer']
            ));
        } else {
            wp_send_json_error($result['error'] ?? 'Failed to add image');
        }
    }
    
    /**
     * Replace existing featured image with a new Unsplash image
     */
    public function ajax_replace_unsplash_image() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }
        
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        $unsplash_key = get_option('lendcity_unsplash_api_key', '');
        if (empty($unsplash_key)) {
            wp_send_json_error('Unsplash API key not configured');
        }
        
        error_log('LendCity: Starting image replacement for post ' . $post_id);
        
        // Delete the old featured image attachment
        $old_thumbnail_id = get_post_thumbnail_id($post_id);
        if ($old_thumbnail_id) {
            error_log('LendCity: Deleting old thumbnail ' . $old_thumbnail_id);
            delete_post_thumbnail($post_id);
            wp_delete_attachment($old_thumbnail_id, true);
        }
        
        // Use Claude to generate a search query based on the post title
        $api = new LendCity_Claude_API();
        $prompt = "Generate a simple 2-3 word Unsplash search query for finding a relevant featured image for this blog post:\n\n";
        $prompt .= "Title: " . $post->post_title . "\n\n";
        $prompt .= "Return ONLY the search terms, nothing else. Example: 'real estate investment' or 'mortgage documents'";
        
        $search_query = $api->simple_completion($prompt, 50);
        $search_query = trim($search_query, '"\'');
        
        error_log('LendCity: Search query generated: ' . $search_query);
        
        if (empty($search_query)) {
            $search_query = implode(' ', array_slice(explode(' ', $post->post_title), 0, 3));
            error_log('LendCity: Using fallback search query: ' . $search_query);
        }
        
        // Get SEO title or use post title
        $seo_title = get_post_meta($post_id, '_seopress_titles_title', true);
        if (empty($seo_title)) {
            $seo_title = $post->post_title;
        }
        
        // Fetch and set the new image
        $result = $this->fetch_unsplash_featured_image($post_id, $search_query, $seo_title, $unsplash_key);
        
        error_log('LendCity: Fetch result: ' . print_r($result, true));
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Image replaced successfully',
                'thumb_url' => get_the_post_thumbnail_url($post_id, 'thumbnail'),
                'photographer' => $result['photographer'] ?? ''
            ));
        } else {
            wp_send_json_error($result['error'] ?? 'Failed to replace image');
        }
    }
    
    // ==================== SETTINGS AJAX ====================
    
    public function ajax_test_api() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $api = new LendCity_Claude_API();
        $result = $api->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result['message'] . ' (Model: ' . $result['model'] . ')');
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_test_tinypng() {
        check_ajax_referer('lendcity_claude_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $api_key = get_option('lendcity_tinypng_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('API key not configured');
        }
        
        // Test by calling the API with validation endpoint
        $response = wp_remote_post('https://api.tinify.com/shrink', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key)
            ),
            'body' => '', // Empty body to just validate
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // 400 = empty body (expected), 401 = bad key, 429 = limit reached
        if ($code === 400 || $code === 201) {
            // Get compression count from headers
            $count = wp_remote_retrieve_header($response, 'compression-count');
            wp_send_json_success('API key valid. Compressions this month: ' . ($count ?: '0'));
        } elseif ($code === 401) {
            wp_send_json_error('Invalid API key');
        } elseif ($code === 429) {
            wp_send_json_error('Monthly limit exceeded');
        } else {
            wp_send_json_error('Unexpected response: ' . $code);
        }
    }
    
    /**
     * Ensure image is exactly the specified dimensions
     * Crops/resizes in place if needed
     */
    private function ensure_image_dimensions($file_path, $target_width = 1920, $target_height = 1080) {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }
        
        // Get current dimensions
        $size = getimagesize($file_path);
        if (!$size) {
            return false;
        }
        
        $orig_width = $size[0];
        $orig_height = $size[1];
        
        // If already correct dimensions, skip
        if ($orig_width == $target_width && $orig_height == $target_height) {
            return true;
        }
        
        // Load image
        $mime = $size['mime'];
        if ($mime == 'image/jpeg') {
            $image = imagecreatefromjpeg($file_path);
        } elseif ($mime == 'image/png') {
            $image = imagecreatefrompng($file_path);
        } elseif ($mime == 'image/webp') {
            $image = imagecreatefromwebp($file_path);
        } else {
            return false;
        }
        
        if (!$image) {
            return false;
        }
        
        // Calculate crop dimensions to maintain aspect ratio
        $target_ratio = $target_width / $target_height;
        $orig_ratio = $orig_width / $orig_height;
        
        if ($orig_ratio > $target_ratio) {
            // Image is wider - crop sides
            $crop_height = $orig_height;
            $crop_width = intval($orig_height * $target_ratio);
            $crop_x = intval(($orig_width - $crop_width) / 2);
            $crop_y = 0;
        } else {
            // Image is taller - crop top/bottom
            $crop_width = $orig_width;
            $crop_height = intval($orig_width / $target_ratio);
            $crop_x = 0;
            $crop_y = intval(($orig_height - $crop_height) / 2);
        }
        
        // Create new image at target size
        $new_image = imagecreatetruecolor($target_width, $target_height);
        
        // Preserve transparency for PNG
        if ($mime == 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }
        
        // Crop and resize
        imagecopyresampled(
            $new_image, $image,
            0, 0, $crop_x, $crop_y,
            $target_width, $target_height, $crop_width, $crop_height
        );
        
        // Save back to file
        imagejpeg($new_image, $file_path, 95);
        
        imagedestroy($image);
        imagedestroy($new_image);
        
        error_log('LendCity: Resized image to ' . $target_width . 'x' . $target_height);
        
        return true;
    }
    
    /**
     * Compress image using TinyPNG API
     * Returns path to compressed file or original if compression fails
     */
    private function compress_with_tinypng($file_path) {
        $api_key = get_option('lendcity_tinypng_api_key', '');
        if (empty($api_key)) {
            return array('success' => false, 'path' => $file_path, 'error' => 'No API key');
        }
        
        // Read file
        $file_data = file_get_contents($file_path);
        if (!$file_data) {
            return array('success' => false, 'path' => $file_path, 'error' => 'Could not read file');
        }
        
        $original_size = strlen($file_data);
        
        // Upload to TinyPNG
        $response = wp_remote_post('https://api.tinify.com/shrink', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
                'Content-Type' => 'application/octet-stream'
            ),
            'body' => $file_data,
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('TinyPNG: Upload failed - ' . $response->get_error_message());
            return array('success' => false, 'path' => $file_path, 'error' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 201) {
            error_log('TinyPNG: Bad response code - ' . $code);
            return array('success' => false, 'path' => $file_path, 'error' => 'API error: ' . $code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['output']['url'])) {
            return array('success' => false, 'path' => $file_path, 'error' => 'No output URL');
        }
        
        // Download compressed image as WebP
        $output_url = $body['output']['url'];
        
        // Request WebP conversion from TinyPNG
        $download_response = wp_remote_post($output_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'convert' => array('type' => 'image/webp')
            )),
            'timeout' => 60
        ));
        
        if (is_wp_error($download_response)) {
            // Fallback: download without conversion
            $download_response = wp_remote_get($output_url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode('api:' . $api_key)
                ),
                'timeout' => 60
            ));
        }
        
        if (is_wp_error($download_response)) {
            error_log('TinyPNG: Download failed');
            return array('success' => false, 'path' => $file_path, 'error' => 'Download failed');
        }
        
        $compressed_data = wp_remote_retrieve_body($download_response);
        $content_type = wp_remote_retrieve_header($download_response, 'content-type');
        $compressed_size = strlen($compressed_data);
        
        // Determine extension based on content type
        $extension = (strpos($content_type, 'webp') !== false) ? '.webp' : '.jpg';
        
        // Save compressed file
        $path_info = pathinfo($file_path);
        $compressed_path = $path_info['dirname'] . '/' . $path_info['filename'] . '-tiny' . $extension;
        
        file_put_contents($compressed_path, $compressed_data);
        
        // Calculate savings
        $savings = round((1 - ($compressed_size / $original_size)) * 100);
        
        error_log('TinyPNG: Compressed ' . basename($file_path) . ' - saved ' . $savings . '% (' . 
            round($original_size/1024) . 'KB -> ' . round($compressed_size/1024) . 'KB)');
        
        return array(
            'success' => true,
            'path' => $compressed_path,
            'original_size' => $original_size,
            'compressed_size' => $compressed_size,
            'savings' => $savings,
            'format' => $extension === '.webp' ? 'webp' : 'jpeg'
        );
    }
}

// Initialize plugin
function lendcity_claude_init() {
    return LendCity_Claude_Integration::get_instance();
}
add_action('plugins_loaded', 'lendcity_claude_init');
