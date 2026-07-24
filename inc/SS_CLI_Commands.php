<?php
/**
 * Module 25 — WP-CLI commands.
 *
 * Thin wrappers around the same static module methods the REST API (and
 * every admin screen) already calls — one implementation of each behavior;
 * this file is just a third caller alongside the REST routes and the
 * server-rendered admin screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_CLI_Commands {

	private static function media_type_map() {
		return array(
			'orphan'      => array( 'SS_Orphan_Media_Scanner', SS_Media_Findings::TYPE_ORPHAN ),
			'duplicate'   => array( 'SS_Duplicate_Finder', SS_Media_Findings::TYPE_DUPLICATE ),
			'large'       => array( 'SS_Large_File_Scanner', SS_Media_Findings::TYPE_LARGE ),
			'broken'      => array( 'SS_Broken_Media', SS_Media_Findings::TYPE_BROKEN ),
			'unused_size' => array( 'SS_Unused_Sizes_Cleaner', SS_Media_Findings::TYPE_UNUSED_SIZE ),
			'broken_link' => array( 'SS_Broken_Links_Scanner', SS_Media_Findings::TYPE_BROKEN_LINK ),
			'oversized'   => array( 'SS_Oversized_Images', SS_Media_Findings::TYPE_OVERSIZED ),
		);
	}

	/**
	 * Runs a full storage scan across every category.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa scan
	 */
	public static function scan( $args, $assoc_args ) {
		$results = SS_Storage_Analyzer::run_full_scan();

		$rows = array();
		foreach ( $results as $row ) {
			$rows[] = array(
				'category' => $row['label'],
				'size'     => storage_sherpa_format_bytes( $row['size'] ),
				'items'    => $row['count'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'category', 'size', 'items' ) );
		WP_CLI::success( 'Full scan complete.' );
	}

	/**
	 * Lists findings for a media-findings category.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : orphan, duplicate, large, broken, unused_size, broken_link, or oversized.
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to return. Default 100.
	 *
	 * [--format=<format>]
	 * : Render as table, csv, json, yaml, or count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa media list orphan
	 *     wp storage-sherpa media list orphan --status=unused
	 */
	public static function media_list( $args, $assoc_args ) {
		list( $type ) = $args;
		$map = self::media_type_map();

		if ( ! isset( $map[ $type ] ) ) {
			WP_CLI::error( sprintf( 'Unknown type "%s". Valid: %s', $type, implode( ', ', array_keys( $map ) ) ) );
			return;
		}

		list( , $finding_type ) = $map[ $type ];

		$rows = SS_Media_Findings::query(
			$finding_type,
			array(
				'status' => isset( $assoc_args['status'] ) ? $assoc_args['status'] : '',
				'limit'  => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 100,
			)
		);

		$out = array_map(
			function ( $row ) {
				return array(
					'id'         => $row->id,
					'file'       => $row->file_path ? basename( $row->file_path ) : ( '#' . $row->attachment_id ),
					'status'     => $row->status,
					'confidence' => $row->confidence,
					'size'       => storage_sherpa_format_bytes( $row->file_size ),
				);
			},
			$rows
		);

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$out,
			array( 'id', 'file', 'status', 'confidence', 'size' )
		);
	}

	/**
	 * Runs a scan for a media-findings category.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : orphan, duplicate, large, broken, unused_size, broken_link, or oversized.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa media scan orphan
	 */
	public static function media_scan( $args, $assoc_args ) {
		list( $type ) = $args;
		$map = self::media_type_map();

		if ( ! isset( $map[ $type ] ) ) {
			WP_CLI::error( sprintf( 'Unknown type "%s". Valid: %s', $type, implode( ', ', array_keys( $map ) ) ) );
			return;
		}

		list( $class ) = $map[ $type ];

		$rows = call_user_func( array( $class, 'run_scan' ) );

		WP_CLI::success( sprintf( 'Found %d finding(s).', count( $rows ) ) );
	}

	/**
	 * Lists items currently in Safe Trash.
	 *
	 * [--limit=<limit>]
	 * : Maximum rows to return. Default 50.
	 *
	 * [--format=<format>]
	 * : Render as table, csv, json, yaml, or count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa trash list
	 */
	public static function trash_list( $args, $assoc_args ) {
		$items = SS_Trash::query( array( 'limit' => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50 ) );

		$out = array_map(
			function ( $item ) {
				return array(
					'id'      => $item->id,
					'label'   => $item->label,
					'module'  => $item->module,
					'size'    => storage_sherpa_format_bytes( $item->size_bytes ),
					'expires' => $item->expires_at,
				);
			},
			$items
		);

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$out,
			array( 'id', 'label', 'module', 'size', 'expires' )
		);
	}

	/**
	 * Permanently deletes every expired Safe Trash item.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa trash sweep
	 */
	public static function trash_sweep( $args, $assoc_args ) {
		$count = SS_Trash::sweep_expired();
		WP_CLI::success( sprintf( 'Permanently deleted %d expired item(s).', $count ) );
	}

	/**
	 * Prints the total space saved and a per-module breakdown.
	 *
	 * ## EXAMPLES
	 *
	 *     wp storage-sherpa reports summary
	 */
	public static function reports_summary( $args, $assoc_args ) {
		WP_CLI::log( sprintf( 'Total space saved: %s', storage_sherpa_format_bytes( SS_Reports::total_saved() ) ) );
		WP_CLI::log( sprintf( 'Items cleaned: %d', SS_Reports::total_items_cleaned() ) );

		$rows = array_map(
			function ( $row ) {
				return array(
					'module' => $row['module'],
					'size'   => storage_sherpa_format_bytes( $row['bytes'] ),
					'items'  => $row['items'],
				);
			},
			SS_Reports::by_module()
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'module', 'size', 'items' ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'storage-sherpa scan', array( 'SS_CLI_Commands', 'scan' ) );
	WP_CLI::add_command( 'storage-sherpa media list', array( 'SS_CLI_Commands', 'media_list' ) );
	WP_CLI::add_command( 'storage-sherpa media scan', array( 'SS_CLI_Commands', 'media_scan' ) );
	WP_CLI::add_command( 'storage-sherpa trash list', array( 'SS_CLI_Commands', 'trash_list' ) );
	WP_CLI::add_command( 'storage-sherpa trash sweep', array( 'SS_CLI_Commands', 'trash_sweep' ) );
	WP_CLI::add_command( 'storage-sherpa reports summary', array( 'SS_CLI_Commands', 'reports_summary' ) );
}
