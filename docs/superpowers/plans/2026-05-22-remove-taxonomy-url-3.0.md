# Remove Taxonomy URL 3.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Remove Taxonomy URL 3.0 — adds 301 redirects, pagination support, hierarchical term URLs, and a slug-collision detector to the existing published plugin without breaking 1,000+ live installs.

**Architecture:** Keep the Tom McFarlin Boilerplate scaffold + Loader. Move the existing two-filter rewrite logic into a focused `RTU_Url_Rewriter` module (with bug fixes). Add four new module classes — `RTU_Options`, `RTU_Redirect_Handler`, `RTU_Conflict_Detector`, `RTU_Pagination_Fix` — each registering its own hooks via the existing Loader. Add a tabbed Settings UI. All new features default OFF on upgrade.

**Tech Stack:** PHP 7.4+, WordPress 5.0+, PHPUnit 9 + wp-phpunit + yoast/phpunit-polyfills for unit tests, WP Coding Standards (PHPCS), WP Plugin Check tool.

**Reference spec:** [`docs/superpowers/specs/2026-05-22-remove-taxonomy-url-3.0-design.md`](../specs/2026-05-22-remove-taxonomy-url-3.0-design.md)

**Working directory:** `/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/remove-taxonomy-url/` (this is the canonical git repo for the plugin — `.git/` lives here).

---

## File map

**Create:**
- `includes/class-rtu-options.php` — options facade, migration, sanitize callback
- `includes/class-rtu-url-rewriter.php` — existing 2 filters, ported + hardened
- `includes/class-rtu-redirect-handler.php` — F1: 301 redirect
- `includes/class-rtu-pagination-fix.php` — F2: pagination rewrite rules
- `includes/class-rtu-conflict-detector.php` — F4: collision check (passive + audit)
- `includes/class-rtu-admin-notices.php` — upgrade banner
- `admin/partials/rtu-health-check.php` — F4: audit UI partial
- `tests/bootstrap.php` — PHPUnit bootstrap
- `tests/phpunit/test-rtu-options.php`
- `tests/phpunit/test-rtu-url-rewriter.php`
- `tests/phpunit/test-rtu-redirect-handler.php`
- `tests/phpunit/test-rtu-pagination-fix.php`
- `tests/phpunit/test-rtu-conflict-detector.php`
- `phpunit.xml.dist`
- `bin/install-wp-tests.sh` — wp-phpunit test environment installer
- `.phpcs.xml.dist` — PHPCS ruleset
- `composer.json` (dev-only, will be excluded from release zip)

**Modify:**
- `remove-taxonomy-url.php` — bump version, fix Author/Plugin URI scheme
- `README.txt` — bump Stable tag, Tested up to, Requires PHP, add changelog
- `README.md` — mirror README.txt updates
- `uninstall.php` — fill out cleanup (currently empty stub)
- `includes/class-remove-taxonomy-url.php` — wire new modules, move settings init to `admin_init`
- `includes/class-remove-taxonomy-url-activator.php` — call `RTU_Options::maybe_migrate()`
- `admin/class-remove-taxonomy-url-admin.php` — strip the two filter methods (moved to rewriter), keep only enqueue + settings glue
- `admin/partials/remove-taxonomy-url-settings.php` — add Redirects & Health Check tabs, new fields, sanitize_callback

**Delete:** none

---

## Phase 1 — Test scaffolding

### Task 1: Composer + PHPUnit + wp-phpunit setup

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `bin/install-wp-tests.sh`
- Create: `tests/bootstrap.php`
- Create: `.gitignore` additions for `vendor/` and `/tmp/wordpress-tests-lib/`

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "sungraizfaryad/remove-taxonomy-url",
    "description": "Dev dependencies for Remove Taxonomy URL plugin",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "yoast/phpunit-polyfills": "^2.0",
        "wp-phpunit/wp-phpunit": "^6.4",
        "wp-coding-standards/wpcs": "^3.0",
        "phpcompatibility/phpcompatibility-wp": "^2.1",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] **Step 2: Install composer deps**

Run: `cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/remove-taxonomy-url" && composer install`
Expected: `vendor/` populated; `phpunit` and `phpcs` binaries available at `vendor/bin/`.

- [ ] **Step 3: Create `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true">
    <testsuites>
        <testsuite name="plugin">
            <directory suffix=".php">tests/phpunit/</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Create `bin/install-wp-tests.sh`**

Use the canonical WP test-install script. Source:
```
https://raw.githubusercontent.com/wp-cli/scaffold-command/v2.4.0/templates/install-wp-tests.sh
```
Save it to `bin/install-wp-tests.sh` and `chmod +x bin/install-wp-tests.sh`.

- [ ] **Step 5: Create `tests/bootstrap.php`**

```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}
require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    require dirname( __DIR__ ) . '/remove-taxonomy-url.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 6: Provision the WP test database**

This Local site has MySQL on a Local-managed port. Find the port in the Local app's site dashboard ("Database" tab). Substitute it below:
```bash
bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:<PORT> latest
```
Expected: prints "Success: Created tables." Test environment lives at `/tmp/wordpress-tests-lib/`.

- [ ] **Step 7: Add `.gitignore` entries**

Append to `.gitignore`:
```
vendor/
/tmp/
composer.lock
```

- [ ] **Step 8: Verify PHPUnit can boot**

Create `tests/phpunit/test-smoke.php`:
```php
<?php
class Smoke_Test extends WP_UnitTestCase {
    public function test_plugin_loaded() {
        $this->assertTrue( defined( 'REMOVE_TAXONOMY_URL_VERSION' ) );
    }
}
```

Run: `vendor/bin/phpunit`
Expected: 1 test, 1 assertion, PASS.

- [ ] **Step 9: Commit**

```bash
git add composer.json phpunit.xml.dist bin/install-wp-tests.sh tests/bootstrap.php tests/phpunit/test-smoke.php .gitignore
git commit -m "test: scaffold PHPUnit + wp-phpunit test environment"
```

---

## Phase 2 — Options facade + migration

### Task 2: `RTU_Options` class — read API

**Files:**
- Create: `includes/class-rtu-options.php`
- Create: `tests/phpunit/test-rtu-options.php`

- [ ] **Step 1: Write failing test for `RTU_Options::get()`**

`tests/phpunit/test-rtu-options.php`:
```php
<?php
class RTU_Options_Test extends WP_UnitTestCase {

    public function tearDown(): void {
        delete_option( 'rtu_basics' );
        parent::tearDown();
    }

    public function test_get_returns_default_when_option_missing() {
        delete_option( 'rtu_basics' );
        $this->assertSame( 'fallback', RTU_Options::get( 'missing_key', 'fallback' ) );
    }

    public function test_get_returns_stored_value() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' ] ] );
        $this->assertSame( [ 'genre' ], RTU_Options::get( 'rtu_post_types' ) );
    }
}
```

- [ ] **Step 2: Run test, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: FAIL with "Class RTU_Options not found".

- [ ] **Step 3: Create `includes/class-rtu-options.php` — minimal**

```php
<?php
/**
 * Options facade for Remove Taxonomy URL.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

final class RTU_Options {

    const OPTION_KEY = 'rtu_basics';
    const DB_VERSION = '3.0';

    /**
     * Per-request cache of the option array.
     *
     * @var array|null
     */
    private static $cache = null;

    /**
     * Read a single key from the rtu_basics option.
     *
     * @param string $key     Key inside rtu_basics.
     * @param mixed  $default Value returned when the key is missing.
     * @return mixed
     */
    public static function get( $key, $default = null ) {
        $opts = self::all();
        return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
    }

    /**
     * Read the full option array.
     *
     * @return array
     */
    public static function all() {
        if ( null === self::$cache ) {
            $stored      = get_option( self::OPTION_KEY, [] );
            self::$cache = is_array( $stored ) ? $stored : [];
        }
        return self::$cache;
    }

    /**
     * Invalidate the per-request cache.
     *
     * @return void
     */
    public static function flush_cache() {
        self::$cache = null;
    }
}
```

- [ ] **Step 4: Load the class from `remove-taxonomy-url.php`**

In `remove-taxonomy-url.php`, immediately before the `require` for `class-remove-taxonomy-url.php`, add:
```php
require_once plugin_dir_path( __FILE__ ) . 'includes/class-rtu-options.php';
```

