<?php
/**
 * REST API — storage-sherpa/v1.
 *
 * The one surface the wp-element dashboard (assets/admin/js/*) talks to via
 * apiFetch. Every route shares the same permission_callback
 * (storage_sherpa_current_user_can) and, for state-changing routes, the
 * standard X-WP-Nonce header apiFetch attaches automatically — no bespoke
 * admin-ajax handlers. Routes are grouped by module; each one is a thin
 * pass-through to the module class that already does the real work, so
 * there's exactly one implementation of each behavior (this file, the admin
 * screens, and WP-CLI when it lands all call the same module methods).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_REST_API {

	const NS = 'storage-sherpa/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function permission() {
		return function () {
			return storage_sherpa_current_user_can();
		};
	}

	public static function register_routes() {
		$perm = self::permission();

		register_rest_route(
			self::NS,
			'/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => $perm,
			)
		);

		// --- Module 24: Background Scanner ---------------------------------
		register_rest_route( self::NS, '/scan/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'scan_start' ), 'permission_callback' => $perm ) );
		register_rest_route(
			self::NS,
			'/scan/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'scan_step' ),
				'permission_callback' => $perm,
				'args'                => array( 'job_id' => array( 'required' => true ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/scan/status/(?P<job_id>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'scan_status' ),
				'permission_callback' => $perm,
			)
		);

		// --- Modules 2/3/4/7: Media findings --------------------------------
		register_rest_route(
			self::NS,
			'/media/(?P<type>orphan|duplicate|large|broken)',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'media_list' ), 'permission_callback' => $perm )
		);
		register_rest_route(
			self::NS,
			'/media/(?P<type>orphan|duplicate|large|broken)/scan',
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'media_scan' ), 'permission_callback' => $perm )
		);
		register_rest_route(
			self::NS,
			'/media/trash',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'media_trash' ),
				'permission_callback' => $perm,
				'args'                => array( 'ids' => array( 'required' => true ) ),
			)
		);

		// --- Module 5: Image Optimization -----------------------------------
		register_rest_route( self::NS, '/images/scan', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'images_scan' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/images/(?P<id>\d+)/compress', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'images_compress' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/images/(?P<id>\d+)/webp', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'images_webp' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/images/(?P<id>\d+)/avif', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'images_avif' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/images/(?P<id>\d+)/regenerate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'images_regenerate' ), 'permission_callback' => $perm ) );

		// --- Module 6: Empty Folder Cleaner ---------------------------------
		register_rest_route( self::NS, '/empty-folders', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'empty_folders_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/empty-folders/clean', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'empty_folders_clean' ), 'permission_callback' => $perm ) );

		// --- Module 8: Database Cleanup -------------------------------------
		register_rest_route( self::NS, '/database/summary', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'database_summary' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/database/preview/(?P<key>[a-z_]+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'database_preview' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/database/run', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'database_run' ), 'permission_callback' => $perm, 'args' => array( 'categories' => array( 'required' => true ) ) ) );
		register_rest_route( self::NS, '/database/maintenance', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'database_maintenance' ), 'permission_callback' => $perm, 'args' => array( 'action' => array( 'required' => true ) ) ) );

		// --- Module 9: Orphan DB Tables --------------------------------------
		register_rest_route( self::NS, '/tables', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'tables_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/tables/drop', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'tables_drop' ), 'permission_callback' => $perm, 'args' => array( 'table' => array( 'required' => true ) ) ) );

		// --- Module 12: Backup Cleanup ----------------------------------------
		register_rest_route( self::NS, '/backups', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'backups_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/backups/delete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'backups_delete' ), 'permission_callback' => $perm, 'args' => array( 'path' => array( 'required' => true ) ) ) );
		register_rest_route( self::NS, '/backups/delete-old', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'backups_delete_old' ), 'permission_callback' => $perm ) );

		// --- Module 13: Cache Cleaner ------------------------------------------
		register_rest_route( self::NS, '/cache/targets', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'cache_targets' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/cache/purge', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cache_purge' ), 'permission_callback' => $perm ) );

		// --- Module 14: Log Cleaner --------------------------------------------
		register_rest_route( self::NS, '/logs', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'logs_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/logs/delete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'logs_delete' ), 'permission_callback' => $perm, 'args' => array( 'path' => array( 'required' => true ) ) ) );
		register_rest_route( self::NS, '/logs/delete-all', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'logs_delete_all' ), 'permission_callback' => $perm ) );

		// --- Module 15: Cron Manager --------------------------------------------
		register_rest_route( self::NS, '/cron', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'cron_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/cron/delete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cron_delete' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/cron/run', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cron_run' ), 'permission_callback' => $perm ) );

		// --- Module 16: Autoload Analyzer -----------------------------------
		register_rest_route( self::NS, '/autoload', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'autoload_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/autoload/toggle', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'autoload_toggle' ), 'permission_callback' => $perm, 'args' => array( 'option_name' => array( 'required' => true ), 'autoload' => array( 'required' => true ) ) ) );

		// --- Module 17: File Type Analyzer ------------------------------------
		register_rest_route( self::NS, '/filetypes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'filetypes_scan' ), 'permission_callback' => $perm ) );

		// --- Module 19: Recovery Center (Safe Trash) --------------------------
		register_rest_route( self::NS, '/trash', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'trash_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/trash/(?P<id>\d+)/restore', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'trash_restore' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/trash/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'trash_delete' ), 'permission_callback' => $perm ) );

		// --- Module 23: Ignore Rules ---------------------------------------------
		register_rest_route( self::NS, '/ignore-rules', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'ignore_rules_list' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/ignore-rules', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'ignore_rules_add' ), 'permission_callback' => $perm, 'args' => array( 'rule_type' => array( 'required' => true ), 'value' => array( 'required' => true ) ) ) );
		register_rest_route( self::NS, '/ignore-rules/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'ignore_rules_remove' ), 'permission_callback' => $perm ) );

		// --- Settings (Modules 20/22/23 general settings) -----------------------
		register_rest_route( self::NS, '/settings', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'settings_get' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/settings', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'settings_update' ), 'permission_callback' => $perm ) );
	}

	private static function ok( $data ) {
		return new WP_REST_Response( $data, 200 );
	}

	private static function maybe_error( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		return null;
	}

	// ---------------------------------------------------------------------
	// Overview
	// ---------------------------------------------------------------------

	public static function overview() {
		return self::ok(
			array(
				'totals'          => SS_Storage_Analyzer::get_latest_results(),
				'growth_history'  => SS_Storage_Analyzer::get_growth_history( 30 ),
				'health_score'    => SS_Storage_Analyzer::calculate_health_score(),
				'recoverable'     => SS_Storage_Analyzer::get_recoverable_estimate(),
				'largest_dirs'    => SS_Storage_Analyzer::get_largest_directories( WP_CONTENT_DIR, 8 ),
				'last_scan'       => get_option( 'storage_sherpa_last_scan' ),
				'trash_pending'   => count( SS_Trash::query( array( 'limit' => 500 ) ) ),
				'orphan_counts'   => SS_Media_Findings::counts( SS_Media_Findings::TYPE_ORPHAN ),
				'duplicate_bytes' => SS_Duplicate_Finder::potential_savings(),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Background scanner
	// ---------------------------------------------------------------------

	public static function scan_start() {
		return self::ok( SS_Background_Process::start_job() );
	}

	public static function scan_step( WP_REST_Request $request ) {
		$state = SS_Background_Process::process_step( $request->get_param( 'job_id' ) );
		return self::maybe_error( $state ) ?? self::ok( $state );
	}

	public static function scan_status( WP_REST_Request $request ) {
		$state = SS_Background_Process::get_status( $request->get_param( 'job_id' ) );
		return self::maybe_error( $state ) ?? self::ok( $state );
	}

	// ---------------------------------------------------------------------
	// Media findings (2/3/4/7)
	// ---------------------------------------------------------------------

	private static function media_type_map() {
		return array(
			'orphan'    => array( 'SS_Orphan_Media_Scanner', SS_Media_Findings::TYPE_ORPHAN ),
			'duplicate' => array( 'SS_Duplicate_Finder', SS_Media_Findings::TYPE_DUPLICATE ),
			'large'     => array( 'SS_Large_File_Scanner', SS_Media_Findings::TYPE_LARGE ),
			'broken'    => array( 'SS_Broken_Media', SS_Media_Findings::TYPE_BROKEN ),
		);
	}

	public static function media_list( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		list( , $finding_type ) = self::media_type_map()[ $type ];

		$rows = SS_Media_Findings::query(
			$finding_type,
			array(
				'status' => $request->get_param( 'status' ) ?: '',
				'limit'  => (int) ( $request->get_param( 'limit' ) ?: 100 ),
				'offset' => (int) ( $request->get_param( 'offset' ) ?: 0 ),
			)
		);

		return self::ok(
			array(
				'rows'   => $rows,
				'counts' => SS_Media_Findings::counts( $finding_type ),
			)
		);
	}

	public static function media_scan( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' );
		list( $class ) = self::media_type_map()[ $type ];

		$rows = call_user_func( array( $class, 'run_scan' ) );

		return self::ok(
			array(
				'count' => count( $rows ),
			)
		);
	}

	public static function media_trash( WP_REST_Request $request ) {
		$ids     = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$results = array();

		foreach ( $ids as $finding_id ) {
			$finding = SS_Media_Findings::get( $finding_id );
			if ( ! $finding ) {
				continue;
			}

			if ( $finding->attachment_id ) {
				$result = SS_Trash::trash_attachment( $finding->attachment_id, 'media_findings' );
			} elseif ( $finding->file_path ) {
				$result = SS_Trash::trash_file( $finding->file_path, 'media_findings' );
			} else {
				continue;
			}

			if ( ! is_wp_error( $result ) ) {
				SS_Media_Findings::delete( $finding_id );
			}

			$results[ $finding_id ] = is_wp_error( $result ) ? $result->get_error_message() : true;
		}

		return self::ok( array( 'results' => $results ) );
	}

	// ---------------------------------------------------------------------
	// Image optimization (5)
	// ---------------------------------------------------------------------

	public static function images_scan() {
		return self::ok(
			array_merge(
				SS_Image_Optimizer::scan(),
				array(
					'webp_supported' => SS_Image_Optimizer::webp_supported(),
					'avif_supported' => SS_Image_Optimizer::avif_supported(),
				)
			)
		);
	}

	public static function images_compress( WP_REST_Request $request ) {
		$result = SS_Image_Optimizer::compress( (int) $request->get_param( 'id' ), (int) ( $request->get_param( 'quality' ) ?: 82 ) );
		return self::maybe_error( $result ) ?? self::ok( $result );
	}

	public static function images_webp( WP_REST_Request $request ) {
		$result = SS_Image_Optimizer::generate_webp( (int) $request->get_param( 'id' ) );
		return self::maybe_error( $result ) ?? self::ok( $result );
	}

	public static function images_avif( WP_REST_Request $request ) {
		$result = SS_Image_Optimizer::generate_avif( (int) $request->get_param( 'id' ) );
		return self::maybe_error( $result ) ?? self::ok( $result );
	}

	public static function images_regenerate( WP_REST_Request $request ) {
		$result = SS_Image_Optimizer::regenerate_thumbnails( (int) $request->get_param( 'id' ) );
		return self::maybe_error( $result ) ?? self::ok( array( 'regenerated' => true ) );
	}

	// ---------------------------------------------------------------------
	// Empty folders (6)
	// ---------------------------------------------------------------------

	public static function empty_folders_list() {
		return self::ok( array( 'folders' => SS_Empty_Folder_Cleaner::scan() ) );
	}

	public static function empty_folders_clean() {
		return self::ok( array( 'removed' => SS_Empty_Folder_Cleaner::clean() ) );
	}

	// ---------------------------------------------------------------------
	// Database cleanup (8)
	// ---------------------------------------------------------------------

	public static function database_summary() {
		return self::ok( SS_Database_Cleanup::summary() );
	}

	public static function database_preview( WP_REST_Request $request ) {
		return self::ok( array( 'rows' => SS_Database_Cleanup::preview( $request->get_param( 'key' ) ) ) );
	}

	public static function database_run( WP_REST_Request $request ) {
		return self::ok( SS_Database_Cleanup::run( (array) $request->get_param( 'categories' ) ) );
	}

	public static function database_maintenance( WP_REST_Request $request ) {
		$result = SS_Database_Cleanup::table_maintenance( $request->get_param( 'action' ) );
		return self::maybe_error( $result ) ?? self::ok( $result );
	}

	// ---------------------------------------------------------------------
	// Orphan DB tables (9)
	// ---------------------------------------------------------------------

	public static function tables_list() {
		return self::ok( array( 'tables' => SS_Orphan_Tables::scan() ) );
	}

	public static function tables_drop( WP_REST_Request $request ) {
		$result = SS_Orphan_Tables::drop_table( $request->get_param( 'table' ) );
		return self::maybe_error( $result ) ?? self::ok( $result );
	}

	// ---------------------------------------------------------------------
	// Backups (12)
	// ---------------------------------------------------------------------

	public static function backups_list() {
		return self::ok( array( 'groups' => SS_Backup_Cleanup::find_backup_dirs() ) );
	}

	public static function backups_delete( WP_REST_Request $request ) {
		$result = SS_Backup_Cleanup::delete_backup_file( $request->get_param( 'path' ) );
		return self::maybe_error( $result ) ?? self::ok( array( 'trash_id' => $result ) );
	}

	public static function backups_delete_old( WP_REST_Request $request ) {
		return self::ok( SS_Backup_Cleanup::delete_old_backups( (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
	}

	// ---------------------------------------------------------------------
	// Cache (13)
	// ---------------------------------------------------------------------

	public static function cache_targets() {
		return self::ok( array( 'targets' => SS_Cache_Cleaner::available_targets() ) );
	}

	public static function cache_purge( WP_REST_Request $request ) {
		$target = $request->get_param( 'target' );

		if ( $target ) {
			$result = SS_Cache_Cleaner::purge( $target );
			return self::maybe_error( $result ) ?? self::ok( array( 'result' => $result ) );
		}

		return self::ok( array( 'results' => SS_Cache_Cleaner::purge_all() ) );
	}

	// ---------------------------------------------------------------------
	// Logs (14)
	// ---------------------------------------------------------------------

	public static function logs_list() {
		return self::ok( array( 'logs' => SS_Log_Cleaner::find_logs() ) );
	}

	public static function logs_delete( WP_REST_Request $request ) {
		$result = SS_Log_Cleaner::clean_single( $request->get_param( 'path' ) );
		return self::maybe_error( $result ) ?? self::ok( array( 'trash_id' => $result ) );
	}

	public static function logs_delete_all() {
		return self::ok( SS_Log_Cleaner::clean_all() );
	}

	// ---------------------------------------------------------------------
	// Cron manager (15)
	// ---------------------------------------------------------------------

	public static function cron_list() {
		return self::ok(
			array(
				'events'  => SS_Cron_Manager::list_events(),
				'failed'  => SS_Cron_Manager::failed_events(),
			)
		);
	}

	public static function cron_delete( WP_REST_Request $request ) {
		$result = SS_Cron_Manager::delete_event(
			$request->get_param( 'hook' ),
			(int) $request->get_param( 'timestamp' ),
			(array) $request->get_param( 'args' )
		);
		return self::ok( array( 'deleted' => (bool) $result ) );
	}

	public static function cron_run( WP_REST_Request $request ) {
		return self::ok( SS_Cron_Manager::run_event( $request->get_param( 'hook' ), (array) $request->get_param( 'args' ) ) );
	}

	// ---------------------------------------------------------------------
	// Autoload analyzer (16)
	// ---------------------------------------------------------------------

	public static function autoload_list() {
		return self::ok(
			array(
				'options' => SS_Autoload_Analyzer::scan( 100 ),
				'total'   => SS_Autoload_Analyzer::total_autoload_size(),
			)
		);
	}

	public static function autoload_toggle( WP_REST_Request $request ) {
		$updated = SS_Autoload_Analyzer::set_autoload( $request->get_param( 'option_name' ), (bool) $request->get_param( 'autoload' ) );
		return self::ok( array( 'updated' => $updated ) );
	}

	// ---------------------------------------------------------------------
	// File type analyzer (17)
	// ---------------------------------------------------------------------

	public static function filetypes_scan() {
		return self::ok(
			array(
				'totals' => SS_Filetype_Analyzer::scan(),
				'labels' => SS_Filetype_Analyzer::labels(),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Recovery Center / Safe Trash (19)
	// ---------------------------------------------------------------------

	public static function trash_list( WP_REST_Request $request ) {
		return self::ok(
			array(
				'items' => SS_Trash::query(
					array(
						'limit'  => (int) ( $request->get_param( 'limit' ) ?: 100 ),
						'offset' => (int) ( $request->get_param( 'offset' ) ?: 0 ),
						'module' => $request->get_param( 'module' ) ?: '',
					)
				),
				'total_size' => SS_Trash::total_trash_size(),
			)
		);
	}

	public static function trash_restore( WP_REST_Request $request ) {
		$result = SS_Trash::restore( (int) $request->get_param( 'id' ) );
		return self::maybe_error( $result ) ?? self::ok( array( 'restored' => true ) );
	}

	public static function trash_delete( WP_REST_Request $request ) {
		$result = SS_Trash::permanently_delete( (int) $request->get_param( 'id' ) );
		return self::maybe_error( $result ) ?? self::ok( array( 'deleted' => true ) );
	}

	// ---------------------------------------------------------------------
	// Ignore rules (23)
	// ---------------------------------------------------------------------

	public static function ignore_rules_list() {
		return self::ok( SS_Ignore_Rules::all() );
	}

	public static function ignore_rules_add( WP_REST_Request $request ) {
		$id = SS_Ignore_Rules::add( $request->get_param( 'rule_type' ), $request->get_param( 'value' ) );
		return $id ? self::ok( array( 'id' => $id ) ) : new WP_REST_Response( array( 'message' => __( 'Invalid rule.', 'storage-sherpa' ) ), 400 );
	}

	public static function ignore_rules_remove( WP_REST_Request $request ) {
		return self::ok( array( 'removed' => SS_Ignore_Rules::remove( (int) $request->get_param( 'id' ) ) ) );
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	public static function settings_get() {
		return self::ok( storage_sherpa_get_settings() );
	}

	public static function settings_update( WP_REST_Request $request ) {
		// get_param() (not get_json_params()) so this works regardless of
		// whether the client sent a JSON body or form-encoded params — the
		// same request-parsing path every other route in this file already
		// relies on via get_param().
		$current  = storage_sherpa_get_settings();
		$settings = $current;

		foreach ( array_keys( $current ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$settings[ $key ] = $value;
			}
		}

		$settings['retention_days']        = max( 1, (int) $settings['retention_days'] );
		$settings['scan_frequency']        = in_array( $settings['scan_frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $settings['scan_frequency'] : 'weekly';
		$settings['auto_cleanup']          = array_map( 'sanitize_key', (array) $settings['auto_cleanup'] );
		$settings['notify_on_scan']        = (bool) $settings['notify_on_scan'];
		$settings['notify_email']          = sanitize_email( $settings['notify_email'] );
		$settings['notify_growth_percent'] = max( 1, (float) $settings['notify_growth_percent'] );
		$settings['notify_min_orphans']    = max( 0, (int) $settings['notify_min_orphans'] );
		$settings['notify_min_log_mb']     = max( 1, (int) $settings['notify_min_log_mb'] );

		update_option( 'storage_sherpa_settings', $settings );

		if ( $current['scan_frequency'] !== $settings['scan_frequency'] ) {
			SS_Cron::reschedule( $settings['scan_frequency'] );
		}

		return self::ok( $settings );
	}
}
