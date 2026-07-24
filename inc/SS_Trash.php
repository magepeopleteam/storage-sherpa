<?php
/**
 * Module 19 — Recovery Center (Safe Trash).
 *
 * The single choke point every other module's "delete" action routes
 * through. Nothing in Storage Sherpa ever calls unlink()/$wpdb->delete()
 * directly on user data — it calls trash_file()/trash_db_row() here first,
 * which is what makes every cleanup restorable until the retention window
 * (Settings → General, default 15 days) expires.
 *
 * Safety pipeline: Scan → Review → Backup → Move to Safe Trash → Restore
 * Available → Permanent Delete. "Backup" for a DB row *is* row_data stored
 * on the trash entry — there's no separate mysqldump step; the trash row is
 * the backup. A full-instance backup tool is out of scope (Module 12 only
 * detects/cleans *other* backup plugins' output, it doesn't create backups).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Trash {

	public static function init() {
		// Hooked from SS_Cron (storage_sherpa_trash_sweep_event) rather than here.
	}

	public static function dir() {
		return SS_Install::trash_dir();
	}

	/**
	 * Moves a real file into the Safe Trash and records a restorable entry.
	 * Refuses anything outside ABSPATH. Returns the trash row id or WP_Error.
	 * $batch_id (optional) ties this row to every other row created by the
	 * same bulk action, so restore_batch() can undo the whole action at once.
	 */
	public static function trash_file( $path, $module, $label = '', $batch_id = null ) {
		$path = storage_sherpa_normalize_path( $path );

		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'ss_missing_file', __( 'File no longer exists.', 'storage-sherpa' ) );
		}

		if ( ! storage_sherpa_path_is_safe( $path ) ) {
			return new WP_Error( 'ss_unsafe_path', __( 'Refusing to touch a path outside the WordPress install.', 'storage-sherpa' ) );
		}

		$size       = filesize( $path );
		$relative   = ltrim( str_replace( storage_sherpa_normalize_path( ABSPATH ), '', $path ), '/' );
		$dest       = trailingslashit( self::dir() ) . time() . '-' . wp_generate_password( 8, false ) . '/' . $relative;

		wp_mkdir_p( dirname( $dest ) );

		if ( ! @rename( $path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cross-device rename fallback follows.
			if ( ! @copy( $path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'ss_move_failed', __( 'Could not move file to Safe Trash (check file permissions).', 'storage-sherpa' ) );
			}
			wp_delete_file( $path );
		}

		return self::insert_entry(
			array(
				'item_type'     => 'file',
				'module'        => $module,
				'label'         => $label ? $label : basename( $path ),
				'original_path' => $path,
				'trashed_path'  => $dest,
				'size_bytes'    => $size,
				'batch_id'      => $batch_id,
			)
		);
	}

	/**
	 * Records a DB row's data before the caller deletes it, so it can be
	 * re-inserted verbatim on restore. Caller is responsible for the actual
	 * DELETE — this only captures the backup copy.
	 */
	public static function trash_db_row( $table_name, array $row, $module, $label = '', $batch_id = null ) {
		return self::insert_entry(
			array(
				'item_type'  => 'db_row',
				'module'     => $module,
				'label'      => $label ? $label : $table_name,
				'table_name' => $table_name,
				'row_data'   => wp_json_encode( $row ),
				'size_bytes' => strlen( wp_json_encode( $row ) ),
				'batch_id'   => $batch_id,
			)
		);
	}

	private static function insert_entry( array $fields ) {
		global $wpdb;

		$settings = storage_sherpa_get_settings();
		$days     = max( 1, (int) $settings['retention_days'] );

		$defaults = array(
			'original_path' => null,
			'trashed_path'  => null,
			'table_name'    => null,
			'row_data'      => null,
			'batch_id'      => null,
		);

		$fields = array_merge( $defaults, $fields );

		$fields['deleted_at'] = current_time( 'mysql' );
		$fields['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
		$fields['restored']   = 0;

		$wpdb->insert(
			$wpdb->prefix . 'ss_trash_items',
			$fields,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Restores every trash row sharing a batch_id in one call — the "Undo"
	 * toast after a bulk delete restores everything that one action created
	 * (an attachment's post row, its postmeta, its base file, and every
	 * thumbnail size) rather than requiring one restore click per row.
	 */
	public static function restore_batch( $batch_id ) {
		global $wpdb;

		if ( ! $batch_id ) {
			return new WP_Error( 'ss_missing_batch', __( 'No batch id given.', 'storage-sherpa' ) );
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ss_trash_items WHERE batch_id = %s AND restored = 0", $batch_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$restored = 0;
		$errors   = array();

		foreach ( $ids as $id ) {
			$result = self::restore( (int) $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$restored;
			}
		}

		return array(
			'restored' => $restored,
			'errors'   => $errors,
		);
	}

	/**
	 * Restores a trash entry: moves the file back, or re-inserts the DB row.
	 */
	public static function restore( $trash_id ) {
		global $wpdb;

		$item = self::get( $trash_id );

		if ( ! $item ) {
			return new WP_Error( 'ss_not_found', __( 'Trash item not found.', 'storage-sherpa' ) );
		}

		if ( 'file' === $item->item_type ) {
			if ( ! file_exists( $item->trashed_path ) ) {
				return new WP_Error( 'ss_missing_trash_file', __( 'Trashed file is missing on disk.', 'storage-sherpa' ) );
			}

			wp_mkdir_p( dirname( $item->original_path ) );

			if ( ! @rename( $item->trashed_path, $item->original_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'ss_restore_failed', __( 'Could not restore file (check permissions).', 'storage-sherpa' ) );
			}
		} elseif ( 'db_row' === $item->item_type ) {
			$row = json_decode( $item->row_data, true );

			if ( ! is_array( $row ) ) {
				return new WP_Error( 'ss_bad_row_data', __( 'Stored row data is corrupt.', 'storage-sherpa' ) );
			}

			$wpdb->replace( $item->table_name, $row ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from our own trash entry, not user input.
		} elseif ( 'table_dump' === $item->item_type ) {
			$dump = json_decode( $item->row_data, true );

			if ( ! is_array( $dump ) || empty( $dump['create_sql'] ) ) {
				return new WP_Error( 'ss_bad_row_data', __( 'Stored table backup is corrupt.', 'storage-sherpa' ) );
			}

			$wpdb->query( str_replace( 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $dump['create_sql'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own captured SHOW CREATE TABLE output, not user input.

			foreach ( (array) $dump['rows'] as $row ) {
				$wpdb->insert( $item->table_name, $row ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		$wpdb->update(
			$wpdb->prefix . 'ss_trash_items',
			array( 'restored' => 1 ),
			array( 'id' => $trash_id ),
			array( '%d' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Permanently deletes a trash entry: unlinks the trashed file (db_row
	 * entries have nothing left to remove, the live row is already gone).
	 */
	public static function permanently_delete( $trash_id ) {
		global $wpdb;

		$item = self::get( $trash_id );

		if ( ! $item ) {
			return new WP_Error( 'ss_not_found', __( 'Trash item not found.', 'storage-sherpa' ) );
		}

		if ( 'file' === $item->item_type && $item->trashed_path && file_exists( $item->trashed_path ) ) {
			wp_delete_file( $item->trashed_path );
		}

		return (bool) $wpdb->delete( $wpdb->prefix . 'ss_trash_items', array( 'id' => $trash_id ), array( '%d' ) );
	}

	/**
	 * Shared by Modules 2/3/4/7 (orphan/duplicate/large-file/broken-media
	 * findings): backs up the attachment's post + postmeta rows, moves the
	 * base file and every registered thumbnail size into Safe Trash, then
	 * removes the now-fileless attachment post. wp_delete_post()'s own
	 * internal unlink() calls become harmless no-ops since the files are
	 * already relocated by the time it runs.
	 */
	public static function trash_attachment( $attachment_id, $module, $batch_id = null ) {
		global $wpdb;

		$post = get_post( $attachment_id, ARRAY_A );
		if ( ! $post ) {
			return new WP_Error( 'ss_not_found', __( 'Attachment not found.', 'storage-sherpa' ) );
		}

		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $attachment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		SS_Trash::trash_db_row( $wpdb->posts, $post, $module, $post['post_title'] ? $post['post_title'] : ( 'attachment #' . $attachment_id ), $batch_id );

		foreach ( (array) $meta as $meta_row ) {
			SS_Trash::trash_db_row( $wpdb->postmeta, array_merge( array( 'post_id' => $attachment_id ), $meta_row ), $module, $meta_row['meta_key'], $batch_id );
		}

		$file = get_attached_file( $attachment_id );
		$bytes = 0;

		if ( $file && file_exists( $file ) ) {
			$bytes += filesize( $file );
			self::trash_file( $file, $module, basename( $file ), $batch_id );
		}

		$meta_data = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta_data['sizes'] ) && is_array( $meta_data['sizes'] ) ) {
			$dir = trailingslashit( dirname( $file ) );
			foreach ( $meta_data['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_path = $dir . $size['file'];
				if ( file_exists( $size_path ) ) {
					$bytes += filesize( $size_path );
					self::trash_file( $size_path, $module, $size['file'], $batch_id );
				}
			}
		}

		wp_delete_post( $attachment_id, true );

		storage_sherpa_log_cleanup( $module, 'trash_attachment:' . $attachment_id, 1, $bytes );

		return true;
	}

	/**
	 * Bundles every currently-pending (not yet restored) Safe Trash file
	 * into one downloadable ZIP — a portable takeaway copy before the
	 * retention window purges them for good. DB-row/table-dump entries
	 * (which have no file of their own — the trash row *is* the backup)
	 * are included as a .json dump instead, so an export is still a
	 * complete snapshot. Returns the temp zip file's path, or WP_Error.
	 */
	public static function export_zip( $limit = 1000 ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ss_no_ziparchive', __( 'The ZipArchive PHP extension is not available on this server.', 'storage-sherpa' ) );
		}

		$items = self::query( array( 'limit' => $limit ) );

		if ( empty( $items ) ) {
			return new WP_Error( 'ss_empty_trash', __( 'Safe Trash is empty — nothing to export.', 'storage-sherpa' ) );
		}

		$zip_path = wp_tempnam( 'storage-sherpa-trash-export.zip' );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'ss_zip_open_failed', __( 'Could not create the export archive.', 'storage-sherpa' ) );
		}

		foreach ( $items as $item ) {
			if ( 'file' === $item->item_type && $item->trashed_path && file_exists( $item->trashed_path ) ) {
				$zip->addFile( $item->trashed_path, $item->id . '-' . basename( $item->trashed_path ) );
			} else {
				$zip->addFromString( $item->id . '-' . sanitize_file_name( $item->label ) . '.json', (string) $item->row_data );
			}
		}

		$zip->close();

		return $zip_path;
	}

	public static function get( $trash_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_trash_items WHERE id = %d", $trash_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'restored' => 0,
			'module'   => '',
			'orderby'  => 'deleted_at DESC',
			'limit'    => 50,
			'offset'   => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( 'restored = %d' );
		$params = array( (int) $args['restored'] );

		if ( $args['module'] ) {
			$where[]  = 'module = %s';
			$params[] = $args['module'];
		}

		$sql = "SELECT * FROM {$wpdb->prefix}ss_trash_items WHERE " . implode( ' AND ', $where )
			. ' ORDER BY ' . esc_sql( $args['orderby'] )
			. ' LIMIT %d OFFSET %d';

		$params[] = (int) $args['limit'];
		$params[] = (int) $args['offset'];

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function total_trash_size() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT SUM(size_bytes) FROM {$wpdb->prefix}ss_trash_items WHERE restored = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Called daily by SS_Cron: unlinks/deletes anything past its
	 * expires_at (Settings → General → "Permanent delete after N days").
	 */
	public static function sweep_expired() {
		global $wpdb;

		$expired = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ss_trash_items WHERE restored = 0 AND expires_at <= %s", current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count = 0;

		foreach ( (array) $expired as $item ) {
			if ( self::permanently_delete( $item->id ) ) {
				++$count;
			}
		}

		return $count;
	}
}
