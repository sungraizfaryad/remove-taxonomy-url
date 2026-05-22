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
		require_once plugin_dir_path( __DIR__ ) . 'admin/partials/remove-taxonomy-url-settings.php';
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
	 *
	 * @param string $hook_suffix Current admin screen's hook suffix (provided by admin_enqueue_scripts).
	 */
	public function enqueue_scripts( $hook_suffix = '' ) {

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/remove-taxonomy-url-admin.js', array( 'jquery' ), $this->version, false );

		if ( 'settings_page_rtu_settings_page' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'rtu-health-check',
			plugin_dir_url( __FILE__ ) . 'js/rtu-health-check.js',
			array(),
			$this->version,
			true
		);
		wp_localize_script(
			'rtu-health-check',
			'rtuHealthCheckL10n',
			array(
				'taxonomy'      => __( 'Taxonomy', 'remove-taxonomy-url' ),
				'termSlug'      => __( 'Term slug', 'remove-taxonomy-url' ),
				'conflictsWith' => __( 'Conflicts with', 'remove-taxonomy-url' ),
				'noConflicts'   => __( 'No collisions found.', 'remove-taxonomy-url' ),
				'failed'        => __( 'Audit failed.', 'remove-taxonomy-url' ),
			)
		);
	}
}
