<?php
class RTU_Conflict_Detector_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        global $wp_settings_errors;
        $wp_settings_errors = array();
        register_taxonomy( 'genre', 'post', [ 'public' => true ] );
        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_collision' => 1,
        ] );
        RTU_Options::flush_cache();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        if ( taxonomy_exists( 'mood' ) ) {
            unregister_taxonomy( 'mood' );
        }
        if ( taxonomy_exists( 'geo' ) ) {
            unregister_taxonomy( 'geo' );
        }
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        global $wp_settings_errors;
        $wp_settings_errors = array();
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
    }

    public function test_find_collisions_empty_for_no_taxonomies() {
        $detector = new RTU_Conflict_Detector();
        $this->assertSame( [], $detector->find_collisions( [] ) );
    }

    public function test_find_collisions_ignores_non_string_taxonomy_args() {
        $detector = new RTU_Conflict_Detector();
        $this->assertSame( [], $detector->find_collisions( [ 123, null, false ] ) );
    }

	public function test_warn_on_save_adds_hierarchy_notice_when_enabling() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$detector = new RTU_Conflict_Detector();
		$new      = array( 'rtu_post_types' => array( 'geo' => 'geo' ), 'rtu_enable_hierarchy' => 'on' );
		$detector->warn_on_save( $new, get_option( 'rtu_basics' ) );

		$codes = wp_list_pluck( get_settings_errors( 'rtu_basics' ), 'code' );
		$this->assertContains( 'rtu_hierarchy_enabled', $codes );
		unregister_taxonomy( 'geo' );
	}

	public function test_warn_on_save_no_hierarchy_notice_when_already_on() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();
		// Clear any errors from the automatic pre_update_option filter firing during update_option() above.
		global $wp_settings_errors;
		$wp_settings_errors = array();

		$detector = new RTU_Conflict_Detector();
		$new      = array( 'rtu_post_types' => array( 'geo' => 'geo' ), 'rtu_enable_hierarchy' => 'on' );
		$detector->warn_on_save( $new, get_option( 'rtu_basics' ) );

		$codes = wp_list_pluck( get_settings_errors( 'rtu_basics' ), 'code' );
		$this->assertNotContains( 'rtu_hierarchy_enabled', $codes );
		unregister_taxonomy( 'geo' );
	}

	public function test_hierarchy_status_reports_state() {
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
}
