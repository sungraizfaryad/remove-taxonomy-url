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
     * Selected taxonomies that are still registered.
     *
     * @return string[]
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
