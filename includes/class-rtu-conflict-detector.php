<?php
/**
 * Slug collision detector — warns when term slugs clash with pages/posts/other terms.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Detects slug collisions between selected taxonomies' terms and other public
 * post types or other taxonomies' terms. Surfaces warnings on settings save and
 * exposes an on-demand AJAX audit for the Health Check UI.
 */
class RTU_Conflict_Detector {

	const AJAX_ACTION = 'rtu_run_audit';
	const NONCE       = 'rtu_audit_nonce';

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_filter( 'pre_update_option_rtu_basics', $this, 'warn_on_save', 10, 2 );
		$loader->add_action( 'wp_ajax_' . self::AJAX_ACTION, $this, 'ajax_run_audit' );
	}

	/**
	 * Find slug collisions across the given taxonomies.
	 *
	 * @param string[] $taxonomies Taxonomy slugs to audit.
	 * @return array[] List of collision rows: [ 'taxonomy', 'slug', 'conflicts' => [ ... ] ].
	 */
	public function find_collisions( $taxonomies ) {
		$taxonomies = array_values( array_filter( (array) $taxonomies, 'is_string' ) );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$term_slugs_by_tax = array();
		foreach ( $taxonomies as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'fields'     => 'id=>slug',
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$term_slugs_by_tax[ $tax ] = array_values( $terms );
		}

		$collisions = array();
		foreach ( $term_slugs_by_tax as $tax => $slugs ) {
			foreach ( $slugs as $slug ) {
				$conflicts = $this->collisions_for_slug( $slug, $tax, $term_slugs_by_tax );
				if ( ! empty( $conflicts ) ) {
					$collisions[] = array(
						'taxonomy'  => $tax,
						'slug'      => $slug,
						'conflicts' => $conflicts,
					);
				}
			}
		}

		return $collisions;
	}

	/**
	 * Resolve every collision target for a single term slug.
	 *
	 * @param string $slug              Term slug to test.
	 * @param string $own_tax           Taxonomy the slug belongs to.
	 * @param array  $term_slugs_by_tax Map of taxonomy => slug list, used for cross-tax checks.
	 * @return array[] List of conflict descriptors { type, label }.
	 */
	private function collisions_for_slug( $slug, $own_tax, $term_slugs_by_tax ) {
		global $wpdb;
		$conflicts = array();

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! empty( $post_types ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is built from an internal count, values still flow through prepare().
			$sql = $wpdb->prepare(
				"SELECT ID, post_title, post_type FROM {$wpdb->posts}
				 WHERE post_name = %s AND post_status = 'publish' AND post_type IN ($placeholders)",
				array_merge( array( $slug ), array_values( $post_types ) )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql was built with prepare() above.
			$matches = $wpdb->get_results( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $matches as $m ) {
				$conflicts[] = array(
					'type'  => $m->post_type,
					'label' => $m->post_title,
				);
			}
		}

		foreach ( $term_slugs_by_tax as $other_tax => $slugs ) {
			if ( $other_tax === $own_tax ) {
				continue;
			}
			if ( in_array( $slug, $slugs, true ) ) {
				$conflicts[] = array(
					'type'  => 'taxonomy:' . $other_tax,
					'label' => sprintf( 'Term in %s', $other_tax ),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Surface a warning notice on settings save if collisions are present.
	 * Filter callback for pre_update_option_rtu_basics. Never blocks the save.
	 *
	 * @param mixed $new_value Incoming option value.
	 * @param mixed $old_value Previously stored value (unused).
	 * @return mixed Unmodified $new_value.
	 */
	public function warn_on_save( $new_value, $old_value ) {
		unset( $old_value );
		if ( ! RTU_Options::is_feature_enabled( 'rtu_enable_collision' ) ) {
			return $new_value;
		}
		$taxonomies = ( is_array( $new_value ) && isset( $new_value['rtu_post_types'] ) )
			? (array) $new_value['rtu_post_types']
			: array();
		$collisions = $this->find_collisions( $taxonomies );
		if ( ! empty( $collisions ) ) {
			add_settings_error(
				'rtu_basics',
				'rtu_collision',
				sprintf(
					/* translators: %d: number of colliding term slugs */
					esc_html__( 'Warning: %d term slug(s) conflict with existing pages/posts/terms. Visit the Health Check tab.', 'remove-taxonomy-url' ),
					count( $collisions )
				),
				'warning'
			);
		}
		return $new_value;
	}

	/**
	 * AJAX endpoint for the Health Check audit.
	 *
	 * @return void
	 */
	public function ajax_run_audit() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$collisions = $this->find_collisions( RTU_Options::get_active_taxonomies() );
		wp_send_json_success( array( 'collisions' => $collisions ) );
	}
}
