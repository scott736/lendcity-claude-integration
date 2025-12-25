<?php
/**
 * Podcast Publisher Page - v9.9.6
 */

if (!defined('ABSPATH')) {
    exit;
}

// Podcast settings
$podcast1_enabled = get_option('lendcity_podcast1_enabled', 'no');
$podcast1_rss = get_option('lendcity_podcast1_rss', 'https://feeds.transistor.fm/wisdom-lifestyle-money-show');
$podcast1_category = get_option('lendcity_podcast1_category', 'The Wisdom, Lifestyle, Money Show');
$podcast1_start_date = get_option('lendcity_podcast1_start_date', '2025-01-05');
$podcast1_start_hour = get_option('lendcity_podcast1_start_hour', '05');
$podcast1_end_hour = get_option('lendcity_podcast1_end_hour', '07');

$podcast2_enabled = get_option('lendcity_podcast2_enabled', 'yes');
$podcast2_rss = get_option('lendcity_podcast2_rss', 'https://feeds.transistor.fm/close-more-deals-for-realtors');
$podcast2_category = get_option('lendcity_podcast2_category', 'Close More Deals – For REALTORS®');
$podcast2_start_date = get_option('lendcity_podcast2_start_date', '2024-12-22');
$podcast2_start_hour = get_option('lendcity_podcast2_start_hour', '09');
$podcast2_end_hour = get_option('lendcity_podcast2_end_hour', '11');

$nonce = wp_create_nonce('lendcity_claude_nonce');
?>