- [ ] **Step 5: Make the test pass**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 2 tests, 2 assertions, PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-options.php remove-taxonomy-url.php tests/phpunit/test-rtu-options.php
git commit -m "feat: add RTU_Options facade with cached read API"
```

---

### Task 3: `RTU_Options::get_active_taxonomies()` + `is_feature_enabled()`

**Files:**
- Modify: `includes/class-rtu-options.php`
- Modify: `tests/phpunit/test-rtu-options.php`

- [ ] **Step 1: Add failing tests**

Append to `test-rtu-options.php`:
```php
    public function test_get_active_taxonomies_filters_to_registered_taxonomies() {
        register_taxonomy( 'genre', 'post' );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre', 'nonexistent_tax' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [ 'genre' ], RTU_Options::get_active_taxonomies() );
        unregister_taxonomy( 'genre' );
    }

    public function test_is_feature_enabled_returns_bool() {
        update_option( 'rtu_basics', [ 'rtu_enable_redirect' => 1, 'rtu_enable_pagination' => 0 ] );
        RTU_Options::flush_cache();
        $this->assertTrue( RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_missing' ) );
    }
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 2 new failures about missing methods.

- [ ] **Step 3: Implement methods**

Append inside `RTU_Options`:
```php
    /**
     * Selected taxonomies that are still registered.
     *
     * @return string[]
     */
    public static function get_active_taxonomies() {
        $selected = (array) self::get( 'rtu_post_types', [] );
        if ( empty( $selected ) ) {
            return [];
        }
        $registered = array_keys( get_taxonomies( [ '_builtin' => false ] ) );
        return array_values( array_intersect( $selected, $registered ) );
    }

    /**
     * Boolean feature flag check.
     *
     * @param string $feature Feature key inside rtu_basics.
     * @return bool
     */
    public static function is_feature_enabled( $feature ) {
        return ! empty( self::get( $feature, 0 ) );
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 4 tests, 4 assertions, PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-options.php tests/phpunit/test-rtu-options.php
git commit -m "feat: RTU_Options gains get_active_taxonomies + is_feature_enabled"
```

---

### Task 4: `RTU_Options::sanitize()` for `register_setting()` callback

**Files:**
- Modify: `includes/class-rtu-options.php`
- Modify: `tests/phpunit/test-rtu-options.php`

- [ ] **Step 1: Failing tests**

Append:
```php
    public function test_sanitize_filters_unknown_taxonomies() {
        register_taxonomy( 'genre', 'post' );
        $input = [
            'rtu_post_types'        => [ 'genre', 'fake_tax', '<script>' ],
            'rtu_enable_redirect'   => '1',
            'rtu_enable_pagination' => '',
            'rtu_enable_hierarchy'  => 'on',
            'rtu_enable_collision'  => 0,
        ];
        $clean = RTU_Options::sanitize( $input );
        $this->assertSame( [ 'genre' ], $clean['rtu_post_types'] );
        $this->assertSame( 1, $clean['rtu_enable_redirect'] );
        $this->assertSame( 0, $clean['rtu_enable_pagination'] );
        $this->assertSame( 1, $clean['rtu_enable_hierarchy'] );
        $this->assertSame( 0, $clean['rtu_enable_collision'] );
        $this->assertSame( '3.0', $clean['rtu_db_version'] );
        unregister_taxonomy( 'genre' );
    }

    public function test_sanitize_handles_non_array_input() {
        $clean = RTU_Options::sanitize( 'not-an-array' );
        $this->assertSame( [], $clean['rtu_post_types'] );
        $this->assertSame( 0, $clean['rtu_enable_redirect'] );
    }
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 2 failures.

- [ ] **Step 3: Implement `sanitize()`**

Append to `RTU_Options`:
```php
    /**
     * Sanitize callback for register_setting().
     *
     * @param mixed $input Raw input from the settings form.
     * @return array
     */
    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];

        $registered = array_keys( get_taxonomies( [ '_builtin' => false ] ) );
        $selected   = isset( $input['rtu_post_types'] ) && is_array( $input['rtu_post_types'] )
            ? $input['rtu_post_types']
            : [];

        $clean                       = [];
        $clean['rtu_post_types']     = array_values( array_intersect( $selected, $registered ) );
        $clean['rtu_enable_redirect']   = ! empty( $input['rtu_enable_redirect'] ) ? 1 : 0;
        $clean['rtu_enable_pagination'] = ! empty( $input['rtu_enable_pagination'] ) ? 1 : 0;
        $clean['rtu_enable_hierarchy']  = ! empty( $input['rtu_enable_hierarchy'] ) ? 1 : 0;
        $clean['rtu_enable_collision']  = isset( $input['rtu_enable_collision'] )
            ? ( ! empty( $input['rtu_enable_collision'] ) ? 1 : 0 )
            : 1; // Collision detection defaults ON.
        $clean['rtu_db_version']        = self::DB_VERSION;

        self::flush_cache();
        return $clean;
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-options.php tests/phpunit/test-rtu-options.php
git commit -m "feat: RTU_Options::sanitize whitelists taxonomies and normalizes flags"
```

---

### Task 5: `RTU_Options::maybe_migrate()` — 1.x → 3.0

**Files:**
- Modify: `includes/class-rtu-options.php`
- Modify: `tests/phpunit/test-rtu-options.php`
- Modify: `includes/class-remove-taxonomy-url-activator.php`
- Modify: `remove-taxonomy-url.php`

- [ ] **Step 1: Failing tests**

Append:
```php
    public function test_maybe_migrate_no_op_on_fresh_install() {
        delete_option( 'rtu_basics' );
        delete_option( 'rtu_db_version' );
        RTU_Options::flush_cache();
        RTU_Options::maybe_migrate();
        $this->assertSame( '3.0', get_option( 'rtu_db_version' ) );
        $this->assertSame( 0, (int) get_option( 'rtu_30_notice_dismissed', -1 ) );
    }

    public function test_maybe_migrate_preserves_existing_post_types() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' ] ] );
        delete_option( 'rtu_db_version' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        $this->assertSame( [ 'genre' ], $stored['rtu_post_types'] );
        $this->assertSame( 0, $stored['rtu_enable_redirect'] );
        $this->assertSame( 0, $stored['rtu_enable_pagination'] );
        $this->assertSame( 0, $stored['rtu_enable_hierarchy'] );
        $this->assertSame( 1, $stored['rtu_enable_collision'] );
        $this->assertSame( '3.0', $stored['rtu_db_version'] );
        $this->assertSame( '3.0', get_option( 'rtu_db_version' ) );
    }

    public function test_maybe_migrate_idempotent_when_already_on_3_0() {
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'genre' ],
            'rtu_enable_redirect' => 1,
            'rtu_db_version'      => '3.0',
        ] );
        update_option( 'rtu_db_version', '3.0' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        $this->assertSame( 1, $stored['rtu_enable_redirect'] ); // Not reset.
    }
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 3 failures.

- [ ] **Step 3: Implement migration**

Append to `RTU_Options`:
```php
    /**
     * Migrate options from any older schema to the current DB version.
     *
     * @return void
     */
    public static function maybe_migrate() {
        $current = get_option( 'rtu_db_version', '' );
        if ( self::DB_VERSION === $current ) {
            return;
        }

        $stored = get_option( self::OPTION_KEY, [] );
        $stored = is_array( $stored ) ? $stored : [];

        $defaults = [
            'rtu_post_types'         => [],
            'rtu_enable_redirect'    => 0,
            'rtu_enable_pagination'  => 0,
            'rtu_enable_hierarchy'   => 0,
            'rtu_enable_collision'   => 1,
            'rtu_db_version'         => self::DB_VERSION,
        ];

        $merged = array_merge( $defaults, $stored );
        $merged['rtu_db_version'] = self::DB_VERSION;

        update_option( self::OPTION_KEY, $merged );
        update_option( 'rtu_db_version', self::DB_VERSION );

        // First-time migration: arm the upgrade banner and a one-shot rewrite flush.
        if ( false === get_option( 'rtu_30_notice_dismissed', false ) ) {
            update_option( 'rtu_30_notice_dismissed', 0 );
        }
        set_transient( 'rtu_needs_flush', 1, HOUR_IN_SECONDS );

        self::flush_cache();
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Options_Test`
Expected: 7 tests, 11 assertions, PASS.

- [ ] **Step 5: Hook migration on plugin load + activation**

In `includes/class-remove-taxonomy-url-activator.php`, replace the empty `activate()` body with:
```php
public static function activate() {
    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-options.php';
    RTU_Options::maybe_migrate();
}
```

In `remove-taxonomy-url.php` after the existing `run_remove_taxonomy_url();` call add a fallback that catches in-place upgrades without deactivation:
```php
add_action( 'plugins_loaded', static function () {
    if ( class_exists( 'RTU_Options' ) ) {
        RTU_Options::maybe_migrate();
    }
}, 5 );
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-options.php includes/class-remove-taxonomy-url-activator.php remove-taxonomy-url.php tests/phpunit/test-rtu-options.php
git commit -m "feat: RTU_Options::maybe_migrate handles 1.x to 3.0 schema upgrade"
```

---

## Phase 3 — Port existing logic into `RTU_Url_Rewriter`

### Task 6: Port `build_tax_slugs` → `RTU_Url_Rewriter::filter_term_link` (with bug fix)

**Files:**
- Create: `includes/class-rtu-url-rewriter.php`
- Create: `tests/phpunit/test-rtu-url-rewriter.php`
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Failing tests**

