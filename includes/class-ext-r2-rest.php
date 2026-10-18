<?php
/**
 * REST endpoints for Extroverting R2 Media.
 *
 * Namespace: extroverting-r2/v1 — kept separate from extroverting/v1 (core)
 * so the media plugin can be activated independently.
 *
 *   GET  /media/{id}              enrichment status + meta for one attachment
 *   POST /media/{id}/enrich       trigger (re-)enrichment for one attachment
 *   GET  /health                  Worker reachability + auth diagnostic (admin)
 *
 * All routes use X-WP-Nonce + cookie auth (modern) instead of admin-ajax.
 *
 * @package Extroverting_R2_Media
 */

defined( 'ABSPATH' ) || exit;

class Ext_R2_Rest {

	const NS = 'extroverting-r2/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Expose enrichment meta to Gutenberg's media entity (so blocks can read it).
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	public function register_meta() {
		$keys = array(
			'_ext_ai_status'       => 'string',
			'_ext_ai_description'  => 'string',
			'_ext_ai_title'        => 'string',
			'_ext_ai_tags'         => 'array',
			'_ext_ai_alt_text'     => 'string',
			'_ext_ai_exif_status'  => 'string',
			'_ext_ai_error'        => 'string',
			'_ext_ai_enriched'     => 'string',
		);
		foreach ( $keys as $key => $type ) {
			$args = array(
				'single'        => true,
				'type'          => $type,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			);
			// Array meta must declare items schema or WP 5.3+ throws a notice.
			if ( 'array' === $type ) {
				$args['show_in_rest'] = array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				);
			} else {
				$args['show_in_rest'] = true;
			}
			register_post_meta( 'attachment', $key, $args );
		}
	}

	public function register_routes() {
		register_rest_route( self::NS, '/media/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_media' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NS, '/media/(?P<id>\d+)/enrich', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'enrich_media' ),
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'health' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	public function get_media( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'ext_r2_not_found', __( 'Attachment not found.', 'extroverting-r2-media' ), array( 'status' => 404 ) );
		}

		$keys = array( '_ext_ai_status', '_ext_ai_description', '_ext_ai_title', '_ext_ai_tags', '_ext_ai_alt_text', '_ext_ai_categories', '_ext_ai_objects', '_ext_ai_exif_status', '_ext_ai_exif_written', '_ext_ai_error', '_ext_ai_enriched', '_ext_ai_enqueued' );
		$meta = array();
		foreach ( $keys as $k ) {
			$meta[ ltrim( $k, '_' ) ] = get_post_meta( $id, $k, true );
		}

		return rest_ensure_response( array(
			'id'       => $id,
			'title'    => $post->post_title,
			'mime'     => $post->post_mime_type,
			'url'      => wp_get_attachment_url( $id ),
			'status'   => $meta['ext_ai_status'] ?: 'unknown',
			'enrichment' => $meta,
		) );
	}

	public function enrich_media( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'ext_r2_not_found', __( 'Attachment not found.', 'extroverting-r2-media' ), array( 'status' => 404 ) );
		}

		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return new WP_Error( 'ext_r2_bad_mime', __( 'Only JPEG, PNG, GIF and WebP are supported.', 'extroverting-r2-media' ), array( 'status' => 400 ) );
		}

		// Reset and schedule.
		delete_post_meta( $id, '_ext_ai_error' );
		update_post_meta( $id, '_ext_ai_status', 'pending' );
		update_post_meta( $id, '_ext_ai_needs_enrichment', 1 );
		wp_schedule_single_event( time() + 2, 'ext_ai_enrich_single', array( $id ) );
		spawn_cron();

		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'status' => 'pending' ) );
	}

	public function health( WP_REST_Request $req ) {
		$url   = get_option( 'ext_ai_worker_url', '' );
		$token = get_option( 'ext_ai_worker_token', '' );

		if ( ! $url ) {
			return new WP_Error( 'ext_r2_no_url', __( 'Worker URL not configured.', 'extroverting-r2-media' ), array( 'status' => 400 ) );
		}

		// Simple reachability check: GET /metadata/__health__ should 404 when reachable.
		$get = wp_remote_get( trailingslashit( $url ) . 'metadata/__health__', array( 'timeout' => 10 ) );
		if ( is_wp_error( $get ) ) {
			return rest_ensure_response( array( 'reachable' => false, 'error' => $get->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $get );
		return rest_ensure_response( array(
			'reachable' => true,
			'http_code' => $code,
			'worker_url' => $url,
			'has_token'  => ! empty( $token ),
			'verdict'    => 404 === $code ? 'Worker reachable (expected 404 for probe)' : 'Unexpected response — check Worker URL',
		) );
	}
}
