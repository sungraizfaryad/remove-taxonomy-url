<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       www.sungraizfaryad.com
 * @since      1.0.0
 *
 * @package    Remove_Taxonomy_Url
 * @subpackage Remove_Taxonomy_Url/admin/partials
 */

/**
 *  Booking Settings Api.
 */
class Remove_Taxonomy_Url_Settings {

	/**
	 * All Settings Saved.
	 *
	 * @var $settings_api array settings.
	 */
	private $settings_api;

	/**
	 * Call methods and variables on init.
	 */
	public function __construct() {
		$this->settings_api = new Remove_Taxonomy_Url_Settings_API();
	}

	/**
	 * Init Booking Settings.
	 */
	public function rtu_settings_init() {

		// set the settings.
		$this->settings_api->set_sections( $this->get_settings_sections() );
		$this->settings_api->set_fields( $this->get_settings_fields() );

		// initialize settings.
		$this->settings_api->admin_init();
	}

	/**
	 * Adding options page under Settings.
	 */
	public function settings_menu() {
		add_submenu_page(
			'options-general.php',
			esc_html__( 'Remove Taxonomy URL', 'remove-taxonomy-url' ),
			esc_html__( 'Remove Taxonomy URL', 'remove-taxonomy-url' ),
			'manage_options',
			'rtu_settings_page',
			array( $this, 'rtu_settings_page' )
		);
	}


	/**
	 * Returns all Sections for settings
	 *
	 * @return array section fields
	 */
	private function get_settings_sections() {
		$sections   = array();
		$sections[] = array(
			'id'    => 'rtu_basics',
			'title' => esc_html__( 'Taxonomies & Redirects', 'remove-taxonomy-url' ),
			'desc'  => sprintf(
				/* translators: %s: emphasized IMPORTANT label */
				esc_html__( '%s You need to save the Permalinks Twice after saving the settings otherwise you will face 404 error.', 'remove-taxonomy-url' ),
				'<strong style="font-size: 1rem; color: red;">***IMPORTANT***</strong><br />'
			),
		);

		return $sections;
	}

	/**
	 * Returns all the settings fields
	 *
	 * @return array settings fields
	 */
	private function get_settings_fields() {

		$all_taxonomies = get_taxonomies( array( '_builtin' => false ) );

		$settings_fields['rtu_basics'] = array(
			array(
				'name'    => 'rtu_post_types',
				'label'   => esc_html__( 'Taxonomies List', 'remove-taxonomy-url' ),
				'desc'    => esc_html__( "Tick the taxonomies whose base slug you want removed. Example: with 'Genre' ticked, /genre/rock/ becomes /rock/. Only tick taxonomies you actually want shortened — each one changes its public URLs site-wide.", 'remove-taxonomy-url' ),
				'type'    => 'multicheck',
				'options' => $all_taxonomies,
			),
			array(
				'name'  => 'rtu_enable_redirect',
				'label' => esc_html__( '301 redirect old URLs', 'remove-taxonomy-url' ),
				'desc'  => esc_html__( 'When ON, the old /genre/rock/ address permanently (301) redirects to the new /rock/. Turn this ON for SEO. Only turn ON once the short URLs are confirmed working — if a short URL is broken, this redirects visitors straight into a 404.', 'remove-taxonomy-url' ),
				'type'  => 'checkbox',
			),
			array(
				'name'  => 'rtu_enable_pagination',
				'label' => esc_html__( 'Pagination support', 'remove-taxonomy-url' ),
				'desc'  => esc_html__( 'Turn ON if your taxonomy archives split across multiple pages (page 2, 3…). Keeps /rock/page/2/ working after the base slug is removed. Safe to leave ON.', 'remove-taxonomy-url' ),
				'type'  => 'checkbox',
			),
			array(
				'name'  => 'rtu_enable_hierarchy',
				'label' => esc_html__( 'Hierarchical term URLs', 'remove-taxonomy-url' ),
				'desc'  => esc_html__( 'Controls how CHILD terms look. OFF: a child term uses a flat URL — /punk/. ON: a child term uses its full parent path — /rock/punk/ — and the flat /punk/ permanently redirects to it. Top-level terms are unaffected either way. Turning this ON changes child-term URLs site-wide; existing flat links keep working via redirect.', 'remove-taxonomy-url' ),
				'type'  => 'checkbox',
			),
			array(
				'name'    => 'rtu_enable_collision',
				'label'   => esc_html__( 'Conflict detection on save', 'remove-taxonomy-url' ),
				'desc'    => esc_html__( "Leave ON. When you save, it checks whether a shortened term URL (e.g. /rock/) clashes with an existing page, post, or another term with the same slug, and warns you. It won't block the save. Use the Health Check tab to scan everything at once.", 'remove-taxonomy-url' ),
				'type'    => 'checkbox',
				'default' => 'on',
			),
		);

		return $settings_fields;
	}


	/**
	 * Returns all setting page
	 */
	public function rtu_settings_page() {
		echo '<div class="wrap">';

		$rtu_version   = defined( 'REMOVE_TAXONOMY_URL_VERSION' ) ? REMOVE_TAXONOMY_URL_VERSION : '';
		$rtu_flushed   = class_exists( 'RTU_Options' ) ? RTU_Options::get_last_flushed() : 0;
		$rtu_flushed_h = $rtu_flushed ? sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 mins" */
			esc_html__( '%s ago', 'remove-taxonomy-url' ),
			human_time_diff( $rtu_flushed, time() )
		) : esc_html__( 'never', 'remove-taxonomy-url' );

		echo '<p style="margin:.5em 0;color:#50575e;">';
		echo esc_html__( 'After changing any setting here, the plugin re-flushes your permalinks automatically. If a URL still shows 404, go to Settings → Permalinks and click Save once.', 'remove-taxonomy-url' );
		echo '<br><strong>' . esc_html__( 'Version:', 'remove-taxonomy-url' ) . '</strong> ' . esc_html( $rtu_version )
			. ' &nbsp;|&nbsp; <strong>' . esc_html__( 'Last flushed:', 'remove-taxonomy-url' ) . '</strong> ' . esc_html( $rtu_flushed_h );
		echo '</p>';

		$this->settings_api->show_navigation();
		echo '<div id="rtu-settings-wrapper">';
		$this->settings_api->show_forms();
		echo '</div>';

		$health_partial = plugin_dir_path( __FILE__ ) . 'rtu-health-check.php';
		if ( file_exists( $health_partial ) ) {
			include $health_partial;
		}

		echo '</div>';
	}

	/**
	 * Get all the pages
	 *
	 * @return array page names with key value pairs
	 */
	public function get_pages() {
		$pages         = get_pages();
		$pages_options = array();
		if ( $pages ) {
			foreach ( $pages as $page ) {
				$pages_options[ $page->ID ] = $page->post_title;
			}
		}

		return $pages_options;
	}
}
