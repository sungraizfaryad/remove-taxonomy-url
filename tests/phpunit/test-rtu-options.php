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
}
