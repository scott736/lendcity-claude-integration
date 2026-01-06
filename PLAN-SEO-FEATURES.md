# LendCity Tools: Built-in SEO Module Plan

## Goal
Replace SEOPress with a lightweight, integrated SEO module that leverages the plugin's existing AI-powered metadata generation while eliminating dependency on external SEO plugins.

---

## Current State Analysis

### What We Already Have (Keep & Enhance)
- AI-powered metadata generation via Claude API
- Smart SEO Health Monitor with auto-fix
- Priority pages & target keywords system
- Semantic keyword matching (TF-IDF weighted)
- Background processing queues for scale

### What SEOPress Currently Provides (Need to Replace)
- Frontend meta tag output (titles, descriptions)
- Open Graph & Twitter Card tags
- XML Sitemaps
- Canonical URLs
- Schema/Structured Data (JSON-LD)
- Robots meta directives
- Content analysis scoring (bloated - skip this)
- Redirect management (overkill for most sites)
- Google Analytics integration (use native GA4 - skip)

---

## Recommended SEO Features

### Tier 1: Essential (Must Have)

#### 1. **Meta Tags Output Engine**
Replace SEOPress frontend output with native implementation.

```php
// Hook into wp_head with priority 1
add_action('wp_head', 'lendcity_seo_meta_tags', 1);
```

**Features:**
- SEO title tag (with fallback hierarchy)
- Meta description
- Canonical URL (self-referencing + custom override)
- Robots meta (index/noindex, follow/nofollow)
- Viewport meta (mobile-first)

**Post Meta Keys (migrate from SEOPress):**
| New Key | Old SEOPress Key | Purpose |
|---------|------------------|---------|
| `_lendcity_seo_title` | `_seopress_titles_title` | Custom SEO title |
| `_lendcity_seo_desc` | `_seopress_titles_desc` | Meta description |
| `_lendcity_seo_canonical` | `_seopress_robots_canonical` | Canonical URL |
| `_lendcity_seo_noindex` | `_seopress_robots_index` | Noindex flag |
| `_lendcity_focus_kw` | `_seopress_analysis_target_kw` | Focus keyphrase |

**Title Fallback Hierarchy:**
1. Custom SEO title (`_lendcity_seo_title`)
2. Post title + Site name
3. Site tagline (homepage only)

---

#### 2. **Open Graph & Social Meta Tags**
Essential for social sharing (Facebook, LinkedIn, WhatsApp).

**Tags to Output:**
```html
<meta property="og:title" content="..." />
<meta property="og:description" content="..." />
<meta property="og:image" content="..." />
<meta property="og:url" content="..." />
<meta property="og:type" content="article|website" />
<meta property="og:site_name" content="LendCity" />
<meta property="og:locale" content="en_CA" />

<!-- Twitter Cards -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="..." />
<meta name="twitter:description" content="..." />
<meta name="twitter:image" content="..." />
```

**Image Fallback Hierarchy:**
1. Custom OG image (`_lendcity_seo_og_image`)
2. Featured image
3. First image in content
4. Default site OG image (from settings)

---

#### 3. **XML Sitemap Generator**
Lightweight, auto-updating sitemaps.

**Implementation:**
- Generate on-demand with caching (transient, 1 hour)
- Single sitemap for sites < 1000 posts
- Sitemap index with split sitemaps for larger sites
- Exclude noindex posts automatically
- Exclude password-protected posts
- Exclude draft/pending/private posts

**Sitemap Types:**
- `/sitemap.xml` - Main sitemap index
- `/sitemap-posts.xml` - Blog posts
- `/sitemap-pages.xml` - Static pages
- `/sitemap-categories.xml` - Category archives (optional)
- `/sitemap-images.xml` - Image sitemap (optional, for image search)

**Sitemap Entry Format:**
```xml
<url>
  <loc>https://lendcity.ca/post-slug/</loc>
  <lastmod>2026-01-05</lastmod>
  <changefreq>weekly</changefreq>
  <priority>0.8</priority>
</url>
```

**Priority Calculation:**
- Homepage: 1.0
- P1 priority pages: 0.9
- P2-P3 pages: 0.8
- Regular posts: 0.6
- Old posts (>1 year): 0.4

---

#### 4. **Schema.org Structured Data (JSON-LD)**
Essential for rich snippets in search results.

**Schema Types to Implement:**

