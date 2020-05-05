<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       www.sungraizfaryad.com
 * @since      1.0.0
 *
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/includes
 * @author     Sungraiz Faryad <sungraiz@gmail.com>
 */
class Remove_Taxonomy_Url_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'remove-taxonomy-url',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
