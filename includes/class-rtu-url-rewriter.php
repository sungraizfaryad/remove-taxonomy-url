<?php
/**
 * URL rewriter for Remove Taxonomy URL.
 *
 * Owns the term_link and request filters that strip taxonomy slugs from URLs
 * and resolve incoming requests to the matching term.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Strips taxonomy slugs from term permalinks and remaps incoming requests back to the
 * matching taxonomy query var.
 */
class RTU_Url_Rewriter {

	/**
	 * Register hooks via the plugin loader.
	 *
	 * Filters are bound unconditionally; their callbacks short-circuit when
	 * `get_active_taxonomies()` is empty. We can't gate at registration time
	 * because the plugin bootstrap runs during plugin file load, BEFORE `init`
	 * fires — third-party CPTs/taxonomies (the only thing `get_taxonomies(['_builtin'=>false])`
	 * sees) aren't registered yet, so a gate here would always early-return and
	 * the filters would never bind for the request lifecycle.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_filter( 'term_link', $this, 'filter_term_link', 10, 3 );
		$loader->add_filter( 'request', $this, 'filter_request', 1, 1 );
	}

	/**
	 * Strip the taxonomy slug from term permalinks.
	 *
	 * Uses a word-boundary regex (`/slug` followed by `/` or end of string) so a
	 * slug appearing inside a parent path component is not over-matched.
	 *
	 * @param string $url      Original term URL.
	 * @param object $term     Term object.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	public function filter_term_link( $url, $term, $taxonomy ) {
		$active = RTU_Options::get_active_taxonomies();
		if ( ! in_array( $taxonomy, $active, true ) ) {
			return $url;
		}
		// Strip the base taxonomy slug (word-boundary so a parent path is not over-matched).
		$stripped = preg_replace( '#/' . preg_quote( $taxonomy, '#' ) . '(?=/|$)#', '', $url, 1 );

		if ( RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' )
			&& isset( $term->parent ) && 0 !== (int) $term->parent ) {
			$path = RTU_Hierarchy_Rules::term_path( $term, $taxonomy );
			if ( '' !== $path && false !== strpos( $path, '/' ) ) {
				// Replace the trailing "/<own-slug>/" (or "/<own-slug>") with the full ancestor path.
				$stripped = preg_replace(
					'#/' . preg_quote( $term->slug, '#' ) . '(/|$)#',
					'/' . $path . '$1',
					$stripped,
					1
				);
			}
		}

		return $stripped;
	}

	/**
	 * Remap an incoming flat `name`/`attachment` request to the matching taxonomy term.
	 *
	 * Always resolves to the single LEAF slug. Nested paths are handled by the explicit
	 * rewrite rules in RTU_Hierarchy_Rules; the canonical 301 (RTU_Redirect_Handler) sends
	 * a flat child URL to its nested form when hierarchy is on. WordPress guarantees unique
	 * term slugs per taxonomy, so the leaf slug resolves unambiguously.
	 *
	 * @param array $query_vars Incoming request query vars.
	 * @return array
	 */
	public function filter_request( $query_vars ) {
		$active = RTU_Options::get_active_taxonomies();
		if ( empty( $active ) ) {
			return $query_vars;
		}

		if ( isset( $query_vars['attachment'] ) ) {
			$key  = 'attachment';
			$name = $query_vars['attachment'];
		} elseif ( isset( $query_vars['name'] ) ) {
			$key  = 'name';
			$name = $query_vars['name'];
		} else {
			return $query_vars;
		}

		foreach ( $active as $taxonomy ) {
			$term = get_term_by( 'slug', $name, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			unset( $query_vars[ $key ] );
			$query_vars[ $taxonomy ] = $term->slug;
			return $query_vars;
		}

		return $query_vars;
	}
}