`tests/phpunit/test-rtu-url-rewriter.php`:
```php
<?php
class RTU_Url_Rewriter_Test extends WP_UnitTestCase {

    private $rewriter;

    public function setUp(): void {
        parent::setUp();
        register_taxonomy( 'genre', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'genre' ] ] );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' ] ] );
        RTU_Options::flush_cache();
        $this->rewriter = new RTU_Url_Rewriter();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        parent::tearDown();
    }

    public function test_filter_term_link_strips_taxonomy_slug() {
        $term_id = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $term    = get_term( $term_id, 'genre' );

        $url = home_url( '/genre/rock/' );
        $out = $this->rewriter->filter_term_link( $url, $term, 'genre' );

        $this->assertSame( home_url( '/rock/' ), $out );
    }

    public function test_filter_term_link_does_not_overmatch_parent_path() {
        register_taxonomy( 'cat', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'cat' ] ] );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'cat' ] ] );
        RTU_Options::flush_cache();

        $url = home_url( '/category/cat/' );
        $out = $this->rewriter->filter_term_link( $url, (object) [ 'slug' => 'cat' ], 'cat' );

        // The /cat segment that matches the taxonomy slug is removed once, leaving /category/.
        $this->assertSame( home_url( '/category/' ), $out );
        unregister_taxonomy( 'cat' );
    }

    public function test_filter_term_link_skips_inactive_taxonomies() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'something_else' ] ] );
        RTU_Options::flush_cache();

        $url = home_url( '/genre/rock/' );
        $out = $this->rewriter->filter_term_link( $url, (object) [ 'slug' => 'rock' ], 'genre' );

        $this->assertSame( $url, $out );
    }
}
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Url_Rewriter_Test`
Expected: failures — class missing.

- [ ] **Step 3: Implement minimal class**

`includes/class-rtu-url-rewriter.php`:
```php
<?php
/**
 * URL rewriter for Remove Taxonomy URL.
 *
 * Owns the term_link and request filters that strip taxonomy slugs from URLs.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class RTU_Url_Rewriter {

    /**
     * Register hooks via the plugin loader.
     *
     * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
     * @return void
     */
    public function register_hooks( $loader ) {
        if ( empty( RTU_Options::get_active_taxonomies() ) ) {
            return;
        }
        $loader->add_filter( 'term_link', $this, 'filter_term_link', 10, 3 );
        $loader->add_filter( 'request',   $this, 'filter_request', 1, 1 );
    }

    /**
     * Strip the taxonomy slug from term permalinks.
     *
     * @param string $url      Original term URL.
     * @param object $term     Term object.
     * @param string $taxonomy Taxonomy slug.
     * @return string
     */
    public function filter_term_link( $url, $term, $taxonomy ) {
        $active = RTU_Options::get_active_taxonomies();
        if ( ! in_array( $taxonomy, $active, true ) ) {
            return $url;
        }
        $pattern = '#/' . preg_quote( $taxonomy, '#' ) . '(?=/|$)#';
        return preg_replace( $pattern, '', $url, 1 );
    }

    /**
     * Stub — filled in by Task 7.
     *
     * @param array $query_vars Query vars.
     * @return array
     */
    public function filter_request( $query_vars ) {
        return $query_vars;
    }
}
```

- [ ] **Step 4: Require the file**

In `includes/class-remove-taxonomy-url.php` inside `load_dependencies()`, after the existing requires, add:
```php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-url-rewriter.php';
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Url_Rewriter_Test`
Expected: 3 PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-url-rewriter.php includes/class-remove-taxonomy-url.php tests/phpunit/test-rtu-url-rewriter.php
git commit -m "feat: port build_tax_slugs to RTU_Url_Rewriter with over-match fix"
```

---

### Task 7: Port `remove_tax_slugs` → `RTU_Url_Rewriter::filter_request` (orphan-term guard, hierarchical opt-in)

**Files:**
- Modify: `includes/class-rtu-url-rewriter.php`
- Modify: `tests/phpunit/test-rtu-url-rewriter.php`
- Modify: `includes/class-remove-taxonomy-url.php`
- Modify: `admin/class-remove-taxonomy-url-admin.php`

- [ ] **Step 1: Failing tests**

Append to `RTU_Url_Rewriter_Test`:
```php
    public function test_filter_request_remaps_single_level_term() {
        $term_id = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );

        $query_vars = [ 'name' => 'rock' ];
        $out        = $this->rewriter->filter_request( $query_vars );

        $this->assertArrayNotHasKey( 'name', $out );
        $this->assertSame( 'rock', $out['genre'] );
    }

    public function test_filter_request_walks_parent_chain_when_hierarchical_enabled() {
        register_taxonomy(
            'genre',
            'post',
            [ 'public' => true, 'hierarchical' => true, 'rewrite' => [ 'slug' => 'genre' ] ]
        );
        $parent = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $child  = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'punk', 'parent' => $parent ] );

        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 1,
        ] );
        RTU_Options::flush_cache();

        $out = $this->rewriter->filter_request( [ 'name' => 'punk' ] );
        $this->assertSame( 'rock/punk', $out['genre'] );
    }

    public function test_filter_request_skips_hierarchy_when_disabled() {
        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 0,
        ] );
        RTU_Options::flush_cache();

        $parent = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $child  = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'punk', 'parent' => $parent ] );

        $out = $this->rewriter->filter_request( [ 'name' => 'punk' ] );
        $this->assertSame( 'punk', $out['genre'] ); // No parent prepended.
    }

    public function test_filter_request_passes_through_unknown_slug() {
        $out = $this->rewriter->filter_request( [ 'name' => 'no-such-term' ] );
        $this->assertSame( [ 'name' => 'no-such-term' ], $out );
    }

    public function test_filter_request_terminates_on_root_term() {
        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 1,
        ] );
        RTU_Options::flush_cache();

        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $out = $this->rewriter->filter_request( [ 'name' => 'rock' ] );
        $this->assertSame( 'rock', $out['genre'] );
    }
