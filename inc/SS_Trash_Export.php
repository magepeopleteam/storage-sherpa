<?php
/**
 * Streams a Safe Trash ZIP export via the standard admin-post.php pattern.
 * A real browser download needs a genuine binary HTTP response, not a JSON
 * envelope — this is the one action in the plugin that deliberately
 * bypasses the REST API every other action uses, for exactly that reason.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Trash_Export {

	public static function init() {
		add_action( 'admin_post_storage_sherpa_export_trash', array( __CLASS__, 'handle_download' ) );
	}

	public static function handle_download() {
		if ( ! storage_sherpa_current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'storage-sherpa' ), 403 );
		}

		check_admin_referer( 'storage_sherpa_export_trash' );

		$zip_path = SS_Trash::export_zip();

		if ( is_wp_error( $zip_path ) ) {
			wp_die( esc_html( $zip_path->get_error_message() ), 400 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="storage-sherpa-trash-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );

		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a just-generated temp export file, not arbitrary user input.

		wp_delete_file( $zip_path );

		exit;
	}
}
