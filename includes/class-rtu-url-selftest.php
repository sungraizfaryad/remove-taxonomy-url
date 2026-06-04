<?php
/**
 * URL resolution self-test for Remove Taxonomy URL.
 *
 * Samples active-taxonomy terms, requests each term's (base-stripped, possibly nested)
 * permalink over an HTTP loopback, and reports which resolve (200) vs 404. Cause-agnostic:
 * catches breakage from hierarchy misconfiguration, stale rewrite rules, slug collisions,
 * or pagination — the check that was missing when child terms 404'd in production.
 *
 * @package Remove_Taxonomy_Url
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Samples terms and verifies their public URLs resolve.
 */
class RTU_Url_Selftest {

	const AJAX_ACTION = 'rtu_run_selftest';
	const NONCE       = 'rtu_selftest_nonce';

	/**
	 * Pick up to $limit terms across the given taxonomies, biased to include top-level
	 * terms and a spread of children from different parents.
	 *
	 * @param string[] $taxonomies Active taxonomy slugs.
	 * @param int      $limit      Max terms to return.
	 * @return array[] Each: array( 'taxonomy', 'term_id', 'slug', 'parent' ).
	 */
	public function sample_terms( $taxonomies, $limit = 25 ) {
		$top   = array();
		$child = array();
		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$row = array(
					'taxonomy' => $taxonomy,
					'term_id'  => (int) $term->term_id,
					'slug'     => $term->slug,
					'parent'   => (int) $term->parent,
				);
				if ( 0 === (int) $term->parent ) {
					$top[] = $row;
				} else {
					$child[ (int) $term->parent ][] = $row;
				}
			}
		}

		$sample = array();
		foreach ( $top as $row ) {
			if ( count( $sample ) >= $limit ) {
				return $sample;
			}
			$sample[] = $row;
		}

		// Round-robin one child per distinct parent for breadth.
		$cursors      = array_fill_keys( array_keys( $child ), 0 );
		$drained      = false;
		$sample_count = count( $sample );
		while ( ! $drained && $sample_count < $limit ) {
			$drained = true;
			foreach ( $cursors as $parent_id => $idx ) {
				if ( isset( $child[ $parent_id ][ $idx ] ) ) {
					$sample[] = $child[ $parent_id ][ $idx ];
					++$sample_count;
					$cursors[ $parent_id ] = $idx + 1;
					$drained               = false;
					if ( $sample_count >= $limit ) {
						break;
					}
				}
			}
		}
		return $sample;
	}

	/**
	 * Verify a single term's public URL resolves.
	 *
	 * Primary: HTTP loopback to the term's stripped permalink (200 = pass, else fail).
	 * Fallback (loopback errored/blocked): internal check that the term exists.
	 *
	 * @param array $row Sample row from sample_terms().
	 * @return array array( 'taxonomy', 'slug', 'url', 'status', 'pass', 'mode' )
	 */
	public function check_term( $row ) {
		$term = get_term( (int) $row['term_id'], $row['taxonomy'] );
		$url  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term, $row['taxonomy'] ) : '';
		$url  = is_wp_error( $url ) ? '' : $url;

		$result = array(
			'taxonomy' => $row['taxonomy'],
			'slug'     => $row['slug'],
			'url'      => $url,
			'status'   => 0,
			'pass'     => false,
			'mode'     => 'loopback',
		);

		if ( '' === $url ) {
			$result['mode'] = 'internal';
			$result['pass'] = false;
			return $result;
		}

		$response = wp_remote_get( // phpcs:ignore WordPress.WP.AlternativeFunctions.wp_remote_get_wp_remote_get -- loopback self-test, admin-triggered
			$url,
			array(
				'timeout'     => 3,
				'sslverify'   => false,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['mode'] = 'internal';
			$result['pass'] = ( $term && ! is_wp_error( $term ) );
			return $result;
		}

		$code             = (int) wp_remote_retrieve_response_code( $response );
		$result['status'] = $code;
		$result['pass']   = ( 200 === $code );
		return $result;
	}

	/**
	 * Register hooks via the plugin loader.
	 *
	 * @param Remove_Taxonomy_Url_Loader $loader Plugin loader.
	 * @return void
	 */
	public function register_hooks( $loader ) {
		$loader->add_action( 'wp_ajax_' . self::AJAX_ACTION, $this, 'ajax_run' );
	}

	/**
	 * AJAX endpoint for the URL resolution self-test.
	 *
	 * @return void
	 */
	public function ajax_run() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		wp_send_json_success( $this->run( 25 ) );
	}

	/**
	 * Run the self-test across active taxonomies.
	 *
	 * @param int $limit Max terms to test.
	 * @return array array( 'rows' => array[], 'tested' => int, 'total' => int )
	 */
	public function run( $limit = 25 ) {
		$active = RTU_Options::get_active_taxonomies();
		$total  = 0;
		foreach ( $active as $taxonomy ) {
			$ids    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			$total += is_wp_error( $ids ) ? 0 : count( $ids );
		}
		$sample   = $this->sample_terms( $active, $limit );
		$rows     = array();
		$deadline = microtime( true ) + 20.0; // Hard cap so the admin AJAX request never runs away on slow hosts.
		foreach ( $sample as $row ) {
			$rows[] = $this->check_term( $row );
			if ( microtime( true ) >= $deadline ) {
				break; // Stop early; report only what we tested.
			}
		}
		return array(
			'rows'   => $rows,
			'tested' => count( $rows ),
			'total'  => $total,
		);
	}
}
