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
}