```

- [ ] **Step 2: Run, verify failures**

Run: `vendor/bin/phpunit --filter RTU_Url_Rewriter_Test`
Expected: 5 new failures (stub returns input unchanged).

- [ ] **Step 3: Implement `filter_request`**

Replace the stub `filter_request` in `RTU_Url_Rewriter` with:
```php
    public function filter_request( $query_vars ) {
        $active = RTU_Options::get_active_taxonomies();
        if ( empty( $active ) ) {
            return $query_vars;
        }

        if ( isset( $query_vars['attachment'] ) ) {
            $include_children = true;
            $name             = $query_vars['attachment'];
        } elseif ( isset( $query_vars['name'] ) ) {
            $include_children = false;
            $name             = $query_vars['name'];
        } else {
            return $query_vars;
        }

        $hierarchy_enabled = RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' );

        foreach ( $active as $taxonomy ) {
            $term = get_term_by( 'slug', $name, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }

            if ( $include_children ) {
                unset( $query_vars['attachment'] );
            } else {
                unset( $query_vars['name'] );
            }

            $resolved = $name;
            if ( $hierarchy_enabled && ! empty( $term->parent ) ) {
                $resolved = $this->prepend_parents( $term, $taxonomy );
            }

            $query_vars[ $taxonomy ] = $resolved;
            return $query_vars;
        }

        return $query_vars;
    }

    /**
     * Walk the parent chain and prepend slugs. Guards against orphan/circular references.
     *
     * @param WP_Term $term     Starting term.
     * @param string  $taxonomy Taxonomy slug.
     * @return string Slash-joined slug path.
     */
    private function prepend_parents( $term, $taxonomy ) {
        $path    = $term->slug;
        $parent  = (int) $term->parent;
        $visited = [ (int) $term->term_id => true ];
        $safety  = 0;

        while ( $parent && $safety++ < 25 ) {
            if ( isset( $visited[ $parent ] ) ) {
                break; // Circular reference guard.
            }
            $visited[ $parent ] = true;

            $parent_term = get_term( $parent, $taxonomy );
            if ( ! $parent_term || is_wp_error( $parent_term ) ) {
                break; // Orphan parent guard.
            }
            $path   = $parent_term->slug . '/' . $path;
            $parent = (int) $parent_term->parent;
        }

        return $path;
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Url_Rewriter_Test`
Expected: 8 PASS.

- [ ] **Step 5: Remove the old methods from the admin class**

In `admin/class-remove-taxonomy-url-admin.php` delete the entire `remove_tax_slugs()` method and `build_tax_slugs()` method. The class now only handles enqueue + settings glue.

- [ ] **Step 6: Re-wire hooks in core class**

In `includes/class-remove-taxonomy-url.php`, replace the `define_admin_hooks()` body so the rewriter (not the admin class) owns the filters and the settings init moves to `admin_init`:

```php
    private function define_admin_hooks() {

        $plugin_admin = new Remove_Taxonomy_Url_Admin( $this->get_plugin_name(), $this->get_version() );

        $rewriter = new RTU_Url_Rewriter();
        $rewriter->register_hooks( $this->loader );

        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

        $plugin_settings = new Remove_Taxonomy_Url_Settings();

        $this->loader->add_action( 'admin_init', $plugin_settings, 'rtu_settings_init' );
        $this->loader->add_action( 'admin_menu', $plugin_settings, 'settings_menu' );
    }
```

(Note: `rtu_settings_init` moved from `admin_menu` to `admin_init` — bug fix B4.)

- [ ] **Step 7: Run full PHPUnit suite to verify no regressions**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add includes/class-rtu-url-rewriter.php includes/class-remove-taxonomy-url.php admin/class-remove-taxonomy-url-admin.php tests/phpunit/test-rtu-url-rewriter.php
git commit -m "feat: port remove_tax_slugs to RTU_Url_Rewriter with orphan-term guard"
```

---

## Phase 4 — New modules

### Task 8: `RTU_Redirect_Handler` — F1 (301 redirect)

**Files:**
- Create: `includes/class-rtu-redirect-handler.php`
- Create: `tests/phpunit/test-rtu-redirect-handler.php`
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Failing tests**

`tests/phpunit/test-rtu-redirect-handler.php`:
```php
<?php
class RTU_Redirect_Handler_Test extends WP_UnitTestCase {

    private $handler;

    public function setUp(): void {
        parent::setUp();
        register_taxonomy( 'genre', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'genre' ] ] );
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'genre' ],
            'rtu_enable_redirect' => 1,
        ] );
        RTU_Options::flush_cache();
        $this->handler = new RTU_Redirect_Handler();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        parent::tearDown();
    }

    public function test_compute_target_strips_taxonomy_slug() {
        $target = $this->handler->compute_target( '/genre/rock/' );
        $this->assertSame( '/rock/', $target );
    }

    public function test_compute_target_returns_null_when_term_missing() {
        $target = $this->handler->compute_target( '/genre/ghost/' );
        $this->assertNull( $target );
    }

    public function test_compute_target_returns_null_when_feature_disabled() {
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'genre' ],
            'rtu_enable_redirect' => 0,
        ] );
        RTU_Options::flush_cache();

        $this->assertNull( $this->handler->compute_target( '/genre/rock/' ) );
    }

    public function test_compute_target_respects_should_redirect_filter() {
        add_filter( 'rtu_should_redirect', '__return_false' );
        $this->assertNull( $this->handler->compute_target( '/genre/rock/' ) );
        remove_filter( 'rtu_should_redirect', '__return_false' );
    }
}
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Redirect_Handler_Test`
Expected: class missing.

- [ ] **Step 3: Implement the handler**

`includes/class-rtu-redirect-handler.php`:
```php
<?php
/**
 * 301 redirect handler — old /taxonomy/term/ to new /term/.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class RTU_Redirect_Handler {

    /**
     * Register hooks via the plugin loader.
     *
     * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
     * @return void
     */
    public function register_hooks( $loader ) {
        if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) ) {
            return;
        }
        $loader->add_action( 'template_redirect', $this, 'maybe_redirect', 99 );
    }

    /**
     * template_redirect entry point.
     *
     * @return void
     */
    public function maybe_redirect() {
        static $already_ran = false;
        if ( $already_ran ) {
            return;
        }
        if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
            return;
        }
        $already_ran = true;

        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '';
        if ( '' === $request_uri ) {
            return;
        }

        $target = $this->compute_target( $request_uri );
        if ( null === $target ) {
            return;
        }

        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * Pure function for testability: given a path, return the redirect target or null.
     *
     * @param string $request_uri Request path (with leading slash).
     * @return string|null Redirect target path, or null when no redirect should fire.
     */
    public function compute_target( $request_uri ) {
        if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) ) {
            return null;
        }
        foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
            $pattern = '#/' . preg_quote( $slug, '#' ) . '/([^/?#]+)(/.*)?$#';
            if ( ! preg_match( $pattern, $request_uri, $m ) ) {
                continue;
            }
            $term = get_term_by( 'slug', $m[1], $slug );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $target = preg_replace( '#/' . preg_quote( $slug, '#' ) . '/#', '/', $request_uri, 1 );

            /**
             * Allow developers to suppress the 301 redirect.
             *
             * @param bool    $should  True to perform the redirect.
             * @param string  $target  Computed target URL.
             * @param WP_Term $term    Resolved term.
             */
            if ( ! apply_filters( 'rtu_should_redirect', true, $target, $term ) ) {
                return null;
            }
            return $target;
        }
        return null;
    }
}
```

- [ ] **Step 4: Wire it up**

In `includes/class-remove-taxonomy-url.php` inside `load_dependencies()` add:
```php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-redirect-handler.php';
```
And inside `define_admin_hooks()` after the rewriter wiring:
```php
$redirect = new RTU_Redirect_Handler();
$redirect->register_hooks( $this->loader );
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter RTU_Redirect_Handler_Test`
Expected: 4 PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-redirect-handler.php includes/class-remove-taxonomy-url.php tests/phpunit/test-rtu-redirect-handler.php
git commit -m "feat: add RTU_Redirect_Handler for 301 old to new URL"
```

---

### Task 9: `RTU_Pagination_Fix` — F2

**Files:**
- Create: `includes/class-rtu-pagination-fix.php`
- Create: `tests/phpunit/test-rtu-pagination-fix.php`
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Failing tests**

`tests/phpunit/test-rtu-pagination-fix.php`:
```php
<?php
class RTU_Pagination_Fix_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        register_taxonomy( 'genre', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'genre' ] ] );
        update_option( 'rtu_basics', [
            'rtu_post_types'        => [ 'genre' ],
            'rtu_enable_pagination' => 1,
        ] );
        RTU_Options::flush_cache();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        parent::tearDown();
    }

    public function test_inject_rules_appends_pagination_rule() {
        $fix     = new RTU_Pagination_Fix();
        $rules   = [ 'existing/?$' => 'index.php?existing=1' ];
        $merged  = $fix->inject_rules( $rules );

        $this->assertArrayHasKey( 'existing/?$', $merged );
        $this->assertSame(
            'index.php?genre=$matches[1]&paged=$matches[2]',
            $merged['^([^/]+)/page/?([0-9]{1,})/?$']
        );
        $this->assertSame(
            'index.php?genre=$matches[1]',
            $merged['^([^/]+)/?$']
        );
    }

    public function test_inject_rules_no_op_when_feature_disabled() {
        update_option( 'rtu_basics', [
            'rtu_post_types'        => [ 'genre' ],
            'rtu_enable_pagination' => 0,
        ] );
        RTU_Options::flush_cache();

        $fix    = new RTU_Pagination_Fix();
        $rules  = [ 'x/?$' => 'index.php?x=1' ];
        $merged = $fix->inject_rules( $rules );

        $this->assertSame( $rules, $merged );
    }
}
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Pagination_Fix_Test`
Expected: class missing.

- [ ] **Step 3: Implement**

`includes/class-rtu-pagination-fix.php`:
```php
<?php
/**
 * Pagination rewrite rules for taxonomies with their base slug removed.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class RTU_Pagination_Fix {

    /**
     * Register hooks via the plugin loader.
     *
     * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
     * @return void
     */
    public function register_hooks( $loader ) {
        $loader->add_filter( 'rewrite_rules_array', $this, 'inject_rules', 10, 1 );
    }

    /**
     * Append pagination + flat-slug rules for each active taxonomy.
     *
     * @param array $rules Current rewrite rules array.
     * @return array
     */
    public function inject_rules( $rules ) {
        if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) ) {
            return $rules;
        }
        if ( ! is_array( $rules ) ) {
            $rules = [];
        }
        foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
            $rules[ '^([^/]+)/page/?([0-9]{1,})/?$' ] = 'index.php?' . $slug . '=$matches[1]&paged=$matches[2]';
            $rules[ '^([^/]+)/?$' ]                    = 'index.php?' . $slug . '=$matches[1]';
        }
        return $rules;
    }
}
```

- [ ] **Step 4: Wire and add deferred rewrite flush**

In `includes/class-remove-taxonomy-url.php` add to `load_dependencies()`:
```php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-pagination-fix.php';
```
In `define_admin_hooks()` after the redirect wiring:
```php
$pagination = new RTU_Pagination_Fix();
$pagination->register_hooks( $this->loader );

