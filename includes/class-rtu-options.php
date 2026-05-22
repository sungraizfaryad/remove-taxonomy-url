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
}