1. **Organization** (site-wide)
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "LendCity",
  "url": "https://lendcity.ca",
  "logo": "https://lendcity.ca/logo.png",
  "sameAs": ["https://facebook.com/lendcity", ...]
}
```

2. **WebSite** (homepage, enables sitelinks search)
```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "url": "https://lendcity.ca",
  "name": "LendCity",
  "potentialAction": {
    "@type": "SearchAction",
    "target": "https://lendcity.ca/?s={search_term_string}",
    "query-input": "required name=search_term_string"
  }
}
```

3. **Article** (blog posts)
```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "...",
  "description": "...",
  "image": "...",
  "author": { "@type": "Person", "name": "..." },
  "publisher": { "@type": "Organization", ... },
  "datePublished": "...",
  "dateModified": "..."
}
```

4. **BreadcrumbList** (navigation breadcrumbs)
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [...]
}
```

5. **FAQPage** (for posts with FAQ blocks)
```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [{ "@type": "Question", ... }]
}
```

6. **LocalBusiness / FinancialService** (optional, for company pages)

---

#### 5. **Archive & Taxonomy SEO**
SEO for category, tag, author, and date archives.

**Features:**
- Custom titles/descriptions for categories and tags
- Author archive SEO (or noindex option)
- Date archives set to noindex by default (duplicate content risk)
- Pagination handling with proper canonicals
- Custom post type archive support

**Archive Meta Keys:**
| Key | Purpose |
|-----|---------|
| `_lendcity_term_seo_title` | Term custom title |
| `_lendcity_term_seo_desc` | Term meta description |
| `_lendcity_term_noindex` | Noindex this archive |

**Pagination Rules:**
- Page 1: Self-referencing canonical
- Page 2+: Canonical to self (NOT to page 1)
- rel="next" / rel="prev" for paginated series
- Deep pagination (page 10+): Consider noindex

---

### Tier 2: Important (Should Have)

#### 6. **Robots.txt Virtual Handler**
Dynamic robots.txt generation.

```php
// Intercept robots.txt requests
add_filter('robots_txt', 'lendcity_robots_txt', 10, 2);
```

**Default Output:**
```
User-agent: *
Disallow: /wp-admin/
Disallow: /wp-includes/
Allow: /wp-admin/admin-ajax.php

Sitemap: https://lendcity.ca/sitemap.xml
```

**Admin Settings:**
- Block specific paths
- Block specific user-agents
- Custom rules input

---

#### 7. **SEO Metabox in Post Editor**
Replace SEOPress metabox with lightweight version.

**Metabox Fields:**
- SEO Title (with character counter, 50-60 chars)
- Meta Description (with character counter, 150-160 chars)
- Focus Keyphrase
- Social Image (media picker)
- Canonical URL (optional override)
- Robots: noindex/nofollow checkboxes

**Preview Panel:**
- Google SERP preview (live updating)
- Social share preview (Facebook/Twitter cards)

**Integration:**
- Use existing Claude metadata as suggestions
- "Generate with AI" button for on-demand generation
- Gutenberg sidebar panel + Classic Editor metabox

---

#### 8. **Breadcrumbs System**
SEO-friendly breadcrumb navigation.

**Shortcode:**
```php
[lendcity_breadcrumbs]
```

**PHP Function:**
```php
<?php lendcity_breadcrumbs(); ?>
```

**Features:**
- Schema.org BreadcrumbList markup included
- Customizable separator
- Home link configurable
- Category/archive support

---

#### 9. **301 Redirect Manager**
Simple redirect management (lightweight).

**Storage:** Options table (JSON array) or custom table for scale

**Features:**
- Add/edit/delete redirects via admin
- Support 301, 302, 307 codes
- Regex pattern matching (optional)
- Import/export redirects
- 404 logging to identify broken links

---

#### 10. **Bulk SEO Editor**
Admin interface for bulk SEO edits.

**Features:**
- Table view of all posts with SEO data
- Inline editing of titles/descriptions
- Filter by: missing meta, missing image, noindex status
- Bulk actions: noindex, generate AI meta, reset to default
- Export to CSV for review

---

#### 11. **Import/Export Tool**
Data portability and backup.

**Features:**
- Export all SEO settings and post meta to JSON
- Import from JSON backup
- Import from SEOPress (migration wizard)
- Import from Yoast/Rank Math (future-proofing)

---

### Tier 3: Nice to Have (Consider for Future)

#### 12. **Internal Link Suggestions in Editor**
Leverage existing catalog for real-time suggestions.

**Features:**
- Sidebar panel showing relevant internal links
- Drag-and-drop to insert links
- Based on semantic matching from catalog

---

#### 13. **SEO Score Dashboard**
Simplified content scoring (not the bloated SEOPress version).

**Metrics:**
- Title length (green/yellow/red)
- Description length
- Focus keyword in title/description/H1
- Internal links count
- External links count
- Image alt text coverage

