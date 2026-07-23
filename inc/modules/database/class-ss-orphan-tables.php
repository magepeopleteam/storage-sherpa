<?php
/**
 * Module 9 — Orphan Database Tables.
 *
 * There's no registry mapping "table X belongs to plugin Y", so this is
 * necessarily a best-effort heuristic, not a certainty: any {$wpdb->prefix}
 * table that (a) isn't a WordPress core table and (b) doesn't contain any
 * currently-active plugin or theme's slug as a substring gets listed as a
 * candidate, with the "estimated plugin" being the guessed slug fragment.
 * Never auto-deleted — the spec calls this "delete manually" for exactly
 * that reason. drop_table() takes a full CREATE TABLE + row dump backup
 * before dropping, restorable from the Recovery Center like anything else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Orphan_Tables {

	const MAX_BACKUP_ROWS = 5000;

	private static function core_tables() {
		global $wpdb;

		return array_map(
			'strtolower',
			array(
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->comments,
				$wpdb->commentmeta,
				$wpdb->terms,
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				$wpdb->termmeta,
				$wpdb->users,
				$wpdb->usermeta,
				$wpdb->options,
				$wpdb->links,
				$wpdb->prefix . 'actionscheduler_actions',
				$wpdb->prefix . 'actionscheduler_claims',
				$wpdb->prefix . 'actionscheduler_groups',
				$wpdb->prefix . 'actionscheduler_logs',
			)
		);
	}

	/**
	 * Slug fragments considered "in use": every active plugin's directory
	 * slug, the active theme's stylesheet/template slugs, and WooCommerce's
	 * own known table family (always active-plugin-detectable via its slug).
	 */
	private static function known_slugs() {
		$slugs = array();

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$slug = strtok( $plugin_file, '/' );
			$slugs[] = strtolower( str_replace( '-', '', $slug ) );
			$slugs[] = strtolower( str_replace( '-', '_', $slug ) );
		}

		$theme = wp_get_theme();
		$slugs[] = strtolower( str_replace( '-', '', $theme->get_stylesheet() ) );
		$slugs[] = strtolower( str_replace( '-', '', $theme->get_template() ) );

		return array_unique( array_filter( $slugs ) );
	}

	public static function scan() {
		global $wpdb;

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;

		// information_schema always returns its columns in uppercase on this
		// server regardless of how they're written in the query — explicit
		// lowercase AS aliases are required to get predictable property names.
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name AS table_name, table_rows AS table_rows, data_length AS data_length, index_length AS index_length, update_time AS update_time
				 FROM information_schema.TABLES
				 WHERE table_schema = %s AND table_name LIKE %s",
				$db_name,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$core   = self::core_tables();
		$slugs  = self::known_slugs();
		$suffix = substr( $wpdb->prefix, -1 ) === '_' ? $wpdb->prefix : $wpdb->prefix . '_';

		$orphans = array();

		foreach ( (array) $tables as $table ) {
			$name = strtolower( $table->table_name );

			if ( in_array( $name, $core, true ) || SS_Ignore_Rules::is_table_ignored( $table->table_name ) ) {
				continue;
			}

			$remainder = str_replace( strtolower( $wpdb->prefix ), '', $name );
			$matched   = false;

			foreach ( $slugs as $slug ) {
				if ( $slug && false !== strpos( $remainder, $slug ) ) {
					$matched = true;
					break;
				}
			}

			if ( $matched ) {
				continue;
			}

			$guess = preg_replace( '/_.*/', '', $remainder );

			$orphans[] = array(
				'table'            => $table->table_name,
				'rows'             => (int) $table->table_rows,
				'size'             => (int) $table->data_length + (int) $table->index_length,
				'estimated_plugin' => $guess ? $guess : __( 'Unknown', 'storage-sherpa' ),
				'last_modified'    => $table->update_time,
			);
		}

		return $orphans;
	}

	public static function drop_table( $table_name ) {
		global $wpdb;

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;
		$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $exists !== $table_name ) {
			return new WP_Error( 'ss_not_found', __( 'Table does not exist.', 'storage-sherpa' ) );
		}

		if ( 0 !== strpos( $table_name, $wpdb->prefix ) ) {
			return new WP_Error( 'ss_unsafe_table', __( 'Refusing to drop a table outside this site\'s prefix.', 'storage-sherpa' ) );
		}

		$create_sql = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration
		$row_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration

		$rows      = $wpdb->get_results( "SELECT * FROM `{$table_name}` LIMIT " . self::MAX_BACKUP_ROWS, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration
		$truncated = $row_count > self::MAX_BACKUP_ROWS;

		$dump = wp_json_encode(
			array(
				'create_sql' => $create_sql ? $create_sql[0] : null,
				'rows'       => $rows,
				'truncated'  => $truncated,
			)
		);

		$size = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT (data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
				$db_name,
				$table_name
			)
		);

		$trash_id = self::insert_table_dump_trash( $table_name, $dump, $size, $truncated );

		$wpdb->query( "DROP TABLE `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration

		storage_sherpa_log_cleanup( 'orphan_tables', 'drop_table:' . $table_name, 1, $size );

		return array(
			'trash_id'  => $trash_id,
			'truncated' => $truncated,
			'size'      => $size,
		);
	}

	private static function insert_table_dump_trash( $table_name, $dump_json, $size, $truncated ) {
		global $wpdb;

		$settings = storage_sherpa_get_settings();
		$days     = max( 1, (int) $settings['retention_days'] );

		$wpdb->insert(
			$wpdb->prefix . 'ss_trash_items',
			array(
				'item_type'  => 'table_dump',
				'module'     => 'orphan_tables',
				'label'      => $table_name . ( $truncated ? ' (partial backup — table exceeded ' . self::MAX_BACKUP_ROWS . ' rows)' : '' ),
				'table_name' => $table_name,
				'row_data'   => $dump_json,
				'size_bytes' => $size,
				'deleted_at' => current_time( 'mysql' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ),
				'restored'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
