<?php
/**
 * Module 9 — Orphan Database Tables.
 *
 * There's no registry mapping "table X belongs to plugin Y", so this is
 * necessarily a best-effort heuristic, not a certainty: any {$wpdb->prefix}
 * table that (a) isn't a WordPress core table and (b) doesn't contain any
 * currently-active plugin or theme's slug as a substring, AND (c) doesn't
 * start with an abbreviation derived from an active plugin's own display
 * name (see known_table_prefixes() — this is what catches plugins like Easy
 * Digital Downloads, whose `edd_*` tables share nothing with its
 * `easy-digital-downloads` folder slug), gets listed as a candidate, with
 * the "estimated plugin" being the guessed slug fragment. Never
 * auto-deleted — the spec calls this "delete manually" for exactly that
 * reason. drop_table() takes a full CREATE TABLE + row dump backup before
 * dropping, restorable from the Recovery Center like anything else.
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
	 * Every active plugin file (site-level, plus network-activated ones on
	 * multisite), as the `folder/file.php` strings WordPress stores them as.
	 */
	private static function active_plugin_files() {
		$files = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_unique( $files );
	}

	/**
	 * Slug fragments considered "in use" via a loose contains-anywhere
	 * match: every active plugin's directory slug and the active theme's
	 * stylesheet/template slugs. Works for the common case where a plugin's
	 * own tables are named after its folder (WooCommerce, Elementor, etc.).
	 */
	private static function known_slugs() {
		$slugs = array();

		foreach ( self::active_plugin_files() as $plugin_file ) {
			$slug = strtok( $plugin_file, '/' );
			$slugs[] = strtolower( str_replace( '-', '', $slug ) );
			$slugs[] = strtolower( str_replace( '-', '_', $slug ) );
		}

		$theme = wp_get_theme();
		$slugs[] = strtolower( str_replace( '-', '', $theme->get_stylesheet() ) );
		$slugs[] = strtolower( str_replace( '-', '', $theme->get_template() ) );

		return array_unique( array_filter( $slugs ) );
	}

	/**
	 * Plenty of active plugins ship DB tables prefixed with an abbreviation
	 * that bears no resemblance to their own folder slug — Easy Digital
	 * Downloads' tables are `{$wpdb->prefix}edd_*`, not
	 * `{$wpdb->prefix}easydigitaldownloads_*`; Gravity Forms uses `gf_*`;
	 * this very plugin's own tables are `{$wpdb->prefix}ss_*`, not
	 * `{$wpdb->prefix}storagesherpa_*`. known_slugs()'s folder-slug matching
	 * can never connect a table like that back to the plugin that owns it,
	 * even while the plugin is active and using the table every day.
	 *
	 * Rather than hardcode a list of known plugins, this derives the same
	 * kind of abbreviation any plugin author would land on from the
	 * plugin's own declared display name (its "Name:" header) — initials
	 * ("Easy Digital Downloads" → "edd", "Storage Sherpa" → "ss") and the
	 * first word ("Yoast SEO" → "yoast"). Only ever built from plugins
	 * WordPress confirms are active right now, so a table only gets
	 * whitelisted when the plugin that owns it verifiably is too.
	 *
	 * Matched with an anchored prefix check (see remainder_matches_prefix())
	 * rather than known_slugs()'s loose "contains anywhere", since a 2-3
	 * letter abbreviation like "gf" or "ss" is short enough that requiring
	 * it to actually be the table's prefix — not just appear somewhere in
	 * the name — matters for avoiding accidental matches.
	 */
	private static function known_table_prefixes() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$prefixes = array();

		foreach ( self::active_plugin_files() as $plugin_file ) {
			$path = WP_PLUGIN_DIR . '/' . $plugin_file;

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$data = get_plugin_data( $path, false, false );

			if ( empty( $data['Name'] ) ) {
				continue;
			}

			$prefixes = array_merge( $prefixes, self::derive_prefix_candidates( $data['Name'] ) );
		}

		return array_unique( $prefixes );
	}

	/**
	 * "Easy Digital Downloads" → array( 'edd', 'easy' );
	 * "Storage Sherpa" → array( 'ss', 'storage' );
	 * "WooCommerce" → array() — a single-word name has no initials worth
	 * deriving, and its first word is already covered by known_slugs()'s
	 * folder-slug match.
	 */
	private static function derive_prefix_candidates( $name ) {
		$words = array_values( array_filter( preg_split( '/[\s\-]+/', (string) $name ) ) );

		if ( count( $words ) < 2 ) {
			return array();
		}

		$candidates = array();
		$initials   = '';

		foreach ( $words as $word ) {
			$letter = strtolower( substr( preg_replace( '/[^a-z0-9]/i', '', $word ), 0, 1 ) );
			$initials .= $letter;
		}

		if ( strlen( $initials ) >= 2 ) {
			$candidates[] = $initials;
		}

		$first_word = strtolower( preg_replace( '/[^a-z0-9]/i', '', $words[0] ) );

		if ( strlen( $first_word ) >= 3 ) {
			$candidates[] = $first_word;
		}

		return $candidates;
	}

	/**
	 * True when $remainder's table-name-after-the-wpdb-prefix genuinely
	 * starts with $prefix as its own segment — `edd_adjustments` matches
	 * `edd`, `nf3_entries` matches `nf` (versioned prefixes), and a bare
	 * `edd` table (no trailing underscore) matches too — but `eddington_log`
	 * does not, which a plain "starts with" check would have wrongly allowed.
	 */
	private static function remainder_matches_prefix( $remainder, $prefix ) {
		if ( ! $prefix ) {
			return false;
		}

		return (bool) preg_match( '/^' . preg_quote( $prefix, '/' ) . '(_|\d|$)/', $remainder );
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

		$core     = self::core_tables();
		$slugs    = self::known_slugs();
		$prefixes = self::known_table_prefixes();
		$suffix   = substr( $wpdb->prefix, -1 ) === '_' ? $wpdb->prefix : $wpdb->prefix . '_';

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

			if ( ! $matched ) {
				foreach ( $prefixes as $prefix ) {
					if ( self::remainder_matches_prefix( $remainder, $prefix ) ) {
						$matched = true;
						break;
					}
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
