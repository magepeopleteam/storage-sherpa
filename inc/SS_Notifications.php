<?php
/**
 * Module 22 — Notification Center.
 *
 * One dispatcher, same shape as the sibling PassPress plugin's
 * PP_Notifications: a private send() wrapping wp_mail() (the one place a
 * future channel — Slack, webhook — would plug in), and public trigger
 * methods called from SS_Cron after each scheduled scan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Notifications {

	public static function init() {
		// Triggers are called directly from SS_Cron; nothing to hook here.
	}

	private static function send( $subject, $body ) {
		$settings = storage_sherpa_get_settings();

		if ( empty( $settings['notify_on_scan'] ) ) {
			return false;
		}

		$to = ! empty( $settings['notify_email'] ) ? $settings['notify_email'] : get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return wp_mail(
			$to,
			'[' . $site . '] ' . $subject,
			$body
		);
	}

	/**
	 * Compares this scan's total install size against the previous scan and
	 * alerts if growth exceeds the configured percentage threshold.
	 */
	public static function maybe_notify_growth( $previous_total, $current_total ) {
		$settings = storage_sherpa_get_settings();

		if ( $previous_total <= 0 ) {
			return;
		}

		$percent_change = ( ( $current_total - $previous_total ) / $previous_total ) * 100;

		if ( $percent_change < (float) $settings['notify_growth_percent'] ) {
			return;
		}

		self::send(
			__( 'Large storage increase detected', 'storage-sherpa' ),
			sprintf(
				/* translators: 1: old size, 2: new size, 3: percent grown */
				__( "Your site's total storage grew from %1\$s to %2\$s (+%3\$s%%) since the last scan.\n\nReview: %4\$s", 'storage-sherpa' ),
				storage_sherpa_format_bytes( $previous_total ),
				storage_sherpa_format_bytes( $current_total ),
				round( $percent_change, 1 ),
				admin_url( 'admin.php?page=storage-sherpa' )
			)
		);
	}

	public static function maybe_notify_database_growth( $previous_db_size, $current_db_size ) {
		$settings = storage_sherpa_get_settings();

		if ( $previous_db_size <= 0 ) {
			return;
		}

		$percent_change = ( ( $current_db_size - $previous_db_size ) / $previous_db_size ) * 100;

		if ( $percent_change < (float) $settings['notify_growth_percent'] ) {
			return;
		}

		self::send(
			__( 'Database growth detected', 'storage-sherpa' ),
			sprintf(
				/* translators: 1: old size, 2: new size */
				__( "Your database grew from %1\$s to %2\$s since the last scan. Consider running Database Cleanup.\n\nReview: %3\$s", 'storage-sherpa' ),
				storage_sherpa_format_bytes( $previous_db_size ),
				storage_sherpa_format_bytes( $current_db_size ),
				admin_url( 'admin.php?page=storage-sherpa-database' )
			)
		);
	}

	public static function maybe_notify_orphan_images( $orphan_count ) {
		$settings = storage_sherpa_get_settings();

		if ( $orphan_count < (int) $settings['notify_min_orphans'] ) {
			return;
		}

		self::send(
			__( 'Many unused media files found', 'storage-sherpa' ),
			sprintf(
				/* translators: %d: number of orphan attachments */
				__( "Storage Sherpa found %d unused media files on your site.\n\nReview: %s", 'storage-sherpa' ),
				$orphan_count,
				admin_url( 'admin.php?page=storage-sherpa-media' )
			)
		);
	}

	public static function maybe_notify_large_logs( $log_size_bytes ) {
		$settings  = storage_sherpa_get_settings();
		$threshold = (int) $settings['notify_min_log_mb'] * MB_IN_BYTES;

		if ( $log_size_bytes < $threshold ) {
			return;
		}

		self::send(
			__( 'Large log files found', 'storage-sherpa' ),
			sprintf(
				/* translators: %s: total log size */
				__( "Your debug/error logs total %s. Consider clearing them.\n\nReview: %s", 'storage-sherpa' ),
				storage_sherpa_format_bytes( $log_size_bytes ),
				admin_url( 'admin.php?page=storage-sherpa-logs' )
			)
		);
	}

	public static function maybe_notify_backup_accumulation( $backup_size_bytes, $backup_count ) {
		$settings  = storage_sherpa_get_settings();
		// Reuses the log-size threshold (in MB) as a generic "this is a lot of
		// accumulated files" trigger rather than adding a dedicated setting.
		$threshold = (int) $settings['notify_min_log_mb'] * MB_IN_BYTES * 5;

		if ( $backup_size_bytes < $threshold ) {
			return;
		}

		self::send(
			__( 'Backup files accumulating', 'storage-sherpa' ),
			sprintf(
				/* translators: 1: number of backup files, 2: total size */
				__( "Storage Sherpa found %1\$d old backup files totaling %2\$s. Consider cleaning up old backups.\n\nReview: %3\$s", 'storage-sherpa' ),
				$backup_count,
				storage_sherpa_format_bytes( $backup_size_bytes ),
				admin_url( 'admin.php?page=storage-sherpa-backups' )
			)
		);
	}

	/**
	 * Emails a scan summary report — the "Email report" option under
	 * Module 20 (Scheduled Scans).
	 */
	public static function send_scan_report( array $summary ) {
		$lines   = array();
		$lines[] = __( 'Storage Sherpa scheduled scan complete.', 'storage-sherpa' );
		$lines[] = '';

		foreach ( $summary as $label => $size ) {
			$lines[] = sprintf( '%s: %s', $label, storage_sherpa_format_bytes( $size ) );
		}

		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=storage-sherpa' );

		self::send( __( 'Scheduled scan report', 'storage-sherpa' ), implode( "\n", $lines ) );
	}
}
