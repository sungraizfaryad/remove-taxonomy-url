<?php
/**
 * Hierarchical rewrite-rule generation for Remove Taxonomy URL.
 *
 * When "Hierarchical term URLs" is ON, child terms get a flat base-stripped URL that
 * includes their full ancestor path (e.g. /portugal/lisbon/). This module builds an
 * explicit rewrite rule per child term mapping that full path to the term's LEAF slug
 * query var. Leaf slugs are unique per taxonomy in WordPress, so the mapping is
 * unambiguous at any depth.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds + injects explicit nested rewrite rules and arms a flush when terms change.
 */
class RTU_Hierarchy_Rules {

	/**
	 * Maximum child terms per taxonomy for which rules are generated. Beyond this the
	 * taxonomy is skipped and surfaced as a warning rather than silently truncated.
	 */
	const MAX_CHILD_TERMS = 2000;

	/**
	 * Taxonomies skipped this generation because they exceeded MAX_CHILD_TERMS.
	 *
	 * @var array<string,int> taxonomy => child count
	 */
	private $skipped = array();

	/**
	 * Full slug path for a term: ancestor slugs (root-first) joined with its own slug.
	 *
	 * Guards against orphan/circular parent chains with a visited set and a hard cap,
	 * mirroring the redirect/rewriter walkers.
	 *
	 * @param WP_Term|int $term     Term object or ID.
	 * @param string      $taxonomy Taxonomy slug.
	 * @return string Slash-joined path, e.g. "portugal/algarve/lagos".
	 */
	public static function term_path( $term, $taxonomy ) {
		$term = is_numeric( $term ) ? get_term( (int) $term, $taxonomy ) : $term;
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		$segments = array( $term->slug );
		$parent   = (int) $term->parent;
		$visited  = array( (int) $term->term_id => true );
		$safety   = 0;

		while ( $parent && $safety++ < 25 ) {
			if ( isset( $visited[ $parent ] ) ) {
				break;
			}
			$visited[ $parent ] = true;

			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				break;
			}
			array_unshift( $segments, $parent_term->slug );
			$parent = (int) $parent_term->parent;
		}

		return implode( '/', $segments );
	}

	/**
	 * Build the explicit nested rewrite rules for every active taxonomy's child terms.
	 *
	 * @return array<string,string> Rewrite rule regex => index.php target.
	 */
	public function generate_rules() {
		$this->skipped = array();
		$rules         = array();

		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' ) ) {
			return $rules;
		}

		foreach ( RTU_Options::get_active_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$children = array_filter(
				$terms,
				static function ( $t ) {
					return 0 !== (int) $t->parent;
				}
			);

			if ( count( $children ) > self::MAX_CHILD_TERMS ) {
				$this->skipped[ $taxonomy ] = count( $children );
				continue;
			}

			foreach ( $children as $term ) {
				$path = self::term_path( $term, $taxonomy );
				if ( '' === $path || false === strpos( $path, '/' ) ) {
					continue; // No real ancestor path; flat rules already cover it.
				}
				$quoted = preg_quote( $path, '#' );

				$rules[ '^' . $quoted . '/?$' ]                   = 'index.php?' . $taxonomy . '=' . $term->slug;
				$rules[ '^' . $quoted . '/page/?([0-9]{1,})/?$' ] = 'index.php?' . $taxonomy . '=' . $term->slug . '&paged=$matches[1]';
			}
		}

		return $rules;
	}

	/**
	 * Taxonomies skipped in the last generate_rules() call due to the child-term cap.
	 *
	 * @return array<string,int> taxonomy => child count
	 */
	public function get_skipped_taxonomies() {
		return $this->skipped;
	}

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_filter( 'rewrite_rules_array', $this, 'inject_rules', 9, 1 );
		$loader->add_action( 'created_term', $this, 'arm_flush', 10, 3 );
		$loader->add_action( 'edited_term', $this, 'arm_flush', 10, 3 );
		$loader->add_action( 'delete_term', $this, 'arm_flush', 10, 3 );
	}

	/**
	 * Merge the generated nested rules ahead of the existing rules so they match first.
	 *
	 * @param array $rules Current rewrite rules.
	 * @return array
	 */
	public function inject_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}
		$generated = $this->generate_rules();
		// Generated specific rules first, then the existing set (union keeps generated keys).
		return $generated + $rules;
	}

	/**
	 * Arm a one-time rewrite flush when a term in an active taxonomy changes.
	 *
	 * @param int    $term_id  Term ID (unused).
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function arm_flush( $term_id, $tt_id = 0, $taxonomy = '' ) {
		unset( $term_id, $tt_id );
		if ( '' !== $taxonomy && ! in_array( $taxonomy, RTU_Options::get_active_taxonomies(), true ) ) {
			return;
		}
		set_transient( 'rtu_needs_flush', 1, HOUR_IN_SECONDS );
	}
}
