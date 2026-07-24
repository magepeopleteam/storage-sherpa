<?php
/**
 * Module 21 — Reports.
 *
 * Every cleanup action across every module already writes one row to
 * {prefix}ss_cleanup_log via storage_sherpa_log_cleanup() at the moment it
 * runs (see SS_Trash::trash_attachment() and friends) — this module only
 * reads that table back out. No new write path, no new data collection;
 * this was previously "deferred" purely for lack of a UI on top of data
 * that was already being recorded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Reports {

	public static function total_saved() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT SUM(bytes_freed) FROM {$wpdb->prefix}ss_cleanup_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function total_items_cleaned() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT SUM(items_count) FROM {$wpdb->prefix}ss_cleanup_log" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Bytes freed / items removed / number of runs, grouped by module —
	 * the "which cleanup actually saved the most space" breakdown.
	 */
	public static function by_module() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT module, SUM(bytes_freed) AS bytes, SUM(items_count) AS items, COUNT(*) AS runs
			 FROM {$wpdb->prefix}ss_cleanup_log
			 GROUP BY module
			 ORDER BY bytes DESC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			function ( $row ) {
				return array(
					'module' => $row->module,
					'bytes'  => (int) $row->bytes,
					'items'  => (int) $row->items,
					'runs'   => (int) $row->runs,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Bytes freed per day over the last N days — the "space saved over
	 * time" counterpart to the dashboard's 30-day storage growth trend.
	 */
	public static function savings_history( $days = 30 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, SUM(bytes_freed) AS bytes
				 FROM {$wpdb->prefix}ss_cleanup_log
				 WHERE created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC",
				$since
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			function ( $row ) {
				return array(
					'date'  => $row->day,
					'bytes' => (int) $row->bytes,
				);
			},
			(array) $rows
		);
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_cleanup_log ORDER BY created_at DESC LIMIT %d", $limit )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function summary() {
		return array(
			'total_saved'    => self::total_saved(),
			'total_items'    => self::total_items_cleaned(),
			'by_module'      => self::by_module(),
			'history'        => self::savings_history( 30 ),
		);
	}
}
