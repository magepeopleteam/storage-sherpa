<?php
/**
 * Module 28 — Break Test mode.
 *
 * Quarantines a file (reusing SS_Trash::trash_file() — the exact same
 * recoverable move-away-don't-delete primitive every other cleanup path
 * uses) and watches for real traffic still hitting its original URL for a
 * configurable window (default 48h) before treating it as confirmed safe.
 *
 * The watch mechanism needs no custom rewrite rules or server config: once
 * a file is moved away, WordPress's own standard rewrite setup (both the
 * default Apache .htaccess `RewriteCond %{REQUEST_FILENAME} !-f` and the
 * standard Nginx `try_files $uri $uri/ /index.php?$args;`) only *skips*
 * routing a request to WordPress when the requested path resolves to a
 * real, existing file. The moment the file is gone, that condition flips
 * and the request falls through to WordPress's front controller like any
 * other 404 — which is exactly where template_redirect below can see it.
 * On hosts with a non-standard uploads rewrite setup this simply never
 * fires, so a hit is never spuriously invented — it fails safe silent, not
 * false-positive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Break_Test {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log_hit' ) );
		add_action( 'storage_sherpa_break_test_sweep_event', array( __CLASS__, 'sweep_expired' ) );
	}

	/**
	 * Starts a break test for one file: quarantines it via the normal Safe
	 * Trash pipeline (so it's already fully recoverable even if the watch
	 * window is somehow never resolved) and begins watching its original
	 * URL path for real hits.
	 */
	public static function start( $file_path, $attachment_id = 0, $hours = 48 ) {
		global $wpdb;

		$file_path = storage_sherpa_normalize_path( $file_path );

		if ( ! file_exists( $file_path ) || ! is_file( $file_path ) ) {
			return new WP_Error( 'ss_missing_file', __( 'File no longer exists.', 'storage-sherpa' ) );
		}

		$upload_dir  = wp_upload_dir();
		$relative    = ltrim( str_replace( storage_sherpa_normalize_path( $upload_dir['basedir'] ), '', $file_path ), '/' );
		$url         = trailingslashit( $upload_dir['baseurl'] ) . $relative;
		$url_path    = (string) wp_parse_url( $url, PHP_URL_PATH );

		$trash_id = SS_Trash::trash_file( $file_path, 'break_test', basename( $file_path ) );

		if ( is_wp_error( $trash_id ) ) {
			return $trash_id;
		}

		$token = wp_generate_password( 16, false, false );

		$wpdb->insert(
			$wpdb->prefix . 'ss_break_tests',
			array(
				'attachment_id'      => (int) $attachment_id,
				'trash_id'           => (int) $trash_id,
				'original_path'      => $file_path,
				'original_url_path'  => $url_path,
				'token'              => $token,
				'status'             => 'running',
				'hit_count'          => 0,
				'started_at'         => current_time( 'mysql' ),
				'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + ( max( 1, (int) $hours ) * HOUR_IN_SECONDS ) ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Runs on every front-end request once WordPress has already decided
	 * where to route it — deliberately cheap (one indexed lookup) since
	 * this executes on every single page load, not just 404s, so a running
	 * break test can still be matched on the rare setup where the missing
	 * file doesn't trip is_404() the normal way.
	 */
	public static function maybe_log_hit() {
		global $wpdb;

		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only match against a stored path, never echoed or used in a query beyond an exact-match WHERE.

		if ( ! $path ) {
			return;
		}

		$match = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, hit_count FROM {$wpdb->prefix}ss_break_tests WHERE status = 'running' AND original_url_path = %s LIMIT 1",
				$path
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $match ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'ss_break_tests',
			array(
				'hit_count'   => (int) $match->hit_count + 1,
				'last_hit_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $match->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Resolves every break test whose window has passed: a hit means the
	 * file wasn't safe to remove after all — auto-restore immediately and
	 * alert the site owner. No hit means the window is over and confidence
	 * is now much higher; the file already sits in the normal Safe Trash
	 * pipeline (with its own retention countdown from when the test
	 * started) so there's nothing further to *do* — just record the result.
	 */
	public static function sweep_expired() {
		global $wpdb;

		$expired = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_break_tests WHERE status = 'running' AND expires_at <= %s", current_time( 'mysql' ) )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $expired as $test ) {
			if ( $test->hit_count > 0 ) {
				SS_Trash::restore( (int) $test->trash_id );
				self::mark_resolved( $test->id, 'flagged' );

				if ( class_exists( 'SS_Notifications' ) ) {
					SS_Notifications::notify_break_test_flagged( $test );
				}
			} else {
				self::mark_resolved( $test->id, 'clean' );
			}
		}
	}

	private static function mark_resolved( $id, $status ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'ss_break_tests',
			array(
				'status'      => $status,
				'resolved_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function list_running() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ss_break_tests WHERE status = 'running' ORDER BY started_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function list_recent( $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_break_tests WHERE status != 'running' ORDER BY resolved_at DESC LIMIT %d", $limit )
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
