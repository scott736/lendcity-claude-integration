<?php
/**
 * Podcast Publisher Page - v11.6.0
 * Uses Transistor webhooks instead of RSS polling
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get webhook URL from plugin (uses backup-aware secret restoration)
$plugin_instance = class_exists('LendCity_Claude_Integration') ? LendCity_Claude_Integration::get_instance() : null;
if ($plugin_instance && method_exists($plugin_instance, 'get_transistor_webhook_url')) {
    $webhook_url = $plugin_instance->get_transistor_webhook_url();
} else {
    // Fallback with backup-aware secret handling
    $webhook_secret = get_option('lendcity_transistor_webhook_secret', '');
    $backup_secret = get_option('lendcity_transistor_webhook_secret_backup', '');

    if (empty($webhook_secret) && !empty($backup_secret)) {
        // Restore from backup
        $webhook_secret = $backup_secret;
        update_option('lendcity_transistor_webhook_secret', $webhook_secret);
    } elseif (empty($webhook_secret)) {
        // First time - generate new secret and backup
        $webhook_secret = wp_generate_password(32, false);
        update_option('lendcity_transistor_webhook_secret', $webhook_secret);
        update_option('lendcity_transistor_webhook_secret_backup', $webhook_secret, false);
    }
    $webhook_url = rest_url('lendcity/v1/transistor-webhook') . '?key=' . $webhook_secret;
}

// Get Transistor API key with backup restoration (survives plugin updates)
$transistor_api_key = get_option('lendcity_transistor_api_key', '');
$transistor_api_key_backup = get_option('lendcity_transistor_api_key_backup', '');

// Restore from backup if main option is empty but backup exists
if (empty($transistor_api_key) && !empty($transistor_api_key_backup)) {
    $transistor_api_key = $transistor_api_key_backup;
    update_option('lendcity_transistor_api_key', $transistor_api_key);
}

// Get show mappings from individual options (WordPress Settings API saves these)
$show_id_1 = get_option('lendcity_show_id_1', '');
$show_cat_1 = get_option('lendcity_show_category_1', '');
$show_id_2 = get_option('lendcity_show_id_2', '');
$show_cat_2 = get_option('lendcity_show_category_2', '');

$nonce = wp_create_nonce('lendcity_claude_nonce');
?>

<div class="wrap">
    <h1>Podcast Publisher</h1>
    <p>Automatically create blog posts when new podcast episodes are published via Transistor webhooks.</p>

    <form method="post" action="options.php">
        <?php settings_fields('lendcity_podcast_settings'); ?>

        <h2>Step 1: Webhook Endpoint</h2>
        <p>Copy this URL and paste it into your Transistor.fm webhook settings.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Webhook URL</th>
                <td>
                    <input type="text" id="webhook-url" value="<?php echo esc_attr($webhook_url); ?>" class="large-text" readonly style="background: #f0f0f0; font-family: monospace;">
                    <button type="button" class="button" id="copy-webhook-url" style="margin-left: 5px;">Copy URL</button>
                    <span id="copy-status" style="margin-left: 10px; color: green;"></span>
                    <p class="description">
                        In Transistor.fm: Go to <strong>Your Account → Webhooks</strong> → Add New Webhook<br>
                        Set event to <strong>episode_published</strong> and paste this URL.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Regenerate Secret</th>
                <td>
                    <button type="button" class="button" id="regenerate-secret-btn">Regenerate Webhook Secret</button>
                    <span id="regenerate-status" style="margin-left: 10px;"></span>
                    <p class="description">Use this if you need to invalidate the old webhook URL.</p>
                </td>
            </tr>
        </table>

        <hr style="margin: 30px 0;">

        <h2>Step 2: Transistor API Key (Optional)</h2>
        <p>Required only for advanced features like show info lookup.</p>
        <table class="form-table">
            <tr>
                <th scope="row">API Key</th>
                <td>
                    <input type="text" name="lendcity_transistor_api_key" value="<?php echo esc_attr($transistor_api_key); ?>" class="large-text" placeholder="Enter your Transistor API key">
                    <p class="description">
                        Get your API key from Transistor.fm: <strong>Your Account → API</strong>
                    </p>
                </td>
            </tr>
        </table>

        <hr style="margin: 30px 0;">

        <h2>Step 3: Show to Category Mapping</h2>
        <p>Map each Transistor show ID to a WordPress category. When a webhook arrives, the episode will be assigned to the matching category.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Show 1</th>
                <td>
                    <label>Show ID:</label>
                    <input type="text" name="lendcity_show_id_1" value="<?php echo esc_attr($show_id_1); ?>" class="regular-text" placeholder="e.g., 71061" style="width: 120px;">
                    <label style="margin-left: 15px;">Category:</label>
                    <input type="text" name="lendcity_show_category_1" value="<?php echo esc_attr($show_cat_1); ?>" class="regular-text" placeholder="e.g., Podcast Name">
                    <p class="description">Find your Show ID in Transistor: Dashboard → Show Settings → the number in the URL</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Show 2</th>
                <td>
                    <label>Show ID:</label>
                    <input type="text" name="lendcity_show_id_2" value="<?php echo esc_attr($show_id_2); ?>" class="regular-text" placeholder="e.g., 71049" style="width: 120px;">
                    <label style="margin-left: 15px;">Category:</label>
                    <input type="text" name="lendcity_show_category_2" value="<?php echo esc_attr($show_cat_2); ?>" class="regular-text" placeholder="e.g., Podcast Name">
                </td>
            </tr>
        </table>

        <?php submit_button('Save Settings'); ?>
    </form>

    <hr style="margin: 30px 0;">

    <h2>Manual Episode Processing</h2>
    <p>Paste a Transistor share URL to manually process an episode that wasn't captured by webhook.</p>
    <table class="form-table">
        <tr>
            <th scope="row">Share URL</th>
            <td>
                <input type="text" id="manual-share-url" class="large-text" placeholder="https://share.transistor.fm/s/xxxxxxxx">
                <p class="description">Find this URL in Transistor: Episode → Share → Copy the share link</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Category</th>
            <td>
                <select id="manual-category">
                    <?php
                    // Get categories from show mappings
                    if (!empty($show_cat_1)) {
                        echo '<option value="' . esc_attr($show_cat_1) . '">' . esc_html($show_cat_1) . '</option>';
                    }
                    if (!empty($show_cat_2)) {
                        echo '<option value="' . esc_attr($show_cat_2) . '">' . esc_html($show_cat_2) . '</option>';
                    }
                    if (empty($show_cat_1) && empty($show_cat_2)) {
                        echo '<option value="Podcast">Podcast</option>';
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-primary" id="manual-process-btn">Process Episode</button>
                <span id="manual-process-status" style="margin-left: 10px;"></span>
            </td>
        </tr>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js($nonce); ?>';

    // Copy webhook URL
    $('#copy-webhook-url').on('click', function() {
        var urlInput = document.getElementById('webhook-url');
        urlInput.select();
        urlInput.setSelectionRange(0, 99999);
        document.execCommand('copy');
        $('#copy-status').text('Copied!').fadeIn().delay(2000).fadeOut();
    });

    // Regenerate webhook secret
    $('#regenerate-secret-btn').on('click', function() {
        if (!confirm('This will invalidate your current webhook URL. You will need to update the URL in Transistor.fm. Continue?')) {
            return;
        }

        var $btn = $(this);
        var $status = $('#regenerate-status');

        $btn.prop('disabled', true).text('Regenerating...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lendcity_action', sub_action: 'regenerate_webhook_secret',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: green;">Secret regenerated! Reloading...</span>');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    $status.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Regenerate Webhook Secret');
            },
            error: function() {
                $status.html('<span style="color: red;">Request failed</span>');
                $btn.prop('disabled', false).text('Regenerate Webhook Secret');
            }
        });
    });

    // Manual episode processing
    $('#manual-process-btn').on('click', function() {
        var shareUrl = $('#manual-share-url').val().trim();
        var category = $('#manual-category').val();
        var $btn = $(this);
        var $status = $('#manual-process-status');

        if (!shareUrl) {
            $status.html('<span style="color: red;">Please enter a share URL</span>');
            return;
        }

        // Extract share_id from URL
        var match = shareUrl.match(/share\.transistor\.fm\/[se]\/([a-z0-9]+)/i);
        if (!match) {
            $status.html('<span style="color: red;">Invalid URL format. Use: https://share.transistor.fm/s/xxxxxxxx</span>');
            return;
        }

        var shareId = match[1];
        $btn.prop('disabled', true).text('Processing...');
        $status.html('<span style="color: blue;">Processing episode, this may take a minute...</span>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lendcity_action', sub_action: 'manual_process_episode',
                nonce: nonce,
                share_id: shareId,
                category: category
            },
            timeout: 120000, // 2 minute timeout for Claude API
            success: function(response) {
                if (response.success) {
                    $status.html('<span style="color: green;">Success! Post created: <a href="' + response.data.edit_url + '" target="_blank">#' + response.data.post_id + '</a></span>');
                    $('#manual-share-url').val('');
                } else {
                    $status.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Process Episode');
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    $status.html('<span style="color: red;">Request timed out. The episode may still be processing.</span>');
                } else {
                    $status.html('<span style="color: red;">Request failed: ' + error + '</span>');
                }
                $btn.prop('disabled', false).text('Process Episode');
            }
        });
    });
});
</script>
