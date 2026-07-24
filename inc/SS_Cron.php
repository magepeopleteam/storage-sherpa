<?php
/**
 * Module 20 — Scheduled Scans (+ the daily Safe Trash retention sweep).
 *
 * Runs a full storage scan on the configured cadence (daily/weekly/monthly),
 * records a trend snapshot, fires Module 22 notifications by comparing
 * against the previous snapshot, optionally runs auto-cleanup for whichever
 * categories are opted in under Settings, and emails a report if enabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Cron {

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_monthly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'storage_sherpa_daily_event', array( __CLASS__, 'maybe_run_scheduled_scan' ) );
		add_action( 'storage_sherpa_weekly_event', array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'storage_sherpa_monthly_event', array( __CLASS__, 'run_scheduled_scan' ) );
		add_action( 'storage_sherpa_trash_sweep_event', array( __CLASS__, 'run_trash_sweep' ) );
	}

	public static function register_monthly_schedule( $schedules ) {
		$schedules['storage_sherpa_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (Storage Sherpa)', 'storage-sherpa' ),
		);

		return $schedules;
	}

	/**
	 * The daily event always fires (it also drives the trash sweep window),
	 * but only actually runs a scan if the configured frequency is "daily".
	 * Weekly/monthly use their own dedicated schedules so a frequency change
	 * takes effect without waiting for the next daily tick.
	 */
	public static function maybe_run_scheduled_scan() {
		$settings = storage_sherpa_get_settings();

		if ( 'daily' === $settings['scan_frequency'] ) {
			self::run_scheduled_scan();
		}
	}

	public static function run_scheduled_scan() {
		if ( ! class_exists( 'SS_Storage_Analyzer' ) ) {
			return;
		}

		$previous = SS_Storage_Analyzer::get_latest_snapshot_totals();

		$results = SS_Storage_Analyzer::run_full_scan();

		$current_total = array_sum( wp_list_pluck( $results, 'size' ) );
		$previous_total = array_sum( array_values( $previous ) );

		SS_Notifications::maybe_notify_growth( $previous_total, $current_total );

		if ( isset( $results['database'], $previous['database'] ) ) {
			SS_Notifications::maybe_notify_database_growth( $previous['database'], $results['database']['size'] );
		}

		if ( class_exists( 'SS_Orphan_Media_Scanner' ) ) {
			$orphans      = SS_Orphan_Media_Scanner::run_scan();
			$unused_count = count( array_filter( $orphans, fn( $row ) => 'unused' === $row['status'] ) );
			SS_Notifications::maybe_notify_orphan_images( $unused_count );
		}

		if ( class_exists( 'SS_Log_Cleaner' ) ) {
			$logs = SS_Log_Cleaner::find_logs();
			$log_total = array_sum( wp_list_pluck( $logs, 'size' ) );
			SS_Notifications::maybe_notify_large_logs( $log_total );
		}

		if ( class_exists( 'SS_Backup_Cleanup' ) ) {
			$backups = SS_Backup_Cleanup::find_backups();
			$backup_total = array_sum( wp_list_pluck( $backups, 'size' ) );
			SS_Notifications::maybe_notify_backup_accumulation( $backup_total, count( $backups ) );
		}

		self::maybe_run_auto_cleanup();

		$settings = storage_sherpa_get_settings();

		if ( ! empty( $settings['notify_on_scan'] ) ) {
			$summary = array();
			foreach ( $results as $row ) {
				$summary[ $row['label'] ] = $row['size'];
			}
			SS_Notifications::send_scan_report( $summary );
		}
	}

	/**
	 * Only categories explicitly opted into Settings → Scheduled Scans →
	 * "Auto cleanup" run unattended — everything else stays manual-review-only
	 * per the plugin's core safety principle ("nothing deleted automatically"
	 * unless the site owner explicitly turns a category on).
	 */
	private static function maybe_run_auto_cleanup() {
		$settings = storage_sherpa_get_settings();
		$enabled  = (array) $settings['auto_cleanup'];

		if ( in_array( 'database', $enabled, true ) && class_exists( 'SS_Database_Cleanup' ) ) {
			SS_Database_Cleanup::run( SS_Database_Cleanup::safe_categories(), 'cron' );
		}

		if ( in_array( 'empty_folders', $enabled, true ) && class_exists( 'SS_Empty_Folder_Cleaner' ) ) {
			SS_Empty_Folder_Cleaner::clean( 'cron' );
		}

		if ( in_array( 'logs', $enabled, true ) && class_exists( 'SS_Log_Cleaner' ) ) {
			SS_Log_Cleaner::clean_all( 'cron' );
		}

		if ( in_array( 'trash_sweep', $enabled, true ) ) {
			SS_Trash::sweep_expired();
		}

		if ( in_array( 'orphan_media', $enabled, true ) && class_exists( 'SS_Orphan_Media_Scanner' ) ) {
			self::auto_clean_orphan_media( $settings );
		}
	}

	/**
	 * The one auto-clean category with real false-positive risk, so it's
	 * gated by two settings on top of the usual opt-in checkbox: a minimum
	 * "safe to delete" confidence (SS_Orphan_Media_Scanner) and a minimum
	 * upload age. Only ever moves matching attachments to Safe Trash — never
	 * a hard delete, same guarantee as every other cleanup path.
	 */
	private static function auto_clean_orphan_media( $settings ) {
		$min_confidence = (int) $settings['orphan_min_confidence'];
		$min_age_days   = (int) $settings['orphan_min_age_days'];

		$rows = SS_Media_Findings::query(
			SS_Media_Findings::TYPE_ORPHAN,
			array(
				'status' => 'unused',
				'limit'  => 500,
			)
		);

		$batch_id = wp_generate_password( 20, false, false );

		foreach ( $rows as $row ) {
			if ( ! $row->attachment_id || (int) $row->confidence < $min_confidence ) {
				continue;
			}

			$uploaded = get_post_field( 'post_date', $row->attachment_id );
			$age_days = $uploaded ? ( time() - strtotime( $uploaded ) ) / DAY_IN_SECONDS : 0;

			if ( $age_days < $min_age_days ) {
				continue;
			}

			$result = SS_Trash::trash_attachment( $row->attachment_id, 'auto_cleanup', $batch_id );

			if ( ! is_wp_error( $result ) ) {
				SS_Media_Findings::delete( $row->id );
			}
		}
	}

	public static function run_trash_sweep() {
		SS_Trash::sweep_expired();
	}

	/**
	 * Called from Settings save when scan_frequency changes, so the new
	 * cadence takes effect immediately rather than after the current cycle.
	 */
	public static function reschedule( $frequency ) {
		wp_clear_scheduled_hook( 'storage_sherpa_weekly_event' );
		wp_clear_scheduled_hook( 'storage_sherpa_monthly_event' );

		if ( 'weekly' === $frequency && ! wp_next_scheduled( 'storage_sherpa_weekly_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'storage_sherpa_weekly_event' );
		} elseif ( 'monthly' === $frequency && ! wp_next_scheduled( 'storage_sherpa_monthly_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'storage_sherpa_monthly', 'storage_sherpa_monthly_event' );
		}
		// 'daily' needs no extra schedule — storage_sherpa_daily_event already runs every day.
	}
}
