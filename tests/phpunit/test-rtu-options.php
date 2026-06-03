<?php
class RTU_Options_Test extends WP_UnitTestCase {

    public function tearDown(): void {
        delete_option( 'rtu_basics' );
        delete_option( 'rtu_db_version' );
        delete_option( 'rtu_30_notice_dismissed' );
        RTU_Options::flush_cache();
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

    public function test_get_active_taxonomies_filters_to_registered_taxonomies() {
        register_taxonomy( 'genre', 'post' );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre', 'nonexistent_tax' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [ 'genre' ], RTU_Options::get_active_taxonomies() );
        unregister_taxonomy( 'genre' );
    }

    public function test_get_active_taxonomies_reads_keyed_storage_format() {
        // 3.0.1 stores rtu_post_types as a slug=>slug keyed array; get_active_taxonomies
        // must still return a clean sequential list of registered slugs.
        register_taxonomy( 'genre', 'post' );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' => 'genre', 'nonexistent_tax' => 'nonexistent_tax' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [ 'genre' ], RTU_Options::get_active_taxonomies() );
        unregister_taxonomy( 'genre' );
    }

    public function test_get_active_taxonomies_excludes_builtin_taxonomies() {
        // `category` is a built-in taxonomy; it must never be returned even if explicitly selected.
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'category', 'post_tag' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [], RTU_Options::get_active_taxonomies() );
    }

    public function test_is_feature_enabled_returns_bool() {
        update_option( 'rtu_basics', [ 'rtu_enable_redirect' => 'on', 'rtu_enable_pagination' => '' ] );
        RTU_Options::flush_cache();
        $this->assertTrue( RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_missing' ) );
    }

    public function test_sanitize_filters_unknown_taxonomies_and_keys_them() {
        register_taxonomy( 'genre', 'post' );
        $input = [
            'rtu_post_types'        => [ 'genre', 'fake_tax', '<script>' ],
            'rtu_enable_redirect'   => 'on',
            'rtu_enable_pagination' => '',
            'rtu_enable_hierarchy'  => 'on',
            'rtu_enable_collision'  => 0,
        ];
        $clean = RTU_Options::sanitize( $input );
        // Selected taxonomies stored as a keyed slug=>slug map so callback_multicheck's isset() check matches.
        $this->assertSame( [ 'genre' => 'genre' ], $clean['rtu_post_types'] );
        // Feature flags stored as 'on'/'' strings, matching what callback_checkbox renders.
        $this->assertSame( 'on', $clean['rtu_enable_redirect'] );
        $this->assertSame( '', $clean['rtu_enable_pagination'] );
        $this->assertSame( 'on', $clean['rtu_enable_hierarchy'] );
        $this->assertSame( '', $clean['rtu_enable_collision'] );
        $this->assertSame( '3.0.1', $clean['rtu_db_version'] );
        unregister_taxonomy( 'genre' );
    }

    public function test_sanitize_rejects_off_companion_value() {
        // WeDevs checkbox renders a hidden companion <input value="off">. When the box is
        // unchecked the literal string 'off' is posted; sanitize must NOT treat it as enabled.
        $clean = RTU_Options::sanitize( [ 'rtu_enable_redirect' => 'off' ] );
        $this->assertSame( '', $clean['rtu_enable_redirect'] );
    }

    public function test_sanitize_empty_taxonomies_returns_empty_array() {
        $clean = RTU_Options::sanitize( [ 'rtu_post_types' => [] ] );
        $this->assertSame( [], $clean['rtu_post_types'] );
    }

    public function test_sanitize_handles_non_array_input() {
        $clean = RTU_Options::sanitize( 'not-an-array' );
        $this->assertSame( [], $clean['rtu_post_types'] );
        $this->assertSame( '', $clean['rtu_enable_redirect'] );
        $this->assertSame( 'on', $clean['rtu_enable_collision'] ); // defaults ON
    }

    public function test_sanitize_collision_defaults_on_when_key_missing() {
        $clean = RTU_Options::sanitize( [] );
        $this->assertSame( 'on', $clean['rtu_enable_collision'] );
    }

    public function test_maybe_flush_on_update_clears_cache_for_rtu_basics() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'first' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [ 'first' ], RTU_Options::get( 'rtu_post_types' ) );

