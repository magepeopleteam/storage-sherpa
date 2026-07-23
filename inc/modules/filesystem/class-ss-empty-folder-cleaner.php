<?php
/**
 * Module 6 — Empty Folder Cleaner.
 *
 * An empty directory holds no data, so removing one is non-destructive by
 * definition — there is nothing for Safe Trash to preserve. This is the one
 * cleanup module that acts directly rather than routing through SS_Trash,
 * but every removal is still recorded to {prefix}ss_cleanup_log for the
 * audit trail (Module 21 — Reports).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Empty_Folder_Cleaner {

	/**
	 * Directories that must never be removed even if currently empty —
	 * WordPress or Storage Sherpa itself expects them to exist.
	 */
	private static function protected_dirs() {
		$upload_dir = wp_upload_dir();

		return array_map(
			'storage_sherpa_normalize_path',
			array(
				WP_CONTENT_DIR,
				WP_PLUGIN_DIR,
				get_theme_root(),
				$upload_dir['basedir'],
				SS_Install::trash_dir(),
			)
		);
	}

	public static function scan( $time_budget = 15 ) {
		$start     = microtime( true );
		$protected = self::protected_dirs();
		$empty     = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( Exception $e ) {
			return array();
		}

		foreach ( $iterator as $item ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			if ( ! $item->isDir() ) {
				continue;
			}

			$path = storage_sherpa_normalize_path( $item->getPathname() );

			if ( in_array( $path, $protected, true ) || storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			$listing = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- race-safe existence probe.
			if ( false === $listing ) {
				continue;
			}

			$real_entries = array_diff( $listing, array( '.', '..' ) );

			if ( empty( $real_entries ) ) {
				$empty[] = $path;
			}
		}

		return $empty;
	}

	public static function clean( $run_type = 'manual', $paths = null ) {
		$targets = null === $paths ? self::scan() : $paths;
		$removed = 0;

		foreach ( $targets as $path ) {
			$path = storage_sherpa_normalize_path( $path );

			if ( in_array( $path, self::protected_dirs(), true ) || ! storage_sherpa_path_is_safe( $path ) ) {
				continue;
			}

			if ( is_dir( $path ) && @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- non-empty-by-race is a benign no-op.
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			storage_sherpa_log_cleanup( 'empty_folders', 'remove_empty_folders', $removed, 0, $run_type );
		}

		return $removed;
	}
}
