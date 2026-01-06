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

**Sitemap Types:**
- `/sitemap.xml` - Main sitemap index
- `/sitemap-posts.xml` - Blog posts
- `/sitemap-pages.xml` - Static pages
- `/sitemap-categories.xml` - Category archives (optional)

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

2. **Article** (blog posts)
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

3. **BreadcrumbList** (navigation breadcrumbs)
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [...]
}
```

4. **FAQPage** (for posts with FAQ blocks)
```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [{ "@type": "Question", ... }]
}
```

5. **LocalBusiness** (optional, for lender pages)

---

### Tier 2: Important (Should Have)

#### 5. **Robots.txt Virtual Handler**
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

#### 6. **SEO Metabox in Post Editor**
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

#### 7. **Breadcrumbs System**
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

#### 8. **301 Redirect Manager**
Simple redirect management (lightweight).

**Storage:** Options table (JSON array) or custom table for scale

**Features:**
- Add/edit/delete redirects via admin
- Support 301, 302, 307 codes
- Regex pattern matching (optional)
- Import/export redirects
- 404 logging to identify broken links

---

### Tier 3: Nice to Have (Consider for Future)

#### 9. **Internal Link Suggestions in Editor**
Leverage existing catalog for real-time suggestions.

**Features:**
- Sidebar panel showing relevant internal links
- Drag-and-drop to insert links
- Based on semantic matching from catalog

---

#### 10. **SEO Score Dashboard**
Simplified content scoring (not the bloated SEOPress version).

**Metrics:**
- Title length (green/yellow/red)
- Description length
- Focus keyword in title/description/H1
- Internal links count
- External links count
- Image alt text coverage

---

#### 11. **Knowledge Graph / Site Links Schema**
For branded search appearance.

```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "url": "https://lendcity.ca",
  "potentialAction": {
    "@type": "SearchAction",
    "target": "https://lendcity.ca/?s={search_term_string}",
    "query-input": "required name=search_term_string"
  }
}
```

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

## Implementation Architecture

### New File Structure
```
includes/
├── class-seo-module.php          # Main SEO class (meta output, hooks)
├── class-seo-sitemap.php         # XML sitemap generator
├── class-seo-schema.php          # JSON-LD structured data
├── class-seo-metabox.php         # Editor metabox/sidebar
└── class-seo-redirects.php       # Redirect manager (optional)
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

        // Remove WordPress default
        remove_action('wp_head', 'rel_canonical');
        remove_action('wp_head', 'wp_robots', 1);

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
    }
}
```

### Migration Strategy

1. **Phase 1: Parallel Operation**
   - Install new SEO module alongside SEOPress
   - New module reads both old and new meta keys
   - Admin toggle to switch output source

2. **Phase 2: Data Migration**
   - Bulk migrate SEOPress meta to LendCity meta
   - Verify data integrity
   - Update Smart Metadata generator to write new keys

3. **Phase 3: Deactivate SEOPress**
   - Disable SEOPress output
   - LendCity SEO takes over completely
   - Keep SEOPress data as backup

4. **Phase 4: Cleanup**
   - Remove SEOPress plugin
   - Optionally clean up old meta keys

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

---

## Performance Considerations

### Caching Strategy
- **Meta tags:** No caching needed (minimal computation)
- **Schema:** Cache in post meta on save
- **Sitemap:** Transient cache (1 hour), invalidate on post save
- **Robots.txt:** Object cache if available

### Database Efficiency
- Use existing `wp_postmeta` table (no new tables for core SEO)
- Optional: Redirects table if managing 100+ redirects
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
| Robots.txt Handler | ~100 | Tier 2 |
| SEO Metabox | ~400 | Tier 2 |
| Breadcrumbs | ~150 | Tier 2 |
| Redirect Manager | ~300 | Tier 2 |
| **Total Tier 1** | **~1,050** | Essential |
| **Total Tier 1+2** | **~2,000** | Complete |

Compare to SEOPress: 50,000+ lines (free) / 100,000+ lines (pro)

---

## Summary: Recommended Implementation Order

### Phase 1: Core SEO (Replace SEOPress Output)
1. Meta Tags Output Engine
2. Open Graph & Twitter Cards
3. XML Sitemap Generator
4. JSON-LD Schema (Article, Organization, Breadcrumbs)

### Phase 2: Admin Interface
5. SEO Metabox in Editor
6. SEO Settings Admin Page
7. Migration tool from SEOPress

### Phase 3: Enhancements
8. Robots.txt Handler
9. Breadcrumbs System
10. 301 Redirect Manager

### Phase 4: Advanced (Future)
11. Internal Link Suggestions
12. SEO Score Dashboard
13. FAQ Schema auto-detection

---

## Questions for Discussion

1. **Redirect Manager:** Do you currently use SEOPress redirects? If not, we can skip this entirely.

2. **Breadcrumbs:** Are you using breadcrumbs on the frontend currently? Need to check theme compatibility.

3. **Organization Type:** For schema, should we use "Organization", "Corporation", or "FinancialService"?

4. **Social Profiles:** Which social platforms does LendCity actively use?

5. **Post Types:** Besides posts and pages, any custom post types that need SEO support?

6. **Migration Timeline:** When would you want to deactivate SEOPress?
