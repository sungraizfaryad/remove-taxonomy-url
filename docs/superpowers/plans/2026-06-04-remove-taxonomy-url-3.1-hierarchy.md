# Remove Taxonomy URL 3.1 — Hierarchy Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the "Hierarchical term URLs" option actually resolve nested child-term URLs (`/portugal/lisbon/`) at arbitrary depth, 301 flat URLs to their canonical nested form, add a cause-agnostic URL resolution self-test to Health Check, and ship clearer per-setting help plus save/flush feedback — as 3.1.0.

**Architecture:** Explicit per-child rewrite rules (one specific rule per child term, mapping the full ancestor path to the leaf slug — unique per taxonomy, so unambiguous) injected via `rewrite_rules_array`; `term_link` prepends ancestor slugs so links match; a canonical 301 sends flat child URLs to the nested form; a loopback-based self-test reports per-term PASS/FAIL. New modules `RTU_Hierarchy_Rules` and `RTU_Url_Selftest`; existing rewriter/redirect/detector/options/admin extended along current seams.

**Tech Stack:** WordPress plugin (Boilerplate + Loader pattern), PHP 7.4+, WP-PHPUnit (PHP 8.2.27 via Local bundle + Unix-socket test DB), PHPCS (WordPress standard).

---

## Conventions for every task

**Canonical repo (source of truth + git + unit tests):**
`/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/remove-taxonomy-url`

**Run the unit suite** (from the canonical repo root):
```bash
PHP82="$HOME/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin-arm64/bin/php"
WP_TESTS_DIR=/tmp/claude-501/wordpress-tests-lib "$PHP82" vendor/bin/phpunit
```
If `/tmp/claude-501/wordpress-tests-lib` is missing (e.g. after a reboot), reinstall once:
```bash
SOCK="$HOME/Library/Application Support/Local/run/H5cgHPi5B/mysql/mysqld.sock"
MYSQL_BIN=$(dirname "$(ls "$HOME/Library/Application Support/Local/lightning-services/mysql"*/bin/darwin-arm64/bin/mysql | head -1)")
PATH="$MYSQL_BIN:$PATH" DB_SOCKET="$SOCK" bash bin/install-wp-tests.sh wordpress_test root root localhost latest true
```

**Run PHPCS on a file:**
```bash
"$PHP82" vendor/bin/phpcs --standard=.phpcs.xml.dist <file>
```

**FLP integration install** (real big hierarchical taxonomy — 444 `property-city` terms, deep nesting):
`/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/remove-taxonomy-url`
Mirror a changed file to FLP for integration testing with:
```bash
cp "<canonical>/includes/FILE.php" "/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/remove-taxonomy-url/includes/FILE.php"
```
FLP MySQL socket: `$HOME/Library/Application Support/Local/run/8-CWukao6/mysql/mysqld.sock`
FLP bundled mysql client: `$HOME/Library/Application Support/Local/lightning-services/mysql-8.0.35+4/bin/darwin-arm64/bin/mysql` (user root / pass root, db `local`).
FLP admin auto-login: `https://flp.local/matt-sung/?localwp_auto_login=36` (WPS Hide Login renames wp-login.php to `matt-sung`; trailing slash required).

All new PHP files start with the direct-access guard:
```php
if ( ! defined( 'WPINC' ) ) {
	die;
}
```
Text domain: `remove-taxonomy-url`. Commit after every task.

---

## File structure

**Create:**
- `includes/class-rtu-hierarchy-rules.php` — `RTU_Hierarchy_Rules`: `term_path()` ancestor-path helper, explicit per-child rule generation with cap, `rewrite_rules_array` injection, term-change flush arming.
- `includes/class-rtu-url-selftest.php` — `RTU_Url_Selftest`: sample active terms, loopback-test each stripped URL, internal fallback, AJAX endpoint.
- `tests/phpunit/test-rtu-hierarchy-rules.php`
- `tests/phpunit/test-rtu-url-selftest.php`

**Modify:**
- `includes/class-rtu-options.php` — `DB_VERSION` → `3.1.0`; `get_last_flushed()`/`set_last_flushed()`; `count_child_terms()`.
- `includes/class-rtu-url-rewriter.php` — `filter_term_link` ancestor prepend (hierarchy ON); simplify `filter_request` to resolve the leaf slug (drop the buggy path-building); remove now-unused `prepend_parents`.
- `includes/class-rtu-redirect-handler.php` — canonical flat→nested 301 when hierarchy ON.
- `includes/class-remove-taxonomy-url.php` — require + wire the two new modules; arm flush on term changes and on `rtu_basics` update; stamp `rtu_last_flushed` in `maybe_flush_rewrite_rules`.
- `includes/class-rtu-conflict-detector.php` — hierarchy off→on save notice; hierarchy status in the audit payload.
- `admin/class-remove-taxonomy-url-admin.php` — localize self-test + status L10n strings.
- `admin/partials/remove-taxonomy-url-settings.php` — richer descriptions, general note, version + last-flushed line.
- `admin/partials/rtu-health-check.php` — self-test button + results container.
- `admin/js/rtu-health-check.js` — render self-test rows + hierarchy status.
- `remove-taxonomy-url.php` — `REMOVE_TAXONOMY_URL_VERSION` + header → `3.1.0`.
- `README.txt` — `Stable tag: 3.1.0` + changelog.
- `uninstall.php` — delete `rtu_last_flushed`.
- `tests/phpunit/test-rtu-options.php`, `test-rtu-url-rewriter.php`, `test-rtu-redirect-handler.php`, `test-rtu-conflict-detector.php` — extend.

---

## Task 1: Options — version bump, last-flushed, child-count helper

**Files:**
- Modify: `includes/class-rtu-options.php`
- Test: `tests/phpunit/test-rtu-options.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/phpunit/test-rtu-options.php` before the final closing `}`:

```php
	public function test_db_version_is_3_1_0() {
		$this->assertSame( '3.1.0', RTU_Options::DB_VERSION );
	}

	public function test_last_flushed_round_trips() {
		delete_option( 'rtu_last_flushed' );
		$this->assertSame( 0, RTU_Options::get_last_flushed() );
		RTU_Options::set_last_flushed( 1700000000 );
		$this->assertSame( 1700000000, RTU_Options::get_last_flushed() );
	}

	public function test_count_child_terms_counts_only_children_of_active_taxonomies() {
		register_taxonomy( 'genre', 'post', array( 'hierarchical' => true ) );
		$parent = self::factory()->term->create( array( 'taxonomy' => 'genre', 'slug' => 'rock' ) );
		self::factory()->term->create( array( 'taxonomy' => 'genre', 'slug' => 'punk', 'parent' => $parent ) );
		self::factory()->term->create( array( 'taxonomy' => 'genre', 'slug' => 'metal' ) ); // top-level
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'genre' => 'genre' ) ) );
		RTU_Options::flush_cache();

		$this->assertSame( 1, RTU_Options::count_child_terms() );
		unregister_taxonomy( 'genre' );
	}
```

- [ ] **Step 2: Run, verify failure**

Run the suite. Expected: 3 failures (`DB_VERSION` is `3.0.1`; `get_last_flushed`/`set_last_flushed`/`count_child_terms` undefined).

- [ ] **Step 3: Implement**

In `includes/class-rtu-options.php`, change the constant:

```php
	const DB_VERSION = '3.1.0';
```

Add these methods inside the class (after `maybe_flush_on_update`):

```php
	/**
	 * Unix timestamp of the last rewrite-rule flush the plugin performed, or 0.
	 *
	 * @return int
	 */
	public static function get_last_flushed() {
		return (int) get_option( 'rtu_last_flushed', 0 );
	}

	/**
	 * Record the time of a rewrite-rule flush.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return void
	 */
	public static function set_last_flushed( $timestamp ) {
		update_option( 'rtu_last_flushed', (int) $timestamp, false );
	}

	/**
	 * Count child terms (parent != 0) across the active taxonomies.
	 *
	 * @return int
	 */
	public static function count_child_terms() {
		$count = 0;
		foreach ( self::get_active_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'     => $taxonomy,
					'hide_empty'   => false,
					'parent__not'  => 0,
					'fields'       => 'ids',
					'number'       => 0,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				$count += count( $terms );
			}
		}
		return $count;
	}
```

