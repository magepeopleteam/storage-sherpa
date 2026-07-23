<?php
/**
 * Module 23 — Ignore Rules.
 *
 * A small always-on gate every scanner/cleanup module calls through
 * (storage_sherpa_is_ignored_path() → self::is_ignored()) so "never touch
 * this folder/file/extension/table" is enforced in exactly one place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Ignore_Rules {

	const TYPES = array( 'folder', 'file', 'extension', 'table', 'image', 'plugin', 'theme' );

	private static $cache = null;

	public static function init() {
		// No hooks needed today — rules are read on demand via is_ignored()/all().
		// Reserved init() for parity with every other module's boot pattern.
	}

	/**
	 * Returns all rules, grouped by type. Cached per-request.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		global $wpdb;

		$rows = $wpdb->get_results( "SELECT id, rule_type, value FROM {$wpdb->prefix}ss_ignore_rules ORDER BY rule_type, value" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input.

		$grouped = array_fill_keys( self::TYPES, array() );

		foreach ( (array) $rows as $row ) {
			if ( isset( $grouped[ $row->rule_type ] ) ) {
				$grouped[ $row->rule_type ][] = array(
					'id'    => (int) $row->id,
					'value' => $row->value,
				);
			}
		}

		self::$cache = $grouped;

		return $grouped;
	}

	public static function add( $rule_type, $value ) {
		if ( ! in_array( $rule_type, self::TYPES, true ) ) {
			return false;
		}

		global $wpdb;

		$value = trim( $rule_type === 'folder' || $rule_type === 'file' ? storage_sherpa_normalize_path( $value ) : $value );

		if ( '' === $value ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'ss_ignore_rules',
			array(
				'rule_type'  => $rule_type,
				'value'      => $value,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		self::$cache = null;

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	public static function remove( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( $wpdb->prefix . 'ss_ignore_rules', array( 'id' => (int) $id ), array( '%d' ) );

		self::$cache = null;

		return (bool) $deleted;
	}

	/**
	 * True if the given absolute filesystem path falls under an ignored
	 * folder, matches an ignored file exactly, or ends in an ignored extension.
	 */
	public static function is_ignored( $path ) {
		$rules = self::all();
		$norm  = storage_sherpa_normalize_path( $path );

		foreach ( $rules['folder'] as $rule ) {
			$rule_path = storage_sherpa_normalize_path( $rule['value'] );
			if ( $norm === $rule_path || 0 === strpos( $norm . '/', $rule_path . '/' ) ) {
				return true;
			}
		}

		foreach ( $rules['file'] as $rule ) {
			if ( $norm === storage_sherpa_normalize_path( $rule['value'] ) ) {
				return true;
			}
		}

		$ext = strtolower( pathinfo( $norm, PATHINFO_EXTENSION ) );

		if ( $ext ) {
			foreach ( $rules['extension'] as $rule ) {
				if ( strtolower( ltrim( $rule['value'], '.' ) ) === $ext ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function is_table_ignored( $table_name ) {
		foreach ( self::all()['table'] as $rule ) {
			if ( strtolower( $rule['value'] ) === strtolower( $table_name ) ) {
				return true;
			}
		}

		return false;
	}

	public static function is_attachment_ignored( $attachment_id ) {
		foreach ( self::all()['image'] as $rule ) {
			if ( (int) $rule['value'] === (int) $attachment_id ) {
				return true;
			}
		}

		return false;
	}

	public static function is_plugin_ignored( $plugin_slug ) {
		foreach ( self::all()['plugin'] as $rule ) {
			if ( $rule['value'] === $plugin_slug ) {
				return true;
			}
		}

		return false;
	}

	public static function is_theme_ignored( $theme_slug ) {
		foreach ( self::all()['theme'] as $rule ) {
			if ( $rule['value'] === $theme_slug ) {
				return true;
			}
		}

		return false;
	}
}
