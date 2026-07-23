<?php
/**
 * Module 14 — Log Cleaner.
 *
 * Only ever looks inside the WordPress install directory (WP_CONTENT_DIR
 * and below) — genuine Apache/NGINX server logs normally live at
 * /var/log/... outside the docroot entirely, which a WordPress plugin has
 * no business (and usually no filesystem permission) touching. This module
 * also covers WooCommerce's uploads/wc-logs/*.log files, which is why
 * Module 8 (Database Cleanup) doesn't have a separate "WooCommerce logs"
 * category — those are files, not rows, so they belong here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Log_Cleaner {

	private static function known_filenames() {
		return array( 'debug.log', 'error_log', 'error.log', 'php_errors.log', 'php_error.log', 'access.log', 'fatal-errors.log' );
	}

	public static function find_logs( $time_budget = 15 ) {
		$start = microtime( true );
		$found = array();

		// The single most common location: WP_DEBUG_LOG defaults to wp-content/debug.log.
		$debug_log = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $debug_log ) ) {
			$found[] = self::to_row( $debug_log );
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return $found;
		}

		$known = self::known_filenames();

		foreach ( $iterator as $file ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$path     = $file->getPathname();
			$filename = $file->getFilename();
			$ext      = strtolower( $file->getExtension() );

			if ( storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			// Storage Sherpa's own Safe Trash directory never counts as a "log".
			if ( 0 === strpos( storage_sherpa_normalize_path( $path ), storage_sherpa_normalize_path( SS_Install::trash_dir() ) ) ) {
				continue;
			}

			$is_known_name = in_array( $filename, $known, true );
			$is_log_ext    = 'log' === $ext;
			$is_wc_log     = false !== strpos( storage_sherpa_normalize_path( $path ), '/uploads/wc-logs/' );

			if ( ! $is_known_name && ! $is_log_ext && ! $is_wc_log ) {
				continue;
			}

			if ( $debug_log === $path ) {
				continue; // Already added above.
			}

			$found[] = self::to_row( $path );
		}

		return $found;
	}

	private static function to_row( $path ) {
		return array(
			'path'     => $path,
			'label'    => basename( $path ),
			'size'     => file_exists( $path ) ? filesize( $path ) : 0,
			'modified' => file_exists( $path ) ? filemtime( $path ) : 0,
		);
	}

	public static function clean_single( $path ) {
		$path = storage_sherpa_normalize_path( $path );

		if ( 0 !== strpos( $path, storage_sherpa_normalize_path( WP_CONTENT_DIR ) ) ) {
			return new WP_Error( 'ss_unsafe_path', __( 'Refusing to touch a path outside wp-content.', 'storage-sherpa' ) );
		}

		$size     = file_exists( $path ) ? filesize( $path ) : 0;
		$trash_id = SS_Trash::trash_file( $path, 'logs', basename( $path ) );

		if ( ! is_wp_error( $trash_id ) ) {
			storage_sherpa_log_cleanup( 'logs', 'delete_log', 1, $size );
		}

		return $trash_id;
	}

	public static function clean_all( $run_type = 'manual' ) {
		$logs    = self::find_logs();
		$count   = 0;
		$bytes   = 0;

		foreach ( $logs as $log ) {
			$result = self::clean_single( $log['path'] );
			if ( ! is_wp_error( $result ) ) {
				++$count;
				$bytes += $log['size'];
			}
		}

		if ( $count > 0 ) {
			storage_sherpa_log_cleanup( 'logs', 'clean_all', $count, $bytes, $run_type );
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}
}