---

#### 14. **REST API Endpoints**
For headless/decoupled frontend support.

**Endpoints:**
- `GET /wp-json/lendcity/v1/seo/{post_id}` - Get SEO data for a post
- `GET /wp-json/lendcity/v1/seo/schema/{post_id}` - Get JSON-LD schema
- `GET /wp-json/lendcity/v1/sitemap` - Sitemap data as JSON

---

## Features to SKIP (Keep Lightweight)

| Feature | Reason to Skip |
|---------|----------------|
| Content readability scoring | Bloat, subjective, slows editor |
| Keyword density checker | Outdated SEO practice |
| Google Analytics integration | Use native GA4 or Site Kit |
| Google Search Console integration | Use native GSC |
| Local SEO (maps, hours) | Overkill unless needed |
| WooCommerce SEO | Not using WooCommerce |
| Image SEO (auto alt text) | Claude already handles content |
| Link attributes (nofollow manager) | Manual control sufficient |
| Social profiles settings | One-time setup, use theme |

---

## Risk Analysis & Mitigation

### Risk 1: SEO Rankings Volatility During Migration
**Severity: HIGH | Likelihood: MEDIUM**

When switching SEO plugins, Google may temporarily see inconsistencies:
- Meta tag format changes trigger re-evaluation
- Sitemap URL changes require rediscovery
- Schema changes affect rich snippets

**Mitigation:**
- Run both plugins in parallel for 2-4 weeks
- Submit new sitemap to Google Search Console immediately
- Monitor GSC for coverage issues daily
- Keep SEOPress installed (deactivated) as rollback option

---

### Risk 2: Duplicate Meta Tags from Theme
**Severity: MEDIUM | Likelihood: HIGH**

Many themes output their own:
- Title tags (`title-tag` theme support)
- Open Graph meta (Genesis, Jenga, etc.)
- Schema markup

**Mitigation:**
- Detect and remove conflicting theme output
- Check `current_theme_supports()` before output
- Add admin setting to disable specific outputs
- Test with theme's SEO features disabled

**Detection Code:**
```php
// Check for theme OG tags
add_action('wp_head', function() {
    global $lendcity_detected_og;
    $lendcity_detected_og = false;
}, 0);

add_action('wp_head', function() {
    global $lendcity_detected_og;
    if ($lendcity_detected_og) {
        // Log warning in admin
    }
}, 999);
```

---

### Risk 3: Plugin Conflicts
**Severity: MEDIUM | Likelihood: MEDIUM**

Other plugins that might conflict:
- **Jetpack** - Has Open Graph output
- **AMP plugins** - Need meta in AMP templates
- **Caching plugins** - May cache old meta tags
- **Other SEO plugins** - If user installs Yoast later

**Mitigation:**
- Detect active conflicting plugins on activation
- Show admin notice with instructions
- Add compatibility layer for Jetpack (disable its OG)
- Provide cache-busting guidance

---

### Risk 4: Schema Validation Errors
**Severity: MEDIUM | Likelihood: MEDIUM**

Invalid JSON-LD will:
- Fail Google rich results
- Show errors in Search Console
- Potentially hurt click-through rates

**Mitigation:**
- Build schema with proper JSON escaping
- Validate schema on save
- Admin warning if schema invalid
- Link to Google Rich Results Test

---

### Risk 5: Pagination SEO Issues
**Severity: MEDIUM | Likelihood: MEDIUM**

Common mistakes:
- Page 2+ canonicalizing to page 1 (wrong!)
- Missing rel="next"/"prev"
- Deep pagination indexed and diluting authority

**Mitigation:**
- Page 2+ canonicalizes to itself
- Implement rel="next"/"prev" properly
- Option to noindex pages beyond N

---

### Risk 6: Missing Canonical Causing Duplicate Content
**Severity: HIGH | Likelihood: LOW**

If canonical tags fail to output:
- Google indexes URL parameters as separate pages
- HTTP/HTTPS and www/non-www treated as duplicates
- Paginated URLs compete with main URL

**Mitigation:**
- Always output canonical (fallback to current URL)
- SEO Health Check validates canonical presence
- Alert on pages missing canonical

---

### Risk 7: WordPress 6.x Compatibility
**Severity: LOW | Likelihood: MEDIUM**

WordPress 6.1+ has native:
- `wp_robots` filter
- Block theme SEO considerations
- Site Editor title tag handling

**Mitigation:**
- Use WordPress filters properly, not override
- Test with both classic and block themes
- Hook into `wp_robots` instead of replacing