<div class="wrap">
    <h1>Podcast Publisher</h1>
    <p>Automatically create blog posts from podcast episodes. Each episode gets a full article with Transistor embed, Claude-written content, featured image, FAQs, and internal links.</p>
    
    <form method="post" action="options.php">
        <?php settings_fields('lendcity_claude_settings'); ?>
        
        <h2>Podcast 1: The Wisdom, Lifestyle, Money Show</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Enable Auto-Publish</th>
                <td>
                    <label>
                        <input type="checkbox" name="lendcity_podcast1_enabled" value="yes" <?php checked($podcast1_enabled, 'yes'); ?>>
                        Automatically publish posts from this podcast
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">RSS Feed URL</th>
                <td>
                    <input type="url" name="lendcity_podcast1_rss" value="<?php echo esc_attr($podcast1_rss); ?>" class="large-text">
                </td>
            </tr>
            <tr>
                <th scope="row">Category</th>
                <td>
                    <input type="text" name="lendcity_podcast1_category" value="<?php echo esc_attr($podcast1_category); ?>" class="regular-text">
                    <p class="description">Posts will be assigned to this category (created if it doesn't exist)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Start Date</th>
                <td>
                    <input type="date" name="lendcity_podcast1_start_date" value="<?php echo esc_attr($podcast1_start_date); ?>">
                    <p class="description">Only process episodes published after this date</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Check Window (EST)</th>
                <td>
                    <input type="number" name="lendcity_podcast1_start_hour" value="<?php echo esc_attr($podcast1_start_hour); ?>" min="0" max="23" style="width: 60px;">:00
                    to
                    <input type="number" name="lendcity_podcast1_end_hour" value="<?php echo esc_attr($podcast1_end_hour); ?>" min="0" max="23" style="width: 60px;">:00
                    <p class="description">Auto-check every 15 minutes during this window (Mondays only)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Manual Check</th>
                <td>
                    <button type="button" class="button button-primary" id="check-podcast1-btn">Check Now</button>
                    <span id="podcast1-check-result" style="margin-left: 10px;"></span>
                    <p class="description">Manually check for new episodes (bypasses day/time restrictions)</p>
                </td>
            </tr>
        </table>
        
        <hr style="margin: 30px 0;">
        
        <h2>Podcast 2: Close More Deals for Realtors</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Enable Auto-Publish</th>
                <td>
                    <label>
                        <input type="checkbox" name="lendcity_podcast2_enabled" value="yes" <?php checked($podcast2_enabled, 'yes'); ?>>
                        Automatically publish posts from this podcast
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">RSS Feed URL</th>
                <td>
                    <input type="url" name="lendcity_podcast2_rss" value="<?php echo esc_attr($podcast2_rss); ?>" class="large-text">
                </td>
            </tr>
            <tr>
                <th scope="row">Category</th>
                <td>
                    <input type="text" name="lendcity_podcast2_category" value="<?php echo esc_attr($podcast2_category); ?>" class="regular-text">
                    <p class="description">Posts will be assigned to this category (created if it doesn't exist)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Start Date</th>
                <td>
                    <input type="date" name="lendcity_podcast2_start_date" value="<?php echo esc_attr($podcast2_start_date); ?>">
                    <p class="description">Only process episodes published after this date</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Check Window (EST)</th>
                <td>
                    <input type="number" name="lendcity_podcast2_start_hour" value="<?php echo esc_attr($podcast2_start_hour); ?>" min="0" max="23" style="width: 60px;">:00
                    to
                    <input type="number" name="lendcity_podcast2_end_hour" value="<?php echo esc_attr($podcast2_end_hour); ?>" min="0" max="23" style="width: 60px;">:00
                    <p class="description">Auto-check every 15 minutes during this window (Mondays only)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Manual Check</th>
                <td>
                    <button type="button" class="button button-primary" id="check-podcast2-btn">Check Now</button>
                    <span id="podcast2-check-result" style="margin-left: 10px;"></span>
                    <p class="description">Manually check for new episodes (bypasses day/time restrictions)</p>
                </td>
            </tr>
        </table>
        
        <?php submit_button('Save Podcast Settings'); ?>
    </form>
    
    <hr style="margin: 30px 0;">
    
    <h2>Backfill Missing Episodes</h2>
    <p>Scan RSS feeds to find episodes that don't have blog posts yet, then create articles for them.</p>
    
    <table class="form-table">
        <tr>
            <th scope="row">Step 1: Scan RSS Feeds</th>
            <td>
                <button type="button" class="button" id="scan-embeds-btn">Scan RSS Feeds</button>
                <span id="scan-result" style="margin-left: 10px;"></span>
                <p class="description">Compares RSS episodes to existing posts with Transistor embeds</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Episodes Found</th>
            <td>
                <div id="found-episodes-list" style="max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
                    <p class="description">Click "Scan RSS Feeds" to check for missing episodes.</p>
                </div>
            </td>
        </tr>
        <tr>
            <th scope="row">Step 2: Create Articles</th>
            <td>
                <button type="button" class="button button-primary" id="backfill-episodes-btn" disabled>Create Missing Articles</button>
                <span id="backfill-result" style="margin-left: 10px;"></span>
                <p class="description">Creates new article posts for episodes that don't have one. This may take several minutes.</p>
            </td>
        </tr>
    </table>
    
    <hr style="margin: 30px 0;">
    
    <h2>Processed Episodes Log</h2>
    <table class="form-table">
        <tr>
            <th scope="row">Recent Episodes</th>
            <td>
                <?php
                $processed = get_option('lendcity_processed_podcast_episodes', array());
                if (!empty($processed)) {
                    echo '<div style="max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">';
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
                    echo '<p class="description">No episodes processed by this plugin yet.</p>';
                }
                ?>
            </td>
        </tr>
    </table>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo esc_js($nonce); ?>';
    
    // Check Podcast 1 manually
    $('#check-podcast1-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#podcast1-check-result');
        
        $btn.prop('disabled', true).text('Checking...');
        $result.html('<span style="color: blue;">Fetching RSS feed...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 120000,
            data: {
                action: 'lendcity_manual_podcast_check',
                nonce: nonce,
                podcast: 1
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    if (response.data.post_id) {
                        $result.append(' <a href="' + response.data.edit_link + '" target="_blank">Edit Post</a>');
                    }
                } else {
                    $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Check Now');
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: red;">Request failed: ' + status + ' - ' + error + '</span>');
                $btn.prop('disabled', false).text('Check Now');
            }
        });
    });
    
    // Check Podcast 2 manually
    $('#check-podcast2-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#podcast2-check-result');
        
        $btn.prop('disabled', true).text('Checking...');
        $result.html('<span style="color: blue;">Fetching RSS feed...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 120000,
            data: {
                action: 'lendcity_manual_podcast_check',
                nonce: nonce,
                podcast: 2
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    if (response.data.post_id) {
                        $result.append(' <a href="' + response.data.edit_link + '" target="_blank">Edit Post</a>');
                    }
                } else {
                    $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Check Now');
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: red;">Request failed: ' + status + ' - ' + error + '</span>');
                $btn.prop('disabled', false).text('Check Now');
            }
        });
    });
    
    // Scan RSS feeds for missing episodes
    var foundEpisodes = [];
    $('#scan-embeds-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#scan-result');
        var $list = $('#found-episodes-list');
        
        $btn.prop('disabled', true).text('Scanning...');
        $result.html('<span style="color: blue;">Fetching RSS feeds and checking existing posts...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 60000,
            data: {
                action: 'lendcity_scan_transistor_embeds',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    foundEpisodes = response.data.episodes;
                    var unprocessed = response.data.unprocessed_count;
                    var total = response.data.total;
                    var hasPost = total - unprocessed;
                    
                    $result.html('<span style="color: green;">Found ' + total + ' episodes in RSS. ' + hasPost + ' already have posts. ' + unprocessed + ' need articles.</span>');
                    
                    // Build list
                    var html = '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<tr style="background: #e0e0e0;"><th style="padding: 5px; text-align: left;">Status</th><th style="padding: 5px; text-align: left;">Episode Title</th><th style="padding: 5px; text-align: left;">Existing Post</th></tr>';
                    
                    response.data.episodes.forEach(function(ep) {
                        var status = ep.processed ? '<span style="color: green;">✓ Has Post</span>' : '<span style="color: orange;">○ Needs Article</span>';
                        var existingPost = ep.processed && ep.existing_post_id ? '<a href="post.php?post=' + ep.existing_post_id + '&action=edit" target="_blank">#' + ep.existing_post_id + '</a>' : '-';
                        html += '<tr style="border-bottom: 1px solid #ddd;">';
                        html += '<td style="padding: 5px;">' + status + '</td>';
                        html += '<td style="padding: 5px;">' + ep.title + ' <small style="color:#888;">(Podcast ' + ep.podcast + ')</small></td>';
                        html += '<td style="padding: 5px;">' + existingPost + '</td>';
                        html += '</tr>';
                    });
                    html += '</table>';
                    $list.html(html);
                    
                    // Enable backfill button if there are unprocessed episodes
                    $('#backfill-episodes-btn').prop('disabled', unprocessed === 0);
                } else {
                    $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Scan RSS Feeds');
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: red;">Request failed: ' + status + ' - ' + error + '</span>');
                $btn.prop('disabled', false).text('Scan RSS Feeds');
            }
        });
    });
    
    // Backfill episodes
    $('#backfill-episodes-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#backfill-result');
        
        if (!confirm('This will create new article posts for all episodes that don\'t have one yet.\n\nEach episode takes 30-60 seconds to process (Claude AI, image fetch, compression).\n\nContinue?')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Processing...');
        $result.html('<span style="color: blue;">Creating articles (this may take several minutes)...</span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 600000, // 10 minute timeout
            data: {
                action: 'lendcity_backfill_podcast_episodes',
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">' + response.data.message + '</span>');
                    // Refresh the page to show updated processed list
                    if (response.data.processed > 0) {
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                } else {
                    $result.html('<span style="color: red;">Error: ' + response.data + '</span>');
                }
                $btn.prop('disabled', false).text('Create Missing Articles');
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: red;">Request failed: ' + status + ' - ' + error + '</span>');
                $btn.prop('disabled', false).text('Create Missing Articles');
            }
        });
    });
});
</script>
