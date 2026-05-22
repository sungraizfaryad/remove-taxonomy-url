<?php

/**
 * Fired during plugin activation
 *
 * @link       www.sungraizfaryad.com
 * @since      1.0.0
 *
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/includes
 * @author     Sungraiz Faryad <sungraiz@gmail.com>
 */
class Remove_Taxonomy_Url_Activator {

	/**
	 * Runs the 3.0 schema migration when the plugin is activated.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-rtu-options.php';
		RTU_Options::maybe_migrate();
	}

}
