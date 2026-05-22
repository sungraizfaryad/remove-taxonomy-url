<?php
class RTU_Conflict_Detector_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
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
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
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
}
