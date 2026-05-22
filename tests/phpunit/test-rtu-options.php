<?php
class RTU_Options_Test extends WP_UnitTestCase {

    public function tearDown(): void {
        delete_option( 'rtu_basics' );
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

    public function test_get_active_taxonomies_excludes_builtin_taxonomies() {
        // `category` is a built-in taxonomy; it must never be returned even if explicitly selected.
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'category', 'post_tag' ] ] );
        RTU_Options::flush_cache();
        $this->assertSame( [], RTU_Options::get_active_taxonomies() );
    }

    public function test_is_feature_enabled_returns_bool() {
        update_option( 'rtu_basics', [ 'rtu_enable_redirect' => 1, 'rtu_enable_pagination' => 0 ] );
        RTU_Options::flush_cache();
        $this->assertTrue( RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_missing' ) );
    }

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
        $this->assertSame( 1, $clean['rtu_enable_collision'] ); // defaults ON
    }

    public function test_sanitize_collision_defaults_on_when_key_missing() {
        $clean = RTU_Options::sanitize( [] );
        $this->assertSame( 1, $clean['rtu_enable_collision'] );
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
}
