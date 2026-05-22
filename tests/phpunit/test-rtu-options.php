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

    public function test_is_feature_enabled_returns_bool() {
        update_option( 'rtu_basics', [ 'rtu_enable_redirect' => 1, 'rtu_enable_pagination' => 0 ] );
        RTU_Options::flush_cache();
        $this->assertTrue( RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) );
        $this->assertFalse( RTU_Options::is_feature_enabled( 'rtu_enable_missing' ) );
    }
}
