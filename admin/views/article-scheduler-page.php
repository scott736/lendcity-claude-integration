<?php
/**
 * Article Scheduler - v9.0
 * Full featured with frequency settings and projections
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$publish_frequency = get_option('lendcity_article_frequency', 3);
$publish_time = get_option('lendcity_article_publish_time', '06:00');
$default_category = get_option('lendcity_claude_default_category', 1);
$min_scheduled_posts = get_option('lendcity_min_scheduled_posts', 20);
$daily_processing_limit = get_option('lendcity_daily_processing_limit', 8);

// Get timezone
$timezone = get_option('timezone_string') ?: 'America/Toronto';

// Queue directory - use the correct path
$upload_dir = wp_upload_dir();
$queue_dir = $upload_dir['basedir'] . '/lendcity-article-queue';

// Create queue directory if it doesn't exist
if (!file_exists($queue_dir)) {
    wp_mkdir_p($queue_dir);
}

// Handle settings update
$settings_message = '';
if (isset($_POST['save_scheduler_settings']) && wp_verify_nonce($_POST['settings_nonce'], 'lendcity_scheduler_settings')) {
    $new_frequency = intval($_POST['publish_frequency']);
    $new_time = sanitize_text_field($_POST['publish_time']);
    $new_category = intval($_POST['default_category']);
    $new_min_scheduled = intval($_POST['min_scheduled_posts']);
    $new_daily_limit = intval($_POST['daily_processing_limit']);

    if ($new_frequency >= 1 && $new_frequency <= 30) {
        update_option('lendcity_article_frequency', $new_frequency);
        $publish_frequency = $new_frequency;

        // Reschedule cron with new frequency
        wp_clear_scheduled_hook('lendcity_auto_schedule_articles');
        wp_schedule_event(time(), 'lendcity_article_frequency', 'lendcity_auto_schedule_articles');
    }

    if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $new_time)) {
        update_option('lendcity_article_publish_time', $new_time);
        $publish_time = $new_time;
    }

    if ($new_min_scheduled >= 5 && $new_min_scheduled <= 100) {
        update_option('lendcity_min_scheduled_posts', $new_min_scheduled);
        $min_scheduled_posts = $new_min_scheduled;
    }

    if ($new_daily_limit >= 1 && $new_daily_limit <= 20) {
        update_option('lendcity_daily_processing_limit', $new_daily_limit);
        $daily_processing_limit = $new_daily_limit;
    }

    update_option('lendcity_claude_default_category', $new_category);
    $default_category = $new_category;

    $settings_message = '<div class="notice notice-success"><p>Settings saved!</p></div>';
}

// Handle file upload
$upload_message = '';
if (isset($_FILES['docx_files']) && !empty($_FILES['docx_files']['name'][0])) {
    if (!wp_verify_nonce($_POST['upload_nonce'], 'lendcity_upload_docx')) {
        $upload_message = '<div class="notice notice-error"><p>Security check failed.</p></div>';
    } else {
        $uploaded = 0;
        $errors = 0;
        
        foreach ($_FILES['docx_files']['name'] as $key => $name) {
            if (empty($name)) continue;
            
            $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($file_ext !== 'docx') {
                $errors++;
                continue;
            }
            
            if ($_FILES['docx_files']['error'][$key] !== UPLOAD_ERR_OK) {
                $errors++;
                continue;
            }
            
            $target_path = $queue_dir . '/' . sanitize_file_name($name);
            if (move_uploaded_file($_FILES['docx_files']['tmp_name'][$key], $target_path)) {
                $uploaded++;
            } else {
                $errors++;
            }
        }
        
        if ($uploaded > 0) {
            $upload_message = '<div class="notice notice-success"><p>Uploaded ' . $uploaded . ' file(s)</p></div>';
        }
        if ($errors > 0) {
            $upload_message .= '<div class="notice notice-warning"><p>' . $errors . ' file(s) failed to upload</p></div>';
        }
    }
}

// Get queued files (shuffled for random order)
$queued_files = glob($queue_dir . '/*.docx');
shuffle($queued_files); // Randomize the order
$queue_count = count($queued_files);

// Get scheduled posts
$scheduled_posts = get_posts(array(
    'post_type' => 'post',
    'post_status' => 'future',
    'posts_per_page' => -1,
    'orderby' => 'date',
    'order' => 'ASC'
));
$scheduled_count = count($scheduled_posts);

// Calculate projection
$last_scheduled_date = null;
if (!empty($scheduled_posts)) {
    $last_post = end($scheduled_posts);
    $last_scheduled_date = new DateTime($last_post->post_date, new DateTimeZone($timezone));
} else {
    $last_scheduled_date = new DateTime('now', new DateTimeZone($timezone));
}

// Project when all queued articles would be published
$projection_date = clone $last_scheduled_date;
$projection_date->modify('+' . ($queue_count * $publish_frequency) . ' days');

// Categories
$categories = get_categories(array('hide_empty' => false));
$nonce = wp_create_nonce('lendcity_claude_nonce');
?>

<div class="wrap">
    <h1>Article Scheduler</h1>
    <p>Upload .docx files and process them into WordPress posts using Claude AI.</p>
    
    <?php echo $settings_message; ?>
    <?php echo $upload_message; ?>
    
    <!-- Stats Overview -->
    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 20px; margin: 20px 0;">
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center;">
            <div style="font-size: 36px; font-weight: 600; color: #2271b1;"><?php echo $queue_count; ?></div>
            <div style="color: #666;">Queued Files</div>
        </div>
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center;">
            <div style="font-size: 36px; font-weight: 600; color: #2e7d32;"><?php echo $scheduled_count; ?></div>
            <div style="color: #666;">Scheduled Posts</div>
        </div>
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center;">
            <div style="font-size: 36px; font-weight: 600; color: #e91e63;"><?php echo $daily_processing_limit; ?></div>
            <div style="color: #666;">Articles/Day (AI)</div>
        </div>
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center;">
            <div style="font-size: 36px; font-weight: 600; color: #9c27b0;"><?php echo $publish_frequency; ?></div>
            <div style="color: #666;">Days Between Posts</div>
        </div>
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center;">
            <div style="font-size: 24px; font-weight: 600; color: #f57c00;"><?php echo $projection_date->format('M j, Y'); ?></div>
            <div style="color: #666;">Last Article Posts</div>
        </div>
    </div>
    
    <!-- Settings Section -->
    <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Scheduling Settings</h2>
        <form method="post">
            <?php wp_nonce_field('lendcity_scheduler_settings', 'settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Publish Frequency</th>
                    <td>
                        <select name="publish_frequency">
                            <?php for ($i = 1; $i <= 14; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php selected($publish_frequency, $i); ?>>
                                    Every <?php echo $i; ?> day<?php echo $i > 1 ? 's' : ''; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Publish Time</th>
                    <td>
                        <input type="time" name="publish_time" value="<?php echo esc_attr($publish_time); ?>">
                        <span class="description">Time in <?php echo $timezone; ?></span>
                    </td>
                </tr>
                <tr>
                    <th>Daily Processing Limit</th>
                    <td>
                        <input type="number" name="daily_processing_limit" value="<?php echo esc_attr($daily_processing_limit); ?>" min="1" max="20" style="width: 80px;">
                        <span class="description">Claude will process up to this many articles per day (runs at 2 AM)</span>
                    </td>
                </tr>
                <tr>
                    <th>Minimum Scheduled Posts</th>
                    <td>
                        <input type="number" name="min_scheduled_posts" value="<?php echo esc_attr($min_scheduled_posts); ?>" min="5" max="100" style="width: 80px;">
                        <span class="description">Fallback: If daily processing is inactive, maintain at least this many scheduled posts</span>
                    </td>
                </tr>
            </table>
            <p class="description" style="margin: 10px 0; background: #e7f3ff; padding: 10px; border-radius: 4px;">
                <strong>📅 Daily Processing:</strong> Every day at 2 AM, Claude will automatically process up to <?php echo $daily_processing_limit; ?> articles from your queue.
                <?php if ($queue_count > 0): ?>
                With <?php echo $queue_count; ?> files in queue, all articles will be processed in ~<?php echo ceil($queue_count / $daily_processing_limit); ?> days.
                <?php endif; ?>
            </p>
            <button type="submit" name="save_scheduler_settings" class="button button-primary">Save Settings</button>
        </form>
    </div>
    
    <!-- Upload Section -->
    <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Upload Articles</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('lendcity_upload_docx', 'upload_nonce'); ?>
            <input type="file" name="docx_files[]" accept=".docx" multiple style="margin-right: 10px;">
            <button type="submit" class="button button-primary">Upload Files</button>
            <p class="description" style="margin-top: 10px;">Select multiple .docx files to upload to the queue.</p>
        </form>
    </div>
    
    <!-- Scheduled Posts (moved above Queue) -->
    <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0;">Scheduled Posts (<?php echo $scheduled_count; ?> / <?php echo $min_scheduled_posts; ?> minimum)</h2>
            <div>
                <?php if ($queue_count > 0 && $scheduled_count < $min_scheduled_posts): ?>
                    <button class="button button-primary" id="run-auto-scheduler" style="margin-right: 10px;">▶ Run Auto-Scheduler Now</button>
                <?php endif; ?>
                <?php if (!empty($scheduled_posts)): ?>
                    <button class="button" id="add-images-scheduled">Add Unsplash Images to Scheduled Posts</button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($scheduled_count < $min_scheduled_posts && $queue_count > 0): ?>
            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px 15px; border-radius: 4px; margin-bottom: 15px;">
                <strong>⚠️ Below minimum:</strong> You have <?php echo $scheduled_count; ?> scheduled posts but need <?php echo $min_scheduled_posts; ?>. 
                Click "Run Auto-Scheduler Now" to process <?php echo min($min_scheduled_posts - $scheduled_count, $queue_count); ?> articles from the queue.
            </div>
        <?php endif; ?>
        
        <?php if (empty($scheduled_posts)): ?>
            <p style="color: #666;">No scheduled posts.</p>
        <?php else: ?>
            <div id="image-progress" style="display: none; background: #f0f6fc; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span id="image-status">Processing...</span>
                    <span id="image-count">0/0</span>
                </div>
                <div style="background: #ddd; height: 8px; border-radius: 4px;">
                    <div id="image-bar" style="background: #2271b1; height: 100%; width: 0%; border-radius: 4px; transition: width 0.3s;"></div>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">Image</th>
                        <th>Title</th>
                        <th style="width: 150px;">Publish Date</th>
                        <th style="width: 120px;">Category</th>
                        <th style="width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($scheduled_posts, 0, 20) as $post): 
                        $cats = get_the_category($post->ID);
                        $cat_name = !empty($cats) ? $cats[0]->name : '—';
                        $has_thumbnail = has_post_thumbnail($post->ID);
                        $thumb_url = $has_thumbnail ? get_the_post_thumbnail_url($post->ID, 'thumbnail') : '';
                    ?>
                    <tr data-post-id="<?php echo $post->ID; ?>">
                        <td>
                            <?php if ($has_thumbnail): ?>
                                <img src="<?php echo esc_url($thumb_url); ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <span style="display: inline-block; width: 50px; height: 50px; background: #f0f0f0; border-radius: 4px; text-align: center; line-height: 50px; color: #999;">No</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank"><?php echo esc_html($post->post_title); ?></a></td>
                        <td><?php echo date('M j, Y g:i A', strtotime($post->post_date)); ?></td>
                        <td><?php echo esc_html($cat_name); ?></td>
                        <td>
                            <a href="<?php echo get_edit_post_link($post->ID); ?>" class="button" target="_blank">Edit</a>
                            <?php if ($has_thumbnail): ?>
                                <button class="button replace-image" data-post-id="<?php echo $post->ID; ?>" style="margin-top: 3px;">Replace Image</button>
                            <?php else: ?>
                                <button class="button add-single-image" data-post-id="<?php echo $post->ID; ?>" style="margin-top: 3px;">Add Image</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($scheduled_count > 20): ?>
                <p style="color: #666; margin-top: 10px;">Showing 20 of <?php echo $scheduled_count; ?> scheduled posts</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Queue Section -->
    <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Article Queue (<?php echo $queue_count; ?> files)</h2>
        
        <?php if (empty($queued_files)): ?>
            <p style="color: #666;">No articles in queue. Upload .docx files above.</p>
        <?php else: ?>
            <?php
            // Calculate projected dates for each file
            $next_date = clone $last_scheduled_date;
            if ($scheduled_count > 0) {
                $next_date->modify('+' . $publish_frequency . ' days');
            }
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th style="width: 80px;">Size</th>
                        <th style="width: 150px;">Projected Date</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $file_index = 0;
                    foreach ($queued_files as $file): 
                        $file_name = basename($file);
                        $file_size = size_format(filesize($file));
                        
                        // Calculate this file's projected date
                        $projected = clone $next_date;
                        $projected->modify('+' . ($file_index * $publish_frequency) . ' days');
                        $file_index++;
                    ?>
                    <tr data-file="<?php echo esc_attr($file_name); ?>">
                        <td title="<?php echo esc_attr($file_name); ?>"><?php echo esc_html(wp_trim_words(pathinfo($file_name, PATHINFO_FILENAME), 8)); ?></td>
                        <td><?php echo esc_html($file_size); ?></td>
                        <td>
                            <span class="projected-date"><?php echo $projected->format('M j, Y'); ?></span>
                            <br><small style="color: #666;"><?php echo $publish_time; ?></small>
                        </td>
                        <td>
                            <button class="button process-article" data-file="<?php echo esc_attr($file_name); ?>">Process & Schedule</button>
                            <button class="button delete-file" data-file="<?php echo esc_attr($file_name); ?>" style="color: #dc3545;">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 15px;">
                <button class="button button-primary button-large" id="process-all">Process All Articles</button>
                <span id="process-status" style="margin-left: 15px;"></span>
            </div>
        <?php endif; ?>
    </div>
    
    <div id="processing-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 40px; border-radius: 8px; text-align: center; min-width: 300px;">
            <span class="spinner is-active" style="float: none; margin: 0 0 20px 0;"></span>
            <h2 style="margin: 0;">Processing Article</h2>
            <p id="modal-status" style="color: #666;">Please wait...</p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo $nonce; ?>';
    var publishFrequency = <?php echo $publish_frequency; ?>;
    var publishTime = '<?php echo $publish_time; ?>';
    var minScheduled = <?php echo $min_scheduled_posts; ?>;
    var currentScheduled = <?php echo $scheduled_count; ?>;
    var queueCount = <?php echo $queue_count; ?>;
    
    // Run Auto-Scheduler manually - processes one at a time
    $('#run-auto-scheduler').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Processing...');
        var needed = Math.min(minScheduled - currentScheduled, queueCount);
        var processed = 0;
        var skipped = 0;
        
        $('#processing-modal').show();
        
        function processNext() {
            if (processed >= needed) {
                var msg = 'Done! Scheduled ' + processed + ' articles.';
                if (skipped > 0) {
                    msg += ' (' + skipped + ' empty files removed)';
                }
                $('#modal-status').text(msg);
                setTimeout(function() {
                    location.reload();
                }, 1500);
                return;
            }
            
            $('#modal-status').text('Processing article ' + (processed + 1) + ' of ' + needed + '...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 120000, // 2 minute timeout
                data: {
                    action: 'lendcity_run_auto_scheduler_single',
                    nonce: nonce,
                    offset: processed
                },
                success: function(response) {
                    if (response.success) {
                        // Check if it was skipped (empty file)
                        if (response.data && response.data.skipped) {
                            skipped++;
                            $('#modal-status').text('Skipped empty file, continuing...');
                            // Don't increment processed, just continue to next file
                            processNext();
                        } else {
                            processed++;
                            processNext();
                        }
                    } else {
                        // If no more files or error, finish
                        if (response.data === 'No queued files') {
                            var msg = 'Done! Scheduled ' + processed + ' articles (queue empty).';
                            if (skipped > 0) {
                                msg += ' (' + skipped + ' empty files removed)';
                            }
                            $('#modal-status').text(msg);
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $('#modal-status').text('Error on article ' + (processed + 1) + ': ' + response.data);
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = 'Request failed';
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out (>2 min)';
                    } else if (xhr.responseText) {
                        errorMsg = 'Server error: ' + xhr.status;
                    }
                    $('#modal-status').text(errorMsg + ' on article ' + (processed + 1) + '. Processed ' + processed + ' so far.');
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                }
            });
        }
        
        processNext();
    });
    
    // Get next available date
    function getNextPublishDate(offset) {
        <?php if ($scheduled_count > 0): ?>
        var baseDate = new Date('<?php echo $last_scheduled_date->format('Y-m-d'); ?>');
        baseDate.setDate(baseDate.getDate() + publishFrequency + (offset * publishFrequency));
        <?php else: ?>
        var baseDate = new Date();
        baseDate.setDate(baseDate.getDate() + (offset * publishFrequency));
        <?php endif; ?>
        return baseDate.toISOString().split('T')[0] + 'T' + publishTime;
    }
    
    // Process single article
    $('.process-article').on('click', function() {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var fileName = $btn.data('file');
        var rowIndex = $row.index();
        var publishDate = getNextPublishDate(rowIndex);
        
        $('#processing-modal').show();
        $('#modal-status').text('Processing ' + fileName + '... (AI selecting category)');
        
        $.post(ajaxurl, {
            action: 'lendcity_process_article',
            nonce: nonce,
            file_name: fileName,
            publish_date: publishDate
        }, function(response) {
            if (response.success) {
                $('#modal-status').text('Article scheduled!');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                $('#processing-modal').hide();
                alert('Error: ' + response.data);
            }
        }).fail(function() {
            $('#processing-modal').hide();
            alert('Request failed');
        });
    });
    
    // Delete file
    $('.delete-file').on('click', function() {
        if (!confirm('Delete this file from the queue?')) return;
        
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var fileName = $btn.data('file');
        
        $.post(ajaxurl, {
            action: 'lendcity_delete_queued_file',
            nonce: nonce,
            file_name: fileName
        }, function(response) {
            if (response.success) {
                $row.fadeOut(function() { $(this).remove(); });
            } else {
                alert('Error deleting file');
            }
        });
    });
    
    // Process all
    $('#process-all').on('click', function() {
        var $rows = $('tr[data-file]');
        if ($rows.length === 0) return;
        
        if (!confirm('Process all ' + $rows.length + ' articles? This may take a while.')) return;
        
        var $btn = $(this);
        var $status = $('#process-status');
        $btn.prop('disabled', true);
        
        var queue = [];
        $rows.each(function(i) {
            queue.push({
                file: $(this).data('file'),
                date: getNextPublishDate(i)
            });
        });
        
        var total = queue.length;
        var processed = 0;
        var errors = 0;
        
        function processNext() {
            if (queue.length === 0) {
                $status.html('<span style="color: green;">Completed! ' + processed + ' processed, ' + errors + ' errors</span>');
                setTimeout(function() { location.reload(); }, 2000);
                return;
            }
            
            var item = queue.shift();
            processed++;
            $status.text('Processing ' + processed + '/' + total + '...');
            
            $.post(ajaxurl, {
                action: 'lendcity_process_article',
                nonce: nonce,
                file_name: item.file,
                publish_date: item.date
            }, function(response) {
                if (!response.success) errors++;
                setTimeout(processNext, 1000);
            }).fail(function() {
                errors++;
                setTimeout(processNext, 1000);
            });
        }
        
        processNext();
    });
    
    // Add single image to a post
    $('.add-single-image').on('click', function() {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        
        $btn.prop('disabled', true).text('Adding...');
        
        $.post(ajaxurl, {
            action: 'lendcity_add_unsplash_image',
            nonce: nonce,
            post_id: postId
        }, function(response) {
            if (response.success) {
                $btn.text('Done!').css('color', '#28a745');
                // Update the image cell
                var $row = $btn.closest('tr');
                $row.find('td:first').html('<img src="' + response.data.thumb_url + '" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">');
                setTimeout(function() { $btn.remove(); }, 1500);
            } else {
                alert('Error: ' + (response.data || 'Failed to add image'));
                $btn.prop('disabled', false).text('Add Image');
            }
        }).fail(function() {
            alert('Request failed');
            $btn.prop('disabled', false).text('Add Image');
        });
    });
    
    // Replace existing image
    $('.replace-image').on('click', function() {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        
        if (!confirm('Replace the current featured image with a new one from Unsplash?')) {
            return;
        }
        
        $btn.prop('disabled', true).text('Replacing...');
        
        $.post(ajaxurl, {
            action: 'lendcity_replace_unsplash_image',
            nonce: nonce,
            post_id: postId
        }, function(response) {
            if (response.success && response.data && response.data.thumb_url) {
                $btn.text('Done!').css('color', '#28a745');
                // Update the image
                var $row = $btn.closest('tr');
                $row.find('td:first img').attr('src', response.data.thumb_url + '?t=' + Date.now());
                setTimeout(function() { 
                    $btn.text('Replace Image').css('color', '').prop('disabled', false);
                }, 1500);
            } else {
                var errorMsg = response.data || 'Unknown error';
                if (typeof response.data === 'object') {
                    errorMsg = JSON.stringify(response.data);
                }
                alert('Error: ' + errorMsg);
                $btn.prop('disabled', false).text('Replace Image');
            }
        }).fail(function(xhr, status, error) {
            alert('Request failed: ' + error);
            $btn.prop('disabled', false).text('Replace Image');
        });
    });
    
    // Add images to all scheduled posts without images
    $('#add-images-scheduled').on('click', function() {
        var $btn = $(this);
        var $progress = $('#image-progress');
        var $status = $('#image-status');
        var $count = $('#image-count');
        var $bar = $('#image-bar');
        
        // Find posts without images
        var queue = [];
        $('tr[data-post-id]').each(function() {
            var $row = $(this);
            var hasImage = $row.find('img').length > 0;
            if (!hasImage) {
                queue.push($row.data('post-id'));
            }
        });
        
        if (queue.length === 0) {
            alert('All visible scheduled posts already have images!');
            return;
        }
        
        if (!confirm('Add Unsplash images to ' + queue.length + ' posts without featured images?')) {
            return;
        }
        
        $btn.prop('disabled', true);
        $progress.show();
        
        var total = queue.length;
        var processed = 0;
        var success = 0;
        
        function processNext() {
            if (queue.length === 0) {
                $status.html('<span style="color: green;">Done! Added images to ' + success + ' of ' + total + ' posts</span>');
                $btn.prop('disabled', false);
                setTimeout(function() { location.reload(); }, 2000);
                return;
            }
            
            var postId = queue.shift();
            processed++;
            
            $status.text('Adding image to post ' + processed + ' of ' + total + '...');
            $count.text(processed + '/' + total);
            $bar.css('width', (processed / total * 100) + '%');
            
            $.post(ajaxurl, {
                action: 'lendcity_add_unsplash_image',
                nonce: nonce,
                post_id: postId
            }, function(response) {
                if (response.success) {
                    success++;
                    // Update the row's image
                    var $row = $('tr[data-post-id="' + postId + '"]');
                    $row.find('td:first').html('<img src="' + response.data.thumb_url + '" style="width: 50px; height: 50px; object-fit: cover; border-radius: 4px;">');
                    $row.find('.add-single-image').remove();
                }
                setTimeout(processNext, 1500); // Delay to respect Unsplash rate limits
            }).fail(function() {
                setTimeout(processNext, 1500);
            });
        }
        
        processNext();
    });
});
</script>
