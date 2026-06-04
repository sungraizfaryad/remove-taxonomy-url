=== Remove Taxonomy URL ===
Contributors: sungraizfaryad
Donate link: https://sungraizfaryad.com/
Tags: taxonomy, custom taxonomy, slug, permalink, redirect
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 3.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Strip custom taxonomy slugs from URLs. Optional 301 redirects, hierarchical terms, pagination support, and slug-collision detection.

== Description ==

Remove Taxonomy URL strips the base slug (`/genre/`, `/topic/`, etc.) from your custom taxonomy term URLs so visitors and search engines see clean, short permalinks.

**What 3.0 adds:**

* **301 redirect old URLs** — old `/taxonomy/term/` URLs redirect permanently to the new `/term/` URL so SEO equity is preserved when you turn the plugin on.
* **Hierarchical term URLs** — nested taxonomies resolve correctly. `/parent/child/` reaches the child term instead of returning 404.
* **Pagination support** — `/term/page/2/` works after the base slug is removed, no more pagination 404s.
* **Slug-collision detection** — warns you (without blocking the save) when a term slug clashes with a page, post, or another taxonomy's term, so you don't accidentally break a URL.
* **Health Check tab** — run a full audit of all selected taxonomies on demand.

All 3.0 features default to OFF on upgrade so your existing site behavior is preserved until you opt in.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/remove-taxonomy-url/`, or install it through the WordPress plugins screen.
2. Activate the plugin via the **Plugins** screen.
3. Visit **Settings → Remove Taxonomy URL** and pick the taxonomies you want stripped from URLs.
4. Save **Settings → Permalinks** twice to flush rewrite rules.

== Frequently Asked Questions ==

= I am facing a 404 error page after saving plugin settings? =

Save your permalinks twice at **Dashboard → Settings → Permalinks**. WordPress regenerates rewrite rules on the second save.

= Will my old taxonomy URLs return a 404 after enabling this plugin? =

Enable the **301 redirect old URLs** option in **Settings → Remove Taxonomy URL**. Old `/taxonomy/term/` URLs will redirect to the new `/term/` so search engines and bookmarks keep working.

= Does it work with nested / hierarchical taxonomies? =

Yes — enable the **Hierarchical term URLs** option in 3.0. Nested terms (e.g. `rock/punk`) resolve correctly.

= What happens if a term slug collides with a page or post slug? =

Enable **Conflict detection on save** (default ON). You'll see a warning when a colliding slug is detected. The Health Check tab can audit every selected taxonomy at once.

= Does this plugin send any telemetry or tracking? =

No. No analytics, no remote calls, no tracking.

### Links

- [GitHub Repository](https://github.com/sungraizfaryad/remove-taxonomy-url)


== Changelog ==
= 3.1.0 =
* New: Hierarchical term URLs now actually resolve. Child terms use their full parent path (/parent/child/) at any depth, backed by explicit rewrite rules; the old flat /child/ URL 301-redirects to the nested one. Previously, turning this option on made child terms 404.
* New: Health Check "Test all term URLs" — requests a sample of your term URLs over a loopback and reports which resolve (200) or fail (404). Catches broken term URLs from any cause (hierarchy, stale permalinks, collisions). Falls back to an internal check if loopback is blocked.
* New: Health Check shows current hierarchy status (on/off, child-URL shape, child-term count).
* Improved: every setting now has a plain-language description explaining what it does and when to use it.
* Improved: settings page shows the plugin version and when permalinks were last flushed; saving settings re-flushes automatically.
* Improved: enabling Hierarchical term URLs shows how many child-term URLs will change.

= 3.0.1 =
* Fix: settings checkboxes no longer reset to unchecked after Save. Sanitize now stores values in the format expected by the rendering callbacks (slug=>slug array for the taxonomy multicheck; 'on'/'' string for feature flags).
* Fix: hidden companion checkbox field's literal 'off' string is no longer treated as "enabled" by the sanitize callback. Feature flags now only enable when explicitly 'on'.
* Migration: existing 3.0.0 installs are normalized from int 0/1 + sequential post_types into the new format automatically on first 3.0.1 load — no user action required.
* Tested up to: 7.0.

= 3.0.0 =
* New: 301 redirect from old /taxonomy/term/ to new /term/ (optional, off by default).
* New: pagination support for taxonomies with their base slug removed.
* New: hierarchical term URLs — multi-level parent paths resolve correctly.
* New: slug-collision detector with on-demand Health Check audit.
* Improved: term-link rewriting hardened against parent-path over-matching.
* Improved: orphan/circular term parent chains no longer cause infinite loops.
* Fix: settings now have a sanitize callback (Plugin Standards section 6).
* Fix: settings page registration moved from admin_menu to admin_init.
* Fix: uninstall now removes all plugin options and transients (Plugin Standards section 13).
* Compatibility: tested with PHP 7.4 through 8.3.

= 1.0.6 =
* Test up to WordPress 6.7.2

= 1.0.5 =
* Test upto WordPress 6.4.

= 1.0.4 =
* Test upto WordPress 5.9.1.

= 1.0.2 =
* Test upto WordPress 5.6

= 1.0.1 =
* Test upto WordPress 5.4.1.

= 1.0.0 =
* Initial Version.
