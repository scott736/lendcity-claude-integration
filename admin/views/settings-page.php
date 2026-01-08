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
$use_external_api = get_option('lendcity_use_external_api', 'no');
$external_api_url = get_option('lendcity_external_api_url', '');
$external_api_key = get_option('lendcity_external_api_key', '');

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

        <h2>External Vector API (Optional)</h2>
        <p class="description">Connect to an external Vercel-hosted API for vector-based smart linking. This provides faster, smarter linking using embeddings.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Enable External API</th>
                <td>
                    <label>
                        <input type="checkbox" name="lendcity_use_external_api" value="yes" <?php checked($use_external_api, 'yes'); ?> id="use-external-api">
                        Use external vector API instead of local catalog
                    </label>
                    <p class="description">When enabled, smart linking will use the external Pinecone-based API for faster, more intelligent results.</p>
                </td>
            </tr>
            <tr class="external-api-settings" style="<?php echo $use_external_api !== 'yes' ? 'display:none;' : ''; ?>">
                <th scope="row">API URL</th>
                <td>
                    <input type="url" name="lendcity_external_api_url" value="<?php echo esc_attr($external_api_url); ?>" class="regular-text" placeholder="https://your-app.vercel.app">
                    <p class="description">Your Vercel deployment URL (e.g., https://lendcity-smart-linker.vercel.app)</p>
                </td>
            </tr>
            <tr class="external-api-settings" style="<?php echo $use_external_api !== 'yes' ? 'display:none;' : ''; ?>">
                <th scope="row">API Secret Key</th>
                <td>
                    <input type="password" name="lendcity_external_api_key" value="<?php echo esc_attr($external_api_key); ?>" class="regular-text" id="external-api-key-field">
                    <button type="button" class="button" onclick="var f=document.getElementById('external-api-key-field'); f.type = f.type==='password'?'text':'password';">Show/Hide</button>
                    <button type="button" class="button" id="test-external-api-btn">Test Connection</button>
                    <span id="external-api-test-result" style="margin-left: 10px;"></span>
                    <p class="description">The API_SECRET_KEY configured in your Vercel environment</p>
                </td>
            </tr>
            <tr class="external-api-settings" style="<?php echo $use_external_api !== 'yes' ? 'display:none;' : ''; ?>">
                <th scope="row">Sync Catalog to Pinecone</th>
                <td>
                    <button type="button" class="button button-primary" id="sync-catalog-btn">Sync All Articles to Pinecone</button>
                    <span id="sync-catalog-result" style="margin-left: 10px;"></span>
                    <div id="sync-progress" style="display:none; margin-top: 10px;">
                        <div style="background: #f0f0f0; border-radius: 4px; overflow: hidden; height: 20px;">
                            <div id="sync-progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="sync-progress-text" style="margin: 5px 0;">Syncing...</p>
                    </div>
                    <p class="description">This will sync all your published posts and pages to Pinecone for vector-based smart linking. Run this once after initial setup.</p>
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

    <hr>

    <h2>Settings Backup</h2>
    <p>Export your plugin settings for backup or to migrate to another site. API keys are not included for security.</p>
    <table class="form-table">
        <tr>
            <th>Export Settings</th>
            <td>
                <button type="button" class="button" id="export-settings-btn">Export Settings (JSON)</button>
                <span id="export-result" style="margin-left: 10px;"></span>
            </td>
        </tr>
        <tr>
            <th>Import Settings</th>
            <td>
                <input type="file" id="import-file" accept=".json" style="display: none;">
                <button type="button" class="button" id="import-settings-btn">Import Settings</button>
                <span id="import-result" style="margin-left: 10px;"></span>
                <p class="description">Select a JSON file exported from another LendCity Tools installation.</p>
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
            action: 'lendcity_action', sub_action: 'test_api',
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
            action: 'lendcity_action', sub_action: 'test_tinypng',
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

    // Export Settings
    $('#export-settings-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#export-result');

        $btn.prop('disabled', true).text('Exporting...');
        $result.html('');

        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'export_settings',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                // Create and download file
                var dataStr = JSON.stringify(response.data, null, 2);
                var blob = new Blob([dataStr], {type: 'application/json'});
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'lendcity-settings-' + new Date().toISOString().slice(0,10) + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                $result.html('<span style="color: green;">Settings exported!</span>');
            } else {
                $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
            }
            $btn.prop('disabled', false).text('Export Settings (JSON)');
        }).fail(function() {
            $result.html('<span style="color: red;">Export failed</span>');
            $btn.prop('disabled', false).text('Export Settings (JSON)');
        });
    });

    // Import Settings
    $('#import-settings-btn').on('click', function() {
        $('#import-file').click();
    });

    $('#import-file').on('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var $result = $('#import-result');
        var $btn = $('#import-settings-btn');

        var reader = new FileReader();
        reader.onload = function(e) {
            var content = e.target.result;

            // Validate JSON
            try {
                JSON.parse(content);
            } catch(err) {
                $result.html('<span style="color: red;">Invalid JSON file</span>');
                return;
            }

            if (!confirm('Import settings from ' + file.name + '? This will overwrite current settings.')) {
                return;
            }

            $btn.prop('disabled', true).text('Importing...');
            $result.html('');

            $.post(ajaxurl, {
                action: 'lendcity_action', sub_action: 'import_settings',
                nonce: nonce,
                settings_json: content
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Import Settings');
            }).fail(function() {
                $result.html('<span style="color: red;">Import failed</span>');
                $btn.prop('disabled', false).text('Import Settings');
            });
        };
        reader.readAsText(file);

        // Reset file input
        $(this).val('');
    });

    // External API toggle
    $('#use-external-api').on('change', function() {
        if ($(this).is(':checked')) {
            $('.external-api-settings').show();
        } else {
            $('.external-api-settings').hide();
        }
    });

    // Test External API
    $('#test-external-api-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#external-api-test-result');

        $btn.prop('disabled', true).text('Testing...');
        $result.html('');

        $.post(ajaxurl, {
            action: 'lendcity_action',
            sub_action: 'test_external_api',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                $result.html('<span style="color: green;">' + response.data + '</span>');
            } else {
                $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
            }
            $btn.prop('disabled', false).text('Test Connection');
        }).fail(function() {
            $result.html('<span style="color: red;">Connection failed</span>');
            $btn.prop('disabled', false).text('Test Connection');
        });
    });

    // Sync Catalog to Pinecone (batched - one at a time)
    $('#sync-catalog-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#sync-catalog-result');
        var $progress = $('#sync-progress');
        var $progressBar = $('#sync-progress-bar');
        var $progressText = $('#sync-progress-text');
        var syncNonce = '<?php echo wp_create_nonce('lendcity_bulk_sync'); ?>';

        if (!confirm('This will sync all published posts and pages to Pinecone. This may take several minutes for large catalogs. Continue?')) {
            return;
        }

        $btn.prop('disabled', true).text('Syncing...');
        $result.html('');
        $progress.show();
        $progressBar.css('width', '5%');
        $progressText.text('Getting article list...');

        // Step 1: Get list of items to sync
        $.post(ajaxurl, {
            action: 'lendcity_get_sync_items',
            nonce: syncNonce
        }, function(response) {
            if (!response.success) {
                $progressText.text('Failed');
                $result.html('<span style="color: red;">Error: ' + response.data.message + '</span>');
                $btn.prop('disabled', false).text('Sync All Articles to Pinecone');
                return;
            }

            var items = response.data.items;
            var total = items.length;
            var current = 0;
            var synced = 0;
            var skipped = 0;
            var failed = 0;
            var errors = [];

            $progressText.text('Syncing 0 of ' + total + ' articles...');

            // Process items one at a time
            function syncNext() {
                if (current >= items.length) {
                    // Done!
                    $progressBar.css('width', '100%');
                    $progressText.text('Complete!');
                    var resultMsg = '<span style="color: green;">Synced ' + synced + ' articles to Pinecone</span>';
                    if (skipped > 0) {
                        resultMsg += '<br><span style="color: #666;">' + skipped + ' unchanged articles skipped (saves API costs)</span>';
                    }
                    if (failed > 0) {
                        resultMsg += '<br><span style="color: orange;">' + failed + ' articles failed. Check console for details.</span>';
                        console.log('Sync errors:', errors);
                    }
                    $result.html(resultMsg);
                    $btn.prop('disabled', false).text('Sync All Articles to Pinecone');
                    return;
                }

                var item = items[current];
                var percent = Math.round(((current + 1) / total) * 100);
                $progressBar.css('width', percent + '%');
                $progressText.text('Syncing ' + (current + 1) + ' of ' + total + ' (' + item.type + ')...');

                $.post(ajaxurl, {
                    action: 'lendcity_sync_single_item',
                    nonce: syncNonce,
                    post_id: item.id
                }, function(res) {
                    if (res.success) {
                        // Check if it was skipped (unchanged) vs actually synced
                        if (res.data && res.data.action === 'skipped') {
                            skipped++;
                        } else {
                            synced++;
                        }
                    } else {
                        failed++;
                        errors.push({
                            postId: item.id,
                            type: item.type,
                            error: res.data ? res.data.message : 'Unknown error'
                        });
                    }
                    current++;
                    syncNext();
                }).fail(function() {
                    failed++;
                    errors.push({
                        postId: item.id,
                        type: item.type,
                        error: 'Request failed'
                    });
                    current++;
                    syncNext();
                });
            }

            // Start syncing
            syncNext();

        }).fail(function() {
            $progressText.text('Failed');
            $result.html('<span style="color: red;">Failed to get article list</span>');
            $btn.prop('disabled', false).text('Sync All Articles to Pinecone');
        });
    });
});
</script>
