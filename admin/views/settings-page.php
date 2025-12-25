<?php
/**
 * Settings Page - v9.9.6 - Added debug mode toggle
 */

if (!defined('ABSPATH')) {
    exit;
}

$api_key = get_option('lendcity_claude_api_key', '');
$unsplash_key = get_option('lendcity_unsplash_api_key', '');
$tinypng_key = get_option('lendcity_tinypng_api_key', '');
$auto_link = get_option('lendcity_smart_linker_auto', 'yes');
$debug_mode = get_option('lendcity_debug_mode', 'no');

$nonce = wp_create_nonce('lendcity_claude_nonce');
?>

<div class="wrap">
    <h1>Settings</h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('lendcity_claude_settings'); ?>
        
        <h2>API Keys</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Claude API Key</th>
                <td>
                    <input type="password" name="lendcity_claude_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" id="api-key-field">
                    <button type="button" class="button" onclick="var f=document.getElementById('api-key-field'); f.type = f.type==='password'?'text':'password';">Show/Hide</button>
                    <button type="button" class="button" id="test-api-btn">Test Connection</button>
                    <span id="api-test-result" style="margin-left: 10px;"></span>
                    <p class="description">Get your API key from <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a></p>
                </td>
            </tr>
            <tr>
                <th scope="row">Unsplash Access Key</th>
                <td>
                    <input type="password" name="lendcity_unsplash_api_key" value="<?php echo esc_attr($unsplash_key); ?>" class="regular-text" id="unsplash-key-field">
                    <button type="button" class="button" onclick="var f=document.getElementById('unsplash-key-field'); f.type = f.type==='password'?'text':'password';">Show/Hide</button>
                    <p class="description">Get your Access Key from <a href="https://unsplash.com/oauth/applications" target="_blank">Unsplash Developers</a>. Free tier: 50 requests/hour.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">TinyPNG API Key</th>
                <td>
                    <input type="password" name="lendcity_tinypng_api_key" value="<?php echo esc_attr($tinypng_key); ?>" class="regular-text" id="tinypng-key-field">
                    <button type="button" class="button" onclick="var f=document.getElementById('tinypng-key-field'); f.type = f.type==='password'?'text':'password';">Show/Hide</button>
                    <button type="button" class="button" id="test-tinypng-btn">Test Connection</button>
                    <span id="tinypng-test-result" style="margin-left: 10px;"></span>
                    <p class="description">Get your API key from <a href="https://tinypng.com/developers" target="_blank">TinyPNG Developers</a>. Compresses images 50-80% smaller. 500 free/month, then $0.009/image.</p>
                </td>
            </tr>
        </table>
        
        <h2>Smart Linker Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Auto-Link New Posts</th>
                <td>
                    <label>
                        <input type="checkbox" name="lendcity_smart_linker_auto" value="yes" <?php checked($auto_link, 'yes'); ?>>
                        Automatically add internal links when posts are published
                    </label>
                    <p class="description">When enabled, new posts (including scheduled posts) will automatically be added to the catalog and receive internal links ~60 seconds after publishing.</p>
                </td>
            </tr>
        </table>
        
        <h2>Advanced Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Debug Mode</th>
                <td>
                    <label>
                        <input type="checkbox" name="lendcity_debug_mode" value="yes" <?php checked($debug_mode, 'yes'); ?>>
                        Enable detailed logging for troubleshooting
                    </label>
                    <p class="description">Only enable when debugging issues. Creates detailed logs in the error log.</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
    
    <hr>
    
    <h2>Plugin Information</h2>
    <table class="form-table">
        <tr>
            <th>Version</th>
            <td><?php echo LENDCITY_CLAUDE_VERSION; ?></td>
        </tr>
        <tr>
            <th>Features</th>
            <td>
                <ul style="margin: 0;">
                    <li><strong>Smart Linker</strong> - AI-powered internal linking with priority pages & keywords</li>
                    <li><strong>SEO Metadata</strong> - Generate titles, descriptions & tags from link keywords</li>
                    <li><strong>Article Scheduler</strong> - Process DOCX files into posts with SEOPress FAQs & Unsplash images</li>
                    <li><strong>Podcast Publisher</strong> - Auto-create posts from podcast episodes</li>
                    <li><strong>Image Optimization</strong> - TinyPNG compression + WebP conversion</li>
                </ul>
            </td>
        </tr>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo $nonce; ?>';
    
    $('#test-api-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#api-test-result');
        
        $btn.prop('disabled', true).text('Testing...');
        $result.html('');
        
        $.post(ajaxurl, {
            action: 'lendcity_test_api',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                $result.html('<span style="color: green;">Connected: ' + response.data + '</span>');
            } else {
                $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
            }
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function() {
            $result.html('<span style="color: red;">Connection failed</span>');
            $btn.prop('disabled', false).text('Test Connection');
        });
    });
    
    $('#test-tinypng-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#tinypng-test-result');
        
        $btn.prop('disabled', true).text('Testing...');
        $result.html('');
        
        $.post(ajaxurl, {
            action: 'lendcity_test_tinypng',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                $result.html('<span style="color: green;">Connected: ' + response.data + '</span>');
            } else {
                $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
            }
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function() {
            $result.html('<span style="color: red;">Connection failed</span>');
            $btn.prop('disabled', false).text('Test Connection');
        });
    });
});
</script>
