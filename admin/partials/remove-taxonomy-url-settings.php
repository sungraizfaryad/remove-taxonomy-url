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
			[ $this, 'rtu_settings_page' ]
		);
	}


	/**
	 * Returns all Sections for settings
	 *
	 * @return array section fields
	 */
	private function get_settings_sections() {
		$sections[] = array(
			'id'    => 'rtu_basics',
			'title' => esc_html__( 'Custom Taxonomies URL Settings', 'remove-taxonomy-url' ),
			'desc'  => sprintf( esc_html__( '%1$s You need to save the Permalinks Twice after saving the settings otherwise you will face 404 error', 'remove-taxonomy-url' ), '<strong style="font-size: 1rem; color: red;">***IMPORTANT***</strong><br />' ),
		);

		return $sections;
	}

	/**
	 * Returns all the settings fields
	 *
	 * @return array settings fields
	 */
	private function get_settings_fields() {

		$all_post_types = get_taxonomies( array( '_builtin' => false ) );

		$settings_fields['rtu_basics'] = array(
			array(
				'name'    => 'rtu_post_types',
				'label'   => esc_html__( 'Taxonomies List', 'remove-taxonomy-url' ),
				'desc'    => esc_html__( 'Selected taxonomies slugs will be removed from URL.', 'remove-taxonomy-url' ),
				'type'    => 'multicheck',
				'options' => $all_post_types,
			),
		);

		return $settings_fields;
	}


	/**
	 * Returns all setting page
	 */
	public function rtu_settings_page() {
		echo '<div class="wrap">';
		$this->settings_api->show_navigation();
		echo '<div id="rtu-settings-wrapper">';
		$this->settings_api->show_forms();
		echo '</div>';
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
