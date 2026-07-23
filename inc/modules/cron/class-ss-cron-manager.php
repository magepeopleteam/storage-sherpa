<?php
/**
 * Module 15 — Cron Manager.
 *
 * A thin, read/act layer over WP-Cron's own storage (the 'cron' option,
 * read via _get_cron_array()) — WordPress doesn't record whether a past
 * cron run actually succeeded, so "failed" here is a documented heuristic
 * (an event whose scheduled timestamp is more than an hour in the past
 * means either cron isn't running on this site or the callback errored),
 * not a certainty. Deleting an event is metadata-only — the hook/args are
 * logged to the cleanup log for reference, but not routed through Safe
 * Trash: if the owning plugin still needs that schedule, it re-registers
 * it the next time its own activation/init logic runs, which is how WP-Cron
 * events are meant to be self-healing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Cron_Manager {

	const OVERDUE_THRESHOLD = HOUR_IN_SECONDS;

	public static function list_events() {
		$cron = _get_cron_array();
		if ( empty( $cron ) ) {
			return array();
		}

		$slugs  = self::plugin_slugs();
		$rows   = array();
		$now    = time();

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $key => $event ) {
					$rows[] = array(
						'hook'      => $hook,
						'timestamp' => (int) $timestamp,
						'schedule'  => $event['schedule'] ? $event['schedule'] : __( 'Single event', 'storage-sherpa' ),
						'interval'  => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
						'args'      => $event['args'],
						'sig'       => $key,
						'overdue'   => ( $timestamp < ( $now - self::OVERDUE_THRESHOLD ) ),
						'owner'     => self::guess_owner( $hook, $slugs ),
					);
				}
			}
		}

		usort( $rows, fn( $a, $b ) => $a['timestamp'] <=> $b['timestamp'] );

		return $rows;
	}

	public static function failed_events() {
		return array_values( array_filter( self::list_events(), fn( $row ) => $row['overdue'] ) );
	}

	private static function plugin_slugs() {
		$slugs = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$slugs[] = strtolower( str_replace( '-', '_', strtok( $plugin_file, '/' ) ) );
		}
		return $slugs;
	}

	private static function guess_owner( $hook, $slugs ) {
		if ( 0 === strpos( $hook, 'wp_' ) || 0 === strpos( $hook, 'wordpress_' ) ) {
			return __( 'WordPress core', 'storage-sherpa' );
		}

		$normalized = strtolower( str_replace( '-', '_', $hook ) );

		foreach ( $slugs as $slug ) {
			if ( $slug && false !== strpos( $normalized, $slug ) ) {
				return $slug;
			}
		}

		return __( 'Unknown', 'storage-sherpa' );
	}

	public static function delete_event( $hook, $timestamp, array $args = array() ) {
		$result = wp_unschedule_event( (int) $timestamp, $hook, $args );

		if ( $result ) {
			storage_sherpa_log_cleanup( 'cron', 'delete_event:' . $hook, 1, 0 );
		}

		return $result;
	}

	public static function run_event( $hook, array $args = array() ) {
		if ( ! has_action( $hook ) && ! did_action( $hook ) ) {
			// Not a hard blocker (some hooks are registered lazily), just a heads-up
			// surfaced to the caller via the return value's 'warning' key.
			$warning = __( 'No callback currently appears to be registered for this hook.', 'storage-sherpa' );
		}

		do_action_ref_array( $hook, $args );

		return array(
			'ran'     => true,
			'warning' => isset( $warning ) ? $warning : null,
		);
	}
}
