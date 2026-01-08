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

    // 1. Database tables - PRESERVED
    // We no longer drop tables on uninstall to preserve:
    // - lendcity_smart_linker_catalog (Claude-analyzed article metadata)
    // This allows users to reinstall without losing their catalog data
    // To fully remove data, users should use a database cleanup tool

    // 2. Delete temporary/queue options only - PRESERVE API keys and settings
    // API keys are valuable and should not be deleted on uninstall
    $options_to_delete = array(
        // Version tracking (can be regenerated)
        'lendcity_catalog_db_version',
        'lendcity_claude_last_version',
        'lendcity_v11_notice_dismissed',

        // Queue states (temporary data)
        'lendcity_link_queue',
        'lendcity_link_queue_status',
        'lendcity_meta_queue',
        'lendcity_meta_queue_status',
        'lendcity_catalog_queue',
        'lendcity_catalog_queue_status',

        // Rate limiting (temporary)
        'lendcity_api_rate_limit',
    );

    // PRESERVED (not deleted):
    // - lendcity_claude_api_key (Claude API key)
    // - lendcity_tinypng_api_key (TinyPNG API key)
    // - lendcity_unsplash_access_key (Unsplash API key)
    // - lendcity_transistor_api_key (Transistor API key)
    // - lendcity_external_api_url (Vercel API URL)
    // - lendcity_external_api_key (Vercel API key)
    // - All other user settings

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

    // 6. Post meta - PRESERVED
    // We no longer delete post meta on uninstall to preserve:
    // - Smart link data (_lendcity_smart_links)
    // - Pillar page settings (_lendcity_is_pillar)
    // - Page priority and keywords
    // This allows users to reinstall the plugin without losing their link structure
}

// Run cleanup
lendcity_claude_uninstall_cleanup();
