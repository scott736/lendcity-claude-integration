<?php
/**
 * LendCity Tools Uninstall
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Cleans up database tables, options, and transients.
 *
 * @package LendCity_Claude
 */

// Exit if not called by WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data
 */
function lendcity_claude_uninstall_cleanup() {
    global $wpdb;

    // 1. Drop custom database table
    $table_name = $wpdb->prefix . 'lendcity_catalog';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

    // 2. Delete all plugin options
    $options_to_delete = array(
        // Core settings
        'lendcity_claude_api_key',
        'lendcity_claude_model',
        'lendcity_links_per_post',
        'lendcity_max_links_per_target',
        'lendcity_link_target',
        'lendcity_smart_linker_enabled',
        'lendcity_catalog_db_version',
        'lendcity_claude_last_version',
        'lendcity_v11_notice_dismissed',

        // Article scheduler settings
        'lendcity_article_category',
        'lendcity_article_author',
        'lendcity_article_frequency',
        'lendcity_auto_scheduler_enabled',
        'lendcity_auto_scheduler_next_run',

        // TinyPNG
        'lendcity_tinypng_api_key',

        // Unsplash
        'lendcity_unsplash_access_key',

        // Podcast settings
        'lendcity_transistor_api_key',
        'lendcity_transistor_shows',
        'lendcity_podcast_webhook_secret',
        'lendcity_podcast_default_author',
        'lendcity_podcast_default_category',

        // Queue states
        'lendcity_link_queue',
        'lendcity_link_queue_status',
        'lendcity_meta_queue',
        'lendcity_meta_queue_status',
        'lendcity_catalog_queue',
        'lendcity_catalog_queue_status',

        // Rate limiting
        'lendcity_api_rate_limit',
    );

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }

    // 3. Delete all transients with our prefix
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_lendcity_%'
         OR option_name LIKE '_transient_timeout_lendcity_%'"
    );

    // 4. Clear scheduled cron events
    $cron_hooks = array(
        'lendcity_process_link_queue',
        'lendcity_process_meta_queue',
        'lendcity_process_catalog_queue',
        'lendcity_auto_article_scheduler',
        'lendcity_cleanup_logs',
    );

    foreach ($cron_hooks as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        wp_clear_scheduled_hook($hook);
    }

    // 5. Clear object cache for our keys
    wp_cache_flush();

    // 6. Clean up post meta
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta}
         WHERE meta_key LIKE 'lendcity_%'"
    );
}

// Run cleanup
lendcity_claude_uninstall_cleanup();