---

### Risk 8: Category/Archive Pages Losing Rankings
**Severity: MEDIUM | Likelihood: MEDIUM**

If archive SEO not implemented properly:
- Categories lose custom titles/descriptions
- Archive pages get generic meta
- Taxonomies may get noindexed accidentally

**Mitigation:**
- Implement archive SEO in Tier 1
- Migrate SEOPress term meta
- Default to index (not noindex) for archives

---

## SEO Health Check & Monitoring System

### Overview
Automated testing system that runs checks and alerts you to any SEO issues before they impact rankings.

### Admin Dashboard: "SEO Health Monitor"

```
┌─────────────────────────────────────────────────────────────┐
│  SEO Health Monitor                          [Run Full Scan] │
├─────────────────────────────────────────────────────────────┤
│  Overall Score: 94/100  ████████████████████░░  HEALTHY     │
├─────────────────────────────────────────────────────────────┤
│  ✅ Meta Tags Output      All pages have valid meta tags    │
│  ✅ Canonical URLs        All canonicals properly set       │
│  ⚠️  Schema Validation    2 pages have schema warnings      │
│  ✅ Sitemap Status        Sitemap accessible, 847 URLs      │
│  ✅ Robots.txt            Valid, sitemap referenced         │
│  ⚠️  Duplicate Meta       3 posts share same description    │
│  ✅ Image SEO             98% of images have alt text       │
│  ❌ 404 Errors            12 broken internal links found    │
└─────────────────────────────────────────────────────────────┘
```

### Check Categories

#### 1. Critical Checks (Blocking Issues)
| Check | What It Does | Alert Threshold |
|-------|--------------|-----------------|
| Meta tags output | Verifies title/description in `<head>` | Any page missing |
| Canonical present | Checks canonical URL exists | Any page missing |
| Sitemap accessible | Fetches /sitemap.xml, validates XML | HTTP error or invalid |
| Robots.txt valid | Parses robots.txt for errors | Parse errors |
| No duplicate titles | Compares all SEO titles | Any exact duplicates |
| Schema valid JSON | Validates JSON-LD syntax | Parse errors |

#### 2. Warning Checks (Should Fix)
| Check | What It Does | Alert Threshold |
|-------|--------------|-----------------|
| Title length | Checks 50-60 char optimal | <30 or >70 chars |
| Description length | Checks 150-160 char optimal | <100 or >170 chars |
| Duplicate descriptions | Finds matching descriptions | >2 posts same desc |
| Missing OG image | Checks for social image | >10% of posts |
| Thin content in sitemap | Flags <300 word posts | In sitemap |
| Orphan pages | No internal links pointing in | Any found |
| Schema warnings | Google validator warnings | Any warnings |

#### 3. Info Checks (Optimization)
| Check | What It Does | Alert Threshold |
|-------|--------------|-----------------|
| Focus keyword usage | Keyword in title/desc/H1 | Not in all 3 |
| Internal link count | Links per post | <2 internal links |
| External link count | Outbound links | 0 external links |
| Image alt coverage | Alt text on images | <90% coverage |
| Heading structure | H1/H2/H3 hierarchy | Missing H1 |

### Automated Scanning

#### Scheduled Scans
```php
// Daily quick scan (critical checks only)
add_action('lendcity_daily_seo_scan', 'run_critical_seo_checks');

// Weekly full scan (all checks)
add_action('lendcity_weekly_seo_scan', 'run_full_seo_scan');
```

#### Email Alerts
```
Subject: ⚠️ SEO Alert: 3 issues detected on LendCity

LendCity SEO Health Monitor detected the following issues:

CRITICAL:
❌ Sitemap returning 404 error
   URL: https://lendcity.ca/sitemap.xml
   Action: Check rewrite rules, clear cache

WARNINGS:
⚠️ 2 new posts missing meta descriptions
   - /blog/new-mortgage-rates-january/
   - /blog/refinancing-guide-2026/
   Action: Add descriptions or run AI generation

⚠️ 5 new 404 errors logged
   Most common: /old-page-slug/ (47 hits)
   Action: Set up redirect to relevant page

View full report: https://lendcity.ca/wp-admin/admin.php?page=lendcity-seo-health
```

### Pre-Migration Audit

Before switching from SEOPress, run comprehensive audit:

```
┌─────────────────────────────────────────────────────────────┐
│  Pre-Migration SEO Audit                                    │
├─────────────────────────────────────────────────────────────┤
│  Current State Snapshot                                     │
│  ─────────────────────                                      │
│  Total Posts: 847                                           │
│  Posts with SEO Title: 712 (84%)                           │
│  Posts with Meta Desc: 698 (82%)                           │
│  Posts with Focus KW: 445 (53%)                            │
│                                                             │
│  Key Pages Baseline Rankings                                │
│  ───────────────────────────                                │
│  /mortgage-calculator/     "mortgage calculator" → #4       │
│  /refinance-guide/         "refinance guide canada" → #7    │
│  /best-mortgage-rates/     "best mortgage rates" → #12      │
│                                                             │
│  [Export Baseline Report]  [Start Migration]                │
└─────────────────────────────────────────────────────────────┘
```

**Baseline Data Captured:**
- All current SEO titles and descriptions
- Google Search Console position data (manual input)
- Current sitemap URL count
- Schema types in use
- Key page rankings for top keywords

### Post-Migration Monitoring

#### Day 1-7: Critical Watch Period
- **Hourly:** Sitemap accessibility
- **Every 4 hours:** Random sample of 10 pages for meta output
- **Daily:** Full critical checks

#### Week 2-4: Stabilization Period
- **Daily:** Critical checks
- **Weekly:** Full scan
- **Compare:** Rankings vs baseline

#### Alert Dashboard
```
┌─────────────────────────────────────────────────────────────┐
│  Migration Monitor - Day 5 of 14                            │
├─────────────────────────────────────────────────────────────┤
│  Status: HEALTHY ✅                                         │
│                                                             │
│  Sitemap:        ✅ Indexed (842/847 URLs)                 │
│  Meta Output:    ✅ All sampled pages correct              │
│  Schema:         ✅ No new errors in GSC                   │
│  Rankings:       ⚠️ Minor fluctuation (-1.2 avg position)  │
│                                                             │
│  Recent Changes Detected:                                   │
│  • Google recrawled 127 pages in last 24h                  │
│  • 3 new pages added to index                              │
│  • 1 page dropped from index (investigating...)            │
│                                                             │
│  [View Detailed Report]  [Compare to Baseline]              │
└─────────────────────────────────────────────────────────────┘
```

### Implementation: SEO Test Class

