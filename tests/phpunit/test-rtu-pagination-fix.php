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
        $fix    = new RTU_Pagination_Fix();
        $rules  = [ 'existing/?$' => 'index.php?existing=1' ];
        $merged = $fix->inject_rules( $rules );

        $this->assertArrayHasKey( 'existing/?$', $merged );
        $this->assertSame(
            'index.php?name=$matches[1]&paged=$matches[2]',
            $merged['^([^/]+)/page/?([0-9]{1,})/?$']
        );
        $this->assertSame(
            'index.php?name=$matches[1]',
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

    public function test_inject_rules_handles_non_array_input() {
        $fix    = new RTU_Pagination_Fix();
        $merged = $fix->inject_rules( null );

        $this->assertIsArray( $merged );
        $this->assertArrayHasKey( '^([^/]+)/?$', $merged );
    }

    public function test_inject_rules_serves_multiple_taxonomies_without_collision() {
        register_taxonomy( 'location', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'location' ] ] );
        update_option( 'rtu_basics', [
            'rtu_post_types'        => [ 'genre' => 'genre', 'location' => 'location' ],
            'rtu_enable_pagination' => 'on',
        ] );
        RTU_Options::flush_cache();

        $merged = ( new RTU_Pagination_Fix() )->inject_rules( array() );

        // One taxonomy-agnostic rule routed via `name`, serving BOTH taxonomies (no overwrite, no hardcoded slug).
        $this->assertSame( 'index.php?name=$matches[1]&paged=$matches[2]', $merged['^([^/]+)/page/?([0-9]{1,})/?$'] );
        $this->assertSame( 'index.php?name=$matches[1]', $merged['^([^/]+)/?$'] );
        // Must NOT hardcode any single taxonomy slug.
        $this->assertStringNotContainsString( 'genre=', $merged['^([^/]+)/page/?([0-9]{1,})/?$'] );
        $this->assertStringNotContainsString( 'location=', $merged['^([^/]+)/page/?([0-9]{1,})/?$'] );
        unregister_taxonomy( 'location' );
    }
}
