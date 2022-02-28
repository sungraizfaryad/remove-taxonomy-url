<?php

/**
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              www.sungraizfaryad.com
 * @since             1.0.0
 * @package           Remove_Taxonomy_Url
 *
 * @wordpress-plugin
 * Plugin Name:       Remove Taxonomy URL
 * Plugin URI:        https://wordpress.org/plugins/remove-taxonomy-url
 * Description:       This is a purpose oriented plugin which just removes the custom taxonomies slugs from URL.
 * Version:           1.0.4
 * Author:            Sungraiz Faryad
 * Author URI:        www.sungraizfaryad.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       remove-taxonomy-url
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'REMOVE_TAXONOMY_URL_VERSION', '1.0.4' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-remove-taxonomy-url-activator.php
 */
function activate_remove_taxonomy_url() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-remove-taxonomy-url-activator.php';
	Remove_Taxonomy_Url_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-remove-taxonomy-url-deactivator.php
 */
function deactivate_remove_taxonomy_url() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-remove-taxonomy-url-deactivator.php';
	Remove_Taxonomy_Url_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_remove_taxonomy_url' );
register_deactivation_hook( __FILE__, 'deactivate_remove_taxonomy_url' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-remove-taxonomy-url.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_remove_taxonomy_url() {

	$plugin = new Remove_Taxonomy_Url();
	$plugin->run();

}
run_remove_taxonomy_url();
