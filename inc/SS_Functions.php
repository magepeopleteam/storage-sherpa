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
		'retention_days'         => 30,
		'scan_frequency'         => 'weekly',
		'auto_cleanup'           => array(),
		'notify_on_scan'         => true,
		'notify_email'           => get_option( 'admin_email' ),
		'notify_growth_percent'  => 20,
		'notify_min_orphans'     => 50,
		'notify_min_log_mb'      => 100,
		// Gates for the "orphan_media" / "post_delete_cleanup" auto_cleanup
		// options — both must pass before an orphan is ever auto-trashed
		// unattended, on top of "everything still goes through Safe Trash
		// first, nothing is ever a hard delete."
		'orphan_min_confidence'  => 95,
		'orphan_min_age_days'    => 365,
	);

	$settings = get_option( 'storage_sherpa_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * True if a known cloud-offload plugin (WP Offload Media, Media Cloud,
 * WP2Static) is active on this install. These plugins routinely remove the
 * local copy of a file after upload, which would otherwise make every
 * file-existence-based check in this plugin (Broken Media, Duplicate
 * Finder, Large File Scanner) misfire against attachments that are working
 * exactly as intended. Filterable so a site running an offload integration
 * this list doesn't know about can still declare itself.
 */
function storage_sherpa_offload_active() {
	$detected = defined( 'AS3CF_SETTINGS_TIME' )        // WP Offload Media.
		|| class_exists( 'Amazon_S3_And_CloudFront' )    // WP Offload Media (legacy class name).
		|| defined( 'MEDIA_CLOUD_VERSION' )              // Media Cloud.
		|| class_exists( 'WP2Static\Controller' );        // WP2Static.

	return (bool) apply_filters( 'storage_sherpa_offload_active', $detected );
}

/**
 * True if a given attachment's local file is expected to be intentionally
 * absent. WP Offload Media's own "remove file from server" setting is the
 * clearest available signal; short of parsing every offload plugin's own
 * per-attachment metadata (which varies release to release and isn't
 * verifiable without a live install of each), treating "an offload plugin
 * is active with local-removal enabled" as "don't trust file_exists() for
 * this attachment" is the safe, conservative default — a filter is
 * provided for a more precise per-attachment signal where one exists.
 */
function storage_sherpa_attachment_is_offloaded( $attachment_id ) {
	if ( ! storage_sherpa_offload_active() ) {
		return false;
	}

	$settings = get_option( 'as3cf_settings' );

	if ( is_array( $settings ) && ! empty( $settings['remove-local-file'] ) ) {
		return true;
	}

	return (bool) apply_filters( 'storage_sherpa_attachment_is_offloaded', false, $attachment_id );
}

/**
 * String search-and-replace inside a postmeta/option value that might be
 * PHP-serialized — used by the Duplicate Finder's merge tool to re-point a
 * duplicate's file URL(s) to the kept attachment's URL(s) wherever they're
 * baked into meta/options data (ACF fields, page-builder settings, widget
 * instances, …) before the duplicate is trashed. For swapping an attachment
 * *id* (an integer leaf, not a string) inside such a structure, use
 * storage_sherpa_replace_id_in_value() instead — see that function's
 * docblock for why these are two separate functions rather than one.
 *
 * A naive str_replace() on a serialized value is actively dangerous: PHP's
 * serialize() format prefixes every string with its byte length
 * (`s:45:"...";`), so changing a string's length without recalculating that
 * prefix produces a blob unserialize() can no longer parse — silently
 * corrupting whatever plugin owns that data. This unserializes first,
 * replaces only inside the actual string leaves of the resulting structure,
 * then re-serializes from scratch so the length prefixes are always correct.
 * Plain strings (including JSON — which has no length prefixes and is safe
 * to substring-replace directly) skip straight to str_replace().
 *
 * Returns the original $value unchanged if it looks serialized but fails to
 * unserialize — safer to leave data alone than guess at malformed input. A
 * string that merely starts with a serialization-like prefix but isn't
 * actually valid serialized data (is_serialized() checks the full shape, not
 * just the prefix) is correctly treated as plain text instead — it was never
 * at risk of being corrupted since nothing would have unserialized it anyway.
 */