```php
class LendCity_SEO_Health_Check {

    private $checks = [];
    private $results = [];

    /**
     * Register all health checks
     */
    public function __construct() {
        $this->register_checks();
    }

    /**
     * Run all checks or specific category
     */
    public function run_scan($category = 'all') {
        $this->results = [];

        foreach ($this->checks as $check) {
            if ($category !== 'all' && $check['category'] !== $category) {
                continue;
            }

            $result = call_user_func($check['callback']);
            $this->results[$check['id']] = [
                'name' => $check['name'],
                'category' => $check['category'],
                'status' => $result['status'], // pass, warning, fail
                'message' => $result['message'],
                'details' => $result['details'] ?? [],
                'fix_url' => $result['fix_url'] ?? null,
            ];
        }

        // Store results
        update_option('lendcity_seo_health_results', $this->results);
        update_option('lendcity_seo_health_last_scan', time());

        // Send alerts if needed
        $this->maybe_send_alerts();

        return $this->results;
    }

    /**
     * Individual check: Meta tags present
     */
    public function check_meta_tags_present() {
        global $wpdb;

        $posts_without_meta = $wpdb->get_results("
            SELECT p.ID, p.post_title
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                AND pm.meta_key = '_lendcity_seo_desc'
            WHERE p.post_status = 'publish'
            AND p.post_type IN ('post', 'page')
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT 50
        ");

        $count = count($posts_without_meta);

        if ($count === 0) {
            return [
                'status' => 'pass',
                'message' => 'All published posts have meta descriptions',
            ];
        }

        return [
            'status' => $count > 10 ? 'fail' : 'warning',
            'message' => "{$count} posts missing meta descriptions",
            'details' => array_map(function($p) {
                return ['id' => $p->ID, 'title' => $p->post_title];
            }, $posts_without_meta),
            'fix_url' => admin_url('admin.php?page=lendcity-bulk-seo'),
        ];
    }

    /**
     * Individual check: Sitemap accessible
     */
    public function check_sitemap_accessible() {
        $sitemap_url = home_url('/sitemap.xml');
        $response = wp_remote_get($sitemap_url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            return [
                'status' => 'fail',
                'message' => 'Sitemap not accessible: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [
                'status' => 'fail',
                'message' => "Sitemap returned HTTP {$code}",
            ];
        }

        $body = wp_remote_retrieve_body($response);
        if (strpos($body, '<?xml') === false) {
            return [
                'status' => 'fail',
                'message' => 'Sitemap is not valid XML',
            ];
        }

        // Count URLs
        preg_match_all('/<loc>/', $body, $matches);
        $url_count = count($matches[0]);

        return [
            'status' => 'pass',
            'message' => "Sitemap valid with {$url_count} URLs",
            'details' => ['url_count' => $url_count],
        ];
    }

    /**
     * Individual check: Duplicate titles
     */
    public function check_duplicate_titles() {
        global $wpdb;

        $duplicates = $wpdb->get_results("
            SELECT meta_value as title, COUNT(*) as count,
                   GROUP_CONCAT(post_id) as post_ids
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_lendcity_seo_title'
            AND meta_value != ''
            GROUP BY meta_value
            HAVING count > 1
            LIMIT 20
        ");

        if (empty($duplicates)) {
            return [
                'status' => 'pass',
                'message' => 'No duplicate SEO titles found',
            ];
        }

        return [
            'status' => 'warning',
            'message' => count($duplicates) . ' duplicate SEO titles found',
            'details' => $duplicates,
        ];
    }

    /**
     * Individual check: Schema validation
     */
    public function check_schema_valid() {
        // Sample 10 random posts
        $posts = get_posts([
            'numberposts' => 10,
            'orderby' => 'rand',
            'post_status' => 'publish',
        ]);

        $errors = [];

        foreach ($posts as $post) {
            $schema = $this->get_post_schema($post->ID);
            if ($schema) {
                $decoded = json_decode($schema);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = [
                        'post_id' => $post->ID,
                        'title' => $post->post_title,
                        'error' => json_last_error_msg(),
                    ];
                }
            }
        }

        if (empty($errors)) {
            return [
                'status' => 'pass',
                'message' => 'Schema JSON valid on sampled pages',
            ];
        }

        return [
            'status' => 'fail',
            'message' => count($errors) . ' pages have invalid schema JSON',
            'details' => $errors,
        ];
    }

    /**
     * Individual check: 404 errors (from log)
     */
    public function check_404_errors() {
        $errors = get_option('lendcity_404_log', []);
        $recent = array_filter($errors, function($e) {
            return $e['time'] > strtotime('-7 days');
        });

        $count = count($recent);

        if ($count === 0) {
            return [
                'status' => 'pass',
                'message' => 'No 404 errors in the past 7 days',
            ];
        }

        // Group by URL
        $grouped = [];
        foreach ($recent as $error) {
            $url = $error['url'];
            if (!isset($grouped[$url])) {
                $grouped[$url] = 0;
            }
            $grouped[$url]++;
        }
        arsort($grouped);

        return [
            'status' => $count > 20 ? 'fail' : 'warning',
            'message' => "{$count} 404 errors in the past 7 days",
            'details' => array_slice($grouped, 0, 10, true),
            'fix_url' => admin_url('admin.php?page=lendcity-redirects'),
        ];
    }

    /**
     * Check for theme/plugin conflicts
     */
    public function check_meta_conflicts() {
        // Check a published post's HTML for duplicate meta
        $post = get_posts(['numberposts' => 1, 'post_status' => 'publish'])[0] ?? null;

        if (!$post) {
            return ['status' => 'pass', 'message' => 'No posts to check'];
        }

        $url = get_permalink($post->ID);
        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return [
                'status' => 'warning',
                'message' => 'Could not fetch page to check for conflicts',
            ];
        }

        $html = wp_remote_retrieve_body($response);

        // Count meta descriptions
        preg_match_all('/<meta[^>]+name=["\']description["\'][^>]*>/i', $html, $desc_matches);
        $desc_count = count($desc_matches[0]);

        // Count OG titles
        preg_match_all('/<meta[^>]+property=["\']og:title["\'][^>]*>/i', $html, $og_matches);
        $og_count = count($og_matches[0]);

        // Count canonical
        preg_match_all('/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html, $canonical_matches);
        $canonical_count = count($canonical_matches[0]);

        $issues = [];
        if ($desc_count > 1) $issues[] = "Multiple meta descriptions ({$desc_count})";
        if ($og_count > 1) $issues[] = "Multiple og:title tags ({$og_count})";
        if ($canonical_count > 1) $issues[] = "Multiple canonical tags ({$canonical_count})";

        if (empty($issues)) {
            return [
                'status' => 'pass',
                'message' => 'No duplicate meta tags detected',
            ];
        }

        return [
            'status' => 'fail',
            'message' => 'Duplicate meta tags detected (theme/plugin conflict)',
            'details' => $issues,
        ];
    }

    /**
     * Send email alerts for failures
     */
    private function maybe_send_alerts() {
        $failures = array_filter($this->results, function($r) {
            return $r['status'] === 'fail';
        });

        if (empty($failures)) {
            return;
        }

        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = "⚠️ SEO Alert: " . count($failures) . " issues on {$site_name}";

        $body = "LendCity SEO Health Monitor detected issues:\n\n";
        foreach ($failures as $id => $result) {
            $body .= "❌ {$result['name']}\n";
            $body .= "   {$result['message']}\n\n";
        }

        $body .= "View full report: " . admin_url('admin.php?page=lendcity-seo-health');

        wp_mail($admin_email, $subject, $body);
    }
}
```

