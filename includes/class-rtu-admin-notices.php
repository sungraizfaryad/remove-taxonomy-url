<?php
/**
 * One-time 3.0 upgrade banner.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Renders a one-time upgrade banner after the 3.0 migration and handles its
 * dismissal via a nonced admin-post action.
 */
class RTU_Admin_Notices {

	const OPTION = 'rtu_30_notice_dismissed';
	const ACTION = 'rtu_dismiss_30_notice';
	const NONCE  = 'rtu_dismiss_30_notice_nonce';

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_action( 'admin_notices', $this, 'render' );
		$loader->add_action( 'admin_post_' . self::ACTION, $this, 'dismiss' );
	}

	/**
	 * Render the banner on Dashboard and the plugin settings page only.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Default value 1 means "do not show" — only the migration code arms the banner by setting it to 0.
		if ( (int) get_option( self::OPTION, 1 ) !== 0 ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$allowed = array( 'dashboard', 'settings_page_rtu_settings_page' );
		if ( ! in_array( $screen->id, $allowed, true ) ) {
			return;
		}
		$settings_url = admin_url( 'options-general.php?page=rtu_settings_page' );
		$dismiss_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION ),
			self::ACTION,
			self::NONCE
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Remove Taxonomy URL 3.0', 'remove-taxonomy-url' ); ?></strong>
				— <?php esc_html_e( 'New features available: 301 redirects, pagination fix, conflict detector. All disabled by default to preserve your current behavior.', 'remove-taxonomy-url' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Review settings', 'remove-taxonomy-url' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'remove-taxonomy-url' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the dismiss admin-post action.
	 *
	 * @return void
	 */
	public function dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, self::NONCE );
		update_option( self::OPTION, 1 );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}
}
