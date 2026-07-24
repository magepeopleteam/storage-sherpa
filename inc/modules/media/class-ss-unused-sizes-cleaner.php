<?php
/**
 * Module 29 — Unused Image Sizes Cleaner.
 *
 * Every registered image size (`add_image_size()`, core's thumbnail/medium/
 * large) generates its own physical file per image. When a theme or plugin
 * that used to register a custom size is swapped out, WordPress has no
 * mechanism of its own to clean up the thumbnail files that size already
 * generated — they just sit there forever, and on an image-heavy site this
 * is routinely the single biggest chunk of recoverable storage (a decade of
 * theme changes each leaving their own thumbnail generation behind).
 *
 * A size only gets flagged when its generated file isn't also the file
 * backing any size that's *currently* registered — WordPress dedupes file
 * generation when two sizes resolve to identical crop dimensions, and a
 * still-registered size sharing that file makes it very much still in use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Unused_Sizes_Cleaner {

	public static function run_scan( $time_budget = 25 ) {
		$rows = self::scan( $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_UNUSED_SIZE, $rows );
		return $rows;
	}

	public static function scan( $time_budget = 25 ) {
		$start = microtime( true );

		$registered = array_keys( wp_get_registered_image_subsizes() );

		global $wpdb;

		$attachment_ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();

		foreach ( $attachment_ids as $id ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			$id       = (int) $id;
			$file     = get_attached_file( $id );
			$metadata = wp_get_attachment_metadata( $id );

			if ( ! $file || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
				continue;
			}

			$base_dir = trailingslashit( dirname( $file ) );

			$files_still_registered = array();
			foreach ( $metadata['sizes'] as $size_name => $size ) {
				if ( in_array( $size_name, $registered, true ) && ! empty( $size['file'] ) ) {
					$files_still_registered[ $size['file'] ] = true;
				}
			}

			foreach ( $metadata['sizes'] as $size_name => $size ) {
				if ( in_array( $size_name, $registered, true ) || empty( $size['file'] ) ) {
					continue;
				}

				if ( isset( $files_still_registered[ $size['file'] ] ) ) {
					continue;
				}

				$path = $base_dir . $size['file'];

				if ( ! file_exists( $path ) || storage_sherpa_is_ignored_path( $path ) ) {
					continue;
				}

				$rows[] = array(
					'attachment_id' => $id,
					'file_path'     => $path,
					'status'        => 'unused_size',
					'reason'        => $size_name,
					'file_size'     => filesize( $path ),
					'confidence'    => 90,
				);
			}
		}

		return $rows;
	}

	/**
	 * Trashes a single unused-size file *without* touching the parent
	 * attachment — this is why unused_size findings can never be routed
	 * through SS_Trash::trash_attachment() (that deletes the whole
	 * attachment). The attachment's `_wp_attachment_metadata` row is backed
	 * up to Safe Trash *before* the size entry is stripped from it, so
	 * restoring the batch puts both the file and the metadata entry back
	 * exactly as they were — the same "the trash row is the backup"
	 * mechanism Module 19 already uses for every other DB-row deletion.
	 */
	public static function trash_size_finding( $finding, $batch_id = null ) {
		global $wpdb;

		if ( ! $finding->attachment_id || ! $finding->file_path ) {
			return new WP_Error( 'ss_invalid_finding', __( 'Invalid unused-size finding.', 'storage-sherpa' ) );
		}

		if ( ! storage_sherpa_path_is_safe( $finding->file_path ) ) {
			return new WP_Error( 'ss_unsafe_path', __( 'Refusing to touch a path outside the WordPress install.', 'storage-sherpa' ) );
		}

		$meta_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata'",
				$finding->attachment_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $meta_row ) {
			SS_Trash::trash_db_row( $wpdb->postmeta, $meta_row, 'unused_sizes', $finding->reason, $batch_id );
		}

		$file_result = SS_Trash::trash_file( $finding->file_path, 'unused_sizes', basename( $finding->file_path ), $batch_id );

		if ( is_wp_error( $file_result ) ) {
			return $file_result;
		}

		$metadata = wp_get_attachment_metadata( $finding->attachment_id );
		if ( is_array( $metadata ) && isset( $metadata['sizes'][ $finding->reason ] ) ) {
			unset( $metadata['sizes'][ $finding->reason ] );
			wp_update_attachment_metadata( $finding->attachment_id, $metadata );
		}

		storage_sherpa_log_cleanup( 'unused_sizes', 'trash_size:' . $finding->attachment_id . ':' . $finding->reason, 1, $finding->file_size );

		return true;
	}
}
