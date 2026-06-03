<?php
/**
 * Options facade for Remove Taxonomy URL.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Read facade plus migration handler for the rtu_basics option that backs every
 * Remove Taxonomy URL module.
 */
final class RTU_Options {

	const OPTION_KEY = 'rtu_basics';
	const DB_VERSION = '3.0.1';

	/**
	 * Per-request cache of the option array.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Read a single key from the rtu_basics option.
	 *
	 * @param string $key     Key inside rtu_basics.
	 * @param mixed  $default Value returned when the key is missing.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$opts = self::all();
		return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	/**
	 * Read the full option array.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION_KEY, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	/**
	 * Invalidate the per-request cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Selected taxonomies that are still registered as custom (non-built-in) taxonomies.
	 *
	 * The `_builtin => false` filter is intentional: built-in WordPress taxonomies
	 * (`category`, `post_tag`, `nav_menu`, etc.) are out of scope for this plugin.
	 * The settings UI only offers non-built-in taxonomies in the multicheck, and
	 * Yoast SEO / Rank Math already provide "Strip Category Base" for `category`.
	 * Do not relax this filter without revisiting the settings UI, the conflict
	 * detector, and the redirect handler.
	 *
	 * @return string[] Sequential list of taxonomy slugs.
	 */
	public static function get_active_taxonomies() {
		$selected = (array) self::get( 'rtu_post_types', array() );
		if ( empty( $selected ) ) {
			return array();
		}
		$registered = array_keys( get_taxonomies( array( '_builtin' => false ) ) );
		return array_values( array_intersect( $selected, $registered ) );
	}

	/**
	 * Boolean feature flag check.
	 *
	 * @param string $feature Feature key inside rtu_basics.
	 * @return bool
	 */
	public static function is_feature_enabled( $feature ) {
		return ! empty( self::get( $feature, 0 ) );
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Output format is tuned to round-trip cleanly through the WeDevs WP-Settings-API
	 * render callbacks (callback_multicheck + callback_checkbox):
	 *   - rtu_post_types: keyed slug=>slug array so callback_multicheck's
	 *     `isset($value[$key])` check finds the selection.
	 *   - rtu_enable_* feature flags: stored as the literal string 'on' (enabled) or
	 *     '' (disabled), matching what callback_checkbox emits as the checkbox value.
	 *     The hidden companion field of WeDevs's checkbox posts the literal string
	 *     'off' when unchecked, so a `!empty()` check would wrongly count it as
	 *     enabled; only an explicit `'on' ===` comparison is correct.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		$registered = array_keys( get_taxonomies( array( '_builtin' => false ) ) );
		$selected   = isset( $input['rtu_post_types'] ) && is_array( $input['rtu_post_types'] )
			? $input['rtu_post_types']
			: array();

		$filtered = array_values( array_intersect( $selected, $registered ) );

		$clean                          = array();
		$clean['rtu_post_types']        = empty( $filtered ) ? array() : array_combine( $filtered, $filtered );
		$clean['rtu_enable_redirect']   = ( isset( $input['rtu_enable_redirect'] ) && 'on' === $input['rtu_enable_redirect'] ) ? 'on' : '';
		$clean['rtu_enable_pagination'] = ( isset( $input['rtu_enable_pagination'] ) && 'on' === $input['rtu_enable_pagination'] ) ? 'on' : '';
		$clean['rtu_enable_hierarchy']  = ( isset( $input['rtu_enable_hierarchy'] ) && 'on' === $input['rtu_enable_hierarchy'] ) ? 'on' : '';
		// Collision detection defaults ON: keep enabled unless the field is submitted with anything other than 'on'.
		$clean['rtu_enable_collision'] = array_key_exists( 'rtu_enable_collision', $input )
			? ( 'on' === $input['rtu_enable_collision'] ? 'on' : '' )
			: 'on';
		$clean['rtu_db_version']       = self::DB_VERSION;

		self::flush_cache();
		return $clean;
	}

	/**
	 * `updated_option` callback that drops the per-request cache when our option changes.
	 * Insurance against writers that bypass the sanitize callback (WP-CLI, REST, unit tests).
	 *
	 * @param string $option Option name that just updated.
	 * @return void
	 */
	public static function maybe_flush_on_update( $option ) {
		if ( self::OPTION_KEY === $option ) {
			self::flush_cache();
		}
	}

	/**
	 * Migrate options from any older schema to the current DB version. Idempotent.
	 * Triggered by the activation hook, an upgrader_process_complete listener, and
	 * a plugins_loaded fallback.
	 *
	 * On a first 3.0 boot:
	 *   - Merges new feature-flag defaults into rtu_basics without clobbering rtu_post_types
	 *   - Sets rtu_db_version = '3.0'
	 *   - Arms the upgrade banner by setting rtu_30_notice_dismissed = 0
	 *   - Sets the rtu_needs_flush transient so admin_init can flush rewrite rules once
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		$current = get_option( 'rtu_db_version', '' );
		if ( self::DB_VERSION === $current ) {
			return;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$defaults = array(
			'rtu_post_types'        => array(),
			'rtu_enable_redirect'   => '',
			'rtu_enable_pagination' => '',
			'rtu_enable_hierarchy'  => '',
			'rtu_enable_collision'  => 'on',
			'rtu_db_version'        => self::DB_VERSION,
		);

		$merged                   = array_merge( $defaults, $stored );
		$merged['rtu_db_version'] = self::DB_VERSION;

		// 3.0.1 normalization: rewrite legacy 3.0.0 storage (sequential rtu_post_types and int 0/1 feature flags)
		// into the formats the WeDevs render callbacks expect (slug=>slug keyed array and 'on'/'' strings).
		// array_unique guards against array_combine() throwing a ValueError on duplicate slugs from
		// corrupted data or non-sanitize writers (WP-CLI, REST). The truthy test below accepts boolean
		// true as well as int/numeric 1 and the string 'on', so a flag written as `true` is not silently lost.
		if ( isset( $merged['rtu_post_types'] ) && is_array( $merged['rtu_post_types'] ) && ! empty( $merged['rtu_post_types'] ) ) {
			$slugs                    = array_values( array_unique( $merged['rtu_post_types'] ) );
			$merged['rtu_post_types'] = array_combine( $slugs, $slugs );
		}
		foreach ( array( 'rtu_enable_redirect', 'rtu_enable_pagination', 'rtu_enable_hierarchy', 'rtu_enable_collision' ) as $flag ) {
			$val             = isset( $merged[ $flag ] ) ? $merged[ $flag ] : '';
			$merged[ $flag ] = ( 'on' === $val || true === $val || ( is_numeric( $val ) && 1 === (int) $val ) ) ? 'on' : '';
		}

		update_option( self::OPTION_KEY, $merged );
		update_option( 'rtu_db_version', self::DB_VERSION );

		// Arm the upgrade banner only if the user hasn't already dismissed it on a prior 3.0 install.
		if ( false === get_option( 'rtu_30_notice_dismissed', false ) ) {
			update_option( 'rtu_30_notice_dismissed', 0 );
		}
		set_transient( 'rtu_needs_flush', 1, HOUR_IN_SECONDS );

		self::flush_cache();
	}
}