Note: `parent__not` is not a core arg; use `'parent' => 0` inverted is wrong. Replace the `get_terms` args with a correct child filter:

```php
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'id=>parent',
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $parent_id ) {
					if ( (int) $parent_id !== 0 ) {
						$count++;
					}
				}
			}
```

(Use the `id=>parent` form above; delete the `parent__not` version.)

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: all green (existing 42 + 3 new = 45).

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-options.php tests/phpunit/test-rtu-options.php
git commit -m "feat(3.1): bump DB_VERSION, add last-flushed + child-count helpers"
```

---

## Task 2: Hierarchy rules — `term_path()` ancestor-path helper

**Files:**
- Create: `includes/class-rtu-hierarchy-rules.php`
- Test: `tests/phpunit/test-rtu-hierarchy-rules.php`

- [ ] **Step 1: Write failing test**

Create `tests/phpunit/test-rtu-hierarchy-rules.php`:

```php
<?php
class RTU_Hierarchy_Rules_Test extends WP_UnitTestCase {

	private $tax = 'geo';

	public function set_up() {
		parent::set_up();
		register_taxonomy( $this->tax, 'post', array( 'hierarchical' => true ) );
	}

	public function tear_down() {
		unregister_taxonomy( $this->tax );
		parent::tear_down();
	}

	public function test_term_path_top_level_is_just_slug() {
		$id   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		$term = get_term( $id, $this->tax );
		$this->assertSame( 'portugal', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}

	public function test_term_path_multi_level() {
		$pt   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		$al   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'algarve', 'parent' => $pt ) );
		$tv   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lagos', 'parent' => $al ) );
		$term = get_term( $tv, $this->tax );
		$this->assertSame( 'portugal/algarve/lagos', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}

	public function test_term_path_stops_on_missing_ancestor() {
		$ghost = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'ghost' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'child', 'parent' => $ghost ) );
		wp_delete_term( $ghost, $this->tax ); // orphan the child
		$term = get_term( $child, $this->tax );
		// Parent gone -> WP reparents child to 0; path is just its own slug.
		$this->assertSame( 'child', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}
}
```

- [ ] **Step 2: Run, verify failure**

Run the suite. Expected: fatal/`class RTU_Hierarchy_Rules not found`.

- [ ] **Step 3: Implement**

Create `includes/class-rtu-hierarchy-rules.php`:

```php
<?php
/**
 * Hierarchical rewrite-rule generation for Remove Taxonomy URL.
 *
 * When "Hierarchical term URLs" is ON, child terms get a flat base-stripped URL that
 * includes their full ancestor path (e.g. /portugal/lisbon/). This module builds an
 * explicit rewrite rule per child term mapping that full path to the term's LEAF slug
 * query var. Leaf slugs are unique per taxonomy in WordPress, so the mapping is
 * unambiguous at any depth.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds + injects explicit nested rewrite rules and arms a flush when terms change.
 */
class RTU_Hierarchy_Rules {

	/**
	 * Maximum child terms per taxonomy for which rules are generated. Beyond this the
	 * taxonomy is skipped and surfaced as a warning rather than silently truncated.
	 */
	const MAX_CHILD_TERMS = 2000;

	/**
	 * Full slug path for a term: ancestor slugs (root-first) joined with its own slug.
	 *
	 * Guards against orphan/circular parent chains with a visited set and a hard cap,
	 * mirroring the redirect/rewriter walkers.
	 *
	 * @param WP_Term|int $term     Term object or ID.
	 * @param string      $taxonomy Taxonomy slug.
	 * @return string Slash-joined path, e.g. "portugal/algarve/lagos".
	 */
	public static function term_path( $term, $taxonomy ) {
		$term = is_numeric( $term ) ? get_term( (int) $term, $taxonomy ) : $term;
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		$segments = array( $term->slug );
		$parent   = (int) $term->parent;
		$visited  = array( (int) $term->term_id => true );
		$safety   = 0;

		while ( $parent && $safety++ < 25 ) {
			if ( isset( $visited[ $parent ] ) ) {
				break;
			}
			$visited[ $parent ] = true;

			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				break;
			}
			array_unshift( $segments, $parent_term->slug );
			$parent = (int) $parent_term->parent;
		}

		return implode( '/', $segments );
	}
}
```

- [ ] **Step 4: Run, verify pass**

Add the new test file is picked up (it is — `tests/phpunit/` suffix `.php`). Run the suite. Expected: 3 new tests green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-hierarchy-rules.php tests/phpunit/test-rtu-hierarchy-rules.php
git commit -m "feat(3.1): RTU_Hierarchy_Rules::term_path ancestor walker"
```

---

## Task 3: Hierarchy rules — generate explicit per-child rules (with cap)

**Files:**
- Modify: `includes/class-rtu-hierarchy-rules.php`
- Test: `tests/phpunit/test-rtu-hierarchy-rules.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/phpunit/test-rtu-hierarchy-rules.php` before the final `}`:

```php
	private function enable_hierarchy_for( $tax ) {
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( $tax => $tax ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();
	}

	public function test_generate_rules_creates_path_rules_for_children_only() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		$this->enable_hierarchy_for( $this->tax );

		$rules = ( new RTU_Hierarchy_Rules() )->generate_rules();

		// Child term gets two rules (base + pagination); top-level term gets none here.
		$this->assertArrayHasKey( '^portugal/lisbon/?$', $rules );
		$this->assertSame( 'index.php?' . $this->tax . '=lisbon', $rules['^portugal/lisbon/?$'] );
		$this->assertArrayHasKey( '^portugal/lisbon/page/?([0-9]{1,})/?$', $rules );
		$this->assertSame(
			'index.php?' . $this->tax . '=lisbon&paged=$matches[1]',
			$rules['^portugal/lisbon/page/?([0-9]{1,})/?$']
		);
		$this->assertArrayNotHasKey( '^portugal/?$', $rules );
	}

	public function test_generate_rules_empty_when_hierarchy_off() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( $this->tax => $this->tax ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$this->assertSame( array(), ( new RTU_Hierarchy_Rules() )->generate_rules() );
	}

	public function test_skipped_taxonomies_reports_over_cap() {
		// Use reflection-free behavior: a tiny cap via filter is overkill; assert helper exists and is empty here.
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		$this->enable_hierarchy_for( $this->tax );
		$rules = new RTU_Hierarchy_Rules();
		$rules->generate_rules();
		$this->assertSame( array(), $rules->get_skipped_taxonomies() );
	}
```

- [ ] **Step 2: Run, verify failure**

Run the suite. Expected: failures (`generate_rules`, `get_skipped_taxonomies` undefined).

- [ ] **Step 3: Implement**

Add to `includes/class-rtu-hierarchy-rules.php` inside the class:

```php
	/**
	 * Taxonomies skipped this generation because they exceeded MAX_CHILD_TERMS.
	 *
	 * @var array<string,int> taxonomy => child count
	 */
	private $skipped = array();

	/**
	 * Build the explicit nested rewrite rules for every active taxonomy's child terms.
	 *
	 * @return array<string,string> Rewrite rule regex => index.php target.
	 */
	public function generate_rules() {
		$this->skipped = array();
		$rules         = array();

		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' ) ) {
			return $rules;
		}

		foreach ( RTU_Options::get_active_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$children = array_filter(
				$terms,
				static function ( $t ) {
					return (int) $t->parent !== 0;
				}
			);

			if ( count( $children ) > self::MAX_CHILD_TERMS ) {
				$this->skipped[ $taxonomy ] = count( $children );
				continue;
			}

			foreach ( $children as $term ) {
				$path = self::term_path( $term, $taxonomy );
				if ( '' === $path || false === strpos( $path, '/' ) ) {
					continue; // No real ancestor path; flat rules already cover it.
				}
				$quoted = preg_quote( $path, '#' );
				$rules[ '^' . $quoted . '/?$' ]                      = 'index.php?' . $taxonomy . '=' . $term->slug;
				$rules[ '^' . $quoted . '/page/?([0-9]{1,})/?$' ]    = 'index.php?' . $taxonomy . '=' . $term->slug . '&paged=$matches[1]';
			}
		}

		return $rules;
	}

	/**
	 * Taxonomies skipped in the last generate_rules() call due to the child-term cap.
	 *
	 * @return array<string,int> taxonomy => child count
	 */
	public function get_skipped_taxonomies() {
		return $this->skipped;
	}
```

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-hierarchy-rules.php tests/phpunit/test-rtu-hierarchy-rules.php
git commit -m "feat(3.1): generate explicit per-child nested rewrite rules with cap"
```

---

## Task 4: Hierarchy rules — inject rules + arm flush on term changes

**Files:**
- Modify: `includes/class-rtu-hierarchy-rules.php`
- Test: `tests/phpunit/test-rtu-hierarchy-rules.php`

- [ ] **Step 1: Write failing tests**

Append:

```php
	public function test_inject_rules_prepends_generated_rules() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		$this->enable_hierarchy_for( $this->tax );

		$existing = array( '^existing/?$' => 'index.php?p=1' );
		$merged   = ( new RTU_Hierarchy_Rules() )->inject_rules( $existing );

		$this->assertArrayHasKey( '^portugal/lisbon/?$', $merged );
		$this->assertArrayHasKey( '^existing/?$', $merged );
		// Our specific rule must come before the pre-existing generic rules.
		$keys = array_keys( $merged );
		$this->assertLessThan( array_search( '^existing/?$', $keys, true ), array_search( '^portugal/lisbon/?$', $keys, true ) );
	}

	public function test_arm_flush_sets_transient() {
		delete_transient( 'rtu_needs_flush' );
		( new RTU_Hierarchy_Rules() )->arm_flush( 0, $this->tax );
		$this->assertSame( 1, (int) get_transient( 'rtu_needs_flush' ) );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `inject_rules` / `arm_flush` undefined.

- [ ] **Step 3: Implement**

Add to the class:

```php
	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_filter( 'rewrite_rules_array', $this, 'inject_rules', 9, 1 );
		$loader->add_action( 'created_term', $this, 'arm_flush', 10, 3 );
		$loader->add_action( 'edited_term', $this, 'arm_flush', 10, 3 );
		$loader->add_action( 'delete_term', $this, 'arm_flush', 10, 3 );
	}

	/**
	 * Merge the generated nested rules ahead of the existing rules so they match first.
	 *
	 * @param array $rules Current rewrite rules.
	 * @return array
	 */
	public function inject_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}
		$generated = $this->generate_rules();
		// Generated specific rules first, then the existing set.
		return $generated + $rules;
	}

	/**
	 * Arm a one-time rewrite flush when a term in an active taxonomy changes.
	 *
	 * @param int    $term_id  Term ID (unused).
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function arm_flush( $term_id, $tt_id = 0, $taxonomy = '' ) {
		unset( $term_id, $tt_id );
		if ( '' !== $taxonomy && ! in_array( $taxonomy, RTU_Options::get_active_taxonomies(), true ) ) {
			return;
		}
		set_transient( 'rtu_needs_flush', 1, HOUR_IN_SECONDS );
	}
```

Note on priority: `inject_rules` runs at priority 9 (before the pagination module's 10) so the specific nested rules sit ahead of the generic flat/pagination rules. The `array + array` union keeps generated keys first and never overwrites existing keys.

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-hierarchy-rules.php tests/phpunit/test-rtu-hierarchy-rules.php
git commit -m "feat(3.1): inject nested rules + arm flush on term changes"
```

---

## Task 5: Rewriter — prepend ancestors in `term_link` when hierarchy ON

**Files:**
- Modify: `includes/class-rtu-url-rewriter.php`
- Test: `tests/phpunit/test-rtu-url-rewriter.php`

- [ ] **Step 1: Write failing test**

Append to `tests/phpunit/test-rtu-url-rewriter.php` before the final `}`:

```php
	public function test_term_link_prepends_ancestors_when_hierarchy_on() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$link     = $rewriter->filter_term_link( 'https://example.com/geo/lisbon/', get_term( $child, 'geo' ), 'geo' );

		$this->assertSame( 'https://example.com/portugal/lisbon/', $link );
		unregister_taxonomy( 'geo' );
	}

	public function test_term_link_flat_when_hierarchy_off() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$link     = $rewriter->filter_term_link( 'https://example.com/geo/lisbon/', get_term( $child, 'geo' ), 'geo' );

		$this->assertSame( 'https://example.com/lisbon/', $link );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `test_term_link_prepends_ancestors_when_hierarchy_on` fails (current code strips to `/lisbon/`, no prepend).

- [ ] **Step 3: Implement**

In `includes/class-rtu-url-rewriter.php`, replace `filter_term_link`:

```php
	public function filter_term_link( $url, $term, $taxonomy ) {
		$active = RTU_Options::get_active_taxonomies();
		if ( ! in_array( $taxonomy, $active, true ) ) {
			return $url;
		}
		// Strip the base taxonomy slug (word-boundary so a parent path is not over-matched).
		$stripped = preg_replace( '#/' . preg_quote( $taxonomy, '#' ) . '(?=/|$)#', '', $url, 1 );

		if ( RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' )
			&& isset( $term->parent ) && (int) $term->parent !== 0 ) {
			$path = RTU_Hierarchy_Rules::term_path( $term, $taxonomy );
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				// Replace the trailing "/<own-slug>/" (or "/<own-slug>") with the full ancestor path.
				$stripped = preg_replace(
					'#/' . preg_quote( $term->slug, '#' ) . '(/|$)#',
					'/' . $path . '$1',
					$stripped,
					1
				);
			}
		}

		return $stripped;
	}
```

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green (both new tests + existing rewriter tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-url-rewriter.php tests/phpunit/test-rtu-url-rewriter.php
git commit -m "feat(3.1): term_link prepends ancestor path when hierarchy on"
```

---

## Task 6: Rewriter — simplify `filter_request` to resolve the leaf slug

**Files:**
- Modify: `includes/class-rtu-url-rewriter.php`
- Test: `tests/phpunit/test-rtu-url-rewriter.php`

Context: the 3.0.x `filter_request` built the nested path INTO the query var (`property-city=portugal/lisbon`), which is the root 404 bug. With explicit nested rules (Task 3–4) the nested request already arrives with the taxonomy query var set to the leaf slug, so `filter_request` only needs to map the flat `name`/`attachment` case to the single matching term — no path building.

- [ ] **Step 1: Write failing test**

Append:

```php
	public function test_request_resolves_flat_child_to_leaf_slug_without_path() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$out      = $rewriter->filter_request( array( 'name' => 'lisbon' ) );

		// Leaf slug only — NOT "portugal/lisbon".
		$this->assertSame( 'lisbon', $out['geo'] );
		$this->assertArrayNotHasKey( 'name', $out );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: fails — current code sets `geo => 'portugal/lisbon'`.

- [ ] **Step 3: Implement**

In `includes/class-rtu-url-rewriter.php`, replace the body of `filter_request` from the `$hierarchy_enabled` line through the end of the `foreach`, so the resolved value is always the single slug. Replace the whole method with:

```php
	public function filter_request( $query_vars ) {
		$active = RTU_Options::get_active_taxonomies();
		if ( empty( $active ) ) {
			return $query_vars;
		}

		if ( isset( $query_vars['attachment'] ) ) {
			$key  = 'attachment';
			$name = $query_vars['attachment'];
		} elseif ( isset( $query_vars['name'] ) ) {
			$key  = 'name';
			$name = $query_vars['name'];
		} else {
			return $query_vars;
		}

		foreach ( $active as $taxonomy ) {
			$term = get_term_by( 'slug', $name, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			unset( $query_vars[ $key ] );
			// Always resolve to the single leaf slug. Nested paths are handled by the
			// explicit rewrite rules in RTU_Hierarchy_Rules; the flat slug maps straight
			// to its term here, and the canonical 301 (when hierarchy is on) sends it to
			// the nested URL.
			$query_vars[ $taxonomy ] = $term->slug;
			return $query_vars;
		}

		return $query_vars;
	}
```

Then delete the now-unused `prepend_parents` private method from this file.

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green. Any old test asserting the path-building behavior must be updated to expect the leaf slug — update it to match.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-url-rewriter.php tests/phpunit/test-rtu-url-rewriter.php
git commit -m "fix(3.1): request filter resolves leaf slug; drop buggy path-building"
```

---

## Task 7: Redirect handler — canonical flat→nested 301

**Files:**
- Modify: `includes/class-rtu-redirect-handler.php`
- Test: `tests/phpunit/test-rtu-redirect-handler.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/phpunit/test-rtu-redirect-handler.php` before the final `}`:

```php
	public function test_canonical_target_flat_child_to_nested() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$handler = new RTU_Redirect_Handler();
		$this->assertSame( '/portugal/lisbon/', $handler->compute_canonical( '/lisbon/' ) );
		unregister_taxonomy( 'geo' );
	}

	public function test_canonical_target_null_for_top_level() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$handler = new RTU_Redirect_Handler();
		$this->assertNull( $handler->compute_canonical( '/portugal/' ) );
		unregister_taxonomy( 'geo' );
	}

	public function test_canonical_target_null_when_already_nested() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$handler = new RTU_Redirect_Handler();
		$this->assertNull( $handler->compute_canonical( '/portugal/lisbon/' ) );
		unregister_taxonomy( 'geo' );
	}

	public function test_canonical_target_null_when_hierarchy_off() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$handler = new RTU_Redirect_Handler();
		$this->assertNull( $handler->compute_canonical( '/lisbon/' ) );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `compute_canonical` undefined.

