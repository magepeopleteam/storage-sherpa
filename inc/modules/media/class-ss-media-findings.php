<?php
/**
 * Shared data-access layer for {prefix}ss_media_findings, backing Modules
 * 2 (Orphan Media), 3 (Duplicate Media), 4 (Large Files), and 7 (Broken
 * Media). One table, one CRUD surface, disambiguated by finding_type — so
 * the four scanners share storage/query/bulk-action code instead of each
 * growing its own near-identical table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Media_Findings {

	const TYPE_ORPHAN       = 'orphan';
	const TYPE_DUPLICATE    = 'duplicate';
	const TYPE_LARGE        = 'large';
	const TYPE_BROKEN       = 'broken';
	const TYPE_UNUSED_SIZE  = 'unused_size';
	const TYPE_BROKEN_LINK  = 'broken_link';
	const TYPE_OVERSIZED    = 'oversized';

	/**
	 * Replaces all stored findings of a given type with a fresh scan result.
	 * Each $row: attachment_id, file_path, status, reason, file_size,
	 * group_hash (optional), confidence (optional, 0-100).
	 */
	public static function replace_all( $finding_type, array $rows ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ss_media_findings';

		$wpdb->delete( $table, array( 'finding_type' => $finding_type ), array( '%s' ) );

		$now = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'finding_type'  => $finding_type,
					'attachment_id' => isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0,
					'file_path'     => isset( $row['file_path'] ) ? $row['file_path'] : null,
					'status'        => isset( $row['status'] ) ? $row['status'] : 'unused',
					'reason'        => isset( $row['reason'] ) ? $row['reason'] : null,
					'file_size'     => isset( $row['file_size'] ) ? (int) $row['file_size'] : 0,
					'group_hash'    => isset( $row['group_hash'] ) ? $row['group_hash'] : null,
					'confidence'    => isset( $row['confidence'] ) ? (int) $row['confidence'] : 0,
					'checked_at'    => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
			);
		}

		return count( $rows );
	}

	/**
	 * A 0-100 confidence score's human label — "100% safe" reads very
	 * differently to a site owner than "possibly used", which is the whole
	 * point of exposing a score instead of a binary used/orphan verdict.
	 */
	public static function confidence_label( $confidence ) {
		$confidence = (int) $confidence;

		if ( $confidence >= 95 ) {
			return __( '100% safe to delete', 'storage-sherpa' );
		}

		if ( $confidence >= 70 ) {
			return sprintf(
				/* translators: %d: confidence percentage */
				__( '%d%% confident — likely used', 'storage-sherpa' ),
				$confidence
			);
		}

		if ( $confidence > 0 ) {
			return sprintf(
				/* translators: %d: confidence percentage */
				__( '%d%% confident — possibly used', 'storage-sherpa' ),
				$confidence
			);
		}

		return __( 'No usage detected', 'storage-sherpa' );
	}

	/**
	 * Shared WHERE-clause builder for query()/ids()/count_matching() — one
	 * place that knows how `status`, `search` (file name / path, matched via
	 * LIKE against file_path), and `file_type` (a SS_Filetype_Analyzer
	 * category — images/videos/pdfs/zip/etc., matched by extension) each
	 * translate into SQL, so the three read paths can never drift out of
	 * sync with each other.
	 */
	private static function build_where( $finding_type, $args ) {
		global $wpdb;

		$where  = array( 'finding_type = %s' );
		$params = array( $finding_type );

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'file_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( ! empty( $args['file_type'] ) && class_exists( 'SS_Filetype_Analyzer' ) ) {
			$categories = SS_Filetype_Analyzer::categories();

			if ( 'unknown' === $args['file_type'] ) {
				// No extension in SS_Filetype_Analyzer's known list — build a
				// NOT LIKE for every one of them instead of trying to name
				// every "other" extension a plugin might have saved.
				$clauses = array();
				foreach ( SS_Filetype_Analyzer::all_extensions() as $ext ) {
					$clauses[] = 'file_path NOT LIKE %s';
					$params[]  = '%.' . $wpdb->esc_like( $ext );
				}
				if ( $clauses ) {
					$where[] = 'file_path IS NOT NULL AND (' . implode( ' AND ', $clauses ) . ')';
				}
			} elseif ( isset( $categories[ $args['file_type'] ] ) ) {
				$clauses = array();
				foreach ( $categories[ $args['file_type'] ] as $ext ) {
					$clauses[] = 'file_path LIKE %s';
					$params[]  = '%.' . $wpdb->esc_like( $ext );
				}
				$where[] = '(' . implode( ' OR ', $clauses ) . ')';
			}
		}

		return array( $where, $params );
	}

	public static function query( $finding_type, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'    => '',
			'search'    => '',
			'file_type' => '',
			'orderby'   => 'file_size DESC',
			'limit'     => 200,
			'offset'    => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where, $params ) = self::build_where( $finding_type, $args );

		$sql = "SELECT * FROM {$wpdb->prefix}ss_media_findings WHERE " . implode( ' AND ', $where )
			. ' ORDER BY ' . esc_sql( $args['orderby'] )
			. ' LIMIT %d OFFSET %d';

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Every id matching status/search, unpaginated — backs the Media
	 * Findings screen's "select all N items matching this filter" bulk
	 * action, which needs the full id list up front so the browser can walk
	 * it in small chunks (see SS_REST_API::media_trash()) instead of the
	 * server trying to process everything in one request.
	 */
	public static function ids( $finding_type, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'    => '',
			'search'    => '',
			'file_type' => '',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where, $params ) = self::build_where( $finding_type, $args );

		$sql = "SELECT id FROM {$wpdb->prefix}ss_media_findings WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Total rows matching status/search — used for pagination once a search
	 * term narrows the result set below the unfiltered per-status counts().
	 */
	public static function count_matching( $finding_type, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'    => '',
			'search'    => '',
			'file_type' => '',
		);
		$args = wp_parse_args( $args, $defaults );

		list( $where, $params ) = self::build_where( $finding_type, $args );

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}ss_media_findings WHERE " . implode( ' AND ', $where );

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_media_findings WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'ss_media_findings', array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Removes every finding row for a given attachment within one finding
	 * type — used after SS_Duplicate_Finder::merge_attachment() trashes the
	 * attachment itself, since the finding row is keyed by its own id, not
	 * the attachment id the merge flow naturally has on hand.
	 */
	public static function delete_by_attachment( $finding_type, $attachment_id ) {
		global $wpdb;
		return (int) $wpdb->delete(
			$wpdb->prefix . 'ss_media_findings',
			array(
				'finding_type'  => $finding_type,
				'attachment_id' => (int) $attachment_id,
			),
			array( '%s', '%d' )
		);
	}

	public static function counts( $finding_type ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS total, SUM(file_size) AS bytes
				 FROM {$wpdb->prefix}ss_media_findings
				 WHERE finding_type = %s
				 GROUP BY status",
				$finding_type
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = array(
				'count' => (int) $row->total,
				'bytes' => (int) $row->bytes,
			);
		}

		return $out;
	}

	public static function last_checked( $finding_type ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT MAX(checked_at) FROM {$wpdb->prefix}ss_media_findings WHERE finding_type = %s", $finding_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
