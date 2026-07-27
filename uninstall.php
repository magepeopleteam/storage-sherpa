<?php
/**
 * Fires only when the user deletes the plugin from wp-admin (not on
 * deactivate). Removes the plugin's own tables/options and the Safe Trash
 * directory. Never touches user content outside what Storage Sherpa itself
 * created — media, posts, and other plugins' data are always left alone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'ss_scan_snapshots',
	$wpdb->prefix . 'ss_media_findings',
	$wpdb->prefix . 'ss_trash_items',
	$wpdb->prefix . 'ss_cleanup_log',
	$wpdb->prefix . 'ss_ignore_rules',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, not user input.
}

delete_option( 'storage_sherpa_settings' );
delete_option( 'storage_sherpa_db_version' );
delete_option( 'storage_sherpa_orphan_scan_incomplete' );

$trash_dir = trailingslashit( WP_CONTENT_DIR ) . 'storage-sherpa-trash';

if ( is_dir( $trash_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}

	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $trash_dir, true );
	}
}

wp_clear_scheduled_hook( 'storage_sherpa_daily_event' );
wp_clear_scheduled_hook( 'storage_sherpa_weekly_event' );
wp_clear_scheduled_hook( 'storage_sherpa_monthly_event' );
wp_clear_scheduled_hook( 'storage_sherpa_trash_sweep_event' );
