<?php
/**
 * Options facade for Remove Taxonomy URL.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

final class RTU_Options {

    const OPTION_KEY = 'rtu_basics';
    const DB_VERSION = '3.0';

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
            $stored      = get_option( self::OPTION_KEY, [] );
            self::$cache = is_array( $stored ) ? $stored : [];
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
        $selected = (array) self::get( 'rtu_post_types', [] );
        if ( empty( $selected ) ) {
            return [];
        }
        $registered = array_keys( get_taxonomies( [ '_builtin' => false ] ) );
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
     * Sanitize callback for register_setting(). Whitelists taxonomies against currently
     * registered non-built-in taxonomies; coerces feature flags to 0/1; defaults the
     * collision detector ON when its checkbox is absent from the submission.
     *
     * @param mixed $input Raw input from the settings form.
     * @return array
     */
    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];

        $registered = array_keys( get_taxonomies( [ '_builtin' => false ] ) );
        $selected   = isset( $input['rtu_post_types'] ) && is_array( $input['rtu_post_types'] )
            ? $input['rtu_post_types']
            : [];

        $clean                          = [];
        $clean['rtu_post_types']        = array_values( array_intersect( $selected, $registered ) );
        $clean['rtu_enable_redirect']   = ! empty( $input['rtu_enable_redirect'] ) ? 1 : 0;
        $clean['rtu_enable_pagination'] = ! empty( $input['rtu_enable_pagination'] ) ? 1 : 0;
        $clean['rtu_enable_hierarchy']  = ! empty( $input['rtu_enable_hierarchy'] ) ? 1 : 0;
        // Collision detection defaults ON: only treated as off when the key is present and explicitly empty/zero.
        $clean['rtu_enable_collision']  = array_key_exists( 'rtu_enable_collision', $input )
            ? ( ! empty( $input['rtu_enable_collision'] ) ? 1 : 0 )
            : 1;
        $clean['rtu_db_version']        = self::DB_VERSION;

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

        $stored = get_option( self::OPTION_KEY, [] );
        $stored = is_array( $stored ) ? $stored : [];

        $defaults = [
            'rtu_post_types'        => [],
            'rtu_enable_redirect'   => 0,
            'rtu_enable_pagination' => 0,
            'rtu_enable_hierarchy'  => 0,
            'rtu_enable_collision'  => 1,
            'rtu_db_version'        => self::DB_VERSION,
        ];

        $merged                     = array_merge( $defaults, $stored );
        $merged['rtu_db_version']   = self::DB_VERSION;

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
