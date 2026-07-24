<?php
/**
 * Module 17 — File Type Analyzer.
 *
 * Buckets every file under wp-content by extension into the categories the
 * spec names for the dashboard's "Storage by type" widget, plus 'audio' —
 * originally left uncategorized here, now broken out since Modules 2-4
 * (Orphan/Duplicate/Large File) need it as a real category too. Anything not
 * matching a known extension falls into "unknown". This is the single
 * canonical extension list other modules should call into rather than
 * maintaining their own — see SS_Large_File_Scanner::extensions().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Filetype_Analyzer {

	public static function categories() {
		return array(
			'images'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tiff' ),
			'videos'    => array( 'mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv' ),
			'audio'     => array( 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'wma' ),
			'pdfs'      => array( 'pdf' ),
			'zip'       => array( 'zip', 'gz', 'tar', 'rar', '7z', 'bz2' ),
			'logs'      => array( 'log' ),
			'fonts'     => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
			'documents' => array( 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt' ),
		);
	}

	public static function labels() {
		return array(
			'images'    => __( 'Images', 'storage-sherpa' ),
			'videos'    => __( 'Videos', 'storage-sherpa' ),
			'audio'     => __( 'Audio', 'storage-sherpa' ),
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

	/**
	 * Flattened, deduplicated list of every known extension across every
	 * category — the canonical list Modules 3/4 (Duplicate Finder, Large
	 * File Scanner) filter non-image files against instead of each keeping
	 * its own separately-maintained copy.
	 */
	public static function all_extensions() {
		$extensions = array();

		foreach ( self::categories() as $category ) {
			$extensions = array_merge( $extensions, $category );
		}

		return array_values( array_unique( $extensions ) );
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
