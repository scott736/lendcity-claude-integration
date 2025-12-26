<?php
/**
 * Smart Linker Admin Page v2.1
 * REVERSED LOGIC: Select a target, find posts to link FROM
 * Optimized for speed
 */

if (!defined('ABSPATH')) {
    exit;
}

$smart_linker = new LendCity_Smart_Linker();
$catalog = $smart_linker->get_catalog();
$catalog_stats = $smart_linker->get_catalog_stats();
$catalog_built_at = get_option('lendcity_post_catalog_built_at', '');
$auto_linking = get_option('lendcity_smart_linker_auto', 'yes');
$auto_seo = get_option('lendcity_auto_seo_metadata', 'yes');
$queue_status = $smart_linker->get_queue_status();

if (isset($_POST['save_smart_linker_settings']) && check_admin_referer('smart_linker_settings')) {
    update_option('lendcity_smart_linker_auto', isset($_POST['auto_linking']) ? 'yes' : 'no');
    update_option('lendcity_auto_seo_metadata', isset($_POST['auto_seo']) ? 'yes' : 'no');
    $auto_linking = get_option('lendcity_smart_linker_auto');
    $auto_seo = get_option('lendcity_auto_seo_metadata');
    echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
}

// Optimized queries - only fetch what's needed
$all_pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'all'));
$all_posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'all'));
$total_items = count($catalog);

// Get links with limit for initial display
$all_links = $smart_linker->get_all_site_links(100);
$total_links = $smart_linker->get_total_link_count();
?>