### CLI Commands for Testing

```bash
# Run full SEO health scan
wp lendcity seo-scan

# Run specific check
wp lendcity seo-scan --check=sitemap

# Compare current state to baseline
wp lendcity seo-compare --baseline=2026-01-01

# Export SEO audit report
wp lendcity seo-export --format=csv --output=/tmp/seo-audit.csv
```

---

## Implementation Architecture

### New File Structure
```
includes/
├── class-seo-module.php          # Main SEO class (meta output, hooks)
├── class-seo-sitemap.php         # XML sitemap generator
├── class-seo-schema.php          # JSON-LD structured data
├── class-seo-metabox.php         # Editor metabox/sidebar
├── class-seo-redirects.php       # Redirect manager
├── class-seo-health-check.php    # Health monitoring & tests
└── class-seo-migration.php       # SEOPress migration tool
```

### Class Design

```php
class LendCity_SEO_Module {
    // Singleton instance
    private static $instance = null;

    // Initialize hooks
    public function init() {
        // Frontend meta tags
        add_action('wp_head', [$this, 'output_meta_tags'], 1);
        add_action('wp_head', [$this, 'output_schema'], 5);

        // Remove WordPress default (extend, don't fight)
        add_filter('wp_robots', [$this, 'filter_robots'], 10, 1);
        remove_action('wp_head', 'rel_canonical');

        // Sitemap
        add_action('init', [$this, 'register_sitemap_rewrites']);
        add_action('template_redirect', [$this, 'render_sitemap']);

        // Robots.txt
        add_filter('robots_txt', [$this, 'custom_robots_txt'], 10, 2);

        // Admin metabox
        add_action('add_meta_boxes', [$this, 'add_seo_metabox']);
        add_action('save_post', [$this, 'save_seo_meta']);

        // Gutenberg sidebar
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_gutenberg_sidebar']);

        // Health checks
        add_action('lendcity_daily_seo_scan', [$this, 'run_daily_scan']);
        add_action('lendcity_weekly_seo_scan', [$this, 'run_weekly_scan']);

        // Conflict detection
        add_action('admin_init', [$this, 'detect_conflicts']);
    }

    /**
     * Detect conflicting plugins/themes
     */
    public function detect_conflicts() {
        $conflicts = [];

        // Check for other SEO plugins
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            $conflicts[] = 'Yoast SEO is active - will cause duplicate meta tags';
        }
        if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
            $conflicts[] = 'Rank Math is active - will cause duplicate meta tags';
        }
        if (class_exists('Jetpack') && Jetpack::is_module_active('publicize')) {
            $conflicts[] = 'Jetpack Open Graph is active - may cause duplicate OG tags';
        }

        // Check theme SEO
        if (current_theme_supports('title-tag')) {
            // This is fine, we'll use document_title_parts filter
        }

        if (!empty($conflicts)) {
            add_action('admin_notices', function() use ($conflicts) {
                echo '<div class="notice notice-warning"><p><strong>LendCity SEO Conflicts Detected:</strong></p><ul>';
                foreach ($conflicts as $c) {
                    echo "<li>{$c}</li>";
                }
                echo '</ul></div>';
            });
        }
    }
}
```

### Migration Strategy

#### Phase 0: Pre-Migration (1 week before)
1. Run pre-migration audit
2. Export baseline rankings for key pages
3. Document current SEOPress settings
4. Take full site backup

#### Phase 1: Parallel Operation (2-4 weeks)
1. Install new SEO module alongside SEOPress
2. New module reads both old and new meta keys
3. Admin toggle to switch output source
4. Run health checks daily

#### Phase 2: Data Migration (1 day)
1. Bulk migrate SEOPress meta to LendCity meta
2. Verify data integrity with health checks
3. Update Smart Metadata generator to write new keys

#### Phase 3: Switchover (Day 1)
1. Disable SEOPress output
2. Enable LendCity SEO output
3. Clear all caches (page cache, CDN, etc.)
4. Submit new sitemap to GSC
5. Begin intensive monitoring period

