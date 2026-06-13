# Remove Taxonomy URL — working notes

Read this first when working in this folder. It exists so a new session can orient without re-reading source.

## 1. What this plugin is

- **WP.org name:** Remove Taxonomy URL (published, 1000+ installs)
- **Folder / slug / text-domain:** `remove-taxonomy-url`
- **PHP prefixes:** functions and options use `rtu_`; the 3.x modules are `RTU_` classes (e.g. `RTU_Options`); the original boilerplate classes are `Remove_Taxonomy_Url_*`. The mixed prefixes are intentional and kept per WP.org guidance — do not rewrite them.
- **GitHub:** https://github.com/sungraizfaryad/remove-taxonomy-url
- **Current version:** 3.1.0 (Requires WP 5.0, Requires PHP 7.4, Tested up to 7.0)

It strips the base slug from custom-taxonomy term URLs so `/genre/rock/` becomes `/rock/`. Built on Tom McFarlin's WordPress Plugin Boilerplate (Loader pattern). On top of the strip it adds optional 301 redirects from the old URLs, hierarchical term URLs (a child term resolves at its full `/parent/child/` path), pagination support so `/rock/page/2/` keeps working, slug-collision detection that warns on save, and a Health Check tab that audits taxonomies and self-tests term URLs over a loopback request.

## 2. Repo layout

- **This folder is the canonical git repo.** It lives inside the `media-usage-inspector` Local test install at `app/public/wp-content/plugins/remove-taxonomy-url/` and has its own `.git/`, `vendor/`, `tests/`, `docs/`.
- There is a separate clean snapshot at `~/Local Sites/plugins/remove-taxonomy-url/` with **no `.git`**. It is shipped-files-only and exists purely as the deploy source reference. Do not develop there. When you ship, you copy the clean files into it, then run the deploy script next to it.
- **Deploy script:** `~/Local Sites/plugins/deploy.sh` (GaryJones SVN deploy wrapper) pushes trunk + tag + assets to the WP.org SVN. `.svnignore` in this folder strips dev files (tests, bin, docs, vendor, composer, phpunit/phpcs config, `.git*`) from what reaches SVN. There is no build zip; WP.org serves from SVN.
- **FLP integration install:** a copy runs at `/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/remove-taxonomy-url`. That site (Fine Luxury Property) has a big hierarchical `property-city` taxonomy with 444 terms and is the real-data test bed. Mirror this folder there to verify URL behavior before shipping.

## 3. Don't trip these mines

These are real, paid-for-in-bugs gotchas specific to this plugin:

- **`rtu_basics` option format is load-bearing.** Feature flags must be stored as `'on'`/`''` strings and `rtu_post_types` as a keyed `slug=>slug` map. A sequential array or int `0/1` makes the WeDevs settings-API render callbacks fail to round-trip, so every checkbox silently resets on Save. This was the 3.0.0 → 3.0.1 bug. The migration in `RTU_Options::maybe_migrate()` normalizes old formats; don't bypass it.
- **URL-rewriter filters bind unconditionally.** They must NOT be gated on whether a setting is enabled. Gating made them early-return before `init` had registered the taxonomies, which 404'd every stripped URL (3.0.0 bug). The settings are checked inside the callbacks, not at hook-registration time.
- **Pagination rules route via `name=`, never hardcoded per-taxonomy rewrite keys.** Constant keys collide in the per-taxonomy loop and only the last taxonomy survives. The fix routes through `index.php?name=$matches[1]` so the request filter resolves the right taxonomy among all active ones (Fix A, was a ship blocker).
- **Conflict detector reads `$new_value`, not the cached old value.** `warn_on_save()` runs on `pre_update_option_rtu_basics`; the collision gate must read the incoming `$new_value['rtu_enable_collision']`, otherwise it checks the previous state.
- **Admin-notices banner logic is inverted.** In `RTU_Admin_Notices`, the option default `1` means "do not show". Only the migration arms the banner by setting it to `0`. Don't read it as a normal boolean.
- **`pre_update_option_rtu_basics` sanitize filter is shared.** `class-rtu-options.php` (around the sanitize) warns: do not relax this filter without revisiting the settings UI and the conflict detector. They depend on its output shape.
- **Hierarchy has a cap.** `RTU_Hierarchy_Rules::MAX_CHILD_TERMS = 2000`. A taxonomy over the cap is skipped and surfaced as a warning rather than silently truncated. Turning hierarchy ON rewrites child URLs to `/parent/child/` and 301s the old flat `/child/`.
- **Permalinks flush.** Settings changes auto-flush; in-place updates (no activation hook) arm a `rtu_needs_flush` transient via `upgrader_process_complete` and flush on the next `admin_init`. If a URL still 404s after a change, save Settings → Permalinks once.

## 4. How to develop here (Local by Flywheel)

WP-CLI against this Local site over its MySQL socket:

```
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID() { grep -rl "$1" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1; }
SOCK="$RUN/$(SITE_RID media-usage-inspector)/mysql/mysqld.sock"
$PHP -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
    $WP --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" plugin list
```

Swap `media-usage-inspector` for `flp` (and the `--path` site name) to drive the FLP test install.

**FLP admin auto-login** (no password, http only). FLP runs WPS Hide Login, so the admin lives at `/matt-sung/`, not `/wp-admin/`:
`http://flp.local/matt-sung/?localwp_auto_login=36`

## 5. Architecture map

`includes/`:
- `class-rtu-options.php` — `RTU_Options`, the option facade. `DB_VERSION`-driven migration, `rtu_basics` read/write, last-flushed stamp, child-term counting, the shared sanitize filter.
- `class-rtu-url-rewriter.php` — `RTU_Url_Rewriter`. Strips the base slug on `term_link`, prepends the ancestor path when hierarchy is on, and resolves an incoming request down to the leaf term slug.
- `class-rtu-hierarchy-rules.php` — `RTU_Hierarchy_Rules`. Generates explicit per-child nested rewrite rules, walks ancestor paths (`term_path()`), enforces `MAX_CHILD_TERMS`, arms a flush on term changes.
- `class-rtu-redirect-handler.php` — `RTU_Redirect_Handler`. 301s legacy `/taxonomy/term/` URLs to the stripped form, and 301s a flat child URL to its nested canonical when hierarchy is on.
- `class-rtu-pagination-fix.php` — `RTU_Pagination_Fix`. Rewrite rules (routed via `name=`) so paginated archives keep working after the slug is removed; deferred flush.
- `class-rtu-conflict-detector.php` — `RTU_Conflict_Detector`. Audits term slugs against pages, posts, and other taxonomies' terms; warns on save and feeds the Health Check audit.
- `class-rtu-url-selftest.php` — `RTU_Url_Selftest`. Samples term URLs and requests them over a loopback (internal fallback if blocked), reporting which resolve. Backs the Health Check "Test all term URLs" button.
- `class-rtu-admin-notices.php` — `RTU_Admin_Notices`. One-time 3.0 upgrade banner (default `1` = hidden; migration arms it).
- `class-remove-taxonomy-url.php` — core orchestrator. Requires the modules and wires their `register_hooks()`.
- `class-remove-taxonomy-url-loader.php` — boilerplate hook collector (`add_action`/`add_filter`/`run`).
- `class-remove-taxonomy-url-settings-api.php` — WeDevs Settings API copy that renders the settings sections/fields.
- `class-remove-taxonomy-url-{activator,deactivator,i18n}.php` — boilerplate lifecycle + text-domain loading.

`admin/`:
- `class-remove-taxonomy-url-admin.php` — admin assets, AJAX wiring, localized strings.
- `partials/remove-taxonomy-url-settings.php` — `Remove_Taxonomy_Url_Settings`, the settings page (fields, per-setting descriptions, version + last-flushed line).
- `partials/rtu-health-check.php` — Health Check tab UI (collision audit + self-test button + hierarchy status).

`public/` — boilerplate public class only; the real front-end behavior lives in the `RTU_` modules above.

## 6. Tests

PHPUnit suite under `tests/phpunit/`. It needs Local's bundled PHP 8.2 (the system PHP breaks PHPUnit 9) and `WP_TESTS_DIR` pointing at an installed WP test lib:

```
PHP82="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php"
WP_TESTS_DIR=/tmp/claude-501/wordpress-tests-lib "$PHP82" vendor/bin/phpunit
```

If `/tmp` was cleared, reinstall the test lib via `bin/install-wp-tests.sh` using the site's MySQL socket first. Lint with `vendor/bin/phpcs` against `.phpcs.xml.dist`.

## 7. Build & ship

No zip build. Ship goes through SVN:

1. Commit and tag on `master` (tags are no-prefix, e.g. `3.1.0`).
2. Copy the shipped files into `~/Local Sites/plugins/remove-taxonomy-url/` (clean snapshot, dev files excluded per `.svnignore`).
3. Run `~/Local Sites/plugins/deploy.sh` to push trunk, the version tag, and `/assets/` to WP.org SVN.

`.svnignore` is the source of truth for what stays out of SVN: `tests`, `bin`, `docs`, `vendor`, `composer.*`, `phpunit.xml.dist`, `.phpcs.xml.dist`, `.git*`, `README.md`.

## 8. When in doubt

- `progress.md` in this folder — current state at a glance (Done / Decisions / Next / Key files).
- Cloud memory at `~/.claude/projects/-Users-sungraizfaryad-Local-Sites-media-usage-inspector/memory/`, files matching `project_remove_taxonomy_url_*` and `reference_*` (deploy guide, repo layout).
- `wp-admin/` and `wp-includes/` are WordPress core. Never edit them.
