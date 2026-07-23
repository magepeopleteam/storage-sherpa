<?php
/**
 * Module 16 — Autoload Option Analyzer.
 *
 * "Disable autoload" only flips wp_options.autoload to 'no' — the option
 * and its value are untouched, so this doesn't go through Safe Trash (there
 * is nothing destructive to restore; toggling autoload back to 'yes' is a
 * complete, instant undo). Every large autoload option gets loaded into
 * memory on *every* single WordPress request via wp_load_alloptions(), so
 * this is a real, common source of avoidable memory/latency overhead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Autoload_Analyzer {

	public static function scan( $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size_bytes
				 FROM {$wpdb->options}
				 WHERE autoload = 'yes'
				 ORDER BY size_bytes DESC
				 LIMIT %d",
				$limit
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$slugs = self::plugin_slug_map();

		return array_map(
			function ( $row ) use ( $slugs ) {
				return array(
					'option_name' => $row->option_name,
					'size'        => (int) $row->size_bytes,
					'owner'       => self::guess_owner( $row->option_name, $slugs ),
				);
			},
			(array) $rows
		);
	}

	public static function total_autoload_size() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function plugin_slug_map() {
		$map = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$slug         = strtok( $plugin_file, '/' );
			$map[ $slug ] = $slug;
		}
		return $map;
	}

	private static function guess_owner( $option_name, $slugs ) {
		$normalized = strtolower( str_replace( array( '-', ' ' ), '_', $option_name ) );

		foreach ( $slugs as $slug ) {
			$slug_normalized = strtolower( str_replace( '-', '_', $slug ) );
			if ( $slug_normalized && false !== strpos( $normalized, $slug_normalized ) ) {
				return $slug;
			}
		}

		if ( 0 === strpos( $option_name, 'widget_' ) || 'sidebars_widgets' === $option_name ) {
			return __( 'WordPress core (widgets)', 'storage-sherpa' );
		}

		if ( in_array( $option_name, array( 'theme_mods_' . get_option( 'stylesheet' ) ), true ) ) {
			return __( 'Active theme', 'storage-sherpa' );
		}

		return __( 'Unknown', 'storage-sherpa' );
	}

	public static function set_autoload( $option_name, $autoload ) {
		global $wpdb;

		$autoload = $autoload ? 'yes' : 'no';

		$updated = $wpdb->update(
			$wpdb->options,
			array( 'autoload' => $autoload ),
			array( 'option_name' => $option_name ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false !== $updated ) {
			storage_sherpa_log_cleanup( 'autoload', ( 'no' === $autoload ? 'disable_autoload' : 'enable_autoload' ) . ':' . $option_name, 1, 0 );
		}

		wp_cache_delete( 'alloptions', 'options' );

		return false !== $updated;
	}
}
