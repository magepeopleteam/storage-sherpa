<?php
/**
 * Module 24 — Background Scanner.
 *
 * A full site scan can genuinely take longer than PHP's max_execution_time
 * allows in one request, so it's broken into small steps (one module scan
 * each) that the browser drives one at a time via REST polling
 * (SS_REST_API's /scan/start, /scan/step, /scan/status routes) — each
 * request only ever runs one bounded step, so there's no single long-
 * running request to time out. Progress is a transient, not a DB table:
 * a scan job is inherently transient/cheap, and if the browser tab closes
 * mid-scan the job simply expires — get_status() lets a reloaded page pick
 * the same job_id back up and keep calling step() from wherever it left off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Background_Process {

	const TRANSIENT_PREFIX = 'storage_sherpa_job_';
	const TRANSIENT_TTL    = HOUR_IN_SECONDS;

	public static function init() {
		// No hooks needed — driven entirely by REST calls from the dashboard.
	}

	/**
	 * Each step name maps to a callable returning a small result array.
	 * Order matters: cheaper/foundational scans run first so partial
	 * progress is still useful if the job is abandoned early.
	 */
	private static function step_registry() {
		return array(
			'storage_analyzer'  => array( 'SS_Storage_Analyzer', 'run_full_scan' ),
			'filetype_analyzer' => array( 'SS_Filetype_Analyzer', 'scan' ),
			'large_files'       => array( 'SS_Large_File_Scanner', 'run_scan' ),
			'broken_media'      => array( 'SS_Broken_Media', 'run_scan' ),
			'orphan_media'      => array( 'SS_Orphan_Media_Scanner', 'run_scan' ),
			'duplicate_media'   => array( 'SS_Duplicate_Finder', 'run_scan' ),
			'unused_sizes'      => array( 'SS_Unused_Sizes_Cleaner', 'run_scan' ),
			'broken_links'      => array( 'SS_Broken_Links_Scanner', 'run_scan' ),
			'oversized_images'  => array( 'SS_Oversized_Images', 'run_scan' ),
		);
	}

	/**
	 * job_id doubles as (part of) a transient key and an object-cache key.
	 * MySQL's default collation compares option_name case-insensitively, but
	 * WordPress's in-memory object cache keys are plain PHP strings compared
	 * case-sensitively — so a mixed-case job_id normalized inconsistently
	 * (sanitize_key() in one call site, raw in another) silently reads back
	 * a stale cached value instead of the just-written one. Normalizing once
	 * here and reusing that single normalized form everywhere avoids the
	 * mismatch entirely, rather than remembering to sanitize at every call site.
	 */
	private static function normalize_job_id( $job_id ) {
		return sanitize_key( $job_id );
	}

	public static function start_job() {
		$job_id = self::normalize_job_id( wp_generate_password( 12, false, false ) );

		$state = array(
			'job_id'    => $job_id,
			'steps'     => array_keys( self::step_registry() ),
			'current'   => 0,
			'status'    => 'pending',
			'results'   => array(),
			'started_at' => time(),
		);

		set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::TRANSIENT_TTL );

		return $state;
	}

	public static function get_status( $job_id ) {
		$state = get_transient( self::TRANSIENT_PREFIX . self::normalize_job_id( $job_id ) );

		if ( false === $state ) {
			return new WP_Error( 'ss_job_not_found', __( 'Scan job not found or expired.', 'storage-sherpa' ) );
		}

		return $state;
	}

	/**
	 * Runs exactly one step and persists the updated state. Safe to call
	 * repeatedly/idempotently by a polling loop until status is 'complete'.
	 */
	public static function process_step( $job_id ) {
		$job_id = self::normalize_job_id( $job_id );
		$state  = self::get_status( $job_id );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( 'complete' === $state['status'] ) {
			return $state;
		}

		$registry   = self::step_registry();
		$step_index = $state['current'];
		$step_name  = $state['steps'][ $step_index ] ?? null;

		if ( null === $step_name || ! isset( $registry[ $step_name ] ) ) {
			$state['status'] = 'complete';
			set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::TRANSIENT_TTL );
			return $state;
		}

		$state['status'] = 'running';

		try {
			$result = call_user_func( $registry[ $step_name ] );
		} catch ( Throwable $e ) {
			$result = array( 'error' => $e->getMessage() );
		}

		$state['results'][ $step_name ] = $result;
		++$state['current'];

		if ( $state['current'] >= count( $state['steps'] ) ) {
			$state['status']      = 'complete';
			$state['finished_at'] = time();
		}

		set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::TRANSIENT_TTL );

		return $state;
	}
}
