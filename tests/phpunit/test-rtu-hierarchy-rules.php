<?php
class RTU_Hierarchy_Rules_Test extends WP_UnitTestCase {

	private $tax = 'geo';

	public function set_up() {
		parent::set_up();
		register_taxonomy( $this->tax, 'post', array( 'hierarchical' => true ) );
	}

	public function tear_down() {
		unregister_taxonomy( $this->tax );
		parent::tear_down();
	}

	public function test_term_path_top_level_is_just_slug() {
		$id   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		$term = get_term( $id, $this->tax );
		$this->assertSame( 'portugal', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}

	public function test_term_path_multi_level() {
		$pt   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		$al   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'algarve', 'parent' => $pt ) );
		$tv   = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lagos', 'parent' => $al ) );
		$term = get_term( $tv, $this->tax );
		$this->assertSame( 'portugal/algarve/lagos', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}

	public function test_term_path_stops_on_missing_ancestor() {
		$ghost = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'ghost' ) );
		$child = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'child', 'parent' => $ghost ) );
		wp_delete_term( $ghost, $this->tax ); // orphan the child
		$term = get_term( $child, $this->tax );
		// Parent gone -> WP reparents child to 0; path is just its own slug.
		$this->assertSame( 'child', RTU_Hierarchy_Rules::term_path( $term, $this->tax ) );
	}
}
