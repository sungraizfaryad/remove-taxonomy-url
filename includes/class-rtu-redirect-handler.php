<?php
/**
 * 301 redirect handler — old /taxonomy/term/ to new /term/.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class RTU_Redirect_Handler {

	/**
	 * Register hooks via the plugin loader. No-op when the feature is disabled.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) ) {
			return;
		}
		$loader->add_action( 'template_redirect', $this, 'maybe_redirect', 99 );
	}

	/**
	 * `template_redirect` entry point. Resolves the target, issues a 301, and exits.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		static $already_ran = false;
		if ( $already_ran ) {
			return;
		}
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}
		$already_ran = true;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- esc_url_raw is the sanitizer.
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		if ( '' === $request_uri ) {
			return;
		}

		$target = $this->compute_target( $request_uri );
		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Pure function for testability: given a path, return the redirect target or null.
	 *
	 * @param string $request_uri Request path with leading slash, optional trailing path/query.
	 * @return string|null Redirect target path, or null when no redirect should fire.
	 */
	public function compute_target( $request_uri ) {
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_redirect' ) ) {
			return null;
		}
		foreach ( RTU_Options::get_active_taxonomies() as $slug ) {
			$pattern = '~/' . preg_quote( $slug, '~' ) . '/([^/?#]+)(/.*)?$~';
			if ( ! preg_match( $pattern, $request_uri, $m ) ) {
				continue;
			}
			$term = get_term_by( 'slug', $m[1], $slug );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$target = preg_replace( '~/' . preg_quote( $slug, '~' ) . '/~', '/', $request_uri, 1 );

			/**
			 * Allow developers to suppress the 301 redirect.
			 *
			 * @param bool    $should  True to perform the redirect.
			 * @param string  $target  Computed target URL.
			 * @param WP_Term $term    Resolved term.
			 */
			if ( ! apply_filters( 'rtu_should_redirect', true, $target, $term ) ) {
				return null;
			}
			return $target;
		}
		return null;
	}
}