<div class="wrap">
    <h1>Smart Linker <span style="font-size: 14px; color: #666;">AI-Powered Internal Linking</span></h1>
    <p><strong>How it works:</strong> Select a target → Claude finds posts that should link TO it → Links inserted automatically.</p>
    
    <!-- Settings -->
    <div style="background: #f0f6fc; border: 1px solid #2271b1; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
        <form method="post" style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <?php wp_nonce_field('smart_linker_settings'); ?>
            <label><input type="checkbox" name="auto_linking" <?php checked($auto_linking, 'yes'); ?>> <strong>Auto-link new posts on publish</strong></label>
            <label><input type="checkbox" name="auto_seo" <?php checked($auto_seo, 'yes'); ?>> <strong>Auto-generate SEO title/description</strong></label>
            <button type="submit" name="save_smart_linker_settings" class="button">Save</button>
        </form>
    </div>
    
    <!-- Catalog -->
    <div style="background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">Content Catalog</h2>
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
            <?php if (!empty($catalog)): ?>
                <div style="background: #d4edda; padding: 15px; border-radius: 4px;">
                    <strong style="font-size: 24px;"><?php echo $catalog_stats['total']; ?></strong> items<br>
                    <small><?php echo $catalog_stats['pages']; ?> pages Page: + <?php echo $catalog_stats['posts']; ?> posts</small>
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; padding: 15px; border-radius: 4px;">⚠️ Catalog not built</div>
            <?php endif; ?>
            <div>
                <button type="button" id="build-catalog" class="button button-primary button-large">Build Catalog</button>
                <button type="button" id="clear-catalog" class="button button-large" style="color: #d63638;" <?php echo empty($catalog) ? 'disabled' : ''; ?>>Clear Catalog</button>
                <p class="description"><?php echo $total_items; ?> items • ~<?php echo ceil($total_items * 1.5 / 60); ?> min</p>
            </div>
        </div>
        <div id="catalog-progress" style="display: none;">
            <div style="background: #e0e0e0; height: 20px; border-radius: 4px;"><div id="catalog-bar" style="background: #2271b1; height: 100%; width: 0%;"></div></div>
            <p id="catalog-status"></p>
        </div>
    </div>
    
    <!-- Trust AI / Bulk Processing -->
    <div style="background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <h2 style="margin-top: 0; color: white;">Bulk Link Everything</h2>
        <p>Process all <?php echo $catalog_stats['posts']; ?> posts in your catalog. Scales to 1000+ posts without timeout!</p>
        
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" id="start-bulk-queue-btn" class="button button-large" style="background: white; color: #764ba2; border: none; font-weight: bold;" <?php echo empty($catalog) ? 'disabled' : ''; ?>>
                Start Bulk Processing
            </button>
            <button type="button" id="review-mode-btn" class="button button-large" style="background: rgba(255,255,255,0.9); color: #764ba2; border: none;" <?php echo empty($catalog) ? 'disabled' : ''; ?>>
                Review Mode — Approve Each
            </button>
            <button type="button" id="clear-queue-btn" class="button" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white;">Clear Queue</button>
        </div>
        
        <!-- Processing Options -->
        <div style="margin-top: 15px; background: rgba(255,255,255,0.2); padding: 10px 15px; border-radius: 4px;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: white; margin-bottom: 8px;">
                <input type="checkbox" id="skip-posts-with-links" checked>
                <strong>Skip posts that already have links</strong>
                <span style="opacity: 0.8; font-size: 12px;">(only process posts with 0 links)</span>
            </label>
        </div>
        
        <p style="margin-top: 10px; font-size: 13px; opacity: 0.9;">
            <strong>Bulk Processing:</strong> Processes in batches of 5. You can close the browser - just come back to check progress and click "Continue" if needed.<br>
            <strong>Review Mode:</strong> Shows suggested links for each target, you approve/skip each one.
        </p>
        
        <!-- Queue Status Panel -->
        <div id="queue-status-panel" style="display: <?php echo ($queue_status['state'] ?? '') === 'running' || ($queue_status['state'] ?? '') === 'paused' || ($queue_status['remaining'] ?? 0) > 0 ? 'block' : 'none'; ?>; margin-top: 15px; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong id="queue-state-label">
                    <?php 
                    $state = $queue_status['state'] ?? 'idle';
                    if ($state === 'running') echo 'Processing';
                    elseif ($state === 'paused') echo 'Paused';
                    elseif ($state === 'complete') echo 'Complete';
                    else echo 'Queued';
                    ?>
                </strong>
                <div style="display: flex; gap: 5px;">
                    <button type="button" id="pause-queue-btn" class="button" style="<?php echo $state !== 'running' ? 'display:none;' : ''; ?>">Pause</button>
                    <button type="button" id="resume-queue-btn" class="button button-primary" style="<?php echo $state !== 'paused' && ($queue_status['remaining'] ?? 0) === 0 ? 'display:none;' : ''; ?>">Continue</button>
                    <button type="button" id="stop-queue-btn" class="button" style="color: #dc3545;">Stop & Clear</button>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div style="background: #e0e0e0; border-radius: 4px; height: 24px; overflow: hidden; position: relative;">
                <?php 
                $total = max(1, $queue_status['total'] ?? 1);
                $processed = $queue_status['processed'] ?? 0;
                $percent = round(($processed / $total) * 100);
                ?>
                <div id="queue-progress-bar" style="background: linear-gradient(90deg, #667eea, #764ba2); height: 100%; width: <?php echo $percent; ?>%; transition: width 0.3s;"></div>
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; font-weight: bold; color: <?php echo $percent > 50 ? 'white' : '#333'; ?>;">
                    <span id="queue-progress-text"><?php echo $processed; ?> / <?php echo $total; ?> (<?php echo $percent; ?>%)</span>
                </div>
            </div>
            
            <!-- Stats -->
            <div style="margin-top: 10px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">
                <span>Post: <strong id="queue-links-created"><?php echo $queue_status['links_created'] ?? 0; ?></strong> links created</span>
                <span>Skipped: <strong id="queue-skipped"><?php echo $queue_status['skipped'] ?? 0; ?></strong> skipped</span>
                <span>Errors: <strong id="queue-errors"><?php echo $queue_status['errors'] ?? 0; ?></strong> errors</span>
                <span>⏳ <strong id="queue-remaining"><?php echo $queue_status['remaining'] ?? 0; ?></strong> remaining</span>
            </div>
            
            <!-- Current Post -->
            <div id="queue-current-post" style="margin-top: 10px; font-size: 12px; color: #666;">
                <?php if (!empty($queue_status['current_post'])): ?>
                    Currently processing: <?php echo esc_html($queue_status['current_post']); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Review Mode Panel (hidden by default) -->
    <div id="review-panel" style="display: none; background: white; border: 2px solid #667eea; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="margin: 0;">Review Mode</h2>
            <button type="button" id="cancel-review" class="button">✕ Cancel Review</button>
        </div>
        <div id="review-progress" style="background: #f0f6fc; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
            <strong>Target <span id="review-current">0</span> of <span id="review-total">0</span>:</strong> <span id="review-target-name">Loading...</span>
        </div>
        <div id="review-suggestions" style="margin-bottom: 15px;">
            <!-- Suggestions will be loaded here -->
        </div>
        <div id="review-actions" style="display: flex; gap: 10px;">
            <button type="button" id="review-approve-all" class="button button-primary">Approve All Shown</button>
            <button type="button" id="review-skip" class="button">Skip This Target</button>
            <button type="button" id="review-stop" class="button" style="color: #dc3545;">Stop Review</button>
        </div>
    </div>
    
    <!-- Smart Metadata v2 - Runs AFTER Linking -->
    <div style="background: linear-gradient(135deg, #f093fb, #f5576c); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
            <h2 style="margin: 0; color: white;">Smart SEO Metadata v2</h2>
            <span style="background: rgba(255,255,255,0.3); padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">PHASE 3</span>
        </div>
        <p style="margin-bottom: 5px;">
            <strong>Run this AFTER linking is complete.</strong> Uses enriched catalog data + inbound link anchor analysis for optimal SEO.
        </p>
        <p style="font-size: 12px; opacity: 0.9; margin-bottom: 15px;">
            Workflow: <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 4px;">1. Build Catalog</span> →
            <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 4px;">2. Run Bulk Linking</span> →
            <span style="background: rgba(255,255,255,0.4); padding: 2px 8px; border-radius: 4px; font-weight: bold;">3. Generate Smart Metadata</span>
        </p>

        <!-- What Smart Metadata Uses -->
        <div style="background: rgba(255,255,255,0.15); padding: 12px 15px; border-radius: 4px; margin-bottom: 15px; font-size: 13px;">
            <strong>Smart Metadata analyzes:</strong>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <li><strong>Inbound Link Anchors</strong> — How other content links TO this page (reveals search intent)</li>
                <li><strong>Catalog Data</strong> — Topic clusters, funnel stage, persona, semantic keywords</li>
                <li><strong>Content Format</strong> — Guide, comparison, calculator, etc. for appropriate title style</li>
                <li><strong>Geographic Targeting</strong> — Canadian regions/cities for local SEO</li>
            </ul>
        </div>

        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 300px;">
                <label><strong>Select Post/Page:</strong></label>
                <select id="smart-metadata-post-select" style="width: 100%; padding: 10px; margin-top: 5px; color: #333;">
                    <option value="">— Select post/page —</option>
                    <?php foreach ($all_posts as $p): ?>
                        <option value="<?php echo $p->ID; ?>"><?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($all_pages as $p): ?>
                        <option value="<?php echo $p->ID; ?>">[PAGE] <?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" id="generate-smart-metadata-btn" class="button button-large" style="background: white; color: #f5576c; border: none; font-weight: bold;">
                Generate Smart Metadata
            </button>
            <button type="button" id="bulk-smart-metadata-btn" class="button button-large" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white;">
                Bulk Generate for All Linked Content
            </button>
        </div>
        <div style="margin-top: 10px;">
            <label style="color: white; cursor: pointer;">
                <input type="checkbox" id="skip-existing-meta" checked style="margin-right: 5px;">
                <strong>Skip posts with existing SEO title/description</strong>
                <span style="opacity: 0.8;">(only process posts missing metadata)</span>
            </label>
        </div>

        <!-- Smart Metadata Result -->
        <div id="smart-metadata-result" style="display: none; margin-top: 15px; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333;">
            <h4 style="margin-top: 0;">Smart Metadata Generated</h4>
            <div id="smart-metadata-sources" style="font-size: 12px; color: #666; margin-bottom: 10px; padding: 8px; background: #f5f5f5; border-radius: 4px;">
                <strong>Data Sources Used:</strong>
                <span id="smart-meta-catalog-badge" style="display: inline-block; margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 11px;"></span>
                <span id="smart-meta-inbound-badge" style="display: inline-block; margin-left: 4px; padding: 2px 6px; border-radius: 3px; font-size: 11px;"></span>
                <span id="smart-meta-outbound-badge" style="display: inline-block; margin-left: 4px; padding: 2px 6px; border-radius: 3px; font-size: 11px;"></span>
            </div>
            <div id="smart-meta-reasoning" style="display: none; font-size: 12px; color: #666; margin-bottom: 10px; padding: 8px; background: #e8f4ff; border-radius: 4px; border-left: 3px solid #2271b1;">
                <strong>AI Reasoning:</strong> <span id="smart-meta-reasoning-text"></span>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr style="border-bottom: 1px solid #ddd;">
                    <th style="text-align: left; padding: 8px; width: 140px;"></th>
                    <th style="text-align: left; padding: 8px; color: #999;">Before</th>
                    <th style="text-align: left; padding: 8px; color: #28a745;">After</th>
                </tr>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 8px; font-weight: bold;">SEO Title</td>
                    <td style="padding: 8px; color: #999;"><span id="smart-meta-title-before">—</span></td>
                    <td style="padding: 8px; color: #28a745;"><span id="smart-meta-title-after"></span></td>
                </tr>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 8px; font-weight: bold;">Description</td>
                    <td style="padding: 8px; color: #999;"><span id="smart-meta-desc-before">—</span></td>
                    <td style="padding: 8px; color: #28a745;"><span id="smart-meta-desc-after"></span></td>
                </tr>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 8px; font-weight: bold;">Focus Keyphrase</td>
                    <td style="padding: 8px; color: #999;"><span id="smart-meta-keyphrase-before">—</span></td>
                    <td style="padding: 8px; color: #28a745;"><span id="smart-meta-keyphrase-after"></span></td>
                </tr>
                <tr>
                    <td style="padding: 8px; font-weight: bold;">Tags</td>
                    <td style="padding: 8px; color: #999;"><span id="smart-meta-tags-before">—</span></td>
                    <td style="padding: 8px; color: #28a745;"><span id="smart-meta-tags-after"></span></td>
                </tr>
            </table>
            <p style="color: #28a745; margin: 10px 0 0; font-size: 13px;">Saved to SEOPress automatically</p>
        </div>

        <!-- Bulk Progress -->
        <div id="smart-metadata-bulk-progress" style="display: none; margin-top: 15px; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong id="smart-bulk-state">Processing...</strong>
                <button type="button" id="smart-bulk-stop" class="button" style="color: #dc3545;">Stop</button>
            </div>
            <div style="background: #e0e0e0; height: 20px; border-radius: 4px; overflow: hidden;">
                <div id="smart-metadata-bulk-bar" style="background: linear-gradient(90deg, #f093fb, #f5576c); height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <p id="smart-metadata-bulk-status" style="margin: 10px 0 0;">Initializing...</p>
            <div id="smart-metadata-bulk-log" style="margin-top: 10px; max-height: 200px; overflow-y: auto; font-size: 12px; font-family: monospace;"></div>
        </div>
    </div>

    <!-- SEO Settings: Priority Pages & Keywords -->
    <div style="background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">SEO Settings: Priority Pages & Keywords</h2>
        <p style="color: #666; margin-bottom: 15px;">Set priority levels and target keywords for your pages. Higher priority pages get more links. Keywords are used as preferred anchor text.</p>
        
        <div style="overflow-x: auto;">
            <table class="wp-list-table widefat fixed striped" id="priority-pages-table">
                <thead>
                    <tr>
                        <th style="width: 250px;">Page</th>
                        <th style="width: 120px;">Priority</th>
                        <th style="width: 300px;">Target Keywords (comma-separated)</th>
                        <th style="width: 100px;">Inbound Links</th>
                        <th style="width: 80px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $priority_pages = $smart_linker->get_priority_pages();
                    foreach ($priority_pages as $page): 
                    ?>
                    <tr data-page-id="<?php echo $page['id']; ?>">
                        <td>
                            <a href="<?php echo esc_url($page['url']); ?>" target="_blank"><?php echo esc_html($page['title']); ?></a>
                        </td>
                        <td>
                            <select class="page-priority" style="width: 100%;">
                                <option value="1" <?php selected($page['priority'], 1); ?>>1 - Low</option>
                                <option value="2" <?php selected($page['priority'], 2); ?>>2</option>
                                <option value="3" <?php selected($page['priority'], 3); ?>>3 - Normal</option>
                                <option value="4" <?php selected($page['priority'], 4); ?>>4</option>
                                <option value="5" <?php selected($page['priority'], 5); ?>>5 - High</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" class="page-keywords" value="<?php echo esc_attr($page['keywords']); ?>" placeholder="mortgage broker, investment loans..." style="width: 100%;">
                        </td>
                        <td style="text-align: center;">
                            <span class="inbound-count" style="font-size: 18px; font-weight: bold; color: <?php echo $page['inbound_links'] == 0 ? '#dc3545' : ($page['inbound_links'] < 3 ? '#ffc107' : '#28a745'); ?>;">
                                <?php echo $page['inbound_links']; ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="button save-page-seo">Save</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Link Gap Analysis -->
    <div style="background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">Link Gap Analysis</h2>
        <p style="color: #666; margin-bottom: 15px;">Content with few or no inbound internal links. These are opportunities to improve SEO.</p>
        
        <?php $link_stats = $smart_linker->get_link_stats(); ?>
        
        <!-- Stats Overview -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="background: #fff5f5; border: 1px solid #dc3545; padding: 15px; border-radius: 4px; text-align: center; min-width: 120px;">
                <div style="font-size: 28px; font-weight: bold; color: #dc3545;"><?php echo $link_stats['zero_links']; ?></div>
                <div style="font-size: 12px; color: #666;">Zero Links</div>
                <div style="font-size: 10px; color: #999;"><?php echo $link_stats['pages_zero']; ?> pages, <?php echo $link_stats['posts_zero']; ?> posts</div>
            </div>
            <div style="background: #fff8e1; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; text-align: center; min-width: 120px;">
                <div style="font-size: 28px; font-weight: bold; color: #ff9800;"><?php echo $link_stats['one_to_three']; ?></div>
                <div style="font-size: 12px; color: #666;">1-3 Links</div>
            </div>
            <div style="background: #e8f5e9; border: 1px solid #4caf50; padding: 15px; border-radius: 4px; text-align: center; min-width: 120px;">
                <div style="font-size: 28px; font-weight: bold; color: #4caf50;"><?php echo $link_stats['four_to_ten']; ?></div>
                <div style="font-size: 12px; color: #666;">4-10 Links</div>
            </div>
            <div style="background: #e3f2fd; border: 1px solid #2196f3; padding: 15px; border-radius: 4px; text-align: center; min-width: 120px;">
                <div style="font-size: 28px; font-weight: bold; color: #2196f3;"><?php echo $link_stats['over_ten']; ?></div>
                <div style="font-size: 12px; color: #666;">10+ Links</div>
            </div>
        </div>
        
        <!-- Gap Table -->
        <h3>Content Needing More Links</h3>
        <?php $gaps = $smart_linker->get_link_gaps(0, 2); ?>
        <?php if (empty($gaps)): ?>
            <p style="color: #28a745;">Great! All content has at least 3 inbound links.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th style="width: 80px;">Type</th>
                        <th style="width: 100px;">Inbound Links</th>
                        <th style="width: 100px;">Priority</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($gaps, 0, 20) as $gap): ?>
                    <tr>
                        <td><a href="<?php echo esc_url($gap['url']); ?>" target="_blank"><?php echo esc_html($gap['title']); ?></a></td>
                        <td><?php echo $gap['type'] === 'page' ? 'Page' : 'Post'; ?></td>
                        <td style="text-align: center;">
                            <span style="font-weight: bold; color: <?php echo $gap['inbound_links'] == 0 ? '#dc3545' : '#ffc107'; ?>;">
                                <?php echo $gap['inbound_links']; ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($gap['type'] === 'page'): ?>
                                <?php echo $gap['priority']; ?>/5
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($gaps) > 20): ?>
                <p style="color: #666; margin-top: 10px;">Showing 20 of <?php echo count($gaps); ?> items with link gaps.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Manage -->
    <div style="background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
        <h2 style="margin-top: 0;">🔧 Manage Links</h2>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 280px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <h4 style="margin-top: 0;">Update URL Site-wide</h4>
                <input type="text" id="old-url" placeholder="/old-slug/" style="width: 100%; padding: 8px; margin-bottom: 8px;">
                <input type="text" id="new-url" placeholder="/new-slug/" style="width: 100%; padding: 8px; margin-bottom: 8px;">
                <button type="button" id="update-urls-btn" class="button">Update All</button>
            </div>
            <div style="flex: 1; min-width: 280px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <h4 style="margin-top: 0;">Remove Links from Post</h4>
                <select id="post-with-links" style="width: 100%; padding: 8px; margin-bottom: 8px;">
                    <option value="">— Select —</option>
                    <?php foreach ($all_posts as $p): $l = $smart_linker->get_post_links($p->ID); if (!empty($l)): ?>
                        <option value="<?php echo $p->ID; ?>"><?php echo esc_html($p->post_title); ?> (<?php echo count($l); ?>)</option>
                    <?php endif; endforeach; ?>
                </select>
                <button type="button" id="remove-all-btn" class="button" style="color: #dc3545;">Remove All from Post</button>
            </div>
            <div style="flex: 1; min-width: 280px; padding: 15px; background: #fff5f5; border: 1px solid #dc3545; border-radius: 4px;">
                <h4 style="margin-top: 0; color: #dc3545;">⚠️ Delete ALL Links Site-wide</h4>
                <p style="margin: 0 0 10px; font-size: 13px; color: #666;">Remove all <?php echo $total_links; ?> Claude-generated links from your entire site.</p>
                <button type="button" id="delete-all-links-btn" class="button" style="background: #dc3545; color: white; border-color: #dc3545;">🗑️ Delete ALL Links (<?php echo $total_links; ?>)</button>
            </div>
        </div>
    </div>
    
    <!-- All Links -->
    <div style="background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px;">
        <h2 style="margin-top: 0;">All Links (<?php echo $total_links; ?>)</h2>
        <?php if (empty($all_links)): ?>
            <p style="color: #666;">No links yet.</p>
        <?php else: ?>
            <!-- Pagination Controls -->
            <div id="links-pagination" style="margin-bottom: 15px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <label>Show: 
                        <select id="links-per-page" style="padding: 5px;">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                        </select>
                    </label>
                </div>
                <div>
                    <button type="button" class="button" id="links-prev" disabled>← Previous</button>
                    <span id="links-page-info" style="margin: 0 10px;">Page 1 of <?php echo ceil($total_links / 50); ?></span>
                    <button type="button" class="button" id="links-next" <?php echo $total_links <= 50 ? 'disabled' : ''; ?>>Next →</button>
                </div>
                <div>
                    <input type="text" id="links-search" placeholder="Search links..." style="padding: 5px; width: 200px;">
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped" id="all-links-table">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="source" style="cursor: pointer;">Source Post <span class="sort-arrow">↕</span></th>
                        <th class="sortable" data-sort="anchor" style="cursor: pointer;">Anchor <span class="sort-arrow">↕</span></th>
                        <th class="sortable" data-sort="target" style="cursor: pointer;">Target (click to change) <span class="sort-arrow">↕</span></th>
                        <th class="sortable" data-sort="type" style="cursor: pointer;">Type <span class="sort-arrow">↕</span></th>
                        <th style="width: 80px;">Action</th>
                    </tr>
                </thead>
                <tbody id="links-tbody">
                    <?php foreach (array_slice($all_links, 0, 50) as $link): ?>
                        <tr data-link-id="<?php echo esc_attr($link['link_id']); ?>" 
                            data-source-id="<?php echo esc_attr($link['source_post_id']); ?>" 
                            data-current-url="<?php echo esc_attr($link['url']); ?>"
                            data-source-title="<?php echo esc_attr(strtolower($link['source_post_title'])); ?>"
                            data-anchor="<?php echo esc_attr(strtolower($link['anchor'])); ?>"
                            data-target="<?php echo esc_attr(strtolower(str_replace(home_url(), '', $link['url']))); ?>"
                            data-type="<?php echo !empty($link['is_page']) ? 'page' : 'post'; ?>">
                            <td><a href="<?php echo get_edit_post_link($link['source_post_id']); ?>" target="_blank"><?php echo esc_html($link['source_post_title']); ?></a></td>
                            <td><code><?php echo esc_html($link['anchor']); ?></code></td>
                            <td class="target-cell">
                                <span class="target-display" style="cursor: pointer; color: #2271b1;" title="Click to change destination">
                                    <?php echo esc_html(str_replace(home_url(), '', $link['url'])); ?>
                                </span>
                                <a href="<?php echo esc_url($link['url']); ?>" target="_blank" title="View target page" class="view-target-link" style="margin-left: 8px; text-decoration: none; font-size: 16px;">🔗</a>
                                <div class="target-edit" style="display: none; margin-top: 8px;">
                                    <select class="target-select" style="width: 100%;">
                                        <option value="">— Select new target —</option>
                                        <optgroup label="Pages">
                                            <?php foreach ($all_pages as $p): ?>
                                                <option value="<?php echo esc_attr(get_permalink($p->ID)); ?>" <?php selected(get_permalink($p->ID), $link['url']); ?>>
                                                    <?php echo esc_html($p->post_title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                        <optgroup label="Posts">
                                            <?php foreach (array_slice($all_posts, 0, 100) as $p): ?>
                                                <option value="<?php echo esc_attr(get_permalink($p->ID)); ?>" <?php selected(get_permalink($p->ID), $link['url']); ?>>
                                                    <?php echo esc_html($p->post_title); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                    <div style="margin-top: 5px;">
                                        <button type="button" class="button button-primary save-target">Save</button>
                                        <button type="button" class="button cancel-target">Cancel</button>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo !empty($link['is_page']) ? 'Page' : 'Post'; ?></td>
                            <td>
                                <button type="button" class="button delete-single-link" style="color: #dc3545; padding: 2px 8px;">✕</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p id="links-showing" style="color: #666; margin-top: 10px;">Showing 1-<?php echo min(50, $total_links); ?> of <?php echo $total_links; ?> links</p>
        <?php endif; ?>
    </div>

    <!-- Meta Queue Panel (Persistent Background Processing) -->
    <?php $meta_queue_status = $smart_linker->get_meta_queue_status(); ?>
    <div id="meta-queue-panel" style="background: linear-gradient(135deg, #11998e, #38ef7d); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
            <h2 style="margin: 0; color: white;">Background SEO Queue</h2>
            <span style="background: rgba(255,255,255,0.3); padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">PERSISTENT</span>
        </div>
        <p style="margin-bottom: 15px; opacity: 0.9;">Start SEO metadata processing and close your browser — it continues in the background. Come back anytime to check progress.</p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
            <button type="button" id="start-meta-queue-btn" class="button button-large" style="background: white; color: #11998e; border: none; font-weight: bold;">
                Start Background Queue
            </button>
            <button type="button" id="clear-meta-queue-btn" class="button" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white;">
                Clear Queue
            </button>
        </div>

        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
            <label style="color: white; cursor: pointer;">
                <input type="checkbox" id="meta-queue-skip-existing" checked>
                Skip posts with existing SEO
            </label>
            <label style="color: white; cursor: pointer;">
                <input type="checkbox" id="meta-queue-only-linked" checked>
                Only process linked content
            </label>
        </div>

        <!-- Queue Status Display -->
        <div id="meta-queue-status-display" style="display: <?php echo $meta_queue_status['status'] !== 'idle' ? 'block' : 'none'; ?>; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong id="meta-queue-state"><?php echo ucfirst($meta_queue_status['status']); ?></strong>
                <span id="meta-queue-percent"><?php echo $meta_queue_status['percent']; ?>%</span>
            </div>
            <div style="background: #e0e0e0; height: 20px; border-radius: 4px; overflow: hidden;">
                <div id="meta-queue-progress-bar" style="background: linear-gradient(90deg, #11998e, #38ef7d); height: 100%; width: <?php echo $meta_queue_status['percent']; ?>%; transition: width 0.3s;"></div>
            </div>
            <div style="margin-top: 10px; display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">
                <span>Completed: <strong id="meta-queue-completed"><?php echo $meta_queue_status['completed']; ?></strong></span>
                <span>Pending: <strong id="meta-queue-pending"><?php echo $meta_queue_status['pending']; ?></strong></span>
                <span>Failed: <strong id="meta-queue-failed"><?php echo $meta_queue_status['failed']; ?></strong></span>
                <span>Total: <strong id="meta-queue-total"><?php echo $meta_queue_status['total']; ?></strong></span>
            </div>
        </div>
    </div>

    <!-- SEO Health Monitor Panel -->
    <div style="background: linear-gradient(135deg, #f093fb, #f5576c); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
            <h2 style="margin: 0; color: white;">SEO Health Monitor</h2>
            <span style="background: rgba(255,255,255,0.3); padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">AI ALERTS</span>
        </div>
        <p style="margin-bottom: 15px; opacity: 0.9;">Detects when SEO metadata should be updated based on inbound link patterns. Posts with high-frequency anchors missing from titles are flagged.</p>

        <button type="button" id="scan-seo-health-btn" class="button button-large" style="background: white; color: #f5576c; border: none; font-weight: bold;">
            Scan for SEO Issues
        </button>

        <div id="seo-health-results" style="display: none; margin-top: 15px; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333; max-height: 400px; overflow-y: auto;">
            <div id="seo-health-loading" style="text-align: center; padding: 20px;">
                <span class="spinner is-active" style="float: none;"></span> Analyzing...
            </div>
            <div id="seo-health-content" style="display: none;"></div>
        </div>
    </div>

    <!-- Duplicate Anchor Detection Panel -->
    <div style="background: linear-gradient(135deg, #ff9a56, #ff6b6b); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
            <h2 style="margin: 0; color: white;">Duplicate Anchor Detection</h2>
            <span style="background: rgba(255,255,255,0.3); padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">SEO FIX</span>
        </div>
        <p style="margin-bottom: 5px; opacity: 0.9;">Finds anchor text used to link to multiple different pages — bad for SEO. Pages are prioritized; duplicates are removed from posts only.</p>
        <p style="margin-bottom: 15px; font-size: 12px; opacity: 0.8;">
            <strong>How it works:</strong> Same anchor → multiple URLs = confusing for search engines. We keep the page link, remove duplicate anchors from posts.
        </p>

        <button type="button" id="scan-duplicate-anchors-btn" class="button button-large" style="background: white; color: #ff6b6b; border: none; font-weight: bold;">
            Scan for Duplicate Anchors
        </button>

        <div id="duplicate-anchors-results" style="display: none; margin-top: 15px; background: rgba(255,255,255,0.95); padding: 15px; border-radius: 4px; color: #333; max-height: 400px; overflow-y: auto;">
            <div id="duplicate-anchors-loading" style="text-align: center; padding: 20px;">
                <span class="spinner is-active" style="float: none;"></span> Scanning...
            </div>
            <div id="duplicate-anchors-content" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- Select2 for searchable dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo wp_create_nonce('lendcity_claude_nonce'); ?>';
    
    // ========== TABLE SORTING ==========
    var currentSort = { column: null, direction: 'asc' };
    
    $('#all-links-table thead th.sortable').on('click', function() {
        var $th = $(this);
        var sortKey = $th.data('sort');
        var $table = $('#all-links-table');
        var $tbody = $table.find('tbody');
        var rows = $tbody.find('tr').get();
        
        // Determine sort direction
        if (currentSort.column === sortKey) {
            currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort.column = sortKey;
            currentSort.direction = 'asc';
        }
        
        // Update arrow indicators
        $table.find('.sort-arrow').text('↕');
        $th.find('.sort-arrow').text(currentSort.direction === 'asc' ? '↑' : '↓');
        
        // Map sort key to data attribute
        var dataAttr = {
            'source': 'source-title',
            'anchor': 'anchor',
            'target': 'target',
            'type': 'type'
        }[sortKey];
        
        // Sort rows
        rows.sort(function(a, b) {
            var aVal = $(a).data(dataAttr) || '';
            var bVal = $(b).data(dataAttr) || '';
            
            if (aVal < bVal) return currentSort.direction === 'asc' ? -1 : 1;
            if (aVal > bVal) return currentSort.direction === 'asc' ? 1 : -1;
            return 0;
        });
        
        // Re-append sorted rows
        $.each(rows, function(index, row) {
            $tbody.append(row);
        });
    });
    
    // ========== PAGINATION ==========
    var linksCurrentPage = 1;
    var linksPerPage = 50;
    var linksTotalLinks = <?php echo $total_links; ?>;
    var linksTotalPages = Math.ceil(linksTotalLinks / linksPerPage);
    
    function updatePagination() {
        linksTotalPages = Math.ceil(linksTotalLinks / linksPerPage);
        $('#links-page-info').text('Page ' + linksCurrentPage + ' of ' + linksTotalPages);
        $('#links-prev').prop('disabled', linksCurrentPage <= 1);
        $('#links-next').prop('disabled', linksCurrentPage >= linksTotalPages);
        
        var start = (linksCurrentPage - 1) * linksPerPage + 1;
        var end = Math.min(linksCurrentPage * linksPerPage, linksTotalLinks);
        $('#links-showing').text('Showing ' + start + '-' + end + ' of ' + linksTotalLinks + ' links');
    }
    
    function loadLinksPage(page) {
        linksCurrentPage = page;
        var offset = (page - 1) * linksPerPage;
        
        $('#links-tbody').css('opacity', '0.5');
        
        $.post(ajaxurl, {
            action: 'lendcity_get_links_page',
            nonce: nonce,
            offset: offset,
            limit: linksPerPage,
            search: $('#links-search').val()
        }, function(r) {
            if (r.success) {
                $('#links-tbody').html(r.data.html);
                linksTotalLinks = r.data.total;
                updatePagination();
            }
            $('#links-tbody').css('opacity', '1');
        });
    }
    
    $('#links-prev').on('click', function() {
        if (linksCurrentPage > 1) {
            loadLinksPage(linksCurrentPage - 1);
        }
    });
    
    $('#links-next').on('click', function() {
        if (linksCurrentPage < linksTotalPages) {
            loadLinksPage(linksCurrentPage + 1);
        }
    });
    
    $('#links-per-page').on('change', function() {
        linksPerPage = parseInt($(this).val());
        linksCurrentPage = 1;
        loadLinksPage(1);
    });
    
    var searchTimeout;
    $('#links-search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            linksCurrentPage = 1;
            loadLinksPage(1);
        }, 300);
    });
    
    // Delete single link from dashboard
    $(document).on('click', '.delete-single-link', function() {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var linkId = $row.data('link-id');
        var sourceId = $row.data('source-id');
        
        if (!confirm('Delete this link?')) return;
        
        $btn.prop('disabled', true).text('...');
        
        $.post(ajaxurl, {
            action: 'lendcity_remove_single_link',
            nonce: nonce,
            post_id: sourceId,
            link_id: linkId
        }, function(r) {
            if (r.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert('Error: ' + (r.data || 'Failed to delete'));
                $btn.prop('disabled', false).text('✕ Delete');
            }
        });
    });
    
    // Build Catalog
    $('#build-catalog').on('click', function() {
        if (!confirm('Build catalog for all posts and pages? This will take a few minutes for large sites.')) return;
        var $btn = $(this).prop('disabled', true).text('Loading...');
        $('#catalog-progress').show();
        $('#catalog-status').text('Fetching content list...');
        
        $.post(ajaxurl, {action: 'lendcity_get_all_content_ids', nonce: nonce}, function(r) {
            if (!r.success) { 
                alert('Error getting content: ' + (r.data || 'Unknown error')); 
                $btn.prop('disabled', false).text('Build Catalog'); 
                $('#catalog-progress').hide();
                return; 
            }
            var ids = r.data.ids, total = ids.length, current = 0, success = 0;
            var batchSize = 5; // Process 5 articles per API call (stays under 30k token limit)
            $btn.text('Building...');
            
            function processBatch() {
                if (current >= total) {
                    $('#catalog-bar').css('width', '100%');
                    $('#catalog-status').text('Done! ' + success + ' of ' + total + ' items indexed.');
                    setTimeout(function() { location.reload(); }, 2000);
                    return;
                }
                
                // Get next batch of IDs
                var batchIds = ids.slice(current, current + batchSize);
                var batchEnd = Math.min(current + batchSize, total);
                
                $('#catalog-bar').css('width', (current/total*100) + '%');
                $('#catalog-status').text('Processing ' + (current+1) + '-' + batchEnd + ' of ' + total + '...');
                
                $.post(ajaxurl, {
                    action: 'lendcity_build_catalog_batch', 
                    nonce: nonce, 
                    post_ids: batchIds
                }, function(resp) {
                    if (resp.success) {
                        success += resp.data.success;
                    }
                    current += batchSize;
                    setTimeout(processBatch, 1000); // 1 second delay between batches
                }).fail(function() {
                    current += batchSize;
                    setTimeout(processBatch, 1000);
                });
            }
            processBatch();
        }).fail(function(xhr, status, error) {
            alert('AJAX Error: ' + error);
            $btn.prop('disabled', false).text('Build Catalog');
            $('#catalog-progress').hide();
        });
    });
    
    // Clear Catalog
    $('#clear-catalog').on('click', function() {
        if (!confirm('Clear the entire catalog? You will need to rebuild it before using Smart Linker.')) return;
        var $btn = $(this).prop('disabled', true).text('Clearing...');
        
        $.post(ajaxurl, {action: 'lendcity_clear_catalog', nonce: nonce}, function(r) {
            if (r.success) {
                alert('Catalog cleared!');
                location.reload();
            } else {
                alert('Error: ' + (r.data || 'Unknown error'));
                $btn.prop('disabled', false).text('Clear Catalog');
            }
        }).fail(function() {
            alert('AJAX Error');
            $btn.prop('disabled', false).text('Clear Catalog');
        });
    });
    
    // Trust AI - Background
    $('#trust-ai-btn').on('click', function() {
        if (!confirm('Queue ALL items for background linking? Links will be auto-inserted.')) return;
        var $btn = $(this).prop('disabled', true).text('Queuing...');
        
        $.post(ajaxurl, {action: 'lendcity_queue_all_linking', nonce: nonce}, function(r) {
            if (r.success) {
                alert('Queued ' + r.data.queued + ' items! Processing will run in background.');
                location.reload();
            } else {
                alert('Error: ' + r.data);
                $btn.prop('disabled', false).text('Trust AI — Background');
            }
        });
    });
    
    // Review Mode
    var reviewQueue = [];
    var reviewIndex = 0;
    var currentSuggestions = [];
    
    $('#review-mode-btn').on('click', function() {
        // Build queue of all catalog items
        reviewQueue = [];
        <?php foreach ($catalog as $id => $entry): ?>
        reviewQueue.push({id: <?php echo $id; ?>, title: <?php echo json_encode($entry['title']); ?>, isPage: <?php echo !empty($entry['is_page']) ? 'true' : 'false'; ?>});
        <?php endforeach; ?>
        
        if (reviewQueue.length === 0) {
            alert('No items in catalog');
            return;
        }
        
        reviewIndex = 0;
        $('#review-panel').show();
        $('#review-total').text(reviewQueue.length);
        processReviewItem();
    });
    
    $('#cancel-review, #review-stop').on('click', function() {
        $('#review-panel').hide();
        reviewQueue = [];
    });
    
    $('#review-skip').on('click', function() {
        reviewIndex++;
        if (reviewIndex >= reviewQueue.length) {
            alert('Review complete!');
            $('#review-panel').hide();
            location.reload();
        } else {
            processReviewItem();
        }
    });
    
    function processReviewItem() {
        var item = reviewQueue[reviewIndex];
        $('#review-current').text(reviewIndex + 1);
        $('#review-target-name').html((item.isPage ? 'Page: ' : 'Post: ') + item.title);
        $('#review-suggestions').html('<p style="color: #666;">🔍 Finding link suggestions...</p>');
        $('#review-approve-all').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'lendcity_get_link_suggestions',
            nonce: nonce,
            target_id: item.id
        }, function(r) {
            if (r.success && r.data.suggestions && r.data.suggestions.length > 0) {
                currentSuggestions = r.data.suggestions;
                var html = '<table class="wp-list-table widefat striped"><thead><tr>';
                html += '<th style="width: 30px;"><input type="checkbox" id="select-all-suggestions" checked></th>';
                html += '<th>Source Post</th><th>Anchor Text</th></tr></thead><tbody>';
                
                r.data.suggestions.forEach(function(s, i) {
                    html += '<tr><td><input type="checkbox" class="suggestion-check" data-index="' + i + '" checked></td>';
                    html += '<td>' + s.source_title + '</td>';
                    html += '<td><input type="text" class="suggestion-anchor" data-index="' + i + '" value="' + s.anchor_text + '" style="width: 100%;"></td></tr>';
                });
                
                html += '</tbody></table>';
                html += '<p style="color: #666; margin-top: 10px;">✏️ You can edit anchor text before approving</p>';
                $('#review-suggestions').html(html);
                $('#review-approve-all').prop('disabled', false);
            } else {
                $('#review-suggestions').html('<p style="color: #666;">No link opportunities found for this target.</p>');
                currentSuggestions = [];
            }
        }).fail(function() {
            $('#review-suggestions').html('<p style="color: #dc3545;">Error fetching suggestions</p>');
        });
    }
    
    // Select all toggle
    $(document).on('change', '#select-all-suggestions', function() {
        $('.suggestion-check').prop('checked', $(this).is(':checked'));
    });
    
    // Approve selected
    $('#review-approve-all').on('click', function() {
        var selected = [];
        $('.suggestion-check:checked').each(function() {
            var idx = $(this).data('index');
            var anchor = $('.suggestion-anchor[data-index="' + idx + '"]').val();
            selected.push({
                source_id: currentSuggestions[idx].source_id,
                anchor_text: anchor
            });
        });
        
        if (selected.length === 0) {
            alert('No links selected');
            return;
        }
        
        var item = reviewQueue[reviewIndex];
        var $btn = $(this).prop('disabled', true).text('Inserting...');
        
        $.post(ajaxurl, {
            action: 'lendcity_insert_approved_links',
            nonce: nonce,
            target_id: item.id,
            links: JSON.stringify(selected)
        }, function(r) {
            $btn.text('Approve All Shown');
            if (r.success) {
                // Move to next
                reviewIndex++;
                if (reviewIndex >= reviewQueue.length) {
                    alert('Review complete! ' + r.data.inserted + ' links inserted for this target.');
                    $('#review-panel').hide();
                    location.reload();
                } else {
                    processReviewItem();
                }
            } else {
                alert('Error: ' + r.data);
                $btn.prop('disabled', false);
            }
        });
    });
    
    // ========== BACKGROUND QUEUE PROCESSING ==========
    var queueProcessing = false;
    var refreshInterval = null;
    
    // Start Bulk Queue
    $('#start-bulk-queue-btn').on('click', function() {
        var skipWithLinks = $('#skip-posts-with-links').is(':checked');
        
        if (!confirm('Start bulk processing? This will add internal links to all your posts.\n\nYou can close the browser and come back - just click "Continue" to resume.')) {
            return;
        }
        
        var $btn = $(this).prop('disabled', true).text('Initializing...');
        
        // Initialize the queue
        $.post(ajaxurl, {
            action: 'lendcity_init_bulk_queue',
            nonce: nonce,
            skip_with_links: skipWithLinks
        }, function(r) {
            $btn.prop('disabled', false).text('Start Bulk Processing');
            
            if (r.success) {
                if (r.data.queued === 0) {
                    if (r.data.skipped > 0) {
                        alert('All ' + r.data.skipped + ' posts already have links. Uncheck "Skip posts that already have links" to reprocess them.');
                    } else {
                        alert('No posts to process. Build the catalog first.');
                    }
                    return;
                }
                
                // Show status panel and start processing
                $('#queue-status-panel').show();
                updateQueueUI(r.data.queued, 0, 0, 0, r.data.skipped, r.data.queued, 'running', '');
                
                // Start processing
                startQueueProcessing();
            } else {
                alert('Error: ' + (r.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Start Bulk Processing');
            alert('Failed to initialize queue');
        });
    });
    
    // Resume/Continue button
    $('#resume-queue-btn').on('click', function() {
        startQueueProcessing();
    });
    
    // Pause button
    $('#pause-queue-btn').on('click', function() {
        queueProcessing = false;
        clearInterval(refreshInterval);
        
        $.post(ajaxurl, {
            action: 'lendcity_pause_queue',
            nonce: nonce
        }, function() {
            $('#queue-state-label').text('Paused');
            $('#pause-queue-btn').hide();
            $('#resume-queue-btn').show();
        });
    });
    
    // Stop & Clear button
    $('#stop-queue-btn').on('click', function() {
        if (!confirm('Stop processing and clear the queue?')) return;
        
        queueProcessing = false;
        clearInterval(refreshInterval);
        
        $.post(ajaxurl, {
            action: 'lendcity_clear_link_queue',
            nonce: nonce
        }, function() {
            location.reload();
        });
    });
    
    function startQueueProcessing() {
        queueProcessing = true;
        $('#queue-state-label').text('Processing');
        $('#pause-queue-btn').show();
        $('#resume-queue-btn').hide();
        
        processNextBatch();
    }
    
    function processNextBatch() {
        if (!queueProcessing) return;
        
        $.post(ajaxurl, {
            action: 'lendcity_process_queue_batch',
            nonce: nonce
        }, function(r) {
            if (r.success) {
                // Refresh status from server
                refreshQueueStatus();
                
                if (r.data.complete) {
                    // All done!
                    queueProcessing = false;
                    $('#queue-state-label').text('Complete!');
                    $('#pause-queue-btn').hide();
                    $('#resume-queue-btn').hide();
                } else if (queueProcessing) {
                    // Continue processing after 30 sec delay (full content = more tokens = need longer wait)
                    setTimeout(processNextBatch, 30000);
                }
            } else {
                // Error - pause
                queueProcessing = false;
                $('#queue-state-label').text('Errors: Error');
                $('#pause-queue-btn').hide();
                $('#resume-queue-btn').show();
            }
        }).fail(function() {
            // Network error - pause but allow retry
            queueProcessing = false;
            $('#queue-state-label').text('⚠️ Connection Lost');
            $('#pause-queue-btn').hide();
            $('#resume-queue-btn').show();
        });
    }
    
    function refreshQueueStatus() {
        $.post(ajaxurl, {
            action: 'lendcity_get_queue_status',
            nonce: nonce
        }, function(r) {
            if (r.success) {
                var d = r.data;
                updateQueueUI(
                    d.total || 0,
                    d.processed || 0,
                    d.links_created || 0,
                    d.errors || 0,
                    d.skipped || 0,
                    d.remaining || 0,
                    d.state || 'idle',
                    d.current_post || ''
                );
            }
        });
    }
    
    function updateQueueUI(total, processed, links, errors, skipped, remaining, state, currentPost) {
        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        
        $('#queue-progress-bar').css('width', percent + '%');
        $('#queue-progress-text').text(processed + ' / ' + total + ' (' + percent + '%)');
        $('#queue-links-created').text(links);
        $('#queue-errors').text(errors);
        $('#queue-skipped').text(skipped);
        $('#queue-remaining').text(remaining);
        
        if (currentPost) {
            $('#queue-current-post').text('Currently processing: ' + currentPost);
        } else {
            $('#queue-current-post').text('');
        }
        
        // Update state label
        if (state === 'running') {
            $('#queue-state-label').text('Processing');
        } else if (state === 'paused') {
            $('#queue-state-label').text('Paused');
        } else if (state === 'complete') {
            $('#queue-state-label').text('Complete!');
        }
    }
    
    // Auto-refresh status if queue is active on page load
    <?php if (($queue_status['state'] ?? '') === 'running'): ?>
    $(function() {
        // Resume processing if it was running
        startQueueProcessing();
    });
    <?php endif; ?>
    
    // Clear Queue
    $('#clear-queue-btn').on('click', function() {
        if (!confirm('Clear the queue?')) return;
        $.post(ajaxurl, {action: 'lendcity_clear_link_queue', nonce: nonce}, function() { location.reload(); });
    });
    
    // Update URLs
    $('#update-urls-btn').on('click', function() {
        var oldUrl = $('#old-url').val(), newUrl = $('#new-url').val();
        if (!oldUrl || !newUrl) { alert('Enter both URLs'); return; }
        
        $.post(ajaxurl, {action: 'lendcity_update_smart_link_urls', nonce: nonce, old_url: oldUrl, new_url: newUrl}, function(r) {
            alert(r.success ? 'Updated ' + r.data.updated_count + ' posts' : 'Error');
        });
    });
    
    // Remove All Links
    $('#remove-all-btn').on('click', function() {
        var postId = $('#post-with-links').val();
        if (!postId) { alert('Select a post'); return; }
        if (!confirm('Remove all Claude links from this post?')) return;
        
        $.post(ajaxurl, {action: 'lendcity_remove_all_smart_links', nonce: nonce, post_id: postId}, function() {
            alert('Links removed');
            location.reload();
        });
    });
    
    // Initialize Select2 for searchable dropdowns
    if (typeof $.fn.select2 !== 'undefined') {
        $('.target-select').select2({
            placeholder: 'Type to search...',
            allowClear: true,
            width: '100%'
        });
    }
    
    // Initialize Select2 on target selects
    function initTargetSelect2() {
        $('.target-select').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    width: '100%',
                    placeholder: 'Search for a page or post...'
                });
            }
        });
    }
    
    // Initialize on page load
    initTargetSelect2();
    
    // Click to edit target
    $(document).on('click', '.target-display', function(e) {
        e.preventDefault();
        var $cell = $(this).closest('.target-cell');
        $(this).hide();
        $cell.find('.view-target-link').hide();
        $cell.find('.target-edit').show();
        
        // Initialize select2 if not already
        var $select = $cell.find('.target-select');
        if (!$select.hasClass('select2-hidden-accessible')) {
            $select.select2({
                width: '100%',
                placeholder: 'Search for a page or post...'
            });
        }
        $select.select2('open');
    });
    
    // Cancel edit
    $(document).on('click', '.cancel-target', function() {
        var $cell = $(this).closest('.target-cell');
        $cell.find('.target-edit').hide();
        $cell.find('.target-display').show();
        $cell.find('.view-target-link').show();
    });
    
    // Save new target
    $(document).on('click', '.save-target', function() {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $cell = $btn.closest('.target-cell');
        var newUrl = $cell.find('.target-select').val();
        var linkId = $row.data('link-id');
        var sourceId = $row.data('source-id');
        var oldUrl = $row.data('current-url');
        
        if (!newUrl || newUrl === oldUrl) {
            $cell.find('.target-edit').hide();
            $cell.find('.target-display').show();
            $cell.find('.view-target-link').show();
            return;
        }
        
        $btn.prop('disabled', true).text('Saving...');
        
        $.post(ajaxurl, {
            action: 'lendcity_change_link_target',
            nonce: nonce,
            source_id: sourceId,
            link_id: linkId,
            old_url: oldUrl,
            new_url: newUrl
        }, function(r) {
            if (r.success) {
                // Update display
                var shortUrl = newUrl.replace('<?php echo home_url(); ?>', '');
                $cell.find('.target-display').text(shortUrl);
                $cell.find('.view-target-link').attr('href', newUrl);
                $row.data('current-url', newUrl);
                $cell.find('.target-edit').hide();
                $cell.find('.target-display').show();
                $cell.find('.view-target-link').show();
            } else {
                alert('Error: ' + r.data);
            }
            $btn.prop('disabled', false).text('Save');
        });
    });
    
    // Delete ALL Links Site-wide
    $('#delete-all-links-btn').on('click', function() {
        var count = <?php echo count($all_links); ?>;
        if (!confirm('⚠️ WARNING: This will permanently delete ALL ' + count + ' Claude-generated links from your entire site.\n\nThis action cannot be undone.\n\nAre you sure?')) return;
        if (!confirm('Please confirm AGAIN: Delete all ' + count + ' links?')) return;
        
        var $btn = $(this).prop('disabled', true).text('Deleting...');
        
        $.post(ajaxurl, {
            action: 'lendcity_delete_all_site_links',
            nonce: nonce
        }, function(r) {
            if (r.success) {
                alert('Deleted ' + r.data.deleted + ' links from ' + r.data.posts_affected + ' posts.');
                location.reload();
            } else {
                alert('Error: ' + r.data);
                $btn.prop('disabled', false).text('Delete ALL Links');
            }
        });
    });
    
    // Save Priority Page SEO Settings
    $(document).on('click', '.save-page-seo', function() {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var pageId = $row.data('page-id');
        var priority = $row.find('.page-priority').val();
        var keywords = $row.find('.page-keywords').val();
        
        $btn.prop('disabled', true).text('...');
        
        $.post(ajaxurl, {
            action: 'lendcity_save_page_seo',
            nonce: nonce,
            page_id: pageId,
            priority: priority,
            keywords: keywords
        }, function(r) {
            if (r.success) {
                $btn.text('Saved!').css('color', '#28a745');
                setTimeout(function() {
                    $btn.text('Save').css('color', '').prop('disabled', false);
                }, 1500);
            } else {
                alert('Error: ' + (r.data || 'Failed to save'));
                $btn.text('Save').prop('disabled', false);
            }
        }).fail(function() {
            alert('Request failed');
            $btn.text('Save').prop('disabled', false);
        });
    });

    // ========== SMART METADATA v2 ==========

    // Generate smart metadata for single post
    $('#generate-smart-metadata-btn').on('click', function() {
        var postId = $('#smart-metadata-post-select').val();
        if (!postId) {
            alert('Please select a post or page first');
            return;
        }

        var $btn = $(this).prop('disabled', true).text('Generating...');
        $('#smart-metadata-result').hide();

        $.post(ajaxurl, {
            action: 'lendcity_generate_smart_metadata',
            nonce: nonce,
            post_id: postId
        }, function(r) {
            if (r.success) {
                // Show data sources badges
                var d = r.data.data_sources;
                $('#smart-meta-catalog-badge')
                    .text(d.catalog_used ? 'Catalog' : 'No Catalog')
                    .css('background', d.catalog_used ? '#d4edda' : '#f8d7da')
                    .css('color', d.catalog_used ? '#155724' : '#721c24');
                $('#smart-meta-inbound-badge')
                    .text(d.inbound_anchors + ' Inbound')
                    .css('background', d.inbound_anchors > 0 ? '#d4edda' : '#fff3cd')
                    .css('color', d.inbound_anchors > 0 ? '#155724' : '#856404');
                $('#smart-meta-outbound-badge')
                    .text(d.outbound_anchors + ' Outbound')
                    .css('background', d.outbound_anchors > 0 ? '#d4edda' : '#e2e3e5')
                    .css('color', d.outbound_anchors > 0 ? '#155724' : '#383d41');

                // Show reasoning if available
                if (r.data.reasoning) {
                    $('#smart-meta-reasoning-text').text(r.data.reasoning);
                    $('#smart-meta-reasoning').show();
                } else {
                    $('#smart-meta-reasoning').hide();
                }

                // Show before/after values
                $('#smart-meta-title-before').text(r.data.before.title || '(empty)');
                $('#smart-meta-desc-before').text(r.data.before.description || '(empty)');
                $('#smart-meta-keyphrase-before').text(r.data.before.focus_keyphrase || '(empty)');
                $('#smart-meta-tags-before').text(r.data.before.tags || '(none)');

                $('#smart-meta-title-after').text(r.data.after.title);
                $('#smart-meta-desc-after').text(r.data.after.description);
                $('#smart-meta-keyphrase-after').text(r.data.after.focus_keyphrase);
                $('#smart-meta-tags-after').text(r.data.after.tags);

                $('#smart-metadata-result').show();
            } else {
                alert('Error: ' + (r.data || 'Failed to generate metadata'));
            }
            $btn.prop('disabled', false).text('Generate Smart Metadata');
        }).fail(function() {
            alert('Request failed');
            $btn.prop('disabled', false).text('Generate Smart Metadata');
        });
    });

    // Bulk smart metadata generation
    var smartBulkRunning = false;

    $('#bulk-smart-metadata-btn').on('click', function() {
        var skipExisting = $('#skip-existing-meta').is(':checked');
        var skipMsg = skipExisting ? '\n\nSkipping posts that already have SEO metadata.' : '';

        if (!confirm('Generate smart metadata for all posts and pages with internal links?' + skipMsg + '\n\nThis uses the enriched catalog + inbound link analysis for optimal SEO.\n\nMake sure you have:\n1. Built the catalog\n2. Run bulk linking\n\nContinue?')) {
            return;
        }

        var $btn = $(this).prop('disabled', true).text('Loading...');
        $('#smart-metadata-bulk-progress').show();
        $('#smart-metadata-result').hide();
        $('#smart-metadata-bulk-log').html('');
        $('#smart-bulk-state').text('Fetching posts...');

        // Get list of posts to process
        $.post(ajaxurl, {
            action: 'lendcity_get_smart_metadata_posts',
            nonce: nonce,
            only_linked: true,
            skip_existing: skipExisting
        }, function(r) {
            if (!r.success || r.data.posts.length === 0) {
                var msg = 'No posts to process.';
                if (r.data && r.data.skipped > 0) {
                    msg = 'All ' + r.data.skipped + ' posts already have SEO metadata.\n\nUncheck "Skip posts with existing SEO title/description" to reprocess them.';
                } else {
                    msg = 'No posts with links found. Run bulk linking first.';
                }
                alert(msg);
                $btn.prop('disabled', false).text('Bulk Generate for All Linked Content');
                $('#smart-metadata-bulk-progress').hide();
                return;
            }

            var posts = r.data.posts;
            var total = posts.length;
            var skippedCount = r.data.skipped || 0;
            var current = 0;
            var success = 0;
            var errors = 0;
            smartBulkRunning = true;

            $btn.text('Processing...');
            var stateText = 'Processing ' + total + ' items';
            if (skippedCount > 0) stateText += ' (' + skippedCount + ' skipped with existing meta)';
            $('#smart-bulk-state').text(stateText);

            function processNextSmartMeta() {
                if (!smartBulkRunning || current >= total) {
                    // Done
                    smartBulkRunning = false;
                    $('#smart-metadata-bulk-bar').css('width', '100%');
                    $('#smart-bulk-state').html('<span style="color: #28a745;">Complete! ' + success + ' updated, ' + errors + ' errors</span>');
                    $btn.prop('disabled', false).text('Bulk Generate for All Linked Content');
                    return;
                }

                var item = posts[current];
                var pct = (current / total * 100).toFixed(0);
                $('#smart-metadata-bulk-bar').css('width', pct + '%');
                $('#smart-metadata-bulk-status').text('Processing ' + (current + 1) + ' of ' + total + ': ' + item.title);

                $.post(ajaxurl, {
                    action: 'lendcity_generate_smart_metadata',
                    nonce: nonce,
                    post_id: item.id
                }, function(resp) {
                    if (resp.success) {
                        success++;
                        var sources = resp.data.data_sources;
                        var badges = '';
                        if (sources.catalog_used) badges += '<span style="background:#d4edda;color:#155724;padding:1px 4px;border-radius:2px;margin-right:3px;">Catalog</span>';
                        if (sources.inbound_anchors > 0) badges += '<span style="background:#cce5ff;color:#004085;padding:1px 4px;border-radius:2px;">' + sources.inbound_anchors + ' inbound</span>';

                        var logEntry = '<div style="color: #28a745;">✓ <strong>' + item.title + '</strong> ' + badges + '</div>';
                        $('#smart-metadata-bulk-log').prepend(logEntry);
                    } else {
                        errors++;
                        var logEntry = '<div style="color: #dc3545;">✗ ' + item.title + ': ' + (resp.data || 'Failed') + '</div>';
                        $('#smart-metadata-bulk-log').prepend(logEntry);
                    }
                    current++;
                    setTimeout(processNextSmartMeta, 500); // 500ms delay
                }).fail(function() {
                    errors++;
                    var logEntry = '<div style="color: #dc3545;">✗ ' + item.title + ': Request failed</div>';
                    $('#smart-metadata-bulk-log').prepend(logEntry);
                    current++;
                    setTimeout(processNextSmartMeta, 1000); // 1 sec on failure
                });
            }

            processNextSmartMeta();
        }).fail(function() {
            alert('Failed to get post list');
            $btn.prop('disabled', false).text('Bulk Generate for All Linked Content');
            $('#smart-metadata-bulk-progress').hide();
        });
    });

    // Stop bulk smart metadata
    $('#smart-bulk-stop').on('click', function() {
        smartBulkRunning = false;
        $('#smart-bulk-state').text('Stopped');
        $('#bulk-smart-metadata-btn').prop('disabled', false).text('Bulk Generate for All Linked Content');
    });

    // ========== META QUEUE (PERSISTENT BACKGROUND) ==========
    var metaQueuePolling = null;

    function updateMetaQueueUI(status) {
        $('#meta-queue-state').text(status.status.charAt(0).toUpperCase() + status.status.slice(1));
        $('#meta-queue-percent').text(status.percent + '%');
        $('#meta-queue-progress-bar').css('width', status.percent + '%');
        $('#meta-queue-completed').text(status.completed);
        $('#meta-queue-pending').text(status.pending);
        $('#meta-queue-failed').text(status.failed);
        $('#meta-queue-total').text(status.total);

        if (status.status !== 'idle' && status.total > 0) {
            $('#meta-queue-status-display').show();
        }

        if (status.status === 'completed' || status.status === 'idle') {
            if (metaQueuePolling) {
                clearInterval(metaQueuePolling);
                metaQueuePolling = null;
            }
            $('#start-meta-queue-btn').prop('disabled', false).text('Start Background Queue');
        }
    }

    function pollMetaQueueStatus() {
        $.post(ajaxurl, {
            action: 'lendcity_get_meta_queue_status',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                updateMetaQueueUI(response.data);
            }
        });
    }

    // Start meta queue
    $('#start-meta-queue-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Starting...');

        $.post(ajaxurl, {
            action: 'lendcity_init_meta_queue',
            nonce: nonce,
            skip_existing: $('#meta-queue-skip-existing').is(':checked') ? 'true' : 'false',
            only_linked: $('#meta-queue-only-linked').is(':checked') ? 'true' : 'false'
        }, function(response) {
            if (response.success) {
                $('#meta-queue-status-display').show();
                updateMetaQueueUI({ status: 'running', percent: 0, completed: 0, pending: response.data.total, failed: 0, total: response.data.total });

                // Start polling
                if (!metaQueuePolling) {
                    metaQueuePolling = setInterval(pollMetaQueueStatus, 5000);
                }
                $btn.text('Processing...');
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('Start Background Queue');
            }
        }).fail(function() {
            alert('Request failed');
            $btn.prop('disabled', false).text('Start Background Queue');
        });
    });

    // Clear meta queue
    $('#clear-meta-queue-btn').on('click', function() {
        if (!confirm('Clear the metadata queue?')) return;

        $.post(ajaxurl, {
            action: 'lendcity_clear_meta_queue',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                if (metaQueuePolling) {
                    clearInterval(metaQueuePolling);
                    metaQueuePolling = null;
                }
                $('#meta-queue-status-display').hide();
                $('#start-meta-queue-btn').prop('disabled', false).text('Start Background Queue');
            }
        });
    });

    // Check queue status on page load
    if ($('#meta-queue-status-display').is(':visible')) {
        pollMetaQueueStatus();
        metaQueuePolling = setInterval(pollMetaQueueStatus, 5000);
    }

    // ========== SEO HEALTH MONITOR ==========
    $('#scan-seo-health-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Scanning...');
        $('#seo-health-results').show();
        $('#seo-health-loading').show();
        $('#seo-health-content').hide();

        $.post(ajaxurl, {
            action: 'lendcity_get_seo_health_issues',
            nonce: nonce
        }, function(response) {
            $('#seo-health-loading').hide();
            $('#seo-health-content').show();
            $btn.prop('disabled', false).text('Scan for SEO Issues');

            if (response.success && response.data.issues.length > 0) {
                var html = '<p style="margin-top: 0;"><strong>' + response.data.count + ' SEO issues found</strong></p>';
                html += '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                html += '<thead><tr><th>Page/Post</th><th>Issues</th><th>Top Anchors</th><th>Action</th></tr></thead><tbody>';

                response.data.issues.forEach(function(issue) {
                    var severityColor = issue.severity === 'high' ? '#dc3545' : '#ffc107';
                    var anchors = Object.keys(issue.top_anchors).slice(0, 3).join(', ');

                    html += '<tr data-post-id="' + issue.post_id + '">';
                    html += '<td><a href="' + issue.url + '" target="_blank">' + issue.post_title + '</a>';
                    html += '<span style="font-size: 11px; color: #666; margin-left: 5px;">(' + issue.post_type + ')</span></td>';
                    html += '<td>';
                    issue.suggestions.forEach(function(s) {
                        html += '<div style="font-size: 12px; color: ' + severityColor + '; margin-bottom: 3px;">• ' + s + '</div>';
                    });
                    html += '</td>';
                    html += '<td style="font-size: 12px; color: #666;">' + anchors + '</td>';
                    html += '<td><button type="button" class="button auto-fix-seo-btn" data-post-id="' + issue.post_id + '">Auto Fix</button></td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $('#seo-health-content').html(html);
            } else {
                $('#seo-health-content').html('<p style="color: #28a745; margin: 0;">✓ No SEO issues detected. All content looks healthy!</p>');
            }
        }).fail(function() {
            $('#seo-health-loading').hide();
            $('#seo-health-content').show().html('<p style="color: #dc3545;">Failed to scan. Please try again.</p>');
            $btn.prop('disabled', false).text('Scan for SEO Issues');
        });
    });

    // Auto-fix SEO
    $(document).on('click', '.auto-fix-seo-btn', function() {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        $btn.prop('disabled', true).text('Fixing...');

        $.post(ajaxurl, {
            action: 'lendcity_auto_fix_seo',
            nonce: nonce,
            post_id: postId
        }, function(response) {
            if (response.success) {
                $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
            } else {
                alert('Error: ' + response.data);
                $btn.prop('disabled', false).text('Auto Fix');
            }
        }).fail(function() {
            alert('Request failed');
            $btn.prop('disabled', false).text('Auto Fix');
        });
    });

    // ========== DUPLICATE ANCHOR DETECTION ==========
    $('#scan-duplicate-anchors-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Scanning...');
        $('#duplicate-anchors-results').show();
        $('#duplicate-anchors-loading').show();
        $('#duplicate-anchors-content').hide();

        $.post(ajaxurl, {
            action: 'lendcity_get_duplicate_anchors',
            nonce: nonce
        }, function(response) {
            $('#duplicate-anchors-loading').hide();
            $('#duplicate-anchors-content').show();
            $btn.prop('disabled', false).text('Scan for Duplicate Anchors');

            if (response.success && response.data.duplicates.length > 0) {
                var html = '<p style="margin-top: 0;"><strong>' + response.data.count + ' duplicate anchors found</strong></p>';
                html += '<p style="font-size: 12px; color: #666; margin-bottom: 15px;">Pages are prioritized — duplicates will be removed from posts only.</p>';
                html += '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                html += '<thead><tr><th>Anchor Text</th><th>Used For</th><th>Keep (Page)</th><th>Action</th></tr></thead><tbody>';

                response.data.duplicates.forEach(function(dup) {
                    var targets = dup.targets.map(function(t) {
                        return '<div style="font-size: 11px; margin-bottom: 2px;">' +
                            '<span style="color: ' + (t.post_type === 'page' ? '#28a745' : '#666') + ';">[' + t.post_type + ']</span> ' +
                            t.url.replace(/^https?:\/\/[^\/]+/, '') + '</div>';
                    }).join('');

                    html += '<tr data-anchor="' + dup.anchor + '">';
                    html += '<td><code style="font-size: 13px;">' + dup.anchor + '</code><br><small style="color: #666;">(' + dup.count + ' targets)</small></td>';
                    html += '<td>' + targets + '</td>';
                    html += '<td style="font-size: 11px; color: #28a745;">' + dup.keep_url.replace(/^https?:\/\/[^\/]+/, '') + '</td>';
                    html += '<td><button type="button" class="button fix-duplicate-anchor-btn" data-anchor="' + dup.anchor + '">Fix (Remove from Posts)</button></td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                $('#duplicate-anchors-content').html(html);
            } else {
                $('#duplicate-anchors-content').html('<p style="color: #28a745; margin: 0;">✓ No duplicate anchors found. All anchor texts are unique!</p>');
            }
        }).fail(function() {
            $('#duplicate-anchors-loading').hide();
            $('#duplicate-anchors-content').show().html('<p style="color: #dc3545;">Failed to scan. Please try again.</p>');
            $btn.prop('disabled', false).text('Scan for Duplicate Anchors');
        });
    });

    // Fix duplicate anchor
    $(document).on('click', '.fix-duplicate-anchor-btn', function() {
        var $btn = $(this);
        var anchor = $btn.data('anchor');
        $btn.prop('disabled', true).text('Fixing...');

        $.post(ajaxurl, {
            action: 'lendcity_fix_duplicate_anchor',
            nonce: nonce,
            anchor: anchor
        }, function(response) {
            if (response.success) {
                $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                if (response.data.fixed > 0) {
                    alert('Fixed! Removed ' + response.data.fixed + ' duplicate link(s).');
                }
            } else {
                alert('Error fixing anchor');
                $btn.prop('disabled', false).text('Fix (Remove from Posts)');
            }
        }).fail(function() {
            alert('Request failed');
            $btn.prop('disabled', false).text('Fix (Remove from Posts)');
        });
    });
});
</script>
