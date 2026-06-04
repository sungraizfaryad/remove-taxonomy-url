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

	public function test_check_term_passes_on_200() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$id = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

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
		$this->assertTrue( $row['pass'] );
		unregister_taxonomy( 'geo' );
	}

	public function test_run_returns_rows_and_counts() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		update_option( 'rtu_basics', array( 'rtu_post_types' => array( 'geo' => 'geo' ) ) );
		RTU_Options::flush_cache();

		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => 'ok' );
		} );

		$result = ( new RTU_Url_Selftest() )->run( 25 );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'tested', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 1, $result['tested'] );
		$this->assertSame( 1, $result['total'] );
		unregister_taxonomy( 'geo' );
	}
}
