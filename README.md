# Remove Taxonomy URL

Strip custom taxonomy slugs from URLs. Optional 301 redirects, hierarchical terms, pagination support, and slug-collision detection.

**Current version:** 3.0.0 · **Requires:** WordPress 5.0+ · PHP 7.4+

## What 3.0 adds

- **301 redirect old URLs** — old `/taxonomy/term/` URLs redirect permanently to the new `/term/` so SEO equity is preserved.
- **Hierarchical term URLs** — nested taxonomies resolve correctly (e.g. `/parent/child/`).
- **Pagination support** — `/term/page/2/` works after the base slug is removed.
- **Slug-collision detection** — warns when a term slug clashes with a page, post, or another taxonomy's term. Full audit available on the Health Check tab.

All 3.0 features default OFF on upgrade so existing behavior is preserved until you opt in.

## Installation

1. Upload to `/wp-content/plugins/remove-taxonomy-url/`, or install via the WordPress plugins screen.
2. Activate via the **Plugins** screen.
3. Visit **Settings → Remove Taxonomy URL** and pick the taxonomies you want stripped.
4. Save **Settings → Permalinks** twice to flush rewrite rules.

## Frequently asked questions

**404 errors after enabling?** Save **Settings → Permalinks** twice. WordPress regenerates rewrite rules on the second save.

**Old `/taxonomy/term/` URLs returning 404?** Enable the **301 redirect old URLs** option in the plugin settings.

**Does it work with nested taxonomies?** Yes — enable the **Hierarchical term URLs** option.

**Slug collision with a page or post?** Enable **Conflict detection on save** (default ON) or run the Health Check audit.

**Does it send telemetry?** No. No analytics, no remote calls, no tracking.

## Links

- [WP.org Plugin Page](https://wordpress.org/plugins/remove-taxonomy-url/)
- [GitHub Repository](https://github.com/sungraizfaryad/remove-taxonomy-url)
