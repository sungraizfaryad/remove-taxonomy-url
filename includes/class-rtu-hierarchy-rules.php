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
}
