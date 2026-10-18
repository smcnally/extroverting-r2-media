<?php
/**
 * Plugin Name:       Extroverting R2 Media
 * Plugin URI:        https://extroverting.example/
 * Description:       Cloudflare R2 + Workers AI enrichment for the WordPress Media Library: generates image descriptions, titles, and tags, stores them in post meta, and writes them back into source files via exiftool (PNG tEXt, EXIF, XMP incl. AltTextAccessibility).
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Mean Business
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       extroverting-r2-media
 * Domain Path:       /languages
 * Update URI:        https://github.com/smcnally/extroverting-r2-media
 *
 * @package Extroverting\R2Media
 */

defined( 'ABSPATH' ) || exit;

define( 'EXT_R2_MEDIA_VERSION', '0.1.0' );
define( 'EXT_R2_MEDIA_FILE', __FILE__ );
define( 'EXT_R2_MEDIA_DIR', plugin_dir_path( __FILE__ ) );

if ( ! class_exists( 'Ext_Exif_Writer' ) ) {
	require_once EXT_R2_MEDIA_DIR . 'includes/class-ext-exif-writer.php';
}

if ( ! class_exists( 'Ext_AI_Enrichment' ) ) {
	require_once EXT_R2_MEDIA_DIR . 'includes/class-ext-ai-enrichment.php';
}

if ( ! class_exists( 'Ext_R2_Rest' ) ) {
	require_once EXT_R2_MEDIA_DIR . 'includes/class-ext-r2-rest.php';
}

register_activation_hook( __FILE__, 'ext_r2_media_activate' );
register_deactivation_hook( __FILE__, 'ext_r2_media_deactivate' );

/**
 * Boot hooks (idempotent).
 *
 * @return void
 */
function ext_r2_media_boot() {
	static $booted = false;

	if ( $booted || ! class_exists( 'Ext_AI_Enrichment' ) ) {
		return;
	}

	$booted = true;

	load_plugin_textdomain( 'extroverting-r2-media', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	new Ext_AI_Enrichment();
	new Ext_R2_Rest();
	add_action( 'admin_init', 'ext_r2_media_register_settings' );
	add_action( 'enqueue_block_editor_assets', 'ext_r2_media_enqueue_editor_assets' );
	add_action( 'admin_enqueue_scripts', 'ext_r2_media_enqueue_media_modal' );
}
add_action( 'plugins_loaded', 'ext_r2_media_boot' );

/**
 * Enqueue a tiny editor script that shows enrichment status in the media modal.
 *
 * @return void
 */
function ext_r2_media_enqueue_media_modal() {
	$screen = get_current_screen();
	if ( ! $screen || 'attachment' !== $screen->id && 'upload' !== $screen->id ) {
		return;
	}
	wp_enqueue_script(
		'ext-r2-media-modal',
		plugins_url( 'assets/js/media-modal.js', __FILE__ ),
		array( 'jquery' ),
		EXT_R2_MEDIA_VERSION,
		true
	);
	wp_localize_script( 'ext-r2-media-modal', 'ExtR2Media', array(
		'restUrl'   => esc_url_raw( rest_url( 'extroverting-r2/v1/' ) ),
		'restNonce' => wp_create_nonce( 'wp_rest' ),
	) );
}

/**
 * Enqueue block-editor panel for attachment enrichment status.
 *
 * @return void
 */
function ext_r2_media_enqueue_editor_assets() {
	wp_enqueue_script(
		'ext-r2-media-editor',
		plugins_url( 'assets/js/editor-panel.js', __FILE__ ),
		array( 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
		EXT_R2_MEDIA_VERSION,
		true
	);
}

/**
 * Activation: seed default options and schedule the batch cron.
 *
 * @return void
 */
function ext_r2_media_activate() {
	add_option( 'ext_ai_worker_url', 'https://extroverting-media-enrichment.steve-8da.workers.dev' );
	add_option( 'ext_ai_worker_token', '' );
	add_option( 'ext_exiftool_binary', 'exiftool' );

	if ( ! wp_next_scheduled( 'ext_ai_enrichment_cron' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ext_ai_enrichment_cron' );
	}
}

/**
 * Deactivation: clear scheduled events.
 *
 * @return void
 */
function ext_r2_media_deactivate() {
	wp_clear_scheduled_hook( 'ext_ai_enrichment_cron' );
	wp_clear_scheduled_hook( 'ext_ai_enrich_single' );
}

/**
 * Register settings so the Worker URL, token, and exiftool binary can be set
 * from Settings > Media in wp-admin.
 *
 * @return void
 */
function ext_r2_media_register_settings() {
	// NOTE: the group must match the settings page slug ('media') — options.php
// only saves options whitelisted under the page's own group.
	register_setting(
		'media',
		'ext_ai_worker_url',
		[
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
		]
	);
	register_setting( 'media', 'ext_ai_worker_token', [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] );
	register_setting(
		'media',
		'ext_exiftool_binary',
		[
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		]
	);

	add_settings_section( 'ext_r2_media_main', 'Cloudflare R2 Media', '__return_false', 'media' );

	add_settings_field( 'ext_ai_worker_url', 'Worker URL', 'ext_r2_media_field_url', 'media', 'ext_r2_media_main' );
	add_settings_field( 'ext_ai_worker_token', 'Worker Token', 'ext_r2_media_field_token', 'media', 'ext_r2_media_main' );
	add_settings_field( 'ext_exiftool_binary', 'exiftool Binary', 'ext_r2_media_field_binary', 'media', 'ext_r2_media_main' );
}

/**
 * Worker URL field.
 *
 * @return void
 */
function ext_r2_media_field_url() {
	printf(
		'<input type="url" class="regular-text" name="ext_ai_worker_url" value="%s" placeholder="https://<worker-name>.<account-subdomain>.workers.dev" />
	<p class="description">Full deployed URL including the account subdomain, e.g. https://extroverting-media-enrichment.steve-8da.workers.dev — a URL without the account subdomain will not resolve.</p>',
		esc_attr( get_option( 'ext_ai_worker_url', '' ) )
	);
}

/**
 * Worker token field.
 *
 * @return void
 */
function ext_r2_media_field_token() {
	printf(
		'<input type="password" class="regular-text" name="ext_ai_worker_token" value="%s" autocomplete="off" />',
		esc_attr( get_option( 'ext_ai_worker_token', '' ) )
	);
}

/**
 * exiftool binary field.
 *
 * @return void
 */
function ext_r2_media_field_binary() {
	printf(
		'<input type="text" class="regular-text" name="ext_exiftool_binary" value="%s" />',
		esc_attr( get_option( 'ext_exiftool_binary', 'exiftool' ) )
	);
	echo '<p class="description">Binary name resolved on PATH, or an absolute path. The web server user must be able to execute it.</p>';
}