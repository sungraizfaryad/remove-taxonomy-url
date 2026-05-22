<?php
/**
 * Pagination rewrite rules for taxonomies with their base slug removed.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RTU_Pagination_Fix {

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_filter( 'rewrite_rules_array', $this, 'inject_rules', 10, 1 );
	}

	/**
	 * Append pagination + flat-slug rules for each active taxonomy.
	 *
	 * Inserted at the end so WordPress's built-in page/post rules match first.
	 *
	 * @param array $rules Current rewrite rules array.
	 * @return array
	 */
	public function inject_rules( $rules ) {
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_pagination' ) ) {
			return is_array( $rules ) ? $rules : [];
		}
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}
		foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
			$rules['^([^/]+)/page/?([0-9]{1,})/?$'] = 'index.php?' . $slug . '=$matches[1]&paged=$matches[2]';
			$rules['^([^/]+)/?$']                    = 'index.php?' . $slug . '=$matches[1]';
		}
		return $rules;
	}
}
