# Remove Taxonomy URL 3.0 — Design Spec

**Date:** 2026-05-22
**Author:** Sungraiz Faryad
**Status:** Draft → pending implementation plan
**Plugin slug:** `remove-taxonomy-url` (WP.org, published)
**Current version:** 1.0.6 (1,000+ installs, 4.2★, last release 2025-03-10)
**Target version:** 3.0.0

---

## 1. Goal

Restore install momentum and close long-standing user complaints on the published plugin by shipping a reliability + conflict-safety release.

Closes recurring issues from [the plugin support forum](https://wordpress.org/support/plugin/remove-taxonomy-url/):
- Pagination 404s (open since 2020)
- Hierarchical/nested term URLs broken
- Two URLs resolve (no 301 redirect from old `/taxonomy/term/`)
- Yoast breadcrumb conflict
- "Tested up to 6.7" warning suppressing installs

Non-goals for 3.0 (deferred):
- WooCommerce `product_cat` support
- WPML / Polylang per-language slugs
- Yoast / Rank Math / AIOSEO direct integration
- WP-CLI / REST endpoints
- Per-term URL overrides (Pro tier candidate)

## 2. Constraints

- **Backwards compat is non-negotiable.** Plugin has 1,000+ active installs. Slug `remove-taxonomy-url` and option key `rtu_basics` MUST stay. `rtu_` 3-char prefix kept on existing keys — WP.org Standards doc §2 prefers 4+ but the plugin is already published; renaming risks breaking installed sites' stored settings. New keys may use longer prefixes if added.
- **New features default OFF on upgrade.** Passive collision check default ON. No URL behavior change without user opt-in.
- **Single repository.** Plugin lives at `app/public/wp-content/plugins/remove-taxonomy-url/` inside this Local site. Plugin has its own `.git/`. No SVN canonical split — this directory is the canonical source.
- **WP.org Plugin Standards compliance** per [`WORDPRESS-PLUGIN-STANDARDS.md`](../../../../../../../../WORDPRESS-PLUGIN-STANDARDS.md) — checklist sections 5, 6, 7, 8, 9, 13, 14 in scope.

## 3. Scope (3.0 features)

| # | Feature | User-facing toggle | Default on upgrade |
|---|---------|--------------------|--------------------|
| F1 | 301 redirect old → new (`/{tax}/{term}/` → `/{term}/`) | `rtu_enable_redirect` | OFF |
| F2 | Pagination support (`/{term}/page/N/` works) | `rtu_enable_pagination` | OFF |
| F3 | Hierarchical/nested term URLs | `rtu_enable_hierarchy` | OFF |
| F4 | Slug-collision pre-flight detector + Health-check audit page | `rtu_enable_collision` | ON (passive) |
| F5 | Existing 1.x behavior (term_link + request filters) | `rtu_post_types[]` | preserved |

Plus bundled bug fixes + Standards remediation (see §8).

## 4. Architecture

Existing Tom McFarlin Boilerplate scaffold preserved. Loader (`Remove_Taxonomy_Url_Loader`) keeps orchestrating hook registration.

```
remove-taxonomy-url/
├── remove-taxonomy-url.php                        (bootstrap — unchanged shape)
├── uninstall.php                                  (filled out — §8)
├── README.md / README.txt                         (Stable tag = 3.0.0)
├── includes/
│   ├── class-remove-taxonomy-url.php              (orchestrator — wire new modules)
│   ├── class-remove-taxonomy-url-loader.php       (unchanged)
│   ├── class-remove-taxonomy-url-i18n.php         (unchanged)
│   ├── class-remove-taxonomy-url-settings-api.php (unchanged)
│   ├── class-remove-taxonomy-url-activator.php    (runs RTU_Options::maybe_migrate)
│   ├── class-remove-taxonomy-url-deactivator.php  (clears scheduled flushes)
│   ├── class-rtu-options.php                      (NEW — option facade + migration)
│   ├── class-rtu-url-rewriter.php                 (NEW — moves existing 2 filters + bug fixes)
│   ├── class-rtu-redirect-handler.php             (NEW — F1)
│   ├── class-rtu-conflict-detector.php            (NEW — F4)
│   └── class-rtu-pagination-fix.php               (NEW — F2)
└── admin/
    ├── class-remove-taxonomy-url-admin.php        (slimmed — settings page + asset enqueue only)
    └── partials/
        ├── remove-taxonomy-url-settings.php       (tabs added)
        └── rtu-health-check.php                   (NEW — F4 audit UI)
```

**Wiring rules:**
- Each module exposes `public function register_hooks( Remove_Taxonomy_Url_Loader $loader ): void`.
- Modules read options exclusively via `RTU_Options::get()` / `RTU_Options::is_feature_enabled()` — never direct `get_option`.
- Modules never write options. Settings page is the only writer.
- Modules know nothing about the settings UI.

**Why modular vs single file:** each concern is independently testable; future Pro-tier modules (per-term overrides, find-replace) drop in as new files without touching shipped modules. Matches Standards doc §3 file ↔ class naming.

## 5. Data model

Single root option preserved: `wp_options.option_name = 'rtu_basics'`.

```php
[
    // Existing — preserved verbatim
    'rtu_post_types'         => [ 'genre', 'mood' ],

    // New in 3.0
    'rtu_enable_redirect'    => 0,
    'rtu_enable_pagination'  => 0,
    'rtu_enable_hierarchy'   => 0,
    'rtu_enable_collision'   => 1,
    'rtu_db_version'         => '3.0',
]
```

Sibling option: `rtu_db_version` (also stored standalone for fast lookup during migration).

### Migration (1.x → 3.0)

Triggered by, in priority order:
1. `register_activation_hook` → `Remove_Taxonomy_Url_Activator::activate()` → `RTU_Options::maybe_migrate()`
2. `upgrader_process_complete` action (in-place upgrades that skip activation)
3. `plugins_loaded` priority 5 fallback (`rtu_db_version` missing → migrate)

`maybe_migrate()`:
- If `rtu_db_version` exists and matches → no-op
- Else: merge new defaults into `rtu_basics` without clobbering `rtu_post_types`; set `rtu_db_version = '3.0'`; set transient `rtu_needs_flush = 1` (consumed on next `admin_init` → calls `flush_rewrite_rules()` once); set option `rtu_30_notice_dismissed = 0` (drives the upgrade banner — see §7)

### Sanitization (fixes Standards §6 gap)

`register_setting( 'rtu_basics', 'rtu_basics', [ 'sanitize_callback' => [ 'RTU_Options', 'sanitize' ] ] )`

```php
public static function sanitize( $input ): array {
    $clean = [];
    $valid_tax = array_keys( get_taxonomies( [ '_builtin' => false ] ) );
    $clean['rtu_post_types'] = array_values( array_intersect(
        (array) ( $input['rtu_post_types'] ?? [] ),
        $valid_tax
    ) );
    foreach ( [ 'rtu_enable_redirect', 'rtu_enable_pagination', 'rtu_enable_hierarchy', 'rtu_enable_collision' ] as $k ) {
        $clean[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
    }
    $clean['rtu_db_version'] = '3.0';
    return $clean;
}
```

## 6. Module designs

### 6.1 `RTU_Options` (shared facade)

```php
final class RTU_Options {
    const OPTION_KEY  = 'rtu_basics';
    const DB_VERSION  = '3.0';
    public static function get( string $key, $default = null );
    public static function get_active_taxonomies(): array;     // whitelisted by get_taxonomies()
    public static function is_feature_enabled( string $feature ): bool;
    public static function sanitize( $input ): array;
    public static function maybe_migrate(): void;
}
```

Static per-request cache to avoid repeated `get_option` calls inside hot paths (`term_link`, `request`, `template_redirect`).

### 6.2 `RTU_Url_Rewriter` (existing logic, hardened — F5 + bug fixes)

Hooks: `term_link` (priority 10), `request` (priority 1). Only active when `rtu_post_types` non-empty.

**`filter_term_link( string $url, WP_Term $term, string $taxonomy ): string`** — replaces existing `build_tax_slugs`.
- Bug fix: drop `stripos( $url, $slug ) === true` branch (never true; stripos returns int|false).
- Over-match guard: use `preg_replace( '#/' . preg_quote( $slug, '#' ) . '(?=/|$)#', '', $url )` so that a taxonomy slug matching part of a parent path (e.g. `/cat/cats/`) isn't double-stripped.

**`filter_request( array $query_vars ): array`** — replaces existing `remove_tax_slugs`.
- Existing parent-chain loop preserved.
- Bug fix: guard `if ( $parent_term->parent === $parent || ! $parent_term ) break;` against orphan terms / circular parent refs (current code can infinite loop).
- When `rtu_enable_hierarchy = 0`: behaves like 1.x (single-level only).
- When `rtu_enable_hierarchy = 1`: walks parent chain and rebuilds the full hierarchical path.

### 6.3 `RTU_Redirect_Handler` (F1 — 301 redirect)

Hook: `template_redirect` priority 99. Only active when `rtu_enable_redirect = 1`.

```php
public function maybe_redirect(): void {
    static $already_ran = false;
    if ( $already_ran ) return;
    if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
    if ( is_customize_preview() ) return;
    $already_ran = true;
    $request_uri = isset( $_SERVER['REQUEST_URI'] )
        ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
        : '';
    foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
        if ( preg_match( '#/' . preg_quote( $slug, '#' ) . '/([^/]+)(/.*)?$#', $request_uri, $m ) ) {
            $term = get_term_by( 'slug', $m[1], $slug );
            if ( $term && ! is_wp_error( $term ) ) {
                $new = str_replace( '/' . $slug . '/', '/', $request_uri );
                if ( apply_filters( 'rtu_should_redirect', true, $new, $term ) ) {
                    wp_safe_redirect( $new, 301 );
                    exit;
                }
            }
        }
    }
}
```

Static `$already_ran` guard at the top prevents loops if WP fires `template_redirect` twice in the same request.

Filter exposed: `rtu_should_redirect( bool $should, string $new_url, WP_Term $term )`.

### 6.4 `RTU_Conflict_Detector` (F4)

Two modes share `find_collisions(): array` helper.

**Passive mode** — `pre_update_option_rtu_basics` filter (priority 10).
- For each candidate taxonomy in incoming options, enumerate terms via `get_terms( [ 'taxonomy' => $slug, 'hide_empty' => false, 'fields' => 'id=>slug' ] )`.
- For each term slug, check collisions against:
  - `wp_posts` where `post_status = 'publish' AND post_type IN (public types)` and `post_name = slug`
  - Other selected taxonomies' term slugs
- If collisions → `add_settings_error( 'rtu_basics', 'rtu_collision', $message, 'warning' )`. **Save still proceeds** — user warned, not blocked.

**Health-check audit mode** — admin partial `rtu-health-check.php`.
- "Run audit" button triggers AJAX `wp_ajax_rtu_run_audit`.
- Guards: `check_ajax_referer( 'rtu_audit_nonce' )` + `current_user_can( 'manage_options' )`.
- Returns JSON list of collisions + suggested actions ("rename term", "exclude this taxonomy from removal").
- Output escaped via `esc_html`/`esc_attr`/`wp_kses_post` per Standards §7.

DB query for collision check uses `$wpdb->prepare()` per Standards §9.

### 6.5 `RTU_Pagination_Fix` (F2)

Hook: `rewrite_rules_array` filter. Only when `rtu_enable_pagination = 1`.

```php
public function inject_rules( array $rules ): array {
    foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
        $tax_rules = [
            '^([^/]+)/page/?([0-9]{1,})/?$' => 'index.php?' . $slug . '=$matches[1]&paged=$matches[2]',
            '^([^/]+)/?$'                    => 'index.php?' . $slug . '=$matches[1]',
        ];
        // Append at end so WP's built-in page/post rules match first.
        $rules = array_merge( $rules, $tax_rules );
    }
    return $rules;
}
```

Flush trigger: when `rtu_enable_pagination` toggles → set transient `rtu_needs_flush` → consumed on next `admin_init` → `flush_rewrite_rules( false )`.

## 7. Settings UI

Single page, three tabs via existing settings-api `show_navigation()`:

```
[ Basics ]  [ Redirects & Compat ]  [ Health Check ]
```

**Basics tab** — existing `rtu_post_types` multicheck of `get_taxonomies( [ '_builtin' => false ] )`.

**Redirects & Compat tab** — four checkbox fields backed by new option keys (F1–F4). Inline help text per field.

**Health Check tab** — "Run audit" button → AJAX → result table:
```
| Term        | Conflicts with         | Suggested action       |
|-------------|------------------------|------------------------|
| rock        | Page: /rock/           | Rename term or exclude |
```

**Upgrade banner** — `admin_notices` hook. Single source of state: option `rtu_30_notice_dismissed` (boolean). Migration sets it to `0`; the "Dismiss" button sets it to `1`. Banner shows while value is `0` and the current screen is the plugin settings page or WP Dashboard.

```
[!] Remove Taxonomy URL 3.0 — new features available:
    301 redirects, pagination fix, conflict detector.
    All disabled by default to preserve your current behavior.
    [Review settings] [Dismiss]
```

Dismiss handler: `admin-post.php?action=rtu_dismiss_30_notice` with nonce + `manage_options` capability check; updates the option and redirects back.

## 8. Bug fixes + Standards remediation (bundled in 3.0)

| # | Fix | Location | Standards ref |
|---|-----|----------|--------------|
| B1 | Drop dead `stripos === true` branch | `RTU_Url_Rewriter::filter_term_link` | — (logic bug) |
| B2 | Fill empty `uninstall.php` — delete `rtu_basics`, `rtu_db_version`, transients `_transient_rtu_*`, post meta `_rtu_*`, clear rewrite cache | `uninstall.php` | §13 |
| B3 | Add `sanitize_callback` to `register_setting()` | settings-api wiring | §6 |
| B4 | Move `rtu_settings_init` registration from `admin_menu` to `admin_init` | `class-remove-taxonomy-url.php` | — (timing) |
| B5 | Add `https://` scheme to `Plugin URI` and `Author URI` | `remove-taxonomy-url.php` header | §1 (review feedback) |
| B6 | Guard parent-chain loop against orphan / circular terms | `RTU_Url_Rewriter::filter_request` | — (DoS bug) |
| B7 | Confirm minimum `Requires PHP: 7.4` (was 7.0) | header + `README.txt` | §4 readme metadata |
| B8 | Bump `Tested up to` to current WP version at release time | `README.txt` | trust signal |
| B9 | Verify all `$_GET` / `$_POST` / `$_REQUEST` reads use `wp_unslash() + sanitize_*` | new modules | §6 |
| B10 | Run Plugin Check + WPCS — zero errors | full plugin | §14 |

## 9. Testing strategy

### Unit (PHPUnit + wp-phpunit)

`tests/phpunit/` (NEW directory). Bootstrap from wp-phpunit. Coverage targets:

- `RTU_Options::sanitize()` — valid input, missing keys, malicious input (non-array `rtu_post_types`, non-whitelisted taxonomy slug, non-bool checkboxes)
- `RTU_Url_Rewriter::filter_term_link()` — flat term, nested term, slug appearing in parent path (over-match guard), taxonomy not in selection
- `RTU_Url_Rewriter::filter_request()` — single level, multi-level hierarchy (with feature on/off), orphan term (no infinite loop), term slug not found
- `RTU_Conflict_Detector::find_collisions()` — page collision, post collision, cross-taxonomy term collision, no collisions
- `RTU_Pagination_Fix::inject_rules()` — rules array shape, key collision with existing rules
- `RTU_Options::maybe_migrate()` — fresh install (no-op), 1.x install (defaults merged), already-3.0 install (no-op)

### Integration (manual on this Local site)

1. Create test taxonomy `genre` (non-builtin), terms: `rock`, `rock/punk` (child of `rock`), `jazz`. Attach to a CPT or `post`.
2. Create page slug `rock` (collision target).
3. Activate plugin, select `genre`, save. Verify `/genre/rock/` → `/rock/` in term links.
4. Enable each new feature one at a time, verify:
   - F1: visit `/genre/rock/` → 301 to `/rock/`
   - F2: visit `/rock/page/2/` → resolves (not 404)
   - F3: visit `/rock/punk/` → resolves to child term
   - F4: settings save shows warning about `rock` ↔ page collision
5. Upgrade simulation: install 1.0.6 via WP admin, set `rtu_post_types`, replace plugin files with 3.0 build, reload admin → verify banner, options preserved, no URL behavior change.
6. Browser test with Playwright (`mcp__plugin_playwright_playwright__browser_navigate`) — assert HTTP status + final URL for each scenario.

### Compat matrix

- PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3
- WP 5.0 (declared minimum) / 6.4 / current latest
- Smoke test with Yoast SEO active, Rank Math active, both inactive

### Tooling gates

- `wp plugin check remove-taxonomy-url` → 0 errors
- `phpcs --standard=WordPress includes/ admin/` → 0 errors
- `php -l` on every PHP file → no syntax errors
- `WP_DEBUG = true` site reload → no notices/warnings

## 10. Release plan

1. Build (no composer deps; vanilla WP plugin)
2. Plugin Check + PHPCS pass
3. Tag `v3.0.0` in plugin's local `.git/`
4. Fresh-install test on WP 6.7 Local site
5. Upgrade-path test: 1.0.6 → 3.0.0 in place
6. SVN deploy:
   ```
   /trunk/         ← replaced with 3.0.0
   /tags/3.0.0/    ← new tag from trunk
   /assets/        ← banners/icons/screenshots refresh (uploaded separately per Standards §12)
   ```
7. Monitor [support forum](https://wordpress.org/support/plugin/remove-taxonomy-url/) first week; cut 3.0.1 patch if any regression report.

## 11. Out of scope (future releases)

- 4.0 candidates: WPML/Polylang, WooCommerce, Yoast/Rank Math/AIOSEO integrations, WP-CLI, REST endpoint, Gutenberg per-term override field, import/export JSON
- Pro-tier candidates: per-term URL override, bulk find-replace

## 12. Open questions

None blocking implementation. Decisions made during brainstorming:
- ✅ Keep slug `remove-taxonomy-url` and `rtu_` 3-char prefix (backwards compat over Standards §2 ideal)
- ✅ Modular refactor inside Boilerplate (Approach B)
- ✅ All new features opt-in on upgrade (banner notice)
- ✅ Skip 2.x — jump 1.0.6 → 3.0.0 (major surface change)
- ✅ Defer WooCommerce / WPML / SEO integrations to 4.0
