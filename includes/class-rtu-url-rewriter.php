<?php
/**
 * URL rewriter for Remove Taxonomy URL.
 *
 * Owns the term_link and request filters that strip taxonomy slugs from URLs.
 * Task 6 ports the term_link logic with the over-match bug fix; the request
 * filter is filled in by Task 7.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RTU_Url_Rewriter {

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		if ( empty( RTU_Options::get_active_taxonomies() ) ) {
			return;
		}
		$loader->add_filter( 'term_link', $this, 'filter_term_link', 10, 3 );
		$loader->add_filter( 'request',   $this, 'filter_request', 1, 1 );
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
		$pattern = '#/' . preg_quote( $taxonomy, '#' ) . '(?=/|$)#';
		return preg_replace( $pattern, '', $url, 1 );
	}

	/**
	 * Remap incoming `?name=slug` to `?taxonomy=slug` after term resolution.
	 *
	 * When `rtu_enable_hierarchy` is on, walks the parent chain and prepends parent
	 * slugs so `/rock/punk/` resolves to the nested term. The walker is guarded
	 * against orphan parents and circular references that would loop in the 1.x code.
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
			$include_children = true;
			$name             = $query_vars['attachment'];
		} elseif ( isset( $query_vars['name'] ) ) {
			$include_children = false;
			$name             = $query_vars['name'];
		} else {
			return $query_vars;
		}

		$hierarchy_enabled = RTU_Options::is_feature_enabled( 'rtu_enable_hierarchy' );

		foreach ( $active as $taxonomy ) {
			$term = get_term_by( 'slug', $name, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			if ( $include_children ) {
				unset( $query_vars['attachment'] );
			} else {
				unset( $query_vars['name'] );
			}

			$resolved = $name;
			if ( $hierarchy_enabled && ! empty( $term->parent ) ) {
				$resolved = $this->prepend_parents( $term, $taxonomy );
			}

			$query_vars[ $taxonomy ] = $resolved;
			return $query_vars;
		}

		return $query_vars;
	}

	/**
	 * Walk the parent chain and prepend slugs. Guards against orphan and circular references.
	 *
	 * @param WP_Term $term     Starting term.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return string Slash-joined slug path.
	 */
	private function prepend_parents( $term, $taxonomy ) {
		$path    = $term->slug;
		$parent  = (int) $term->parent;
		$visited = [ (int) $term->term_id => true ];
		$safety  = 0;

		while ( $parent && $safety++ < 25 ) {
			if ( isset( $visited[ $parent ] ) ) {
				break;
			}
			$visited[ $parent ] = true;

			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				break;
			}
			$path   = $parent_term->slug . '/' . $path;
			$parent = (int) $parent_term->parent;
		}

		return $path;
	}
}
