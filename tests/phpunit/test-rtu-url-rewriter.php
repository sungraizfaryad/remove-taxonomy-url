<?php
class RTU_Url_Rewriter_Test extends WP_UnitTestCase {

    private $rewriter;

    public function setUp(): void {
        parent::setUp();
        register_taxonomy( 'genre', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'genre' ] ] );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'genre' ] ] );
        RTU_Options::flush_cache();
        $this->rewriter = new RTU_Url_Rewriter();
    }

    public function tearDown(): void {
        unregister_taxonomy( 'genre' );
        delete_option( 'rtu_basics' );
        RTU_Options::flush_cache();
        parent::tearDown();
    }

    public function test_filter_term_link_strips_taxonomy_slug() {
        $term_id = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $term    = get_term( $term_id, 'genre' );

        $url = home_url( '/genre/rock/' );
        $out = $this->rewriter->filter_term_link( $url, $term, 'genre' );

        $this->assertSame( home_url( '/rock/' ), $out );
    }

    public function test_filter_term_link_does_not_overmatch_parent_path() {
        // A taxonomy whose slug is "cat" should not strip "/cat" from "/category/cat/".
        register_taxonomy( 'cat', 'post', [ 'public' => true, 'rewrite' => [ 'slug' => 'cat' ] ] );
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'cat' ] ] );
        RTU_Options::flush_cache();

        $url = home_url( '/category/cat/' );
        $out = $this->rewriter->filter_term_link( $url, (object) [ 'slug' => 'cat' ], 'cat' );

        // The /cat segment matching the taxonomy slug at the term position is stripped,
        // leaving the literal "/category/" parent path intact.
        $this->assertSame( home_url( '/category/' ), $out );
        unregister_taxonomy( 'cat' );
    }

    public function test_filter_term_link_skips_inactive_taxonomies() {
        update_option( 'rtu_basics', [ 'rtu_post_types' => [ 'something_else' ] ] );
        RTU_Options::flush_cache();

        $url = home_url( '/genre/rock/' );
        $out = $this->rewriter->filter_term_link( $url, (object) [ 'slug' => 'rock' ], 'genre' );

        $this->assertSame( $url, $out );
    }

    public function test_filter_request_remaps_single_level_term() {
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );

        $out = $this->rewriter->filter_request( [ 'name' => 'rock' ] );

        $this->assertArrayNotHasKey( 'name', $out );
        $this->assertSame( 'rock', $out['genre'] );
    }

    public function test_filter_request_resolves_leaf_slug_when_hierarchical_enabled() {
        unregister_taxonomy( 'genre' );
        register_taxonomy(
            'genre',
            'post',
            [ 'public' => true, 'hierarchical' => true, 'rewrite' => [ 'slug' => 'genre' ] ]
        );
        $parent = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'punk', 'parent' => $parent ] );

        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 1,
        ] );
        RTU_Options::flush_cache();

        $out = $this->rewriter->filter_request( [ 'name' => 'punk' ] );
        $this->assertSame( 'punk', $out['genre'] );
    }

    public function test_filter_request_skips_hierarchy_when_disabled() {
        unregister_taxonomy( 'genre' );
        register_taxonomy(
            'genre',
            'post',
            [ 'public' => true, 'hierarchical' => true, 'rewrite' => [ 'slug' => 'genre' ] ]
        );
        $parent = $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'punk', 'parent' => $parent ] );

        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 0,
        ] );
        RTU_Options::flush_cache();

        $out = $this->rewriter->filter_request( [ 'name' => 'punk' ] );
        $this->assertSame( 'punk', $out['genre'] );
    }

    public function test_filter_request_passes_through_unknown_slug() {
        $out = $this->rewriter->filter_request( [ 'name' => 'no-such-term' ] );
        $this->assertSame( [ 'name' => 'no-such-term' ], $out );
    }

    public function test_filter_request_terminates_on_root_term() {
        update_option( 'rtu_basics', [
            'rtu_post_types'       => [ 'genre' ],
            'rtu_enable_hierarchy' => 1,
        ] );
        RTU_Options::flush_cache();

        $this->factory->term->create( [ 'taxonomy' => 'genre', 'slug' => 'rock' ] );
        $out = $this->rewriter->filter_request( [ 'name' => 'rock' ] );
        $this->assertSame( 'rock', $out['genre'] );
    }

	public function test_term_link_prepends_ancestors_when_hierarchy_on() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$link     = $rewriter->filter_term_link( 'https://example.com/geo/lisbon/', get_term( $child, 'geo' ), 'geo' );

		$this->assertSame( 'https://example.com/portugal/lisbon/', $link );
		unregister_taxonomy( 'geo' );
	}

	public function test_term_link_flat_when_hierarchy_off() {
		register_taxonomy( 'geo', 'post', array( 'hierarchical' => true, 'rewrite' => array( 'slug' => 'geo' ) ) );
		$pt    = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'portugal' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => 'geo', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo' => 'geo' ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$link     = $rewriter->filter_term_link( 'https://example.com/geo/lisbon/', get_term( $child, 'geo' ), 'geo' );

		$this->assertSame( 'https://example.com/lisbon/', $link );
		unregister_taxonomy( 'geo' );
	}

	public function test_request_resolves_flat_child_to_leaf_slug_without_path() {
		register_taxonomy( 'geo2', 'post', array( 'hierarchical' => true ) );
		$pt = self::factory()->term->create( array( 'taxonomy' => 'geo2', 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => 'geo2', 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( 'geo2' => 'geo2' ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();

		$rewriter = new RTU_Url_Rewriter();
		$out      = $rewriter->filter_request( array( 'name' => 'lisbon' ) );

		// Leaf slug only — NOT "portugal/lisbon".
		$this->assertSame( 'lisbon', $out['geo2'] );
		$this->assertArrayNotHasKey( 'name', $out );
		unregister_taxonomy( 'geo2' );
	}
}
