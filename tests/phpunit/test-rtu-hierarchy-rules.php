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

	private function enable_hierarchy_for( $tax ) {
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( $tax => $tax ),
			'rtu_enable_hierarchy' => 'on',
		) );
		RTU_Options::flush_cache();
	}

	public function test_generate_rules_creates_path_rules_for_children_only() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		$this->enable_hierarchy_for( $this->tax );

		$rules = ( new RTU_Hierarchy_Rules() )->generate_rules();

		$this->assertArrayHasKey( '^portugal/lisbon/?$', $rules );
		$this->assertSame( 'index.php?' . $this->tax . '=lisbon', $rules['^portugal/lisbon/?$'] );
		$this->assertArrayHasKey( '^portugal/lisbon/page/?([0-9]{1,})/?$', $rules );
		$this->assertSame(
			'index.php?' . $this->tax . '=lisbon&paged=$matches[1]',
			$rules['^portugal/lisbon/page/?([0-9]{1,})/?$']
		);
		$this->assertArrayNotHasKey( '^portugal/?$', $rules );
	}

	public function test_generate_rules_empty_when_hierarchy_off() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		update_option( 'rtu_basics', array(
			'rtu_post_types'       => array( $this->tax => $this->tax ),
			'rtu_enable_hierarchy' => '',
		) );
		RTU_Options::flush_cache();

		$this->assertSame( array(), ( new RTU_Hierarchy_Rules() )->generate_rules() );
	}

	public function test_skipped_taxonomies_empty_under_cap() {
		$pt = self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'portugal' ) );
		self::factory()->term->create( array( 'taxonomy' => $this->tax, 'slug' => 'lisbon', 'parent' => $pt ) );
		$this->enable_hierarchy_for( $this->tax );
		$rules = new RTU_Hierarchy_Rules();
		$rules->generate_rules();
		$this->assertSame( array(), $rules->get_skipped_taxonomies() );
	}
}