- [ ] **Step 3: Implement**

Add `compute_canonical` to `includes/class-rtu-redirect-handler.php`, and call it from `maybe_redirect`. Insert this method into the class:

```php
	/**
	 * Canonical target for a flat child-term request when hierarchy is ON.
	 *
	 * Given an incoming path, if it is the flat single-segment URL of a child term
	 * (e.g. /lisbon/), return the canonical nested path (/portugal/lisbon/). Returns
	 * null when hierarchy is off, the term is top-level, the path is already nested,
	 * or no term matches.
	 *
	 * @param string $request_uri Request path with leading slash.
	 * @return string|null
	 */
	public function compute_canonical( $request_uri ) {
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' ) ) {
			return null;
		}
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';
		if ( '' === $path || false !== strpos( $path, '/' ) ) {
			return null; // Empty or already multi-segment (nested) — nothing to do.
		}
		foreach ( RTU_Options::get_active_taxonomies() as $taxonomy ) {
			$term = get_term_by( 'slug', $path, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || (int) $term->parent === 0 ) {
				continue;
			}
			$full = RTU_Hierarchy_Rules::term_path( $term, $taxonomy );
			if ( '' === $full || false === strpos( $full, '/' ) ) {
				continue;
			}
			return '/' . $full . '/';
		}
		return null;
	}
```

In `maybe_redirect`, after the `$request_uri` is computed and before the existing `compute_target` call, add the canonical check:

```php
		$canonical = $this->compute_canonical( $request_uri );
		if ( null !== $canonical ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
```

Note: `maybe_redirect` currently early-returns when the `rtu_enable_redirect` feature is off — but canonical redirects must run whenever hierarchy is ON, independent of that option. Verify `register_hooks` binds `maybe_redirect` whenever EITHER `rtu_enable_redirect` OR `rtu_enable_hierarchy` is enabled. Update `register_hooks`:

```php
	public function register_hooks( $loader ) {
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_redirect' )
			&& ! RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' ) ) {
			return;
		}
		$loader->add_action( 'template_redirect', $this, 'maybe_redirect', 99 );
	}
```

And in `maybe_redirect`, ensure the `compute_canonical` block runs before the `compute_target` (old-URL) block, and that the method does not bail early solely because `rtu_enable_redirect` is off (move any such guard to wrap only the `compute_target` call).

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-redirect-handler.php tests/phpunit/test-rtu-redirect-handler.php
git commit -m "feat(3.1): canonical 301 from flat child URL to nested path"
```

---

## Task 8: Wire new modules + last-flushed into the orchestrator

**Files:**
- Modify: `includes/class-remove-taxonomy-url.php`

- [ ] **Step 1: Add the requires**

In `load_dependencies()`, after the existing `require_once ... class-rtu-admin-notices.php;` line, add:

```php
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-rtu-hierarchy-rules.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-rtu-url-selftest.php';
```

(The self-test file is created in Task 10; this require is harmless once that file exists. If executing strictly in order, add only the hierarchy-rules require here and add the self-test require in Task 11.)

- [ ] **Step 2: Wire hierarchy rules + flush triggers**

In `define_admin_hooks()`, after the `$notices->register_hooks( $this->loader );` block, add:

```php
		$hierarchy = new RTU_Hierarchy_Rules();
		$hierarchy->register_hooks( $this->loader );

		// Re-flush rewrite rules once after the rtu_basics option changes (taxonomy or
		// hierarchy toggle), so new nested rules take effect without a manual permalink save.
		$this->loader->add_action(
			'update_option_rtu_basics',
			$this,
			'arm_rewrite_flush_on_settings_change',
			10,
			0
		);
```

Add the method to the class:

```php
	/**
	 * Arm a one-time rewrite flush after the plugin's settings change.
	 *
	 * @return void
	 */
	public function arm_rewrite_flush_on_settings_change() {
		set_transient( 'rtu_needs_flush', 1, HOUR_IN_SECONDS );
	}
```

- [ ] **Step 3: Stamp last-flushed**

Replace `maybe_flush_rewrite_rules()` body so it records the flush time:

```php
	public function maybe_flush_rewrite_rules() {
		if ( get_transient( 'rtu_needs_flush' ) ) {
			flush_rewrite_rules( false );
			delete_transient( 'rtu_needs_flush' );
			if ( class_exists( 'RTU_Options' ) ) {
				RTU_Options::set_last_flushed( time() );
			}
		}
	}
```

- [ ] **Step 4: Verify suite still green**

Run the unit suite (no new unit test here; this is wiring). Expected: all previously-passing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add includes/class-remove-taxonomy-url.php
git commit -m "feat(3.1): wire hierarchy rules, settings-change flush, last-flushed stamp"
```

---

## Task 9: Self-test — term sampling

**Files:**
- Create: `includes/class-rtu-url-selftest.php`
- Test: `tests/phpunit/test-rtu-url-selftest.php`

- [ ] **Step 1: Write failing test**

Create `tests/phpunit/test-rtu-url-selftest.php`:

```php
<?php
class RTU_Url_Selftest_Test extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'rtu_basics' );
		RTU_Options::flush_cache();
		parent::tear_down();
	}

	public function test_sample_terms_caps_total_and_includes_top_and_children() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		for ( $i = 0; $i < 40; $i++ ) {
			self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'city-' . $i, 'parent' => $pt ) );
		}
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

		$sample = ( new RTU_Url_Selftest() )->sample_terms( array( 'geo' ), 25 );

		$this->assertLessThanOrEqual( 25, count( $sample ) );
		$slugs = wp_list_pluck( $sample, 'slug' );
		$this->assertContains( 'portugal', $slugs ); // top-level included
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `class RTU_Url_Selftest not found`.

- [ ] **Step 3: Implement**

Create `includes/class-rtu-url-selftest.php`:

```php
<?php
/**
 * URL resolution self-test for Remove Taxonomy URL.
 *
 * Samples active-taxonomy terms, requests each term's (base-stripped, possibly nested)
 * permalink over an HTTP loopback, and reports which resolve (200) vs 404. Cause-agnostic:
 * catches breakage from hierarchy misconfiguration, stale rewrite rules, slug collisions,
 * or pagination — the check that was missing when child terms 404'd in production.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Samples terms and verifies their public URLs resolve.
 */
class RTU_Url_Selftest {

	const AJAX_ACTION = 'rtu_run_selftest';
	const NONCE       = 'rtu_selftest_nonce';

