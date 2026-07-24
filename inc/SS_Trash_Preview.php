<?php
/**
 * Streams a trashed image file's raw bytes via the standard admin-post.php
 * pattern — same reasoning as SS_Trash_Export.php: a browser <img> tag needs
 * a genuine binary HTTP response, not a JSON envelope.
 *
 * This exists specifically because a trashed file lives in
 * wp-content/storage-sherpa-trash/, which is deliberately protected by a
 * "Deny from all" .htaccess (see SS_Install::protect_directory()) so a
 * trashed file can never be reached by guessing a URL — and, for anything
 * that came from the media library via SS_Trash::trash_attachment(), the
 * attachment post itself is already deleted by the time it's in Safe Trash,
 * so there's no WP attachment left to generate a thumbnail URL from either
 * way. This is the one authenticated, capability-checked path back to that
 * file's actual bytes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Trash_Preview {

	/**
	 * Deliberately a narrower list than SS_Filetype_Analyzer's "images"
	 * category: SVG is excluded on purpose — an <img> (or worse, a direct
	 * navigation) can execute embedded script in an SVG, and this endpoint
	 * streams a file straight from disk with no scan of its contents, so
	 * it's not the place to trust that a ".svg" extension is actually safe
	 * markup. AVIF/TIFF/BMP/ICO are excluded too since <img> support for
	 * them is inconsistent across browsers, not for a safety reason.
	 */
	const PREVIEWABLE_MIMES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	public static function init() {
		add_action( 'admin_post_storage_sherpa_trash_preview', array( __CLASS__, 'handle_preview' ) );
	}

	/**
	 * True if this trash entry is something handle_preview() can actually
	 * stream — used by the Recovery Center screen to decide whether to
	 * render a thumbnail or fall back to the plain file-path display.
	 */
	public static function is_previewable( $item ) {
		if ( ! $item || 'file' !== $item->item_type || ! $item->trashed_path ) {
			return false;
		}

		$ext = strtolower( pathinfo( $item->trashed_path, PATHINFO_EXTENSION ) );

		return isset( self::PREVIEWABLE_MIMES[ $ext ] );
	}

	public static function preview_url( $trash_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=storage_sherpa_trash_preview&id=' . (int) $trash_id ),
			'storage_sherpa_trash_preview_' . (int) $trash_id
		);
	}

	public static function handle_preview() {
		if ( ! storage_sherpa_current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'storage-sherpa' ), 403 );
		}

		$trash_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below via check_admin_referer().

		check_admin_referer( 'storage_sherpa_trash_preview_' . $trash_id );

		$item = SS_Trash::get( $trash_id );

		if ( ! self::is_previewable( $item ) || ! file_exists( $item->trashed_path ) ) {
			wp_die( esc_html__( 'Preview not available.', 'storage-sherpa' ), 404 );
		}

		$ext = strtolower( pathinfo( $item->trashed_path, PATHINFO_EXTENSION ) );

		nocache_headers();
		header( 'Content-Type: ' . self::PREVIEWABLE_MIMES[ $ext ] );
		header( 'Content-Length: ' . filesize( $item->trashed_path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $item->trashed_path ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $item->trashed_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- reading our own Safe Trash file after the checks above, not arbitrary user input.

		exit;
	}
}
