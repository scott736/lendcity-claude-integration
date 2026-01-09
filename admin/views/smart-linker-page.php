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
// v12.6.1: Removed local catalog references - now using Pinecone as source of truth
$auto_linking = get_option('lendcity_smart_linker_auto', 'yes');
$queue_status = $smart_linker->get_queue_status();

if (isset($_POST['save_smart_linker_settings']) && check_admin_referer('smart_linker_settings')) {
    update_option('lendcity_smart_linker_auto', isset($_POST['auto_linking']) ? 'yes' : 'no');
    $auto_linking = get_option('lendcity_smart_linker_auto');
    echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
}

// Optimized queries - only fetch what's needed
$all_pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'all'));
$all_posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'all'));

// Get links with limit for initial display
$all_links = $smart_linker->get_all_site_links(100);
$total_links = $smart_linker->get_total_link_count();
?>

<div class="wrap">
    <h1>Smart Linker <span style="font-size: 14px; color: #666;">AI-Powered Internal Linking</span></h1>

    <!-- Background Queue Status Dashboard -->
    <!-- v12.6.1: Removed local Catalog Queue (now using Pinecone for catalog) -->
    <?php
    $meta_queue_status = $smart_linker->get_meta_queue_status();
    $any_queue_active =
        ($queue_status['state'] ?? 'idle') === 'running' ||
        ($meta_queue_status['status'] ?? 'idle') === 'running';
    ?>
    <div id="background-queue-dashboard" style="background: linear-gradient(135deg, #1a1a2e, #16213e); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white; <?php echo $any_queue_active ? '' : 'display: none;'; ?>">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
            <h2 style="margin: 0; color: white;">🔄 Background Queues</h2>
            <span style="background: #00c853; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; animation: pulse 2s infinite;">RUNNING</span>
            <span style="opacity: 0.7; font-size: 13px; margin-left: auto;">You can close this window — processing continues in background</span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px;">
            <!-- Linker Queue -->
            <div id="bg-linker-status" class="bg-queue-card" style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; <?php echo ($queue_status['state'] ?? 'idle') !== 'running' ? 'opacity: 0.5;' : ''; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong>🔗 Auto Linker</strong>
                    <span class="bg-status-badge" style="background: <?php echo ($queue_status['state'] ?? 'idle') === 'running' ? '#00c853' : '#666'; ?>; padding: 2px 8px; border-radius: 10px; font-size: 11px;">
                        <?php echo ucfirst($queue_status['state'] ?? 'idle'); ?>
                    </span>
                </div>
                <div style="background: rgba(255,255,255,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                    <?php $link_pct = ($queue_status['total'] ?? 0) > 0 ? round(($queue_status['processed'] ?? 0) / ($queue_status['total'] ?? 1) * 100) : 0; ?>
                    <div class="bg-progress-bar" style="background: #7c4dff; height: 100%; width: <?php echo $link_pct; ?>%;"></div>
                </div>
                <div style="font-size: 12px; margin-top: 8px; opacity: 0.8;">
                    <span class="bg-processed"><?php echo $queue_status['processed'] ?? 0; ?></span> /
                    <span class="bg-total"><?php echo $queue_status['total'] ?? 0; ?></span> items
                </div>
            </div>
            <!-- Meta Queue -->
            <div id="bg-meta-status" class="bg-queue-card" style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; <?php echo ($meta_queue_status['status'] ?? 'idle') !== 'running' ? 'opacity: 0.5;' : ''; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong>📝 SEO Metadata</strong>
                    <span class="bg-status-badge" style="background: <?php echo ($meta_queue_status['status'] ?? 'idle') === 'running' ? '#00c853' : '#666'; ?>; padding: 2px 8px; border-radius: 10px; font-size: 11px;">
                        <?php echo ucfirst($meta_queue_status['status'] ?? 'idle'); ?>
                    </span>
                </div>
                <div style="background: rgba(255,255,255,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                    <?php $meta_pct = ($meta_queue_status['total'] ?? 0) > 0 ? round((($meta_queue_status['total'] ?? 0) - ($meta_queue_status['remaining'] ?? 0)) / ($meta_queue_status['total'] ?? 1) * 100) : 0; ?>
                    <div class="bg-progress-bar" style="background: #ff7043; height: 100%; width: <?php echo $meta_pct; ?>%;"></div>
                </div>
                <div style="font-size: 12px; margin-top: 8px; opacity: 0.8;">
                    <span class="bg-processed"><?php echo ($meta_queue_status['total'] ?? 0) - ($meta_queue_status['remaining'] ?? 0); ?></span> /
                    <span class="bg-total"><?php echo $meta_queue_status['total'] ?? 0; ?></span> items
                </div>
            </div>
        </div>
        <div style="margin-top: 15px; text-align: right;">
            <button type="button" id="stop-all-queues-btn" class="button" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">
                Stop All Queues
            </button>
        </div>
    </div>
    <style>
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    </style>

    <!-- Settings -->
    <div style="background: #f0f6fc; border: 1px solid #2271b1; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
        <form method="post" style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
            <?php wp_nonce_field('smart_linker_settings'); ?>
            <label><input type="checkbox" name="auto_linking" <?php checked($auto_linking, 'yes'); ?>> <strong>Auto-link new posts on publish</strong> <span style="color: #666; font-weight: normal;">(also generates SEO title/description)</span></label>
            <button type="submit" name="save_smart_linker_settings" class="button">Save</button>
        </form>
    </div>

    <!-- Vector API (Pinecone) Section -->
    <?php
    $external_api = new LendCity_External_API();
    $external_api_configured = $external_api->is_configured();
    $max_links = get_option('lendcity_max_links_per_article', 5);
    ?>
    <?php if ($external_api_configured): ?>
    <div style="background: linear-gradient(135deg, #0f0c29, #302b63, #24243e); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
            <h2 style="margin: 0; color: white;">🧠 Vector Smart Linker</h2>
            <span style="background: #00c853; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">PINECONE CONNECTED</span>
            <span id="pinecone-stats" style="background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 12px; font-size: 11px;">
                <span class="spinner is-active" style="float: none; margin: 0; width: 12px; height: 12px;"></span> Loading stats...
            </span>
        </div>
        <p style="margin: 0 0 15px; opacity: 0.9;">AI-powered linking using semantic vectors. Pillars define clusters, posts auto-link to best matches.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <!-- Rebuild Catalog Card -->
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                <h3 style="margin: 0 0 10px; color: white;">📚 Rebuild Catalog</h3>
                <p style="margin: 0 0 10px; font-size: 13px; opacity: 0.8;">Sync all content to Pinecone. Pillars first, then pages, then posts.</p>
                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 12px; cursor: pointer;">
                    <input type="checkbox" id="skip-already-synced" checked>
                    <span>Skip already catalogued articles</span>
                </label>
                <button type="button" id="rebuild-pinecone-catalog" class="button button-large" style="background: #4fc3f7; color: #0f0c29; border: none; font-weight: bold; width: 100%;">
                    🔄 Rebuild Catalog
                </button>
                <div id="rebuild-catalog-status" style="margin-top: 10px; display: none;">
                    <div style="background: rgba(255,255,255,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="rebuild-catalog-bar" style="background: #4fc3f7; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="rebuild-catalog-text" style="margin: 8px 0 0; font-size: 12px;"></p>
                </div>
            </div>

            <!-- Audit Links Card -->
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                <h3 style="margin: 0 0 10px; color: white;">🔍 Audit All Links</h3>
                <p style="margin: 0 0 15px; font-size: 13px; opacity: 0.8;">Check existing links for broken, suboptimal, or missing opportunities.</p>
                <button type="button" id="audit-all-links" class="button button-large" style="background: #ff7043; color: white; border: none; font-weight: bold; width: 100%;">
                    🔍 Audit Links
                </button>
                <div id="audit-links-status" style="margin-top: 10px; display: none;">
                    <div style="background: rgba(255,255,255,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="audit-links-bar" style="background: #ff7043; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="audit-links-text" style="margin: 8px 0 0; font-size: 12px;"></p>
                </div>
            </div>

            <!-- Settings Card -->
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                <h3 style="margin: 0 0 10px; color: white;">⚙️ Link Settings</h3>
                <p style="margin: 0 0 10px; font-size: 13px; opacity: 0.8;">Configure auto-linking behavior.</p>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <label style="font-size: 13px;">Max links per article:</label>
                    <input type="number" id="max-links-setting" value="<?php echo esc_attr($max_links); ?>" min="1" max="20" style="width: 60px; padding: 5px;">
                    <button type="button" id="save-max-links" class="button" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3);">Save</button>
                </div>
                <p style="margin: 0; font-size: 11px; opacity: 0.6;">How many links to add when auto-linking new posts.</p>
            </div>

            <!-- Bulk Link Card -->
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                <h3 style="margin: 0 0 10px; color: white;">🔗 Bulk Link All Posts</h3>
                <p style="margin: 0 0 10px; font-size: 13px; opacity: 0.8;">Add internal links to all posts using vector matching.</p>
                <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 12px; cursor: pointer;">
                    <input type="checkbox" id="skip-posts-with-links" checked>
                    <span>Skip posts that already have links</span>
                </label>
                <button type="button" id="start-bulk-queue-btn" class="button button-large" style="background: #81c784; color: #1b5e20; border: none; font-weight: bold; width: 100%;">
                    🚀 Start Bulk Linking
                </button>
                <div id="bulk-link-status" style="margin-top: 10px; display: none;">
                    <div style="background: rgba(255,255,255,0.2); height: 8px; border-radius: 4px; overflow: hidden;">
                        <div id="queue-progress-bar" style="background: #81c784; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="queue-progress-text" style="margin: 8px 0 0; font-size: 12px;">0 / 0 (0%)</p>
                    <div style="margin-top: 8px; font-size: 11px; display: flex; gap: 15px; flex-wrap: wrap;">
                        <span>Links: <strong id="queue-links-created">0</strong></span>
                        <span>Skipped: <strong id="queue-skipped">0</strong></span>
                        <span>Errors: <strong id="queue-errors">0</strong></span>
                    </div>
                    <div style="margin-top: 8px;">
                        <button type="button" id="pause-queue-btn" class="button" style="font-size: 11px; padding: 2px 8px;">Pause</button>
                        <button type="button" id="resume-queue-btn" class="button" style="font-size: 11px; padding: 2px 8px; display: none;">Resume</button>
                        <button type="button" id="stop-queue-btn" class="button" style="font-size: 11px; padding: 2px 8px; color: #dc3545;">Stop</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audit Results Panel -->
        <div id="audit-results-panel" style="background: rgba(255,255,255,0.95); padding: 20px; border-radius: 8px; color: #333; margin-top: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <h3 style="margin: 0;">Link Audit Results</h3>
                <div id="audit-cache-info" style="display: none; font-size: 12px; color: #666;">
                    <span id="audit-last-updated"></span>
                    <span id="audit-stale-info" style="margin-left: 10px; color: #ef6c00;"></span>
                </div>
            </div>
            <div id="audit-action-bar" style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                <button type="button" id="run-full-audit-btn" class="button button-primary">🔍 Full Audit</button>
                <button type="button" id="refresh-stale-btn" class="button" style="display: none;">🔄 Refresh Changed Posts (<span id="stale-count">0</span>)</button>
            </div>
            <div id="audit-progress-container" style="display: none; margin-bottom: 15px;">
                <div style="background: #e0e0e0; height: 8px; border-radius: 4px; overflow: hidden;">
                    <div id="audit-progress-bar" style="background: linear-gradient(90deg, #667eea, #764ba2); height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p id="audit-progress-text" style="margin: 8px 0 0; font-size: 12px; color: #666;"></p>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div style="text-align: center; padding: 15px; background: #e8f5e9; border-radius: 8px;">
                    <div id="audit-health-score" style="font-size: 32px; font-weight: bold; color: #2e7d32;">-</div>
                    <div style="font-size: 12px; color: #666;">Health Score</div>
                </div>
                <div style="text-align: center; padding: 15px; background: #e3f2fd; border-radius: 8px;">
                    <div id="audit-total-links" style="font-size: 32px; font-weight: bold; color: #1565c0;">-</div>
                    <div style="font-size: 12px; color: #666;">Total Links</div>
                </div>
                <div class="audit-stat-card" data-filter="broken" style="text-align: center; padding: 15px; background: #ffebee; border-radius: 8px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Click to view broken links">
                    <div id="audit-broken-links" style="font-size: 32px; font-weight: bold; color: #c62828;">-</div>
                    <div style="font-size: 12px; color: #666;">Broken</div>
                </div>
                <div class="audit-stat-card" data-filter="suboptimal" style="text-align: center; padding: 15px; background: #fff3e0; border-radius: 8px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Click to view suboptimal links">
                    <div id="audit-suboptimal-links" style="font-size: 32px; font-weight: bold; color: #ef6c00;">-</div>
                    <div style="font-size: 12px; color: #666;">Suboptimal</div>
                </div>
                <div class="audit-stat-card" data-filter="missing" style="text-align: center; padding: 15px; background: #f3e5f5; border-radius: 8px; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;" title="Click to view missing opportunities">
                    <div id="audit-missing-opps" style="font-size: 32px; font-weight: bold; color: #7b1fa2;">-</div>
                    <div style="font-size: 12px; color: #666;">Missing Opportunities</div>
                </div>
            </div>
            <div id="audit-filter-bar" style="display: none; margin-bottom: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                <span id="audit-filter-label" style="font-weight: bold;"></span>
                <button type="button" id="audit-clear-filter" class="button button-small" style="margin-left: 10px;">Show All</button>
                <button type="button" id="accept-all-missing" class="button button-small" style="margin-left: 10px; background: #7b1fa2; color: white; border: none; display: none;">✓ Accept All Suggestions</button>
                <button type="button" id="dismiss-all-missing" class="button button-small" style="margin-left: 10px; background: #6c757d; color: white; border: none; display: none;" title="Hide all suggestions from this view (does NOT affect Pinecone)">✕ Dismiss All</button>
                <button type="button" id="reset-dismissed" class="button button-small" style="margin-left: 10px; background: #17a2b8; color: white; border: none; display: none;" title="Show previously dismissed suggestions again">↺ Reset Dismissed</button>
            </div>
            <div id="audit-issues-list" style="max-height: 400px; overflow-y: auto;"></div>
        </div>
    </div>
    <?php else: ?>
    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
        <strong>⚠️ Vector API not configured.</strong> Add your Vercel API URL and key in <a href="<?php echo admin_url('admin.php?page=lendcity-tools'); ?>">LendCity Tools Settings</a> to enable AI-powered smart linking.
    </div>
    <?php endif; ?>

    <!-- Smart SEO Metadata -->
    <div style="background: linear-gradient(135deg, #f093fb, #f5576c); border-radius: 4px; padding: 20px; margin-bottom: 20px; color: white;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
            <h2 style="margin: 0; color: white;"><span style="background: rgba(255,255,255,0.3); padding: 2px 10px; border-radius: 12px; font-size: 14px; margin-right: 10px;">Step 3</span>Smart SEO Metadata</h2>
        </div>
        <p style="margin-bottom: 15px; opacity: 0.9;">
            Generate optimized SEO titles and descriptions using catalog data and inbound link analysis.
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
                        <th style="width: 70px;">Pillar</th>
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
                        <td style="text-align: center;">
                            <input type="checkbox" class="page-pillar" <?php checked(!empty($page['is_pillar'])); ?> title="Mark as pillar content">
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
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                        </select>
                    </label>
                </div>
                <div>
                    <button type="button" class="button" id="links-prev" disabled>← Previous</button>
                    <span id="links-page-info" style="margin: 0 10px;">Page 1 of <?php echo ceil($total_links / 25); ?></span>
                    <button type="button" class="button" id="links-next" <?php echo $total_links <= 25 ? 'disabled' : ''; ?>>Next →</button>
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
                    <?php foreach (array_slice($all_links, 0, 25) as $idx => $link):
                        // Skip invalid links missing required fields
                        if (empty($link['url']) || empty($link['anchor']) || empty($link['source_post_id'])) continue;
                        $link_id = $link['link_id'] ?? $link['source_post_id'] . '-' . $idx;
                        $source_title = $link['source_post_title'] ?? get_the_title($link['source_post_id']);
                    ?>
                        <tr data-link-id="<?php echo esc_attr($link_id); ?>"
                            data-source-id="<?php echo esc_attr($link['source_post_id']); ?>"
                            data-current-url="<?php echo esc_attr($link['url']); ?>"
                            data-source-title="<?php echo esc_attr(strtolower($source_title)); ?>"
                            data-anchor="<?php echo esc_attr(strtolower($link['anchor'])); ?>"
                            data-target="<?php echo esc_attr(strtolower(str_replace(home_url(), '', $link['url']))); ?>"
                            data-type="<?php echo !empty($link['is_page']) ? 'page' : 'post'; ?>">
                            <td><a href="<?php echo get_edit_post_link($link['source_post_id']); ?>" target="_blank"><?php echo esc_html($source_title); ?></a></td>
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
            <p id="links-showing" style="color: #666; margin-top: 10px;">Showing 1-<?php echo min(25, $total_links); ?> of <?php echo $total_links; ?> links</p>
        <?php endif; ?>
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

    <!-- Delete All Links (Danger Zone) -->
    <div style="background: #fff5f5; border: 1px solid #dc3545; border-radius: 4px; padding: 20px; margin-top: 40px;">
        <h3 style="margin-top: 0; color: #dc3545;">⚠️ Danger Zone</h3>
        <p style="margin: 0 0 15px; color: #666;">Remove all <?php echo $total_links; ?> Claude-generated links from your entire site. This cannot be undone.</p>
        <button type="button" id="delete-all-links-btn" class="button button-large" style="background: #dc3545; color: white; border-color: #dc3545;">
            🗑️ Delete ALL Links (<?php echo $total_links; ?>)
        </button>
    </div>