$this->loader->add_action( 'admin_init', $this, 'maybe_flush_rewrite_rules', 99 );
```

And add this method to the same class:
```php
    /**
     * Flush rewrite rules once after migration or settings toggle.
     *
     * @return void
     */
    public function maybe_flush_rewrite_rules() {
        if ( get_transient( 'rtu_needs_flush' ) ) {
            flush_rewrite_rules( false );
            delete_transient( 'rtu_needs_flush' );
        }
    }
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-pagination-fix.php includes/class-remove-taxonomy-url.php tests/phpunit/test-rtu-pagination-fix.php
git commit -m "feat: add RTU_Pagination_Fix with deferred rewrite flush"
```

---

### Task 10: `RTU_Conflict_Detector` — passive mode + audit helper

**Files:**
- Create: `includes/class-rtu-conflict-detector.php`
- Create: `tests/phpunit/test-rtu-conflict-detector.php`
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Failing tests**

`tests/phpunit/test-rtu-conflict-detector.php`:
```php
<?php
class RTU_Conflict_Detector_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        register_taxonomy( 'genre', 'post', [ 'public' => true ] );
        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_collision' => 1,
        ] );
        RTU_Options::flush_cache();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        parent::tearDown();
    }

    public function test_find_collisions_flags_term_vs_page_clash() {
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $this->factory->post->create( [ 'post_type' => 'page', 'post_name' => 'rock', 'post_status' => 'publish' ] );

        $detector   = new RTU_Conflict_Detector();
        $collisions = $detector->find_collisions( [ 'genre' ] );

        $this->assertCount( 1, $collisions );
        $this->assertSame( 'rock', $collisions[0]['slug'] );
        $this->assertSame( 'genre', $collisions[0]['taxonomy'] );
        $this->assertContains( 'page', wp_list_pluck( $collisions[0]['conflicts'], 'type' ) );
    }

    public function test_find_collisions_empty_for_clean_slugs() {
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'unique-rock' ] );

        $detector   = new RTU_Conflict_Detector();
        $collisions = $detector->find_collisions( [ 'genre' ] );

        $this->assertSame( [], $collisions );
    }

    public function test_find_collisions_detects_cross_taxonomy_clash() {
        register_taxonomy( 'mood', 'post', [ 'public' => true ] );
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'happy' ] );
        $this->factory->term->create( [ 'taxonomy' => 'mood',  'slug' => 'happy' ] );

        $detector   = new RTU_Conflict_Detector();
        $collisions = $detector->find_collisions( [ 'genre', 'mood' ] );

        $this->assertNotEmpty( $collisions );
        unregister_taxonomy( 'mood' );
    }
}
```

- [ ] **Step 2: Run, verify failure**

Run: `vendor/bin/phpunit --filter RTU_Conflict_Detector_Test`
Expected: failures — class missing.

- [ ] **Step 3: Implement**

`includes/class-rtu-conflict-detector.php`:
```php
<?php
/**
 * Slug collision detector — warns when term slugs clash with pages/posts/other terms.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class RTU_Conflict_Detector {

    const AJAX_ACTION = 'rtu_run_audit';
    const NONCE       = 'rtu_audit_nonce';

    /**
     * Register hooks via the plugin loader.
     *
     * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
     * @return void
     */
    public function register_hooks( $loader ) {
        $loader->add_filter( 'pre_update_option_rtu_basics', $this, 'warn_on_save', 10, 2 );
        $loader->add_action( 'wp_ajax_' . self::AJAX_ACTION, $this, 'ajax_run_audit' );
    }

    /**
     * Find slug collisions across the given taxonomies.
     *
     * @param string[] $taxonomies Taxonomy slugs to audit.
     * @return array[] List of collision rows.
     */
    public function find_collisions( $taxonomies ) {
        $taxonomies = array_values( array_filter( (array) $taxonomies, 'is_string' ) );
        if ( empty( $taxonomies ) ) {
            return [];
        }

        $term_slugs_by_tax = [];
        foreach ( $taxonomies as $tax ) {
            $terms = get_terms( [
                'taxonomy'   => $tax,
                'hide_empty' => false,
                'fields'     => 'id=>slug',
            ] );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            $term_slugs_by_tax[ $tax ] = array_values( $terms );
        }

        $collisions = [];
        foreach ( $term_slugs_by_tax as $tax => $slugs ) {
            foreach ( $slugs as $slug ) {
                $conflicts = $this->collisions_for_slug( $slug, $tax, $term_slugs_by_tax );
                if ( ! empty( $conflicts ) ) {
                    $collisions[] = [
                        'taxonomy'  => $tax,
                        'slug'      => $slug,
                        'conflicts' => $conflicts,
                    ];
                }
            }
        }

        return $collisions;
    }

    /**
     * Resolve every collision target for a single term slug.
     *
     * @param string $slug              Term slug to test.
     * @param string $own_tax           Taxonomy the slug belongs to.
     * @param array  $term_slugs_by_tax Map of taxonomy => slug list, used for cross-tax checks.
     * @return array[] List of conflict descriptors { type, label }.
     */
    private function collisions_for_slug( $slug, $own_tax, $term_slugs_by_tax ) {
        global $wpdb;
        $conflicts = [];

        $post_types = get_post_types( [ 'public' => true ], 'names' );
        if ( ! empty( $post_types ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
            $sql          = $wpdb->prepare(
                "SELECT ID, post_title, post_type FROM {$wpdb->posts}
                 WHERE post_name = %s AND post_status = 'publish' AND post_type IN ($placeholders)",
                array_merge( [ $slug ], array_values( $post_types ) )
            );
            $matches = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
            foreach ( $matches as $m ) {
                $conflicts[] = [
                    'type'  => $m->post_type,
                    'label' => $m->post_title,
                ];
            }
        }

        foreach ( $term_slugs_by_tax as $other_tax => $slugs ) {
            if ( $other_tax === $own_tax ) {
                continue;
            }
            if ( in_array( $slug, $slugs, true ) ) {
                $conflicts[] = [
                    'type'  => 'taxonomy:' . $other_tax,
                    'label' => sprintf( 'Term in %s', $other_tax ),
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Surface a warning notice on settings save if collisions are present.
     * Filter callback for pre_update_option_rtu_basics. Does NOT block the save.
     *
     * @param mixed $new_value Incoming option value.
     * @param mixed $old_value Previously stored value.
     * @return mixed Unmodified $new_value.
     */
    public function warn_on_save( $new_value, $old_value ) {
        if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_collision' ) ) {
            return $new_value;
        }
        $taxonomies = ( is_array( $new_value ) && isset( $new_value['rtu_post_types'] ) )
            ? (array) $new_value['rtu_post_types']
            : [];
        $collisions = $this->find_collisions( $taxonomies );
        if ( ! empty( $collisions ) ) {
            add_settings_error(
                'rtu_basics',
                'rtu_collision',
                sprintf(
                    /* translators: %d: number of colliding term slugs */
                    esc_html__( 'Warning: %d term slug(s) conflict with existing pages/posts/terms. Visit the Health Check tab.', 'remove-taxonomy-url' ),
                    count( $collisions )
                ),
                'warning'
            );
        }
        return $new_value;
    }

    /**
     * AJAX endpoint for the Health Check audit.
     *
     * @return void
     */
    public function ajax_run_audit() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        $collisions = $this->find_collisions( RTU_Options::get_active_taxonomies() );
        wp_send_json_success( [ 'collisions' => $collisions ] );
    }
}
```

- [ ] **Step 4: Wire**

In `includes/class-remove-taxonomy-url.php::load_dependencies()`:
```php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-conflict-detector.php';
```
In `define_admin_hooks()`:
```php
$detector = new RTU_Conflict_Detector();
$detector->register_hooks( $this->loader );
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add includes/class-rtu-conflict-detector.php includes/class-remove-taxonomy-url.php tests/phpunit/test-rtu-conflict-detector.php
git commit -m "feat: add RTU_Conflict_Detector with passive warnings and audit AJAX"
```

---

## Phase 5 — Settings UI

### Task 11: Add new fields + tabs + sanitize_callback

**Files:**
- Modify: `admin/partials/remove-taxonomy-url-settings.php`
- Modify: `includes/class-remove-taxonomy-url-settings-api.php`

- [ ] **Step 1: Replace `get_settings_sections()`**

In `admin/partials/remove-taxonomy-url-settings.php`:

```php
    private function get_settings_sections() {
        $sections   = [];
        $sections[] = [
            'id'    => 'rtu_basics',
            'title' => esc_html__( 'Taxonomies', 'remove-taxonomy-url' ),
            'desc'  => sprintf(
                /* translators: %s: emphasized "IMPORTANT" label */
                esc_html__( '%s You need to save the Permalinks Twice after saving the settings otherwise you will face 404 error.', 'remove-taxonomy-url' ),
                '<strong style="font-size:1rem;color:red;">***IMPORTANT***</strong><br />'
            ),
        ];
        $sections[] = [
            'id'    => 'rtu_advanced',
            'title' => esc_html__( 'Redirects & Compatibility', 'remove-taxonomy-url' ),
            'desc'  => esc_html__( 'Enable the optional reliability features added in 3.0. All default off to preserve existing behavior.', 'remove-taxonomy-url' ),
        ];
        $sections[] = [
            'id'    => 'rtu_health',
            'title' => esc_html__( 'Health Check', 'remove-taxonomy-url' ),
            'desc'  => esc_html__( 'Audit your selected taxonomies for slug collisions with pages, posts, or other terms.', 'remove-taxonomy-url' ),
        ];
        return $sections;
    }
```

- [ ] **Step 2: Replace `get_settings_fields()`**

```php
    private function get_settings_fields() {
        $all_taxonomies = get_taxonomies( [ '_builtin' => false ] );

        $fields                  = [];
        $fields['rtu_basics']    = [
            [
                'name'    => 'rtu_post_types',
                'label'   => esc_html__( 'Taxonomies List', 'remove-taxonomy-url' ),
                'desc'    => esc_html__( 'Selected taxonomies slugs will be removed from URL.', 'remove-taxonomy-url' ),
                'type'    => 'multicheck',
                'options' => $all_taxonomies,
            ],
        ];
        $fields['rtu_advanced'] = [
            [
                'name'  => 'rtu_enable_redirect',
                'label' => esc_html__( '301 redirect old URLs', 'remove-taxonomy-url' ),
                'desc'  => esc_html__( 'Redirect /taxonomy/term/ to /term/ permanently.', 'remove-taxonomy-url' ),
                'type'  => 'checkbox',
            ],
            [
                'name'  => 'rtu_enable_pagination',
                'label' => esc_html__( 'Pagination support', 'remove-taxonomy-url' ),
                'desc'  => esc_html__( 'Fix /term/page/N/ routes after the base slug is removed.', 'remove-taxonomy-url' ),
                'type'  => 'checkbox',
            ],
            [
                'name'  => 'rtu_enable_hierarchy',
                'label' => esc_html__( 'Hierarchical term URLs', 'remove-taxonomy-url' ),
                'desc'  => esc_html__( 'Preserve parent paths for nested terms (e.g. /rock/punk/).', 'remove-taxonomy-url' ),
                'type'  => 'checkbox',
            ],
            [
                'name'    => 'rtu_enable_collision',
                'label'   => esc_html__( 'Conflict detection on save', 'remove-taxonomy-url' ),
                'desc'    => esc_html__( 'Warn (but do not block) when term slugs collide with pages, posts, or other terms.', 'remove-taxonomy-url' ),
                'type'    => 'checkbox',
                'default' => 1,
            ],
        ];
        return $fields;
    }
```

- [ ] **Step 3: Replace `rtu_settings_page()`**

```php
    public function rtu_settings_page() {
        echo '<div class="wrap">';
        $this->settings_api->show_navigation();
        echo '<div id="rtu-settings-wrapper">';
        $this->settings_api->show_forms();
        echo '</div>';
        $health = plugin_dir_path( __FILE__ ) . 'rtu-health-check.php';
        if ( file_exists( $health ) ) {
            include $health;
        }
        echo '</div>';
    }
```

- [ ] **Step 4: Wire the sanitize callback**

Find every `register_setting(` call inside `includes/class-remove-taxonomy-url-settings-api.php` (run `grep -n 'register_setting' includes/class-remove-taxonomy-url-settings-api.php`). Each call must pass the sanitize callback. The simplest portable change: pass it as the third argument:
```php
register_setting(
    $section['id'],
    $section['id'],
    [ 'sanitize_callback' => [ 'RTU_Options', 'sanitize' ] ]
);
```

If the existing implementation uses the older signature `register_setting( $section, $option )`, update it to the modern array signature shown above.

- [ ] **Step 5: Manual smoke check**

Open the WordPress admin in this Local site. Navigate to Settings → Remove Taxonomy URL. Verify three tabs render with the new fields. Toggle `rtu_enable_redirect`, save, reload — value persists.

- [ ] **Step 6: Commit**

```bash
git add admin/partials/remove-taxonomy-url-settings.php includes/class-remove-taxonomy-url-settings-api.php
git commit -m "feat: settings page gains Redirects + Health Check tabs and sanitize callback"
```

---

### Task 12: Health Check partial — audit UI

**Files:**
- Create: `admin/partials/rtu-health-check.php`
- Create: `admin/js/rtu-health-check.js`

This task uses an external JS file (not inline) because the WordPress admin runs many third-party scripts in the same DOM and we want a clean, CSP-friendly setup. The JS uses `document.createElement` + `textContent` (no `innerHTML` with user data) so escaping is enforced by the DOM API rather than string concatenation.

- [ ] **Step 1: Create `admin/js/rtu-health-check.js`**

```javascript
( function () {
    'use strict';

    const button = document.getElementById( 'rtu-run-audit' );
    if ( ! button ) {
        return;
    }
    const out     = document.getElementById( 'rtu-audit-results' );
    const spinner = button.nextElementSibling;
    const labels  = ( window.rtuHealthCheckL10n || {} );

    function buildTable( rows ) {
        const table = document.createElement( 'table' );
        table.className = 'widefat striped';

        const thead = document.createElement( 'thead' );
        const headRow = document.createElement( 'tr' );
        [ labels.taxonomy, labels.termSlug, labels.conflictsWith ].forEach( function ( text ) {
            const th = document.createElement( 'th' );
            th.textContent = text || '';
            headRow.appendChild( th );
        } );
        thead.appendChild( headRow );
        table.appendChild( thead );

        const tbody = document.createElement( 'tbody' );
        rows.forEach( function ( row ) {
            const tr = document.createElement( 'tr' );

            const taxCell = document.createElement( 'td' );
            taxCell.textContent = row.taxonomy || '';
            tr.appendChild( taxCell );

            const slugCell = document.createElement( 'td' );
            slugCell.textContent = row.slug || '';
            tr.appendChild( slugCell );

            const conflictsCell = document.createElement( 'td' );
            ( row.conflicts || [] ).forEach( function ( c, index ) {
                if ( index > 0 ) {
                    conflictsCell.appendChild( document.createElement( 'br' ) );
                }
                const span = document.createElement( 'span' );
                span.textContent = ( c.type || '' ) + ': ' + ( c.label || '' );
                conflictsCell.appendChild( span );
            } );
            tr.appendChild( conflictsCell );

            tbody.appendChild( tr );
        } );
        table.appendChild( tbody );

        return table;
    }

    function setMessage( text ) {
        out.replaceChildren();
        const p = document.createElement( 'p' );
        p.textContent = text;
        out.appendChild( p );
    }

    button.addEventListener( 'click', async function () {
        spinner.classList.add( 'is-active' );
        out.replaceChildren();

        const body = new URLSearchParams();
        body.append( 'action', button.dataset.action );
        body.append( 'nonce', button.dataset.nonce );

        try {
            const res = await fetch( window.ajaxurl, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
            } );
            const json = await res.json();
            if ( ! json.success ) {
                setMessage( labels.failed || 'Audit failed.' );
                return;
            }
            const rows = json.data && json.data.collisions ? json.data.collisions : [];
            if ( rows.length === 0 ) {
                setMessage( labels.noConflicts || 'No collisions found.' );
                return;
            }
            out.replaceChildren( buildTable( rows ) );
        } catch ( err ) {
            setMessage( labels.failed || 'Audit failed.' );
        } finally {
            spinner.classList.remove( 'is-active' );
        }
    } );
} )();
```

- [ ] **Step 2: Create `admin/partials/rtu-health-check.php`**

```php
<?php
/**
 * Health Check tab — slug collision audit.
 *
 * @package Remove_Taxonomy_Url
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no action taken.
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
if ( 'rtu_health' !== $current_tab ) {
    return;
}

$nonce = wp_create_nonce( RTU_Conflict_Detector::NONCE );
?>
<div id="rtu-health-check">
    <p>
        <button type="button" class="button button-primary" id="rtu-run-audit"
            data-nonce="<?php echo esc_attr( $nonce ); ?>"
            data-action="<?php echo esc_attr( RTU_Conflict_Detector::AJAX_ACTION ); ?>">
            <?php esc_html_e( 'Run audit', 'remove-taxonomy-url' ); ?>
        </button>
        <span class="spinner" style="float:none;"></span>
    </p>
    <div id="rtu-audit-results"></div>
</div>
```

- [ ] **Step 3: Enqueue the JS only on the plugin settings page**

In `admin/class-remove-taxonomy-url-admin.php` replace the existing `enqueue_scripts()` method with:
```php
    public function enqueue_scripts( $hook_suffix ) {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url( __FILE__ ) . 'js/remove-taxonomy-url-admin.js',
            [ 'jquery' ],
            $this->version,
            false
        );

        if ( 'settings_page_rtu_settings_page' !== $hook_suffix ) {
            return;
        }

        wp_enqueue_script(
            'rtu-health-check',
            plugin_dir_url( __FILE__ ) . 'js/rtu-health-check.js',
            [],
            $this->version,
            true
        );
        wp_localize_script(
            'rtu-health-check',
            'rtuHealthCheckL10n',
            [
                'taxonomy'      => __( 'Taxonomy', 'remove-taxonomy-url' ),
                'termSlug'      => __( 'Term slug', 'remove-taxonomy-url' ),
                'conflictsWith' => __( 'Conflicts with', 'remove-taxonomy-url' ),
                'noConflicts'   => __( 'No collisions found.', 'remove-taxonomy-url' ),
                'failed'        => __( 'Audit failed.', 'remove-taxonomy-url' ),
            ]
        );
    }
```

- [ ] **Step 4: Move the health-check JS into `admin/js/`**

Verify the file lives at `admin/js/rtu-health-check.js` (created in Step 1).

- [ ] **Step 5: Manual smoke check**

In the admin, create a page with slug `rock`, create a term `rock` in your selected taxonomy, switch to the Health Check tab, click "Run audit". Verify the table lists the collision. Open the browser console and confirm no JS errors.

- [ ] **Step 6: Commit**

```bash
git add admin/partials/rtu-health-check.php admin/js/rtu-health-check.js admin/class-remove-taxonomy-url-admin.php
git commit -m "feat: Health Check tab — audit UI with DOM-safe JS"
```

---

### Task 13: Upgrade banner

**Files:**
- Create: `includes/class-rtu-admin-notices.php`
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Create the notice class**

`includes/class-rtu-admin-notices.php`:
```php
<?php
/**
 * One-time 3.0 upgrade banner.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class RTU_Admin_Notices {

    const OPTION = 'rtu_30_notice_dismissed';
    const ACTION = 'rtu_dismiss_30_notice';
    const NONCE  = 'rtu_dismiss_30_notice_nonce';

    /**
     * Register hooks via the plugin loader.
     *
     * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
     * @return void
     */
    public function register_hooks( $loader ) {
        $loader->add_action( 'admin_notices', $this, 'render' );
        $loader->add_action( 'admin_post_' . self::ACTION, $this, 'dismiss' );
    }

    /**
     * Render the banner on Dashboard and the plugin settings page only.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Default value 1 means "do not show" — only the migration code arms the banner by setting it to 0.
        if ( (int) get_option( self::OPTION, 1 ) !== 0 ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }
        $allowed = [ 'dashboard', 'settings_page_rtu_settings_page' ];
        if ( ! in_array( $screen->id, $allowed, true ) ) {
            return;
        }
        $settings_url = admin_url( 'options-general.php?page=rtu_settings_page' );
        $dismiss_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::ACTION ),
            self::ACTION,
            self::NONCE
        );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e( 'Remove Taxonomy URL 3.0', 'remove-taxonomy-url' ); ?></strong>
                — <?php esc_html_e( 'New features available: 301 redirects, pagination fix, conflict detector. All disabled by default to preserve your current behavior.', 'remove-taxonomy-url' ); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Review settings', 'remove-taxonomy-url' ); ?></a>
                <a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'remove-taxonomy-url' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Handle the dismiss admin-post action.
     *
     * @return void
     */
    public function dismiss() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden', '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::ACTION, self::NONCE );
        update_option( self::OPTION, 1 );
        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? $referer : admin_url() );
        exit;
    }
}
```

- [ ] **Step 2: Wire**

In `includes/class-remove-taxonomy-url.php::load_dependencies()`:
```php
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-rtu-admin-notices.php';
```
In `define_admin_hooks()`:
```php
$notices = new RTU_Admin_Notices();
$notices->register_hooks( $this->loader );
```

- [ ] **Step 3: Manual check**

Set the banner-active state and reload:
```bash
wp option update rtu_30_notice_dismissed 0
```
Visit Dashboard → banner shows. Click "Dismiss" → banner gone, option value is now `1`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rtu-admin-notices.php includes/class-remove-taxonomy-url.php
git commit -m "feat: add one-time upgrade banner for 3.0 features"
```

