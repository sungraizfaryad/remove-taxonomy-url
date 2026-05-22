<?php
/**
 * Health Check section — slug collision audit UI.
 * Included by Remove_Taxonomy_Url_Settings::rtu_settings_page() beneath the main form.
 *
 * @package Remove_Taxonomy_Url
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$rtu_audit_nonce = wp_create_nonce( RTU_Conflict_Detector::NONCE );
?>
<div id="rtu-health-check" style="margin-top: 2em; padding-top: 1em; border-top: 1px solid #ccd0d4;">
	<h2><?php esc_html_e( 'Health Check', 'remove-taxonomy-url' ); ?></h2>
	<p><?php esc_html_e( 'Audit your selected taxonomies for slug collisions with pages, posts, or other terms before they break URLs in production.', 'remove-taxonomy-url' ); ?></p>
	<p>
		<button type="button" class="button button-primary" id="rtu-run-audit"
			data-nonce="<?php echo esc_attr( $rtu_audit_nonce ); ?>"
			data-action="<?php echo esc_attr( RTU_Conflict_Detector::AJAX_ACTION ); ?>">
			<?php esc_html_e( 'Run audit', 'remove-taxonomy-url' ); ?>
		</button>
		<span class="spinner" style="float:none;"></span>
	</p>
	<div id="rtu-audit-results"></div>
</div>
