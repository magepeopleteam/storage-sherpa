<?php
/**
 * Shared helper functions used across every Storage Sherpa module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single capability every Storage Sherpa screen/REST route/CLI command is gated on.
 * Filterable so multisite/hosting-provider setups can map it to a custom role.
 */
function storage_sherpa_capability() {
	return apply_filters( 'storage_sherpa_capability', 'manage_options' );
}

function storage_sherpa_current_user_can() {
	return current_user_can( storage_sherpa_capability() );
}

/**
 * Human-readable byte formatting, e.g. 3.9 GB.
 */
function storage_sherpa_format_bytes( $bytes, $precision = 2 ) {
	$bytes = max( 0, (float) $bytes );
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	$power = $bytes > 0 ? floor( log( $bytes, 1024 ) ) : 0;
	$power = min( $power, count( $units ) - 1 );

	$value = $bytes / ( 1024 ** $power );

	return round( $value, $precision ) . ' ' . $units[ $power ];
}

/**
 * Recursively sums file sizes under a directory, skipping ignored paths.
 * Returns array( 'size' => int, 'files' => int, 'dirs' => int ).
 */
function storage_sherpa_dir_stats( $path, $max_seconds = 20 ) {
	$result = array(
		'size'  => 0,
		'files' => 0,
		'dirs'  => 0,
	);

	if ( ! is_dir( $path ) ) {
		return $result;
	}

	$start = microtime( true );

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
	} catch ( Exception $e ) {
		return $result;
	}

	foreach ( $iterator as $item ) {
		if ( ( microtime( true ) - $start ) > $max_seconds ) {
			break;
		}

		$item_path = $item->getPathname();

		if ( storage_sherpa_is_ignored_path( $item_path ) ) {
			continue;
		}

		if ( $item->isDir() ) {
			++$result['dirs'];
			continue;
		}

		$result['size'] += $item->getSize();
		++$result['files'];
	}

	return $result;
}

/**
 * True if $path sits inside (or equals) any configured ignore rule folder,
 * or matches an ignored extension/file rule.
 */
function storage_sherpa_is_ignored_path( $path ) {
	return SS_Ignore_Rules::is_ignored( $path );
}

/**
 * Normalizes a filesystem path to forward slashes for reliable comparisons.
 */
function storage_sherpa_normalize_path( $path ) {
	return rtrim( wp_normalize_path( $path ), '/' );
}

/**
 * True if $path is safely contained inside ABSPATH — refuses to operate
 * outside the WordPress install root under any circumstance.
 */
function storage_sherpa_path_is_safe( $path ) {
	$real = realpath( $path );
	$root = realpath( ABSPATH );

	if ( false === $real || false === $root ) {
		return false;
	}

	$real = storage_sherpa_normalize_path( $real );
	$root = storage_sherpa_normalize_path( $root );

	return 0 === strpos( $real . '/', $root . '/' );
}

/**
 * Recursively deletes a directory. Only ever called on paths that already
 * passed storage_sherpa_path_is_safe() by the caller.
 */
function storage_sherpa_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return false;
	}

	$items = scandir( $dir );

	if ( false === $items ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $item;

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			storage_sherpa_rrmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	return rmdir( $dir );
}

/**
 * Returns the merged, current Storage Sherpa settings option with defaults applied.
 */
function storage_sherpa_get_settings() {
	$defaults = array(
		'retention_days'        => 15,
		'scan_frequency'        => 'weekly',
		'auto_cleanup'          => array(),
		'notify_on_scan'        => true,
		'notify_email'          => get_option( 'admin_email' ),
		'notify_growth_percent' => 20,
		'notify_min_orphans'    => 50,
		'notify_min_log_mb'     => 100,
	);

	$settings = get_option( 'storage_sherpa_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Records one cleanup action into the {prefix}ss_cleanup_log table for
 * Module 21 (Reports) and the dashboard's "Cleanup History" chart.
 */
function storage_sherpa_log_cleanup( $module, $action, $items_count, $bytes_freed, $run_type = 'manual' ) {
	global $wpdb;

	$wpdb->insert(
		$wpdb->prefix . 'ss_cleanup_log',
		array(
			'module'      => sanitize_key( $module ),
			'action'      => sanitize_text_field( $action ),
			'items_count' => (int) $items_count,
			'bytes_freed' => (int) $bytes_freed,
			'run_by'      => get_current_user_id(),
			'run_type'    => sanitize_key( $run_type ),
			'created_at'  => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
	);
}
