<?php
/**
 * Module 12 — Backup Cleanup.
 *
 * Detects backup archives from the six named plugins by their well-known
 * default storage folders. None of these plugins are installed in this
 * environment to verify against directly, so detection is glob-based
 * against each plugin's documented default path (including BackWPup's
 * randomized-suffix folder, matched with a wildcard) rather than requiring
 * the plugin's own code to be loaded — same honest-best-effort approach as
 * Module 9's table detection. A folder existing with matching file
 * extensions is treated as "found"; nothing here parses a plugin's own
 * backup manifest format.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Backup_Cleanup {

	private static function candidate_globs() {
		$uploads = wp_upload_dir();

		return array(
			array(
				'plugin' => 'UpdraftPlus',
				'glob'   => trailingslashit( WP_CONTENT_DIR ) . 'updraft',
			),
			array(
				'plugin' => 'Duplicator',
				'glob'   => trailingslashit( WP_CONTENT_DIR ) . 'backups-dup-lite',
			),
			array(
				'plugin' => 'Duplicator Pro',
				'glob'   => trailingslashit( $uploads['basedir'] ) . 'duplicator-packages',
			),
			array(
				'plugin' => 'Solid Backup / BackupBuddy',
				'glob'   => trailingslashit( $uploads['basedir'] ) . 'backupbuddy_backups',
			),
			array(
				'plugin' => 'BackWPup',
				'glob'   => trailingslashit( $uploads['basedir'] ) . 'backwpup*',
			),
			array(
				'plugin' => 'All-in-One WP Migration',
				'glob'   => trailingslashit( $uploads['basedir'] ) . 'ai1wm-backups',
			),
			array(
				'plugin' => 'WPvivid',
				'glob'   => trailingslashit( $uploads['basedir'] ) . 'wpvivid-backups',
			),
		);
	}

	private static function backup_extensions() {
		return array( 'zip', 'gz', 'tar', 'sql', 'daf', 'wpress' );
	}

	/**
	 * Grouped by detected plugin — for the admin screen's per-plugin sections.
	 */
	public static function find_backup_dirs() {
		$out = array();

		foreach ( self::candidate_globs() as $candidate ) {
			foreach ( glob( $candidate['glob'], GLOB_ONLYDIR ) ?: array() as $dir ) {
				if ( ! is_dir( $dir ) || storage_sherpa_is_ignored_path( $dir ) ) {
					continue;
				}

				$files = self::files_in_dir( $dir );
				if ( empty( $files ) ) {
					continue;
				}

				$out[] = array(
					'plugin' => $candidate['plugin'],
					'path'   => $dir,
					'files'  => $files,
					'size'   => array_sum( wp_list_pluck( $files, 'size' ) ),
				);
			}
		}

		return $out;
	}

	/**
	 * Flat file list across every detected plugin's backup folder — used by
	 * Storage Analyzer/Cron for the "Backups" category total.
	 */
	public static function find_backups() {
		$all = array();

		foreach ( self::find_backup_dirs() as $group ) {
			foreach ( $group['files'] as $file ) {
				$file['plugin'] = $group['plugin'];
				$all[]          = $file;
			}
		}

		return $all;
	}

	private static function files_in_dir( $dir ) {
		$extensions = self::backup_extensions();
		$files      = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return $files;
		}

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$ext = strtolower( $item->getExtension() );
			if ( ! in_array( $ext, $extensions, true ) ) {
				continue;
			}

			$files[] = array(
				'path'     => $item->getPathname(),
				'label'    => $item->getFilename(),
				'size'     => $item->getSize(),
				'modified' => $item->getMTime(),
			);
		}

		return $files;
	}

	public static function delete_backup_file( $path ) {
		$path = storage_sherpa_normalize_path( $path );

		$allowed = false;
		foreach ( self::candidate_globs() as $candidate ) {
			$base = storage_sherpa_normalize_path( dirname( $candidate['glob'] ) );
			if ( 0 === strpos( $path, $base ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			return new WP_Error( 'ss_unsafe_path', __( 'This path is not a recognized backup location.', 'storage-sherpa' ) );
		}

		$size     = file_exists( $path ) ? filesize( $path ) : 0;
		$trash_id = SS_Trash::trash_file( $path, 'backups', basename( $path ) );

		if ( ! is_wp_error( $trash_id ) ) {
			storage_sherpa_log_cleanup( 'backups', 'delete_backup', 1, $size );
		}

		return $trash_id;
	}

	public static function delete_old_backups( $days = 30 ) {
		$cutoff = time() - ( (int) $days * DAY_IN_SECONDS );
		$count  = 0;
		$bytes  = 0;

		foreach ( self::find_backups() as $file ) {
			if ( $file['modified'] > $cutoff ) {
				continue;
			}

			$result = self::delete_backup_file( $file['path'] );
			if ( ! is_wp_error( $result ) ) {
				++$count;
				$bytes += $file['size'];
			}
		}

		if ( $count > 0 ) {
			storage_sherpa_log_cleanup( 'backups', 'delete_old_backups', $count, $bytes );
		}

		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}
}