</div>

<!-- Select2 for searchable dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo wp_create_nonce('lendcity_claude_nonce'); ?>';
    var bulkSyncNonce = '<?php echo wp_create_nonce('lendcity_bulk_sync'); ?>';
    var linkAuditNonce = '<?php echo wp_create_nonce('lendcity_link_audit'); ?>';

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ========== VECTOR SMART LINKER (Pinecone) ==========

    // Load Pinecone stats on page load
    function loadPineconeStats() {
        $.post(ajaxurl, {
            action: 'lendcity_get_pinecone_stats',
            nonce: bulkSyncNonce
        }, function(response) {
            if (response.success) {
                var data = response.data;
                $('#pinecone-stats').html('<strong>' + data.catalogued + '</strong> of ' + data.total_wp + ' catalogued');
            } else {
                $('#pinecone-stats').text('Stats unavailable');
            }
        }).fail(function() {
            $('#pinecone-stats').text('Stats unavailable');
        });
    }
    loadPineconeStats();

    // Rebuild Catalog Button - CHUNKED with skip option
    $('#rebuild-pinecone-catalog').on('click', function() {
        var $btn = $(this);
        var $status = $('#rebuild-catalog-status');
        var $bar = $('#rebuild-catalog-bar');
        var $text = $('#rebuild-catalog-text');
        var skipSynced = $('#skip-already-synced').is(':checked');

        $btn.prop('disabled', true).text('Rebuilding...');
        $status.show();
        $bar.css('width', '5%');
        $text.text('Getting content list...');

        // Get filtered list based on skip option
        $.post(ajaxurl, {
            action: 'lendcity_get_sync_list_filtered',
            nonce: bulkSyncNonce,
            skip_synced: skipSynced ? 'true' : 'false'
        }, function(response) {
            if (!response.success) {
                $text.text('Error: ' + (response.data?.message || 'Unknown error'));
                $btn.prop('disabled', false).text('🔄 Rebuild Catalog');
                return;
            }

            var items = response.data.items;
            var total = items.length;

            if (total === 0) {
                $bar.css('width', '100%');
                $text.html('<strong>All articles already catalogued!</strong> Uncheck "Skip already catalogued" to re-sync.');
                $btn.prop('disabled', false).text('🔄 Rebuild Catalog');
                return;
            }

            var processed = 0;
            var succeeded = 0;
            var failed = 0;
            var chunkSize = 3;

            $text.text('Syncing 0 of ' + total + ' articles...');

            function processChunk() {
                if (processed >= total) {
                    $bar.css('width', '100%');
                    $text.html('<strong>Complete!</strong> ' + succeeded + ' of ' + total + ' synced.' +
                        (failed > 0 ? ' <span style="color: #ff5252;">' + failed + ' failed</span>' : ''));
                    $btn.prop('disabled', false).text('🔄 Rebuild Catalog');
                    loadPineconeStats(); // Refresh stats
                    return;
                }

                var chunk = items.slice(processed, processed + chunkSize);
                var chunkIds = chunk.map(function(item) { return item.id; });

                $.post(ajaxurl, {
                    action: 'lendcity_sync_chunk',
                    nonce: bulkSyncNonce,
                    post_ids: chunkIds
                }, function(chunkResponse) {
                    if (chunkResponse.success) {
                        succeeded += chunkResponse.data.success || 0;
                        failed += chunkResponse.data.failed || 0;
                    } else {
                        failed += chunk.length;
                    }
                    processed += chunk.length;

                    var percent = Math.round((processed / total) * 100);
                    $bar.css('width', percent + '%');
                    $text.text('Syncing ' + processed + ' of ' + total + ' articles...');

                    setTimeout(processChunk, 500);
                }).fail(function() {
                    failed += chunk.length;
                    processed += chunk.length;
                    setTimeout(processChunk, 500);
                });
            }

            processChunk();
        }).fail(function() {
            $text.text('Request failed. Check console for details.');
            $btn.prop('disabled', false).text('🔄 Rebuild Catalog');
        });
    });

    // ========== AUDIT CACHING SYSTEM ==========

    // Load cached audit results on page load
    function loadCachedAudit() {
        $.post(ajaxurl, {
            action: 'lendcity_get_audit_cache',
            nonce: linkAuditNonce
        }, function(response) {
            if (response.success && response.data.has_cache) {
                displayAuditResults(response.data.stats, response.data.issues);

                // Show cache info
                $('#audit-cache-info').show();
                $('#audit-last-updated').text('Last updated: ' + response.data.updated_at);

                if (response.data.posts_stale > 0) {
                    $('#audit-stale-info').text(response.data.posts_stale + ' posts changed since last audit');
                    $('#refresh-stale-btn').show();
                    $('#stale-count').text(response.data.posts_stale);
                } else {
                    $('#audit-stale-info').text('All posts up to date');
                    $('#refresh-stale-btn').hide();
                }
            }
        });
    }

    // Display audit results (shared by cache load and fresh audit)
    function displayAuditResults(stats, issues) {
        var healthScore = 100;
        if (stats.totalLinks > 0) {
            var healthyLinks = stats.totalLinks - stats.brokenLinks - stats.suboptimalLinks;
            healthScore = Math.round((healthyLinks / stats.totalLinks) * 100);
        }

        $('#audit-health-score').text(healthScore + '%');
        $('#audit-total-links').text(stats.totalLinks);
        $('#audit-broken-links').text(stats.brokenLinks);
        $('#audit-suboptimal-links').text(stats.suboptimalLinks);
        $('#audit-missing-opps').text(stats.missingOpportunities);

        // Store issues globally
        window.auditIssues = issues;
        window.currentFilter = null;

        // Helper to check if an issue is dismissed in sessionStorage
        window.isIssueDismissed = function(issue) {
            var dismissed = JSON.parse(sessionStorage.getItem('dismissedAuditItems') || '[]');
            return dismissed.some(function(d) {
                return d.postId === issue.postId && d.targetPostId === issue.targetPostId;
            });
        };

        // Build and store the table builder function
        window.buildIssuesTable = function(issuesList, filterType) {
            var filteredIssues = filterType
                ? issuesList.filter(function(i) { return i.type === filterType; })
                : issuesList;

            // Filter out dismissed items from sessionStorage
            filteredIssues = filteredIssues.filter(function(i) {
                return !window.isIssueDismissed(i);
            });

            if (filteredIssues.length === 0) {
                return '<p style="color: #666; text-align: center; padding: 20px;">No ' + (filterType || '') + ' issues found.</p>';
            }

            var html = '<table style="width: 100%; border-collapse: collapse; font-size: 13px;" id="audit-issues-table">';
            html += '<tr style="background: #f5f5f5;"><th style="padding: 8px; text-align: left;">Type</th><th style="padding: 8px; text-align: left;">Post</th><th style="padding: 8px; text-align: left;">Details</th><th style="padding: 8px; text-align: center;">Actions</th></tr>';

            filteredIssues.slice(0, 100).forEach(function(issue) {
                var origIndex = issuesList.indexOf(issue);
                var typeColor = issue.type === 'broken' ? '#c62828' : (issue.type === 'suboptimal' ? '#ef6c00' : '#7b1fa2');
                var typeLabel = issue.type === 'missing' ? 'OPPORTUNITY' : issue.type.toUpperCase();
                var staleIndicator = issue.isStale ? ' <span style="color: #ef6c00; font-size: 10px;" title="Post changed since audit">⚠</span>' : '';
                var details = '';
                var actions = '';

                if (issue.type === 'broken') {
                    details = 'Anchor: "' + escapeHtml(issue.anchor) + '" → ' + escapeHtml(issue.url);
                    actions = '<button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="remove_broken" style="background: #dc3545; color: white; border: none;">Remove Link</button>';
                    actions += ' <button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="ignore" style="background: #6c757d; color: white; border: none;">Ignore</button>';
                } else if (issue.type === 'suboptimal') {
                    details = 'Current: ' + escapeHtml(issue.currentTarget) + ' → Better: ' + escapeHtml(issue.betterOption);
                    actions = '<button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="swap_link" style="background: #28a745; color: white; border: none;">Accept Better</button>';
                    actions += ' <button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="ignore" style="background: #6c757d; color: white; border: none;">Ignore</button>';
                } else if (issue.type === 'missing') {
                    details = 'Link to: <strong>' + escapeHtml(issue.targetTitle) + '</strong>';
                    if (issue.topicCluster) {
                        details += ' <span style="background: #e8f5e9; padding: 2px 6px; border-radius: 3px; font-size: 11px;">' + escapeHtml(issue.topicCluster) + '</span>';
                    }
                    // Show anchor type badge (sentence, phrase, contextual)
                    if (issue.anchorType) {
                        var typeColors = { sentence: '#9c27b0', phrase: '#1976d2', contextual: '#00796b' };
                        var typeLabels = { sentence: '📝 Sentence', phrase: '🔗 Phrase', contextual: '🎯 Contextual' };
                        details += ' <span style="background: ' + (typeColors[issue.anchorType] || '#666') + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">' + (typeLabels[issue.anchorType] || issue.anchorType) + '</span>';
                    }
                    // Show position badge (intro, body, conclusion)
                    if (issue.anchorPosition) {
                        var posColors = { intro: '#2e7d32', body: '#757575', conclusion: '#1565c0' };
                        details += ' <span style="background: ' + (posColors[issue.anchorPosition] || '#666') + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; text-transform: uppercase;">' + issue.anchorPosition + '</span>';
                    }
                    // Show SEO quality signals
                    if (issue.isNaturalLanguage) {
                        details += ' <span style="background: #4caf50; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;" title="Natural language anchor - better for SEO">✓ Natural</span>';
                    }
                    if (issue.isExactMatch) {
                        details += ' <span style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;" title="Exact title match - use sparingly to avoid over-optimization">⚠ Exact Match</span>';
                    }
                    // Show the anchor text that will be used
                    if (issue.anchorText) {
                        details += '<br><span style="color: #1565c0; font-size: 12px;">Anchor: "<strong>' + escapeHtml(issue.anchorText) + '</strong>"</span>';
                    }
                    // Show matching keywords
                    if (issue.matchingWords && issue.matchingWords.length > 0) {
                        details += ' <span style="color: #666; font-size: 11px;">(keywords: ' + issue.matchingWords.join(', ') + ')</span>';
                    }
                    if (issue.anchorContext) {
                        details += '<br><span style="color: #888; font-size: 11px; font-style: italic;">' + escapeHtml(issue.anchorContext) + '</span>';
                    }
                    // Show SEO reason
                    if (issue.reason) {
                        details += '<br><span style="color: #4527a0; font-size: 10px;">💡 ' + escapeHtml(issue.reason) + '</span>';
                    }
                    actions = '<button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="accept_opportunity" style="background: #7b1fa2; color: white; border: none;">Accept</button>';
                    actions += ' <button class="button button-small fix-link-btn" data-index="' + origIndex + '" data-action="decline_opportunity" style="background: #6c757d; color: white; border: none;">Decline</button>';
                }

                html += '<tr id="issue-row-' + origIndex + '" data-type="' + issue.type + '" style="border-bottom: 1px solid #eee;">';
                html += '<td style="padding: 8px;"><span style="color: ' + typeColor + '; font-weight: bold;">' + typeLabel + '</span>' + staleIndicator + '</td>';
                html += '<td style="padding: 8px;"><a href="post.php?post=' + issue.postId + '&action=edit" target="_blank">' + escapeHtml(issue.postTitle) + '</a></td>';
                html += '<td style="padding: 8px; font-size: 12px;">' + details + '</td>';
                html += '<td style="padding: 8px; text-align: center; white-space: nowrap;">' + actions + '</td>';
                html += '</tr>';
            });
            html += '</table>';
            if (filteredIssues.length > 100) {
                html += '<p style="color: #666; font-size: 12px;">Showing first 100 of ' + filteredIssues.length + ' items.</p>';
            }
            return html;
        };

        // Initially show only broken and suboptimal
        var initialIssues = issues.filter(function(i) { return i.type !== 'missing'; });
        var issuesHtml = initialIssues.length > 0
            ? window.buildIssuesTable(initialIssues, null)
            : '<p style="color: #2e7d32; text-align: center; padding: 20px;">✅ No broken or suboptimal links! Click "Missing Opportunities" above to see suggested links.</p>';

        $('#audit-issues-list').html(issuesHtml);
        $('#audit-filter-bar').hide();
    }

    // Run audit on specific posts
    function runAudit(postIds, onComplete) {
        var $bar = $('#audit-progress-bar');
        var $text = $('#audit-progress-text');
        var $progress = $('#audit-progress-container');

        $progress.show();
        $bar.css('width', '5%');

        var total = postIds.length;
        var processed = 0;
        var chunkSize = 3;

        var aggregatedStats = { totalLinks: 0, brokenLinks: 0, suboptimalLinks: 0, missingOpportunities: 0 };
        var allIssues = [];

        function processChunk() {
            if (processed >= total) {
                $bar.css('width', '100%');
                $text.text('Complete! Refreshing results...');

                // Reload cached results to get fresh data
                setTimeout(function() {
                    loadCachedAudit();
                    $progress.hide();
                    if (onComplete) onComplete();
                }, 500);
                return;
            }

            var chunk = postIds.slice(processed, processed + chunkSize);

            $.post(ajaxurl, {
                action: 'lendcity_audit_chunk',
                nonce: linkAuditNonce,
                post_ids: chunk
            }, function(chunkResponse) {
                if (chunkResponse.success) {
                    var data = chunkResponse.data;
                    aggregatedStats.totalLinks += data.stats.totalLinks || 0;
                    aggregatedStats.brokenLinks += data.stats.brokenLinks || 0;
                    aggregatedStats.suboptimalLinks += data.stats.suboptimalLinks || 0;
                    aggregatedStats.missingOpportunities += data.stats.missingOpportunities || 0;
                    if (data.issues) allIssues = allIssues.concat(data.issues);
                }

                processed += chunk.length;
                var percent = Math.round((processed / total) * 100);
                $bar.css('width', percent + '%');
                $text.text('Auditing ' + processed + ' of ' + total + ' posts...');

                setTimeout(processChunk, 500);
            }).fail(function() {
                processed += chunk.length;
                setTimeout(processChunk, 500);
            });
        }

        processChunk();
    }

    // Load cached results on page load
    loadCachedAudit();

    // Full Audit Button
    $('#run-full-audit-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Starting...');

        $.post(ajaxurl, {
            action: 'lendcity_get_audit_list',
            nonce: linkAuditNonce
        }, function(response) {
            if (!response.success) {
                alert('Error: ' + (response.data?.message || 'Unknown error'));
                $btn.prop('disabled', false).text('🔍 Full Audit');
                return;
            }

            var postIds = response.data.items.map(function(item) { return item.id; });
            $('#audit-progress-text').text('Auditing ' + postIds.length + ' posts...');

            runAudit(postIds, function() {
                $btn.prop('disabled', false).text('🔍 Full Audit');
            });
        }).fail(function() {
            alert('Failed to get post list');
            $btn.prop('disabled', false).text('🔍 Full Audit');
        });
    });

    // Refresh Stale Posts Button
    $('#refresh-stale-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Refreshing...');

        $.post(ajaxurl, {
            action: 'lendcity_get_stale_posts',
            nonce: linkAuditNonce
        }, function(response) {
            if (!response.success || response.data.count === 0) {
                alert('No stale posts to refresh');
                $btn.prop('disabled', false).text('🔄 Refresh Changed Posts (0)');
                return;
            }

            var postIds = response.data.items.map(function(item) { return item.id; });
            $('#audit-progress-text').text('Refreshing ' + postIds.length + ' changed posts...');

            runAudit(postIds, function() {
                $btn.hide();
                $btn.prop('disabled', false);
            });
        }).fail(function() {
            alert('Failed to get stale posts');
            $btn.prop('disabled', false);
        });
    });

    // Legacy support - redirect old audit button to new one
    $('#audit-all-links').on('click', function() {
        $('#run-full-audit-btn').click();
    });

    // Save Max Links Setting
    $('#save-max-links').on('click', function() {
        var $btn = $(this);
        var maxLinks = parseInt($('#max-links-setting').val()) || 5;

        $btn.prop('disabled', true).text('Saving...');

        $.post(ajaxurl, {
            action: 'lendcity_save_max_links',
            nonce: nonce,
            max_links: maxLinks
        }, function(response) {
            if (response.success) {
                $btn.text('✓ Saved').css('background', '#4caf50');
                setTimeout(function() {
                    $btn.prop('disabled', false).text('Save').css('background', '');
                }, 2000);
            } else {
                $btn.prop('disabled', false).text('Save');
                alert('Failed to save: ' + (response.data?.message || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });

    // Stat Card Click Handler - Filter issues by type
    $(document).on('click', '.audit-stat-card', function() {
        var filterType = $(this).data('filter');
        var filterLabels = {
            'broken': 'Showing Broken Links',
            'suboptimal': 'Showing Suboptimal Links',
            'missing': 'Showing Missing Opportunities'
        };

        if (!window.auditIssues) return;

        window.currentFilter = filterType;
        $('#audit-filter-label').text(filterLabels[filterType] || 'Filtered');
        $('#audit-filter-bar').show();

        // Show/hide Accept All, Dismiss All, and Reset buttons only for missing opportunities
        if (filterType === 'missing') {
            $('#accept-all-missing').show();
            $('#dismiss-all-missing').show();
            $('#reset-dismissed').show();
        } else {
            $('#accept-all-missing').hide();
            $('#dismiss-all-missing').hide();
            $('#reset-dismissed').hide();
        }

        // Highlight active card
        $('.audit-stat-card').css({ 'transform': '', 'box-shadow': '' });
        $(this).css({ 'transform': 'scale(1.05)', 'box-shadow': '0 4px 12px rgba(0,0,0,0.15)' });

        // Rebuild table with filter
        var html = window.buildIssuesTable(window.auditIssues, filterType);
        $('#audit-issues-list').html(html);
    });

    // Clear Filter Handler
    $('#audit-clear-filter').on('click', function() {
        window.currentFilter = null;
        $('#audit-filter-bar').hide();
        $('.audit-stat-card').css({ 'transform': '', 'box-shadow': '' });

        // Show broken + suboptimal only (not missing)
        var initialIssues = window.auditIssues.filter(function(i) { return i.type !== 'missing'; });
        var html = initialIssues.length > 0
            ? window.buildIssuesTable(initialIssues, null)
            : '<p style="color: #2e7d32; text-align: center; padding: 20px;">✅ No broken or suboptimal links!</p>';
        $('#audit-issues-list').html(html);
    });

    // Accept All Missing Opportunities Handler
    $('#accept-all-missing').on('click', function() {
        var missingIssues = window.auditIssues.filter(function(i) { return i.type === 'missing'; });
        var pendingRows = [];

        // Find rows that haven't been processed yet
        missingIssues.forEach(function(issue) {
            var idx = window.auditIssues.indexOf(issue);
            var $row = $('#issue-row-' + idx);
            if ($row.find('.fix-link-btn').length > 0) {
                pendingRows.push({ issue: issue, index: idx });
            }
        });

        if (pendingRows.length === 0) {
            alert('No pending opportunities to accept.');
            return;
        }

        if (!confirm('Accept all ' + pendingRows.length + ' missing link opportunities?\n\nThis will trigger smart linking for each source post to add the suggested links.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Processing...');

        var processed = 0;
        var succeeded = 0;

        function processNext() {
            if (processed >= pendingRows.length) {
                $btn.prop('disabled', false).text('✓ Accept All Suggestions');
                alert('Completed! ' + succeeded + ' of ' + pendingRows.length + ' opportunities accepted.');
                // Update counter
                var currentMissing = parseInt($('#audit-missing-opps').text()) || 0;
                $('#audit-missing-opps').text(Math.max(0, currentMissing - succeeded));
                return;
            }

            var item = pendingRows[processed];
            var $row = $('#issue-row-' + item.index);
            $row.find('.fix-link-btn').prop('disabled', true);

            $.post(ajaxurl, {
                action: 'lendcity_accept_opportunity',
                nonce: linkAuditNonce,
                post_id: item.issue.postId,
                target_post_id: item.issue.targetPostId,
                target_url: item.issue.targetUrl,
                target_title: item.issue.targetTitle,
                anchor_text: item.issue.anchorText || ''
            }, function(response) {
                if (response.success) {
                    succeeded++;
                    $row.css('background', '#d4edda');
                    $row.find('.fix-link-btn').remove();
                    $row.find('td:last').html('<span style="color: #28a745; font-weight: bold;">✓ Accepted</span>');
                } else {
                    $row.css('background', '#f8d7da');
                    $row.find('td:last').html('<span style="color: #c62828;">✗ Failed</span>');
                }
                processed++;
                $btn.text('Processing ' + processed + '/' + pendingRows.length + '...');
                setTimeout(processNext, 300);
            }).fail(function() {
                $row.css('background', '#f8d7da');
                $row.find('td:last').html('<span style="color: #c62828;">✗ Failed</span>');
                processed++;
                setTimeout(processNext, 300);
            });
        }

        processNext();
    });

    // Dismiss All Missing Opportunities Handler
    // This hides all suggestions from the view WITHOUT affecting Pinecone
    $('#dismiss-all-missing').on('click', function() {
        // Get ALL missing issues (not just rendered ones)
        var allMissingIssues = window.auditIssues.filter(function(i) { return i.type === 'missing'; });

        // Filter out already dismissed ones
        var pendingIssues = allMissingIssues.filter(function(issue) {
            return !window.isIssueDismissed(issue);
        });

        if (pendingIssues.length === 0) {
            alert('No pending opportunities to dismiss.');
            return;
        }

        if (!confirm('Dismiss all ' + pendingIssues.length + ' suggestions?\n\nThis hides them from the audit view. Does NOT affect Pinecone.\n\nThey will reappear on the next full audit.')) {
            return;
        }

        // Store ALL pending items in session storage
        var dismissed = JSON.parse(sessionStorage.getItem('dismissedAuditItems') || '[]');
        pendingIssues.forEach(function(issue) {
            dismissed.push({
                postId: issue.postId,
                targetPostId: issue.targetPostId,
                dismissedAt: new Date().toISOString()
            });
        });
        sessionStorage.setItem('dismissedAuditItems', JSON.stringify(dismissed));

        // Update counter to 0
        $('#audit-missing-opps').text(0);

        // Rebuild the table to reflect dismissed items
        var html = window.buildIssuesTable(window.auditIssues, 'missing');
        $('#audit-issues-list').html(html);

        alert('Dismissed ' + pendingIssues.length + ' suggestions.\n\nThese will reappear on the next full audit.');
    });

    // Reset Dismissed Handler - Clear sessionStorage and show all opportunities again
    $('#reset-dismissed').on('click', function() {
        var dismissed = JSON.parse(sessionStorage.getItem('dismissedAuditItems') || '[]');
        if (dismissed.length === 0) {
            alert('No dismissed items to restore.');
            return;
        }

        if (!confirm('Restore ' + dismissed.length + ' previously dismissed suggestions?')) {
            return;
        }

        // Clear sessionStorage
        sessionStorage.removeItem('dismissedAuditItems');

        // Recalculate the missing opportunities count
        var allMissing = window.auditIssues.filter(function(i) { return i.type === 'missing'; });
        $('#audit-missing-opps').text(allMissing.length);

        // Rebuild the table
        var html = window.buildIssuesTable(window.auditIssues, 'missing');
        $('#audit-issues-list').html(html);

        alert('Restored ' + dismissed.length + ' suggestions.');
    });

    // Fix Link Button Handler (delegated for dynamically added buttons)
    $(document).on('click', '.fix-link-btn', function() {
        var $btn = $(this);
        var index = parseInt($btn.data('index'));
        var action = $btn.data('action');
        var issue = window.auditIssues[index];

        if (!issue) {
            alert('Issue not found');
            return;
        }

        // Confirmation
        var confirmMsg = '';
        if (action === 'remove_broken') {
            confirmMsg = 'Remove the broken link "' + issue.anchor + '" from the post?\n\nThe anchor text will be kept, but the link will be removed.';
        } else if (action === 'swap_link') {
            confirmMsg = 'Change the link target from:\n"' + issue.currentTarget + '"\nto:\n"' + issue.betterOption + '"?';
        } else if (action === 'ignore') {
            confirmMsg = 'Ignore this issue?';
        } else if (action === 'accept_opportunity') {
            var anchorPreview = issue.anchorText ? '"' + issue.anchorText + '"' : '(no anchor found)';
            confirmMsg = 'Add link to "' + issue.targetTitle + '"?\n\nAnchor text: ' + anchorPreview;
        } else if (action === 'decline_opportunity') {
            confirmMsg = 'Decline this suggestion?';
        }

        if (!confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true).text('...');

        // Handle accept/decline opportunity differently
        if (action === 'accept_opportunity') {
            $.post(ajaxurl, {
                action: 'lendcity_accept_opportunity',
                nonce: linkAuditNonce,
                post_id: issue.postId,
                target_post_id: issue.targetPostId,
                target_url: issue.targetUrl,
                target_title: issue.targetTitle,
                anchor_text: issue.anchorText || ''
            }, function(response) {
                var $row = $('#issue-row-' + index);
                if (response.success) {
                    $row.css('background', '#d4edda');
                    $row.find('.fix-link-btn').remove();
                    $row.find('td:last').html('<span style="color: #28a745; font-weight: bold;">✓ Accepted</span>');
                    // Update counter
                    var currentMissing = parseInt($('#audit-missing-opps').text()) || 0;
                    if (currentMissing > 0) {
                        $('#audit-missing-opps').text(currentMissing - 1);
                    }
                } else {
                    alert('Error: ' + (response.data?.message || 'Failed to add link'));
                    $btn.prop('disabled', false).text('Accept');
                }
            }).fail(function() {
                alert('Request failed. Please try again.');
                $btn.prop('disabled', false).text('Accept');
            });
            return;
        }

        if (action === 'decline_opportunity') {
            // Just hide the row - no server action needed (does NOT affect Pinecone)
            var $row = $('#issue-row-' + index);
            $row.css('background', '#f5f5f5');
            $row.find('.fix-link-btn').remove();
            $row.find('td:last').html('<span style="color: #666;">Declined</span>');
            // Update counter
            var currentMissing = parseInt($('#audit-missing-opps').text()) || 0;
            if (currentMissing > 0) {
                $('#audit-missing-opps').text(currentMissing - 1);
            }
            // Store in session storage to keep hidden until next full audit
            var dismissed = JSON.parse(sessionStorage.getItem('dismissedAuditItems') || '[]');
            dismissed.push({
                postId: issue.postId,
                targetPostId: issue.targetPostId,
                dismissedAt: new Date().toISOString()
            });
            sessionStorage.setItem('dismissedAuditItems', JSON.stringify(dismissed));
            return;
        }

        var postData = {
            action: 'lendcity_fix_link',
            nonce: linkAuditNonce,
            post_id: issue.postId,
            fix_type: action,
            anchor: issue.anchor,
            old_url: issue.type === 'broken' ? issue.url : issue.currentUrl,
            new_url: issue.betterUrl || ''
        };

        $.post(ajaxurl, postData, function(response) {
            if (response.success) {
                // Mark row as fixed
                var $row = $('#issue-row-' + index);
                $row.css('background', '#d4edda');
                $row.find('.fix-link-btn').remove();
                $row.find('td:last').html('<span style="color: #28a745; font-weight: bold;">✓ ' + (action === 'ignore' ? 'Ignored' : 'Fixed') + '</span>');

                // Update counters
                if (action !== 'ignore') {
                    var currentBroken = parseInt($('#audit-broken-links').text()) || 0;
                    var currentSuboptimal = parseInt($('#audit-suboptimal-links').text()) || 0;
                    if (issue.type === 'broken' && currentBroken > 0) {
                        $('#audit-broken-links').text(currentBroken - 1);
                    } else if (issue.type === 'suboptimal' && currentSuboptimal > 0) {
                        $('#audit-suboptimal-links').text(currentSuboptimal - 1);
                    }
                }
            } else {
                alert('Error: ' + (response.data?.message || 'Failed to fix link'));
                $btn.prop('disabled', false).text(action === 'remove_broken' ? 'Remove Link' : (action === 'swap_link' ? 'Accept Better' : 'Ignore'));
            }
        }).fail(function() {
            alert('Request failed. Please try again.');
            $btn.prop('disabled', false).text(action === 'remove_broken' ? 'Remove Link' : (action === 'swap_link' ? 'Accept Better' : 'Ignore'));
        });
    });

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
    var linksPerPage = 25;
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
            action: 'lendcity_action', sub_action: 'get_links_page',
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
        var linkUrl = $row.data('current-url');
        var linkAnchor = $row.find('td:eq(1) code').text(); // Get anchor from the code element

        if (!confirm('Delete this link?')) return;

        $btn.prop('disabled', true).text('...');

        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'remove_single_link',
            nonce: nonce,
            post_id: sourceId,
            link_id: linkId,
            link_url: linkUrl,
            link_anchor: linkAnchor
        }, function(r) {
            if (r.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert('Error: ' + (r.data || 'Failed to delete'));
                $btn.prop('disabled', false).text('✕');
            }
        });
    });

    // Trust AI - Background
    $('#trust-ai-btn').on('click', function() {
        if (!confirm('Queue ALL items for background linking? Links will be auto-inserted.')) return;
        var $btn = $(this).prop('disabled', true).text('Queuing...');
        
        $.post(ajaxurl, {action: 'lendcity_action', sub_action: 'queue_all_linking', nonce: nonce}, function(r) {
            if (r.success) {
                alert('Queued ' + r.data.queued + ' items! Processing will run in background.');
                location.reload();
            } else {
                alert('Error: ' + r.data);
                $btn.prop('disabled', false).text('Trust AI — Background');
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
        
        var $btn = $(this).prop('disabled', true).html('Initializing...');
        
        // Initialize the queue
        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'init_bulk_queue',
            nonce: nonce,
            skip_with_links: skipWithLinks
        }, function(r) {
            $btn.prop('disabled', false).html('🚀 Start Bulk Linking');

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
                $('#bulk-link-status').show();
                updateQueueUI(r.data.queued, 0, 0, 0, r.data.skipped, r.data.queued, 'running', '');
                
                // Start processing
                startQueueProcessing();
            } else {
                alert('Error: ' + (r.data || 'Unknown error'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('🚀 Start Bulk Linking');
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
            action: 'lendcity_action', sub_action: 'pause_queue',
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
            action: 'lendcity_action', sub_action: 'clear_link_queue',
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
            action: 'lendcity_action', sub_action: 'process_queue_batch',
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
            action: 'lendcity_action', sub_action: 'get_queue_status',
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

        // Update button visibility based on state
        if (state === 'running') {
            $('#pause-queue-btn').show();
            $('#resume-queue-btn').hide();
        } else if (state === 'paused') {
            $('#pause-queue-btn').hide();
            $('#resume-queue-btn').show();
        } else if (state === 'complete') {
            $('#pause-queue-btn').hide();
            $('#resume-queue-btn').hide();
            $('#queue-progress-text').text('Complete! ' + links + ' links created');
        }
    }
    
    // Auto-refresh status if queue is active on page load
    <?php if (($queue_status['state'] ?? '') === 'running'): ?>
    $(function() {
        // Show status and resume processing if it was running
        $('#bulk-link-status').show();
        startQueueProcessing();
    });
    <?php endif; ?>
    
    // Update URLs
    $('#update-urls-btn').on('click', function() {
        var oldUrl = $('#old-url').val(), newUrl = $('#new-url').val();
        if (!oldUrl || !newUrl) { alert('Enter both URLs'); return; }
        
        $.post(ajaxurl, {action: 'lendcity_action', sub_action: 'update_smart_link_urls', nonce: nonce, old_url: oldUrl, new_url: newUrl}, function(r) {
            alert(r.success ? 'Updated ' + r.data.updated_count + ' posts' : 'Error');
        });
    });
    
    // Remove All Links
    $('#remove-all-btn').on('click', function() {
        var postId = $('#post-with-links').val();
        if (!postId) { alert('Select a post'); return; }
        if (!confirm('Remove all Claude links from this post?')) return;
        
        $.post(ajaxurl, {action: 'lendcity_action', sub_action: 'remove_all_smart_links', nonce: nonce, post_id: postId}, function() {
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
            action: 'lendcity_action', sub_action: 'change_link_target',
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
            action: 'lendcity_action', sub_action: 'delete_all_site_links',
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
        var isPillar = $row.find('.page-pillar').is(':checked') ? 1 : 0;
        var keywords = $row.find('.page-keywords').val();

        $btn.prop('disabled', true).text('...');

        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'save_page_seo',
            nonce: nonce,
            page_id: pageId,
            priority: priority,
            is_pillar: isPillar,
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
            action: 'lendcity_action', sub_action: 'generate_smart_metadata',
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
            action: 'lendcity_action', sub_action: 'get_smart_metadata_posts',
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
                    action: 'lendcity_action', sub_action: 'generate_smart_metadata',
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
            action: 'lendcity_action', sub_action: 'get_meta_queue_status',
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
            action: 'lendcity_action', sub_action: 'init_meta_queue',
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
            action: 'lendcity_action', sub_action: 'clear_meta_queue',
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

    // ========== SEO HEALTH MONITOR (Paginated) ==========
    function runSeoHealthScan(reset) {
        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'get_seo_health_issues',
            nonce: nonce,
            reset: reset ? 'true' : 'false'
        }, function(response) {
            if (!response.success) {
                $('#seo-health-loading').html('<p style="color: #dc3545;">Error: ' + response.data + '</p>');
                $('#scan-seo-health-btn').prop('disabled', false).text('Scan for SEO Issues');
                return;
            }

            var data = response.data;

            if (data.status === 'scanning') {
                // Update progress
                $('#seo-health-loading').html(
                    '<div style="margin-bottom: 10px;"><strong>Scanning... ' + data.percent + '%</strong></div>' +
                    '<div style="background: #e0e0e0; height: 20px; border-radius: 4px; overflow: hidden;">' +
                    '<div style="background: linear-gradient(90deg, #f093fb, #f5576c); height: 100%; width: ' + data.percent + '%; transition: width 0.3s;"></div>' +
                    '</div>' +
                    '<p style="margin-top: 10px; font-size: 12px; color: #666;">Processed ' + data.processed + ' of ' + data.total + ' posts</p>'
                );
                // Continue scanning
                setTimeout(function() { runSeoHealthScan(false); }, 500);
            } else {
                // Complete - show results
                $('#seo-health-loading').hide();
                $('#seo-health-content').show();
                $('#scan-seo-health-btn').prop('disabled', false).text('Scan for SEO Issues');

                if (data.issues && data.issues.length > 0) {
                    var html = '<p style="margin-top: 0;"><strong>' + data.count + ' SEO issues found</strong></p>';
                    html += '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
                    html += '<thead><tr><th>Page/Post</th><th>Issues</th><th>Top Anchors</th><th>Action</th></tr></thead><tbody>';

                    data.issues.forEach(function(issue) {
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
            }
        }).fail(function() {
            $('#seo-health-loading').html('<p style="color: #dc3545;">Request failed. Please try again.</p>');
            $('#scan-seo-health-btn').prop('disabled', false).text('Scan for SEO Issues');
        });
    }

    $('#scan-seo-health-btn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Scanning...');
        $('#seo-health-results').show();
        $('#seo-health-loading').show().html('<div style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none;"></span> Starting scan...</div>');
        $('#seo-health-content').hide();

        runSeoHealthScan(true);
    });

    // Auto-fix SEO
    $(document).on('click', '.auto-fix-seo-btn', function() {
        var $btn = $(this);
        var postId = $btn.data('post-id');
        $btn.prop('disabled', true).text('Fixing...');

        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'auto_fix_seo',
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


    // ========== v12.1 BACKGROUND QUEUE HANDLERS ==========

    // Background queue status polling
    var bgQueuePollInterval = null;

    function updateBackgroundQueueUI(data) {
        var anyActive = false;

        // Catalog
        if (data.catalog) {
            var cat = data.catalog;
            var catCard = $('#bg-catalog-status');
            var catProcessed = (cat.total || 0) - (cat.remaining || 0);
            var catPct = cat.total > 0 ? Math.round(catProcessed / cat.total * 100) : 0;
            catCard.find('.bg-status-badge').text(cat.status || 'idle').css('background', cat.status === 'running' ? '#00c853' : '#666');
            catCard.find('.bg-progress-bar').css('width', catPct + '%');
            catCard.find('.bg-processed').text(catProcessed);
            catCard.find('.bg-total').text(cat.total || 0);
            catCard.css('opacity', cat.status === 'running' ? '1' : '0.5');
            if (cat.status === 'running') anyActive = true;
        }

        // Linker
        if (data.linking) {
            var link = data.linking;
            var linkCard = $('#bg-linker-status');
            var linkPct = link.total > 0 ? Math.round((link.processed || 0) / link.total * 100) : 0;
            linkCard.find('.bg-status-badge').text(link.state || 'idle').css('background', link.state === 'running' ? '#00c853' : '#666');
            linkCard.find('.bg-progress-bar').css('width', linkPct + '%');
            linkCard.find('.bg-processed').text(link.processed || 0);
            linkCard.find('.bg-total').text(link.total || 0);
            linkCard.css('opacity', link.state === 'running' ? '1' : '0.5');
            if (link.state === 'running') anyActive = true;
        }

        // Metadata
        if (data.metadata) {
            var meta = data.metadata;
            var metaCard = $('#bg-meta-status');
            var metaProcessed = (meta.total || 0) - (meta.remaining || 0);
            var metaPct = meta.total > 0 ? Math.round(metaProcessed / meta.total * 100) : 0;
            metaCard.find('.bg-status-badge').text(meta.status || 'idle').css('background', meta.status === 'running' ? '#00c853' : '#666');
            metaCard.find('.bg-progress-bar').css('width', metaPct + '%');
            metaCard.find('.bg-processed').text(metaProcessed);
            metaCard.find('.bg-total').text(meta.total || 0);
            metaCard.css('opacity', meta.status === 'running' ? '1' : '0.5');
            if (meta.status === 'running') anyActive = true;
        }

        // Show/hide dashboard
        if (anyActive) {
            $('#background-queue-dashboard').show();
        } else if (bgQueuePollInterval) {
            // Keep showing for a moment after completion, then hide
            setTimeout(function() {
                if (!anyActive) {
                    $('#background-queue-dashboard').hide();
                }
            }, 3000);
            clearInterval(bgQueuePollInterval);
            bgQueuePollInterval = null;
        }
    }

    function pollBackgroundQueues() {
        $.post(ajaxurl, {
            action: 'lendcity_action', sub_action: 'get_all_queue_statuses',
            nonce: nonce
        }, function(r) {
            if (r.success) {
                updateBackgroundQueueUI(r.data);
            }
        });
    }

    function startBackgroundQueuePolling() {
        $('#background-queue-dashboard').show();
        if (!bgQueuePollInterval) {
            bgQueuePollInterval = setInterval(pollBackgroundQueues, 5000);
        }
        pollBackgroundQueues(); // Immediate first poll
    }

    // Stop All Queues
    $('#stop-all-queues-btn').on('click', function() {
        if (!confirm('Stop ALL background queues?')) return;
        var $btn = $(this).prop('disabled', true).text('Stopping...');

        // Stop all queues in parallel
        $.when(
            $.post(ajaxurl, { action: 'lendcity_action', sub_action: 'clear_catalog_queue', nonce: nonce }),
            $.post(ajaxurl, { action: 'lendcity_action', sub_action: 'clear_link_queue', nonce: nonce }),
            $.post(ajaxurl, { action: 'lendcity_action', sub_action: 'clear_meta_queue', nonce: nonce })
        ).then(function() {
            $btn.prop('disabled', false).text('Stop All Queues');
            alert('All queues stopped.');
            if (bgQueuePollInterval) {
                clearInterval(bgQueuePollInterval);
                bgQueuePollInterval = null;
            }
            $('#background-queue-dashboard').hide();
        });
    });

    // Start polling if any queue was already running on page load
    <?php if ($any_queue_active): ?>
    startBackgroundQueuePolling();
    <?php endif; ?>
});
</script>