#### Phase 4: Stabilization (2-4 weeks)
1. Daily health checks
2. Monitor GSC for coverage issues
3. Compare rankings to baseline
4. Keep SEOPress installed (deactivated) as rollback

#### Phase 5: Cleanup (After stabilization)
1. Remove SEOPress plugin
2. Optionally clean up old meta keys
3. Full SEO audit to confirm health

---

## Admin Settings Page

### New "SEO Settings" Tab or Submenu

**Sections:**

1. **General Settings**
   - Site title separator (|, -, –, •)
   - Default meta description (fallback)
   - Default OG image

2. **Social Profiles**
   - Facebook URL
   - Twitter/X handle
   - LinkedIn URL
   - Instagram URL (for schema sameAs)

3. **Organization Schema**
   - Organization name
   - Organization type (Organization, Corporation, FinancialService)
   - Logo URL
   - Contact info (optional)

4. **Sitemap Settings**
   - Enable/disable sitemap
   - Post types to include
   - Taxonomies to include
   - Posts per sitemap file

5. **Robots.txt**
   - Custom rules textarea
   - Block paths input
   - Preview generated robots.txt

6. **Redirects** (if implemented)
   - Redirect table with add/edit/delete
   - 404 log viewer

7. **Health Monitor**
   - Scan schedule settings
   - Email alert settings
   - Alert threshold configuration

---

## Performance Considerations

### Caching Strategy
- **Meta tags:** No caching needed (minimal computation)
- **Schema:** Cache in post meta on save
- **Sitemap:** Transient cache (1 hour), invalidate on post save
- **Robots.txt:** Object cache if available
- **Health checks:** Results cached, scheduled scans

### Database Efficiency
- Use existing `wp_postmeta` table (no new tables for core SEO)
- Optional: Redirects table if managing 100+ redirects
- Optional: 404 log table for high-traffic sites
- Batch operations for migration

### Frontend Performance
- Single `wp_head` hook for all meta output
- Minified inline JSON-LD
- No external requests
- No additional CSS/JS on frontend

---

## Estimated Code Size

| Component | Lines of Code | Priority |
|-----------|---------------|----------|
| Meta Tags Output | ~200 | Tier 1 |
| Open Graph Tags | ~150 | Tier 1 |
| XML Sitemap | ~300 | Tier 1 |
| JSON-LD Schema | ~400 | Tier 1 |
| Archive/Taxonomy SEO | ~200 | Tier 1 |
| Robots.txt Handler | ~100 | Tier 2 |
| SEO Metabox | ~400 | Tier 2 |
| Breadcrumbs | ~150 | Tier 2 |
| Redirect Manager | ~300 | Tier 2 |
| Bulk SEO Editor | ~300 | Tier 2 |
| Import/Export | ~200 | Tier 2 |
| **Health Check System** | **~500** | **Tier 1** |
| Migration Tool | ~300 | Tier 1 |
| **Total Tier 1** | **~2,050** | Essential |
| **Total All** | **~3,500** | Complete |

Compare to SEOPress: 50,000+ lines (free) / 100,000+ lines (pro)

---

## Summary: Recommended Implementation Order

### Phase 1: Core SEO + Monitoring (Replace SEOPress Output)
1. Meta Tags Output Engine
2. Open Graph & Twitter Cards
3. XML Sitemap Generator
4. JSON-LD Schema (Article, Organization, Breadcrumbs, WebSite)
5. Archive & Taxonomy SEO
6. **SEO Health Check System**
7. **Migration Tool from SEOPress**

### Phase 2: Admin Interface
8. SEO Metabox in Editor
9. SEO Settings Admin Page
10. Bulk SEO Editor

### Phase 3: Enhancements
11. Robots.txt Handler
12. Breadcrumbs System
13. 301 Redirect Manager
14. Import/Export Tool

### Phase 4: Advanced (Future)
15. Internal Link Suggestions
16. SEO Score Dashboard
17. FAQ Schema auto-detection
18. REST API Endpoints

---

## Questions Resolved

| Question | Recommendation |
|----------|----------------|
| Organization Type | Use "FinancialService" for LendCity (subtype of LocalBusiness) |
| Breadcrumbs | Implement but make optional via shortcode/function |
| Redirect Manager | Include basic version, skip regex for v1 |
| Custom Post Types | Design to support any public post type |

## Open Questions

1. **Migration Timeline:** When would you want to start the parallel operation phase?
2. **Email Alerts:** Who should receive SEO health alerts? (admin email, custom list?)
3. **Baseline Rankings:** Do you have access to current GSC position data to input as baseline?