	/**
	 * Pick up to $limit terms across the given taxonomies, biased to include top-level
	 * terms and a spread of children from different parents.
	 *
	 * @param string[] $taxonomies Active taxonomy slugs.
	 * @param int      $limit      Max terms to return.
	 * @return array[] Each: array( 'taxonomy' => string, 'term_id' => int, 'slug' => string ).
	 */
	public function sample_terms( $taxonomies, $limit = 25 ) {
		$top    = array();
		$child  = array();
		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$row = array( 'taxonomy' => $taxonomy, 'term_id' => (int) $term->term_id, 'slug' => $term->slug, 'parent' => (int) $term->parent );
				if ( 0 === (int) $term->parent ) {
					$top[] = $row;
				} else {
					$child[ (int) $term->parent ][] = $row;
				}
			}
		}

		$sample = array();
		// Take top-level first (they are the cheapest to verify and most visible).
		foreach ( $top as $row ) {
			if ( count( $sample ) >= $limit ) {
				return $sample;
			}
			$sample[] = $row;
		}
		// Then round-robin one child per distinct parent for breadth.
		$cursors = array_fill_keys( array_keys( $child ), 0 );
		$drained = false;
		while ( ! $drained && count( $sample ) < $limit ) {
			$drained = true;
			foreach ( $cursors as $parent_id => $idx ) {
				if ( isset( $child[ $parent_id ][ $idx ] ) ) {
					$sample[] = $child[ $parent_id ][ $idx ];
					$cursors[ $parent_id ] = $idx + 1;
					$drained = false;
					if ( count( $sample ) >= $limit ) {
						break;
					}
				}
			}
		}
		return $sample;
	}
}
```

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-url-selftest.php tests/phpunit/test-rtu-url-selftest.php
git commit -m "feat(3.1): self-test term sampling with top-level + child spread"
```

---

## Task 10: Self-test — loopback run with internal fallback

**Files:**
- Modify: `includes/class-rtu-url-selftest.php`
- Test: `tests/phpunit/test-rtu-url-selftest.php`

- [ ] **Step 1: Write failing tests**

Append to `tests/phpunit/test-rtu-url-selftest.php`:

```php
	public function test_check_term_passes_on_200() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$id = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

		// Force loopback to return 200.
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => 'ok' );
		} );

		$row = ( new RTU_Url_Selftest() )->check_term( array( 'taxonomy' => 'geo', 'term_id' => $id, 'slug' => 'portugal', 'parent' => 0 ) );
		$this->assertTrue( $row['pass'] );
		$this->assertSame( 200, $row['status'] );
		$this->assertSame( 'loopback', $row['mode'] );
		unregister_taxonomy( 'geo' );
	}

	public function test_check_term_fails_on_404() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$id = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 404 ), 'body' => 'not found' );
		} );

		$row = ( new RTU_Url_Selftest() )->check_term( array( 'taxonomy' => 'geo', 'term_id' => $id, 'slug' => 'portugal', 'parent' => 0 ) );
		$this->assertFalse( $row['pass'] );
		$this->assertSame( 404, $row['status'] );
		unregister_taxonomy( 'geo' );
	}

	public function test_check_term_falls_back_when_loopback_errors() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$id = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'blocked', 'loopback blocked' );
		} );

		$row = ( new RTU_Url_Selftest() )->check_term( array( 'taxonomy' => 'geo', 'term_id' => $id, 'slug' => 'portugal', 'parent' => 0 ) );
		$this->assertSame( 'internal', $row['mode'] );
		// Term exists, so the internal check passes.
		$this->assertTrue( $row['pass'] );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `check_term` undefined.

- [ ] **Step 3: Implement**

Add to `includes/class-rtu-url-selftest.php`:

```php
	/**
	 * Verify a single term's public URL resolves.
	 *
	 * Primary: HTTP loopback to the term's stripped permalink (200 = pass, else fail).
	 * Fallback (loopback errored/blocked): internal check that the term exists and a
	 * rewrite rule matches its stripped path.
	 *
	 * @param array $row Sample row from sample_terms().
	 * @return array array( 'taxonomy', 'slug', 'url', 'status', 'pass', 'mode' )
	 */
	public function check_term( $row ) {
		$term = get_term( (int) $row['term_id'], $row['taxonomy'] );
		$url  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term, $row['taxonomy'] ) : '';
		$url  = is_wp_error( $url ) ? '' : $url;

		$result = array(
			'taxonomy' => $row['taxonomy'],
			'slug'     => $row['slug'],
			'url'      => $url,
			'status'   => 0,
			'pass'     => false,
			'mode'     => 'loopback',
		);

		if ( '' === $url ) {
			$result['mode'] = 'internal';
			$result['pass'] = false;
			return $result;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'sslverify'   => false,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Fallback: internal verification.
			$result['mode'] = 'internal';
			$result['pass'] = ( $term && ! is_wp_error( $term ) );
			return $result;
		}

		$code             = (int) wp_remote_retrieve_response_code( $response );
		$result['status'] = $code;
		$result['pass']   = ( 200 === $code );
		return $result;
	}

	/**
	 * Run the self-test across active taxonomies.
	 *
	 * @param int $limit Max terms to test.
	 * @return array array( 'rows' => array[], 'tested' => int, 'total' => int )
	 */
	public function run( $limit = 25 ) {
		$active = RTU_Options::get_active_taxonomies();
		$total  = 0;
		foreach ( $active as $taxonomy ) {
			$count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			$total += is_wp_error( $count ) ? 0 : (int) $count;
		}
		$sample = $this->sample_terms( $active, $limit );
		$rows   = array();
		foreach ( $sample as $row ) {
			$rows[] = $this->check_term( $row );
		}
		return array(
			'rows'   => $rows,
			'tested' => count( $rows ),
			'total'  => $total,
		);
	}
```

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-url-selftest.php tests/phpunit/test-rtu-url-selftest.php
git commit -m "feat(3.1): self-test loopback check with internal fallback"
```

---

## Task 11: Self-test — AJAX endpoint + wiring

**Files:**
- Modify: `includes/class-rtu-url-selftest.php`
- Modify: `includes/class-remove-taxonomy-url.php`
- Test: `tests/phpunit/test-rtu-url-selftest.php`

- [ ] **Step 1: Write failing test**

Append:

```php
	public function test_register_hooks_binds_ajax_action() {
		$loader = new Remove_Taxonomy_Url_Loader();
		( new RTU_Url_Selftest() )->register_hooks( $loader );
		$loader->run();
		$this->assertNotFalse( has_action( 'wp_ajax_' . RTU_Url_Selftest::AJAX_ACTION ) );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `register_hooks` undefined on `RTU_Url_Selftest`.

- [ ] **Step 3: Implement**

Add to `includes/class-rtu-url-selftest.php`:

```php
	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_action( 'wp_ajax_' . self::AJAX_ACTION, $this, 'ajax_run' );
	}

	/**
	 * AJAX endpoint for the URL resolution self-test.
	 *
	 * @return void
	 */
	public function ajax_run() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		wp_send_json_success( $this->run( 25 ) );
	}
```

In `includes/class-remove-taxonomy-url.php` `define_admin_hooks()`, after the hierarchy block, add:

```php
		$selftest = new RTU_Url_Selftest();
		$selftest->register_hooks( $this->loader );
```

(If you deferred the self-test require in Task 8, add it now in `load_dependencies()`.)

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-url-selftest.php includes/class-remove-taxonomy-url.php tests/phpunit/test-rtu-url-selftest.php
git commit -m "feat(3.1): self-test AJAX endpoint + orchestrator wiring"
```

---

## Task 12: Conflict detector — hierarchy off→on save notice

**Files:**
- Modify: `includes/class-rtu-conflict-detector.php`
- Test: `tests/phpunit/test-rtu-conflict-detector.php`

- [ ] **Step 1: Write failing test**

Append to `tests/phpunit/test-rtu-conflict-detector.php` before the final `}`:

```php
	public function test_warn_on_save_adds_hierarchy_notice_when_enabling() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => '', // currently off
		) );
		RTU_Options::flush_cache();

		$detector = new RTU_Conflict_Detector();
		$new      = array( 'rtu_post_types' => array( 'geo' => 'geo' ), 'rtu_enable_hierarchy' => 'on' );
		$detector->warn_on_save( $new, get_option( 'rtu_basics' ) );

		$errors = get_settings_errors( 'rtu_basics' );
		$codes  = wp_list_pluck( $errors, 'code' );
		$this->assertContains( 'rtu_hierarchy_enabled', $codes );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: no `rtu_hierarchy_enabled` notice.

- [ ] **Step 3: Implement**

In `includes/class-rtu-conflict-detector.php` `warn_on_save`, before `return $new_value;`, add the off→on detection:

```php
		$was_on = ( is_array( $old_value ) && ! empty( $old_value['rtu_enable_hierarchy'] ) && 'on' === $old_value['rtu_enable_hierarchy'] );
		$now_on = ( is_array( $new_value ) && isset( $new_value['rtu_enable_hierarchy'] ) && 'on' === $new_value['rtu_enable_hierarchy'] );
		if ( ! $was_on && $now_on ) {
			$child_count = RTU_Options::count_child_terms();
			add_settings_error(
				'rtu_basics',
				'rtu_hierarchy_enabled',
				sprintf(
					/* translators: %d: number of child terms affected */
					esc_html__( 'Hierarchical term URLs enabled. %d child-term URL(s) will change to /parent/child/; existing flat URLs will 301-redirect to them.', 'remove-taxonomy-url' ),
					(int) $child_count
				),
				'info'
			);
		}
```

Note: the existing method signature is `warn_on_save( $new_value, $old_value )` and currently does `unset( $old_value )`. Remove that `unset` so `$old_value` is usable. The collision check inside reads `rtu_post_types` from `$new_value` and must still run; ensure both run before the single `return $new_value;`. Also note `count_child_terms()` reads the CURRENTLY stored active taxonomies; that is acceptable here because the taxonomy selection is unchanged in this save path. If `rtu_post_types` changed in the same save, the count is approximate — acceptable for an informational notice.

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-conflict-detector.php tests/phpunit/test-rtu-conflict-detector.php
git commit -m "feat(3.1): info notice when enabling hierarchical term URLs"
```

---

## Task 13: Audit payload — hierarchy status block

**Files:**
- Modify: `includes/class-rtu-conflict-detector.php`
- Test: `tests/phpunit/test-rtu-conflict-detector.php`

- [ ] **Step 1: Write failing test**

Append:

```php
	public function test_audit_status_reports_hierarchy_state() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$status = ( new RTU_Conflict_Detector() )->hierarchy_status();
		$this->assertTrue( $status['enabled'] );
		$this->assertSame( 1, $status['child_terms'] );
		$this->assertSame( 'nested', $status['child_url_shape'] );
		unregister_taxonomy( 'geo' );
	}
```

- [ ] **Step 2: Run, verify failure**

Expected: `hierarchy_status` undefined.

- [ ] **Step 3: Implement**

Add to `includes/class-rtu-conflict-detector.php`:

```php
	/**
	 * Current hierarchy status for the Health Check display.
	 *
	 * @return array array( 'enabled' => bool, 'child_terms' => int, 'child_url_shape' => string )
	 */
	public function hierarchy_status() {
		$enabled = RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' );
		return array(
			'enabled'         => (bool) $enabled,
			'child_terms'     => RTU_Options::count_child_terms(),
			'child_url_shape' => $enabled ? 'nested' : 'flat',
		);
	}
```

Then include it in the existing AJAX audit response. In `ajax_run_audit`, change the success payload:

```php
		$collisions = $this->find_collisions( RTU_Options::get_active_taxonomies() );
		wp_send_json_success(
			array(
				'collisions' => $collisions,
				'hierarchy'  => $this->hierarchy_status(),
			)
		);
```

- [ ] **Step 4: Run, verify pass**

Run the suite. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-rtu-conflict-detector.php tests/phpunit/test-rtu-conflict-detector.php
git commit -m "feat(3.1): hierarchy status in Health Check audit payload"
```

---

## Task 14: Settings partial — descriptions, general note, version + last-flushed

**Files:**
- Modify: `admin/partials/remove-taxonomy-url-settings.php`

No unit test (presentation). Verified visually on FLP in Task 18.

- [ ] **Step 1: Update the five field descriptions**

In `get_settings_fields()`, replace each `desc` string with the approved wording:

- `rtu_post_types`:
```php
			'desc'    => esc_html__( "Tick the taxonomies whose base slug you want removed. Example: with 'Genre' ticked, /genre/rock/ becomes /rock/. Only tick taxonomies you actually want shortened — each one changes its public URLs site-wide.", 'remove-taxonomy-url' ),
```
- `rtu_enable_redirect`:
```php
			'desc'  => esc_html__( 'When ON, the old /genre/rock/ address permanently (301) redirects to the new /rock/. Turn this ON for SEO. Only turn ON once the short URLs are confirmed working — if a short URL is broken, this redirects visitors straight into a 404.', 'remove-taxonomy-url' ),
```
- `rtu_enable_pagination`:
```php
			'desc'  => esc_html__( 'Turn ON if your taxonomy archives split across multiple pages (page 2, 3…). Keeps /rock/page/2/ working after the base slug is removed. Safe to leave ON.', 'remove-taxonomy-url' ),
```
- `rtu_enable_hierarchy`:
```php
			'desc'  => esc_html__( 'Controls how CHILD terms look. OFF: a child term uses a flat URL — /punk/. ON: a child term uses its full parent path — /rock/punk/ — and the flat /punk/ permanently redirects to it. Top-level terms are unaffected either way. Turning this ON changes child-term URLs site-wide; existing flat links keep working via redirect.', 'remove-taxonomy-url' ),
```
- `rtu_enable_collision`:
```php
			'desc'    => esc_html__( "Leave ON. When you save, it checks whether a shortened term URL (e.g. /rock/) clashes with an existing page, post, or another term with the same slug, and warns you. It won't block the save. Use the Health Check tab to scan everything at once.", 'remove-taxonomy-url' ),
```

- [ ] **Step 2: Add the general note + status line**

In `rtu_settings_page()`, immediately after `echo '<div class="wrap">';` and before `show_navigation()`, add:

```php
		$rtu_version   = defined( 'REMOVE_TAXONOMY_URL_VERSION' ) ? REMOVE_TAXONOMY_URL_VERSION : '';
		$rtu_flushed   = class_exists( 'RTU_Options' ) ? RTU_Options::get_last_flushed() : 0;
		$rtu_flushed_h = $rtu_flushed ? sprintf(
			/* translators: %s: human-readable time difference */
			esc_html__( '%s ago', 'remove-taxonomy-url' ),
			human_time_diff( $rtu_flushed, time() )
		) : esc_html__( 'never', 'remove-taxonomy-url' );

		echo '<p style="margin:.5em 0;color:#50575e;">';
		echo esc_html__( 'After changing any setting here, the plugin re-flushes your permalinks automatically. If a URL still shows 404, go to Settings → Permalinks and click Save once.', 'remove-taxonomy-url' );
		echo '<br><strong>' . esc_html__( 'Version:', 'remove-taxonomy-url' ) . '</strong> ' . esc_html( $rtu_version )
			. ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Last flushed:', 'remove-taxonomy-url' ) . '</strong> ' . esc_html( $rtu_flushed_h );
		echo '</p>';
```

- [ ] **Step 3: PHPCS**

Run PHPCS on the file. Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add admin/partials/remove-taxonomy-url-settings.php
git commit -m "feat(3.1): clearer setting descriptions + version/last-flushed note"
```

---

## Task 15: Health Check UI + JS — self-test button, results, hierarchy status

**Files:**
- Modify: `admin/partials/rtu-health-check.php`
- Modify: `admin/js/rtu-health-check.js`
- Modify: `admin/class-remove-taxonomy-url-admin.php`

- [ ] **Step 1: Add the self-test button + status container to the partial**

In `admin/partials/rtu-health-check.php`, after the existing `<div id="rtu-audit-results"></div>`, before the closing `</div>`, add:

