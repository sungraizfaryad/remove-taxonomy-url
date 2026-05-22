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

    public function test_compute_target_returns_null_for_inactive_taxonomy() {
        update_option( 'rtu_basics', [
            'rtu_post_types'      => [ 'unrelated' ],
            'rtu_enable_redirect' => 1,
        ] );
        RTU_Options::flush_cache();

        $this->assertNull( $this->handler->compute_target( '/genre/rock/' ) );
    }

    public function test_compute_target_preserves_query_string() {
        $target = $this->handler->compute_target( '/genre/rock/page/2/' );
        $this->assertSame( '/rock/page/2/', $target );
    }
}
