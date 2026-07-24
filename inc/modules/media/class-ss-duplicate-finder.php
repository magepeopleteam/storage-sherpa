<?php
/**
 * Module 3 — Duplicate Media Finder.
 *
 * Groups attachments by exact file size first (cheap), then only hashes
 * files that share a size with at least one other file — avoids hashing
 * every attachment on large libraries. SHA-256 by default (MD5 is offered
 * as a faster/legacy option via the same method, both collision-safe enough
 * for "is this file byte-identical" purposes).
 *
 * Filters candidates by file extension against SS_Filetype_Analyzer's
 * canonical list (via SS_Large_File_Scanner::extensions()) rather than
 * post_mime_type — covers every non-image type those modules already know
 * about (PDFs, video, audio, documents, archives), not just images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Duplicate_Finder {

	public static function run_scan( $algo = 'sha256', $time_budget = 25 ) {
		$rows = self::scan( $algo, $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_DUPLICATE, $rows );
		return $rows;
	}

	public static function scan( $algo = 'sha256', $time_budget = 25 ) {
		$start = microtime( true );
		$algo  = in_array( $algo, array( 'md5', 'sha256' ), true ) ? $algo : 'sha256';

		global $wpdb;

		$attachments = $wpdb->get_results(
			"SELECT p.ID, p.post_date, pm.meta_value AS relative_path
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$allowed_extensions = SS_Large_File_Scanner::extensions();
		$upload_dir         = wp_upload_dir();
		$by_size            = array();

		foreach ( $attachments as $row ) {
			$ext = strtolower( pathinfo( $row->relative_path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, $allowed_extensions, true ) ) {
				continue;
			}

			$path = trailingslashit( $upload_dir['basedir'] ) . $row->relative_path;

			if ( ! file_exists( $path ) || storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			$size = filesize( $path );
			if ( $size <= 0 ) {
				continue;
			}

			$by_size[ $size ][] = array(
				'id'   => (int) $row->ID,
				'path' => $path,
				'date' => $row->post_date,
			);
		}

		$results = array();

		foreach ( $by_size as $candidates ) {
			if ( count( $candidates ) < 2 ) {
				continue; // Unique size, can't be a duplicate of anything.
			}

			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			$by_hash = array();

			foreach ( $candidates as $file ) {
				$hash = 'sha256' === $algo ? hash_file( 'sha256', $file['path'] ) : md5_file( $file['path'] );
				if ( false === $hash ) {
					continue;
				}
				$by_hash[ $hash ][] = $file;
			}

			foreach ( $by_hash as $hash => $group ) {
				if ( count( $group ) < 2 ) {
					continue;
				}

				// Oldest (by post_date, ties broken by lowest ID) is treated as the original.
				usort( $group, fn( $a, $b ) => strcmp( $a['date'] . $a['id'], $b['date'] . $b['id'] ) );

				$original = array_shift( $group );

				$results[] = array(
					'attachment_id' => $original['id'],
					'file_path'     => $original['path'],
					'status'        => 'original',
					'reason'        => sprintf( '%d duplicate(s) found', count( $group ) ),
					'file_size'     => filesize( $original['path'] ),
					'group_hash'    => $hash,
				);

				foreach ( $group as $dupe ) {
					$results[] = array(
						'attachment_id' => $dupe['id'],
						'file_path'     => $dupe['path'],
						'status'        => 'duplicate',
						'reason'        => 'duplicate_of:' . $original['id'],
						'file_size'     => filesize( $dupe['path'] ),
						'group_hash'    => $hash,
					);
				}
			}
		}

		return $results;
	}

	public static function potential_savings() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(file_size) FROM {$wpdb->prefix}ss_media_findings WHERE finding_type = %s AND status = 'duplicate'",
				SS_Media_Findings::TYPE_DUPLICATE
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