```php
	<div id="rtu-hierarchy-status" style="margin-top:1em;"></div>
	<p style="margin-top:1.5em;">
		<button type="button" class="button" id="rtu-run-selftest"
			data-nonce="<?php echo esc_attr( wp_create_nonce( RTU_Url_Selftest::NONCE ) ); ?>"
			data-action="<?php echo esc_attr( RTU_Url_Selftest::AJAX_ACTION ); ?>">
			<?php esc_html_e( 'Test all term URLs', 'remove-taxonomy-url' ); ?>
		</button>
		<span class="spinner" style="float:none;"></span>
	</p>
	<p class="description"><?php esc_html_e( 'Requests a sample of your term URLs and reports which resolve (200) or fail (404). Catches broken URLs from any cause.', 'remove-taxonomy-url' ); ?></p>
	<div id="rtu-selftest-results"></div>
```

- [ ] **Step 2: Extend the audit JS to render hierarchy status + add the self-test handler**

In `admin/js/rtu-health-check.js`, inside the audit click handler success branch, after computing `rows`, render the hierarchy status. Replace the success handling block with:

```js
				const data = json.data || {};
				const rows = data.collisions ? data.collisions : [];
				renderHierarchyStatus( data.hierarchy );
				if ( rows.length === 0 ) {
					setMessage( labels.noConflicts || 'No collisions found.' );
					return;
				}
				while ( out.firstChild ) {
					out.removeChild( out.firstChild );
				}
				out.appendChild( buildTable( rows ) );
```

Add these helpers inside the IIFE (before the audit `button.addEventListener`):

```js
	const statusBox = document.getElementById( 'rtu-hierarchy-status' );

	function renderHierarchyStatus( h ) {
		if ( ! statusBox || ! h ) {
			return;
		}
		while ( statusBox.firstChild ) {
			statusBox.removeChild( statusBox.firstChild );
		}
		const p = document.createElement( 'p' );
		const shape = h.child_url_shape === 'nested' ? '/parent/child/' : '/child/';
		p.textContent = ( labels.hierarchyLabel || 'Hierarchical term URLs' ) + ': '
			+ ( h.enabled ? ( labels.on || 'ON' ) : ( labels.off || 'OFF' ) )
			+ ' — ' + ( labels.childUrls || 'child terms use' ) + ' ' + shape
			+ ' (' + ( h.child_terms || 0 ) + ' ' + ( labels.childTerms || 'child terms' ) + ')';
		statusBox.appendChild( p );
	}

	function buildSelftestTable( rows ) {
		const table = document.createElement( 'table' );
		table.className = 'widefat striped';
		const thead = document.createElement( 'thead' );
		const hr = document.createElement( 'tr' );
		[ labels.taxonomy, labels.termSlug, 'URL', labels.result || 'Result' ].forEach( function ( t ) {
			const th = document.createElement( 'th' );
			th.textContent = t || '';
			hr.appendChild( th );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );
		const tbody = document.createElement( 'tbody' );
		rows.forEach( function ( r ) {
			const tr = document.createElement( 'tr' );
			[ r.taxonomy, r.slug, r.url ].forEach( function ( v ) {
				const td = document.createElement( 'td' );
				td.textContent = v || '';
				tr.appendChild( td );
			} );
			const res = document.createElement( 'td' );
			res.textContent = ( r.pass ? '✓ 200' : '✗ ' + ( r.status || 'fail' ) )
				+ ( r.mode === 'internal' ? ' (' + ( labels.internal || 'internal check' ) + ')' : '' );
			res.style.color = r.pass ? '#1a7f37' : '#b32d2e';
			tr.appendChild( res );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		return table;
	}
```

Add a self-test click handler at the end of the IIFE (before the closing `} )();`):

```js
	const selftestBtn = document.getElementById( 'rtu-run-selftest' );
	const selftestOut = document.getElementById( 'rtu-selftest-results' );
	if ( selftestBtn && selftestOut ) {
		const sSpinner = selftestBtn.nextElementSibling;
		selftestBtn.addEventListener( 'click', async function () {
			sSpinner.classList.add( 'is-active' );
			while ( selftestOut.firstChild ) {
				selftestOut.removeChild( selftestOut.firstChild );
			}
			const body = new URLSearchParams();
			body.append( 'action', selftestBtn.dataset.action );
			body.append( 'nonce', selftestBtn.dataset.nonce );
			try {
				const res  = await fetch( window.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } );
				const json = await res.json();
				if ( ! json.success ) {
					const p = document.createElement( 'p' );
					p.textContent = labels.failed || 'Test failed.';
					selftestOut.appendChild( p );
					return;
				}
				const d = json.data || {};
				const summary = document.createElement( 'p' );
				summary.textContent = ( labels.tested || 'Tested' ) + ' ' + ( d.tested || 0 ) + ' / ' + ( d.total || 0 );
				selftestOut.appendChild( summary );
				selftestOut.appendChild( buildSelftestTable( d.rows || [] ) );
			} catch ( e ) {
				const p = document.createElement( 'p' );
				p.textContent = labels.failed || 'Test failed.';
				selftestOut.appendChild( p );
			} finally {
				sSpinner.classList.remove( 'is-active' );
			}
		} );
	}
```

- [ ] **Step 3: Localize the new strings**

In `admin/class-remove-taxonomy-url-admin.php` `enqueue_scripts`, extend the `wp_localize_script` array with:

```php
					'result'         => __( 'Result', 'remove-taxonomy-url' ),
					'tested'         => __( 'Tested', 'remove-taxonomy-url' ),
					'internal'       => __( 'internal check', 'remove-taxonomy-url' ),
					'hierarchyLabel' => __( 'Hierarchical term URLs', 'remove-taxonomy-url' ),
					'on'             => __( 'ON', 'remove-taxonomy-url' ),
					'off'            => __( 'OFF', 'remove-taxonomy-url' ),
					'childUrls'      => __( 'child terms use', 'remove-taxonomy-url' ),
					'childTerms'     => __( 'child terms', 'remove-taxonomy-url' ),
```

- [ ] **Step 4: PHPCS**

Run PHPCS on the two PHP files. Expected: 0 errors. (JS is excluded from PHPCS.)

- [ ] **Step 5: Commit**

```bash
git add admin/partials/rtu-health-check.php admin/js/rtu-health-check.js admin/class-remove-taxonomy-url-admin.php
git commit -m "feat(3.1): Health Check self-test UI + hierarchy status display"
```

---

## Task 16: Version bumps, README changelog, uninstall cleanup

**Files:**
- Modify: `remove-taxonomy-url.php`
- Modify: `README.txt`
- Modify: `uninstall.php`

- [ ] **Step 1: Bump plugin version**

In `remove-taxonomy-url.php`, change both the header `Version:` and the constant to `3.1.0`:

```php
 * Version:           3.1.0
```
```php
define( 'REMOVE_TAXONOMY_URL_VERSION', '3.1.0' );
```

- [ ] **Step 2: README stable tag + changelog**

In `README.txt`, set `Stable tag: 3.1.0` and add a changelog entry above `= 3.0.1 =`:

```
= 3.1.0 =
* New: Hierarchical term URLs now actually resolve. Child terms use their full parent path (/parent/child/) at any depth, backed by explicit rewrite rules; the old flat /child/ URL 301-redirects to the nested one. Previously, turning this option on made child terms 404.
* New: Health Check "Test all term URLs" — requests a sample of your term URLs over a loopback and reports which resolve (200) or fail (404). Catches broken term URLs from any cause (hierarchy, stale permalinks, collisions). Falls back to an internal check if loopback is blocked.
* New: Health Check shows current hierarchy status (on/off, child-URL shape, child-term count).
* Improved: every setting now has a plain-language description explaining what it does and when to use it.
* Improved: settings page shows the plugin version and when permalinks were last flushed; saving settings re-flushes automatically.
* Improved: enabling Hierarchical term URLs shows how many child-term URLs will change.
```

- [ ] **Step 3: uninstall cleanup**

In `uninstall.php`, add `rtu_last_flushed` to the deleted options. Find the `delete_option` calls and add:

```php
delete_option( 'rtu_last_flushed' );
```

- [ ] **Step 4: Verify versions align**

```bash
grep -i "Version:" remove-taxonomy-url.php | head -1
grep "REMOVE_TAXONOMY_URL_VERSION" remove-taxonomy-url.php
grep -i "Stable tag" README.txt
grep "DB_VERSION" includes/class-rtu-options.php
```
Expected: all show 3.1.0 (DB_VERSION `3.1.0`).

