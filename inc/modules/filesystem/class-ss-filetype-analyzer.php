<?php
/**
 * Module 17 — File Type Analyzer.
 *
 * Buckets every file under wp-content by extension into the categories the
 * spec names for the dashboard's "Storage by type" widget. Anything not
 * matching a known extension falls into "unknown" — including audio, which
 * the spec's category list doesn't call out separately.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Filetype_Analyzer {

	public static function categories() {
		return array(
			'images'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tiff' ),
			'videos'    => array( 'mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv' ),
			'pdfs'      => array( 'pdf' ),
			'zip'       => array( 'zip', 'gz', 'tar', 'rar', '7z' ),
			'logs'      => array( 'log' ),
			'fonts'     => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
			'documents' => array( 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt' ),
		);
	}

	public static function labels() {
		return array(
			'images'    => __( 'Images', 'storage-sherpa' ),
			'videos'    => __( 'Videos', 'storage-sherpa' ),
			'pdfs'      => __( 'PDFs', 'storage-sherpa' ),
			'zip'       => __( 'ZIP', 'storage-sherpa' ),
			'logs'      => __( 'Logs', 'storage-sherpa' ),
			'fonts'     => __( 'Fonts', 'storage-sherpa' ),
			'documents' => __( 'Documents', 'storage-sherpa' ),
			'unknown'   => __( 'Unknown', 'storage-sherpa' ),
		);
	}

	private static function extension_map() {
		$map = array();
		foreach ( self::categories() as $bucket => $extensions ) {
			foreach ( $extensions as $ext ) {
				$map[ $ext ] = $bucket;
			}
		}
		return $map;
	}

	public static function scan( $time_budget = 20 ) {
		$start = microtime( true );
		$map   = self::extension_map();

		$totals = array();
		foreach ( array_keys( self::labels() ) as $bucket ) {
			$totals[ $bucket ] = array(
				'size'  => 0,
				'count' => 0,
			);
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return $totals;
		}

		foreach ( $iterator as $file ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			if ( ! $file->isFile() || storage_sherpa_is_ignored_path( $file->getPathname() ) ) {
				continue;
			}

			$ext    = strtolower( $file->getExtension() );
			$bucket = isset( $map[ $ext ] ) ? $map[ $ext ] : 'unknown';

			$totals[ $bucket ]['size']  += $file->getSize();
			$totals[ $bucket ]['count'] += 1;
		}

		return $totals;
	}
}
