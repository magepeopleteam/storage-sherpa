<?php
/**
 * Module 4 — Large File Scanner.
 *
 * Walks wp-content (not just uploads — the spec's extension list includes
 * .sql/.log/.zip/.iso, which live in backups/cache/logs too) looking for
 * files matching the supported extension list, sorted largest first. The
 * bulk of that list — images, video, audio, PDFs, documents, archives — is
 * SS_Filetype_Analyzer's canonical category list, so a new extension only
 * needs adding in one place; .sql and .iso are the two extras this module
 * needs that don't belong to any File Type Analyzer category.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Large_File_Scanner {

	public static function extensions() {
		return array_values(
			array_unique(
				array_merge(
					SS_Filetype_Analyzer::all_extensions(),
					array( 'iso', 'sql' )
				)
			)
		);
	}

	public static function run_scan( $limit = 200, $time_budget = 20 ) {
		$rows = self::scan( $limit, $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_LARGE, $rows );
		return $rows;
	}

	public static function scan( $limit = 200, $time_budget = 20 ) {
		$start = microtime( true );
		$found = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return array();
		}

		$extensions = self::extensions();
		$upload_dir = wp_upload_dir();
		$attach_map = self::build_attachment_map();

		foreach ( $iterator as $file ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();

			if ( storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $extensions, true ) ) {
				continue;
			}

			$relative_to_uploads = ltrim( str_replace( storage_sherpa_normalize_path( $upload_dir['basedir'] ), '', storage_sherpa_normalize_path( $path ) ), '/' );

			$found[] = array(
				'attachment_id' => isset( $attach_map[ $relative_to_uploads ] ) ? $attach_map[ $relative_to_uploads ] : 0,
				'file_path'     => $path,
				'status'        => 'large',
				'reason'        => strtoupper( $ext ),
				'file_size'     => $file->getSize(),
			);
		}

		usort( $found, fn( $a, $b ) => $b['file_size'] <=> $a['file_size'] );

		return array_slice( $found, 0, $limit );
	}

	private static function build_attachment_map() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row->meta_value ] = (int) $row->post_id;
		}

		return $map;
	}
}
