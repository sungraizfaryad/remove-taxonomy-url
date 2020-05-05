<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       www.sungraizfaryad.com
 * @since      1.0.0
 *
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/admin
 * @author     Sungraiz Faryad <sungraiz@gmail.com>
 */
class Remove_Taxonomy_Url_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 *
	 * @param      string $plugin_name The name of this plugin.
	 * @param      string $version The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->load_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Remove_Taxonomy_Url_Settings. Orchestrates the settings of the plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/remove-taxonomy-url-settings.php';

	}

	public function remove_tax_slugs( $query_vars ) {

		// Add the slugs of those taxonomies which you want to remove from url.
		$options   = get_option( 'rtu_basics' );
		$tax_slugs = $options['rtu_post_types'];

		if ( isset( $query_vars['attachment'] ) ? $query_vars['attachment'] : null ) :
			$include_children = true;
			$name             = $query_vars['attachment'];
		else :
			if ( isset( $query_vars['name'] ) ? $query_vars['name'] : null ) {
				$include_children = false;
				$name             = $query_vars['name'];
			}
		endif;
		if ( isset( $name ) ) :
			foreach ( $tax_slugs as $slug ) {
				$term = get_term_by( 'slug', $name, $slug );
				if ( $term && ! is_wp_error( $term ) ) :
					if ( $include_children ) {
						unset( $query_vars['attachment'] );
						$parent = $term->parent;
						while ( $parent ) {
							$parent_term = get_term( $parent, $slug );
							$name        = $parent_term->slug . '/' . $name;
							$parent      = $parent_term->parent;
						}
					} else {
						unset( $query_vars['name'] );
					}
					$query_vars[ $slug ] = $name;
				endif;
			}
		endif;

		return $query_vars;
	}

	public function build_tax_slugs( $url, $term, $taxonomy ) {

		// Add the slugs of those taxonomies which you want to remove from url.
		$options        = get_option( 'rtu_basics' );
		$taxonomy_slugs = $options['rtu_post_types'];
		foreach ( $taxonomy_slugs as $taxonomy_slug ) {
			if ( stripos( $url, $taxonomy_slug ) === true || $taxonomy == $taxonomy_slug ) {
				$url = str_replace( '/' . $taxonomy_slug, '', $url );
			}
		}

		return $url;
	}


	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Remove_Taxonomy_Url_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Remove_Taxonomy_Url_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/remove-taxonomy-url-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Remove_Taxonomy_Url_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Remove_Taxonomy_Url_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/remove-taxonomy-url-admin.js', array( 'jquery' ), $this->version, false );

	}

}

