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
}
