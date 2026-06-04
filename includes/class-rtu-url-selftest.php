<?php
/**
 * URL resolution self-test for Remove Taxonomy URL.
 *
 * Samples active-taxonomy terms, requests each term's (base-stripped, possibly nested)
 * permalink over an HTTP loopback, and reports which resolve (200) vs 404. Cause-agnostic:
 * catches breakage from hierarchy misconfiguration, stale rewrite rules, slug collisions,
 * or pagination — the check that was missing when child terms 404'd in production.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Samples terms and verifies their public URLs resolve.
 */
class RTU_Url_Selftest {

	const AJAX_ACTION = 'rtu_run_selftest';
	const NONCE       = 'rtu_selftest_nonce';

	/**
	 * Pick up to $limit terms across the given taxonomies, biased to include top-level
	 * terms and a spread of children from different parents.
	 *
	 * @param string[] $taxonomies Active taxonomy slugs.
	 * @param int      $limit      Max terms to return.
	 * @return array[] Each: array( 'taxonomy', 'term_id', 'slug', 'parent' ).
	 */
	public function sample_terms( $taxonomies, $limit = 25 ) {
		$top   = array();
		$child = array();
		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$row = array(
					'taxonomy' => $taxonomy,
					'term_id'  => (int) $term->term_id,
					'slug'     => $term->slug,
					'parent'   => (int) $term->parent,
				);
				if ( 0 === (int) $term->parent ) {
					$top[] = $row;
				} else {
					$child[ (int) $term->parent ][] = $row;
				}
			}
		}

		$sample = array();
		foreach ( $top as $row ) {
			if ( count( $sample ) >= $limit ) {
				return $sample;
			}
			$sample[] = $row;
		}

		// Round-robin one child per distinct parent for breadth.
		$cursors      = array_fill_keys( array_keys( $child ), 0 );
		$drained      = false;
		$sample_count = count( $sample );
		while ( ! $drained && $sample_count < $limit ) {
			$drained = true;
			foreach ( $cursors as $parent_id => $idx ) {
				if ( isset( $child[ $parent_id ][ $idx ] ) ) {
					$sample[] = $child[ $parent_id ][ $idx ];
					++$sample_count;
					$cursors[ $parent_id ] = $idx + 1;
					$drained               = false;
					if ( $sample_count >= $limit ) {
						break;
					}
				}
			}
		}
		return $sample;
	}
}
