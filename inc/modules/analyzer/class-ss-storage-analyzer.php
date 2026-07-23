<?php
/**
 * Module 1 — Storage Analyzer.
 *
 * Scans the whole install by category (Uploads, Database, Plugins, Themes,
 * Cache, Logs, Backups) and records one snapshot row per category per scan
 * into {prefix}ss_scan_snapshots — that table is both "today's dashboard
 * numbers" and the 30-day growth trend history in one place.
 *
 * Each category total delegates to the module that actually owns that
 * domain (SS_Log_Cleaner for logs, SS_Backup_Cleanup for backups, etc.)
 * rather than re-implementing detection here, so there is exactly one
 * canonical definition of "what counts as a log file" etc.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Storage_Analyzer {

	/**
	 * The category order shown on the dashboard's Storage Overview / pie chart.
	 */
	public static function scopes() {
		return array(
			'uploads'  => __( 'Uploads', 'storage-sherpa' ),
			'database' => __( 'Database', 'storage-sherpa' ),
			'plugins'  => __( 'Plugins', 'storage-sherpa' ),
			'themes'   => __( 'Themes', 'storage-sherpa' ),
			'cache'    => __( 'Cache', 'storage-sherpa' ),
			'logs'     => __( 'Logs', 'storage-sherpa' ),
			'backups'  => __( 'Backups', 'storage-sherpa' ),
		);
	}

	/**
	 * Runs every category scan, persists one snapshot row per category, and
	 * returns the fresh totals keyed by scope.
	 */
	public static function run_full_scan() {
		$results = array();

		foreach ( self::scopes() as $scope => $label ) {
			$method = 'scan_' . $scope;
			$data   = method_exists( __CLASS__, $method ) ? self::$method() : array(
				'size'  => 0,
				'count' => 0,
				'path'  => null,
			);

			$results[ $scope ] = array(
				'label' => $label,
				'size'  => $data['size'],
				'count' => $data['count'],
				'path'  => $data['path'],
			);

			self::save_snapshot( $scope, $label, $data['path'], $data['size'], $data['count'] );
		}

		update_option( 'storage_sherpa_last_scan', current_time( 'mysql' ) );

		return $results;
	}

	private static function save_snapshot( $scope, $label, $path, $size, $count ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'ss_scan_snapshots',
			array(
				'scope'      => $scope,
				'label'      => $label,
				'path'       => $path,
				'size_bytes' => $size,
				'item_count' => $count,
				'scanned_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	private static function scan_uploads() {
		$dir   = wp_upload_dir();
		$stats = storage_sherpa_dir_stats( $dir['basedir'] );

		return array(
			'size'  => $stats['size'],
			'count' => $stats['files'],
			'path'  => $dir['basedir'],
		);
	}

	private static function scan_plugins() {
		$stats = storage_sherpa_dir_stats( WP_PLUGIN_DIR );

		return array(
			'size'  => $stats['size'],
			'count' => $stats['files'],
			'path'  => WP_PLUGIN_DIR,
		);
	}

	private static function scan_themes() {
		$stats = storage_sherpa_dir_stats( get_theme_root() );

		return array(
			'size'  => $stats['size'],
			'count' => $stats['files'],
			'path'  => get_theme_root(),
		);
	}

	private static function scan_cache() {
		$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache';
		$stats     = storage_sherpa_dir_stats( $cache_dir );

		return array(
			'size'  => $stats['size'],
			'count' => $stats['files'],
			'path'  => $cache_dir,
		);
	}

	private static function scan_logs() {
		if ( ! class_exists( 'SS_Log_Cleaner' ) ) {
			return array(
				'size'  => 0,
				'count' => 0,
				'path'  => null,
			);
		}

		$logs = SS_Log_Cleaner::find_logs();

		return array(
			'size'  => array_sum( wp_list_pluck( $logs, 'size' ) ),
			'count' => count( $logs ),
			'path'  => null,
		);
	}

	private static function scan_backups() {
		if ( ! class_exists( 'SS_Backup_Cleanup' ) ) {
			return array(
				'size'  => 0,
				'count' => 0,
				'path'  => null,
			);
		}

		$backups = SS_Backup_Cleanup::find_backups();

		return array(
			'size'  => array_sum( wp_list_pluck( $backups, 'size' ) ),
			'count' => count( $backups ),
			'path'  => null,
		);
	}

	private static function scan_database() {
		global $wpdb;

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;

		// information_schema always returns its columns in uppercase on this
		// server regardless of how they're written in the query — explicit
		// lowercase AS aliases are required to get predictable property names.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name AS table_name, data_length AS data_length, index_length AS index_length, table_rows AS table_rows
				 FROM information_schema.TABLES
				 WHERE table_schema = %s",
				$db_name
			)
		);

		$size  = 0;
		$count = 0;

		foreach ( (array) $rows as $row ) {
			if ( 0 !== strpos( $row->table_name, $wpdb->prefix ) ) {
				continue;
			}

			$size += (int) $row->data_length + (int) $row->index_length;
			++$count;
		}

		return array(
			'size'  => $size,
			'count' => $count,
			'path'  => null,
		);
	}

	/**
	 * Totals from the most recently saved snapshot per scope — used by
	 * SS_Cron to diff "before this scan" vs "after this scan" for growth
	 * notifications, and by the dashboard when no fresh scan has run yet.
	 */
	public static function get_latest_snapshot_totals() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT s1.scope, s1.size_bytes
			 FROM {$wpdb->prefix}ss_scan_snapshots s1
			 INNER JOIN (
				 SELECT scope, MAX(id) AS max_id
				 FROM {$wpdb->prefix}ss_scan_snapshots
				 GROUP BY scope
			 ) s2 ON s1.scope = s2.scope AND s1.id = s2.max_id"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.

		$totals = array();

		foreach ( (array) $rows as $row ) {
			$totals[ $row->scope ] = (int) $row->size_bytes;
		}

		return $totals;
	}

	public static function get_latest_results() {
		$totals = self::get_latest_snapshot_totals();
		$scopes = self::scopes();
		$out    = array();

		foreach ( $scopes as $scope => $label ) {
			$out[ $scope ] = array(
				'label' => $label,
				'size'  => isset( $totals[ $scope ] ) ? $totals[ $scope ] : 0,
			);
		}

		return $out;
	}

	/**
	 * Daily totals (summed across all scopes) for the last N days, for the
	 * dashboard's "Storage Trend — Last 30 Days" line chart.
	 */
	public static function get_growth_history( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(scanned_at) AS scan_date, SUM(size_bytes) AS total_size
				 FROM {$wpdb->prefix}ss_scan_snapshots
				 WHERE scanned_at >= %s
				 GROUP BY DATE(scanned_at)
				 ORDER BY scan_date ASC",
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			function ( $row ) {
				return array(
					'date' => $row->scan_date,
					'size' => (int) $row->total_size,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Immediate child-directory sizes under $base, sorted largest first —
	 * powers the dashboard's "Largest Directories" widget.
	 */
	public static function get_largest_directories( $base, $limit = 10 ) {
		if ( ! is_dir( $base ) ) {
			return array();
		}

		$out = array();

		foreach ( scandir( $base ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $base . DIRECTORY_SEPARATOR . $entry;

			if ( ! is_dir( $path ) || storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			$stats = storage_sherpa_dir_stats( $path, 5 );

			$out[] = array(
				'path'  => $path,
				'label' => $entry,
				'size'  => $stats['size'],
				'files' => $stats['files'],
			);
		}

		usort( $out, fn( $a, $b ) => $b['size'] <=> $a['size'] );

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Overall recoverable space estimate: everything currently sitting in
	 * Safe Trash pending permanent delete, plus rough estimates from
	 * modules that expose a cheap count (orphan media, logs, backups).
	 */
	public static function get_recoverable_estimate() {
		$estimate = SS_Trash::total_trash_size();

		if ( class_exists( 'SS_Log_Cleaner' ) ) {
			$estimate += array_sum( wp_list_pluck( SS_Log_Cleaner::find_logs(), 'size' ) );
		}

		return $estimate;
	}

	/**
	 * A simple, documented heuristic (0-100): starts at 100 and subtracts
	 * penalty points for signals that correlate with "this install could use
	 * a cleanup" — not a scientific score, a directional indicator.
	 */
	public static function calculate_health_score() {
		$score = 100;

		$totals      = self::get_latest_snapshot_totals();
		$grand_total = array_sum( $totals );

		if ( $grand_total > 0 ) {
			$recoverable_ratio = self::get_recoverable_estimate() / $grand_total;
			$score             -= min( 40, round( $recoverable_ratio * 200 ) );
		}

		if ( class_exists( 'SS_Log_Cleaner' ) ) {
			$log_size = array_sum( wp_list_pluck( SS_Log_Cleaner::find_logs(), 'size' ) );
			if ( $log_size > 100 * MB_IN_BYTES ) {
				$score -= 10;
			}
		}

		if ( class_exists( 'SS_Orphan_Tables' ) ) {
			$orphan_tables = SS_Orphan_Tables::scan();
			$score        -= min( 15, count( $orphan_tables ) * 3 );
		}

		$trash_pending = SS_Trash::query(
			array(
				'restored' => 0,
				'limit'    => 500,
			)
		);
		if ( count( $trash_pending ) > 200 ) {
			$score -= 10;
		}

		return (int) max( 0, min( 100, $score ) );
	}
}
