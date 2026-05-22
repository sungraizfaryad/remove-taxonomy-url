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
	 * Stub — filled by Task 7.
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public function filter_request( $query_vars ) {
		return $query_vars;
	}
}