function storage_sherpa_replace_in_value( $value, $search, $replace ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	if ( is_serialized( $value ) ) {
		$unserialized = @unserialize( trim( $value ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- deliberately tolerant of malformed input; see docblock.

		if ( false === $unserialized && 'b:0;' !== trim( $value ) ) {
			return $value;
		}

		return serialize( storage_sherpa_walk_replace_strings( $unserialized, $search, $replace ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- re-serializing what was just safely unserialized above.
	}

	return str_replace( $search, $replace, $value );
}

function storage_sherpa_walk_replace_strings( $data, $search, $replace ) {
	if ( is_string( $data ) ) {
		return str_replace( $search, $replace, $data );
	}

	if ( is_array( $data ) ) {
		$out = array();
		foreach ( $data as $key => $item ) {
			$out[ $key ] = storage_sherpa_walk_replace_strings( $item, $search, $replace );
		}
		return $out;
	}

	if ( is_object( $data ) ) {
		foreach ( $data as $key => $item ) {
			$data->$key = storage_sherpa_walk_replace_strings( $item, $search, $replace );
		}
		return $data;
	}

	return $data;
}

/**
 * Swaps an attachment id inside a postmeta/option value that might be
 * PHP-serialized — the id-equivalent of storage_sherpa_replace_in_value()
 * above. Deliberately a separate function rather than one that handles both
 * strings and ids: once a serialized value is unserialized, an id stored as
 * `i:5;` becomes a genuine PHP int (5), not the string "5" — a string
 * search/replace pass over the resulting structure would never match it
 * (ints aren't strings), and matching it as a string everywhere else would
 * risk clobbering an unrelated string that merely contains "5" as a
 * substring (e.g. a caption reading "Photo 5 of 10"). This instead compares
 * each *leaf* by exact value: an int leaf equal to $old_id becomes $new_id;
 * a string leaf that is *exactly* (not merely contains) the numeric string
 * "$old_id" — some plugins store ids as strings — becomes "$new_id" too.
 * Every other leaf, string or otherwise, is left completely untouched.
 */
function storage_sherpa_replace_id_in_value( $value, $old_id, $new_id ) {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	if ( is_serialized( $value ) ) {
		$unserialized = @unserialize( trim( $value ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- deliberately tolerant of malformed input; see docblock above.

		if ( false === $unserialized && 'b:0;' !== trim( $value ) ) {
			return $value;
		}

		return serialize( storage_sherpa_walk_replace_id( $unserialized, (int) $old_id, (int) $new_id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- re-serializing what was just safely unserialized above.
	}

	// Not serialized — a bare numeric value (e.g. a plain "5" postmeta row).
	return ( is_numeric( $value ) && (int) $value === (int) $old_id ) ? (string) $new_id : $value;
}

function storage_sherpa_walk_replace_id( $data, $old_id, $new_id ) {
	if ( is_int( $data ) ) {
		return $data === $old_id ? $new_id : $data;
	}

	if ( is_string( $data ) ) {
		return ( ctype_digit( $data ) && (int) $data === $old_id ) ? (string) $new_id : $data;
	}

	if ( is_array( $data ) ) {
		$out = array();
		foreach ( $data as $key => $item ) {
			$out[ $key ] = storage_sherpa_walk_replace_id( $item, $old_id, $new_id );
		}
		return $out;
	}

	if ( is_object( $data ) ) {
		foreach ( $data as $key => $item ) {
			$data->$key = storage_sherpa_walk_replace_id( $item, $old_id, $new_id );
		}
		return $data;
	}

	return $data;
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
