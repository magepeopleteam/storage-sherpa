<?php
/**
 * Activation / deactivation: creates the custom tables every module reads
 * and writes through, and seeds default options + the daily cron events.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Install {

	public static function activate() {
		self::create_tables();
		self::seed_options();
		self::schedule_events();

		// A dedicated directory outside wp-content/uploads (so it never
		// shows up in the Media Library or gets counted as "Uploads" size)
		// where the Safe Trash physically parks moved files.
		wp_mkdir_p( self::trash_dir() );
		self::protect_directory( self::trash_dir() );

		update_option( 'storage_sherpa_db_version', STORAGE_SHERPA_DB_VERSION );
	}

	public static function deactivate() {
		$timestamps = array(
			'storage_sherpa_daily_event',
			'storage_sherpa_weekly_event',
			'storage_sherpa_monthly_event',
			'storage_sherpa_trash_sweep_event',
		);

		foreach ( $timestamps as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( $next ) {
				wp_unschedule_event( $next, $hook );
			}
		}
	}

	public static function trash_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( WP_CONTENT_DIR ) . 'storage-sherpa-trash';
	}

	/**
	 * Blocks directory listing / direct HTTP access to trashed files —
	 * they're recoverable through the plugin UI only, never a bare URL.
	 */
	private static function protect_directory( $dir ) {
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix           = $wpdb->prefix;

		$sql = array();

		// Module 1 / 20 — storage breakdown snapshots, one row per scope per
		// scan, so the dashboard can chart 30-day growth per category.
		$sql[] = "CREATE TABLE {$prefix}ss_scan_snapshots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(40) NOT NULL,
			label VARCHAR(191) NOT NULL,
			path VARCHAR(500) NULL,
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			item_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			scanned_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY scope_scanned (scope, scanned_at)
		) {$charset_collate};";

		// Modules 2, 3, 4, 7 — one shared table for orphan / duplicate /
		// large-file / broken-media findings, disambiguated by finding_type.
		$sql[] = "CREATE TABLE {$prefix}ss_media_findings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			finding_type VARCHAR(20) NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_path VARCHAR(500) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unused',
			reason VARCHAR(191) NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			group_hash VARCHAR(64) NULL,
			checked_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY finding_type (finding_type),
			KEY attachment_id (attachment_id),
			KEY group_hash (group_hash)
		) {$charset_collate};";

		// Module 19 — Safe Trash / Recovery Center. Handles both file-based
		// deletions (original_path set) and DB-row deletions (table_name +
		// row_data set) through one restore/permanent-delete pipeline.
		$sql[] = "CREATE TABLE {$prefix}ss_trash_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			item_type VARCHAR(20) NOT NULL,
			module VARCHAR(40) NOT NULL,
			label VARCHAR(191) NOT NULL,
			original_path VARCHAR(500) NULL,
			trashed_path VARCHAR(500) NULL,
			table_name VARCHAR(191) NULL,
			row_data LONGTEXT NULL,
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			deleted_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			restored TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY module (module),
			KEY expires_at (expires_at),
			KEY restored (restored)
		) {$charset_collate};";

		// Module 21 — every cleanup run, across every module, for Reports
		// and the dashboard's "Cleanup History" chart.
		$sql[] = "CREATE TABLE {$prefix}ss_cleanup_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module VARCHAR(40) NOT NULL,
			action VARCHAR(191) NOT NULL,
			items_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			bytes_freed BIGINT UNSIGNED NOT NULL DEFAULT 0,
			run_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			run_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY module (module),
			KEY created_at (created_at)
		) {$charset_collate};";

		// Module 23 — Ignore Rules.
		$sql[] = "CREATE TABLE {$prefix}ss_ignore_rules (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_type VARCHAR(20) NOT NULL,
			value VARCHAR(500) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY rule_type (rule_type)
		) {$charset_collate};";

		foreach ( $sql as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	private static function seed_options() {
		if ( false === get_option( 'storage_sherpa_settings' ) ) {
			add_option( 'storage_sherpa_settings', storage_sherpa_get_settings() );
		}
	}

	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'storage_sherpa_daily_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'storage_sherpa_daily_event' );
		}

		if ( ! wp_next_scheduled( 'storage_sherpa_trash_sweep_event' ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'storage_sherpa_trash_sweep_event' );
		}
	}
}
