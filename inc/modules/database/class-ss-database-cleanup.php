<?php
/**
 * Module 8 — Database Cleanup.
 *
 * Every category here is a well-defined, low-ambiguity row set (real
 * revisions, real orphaned meta rows, real expired transients...). Two
 * categories named in the spec were deliberately NOT put here:
 *
 * - "Orphan options" isn't implementable safely as a blind rule — there's
 *   no registry of which option belongs to which plugin, so guessing risks
 *   deleting a live setting. That's exactly what Module 10 (Plugin Cleanup)
 *   does instead, using a curated signature list keyed to specific
 *   deactivated plugins rather than a generic heuristic.
 * - "WooCommerce logs" are files under uploads/wc-logs/, not DB rows — that
 *   detection lives in Module 14 (Log Cleaner) so log-file handling stays
 *   in one place.
 * - "Temporary tables" (whole tables, not rows) is Module 9's job (Orphan
 *   DB Tables) — this module only ever deletes *rows*, never drops a table.
 *
 * Every row this module deletes is backed up via SS_Trash::trash_db_row()
 * first — restorable until the retention window closes, same as every
 * other module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Database_Cleanup {

	private static function categories() {
		global $wpdb;

		$defs = array(
			'revisions'            => array(
				'label' => __( 'Post Revisions', 'storage-sherpa' ),
				'table' => $wpdb->posts,
				'pk'    => 'ID',
				'where' => "post_type = 'revision'",
			),
			'auto_drafts'          => array(
				'label' => __( 'Auto Drafts', 'storage-sherpa' ),
				'table' => $wpdb->posts,
				'pk'    => 'ID',
				'where' => "post_status = 'auto-draft'",
			),
			'trash_posts'          => array(
				'label' => __( 'Trashed Posts', 'storage-sherpa' ),
				'table' => $wpdb->posts,
				'pk'    => 'ID',
				'where' => "post_status = 'trash' AND post_type = 'post'",
			),
			'trash_pages'          => array(
				'label' => __( 'Trashed Pages', 'storage-sherpa' ),
				'table' => $wpdb->posts,
				'pk'    => 'ID',
				'where' => "post_status = 'trash' AND post_type = 'page'",
			),
			'spam_comments'        => array(
				'label' => __( 'Spam Comments', 'storage-sherpa' ),
				'table' => $wpdb->comments,
				'pk'    => 'comment_ID',
				'where' => "comment_approved = 'spam'",
			),
			'trash_comments'       => array(
				'label' => __( 'Trashed Comments', 'storage-sherpa' ),
				'table' => $wpdb->comments,
				'pk'    => 'comment_ID',
				'where' => "comment_approved = 'trash'",
			),
			'trackbacks_pingbacks' => array(
				'label' => __( 'Trackbacks & Pingbacks', 'storage-sherpa' ),
				'table' => $wpdb->comments,
				'pk'    => 'comment_ID',
				'where' => "comment_type IN ('trackback','pingback')",
			),
			'orphan_postmeta'      => array(
				'label' => __( 'Orphan Post Meta', 'storage-sherpa' ),
				'table' => $wpdb->postmeta,
				'pk'    => 'meta_id',
				'join'  => "LEFT JOIN {$wpdb->posts} ss_p ON ss_p.ID = {$wpdb->postmeta}.post_id",
				'where' => 'ss_p.ID IS NULL',
			),
			'orphan_commentmeta'   => array(
				'label' => __( 'Orphan Comment Meta', 'storage-sherpa' ),
				'table' => $wpdb->commentmeta,
				'pk'    => 'meta_id',
				'join'  => "LEFT JOIN {$wpdb->comments} ss_c ON ss_c.comment_ID = {$wpdb->commentmeta}.comment_id",
				'where' => 'ss_c.comment_ID IS NULL',
			),
			'orphan_usermeta'      => array(
				'label' => __( 'Orphan User Meta', 'storage-sherpa' ),
				'table' => $wpdb->usermeta,
				'pk'    => 'umeta_id',
				'join'  => "LEFT JOIN {$wpdb->users} ss_u ON ss_u.ID = {$wpdb->usermeta}.user_id",
				'where' => 'ss_u.ID IS NULL',
			),
			'orphan_termmeta'      => array(
				'label' => __( 'Orphan Term Meta', 'storage-sherpa' ),
				'table' => $wpdb->termmeta,
				'pk'    => 'meta_id',
				'join'  => "LEFT JOIN {$wpdb->terms} ss_t ON ss_t.term_id = {$wpdb->termmeta}.term_id",
				'where' => 'ss_t.term_id IS NULL',
			),
			'orphan_relationships' => array(
				'label' => __( 'Orphan Term Relationships', 'storage-sherpa' ),
				'table' => $wpdb->term_relationships,
				'pk'    => null, // Composite key (object_id, term_taxonomy_id) — handled specially.
				'join'  => "LEFT JOIN {$wpdb->posts} ss_p2 ON ss_p2.ID = {$wpdb->term_relationships}.object_id",
				'where' => 'ss_p2.ID IS NULL',
			),
			'elementor_css_cache'  => array(
				'label' => __( 'Elementor CSS Cache', 'storage-sherpa' ),
				'table' => $wpdb->postmeta,
				'pk'    => 'meta_id',
				'where' => "meta_key = '_elementor_css'",
			),
			'seo_plugin_cache'     => array(
				'label' => __( 'Rank Math / Yoast Transient Cache', 'storage-sherpa' ),
				'table' => $wpdb->options,
				'pk'    => 'option_id',
				'where' => "(option_name LIKE '\\_transient\\_rank\\_math\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rank\\_math\\_%' "
					. "OR option_name LIKE '\\_transient\\_wpseo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wpseo\\_%')",
			),
		);

		if ( self::table_exists( $wpdb->prefix . 'woocommerce_sessions' ) ) {
			$defs['wc_sessions'] = array(
				'label' => __( 'Expired WooCommerce Sessions', 'storage-sherpa' ),
				'table' => $wpdb->prefix . 'woocommerce_sessions',
				'pk'    => 'session_id',
				'where' => 'session_expiry < UNIX_TIMESTAMP()',
			);
		}

		if ( self::table_exists( $wpdb->prefix . 'actionscheduler_actions' ) ) {
			$defs['action_scheduler_completed'] = array(
				'label' => __( 'Completed Scheduled Actions (30+ days)', 'storage-sherpa' ),
				'table' => $wpdb->prefix . 'actionscheduler_actions',
				'pk'    => 'action_id',
				'where' => "status = 'complete' AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL 30 DAY)",
			);
		}

		if ( self::table_exists( $wpdb->prefix . 'actionscheduler_logs' ) && self::table_exists( $wpdb->prefix . 'actionscheduler_actions' ) ) {
			$defs['action_scheduler_logs'] = array(
				'label' => __( 'Orphan Action Scheduler Logs', 'storage-sherpa' ),
				'table' => $wpdb->prefix . 'actionscheduler_logs',
				'pk'    => 'log_id',
				'join'  => "LEFT JOIN {$wpdb->prefix}actionscheduler_actions ss_as ON ss_as.action_id = {$wpdb->prefix}actionscheduler_logs.action_id",
				'where' => 'ss_as.action_id IS NULL',
			);
		}

		return $defs;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public static function safe_categories() {
		return array_keys( self::categories() );
	}

	public static function count( $key ) {
		global $wpdb;
		$def = self::categories()[ $key ] ?? null;
		if ( ! $def ) {
			return 0;
		}

		$join = isset( $def['join'] ) ? ' ' . $def['join'] : '';
		$sql  = "SELECT COUNT(*) FROM {$def['table']}{$join} WHERE {$def['where']}";

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- category definitions are static, not user input.
	}

	public static function summary() {
		$out = array();
		foreach ( self::categories() as $key => $def ) {
			$out[ $key ] = array(
				'label' => $def['label'],
				'count' => self::count( $key ),
			);
		}

		if ( self::transients_count() ) {
			$out['expired_transients'] = array(
				'label' => __( 'Expired Transients', 'storage-sherpa' ),
				'count' => self::transients_count(),
			);
		}

		return $out;
	}

	public static function preview( $key, $limit = 20 ) {
		global $wpdb;

		if ( 'expired_transients' === $key ) {
			return array_slice( self::expired_transient_option_names(), 0, $limit );
		}

		$def = self::categories()[ $key ] ?? null;
		if ( ! $def ) {
			return array();
		}

		$join = isset( $def['join'] ) ? ' ' . $def['join'] : '';
		$sql  = "SELECT {$def['table']}.* FROM {$def['table']}{$join} WHERE {$def['where']} LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes every row in the given categories, backing each one up to
	 * Safe Trash first. Returns per-category counts + total bytes freed.
	 */
	public static function run( array $keys, $run_type = 'manual' ) {
		global $wpdb;

		$results     = array();
		$grand_bytes = 0;

		foreach ( $keys as $key ) {
			if ( 'expired_transients' === $key ) {
				$result = self::clean_expired_transients();
				$results[ $key ] = $result;
				$grand_bytes     += $result['bytes'];
				continue;
			}

			$def = self::categories()[ $key ] ?? null;
			if ( ! $def ) {
				continue;
			}

			$join = isset( $def['join'] ) ? ' ' . $def['join'] : '';
			$rows = $wpdb->get_results( "SELECT {$def['table']}.* FROM {$def['table']}{$join} WHERE {$def['where']} LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$count = 0;
			$bytes = 0;

			foreach ( (array) $rows as $row ) {
				$bytes += strlen( wp_json_encode( $row ) );

				SS_Trash::trash_db_row( $def['table'], $row, 'database_cleanup', $def['label'] );

				if ( null === $def['pk'] ) {
					// Composite key (term_relationships): delete by the two columns directly.
					$wpdb->delete(
						$def['table'],
						array(
							'object_id'        => $row['object_id'],
							'term_taxonomy_id' => $row['term_taxonomy_id'],
						)
					);
				} else {
					$wpdb->delete( $def['table'], array( $def['pk'] => $row[ $def['pk'] ] ) );
				}

				++$count;
			}

			$results[ $key ] = array(
				'label' => $def['label'],
				'count' => $count,
				'bytes' => $bytes,
			);
			$grand_bytes += $bytes;

			if ( $count > 0 ) {
				storage_sherpa_log_cleanup( 'database', $key, $count, $bytes, $run_type );
			}
		}

		return array(
			'categories' => $results,
			'bytes'      => $grand_bytes,
		);
	}

	private static function expired_transient_option_names() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			fn( $row ) => str_replace( '_transient_timeout_', '', $row->option_name ),
			(array) $rows
		);
	}

	private static function transients_count() {
		return count( self::expired_transient_option_names() );
	}

	private static function clean_expired_transients() {
		global $wpdb;

		$names = self::expired_transient_option_names();
		$count = 0;
		$bytes = 0;

		foreach ( $names as $name ) {
			foreach ( array( '_transient_' . $name, '_transient_timeout_' . $name ) as $option_name ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name = %s", $option_name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $row ) {
					$bytes += strlen( wp_json_encode( $row ) );
					SS_Trash::trash_db_row( $wpdb->options, $row, 'database_cleanup', 'Expired Transient' );
					$wpdb->delete( $wpdb->options, array( 'option_name' => $option_name ) );
				}
			}
			++$count;
		}

		if ( $count > 0 ) {
			storage_sherpa_log_cleanup( 'database', 'expired_transients', $count, $bytes, 'manual' );
		}

		return array(
			'label' => __( 'Expired Transients', 'storage-sherpa' ),
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * OPTIMIZE/REPAIR/ANALYZE TABLE for every {prefix}-owned table. These
	 * don't delete rows, so they run directly (nothing to route through
	 * Safe Trash) — only the reclaimed byte count is logged.
	 */
	public static function table_maintenance( $action ) {
		global $wpdb;

		$action = strtoupper( $action );
		if ( ! in_array( $action, array( 'OPTIMIZE', 'REPAIR', 'ANALYZE' ), true ) ) {
			return new WP_Error( 'ss_invalid_action', __( 'Invalid table maintenance action.', 'storage-sherpa' ) );
		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		$before = self::total_table_bytes( $tables );
		$results = array();

		foreach ( $tables as $table ) {
			if ( SS_Ignore_Rules::is_table_ignored( $table ) ) {
				continue;
			}
			$results[ $table ] = $wpdb->get_results( "{$action} TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedDynamicPlaceholderGeneration
		}

		$after = self::total_table_bytes( $tables );
		$freed = max( 0, $before - $after );

		storage_sherpa_log_cleanup( 'database', strtolower( $action ) . '_tables', count( $results ), $freed, 'manual' );

		return array(
			'tables' => count( $results ),
			'before' => $before,
			'after'  => $after,
			'freed'  => $freed,
		);
	}

	private static function total_table_bytes( array $tables ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			return 0;
		}

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : $wpdb->dbname;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name IN (' . implode( ',', array_fill( 0, count( $tables ), '%s' ) ) . ')',
				array_merge( array( $db_name ), $tables )
			)
		);
	}
}
