<?php
/**
 * Dashboard Page - v9.2
 */

if (!defined('ABSPATH')) {
    exit;
}

$smart_linker = new LendCity_Smart_Linker();
$catalog_stats = $smart_linker->get_catalog_stats();
$all_links = $smart_linker->get_all_site_links();
$queue_status = $smart_linker->get_queue_status();

// Count posts/pages
$total_posts = wp_count_posts('post')->publish;
$total_pages = wp_count_posts('page')->publish;

// Article queue
$queue_dir = wp_upload_dir()['basedir'] . '/lendcity-article-queue';
$queued_files = file_exists($queue_dir) ? glob($queue_dir . '/*.docx') : array();
$scheduled_posts = get_posts(array('post_status' => 'future', 'posts_per_page' => -1));

// Get publishing frequency
$publish_frequency = get_option('lendcity_article_frequency', 3);
$min_scheduled_posts = get_option('lendcity_min_scheduled_posts', 20);

// Calculate content runway
$total_content_days = (count($queued_files) + count($scheduled_posts)) * $publish_frequency;
$runway_years = round($total_content_days / 365, 1);
$runway_months = round($total_content_days / 30, 1);

// Links stats
$links_to_pages = 0;
$links_to_posts = 0;
foreach ($all_links as $link) {
    if (!empty($link['is_page'])) {
        $links_to_pages++;
    } else {
        $links_to_posts++;
    }
}
?>

<div class="wrap">
    <h1>LendCity Claude Dashboard <span style="font-size: 14px; color: #666;">v9.2</span></h1>
    <p>AI-powered Smart Linker, Article Scheduler, and Bulk Metadata</p>
    
    <!-- Content Runway Banner -->
    <div style="background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 4px; padding: 20px; margin: 20px 0; color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <div>
                <h2 style="margin: 0; color: white; font-size: 18px;">Content Runway</h2>
                <p style="margin: 5px 0 0; opacity: 0.9;">Based on <?php echo $publish_frequency; ?>-day publishing frequency</p>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 36px; font-weight: bold;">
                    <?php echo $runway_years >= 1 ? $runway_years . ' years' : $runway_months . ' months'; ?>
                </div>
                <div style="opacity: 0.9; font-size: 14px;">
                    <?php echo count($queued_files); ?> queued + <?php echo count($scheduled_posts); ?> scheduled = <?php echo count($queued_files) + count($scheduled_posts); ?> articles
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 30px 0;">
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px; color: #666;">Content Catalog</h3>
            <div style="font-size: 36px; font-weight: 600; color: #2271b1;"><?php echo $catalog_stats['total']; ?></div>
            <div style="font-size: 13px; color: #666;">Items Indexed</div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 12px;">
                <?php echo $catalog_stats['pages']; ?> pages • <?php echo $catalog_stats['posts']; ?> posts
            </div>
        </div>
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px; color: #666;">Claude Links</h3>
            <div style="font-size: 36px; font-weight: 600; color: #2e7d32;"><?php echo count($all_links); ?></div>
            <div style="font-size: 13px; color: #666;">Links Created</div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 12px;">
                <?php echo $links_to_pages; ?> to pages • <?php echo $links_to_posts; ?> to posts
            </div>
        </div>
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px; color: #666;">Article Queue</h3>
            <div style="font-size: 36px; font-weight: 600; color: #9c27b0;"><?php echo count($queued_files); ?></div>
            <div style="font-size: 13px; color: #666;">Queued Articles</div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 12px;">
                <?php echo count($scheduled_posts); ?> scheduled (min: <?php echo $min_scheduled_posts; ?>)
            </div>
        </div>
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0; font-size: 14px; color: #666;">Site Content</h3>
            <div style="font-size: 36px; font-weight: 600; color: #f57c00;"><?php echo $total_posts + $total_pages; ?></div>
            <div style="font-size: 13px; color: #666;">Total Published</div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; font-size: 12px;">
                <?php echo $total_pages; ?> pages • <?php echo $total_posts; ?> posts
            </div>
        </div>
        
    </div>
    
    <!-- Smart Linker Banner -->
    <div style="background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 4px; padding: 25px; margin: 20px 0; color: white;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <div>
                <h2 style="margin: 0 0 10px 0; color: white;">🔗 Smart Linker</h2>
                <p style="margin: 0; opacity: 0.9;">AI-powered internal linking • Select targets, Claude finds sources</p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <?php if ($queue_status['pending'] > 0 || $queue_status['processing'] > 0): ?>
                    <div style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 4px;">
                        ⏳ <?php echo $queue_status['pending']; ?> pending • ✅ <?php echo $queue_status['complete']; ?> done
                    </div>
                <?php endif; ?>
                <a href="<?php echo admin_url('admin.php?page=lendcity-claude-smart-linker'); ?>" class="button button-large" style="background: white; color: #764ba2; border: none;">
                    Open Smart Linker
                </a>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0;">🔗 Recent Links</h3>
            <?php if (empty($all_links)): ?>
                <p style="color: #666;">No links created yet.</p>
            <?php else: ?>
                <ul style="margin: 0; padding: 0; list-style: none;">
                    <?php foreach (array_slice($all_links, 0, 5) as $link): ?>
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee; font-size: 13px;">
                            <code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;"><?php echo esc_html($link['anchor']); ?></code>
                            → <a href="<?php echo esc_url($link['url']); ?>" target="_blank"><?php echo esc_html(basename($link['url'])); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div style="background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0;">📅 Scheduled Posts</h3>
            <?php if (empty($scheduled_posts)): ?>
                <p style="color: #666;">No scheduled posts.</p>
            <?php else: ?>
                <ul style="margin: 0; padding: 0; list-style: none;">
                    <?php foreach (array_slice($scheduled_posts, 0, 5) as $post): ?>
                        <li style="padding: 8px 0; border-bottom: 1px solid #eee; font-size: 13px;">
                            <a href="<?php echo get_edit_post_link($post->ID); ?>"><?php echo esc_html($post->post_title); ?></a><br>
                            <small style="color: #666;"><?php echo date('M j, Y g:i A', strtotime($post->post_date)); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div style="background: #e7f3ff; border: 1px solid #2271b1; border-radius: 4px; padding: 20px;">
            <h3 style="margin: 0 0 15px 0;">⚡ Quick Actions</h3>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <a href="<?php echo admin_url('admin.php?page=lendcity-claude-smart-linker'); ?>" class="button button-primary" style="text-align: center;">🔗 Smart Linker</a>
                <a href="<?php echo admin_url('admin.php?page=lendcity-claude-bulk-metadata'); ?>" class="button" style="text-align: center;">📝 Bulk Metadata</a>
                <a href="<?php echo admin_url('admin.php?page=lendcity-claude-article-scheduler'); ?>" class="button" style="text-align: center;">📄 Article Scheduler</a>
                <a href="<?php echo admin_url('admin.php?page=lendcity-claude-settings'); ?>" class="button" style="text-align: center;">⚙️ Settings</a>
            </div>
        </div>
        
    </div>
</div>