---

## Phase 6 — Bug fixes + Standards remediation

### Task 14: Fill out `uninstall.php` (Standards §13)

**Files:**
- Modify: `uninstall.php`

- [ ] **Step 1: Replace the stub body**

Replace everything **below** the `defined( 'WP_UNINSTALL_PLUGIN' )` exit check with:

```php
global $wpdb;

delete_option( 'rtu_basics' );
delete_option( 'rtu_db_version' );
delete_option( 'rtu_30_notice_dismissed' );

$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_rtu\_%' ESCAPE '\\\\'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary cleanup of plugin transients on uninstall.

$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_rtu\_%' ESCAPE '\\\\'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.

// Force rewrite rule regeneration on next request.
delete_option( 'rewrite_rules' );
```

- [ ] **Step 2: Commit**

```bash
git add uninstall.php
git commit -m "fix: uninstall.php cleans up options, transients, rewrite rules (Standards §13)"
```

---

### Task 15: Header metadata + version bumps

**Files:**
- Modify: `remove-taxonomy-url.php`
- Modify: `README.txt`
- Modify: `README.md`

- [ ] **Step 1: Update the plugin header**

In `remove-taxonomy-url.php`, change the header block to:
```
* @link              https://www.sungraizfaryad.com
* @since             1.0.0
* @package           Remove_Taxonomy_Url
*
* @wordpress-plugin
* Plugin Name:       Remove Taxonomy URL
* Plugin URI:        https://wordpress.org/plugins/remove-taxonomy-url/
* Description:       This is a purpose oriented plugin that just removes the custom taxonomies slugs from URL.
* Version:           3.0.0
* Requires at least: 5.0
* Requires PHP:      7.4
* Author:            Sungraiz Faryad
* Author URI:        https://www.sungraizfaryad.com
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Text Domain:       remove-taxonomy-url
* Domain Path:       /languages
```

