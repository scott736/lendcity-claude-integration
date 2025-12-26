<?php
/**
 * Podcast Publisher Page - v11.6.0
 * Uses Transistor webhooks instead of RSS polling
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get or generate webhook secret
$webhook_secret = get_option('lendcity_transistor_webhook_secret', '');
if (empty($webhook_secret)) {
    $webhook_secret = wp_generate_password(32, false);
    update_option('lendcity_transistor_webhook_secret', $webhook_secret);
}
$webhook_url = rest_url('lendcity/v1/transistor-webhook') . '?key=' . $webhook_secret;

// Get Transistor API key
$transistor_api_key = get_option('lendcity_transistor_api_key', '');

// Get show mappings (show_id => category)
$shows_config = get_option('lendcity_transistor_shows', '');
$shows = !empty($shows_config) ? json_decode($shows_config, true) : array();

// Default show mappings if not set
if (empty($shows)) {
    $shows = array(
        '12576' => 'The Wisdom, Lifestyle, Money Show',
        '39269' => 'Close More Deals – For REALTORS®'
    );
    update_option('lendcity_transistor_shows', json_encode($shows));
}

// Get show IDs and categories as arrays for the form
$show_ids = array_keys($shows);
$show_categories = array_values($shows);

$nonce = wp_create_nonce('lendcity_claude_nonce');
?>

<div class="wrap">
    <h1>Podcast Publisher</h1>
    <p>Automatically create blog posts when new podcast episodes are published via Transistor webhooks.</p>

    <form method="post" action="options.php">
        <?php settings_fields('lendcity_claude_settings'); ?>

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
                    <input type="text" name="lendcity_show_id_1" value="<?php echo esc_attr($show_ids[0] ?? ''); ?>" class="regular-text" placeholder="e.g., 12576" style="width: 120px;">
                    <label style="margin-left: 15px;">Category:</label>
                    <input type="text" name="lendcity_show_category_1" value="<?php echo esc_attr($show_categories[0] ?? ''); ?>" class="regular-text" placeholder="e.g., Podcast Name">
                    <p class="description">Find your Show ID in Transistor: Dashboard → Show Settings → the number in the URL</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Show 2</th>
                <td>
                    <label>Show ID:</label>
                    <input type="text" name="lendcity_show_id_2" value="<?php echo esc_attr($show_ids[1] ?? ''); ?>" class="regular-text" placeholder="e.g., 39269" style="width: 120px;">
                    <label style="margin-left: 15px;">Category:</label>
                    <input type="text" name="lendcity_show_category_2" value="<?php echo esc_attr($show_categories[1] ?? ''); ?>" class="regular-text" placeholder="e.g., Podcast Name">
                </td>
            </tr>
        </table>

        <?php submit_button('Save Settings'); ?>
    </form>

    <hr style="margin: 30px 0;">

    <h2>Processed Episodes Log</h2>
    <p>Episodes processed via webhook appear here. Use this to confirm the integration is working.</p>
    <table class="form-table">
        <tr>
            <th scope="row">Recent Episodes</th>
            <td>
                <?php
                $processed = get_option('lendcity_processed_podcast_episodes', array());
                if (!empty($processed)) {
                    echo '<div style="max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">';
                    echo '<table style="width: 100%; border-collapse: collapse;">';
                    echo '<tr style="background: #e0e0e0;"><th style="padding: 5px; text-align: left;">Date</th><th style="padding: 5px; text-align: left;">Title</th><th style="padding: 5px; text-align: left;">Post</th></tr>';
                    foreach (array_reverse($processed) as $ep) {
                        $edit_link = get_edit_post_link($ep['post_id'], 'raw');
                        echo '<tr style="border-bottom: 1px solid #ddd;">';
                        echo '<td style="padding: 5px;"><small>' . esc_html($ep['date']) . '</small></td>';
                        echo '<td style="padding: 5px;"><small>' . esc_html($ep['title']) . '</small></td>';
                        echo '<td style="padding: 5px;"><small><a href="' . esc_url($edit_link) . '" target="_blank">#' . esc_html($ep['post_id']) . '</a></small></td>';
                        echo '</tr>';
                    }
                    echo '</table></div>';
                } else {
                    echo '<p class="description">No episodes processed yet. Publish an episode in Transistor to test the webhook.</p>';
                }
                ?>
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
                action: 'lendcity_regenerate_webhook_secret',
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
});
</script>
