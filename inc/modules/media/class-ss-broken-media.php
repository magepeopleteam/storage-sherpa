<?php
/**
 * Module 7 — Broken Media.
 *
 * Finds attachment rows whose physical file no longer exists on disk (the
 * inverse of orphan media: a real file with no DB row isn't broken, it's
 * just an untracked upload — this module only looks at attachments that
 * *do* have a DB row). Offers three fixes: delete the dangling attachment,
 * reconnect it to a same-named file found elsewhere in uploads, or let the
 * admin upload a replacement (handled by the admin screen's normal media
 * uploader, then reconnect() re-points the existing attachment at it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Broken_Media {

	public static function run_scan( $time_budget = 20 ) {
		$rows = self::scan( $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_BROKEN, $rows );
		return $rows;
	}

	public static function scan( $time_budget = 20 ) {
		global $wpdb;

		$start = microtime( true );

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title, pm.meta_value AS relative_path
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$upload_dir = wp_upload_dir();
		$broken     = array();

		foreach ( $rows as $row ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			// External/remote-only attachments (no local file ever expected) are skipped.
			if ( preg_match( '#^https?://#i', $row->relative_path ) ) {
				continue;
			}

			$path = trailingslashit( $upload_dir['basedir'] ) . $row->relative_path;

			if ( file_exists( $path ) ) {
				continue;
			}

			$broken[] = array(
				'attachment_id' => (int) $row->ID,
				'file_path'     => $path,
				'status'        => 'broken',
				'reason'        => 'missing_file:' . $row->relative_path,
				'file_size'     => 0,
			);
		}

		return $broken;
	}

	/**
	 * Looks for exactly one same-named file anywhere under uploads/ — a
	 * common signature of a file that got moved rather than truly deleted.
	 * Returns the candidate relative path, or null if zero or multiple matches.
	 */
	public static function suggest_reconnect( $attachment_id ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return null;
		}

		$basename   = basename( $file );
		$upload_dir = wp_upload_dir();
		$matches    = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $upload_dir['basedir'], FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return null;
		}

		foreach ( $iterator as $candidate ) {
			if ( $candidate->isFile() && $candidate->getFilename() === $basename ) {
				$matches[] = storage_sherpa_normalize_path( $candidate->getPathname() );
				if ( count( $matches ) > 1 ) {
					return null; // Ambiguous — let the admin pick manually instead of guessing.
				}
			}
		}

		if ( 1 !== count( $matches ) ) {
			return null;
		}

		return ltrim( str_replace( storage_sherpa_normalize_path( $upload_dir['basedir'] ), '', $matches[0] ), '/' );
	}

	public static function reconnect( $attachment_id, $relative_path ) {
		$upload_dir = wp_upload_dir();
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/' );

		if ( ! file_exists( $full_path ) || ! storage_sherpa_path_is_safe( $full_path ) ) {
			return new WP_Error( 'ss_invalid_target', __( 'Target file does not exist.', 'storage-sherpa' ) );
		}

		update_post_meta( $attachment_id, '_wp_attached_file', ltrim( $relative_path, '/' ) );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $full_path ) );

		return true;
	}

	/**
	 * Backs up the attachment's post row + postmeta into Safe Trash, then
	 * deletes the dangling attachment record (there's no physical file left
	 * to move — only the DB row is being removed).
	 */
	public static function delete( $attachment_id ) {
		global $wpdb;

		$post = get_post( $attachment_id, ARRAY_A );
		if ( ! $post ) {
			return new WP_Error( 'ss_not_found', __( 'Attachment not found.', 'storage-sherpa' ) );
		}

		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $attachment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		SS_Trash::trash_db_row(
			$wpdb->posts,
			$post,
			'broken_media',
			$post['post_title'] ? $post['post_title'] : ( 'attachment #' . $attachment_id )
		);

		foreach ( (array) $meta as $meta_row ) {
			SS_Trash::trash_db_row( $wpdb->postmeta, array_merge( array( 'post_id' => $attachment_id ), $meta_row ), 'broken_media', $meta_row['meta_key'] );
		}

		wp_delete_post( $attachment_id, true );

		return true;
	}
}