And the constant:
```php
define( 'REMOVE_TAXONOMY_URL_VERSION', '3.0.0' );
```

- [ ] **Step 2: Update `README.txt`**

Change the top metadata block:
```
Requires at least: 5.0
Tested up to: 6.7.5
Stable tag: 3.0.0
Requires PHP: 7.4
```
(use the latest released WordPress version at release time for `Tested up to`).

Insert into the Changelog section above the existing `= 1.0.6 =` entry:
```
= 3.0.0 =
* New: 301 redirect from old /taxonomy/term/ to /term/.
* New: pagination support for taxonomies with their base removed.
* New: hierarchical term URLs (multi-level parent paths).
* New: slug-collision detector — warns on settings save, full audit on the Health Check tab.
* Improved: hardened term-link rewriting against parent-path over-matching.
* Improved: orphan/circular term parent chains no longer cause loops.
* Fix: settings now have a sanitize callback (Plugin Standards §6).
* Fix: settings page registration moved from admin_menu to admin_init.
* Fix: uninstall now removes all plugin options and transients (Plugin Standards §13).
* Compatibility: tested with PHP 7.4–8.3.
```

- [ ] **Step 3: Mirror to `README.md`**

Update `README.md` to match the new version metadata and changelog summary.

- [ ] **Step 4: Commit**

```bash
git add remove-taxonomy-url.php README.txt README.md
git commit -m "release: bump to 3.0.0 — version headers, URI scheme, PHP/WP requirements"
```

---

### Task 16: PHPCS WordPress Coding Standards pass

**Files:**
- Create: `.phpcs.xml.dist`
- Plus any source files that need fixes during the pass.

- [ ] **Step 1: Create ruleset**

`.phpcs.xml.dist`:
```xml
<?xml version="1.0"?>
<ruleset name="Remove Taxonomy URL">
    <description>Plugin coding standards.</description>

    <file>./includes</file>
    <file>./admin</file>
    <file>./remove-taxonomy-url.php</file>
    <file>./uninstall.php</file>

    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
    <exclude-pattern>*/tests/*</exclude-pattern>

    <arg value="ps"/>
    <arg name="extensions" value="php"/>

    <rule ref="WordPress">
        <exclude name="WordPress.Files.FileName.NotHyphenatedLowercase"/>
    </rule>

    <rule ref="PHPCompatibilityWP"/>
    <config name="testVersion" value="7.4-"/>
    <config name="minimum_wp_version" value="5.0"/>
</ruleset>
```

- [ ] **Step 2: Run PHPCS**

Run: `vendor/bin/phpcs --report=summary`
Expected: a list of errors + warnings.

- [ ] **Step 3: Fix all errors**

Work through errors one at a time. Common required fixes:
- Missing `wp_unslash()` / `sanitize_*()` on `$_GET` / `$_POST` reads — add them
- Missing escaping on output — wrap with `esc_html`, `esc_attr`, `esc_url`, or `wp_kses_post`
- Yoda conditions, alignment, doc blocks — apply per the reported rule

Re-run `vendor/bin/phpcs` until errors drop to 0. Warnings can remain only if their fix is intrusive — document each suppression with an inline `// phpcs:ignore <Rule> -- reason` comment.

