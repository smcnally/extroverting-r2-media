<?php
/**
 * Uninstall cleanup for Extroverting R2 Media.
 *
 * Removes plugin options and cron, but leaves attachment meta intact
 * (enrichment history) so a reinstall can resume. To fully purge meta,
 * run: wp post meta delete --all _ext_ai_*
 *
 * @package Extroverting_R2_Media
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ext_ai_worker_url' );
delete_option( 'ext_ai_worker_token' );
delete_option( 'ext_exiftool_binary' );

wp_clear_scheduled_hook( 'ext_ai_enrichment_cron' );
wp_clear_scheduled_hook( 'ext_ai_enrich_single' );