        // Direct update without flush — without the auto-flush hook the cache would lie.
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'second' ] ] );
        // The updated_option action fires synchronously; if we registered the auto-flush
        // hook in plugin bootstrap, cache should already be invalidated.
        RTU_Options::maybe_flush_on_update( 'rtu_basics' );
        $this->assertSame( [ 'second' ], RTU_Options::get( 'rtu_post_types' ) );
    }

    public function test_maybe_flush_on_update_ignores_other_options() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'x' ] ] );
        RTU_Options::flush_cache();
        RTU_Options::get( 'rtu_post_types' ); // prime cache
        RTU_Options::maybe_flush_on_update( 'siteurl' );
        // Cache should still be primed (we'd see the original value).
        $reflection = new ReflectionClass( 'RTU_Options' );
        $prop       = $reflection->getProperty( 'cache' );
        $prop->setAccessible( true );
        $this->assertNotNull( $prop->getValue() );
    }

    public function test_maybe_migrate_sets_db_version_on_fresh_install() {
        delete_option( 'rtu_basics' );
        delete_option( 'rtu_db_version' );
        delete_option( 'rtu_30_notice_dismissed' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $this->assertSame( '3.0.1', get_option( 'rtu_db_version' ) );
        $this->assertSame( 0, (int) get_option( 'rtu_30_notice_dismissed', -1 ) );
    }

    public function test_maybe_migrate_preserves_existing_post_types() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' ] ] );
        delete_option( 'rtu_db_version' );
        delete_option( 'rtu_30_notice_dismissed' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        // Sequential legacy storage is normalized to the keyed format.
        $this->assertSame( [ 'genre' => 'genre' ], $stored['rtu_post_types'] );
        $this->assertSame( '', $stored['rtu_enable_redirect'] );
        $this->assertSame( '', $stored['rtu_enable_pagination'] );
        $this->assertSame( '', $stored['rtu_enable_hierarchy'] );
        $this->assertSame( 'on', $stored['rtu_enable_collision'] );
        $this->assertSame( '3.0.1', $stored['rtu_db_version'] );
        $this->assertSame( '3.0.1', get_option( 'rtu_db_version' ) );
    }

    public function test_maybe_migrate_normalizes_legacy_3_0_0_storage() {
        // Regression for the 3.0.0 → 3.0.1 bug: 3.0.0 stored sequential post_types and
        // integer 0/1 feature flags, which the render callbacks could not round-trip.
        // Migration must rewrite them into keyed slugs + 'on'/'' strings.
        update_option( 'rtu_basics', [
            'rtu_post_types'        => [ 'property-type', 'property-city' ], // sequential
            'rtu_enable_redirect'   => 1,
            'rtu_enable_pagination' => 0,
            'rtu_enable_hierarchy'  => 1,
            'rtu_enable_collision'  => 1,
            'rtu_db_version'        => '3.0',
        ] );
        update_option( 'rtu_db_version', '3.0' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        $this->assertSame(
            [ 'property-type' => 'property-type', 'property-city' => 'property-city' ],
            $stored['rtu_post_types']
        );
        $this->assertSame( 'on', $stored['rtu_enable_redirect'] );
        $this->assertSame( '', $stored['rtu_enable_pagination'] );
        $this->assertSame( 'on', $stored['rtu_enable_hierarchy'] );
        $this->assertSame( 'on', $stored['rtu_enable_collision'] );
        $this->assertSame( '3.0.1', $stored['rtu_db_version'] );
    }

    public function test_maybe_migrate_normalizes_boolean_true_flags() {
        // Defensive: a non-sanitize writer (WP-CLI/REST) could store a flag as boolean true.
        // Migration must treat it as enabled, not silently drop it to ''.
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'genre' ],
            'rtu_enable_redirect' => true,
            'rtu_enable_hierarchy' => false,
            'rtu_db_version'      => '3.0',
        ] );
        update_option( 'rtu_db_version', '3.0' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        $this->assertSame( 'on', $stored['rtu_enable_redirect'] );
        $this->assertSame( '', $stored['rtu_enable_hierarchy'] );
    }

    public function test_maybe_migrate_survives_duplicate_taxonomy_slugs() {
        // Defensive: corrupted/duplicate slugs must not fatal array_combine (PHP 8 ValueError).
        update_option( 'rtu_basics', [
            'rtu_post_types' => [ 'genre', 'genre', 'topic' ],
            'rtu_db_version' => '3.0',
        ] );
        update_option( 'rtu_db_version', '3.0' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate(); // Must not throw.

        $stored = get_option( 'rtu_basics' );
        $this->assertSame( [ 'genre' => 'genre', 'topic' => 'topic' ], $stored['rtu_post_types'] );
    }

    public function test_maybe_migrate_idempotent_when_already_current() {
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'genre' => 'genre' ],
            'rtu_enable_redirect' => 'on',
            'rtu_db_version'      => '3.0.1',
        ] );
        update_option( 'rtu_db_version', '3.0.1' );
        RTU_Options::flush_cache();

        RTU_Options::maybe_migrate();

        $stored = get_option( 'rtu_basics' );
        $this->assertSame( 'on', $stored['rtu_enable_redirect'] ); // Not reset.
        $this->assertSame( [ 'genre' => 'genre' ], $stored['rtu_post_types'] );
    }
}