- [ ] **Step 4: Commit**

```bash
git add .phpcs.xml.dist
git add -A  # picks up source files modified during the fix pass
git commit -m "chore: add WPCS ruleset and fix all reported errors"
```

---

### Task 17: WP Plugin Check tool pass

- [ ] **Step 1: Install Plugin Check**

In the Local site, run:
```bash
wp plugin install plugin-check --activate
```

- [ ] **Step 2: Run the check**

```bash
wp plugin check remove-taxonomy-url
```
Expected output: lists any remaining issues by category (`Security`, `Plugin Repo`, `General`).

- [ ] **Step 3: Resolve every error**

Iterate on the source code until `wp plugin check remove-taxonomy-url` reports 0 errors. Warnings can stay only with an inline `// phpcs:ignore` comment that explains why.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: Plugin Check passes with zero errors"
```

---

## Phase 7 — Integration testing + release

### Task 18: Manual integration test on Local site

This task is manual. Capture results in a local notes file (do NOT commit it).

- [ ] **Step 1: Fresh-install test (clean WP 6.7)**

1. Create a fresh Local site or reset this one (`wp db reset --yes && wp core install ...`).
2. Build the plugin zip:
   ```bash
   git archive --format=zip --prefix=remove-taxonomy-url/ --output=/tmp/remove-taxonomy-url-3.0.0.zip HEAD
   ```
3. Install the plugin from the local zip:
   ```bash
   wp plugin install /tmp/remove-taxonomy-url-3.0.0.zip --activate
   ```
4. Register a test taxonomy via a tiny mu-plugin:
   ```php
   // wp-content/mu-plugins/rtu-test-tax.php
   add_action( 'init', function () {
       register_taxonomy( 'genre', 'post', [ 'public' => true, 'hierarchical' => true, 'rewrite' => [ 'slug' => 'genre' ] ] );
   } );
   ```
5. Create terms via WP-CLI:
   ```bash
   wp term create genre rock --slug=rock
   ROCK_ID=$(wp term get genre rock --by=slug --field=term_id)
   wp term create genre punk --slug=punk --parent=$ROCK_ID
   wp term create genre jazz --slug=jazz
   ```
6. In Settings → Remove Taxonomy URL → Basics, select `genre`, save. Settings → Permalinks → Save (twice). Visit `/rock/` → 200. Visit `/punk/` → 404 (hierarchy off).
7. Enable `rtu_enable_hierarchy`, save, flush permalinks. Visit `/rock/punk/` → 200.
8. Enable `rtu_enable_redirect`. Visit `/genre/rock/` → 301 → `/rock/` 200. Verify with:
   ```bash
   curl -sIL "$(wp option get siteurl)/genre/rock/"
   ```
9. Enable `rtu_enable_pagination`. Visit `/rock/page/2/` → 200 (if enough posts) or 404 (no posts on page 2) — both are acceptable, just not a 500.
10. Create a page slug `rock`. Switch to Health Check tab, click "Run audit" → table lists the collision.

- [ ] **Step 2: Upgrade-path test (1.0.6 → 3.0.0)**

1. On a separate Local site, install Remove Taxonomy URL 1.0.6 from WP.org. Activate. Select a taxonomy. Save.
2. Replace plugin files in-place with the 3.0 build:
   ```bash
   rsync -a --delete \
       --exclude '.git' --exclude 'vendor' --exclude 'tests' --exclude 'docs' --exclude 'bin' --exclude 'composer.json' --exclude 'composer.lock' --exclude '.phpcs.xml.dist' --exclude 'phpunit.xml.dist' \
       "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/remove-taxonomy-url/" \
       /path/to/other-local-site/app/public/wp-content/plugins/remove-taxonomy-url/
   ```
3. Reload `wp-admin` → upgrade banner appears once. Click "Review settings" → existing taxonomy still selected.
4. Verify the option shape:
   ```bash
   wp option get rtu_basics --format=json
   ```
   Confirm the new keys (`rtu_enable_redirect`, `rtu_enable_pagination`, `rtu_enable_hierarchy`, `rtu_enable_collision`, `rtu_db_version`) are present with safe defaults.
5. Click "Dismiss" on the banner → reload → banner gone.

- [ ] **Step 3: Compat smoke test**

Activate Yoast SEO, reload front-end pages with the rewriter active. Check that no PHP notices appear in `wp-content/debug.log` (with `WP_DEBUG = true`). Deactivate Yoast. Repeat with Rank Math.

- [ ] **Step 4: PHP version matrix**

Re-run `vendor/bin/phpunit` against PHP 7.4, 8.0, 8.1, 8.2, 8.3 if multiple PHP versions are available locally (Local's PHP switcher). At minimum, run against the PHP version the Local site is configured to use.

---

### Task 19: Release prep

- [ ] **Step 1: Verify version stamps line up**

Run:
```bash
grep -nE 'Version:|REMOVE_TAXONOMY_URL_VERSION|Stable tag:' remove-taxonomy-url.php README.txt
```
Expected: every match references `3.0.0`.

- [ ] **Step 2: Add `.gitattributes` to exclude dev files from `git archive`**

Create `.gitattributes`:
```
/tests              export-ignore
/bin                export-ignore
/docs               export-ignore
/.phpcs.xml.dist    export-ignore
/phpunit.xml.dist   export-ignore
/composer.json      export-ignore
/composer.lock      export-ignore
/.gitignore         export-ignore
/.gitattributes     export-ignore
```

- [ ] **Step 3: Build release zip**

```bash
git archive --format=zip --prefix=remove-taxonomy-url/ --output=/tmp/remove-taxonomy-url-3.0.0.zip HEAD
```

Verify the contents:
```bash
unzip -l /tmp/remove-taxonomy-url-3.0.0.zip
```
Confirm none of the dev-only paths are present (`tests/`, `bin/`, `docs/`, `composer.json`, `.phpcs.xml.dist`, `phpunit.xml.dist`).

- [ ] **Step 4: Tag the release**

```bash
git tag -a v3.0.0 -m "Remove Taxonomy URL 3.0.0"
```

- [ ] **Step 5: Hold for user before pushing**

Stop here. Do NOT `git push`, do NOT `svn commit` to WP.org. Report back to the user with:
- Path to the release zip
- Confirmation that PHPUnit, PHPCS, and Plugin Check all pass
- Manual integration test notes

The user runs the SVN deploy to WP.org manually following their existing workflow.

---

## Self-review

After writing this plan, I checked:

1. **Spec coverage** — every section of the spec has a task:
   - §3 F1 → Task 8 ✓
   - §3 F2 → Task 9 ✓
   - §3 F3 (hierarchy) → Task 7 ✓
   - §3 F4 → Task 10 (detector) + Task 12 (UI) ✓
   - §3 F5 (preserve existing behavior) → Tasks 6 + 7 ✓
   - §4 architecture → Tasks 6–10, 13 ✓
   - §5 data model + migration → Tasks 2–5 ✓
   - §6 module designs → Tasks 6–10, 13 ✓
   - §7 settings UI → Tasks 11–13 ✓
   - §8 bug fixes B1 (stripos) → Task 6; B2 (uninstall) → Task 14; B3 (sanitize_callback) → Task 11; B4 (admin_init) → Task 7 step 6; B5 (URI scheme) → Task 15; B6 (orphan guard) → Task 7; B7 (Requires PHP) → Task 15; B8 (Tested up to) → Task 15; B9 (`$_GET` sanitization) → Task 12 + Task 16 PHPCS pass; B10 (Plugin Check + WPCS) → Tasks 16, 17 ✓
   - §9 testing → Tasks 1–10 (each module has a test); Task 18 manual integration ✓
   - §10 release plan → Tasks 18, 19 ✓

2. **Placeholder scan** — no TBD/TODO/"handle edge cases" placeholders. Every code step shows full code.

3. **Type consistency** — `RTU_Options::DB_VERSION` referenced in Task 5, defined in Task 2. `RTU_Conflict_Detector::AJAX_ACTION`/`::NONCE` referenced from Task 12 partial, defined in Task 10. `register_hooks( $loader )` signature used identically across all modules. Option key `rtu_30_notice_dismissed` written in Task 5 step 3, read in Task 13. The Health Check JS file path (`admin/js/rtu-health-check.js`) referenced in Task 12 enqueue step matches the file location.

4. **Security** — Health Check JS uses `document.createElement` + `textContent` exclusively, no `innerHTML` with server data. Uninstall queries use `ESCAPE '\\\\'` against the `LIKE` pattern. AJAX handler verifies nonce + capability. Dismiss handler verifies nonce + capability. All `$_GET` / `$_POST` reads pass through `wp_unslash()` + `sanitize_text_field()` or equivalent.

5. **Out-of-scope items** correctly deferred to 4.0: WPML/Polylang, WooCommerce, SEO integrations, WP-CLI, REST endpoints.