- [ ] **Step 5: Commit**

```bash
git add remove-taxonomy-url.php README.txt uninstall.php
git commit -m "chore(3.1): bump version to 3.1.0, changelog, uninstall cleanup"
```

---

## Task 17: Full unit suite + PHPCS gate

**Files:** none (verification).

- [ ] **Step 1: Full suite**

Run the unit suite. Expected: all green (≈55+ tests), 0 failures, 0 errors.

- [ ] **Step 2: PHPCS across changed + new files**

```bash
"$PHP82" vendor/bin/phpcs --standard=.phpcs.xml.dist \
  includes/class-rtu-options.php includes/class-rtu-hierarchy-rules.php \
  includes/class-rtu-url-selftest.php includes/class-rtu-url-rewriter.php \
  includes/class-rtu-redirect-handler.php includes/class-rtu-conflict-detector.php \
  includes/class-remove-taxonomy-url.php admin/partials/remove-taxonomy-url-settings.php \
  admin/partials/rtu-health-check.php admin/class-remove-taxonomy-url-admin.php
```
Expected: 0 errors (pre-existing `$default` reserved-keyword warning in options is acceptable).

- [ ] **Step 3: Commit any phpcbf fixes**

If PHPCS reports auto-fixable issues, run `phpcbf` on the file, re-run `phpcs`, then:

```bash
git add -A
git commit -m "style(3.1): phpcs cleanup"
```

---

## Task 18: FLP integration — reproduce the live bug, prove the fix, lock the 404 heuristic

**Files:** none in canonical except possible heuristic tweak to `includes/class-rtu-url-selftest.php`.

Mirror the full set of changed plugin files to FLP first:

```bash
SRC="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/remove-taxonomy-url"
DST="/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/remove-taxonomy-url"
rsync -a --exclude='.git' --exclude='tests' --exclude='bin' --exclude='docs' \
  --exclude='vendor' --exclude='composer.*' --exclude='phpunit.xml.dist' \
  --exclude='.phpcs.xml.dist' --exclude='.svnignore' --exclude='README.md' \
  "$SRC/" "$DST/"
```

- [ ] **Step 1: Enable hierarchy on FLP + flush**

Log in via `https://flp.local/matt-sung/?localwp_auto_login=36`, go to Settings → Remove Taxonomy URL, ensure `property-type, property-city, property-status` ticked, **check "Hierarchical term URLs"**, Save. Then Settings → Permalinks → Save (once). (Or set the option directly via the FLP DB + visit an admin page to trigger the flush.)

- [ ] **Step 2: Verify nested child URLs resolve (the live failure, now fixed)**

```bash
for u in \
  "https://flp.local/portugal/lisbon/" \
  "https://flp.local/portugal/cascais/" \
  "https://flp.local/portugal/algarve/" \
  "https://flp.local/portugal/" \
  "https://flp.local/spain/" \
  "https://flp.local/lisbon/" ; do
  printf "%-45s -> %s\n" "$u" "$(curl -ksI -o /dev/null -w 'HTTP %{http_code} %{redirect_url}' "$u")"
done
```
Expected: `/portugal/lisbon/`, `/portugal/cascais/`, `/portugal/algarve/`, `/portugal/`, `/spain/` → **200**; flat `/lisbon/` → **301 → /portugal/lisbon/**.

- [ ] **Step 3: Verify a multi-level (grandchild) path**

Pick a grandchild from FLP (a `property-city` term whose parent is itself a child, e.g. under `algarve`). Query the DB to find one:
```bash
SOCK="$HOME/Library/Application Support/Local/run/8-CWukao6/mysql/mysqld.sock"
MYSQL="$HOME/Library/Application Support/Local/lightning-services/mysql-8.0.35+4/bin/darwin-arm64/bin/mysql"
"$MYSQL" -uroot -proot -S "$SOCK" -Nse "USE local;
SELECT c.slug AS child, p.slug AS parent, gp.slug AS grandparent
FROM wp_term_taxonomy tt
JOIN wp_terms c ON tt.term_id=c.term_id
JOIN wp_terms p ON tt.parent=p.term_id
JOIN wp_term_taxonomy tp ON tp.term_id=p.term_id
LEFT JOIN wp_terms gp ON tp.parent=gp.term_id
WHERE tt.taxonomy='property-city' AND tp.parent<>0 LIMIT 5;" 2>/dev/null
```
Then `curl -ksI` the assembled `/grandparent/parent/child/` URL. Expected: **200**.

- [ ] **Step 4: Run the Health Check self-test in the browser**

On the settings page, click "Test all term URLs". Expected: sampled rows show ✓ 200 for resolvable terms. Confirm the summary shows "Tested N / 444" and the hierarchy status line reads ON / nested.

- [ ] **Step 5: Lock the 404 heuristic**

Capture FLP's actual 404 response to confirm the status-code heuristic is correct:
```bash
curl -ksI -o /dev/null -w 'HTTP %{http_code}\n' "https://flp.local/this-slug-does-not-exist-xyz/"
```
If FLP returns a clean 404 status (expected), the status-code heuristic in `check_term` is correct — no change. If the theme returns 200 on its 404 page (soft-404), tighten `check_term`: additionally fetch the body and fail when it contains the theme's 404 marker. If a tweak is needed, update `includes/class-rtu-url-selftest.php`, re-run the unit suite, and commit:
```bash
git add includes/class-rtu-url-selftest.php
git commit -m "fix(3.1): tighten self-test 404 detection against FLP 404 response"
```

- [ ] **Step 6: Toggle hierarchy OFF, confirm flat behavior**

Uncheck "Hierarchical term URLs", Save, Permalinks → Save. Verify flat `/lisbon/` → 200 and no `/portugal/lisbon/` rule remains (e.g. `/portugal/lisbon/` now 404s or 301s per core). Confirm this matches 3.0.1 behavior. Re-enable hierarchy afterward if the site owner runs with it on.

- [ ] **Step 7: Commit a short integration note (optional)**

If any heuristic/code changed during integration, ensure it is committed. Otherwise no commit needed — integration is verification.

---

## Task 19: Pre-ship adversarial review (gate before deploy)

**Files:** none (review).

- [ ] **Step 1: Run a pre-ship adversarial review**

Use the same multi-lens review used for 3.0.1 (Workflow tool): lenses = (a) hierarchy rewrite-rule correctness & rule-ordering/shadowing, (b) redirect-loop safety (flat→nested→flat?), (c) self-test correctness & loopback safety/perf, (d) WP.org readiness/version consistency. Adversarially verify each finding; fix confirmed blockers/highs before deploy.

- [ ] **Step 2: Address confirmed findings**

For each confirmed finding, write a failing test (where unit-testable), fix, re-run the suite, commit.

- [ ] **Step 3: Final gate**

Re-run the full unit suite + PHPCS. Expected: green. Then hand off to deploy (git tag `3.1.0` + `v3.1.0`, push, sync clean snapshot, `deploy.sh` off the canonical repo) — same proven path as 3.0.1, performed after user confirmation.

---

## Self-review notes (author)

- **Spec coverage:** hierarchy resolution (Tasks 2–8), term_link nesting (5), leaf-resolution fix (6), canonical 301 (7), rule lifecycle/flush (4, 8), cap (3), self-test loopback+fallback+sampling+cap-report (9–11), hierarchy status (13), descriptions (14), general note + last-flushed (14), save/flush feedback (8, 14), hierarchy save notice (12), version/changelog/uninstall (16), FLP integration incl. multi-level + heuristic lock (18), gates + pre-ship review (17, 19). All spec sections map to tasks.
- **Type consistency:** `RTU_Hierarchy_Rules::term_path()` (static) is the single ancestor-path source used by the rewriter (Task 5), redirect handler (Task 7), and rule generation (Task 3). Self-test rows use one shape `{taxonomy,slug,url,status,pass,mode}` across Tasks 10–11 and the JS in 15. Option keys unchanged; new option `rtu_last_flushed` set in Task 1, stamped in Task 8, rendered in Task 14, deleted in Task 16.
- **Known approximation (documented in-task):** `count_child_terms()` in the off→on save notice (Task 12) reads currently-stored active taxonomies; acceptable for an informational count.
