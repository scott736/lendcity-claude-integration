# LendCity Tools Plugin

**Version:** 12.2.1
**WordPress Plugin for AI-Powered Content Management**

## Overview

This plugin integrates Claude AI into WordPress for LendCity Mortgages, providing:

1. **Smart Linker** - AI-powered internal linking system
2. **Podcast Publisher** - Auto-creates blog posts from Transistor.FM podcast episodes
3. **Article Scheduler** - Processes DOCX files into scheduled WordPress posts
4. **SEO Metadata** - Generates titles, descriptions & tags

## Features

### Smart Linker
- Builds a semantic catalog of all posts/pages with AI-generated summaries
- Automatically adds internal links to new posts on publish
- Bulk processing for existing content (3 posts per batch)
- Tracks all links with ability to remove/update
- Priority pages and keyword targeting
- Auto-cleanup when posts are deleted (removes from catalog + removes links pointing to deleted post)
- **v5.0 Semantic Intelligence:**
  - TF-IDF weighted keyword matching
  - Synonym-aware matching (mortgage = home loan)
  - Entity-to-cluster relevance scoring
  - Anchor diversity tracking
  - Reciprocal link detection
  - Persona conflict penalties
  - Deep page priority scoring

### Podcast Publisher
- Monitors RSS feeds for new episodes (Mondays only)
- Fetches transcripts from Transistor.FM
- Generates full blog posts with Claude AI
- Includes Transistor embed player (iframe whitelisted via wp_kses filter)
- Smart cron: only runs on Mondays, stops when both podcasts are processed
- Supports 2 podcasts: "Wisdom Lifestyle Money Show" and "Close More Deals"

### Article Scheduler
- Watches `/wp-content/uploads/lendcity-articles/` for DOCX files
- Converts to WordPress posts with Gutenberg blocks
- Adds SEOPress FAQ blocks
- Fetches featured images from Unsplash
- Compresses images with TinyPNG
- Maintains minimum 20 scheduled posts

## File Structure

```
lendcity-claude-integration/
├── lendcity-claude-integration.php   # Main plugin file (3300+ lines)
├── includes/
│   ├── class-smart-linker.php        # Smart Linker class (2000+ lines)
│   ├── class-claude-api.php          # Claude API wrapper
│   └── docx-reader.php               # DOCX file parser
├── admin/views/
│   ├── dashboard-page.php            # Main dashboard
│   ├── smart-linker-page.php         # Smart Linker UI
│   ├── podcast-publisher-page.php    # Podcast settings & controls
│   ├── article-scheduler-page.php    # Article queue management
│   └── settings-page.php             # API keys & settings
└── README.md
```

## Settings

### Required API Keys (Settings page)
- **Claude API Key** - From Anthropic Console
- **Unsplash Access Key** - For featured images
- **TinyPNG API Key** - For image compression

### Debug Mode
Enable in Settings → Advanced Settings to see detailed logs in WP debug log.

## Cron Jobs

| Hook | Schedule | Purpose |
|------|----------|---------|
| `lendcity_check_podcasts` | Every 15 min (Mondays only) | Check podcast RSS feeds |
| `lendcity_auto_schedule_articles` | Hourly | Maintain 20 scheduled posts |
| `lendcity_process_link_queue` | Every minute (when active) | Bulk link processing |
| `lendcity_auto_link_new_post` | One-time (60s delay) | Link new posts on publish |

## Key Technical Details

### Iframe Preservation
WordPress strips iframes by default. Plugin adds `wp_kses_allowed_html` filter to whitelist Transistor.FM embeds.

### Duplicate Processing Prevention
- Transient locks prevent concurrent queue processing
- Queue items removed immediately after being grabbed
- 2-minute lock timeout as backup

### Catalog
- Stored in `wp_options` as `lendcity_post_catalog`
- Each entry: title, URL, 4-5 sentence summary, semantic keywords, topics, entities
- In-memory caching to reduce DB queries
- Auto-removes entries when posts are deleted

## Development Notes

### To Continue Development
```
Clone this repo and work on the files directly. 
Main logic is in:
- lendcity-claude-integration.php (podcast, article scheduler, AJAX handlers)
- includes/class-smart-linker.php (all linking logic)
```

### Version History Highlights
- v9.9.103: Fixed iframe stripping (wp_kses filter)
- v10.0.0: Added debug mode toggle, removed sleep delays, optimized logging
- v10.0.1: Dynamic cron for Smart Linker (only when queue has items)
- v10.0.2: Smart podcast cron (Mondays only, stops when done)
- v10.0.3: Auto-cleanup on post delete
- v10.0.4: Added Clear Catalog button
- v10.0.5: Fixed queue double-processing with locks
- v10.0.6: Speed optimizations (batch size 3, 1s delay, catalog caching)
- v10.0.7: Increased catalog summary to 4-5 sentences for better link relevance
- v11.0.0: Custom database table for scalability (10,000+ posts)
- v11.1.0: Enriched catalog v4.0 (topic clusters, personas, funnel stages, geographic targeting)
- v11.1.1: Fixed dbDelta compatibility issues (direct SQL)
- v11.2.0: Smart Metadata v2 - Runs AFTER linking for optimal SEO using catalog + inbound anchors
- v12.0.0: **Semantic Intelligence v5.0** - Major linking improvements:
  - TF-IDF weighted keyword matching (rare keywords worth more)
  - Synonym-aware keyword matching (mortgage = home loan)
  - Entity-to-cluster relevance scoring
  - Anchor diversity tracking (penalize overused anchors)
  - Reciprocal link detection (avoid A→B + B→A)
  - Persona conflict penalties (don't link investor→first-time-buyer)
  - Deep page priority (prioritize orphaned pages)
  - Optimal anchor length scoring (2-4 words preferred)
  - Enhanced linking prompt with summaries + anchor suggestions
  - Increased candidate pool (15 pages, 20 posts shown to Claude)
  - New `build_semantic_indexes()` method for catalog optimization
- v12.1.0: **Background Processing** - All batch operations now run without keeping browser open:
  - WP Cron-based background queues for Catalog, Ownership Map, Auto-Linker, and SEO Metadata
  - New "Background Queue Status" dashboard showing real-time progress
  - "BUILD ALL (Background)" button starts all 4 processes via WP Cron
  - Individual "Build (Background)" buttons for each step
  - Auto-polling status updates every 5 seconds when queues are active
  - "Stop All Queues" button to cancel all background processing
  - Processes continue even after closing browser window
- v12.2.0: **Full Catalog Intelligence** - Claude sees entire site architecture for strategic linking:
  - Compact catalog table shows ALL pages and posts (not just top 15/20)
  - Claude can make site-wide strategic decisions about link distribution
  - Prioritizes orphan pages (low inbound link count) automatically
  - Shows inbound link counts so Claude knows which pages need links
  - Respects page priority settings (P1-P5) in linking decisions
  - Better topic cluster integrity and funnel progression
  - Truly holistic SEO linking strategy
- v12.2.1: **No Limits Mode** - Claude now sees ALL pages AND posts with anchor suggestions:
  - Removed 25 page / 30 post limit entirely
  - Every single page and post includes anchor phrase suggestions
  - Maximum information for optimal link selection
  - Works with sites up to 1000+ posts (fits within 200k token context)

## Installation

1. Upload `lendcity-claude-integration` folder to `/wp-content/plugins/`
2. Activate in WordPress admin
3. Go to LendCity Tools → Settings and add API keys
4. Build the catalog in Smart Linker tab
5. Configure podcasts in Podcast Publisher tab
