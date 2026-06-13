_Last updated: 2026-06-13._
_Glance file only. Full detail lives in CLAUDE.md and in cloud memory (`project_remove_taxonomy_url_*`)._

## Done
- 3.1.0 shipped to WP.org (trunk + tag). Hierarchical term URLs now actually resolve via explicit per-child rewrite rules; old flat child URL 301s to the nested path.
- Health Check "Test all term URLs" self-test (loopback with internal fallback) + hierarchy status display.
- Clearer per-setting descriptions; settings page shows version + last-flushed; auto re-flush on settings change.
- 3.0.1 fix: settings checkboxes no longer reset on save; stripped taxonomy URLs no longer 404.
- 3.0.0: 301 redirects, pagination support, slug-collision detector, sanitize callback, populated uninstall.php, PHPCS-clean.
- Hardening: conflict detector `warn_on_save()` bails when `add_settings_error` is unavailable (option can be written outside admin via migration/WP-CLI), preventing a fatal on front-end/login requests.

## Decisions
- Mixed PHP prefixes (`rtu_`, `RTU_`, `Remove_Taxonomy_Url_`) are intentional per WP.org guidance. Do not unify.
- Canonical git repo stays inside this test install; `~/Local Sites/plugins/remove-taxonomy-url/` is a clean shipped-files snapshot only, used as the deploy source.
- 3.x features default OFF on upgrade to preserve existing site behavior until opted in.
- `rtu_basics` stores flags as `'on'`/`''` and post types as keyed `slug=>slug`; required by the render callbacks, enforced by migration.

## Next steps
- No TODO/FIXME markers in source. Awaiting next instruction.
- Hardening fix above is on `master` but not yet in a tagged WP.org release; fold into the next ship (3.1.1 or later).
- Optional follow-ups (user-owned): refresh WP.org screenshots to show the 3.1 UI; update live fineluxuryproperty.com to 3.1.0 and decide hierarchy on/off.

## Key files
- `remove-taxonomy-url.php` — bootstrap, version, migration/flush triggers
- `includes/class-remove-taxonomy-url.php` — orchestrator wiring
- `README.txt` — changelog / stable tag
- `includes/class-rtu-options.php` — option facade + migration
- `includes/class-rtu-url-rewriter.php` — slug strip + leaf resolution
- `admin/partials/remove-taxonomy-url-settings.php` — settings page
