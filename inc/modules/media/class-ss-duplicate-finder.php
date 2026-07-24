<?php
/**
 * Module 3 — Duplicate Media Finder.
 *
 * Groups attachments by exact file size first (cheap), then only hashes
 * files that share a size with at least one other file — avoids hashing
 * every attachment on large libraries. SHA-256 by default (MD5 is offered
 * as a faster/legacy option via the same method, both collision-safe enough
 * for "is this file byte-identical" purposes).
 *
 * Filters candidates by file extension against SS_Filetype_Analyzer's
 * canonical list (via SS_Large_File_Scanner::extensions()) rather than
 * post_mime_type — covers every non-image type those modules already know
 * about (PDFs, video, audio, documents, archives), not just images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Duplicate_Finder {

	public static function run_scan( $algo = 'sha256', $time_budget = 25 ) {
		$rows = self::scan( $algo, $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_DUPLICATE, $rows );
		return $rows;
	}

	public static function scan( $algo = 'sha256', $time_budget = 25 ) {
		$start = microtime( true );
		$algo  = in_array( $algo, array( 'md5', 'sha256' ), true ) ? $algo : 'sha256';

		global $wpdb;

		$attachments = $wpdb->get_results(
			"SELECT p.ID, p.post_date, pm.meta_value AS relative_path
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$allowed_extensions = SS_Large_File_Scanner::extensions();
		$upload_dir         = wp_upload_dir();
		$by_size            = array();

		foreach ( $attachments as $row ) {
			$ext = strtolower( pathinfo( $row->relative_path, PATHINFO_EXTENSION ) );

			if ( ! in_array( $ext, $allowed_extensions, true ) ) {
				continue;
			}

			$path = trailingslashit( $upload_dir['basedir'] ) . $row->relative_path;

			if ( ! file_exists( $path ) || storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			$size = filesize( $path );
			if ( $size <= 0 ) {
				continue;
			}

			$by_size[ $size ][] = array(
				'id'   => (int) $row->ID,
				'path' => $path,
				'date' => $row->post_date,
			);
		}

		$results = array();

		foreach ( $by_size as $candidates ) {
			if ( count( $candidates ) < 2 ) {
				continue; // Unique size, can't be a duplicate of anything.
			}

			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			$by_hash = array();

			foreach ( $candidates as $file ) {
				$hash = 'sha256' === $algo ? hash_file( 'sha256', $file['path'] ) : md5_file( $file['path'] );
				if ( false === $hash ) {
					continue;
				}
				$by_hash[ $hash ][] = $file;
			}

			foreach ( $by_hash as $hash => $group ) {
				if ( count( $group ) < 2 ) {
					continue;
				}

				// Oldest (by post_date, ties broken by lowest ID) is treated as the original.
				usort( $group, fn( $a, $b ) => strcmp( $a['date'] . $a['id'], $b['date'] . $b['id'] ) );

				$original = array_shift( $group );

				$results[] = array(
					'attachment_id' => $original['id'],
					'file_path'     => $original['path'],
					'status'        => 'original',
					'reason'        => sprintf( '%d duplicate(s) found', count( $group ) ),
					'file_size'     => filesize( $original['path'] ),
					'group_hash'    => $hash,
				);

				foreach ( $group as $dupe ) {
					$results[] = array(
						'attachment_id' => $dupe['id'],
						'file_path'     => $dupe['path'],
						'status'        => 'duplicate',
						'reason'        => 'duplicate_of:' . $original['id'],
						'file_size'     => filesize( $dupe['path'] ),
						'group_hash'    => $hash,
					);
				}
			}
		}

		return $results;
	}

	/**
	 * Every duplicate finding grouped by group_hash — one card per group in
	 * the admin screen's visual compare UI (thumbnails side by side), rather
	 * than the flat per-row list the other finding types use. 'original'
	 * sorts first within each group via the ORDER BY (its status string,
	 * 'original', is lexically greater than 'duplicate' — DESC puts it
	 * first), matching how it's already treated as the default "keep" pick.
	 */
	public static function grouped_findings( $args = array() ) {
		global $wpdb;

		$defaults = array( 'search' => '' );
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( 'finding_type = %s' );
		$params = array( SS_Media_Findings::TYPE_DUPLICATE );

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'file_path LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$sql = "SELECT * FROM {$wpdb->prefix}ss_media_findings WHERE " . implode( ' AND ', $where )
			. " ORDER BY group_hash ASC, status DESC, id ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ $row->group_hash ][] = $row;
		}

		return $groups;
	}

	/**
	 * The merge tool: re-points every reference this plugin can safely
	 * identify from $loser_id to $keeper_id, then trashes $loser_id through
	 * the normal Safe Trash path. Unlike a plain "trash the duplicate"
	 * action, this matters here specifically because the Duplicate Finder
	 * (unlike Orphan Media) never checks whether a "duplicate" copy is
	 * itself actively referenced somewhere — the oldest upload is just
	 * assumed to be "the original" — so a page that happens to use the
	 * newer copy as its featured image would silently break on a plain
	 * delete. Re-pointing first is what makes deleting the redundant copy
	 * safe.
	 *
	 * Four channels, each one either a structurally-safe single-value meta
	 * key or a byte-exact URL/id substitution — deliberately not a generic
	 * regex sweep over post content, since a *write* that guesses wrong is
	 * far more costly than a scanner's read-only heuristic guessing wrong:
	 *  1. `_thumbnail_id` (featured image) — exact value swap.
	 *  2. `_product_image_gallery` (WooCommerce) — token swap within the
	 *     comma-separated id list, de-duplicated afterward.
	 *  3. `post_content` — literal URL swap (never PHP-serialized, so a
	 *     plain string replace is safe) for the base file and every
	 *     thumbnail size both attachments share.
	 *  4. `postmeta`/`options` values that might be PHP-serialized (ACF
	 *     fields, page-builder settings, widget instances, …) — the same URL
	 *     swap plus an id swap, both run through the serialization-safe
	 *     helpers in SS_Functions.php so a length-prefixed serialized string
	 *     can never be corrupted.
	 *
	 * Returns an array with a per-channel count of rows touched, or a
	 * WP_Error if either attachment doesn't exist.
	 */
	public static function merge_attachment( $loser_id, $keeper_id, $batch_id = null ) {
		global $wpdb;

		$loser_id  = (int) $loser_id;
		$keeper_id = (int) $keeper_id;

		if ( $loser_id === $keeper_id ) {
			return new WP_Error( 'ss_same_attachment', __( 'Cannot merge an attachment into itself.', 'storage-sherpa' ) );
		}

		if ( ! get_post( $loser_id ) || ! get_post( $keeper_id ) ) {
			return new WP_Error( 'ss_not_found', __( 'One of these attachments no longer exists.', 'storage-sherpa' ) );
		}

		if ( ! $batch_id ) {
			$batch_id = wp_generate_password( 20, false, false );
		}

		$updated = array(
			'thumbnail' => 0,
			'gallery'   => 0,
			'content'   => 0,
			'meta'      => 0,
		);

		// --- 1. Featured image -------------------------------------------
		$updated['thumbnail'] = (int) $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $keeper_id ),
			array(
				'meta_key'   => '_thumbnail_id',
				'meta_value' => $loser_id,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);

		// --- 2. WooCommerce product gallery -------------------------------
		$gallery_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, meta_value)",
				$loser_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $gallery_rows as $row ) {
			$ids = array_filter( array_map( 'trim', explode( ',', $row->meta_value ) ) );
			$ids = array_map(
				function ( $id ) use ( $loser_id, $keeper_id ) {
					return (int) $id === $loser_id ? $keeper_id : (int) $id;
				},
				$ids
			);
			$ids = array_unique( $ids ); // Don't end up with the keeper listed twice.

			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => implode( ',', $ids ) ),
				array( 'meta_id' => $row->meta_id ),
				array( '%s' ),
				array( '%d' )
			);
			++$updated['gallery'];
		}

		// --- 3 & 4. URL pairs (base file + every shared thumbnail size) ---
		$url_search  = array();
		$url_replace = array();

		$loser_url = wp_get_attachment_url( $loser_id );
		$keeper_url = wp_get_attachment_url( $keeper_id );
		if ( $loser_url && $keeper_url ) {
			$url_search[]  = $loser_url;
			$url_replace[] = $keeper_url;
		}

		$loser_meta  = wp_get_attachment_metadata( $loser_id );
		$keeper_meta = wp_get_attachment_metadata( $keeper_id );

		if ( ! empty( $loser_meta['sizes'] ) && ! empty( $keeper_meta['sizes'] ) && $loser_url && $keeper_url ) {
			$loser_base_dir  = trailingslashit( dirname( $loser_url ) );
			$keeper_base_dir = trailingslashit( dirname( $keeper_url ) );

			foreach ( $loser_meta['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) || empty( $keeper_meta['sizes'][ $size_name ]['file'] ) ) {
					continue;
				}
				$url_search[]  = $loser_base_dir . $size_data['file'];
				$url_replace[] = $keeper_base_dir . $keeper_meta['sizes'][ $size_name ]['file'];
			}
		}

		if ( $url_search ) {
			$updated['content'] = self::replace_urls_in_post_content( $url_search, $url_replace );
		}

		// --- 4. postmeta/options possibly-serialized values ----------------
		$updated['meta'] = self::rewrite_meta_and_options( $loser_id, $keeper_id, $url_search, $url_replace );

		if ( array_sum( $updated ) > 0 ) {
			storage_sherpa_log_cleanup( 'duplicate_finder', 'merge_references', array_sum( $updated ), 0 );
		}

		$trash_result = SS_Trash::trash_attachment( $loser_id, 'duplicate_finder', $batch_id );
		if ( is_wp_error( $trash_result ) ) {
			return $trash_result;
		}

		SS_Media_Findings::delete_by_attachment( SS_Media_Findings::TYPE_DUPLICATE, $loser_id );

		return array(
			'references_updated' => $updated,
			'batch_id'            => $batch_id,
		);
	}

	/**
	 * Swaps every $url_search[i] => $url_replace[i] pair inside post_content,
	 * post by post (never a single blanket UPDATE...LIKE) specifically so
	 * clean_post_cache() can run per touched post afterward — WordPress
	 * caches post objects, so a raw SQL UPDATE that bypasses wp_update_post()
	 * would otherwise leave get_post() returning stale post_content for the
	 * rest of the request (and for any other request until that cache entry
	 * naturally expires/gets evicted). Returns the count of distinct posts
	 * touched, not a raw affected-rows count (a post can match more than one
	 * URL pair — e.g. the base file and one of its thumbnail sizes both
	 * appearing in the same page — and should only count once).
	 */
	private static function replace_urls_in_post_content( $url_search, $url_replace ) {
		global $wpdb;

		$touched_post_ids = array();

		foreach ( $url_search as $i => $search_url ) {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
					'%' . $wpdb->esc_like( $search_url ) . '%'
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;

				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE ID = %d",
						$search_url,
						$url_replace[ $i ],
						$post_id
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				clean_post_cache( $post_id );
				$touched_post_ids[ $post_id ] = true;
			}
		}

		return count( $touched_post_ids );
	}


	/**
	 * Bounded, LIKE-prefiltered sweep of postmeta/options for values that
	 * might reference the loser attachment — capped at a few thousand rows
	 * per table, same "bounded best-effort, not a guarantee" honesty as
	 * every other best-effort scan in this plugin (Orphan Tables, Backup
	 * Cleanup detection). URL values are swapped via
	 * storage_sherpa_replace_in_value(); the bare/serialized id is swapped
	 * via storage_sherpa_replace_id_in_value() — both serialization-safe.
	 *
	 * Written back through update_metadata()/update_option() rather than a
	 * raw $wpdb->update(), deliberately — WordPress caches both postmeta and
	 * options, and a raw SQL write doesn't invalidate that cache, so
	 * get_post_meta()/get_option() calls elsewhere on the same request (or
	 * on a future request, under a persistent object cache) would keep
	 * returning the pre-merge value. update_metadata()'s $prev_value
	 * argument targets exactly the one row this loop already fetched — not
	 * every row sharing that meta_key — so a legitimately multi-valued key
	 * (the same meta_key repeated across several rows for one post) is left
	 * alone apart from the specific row that actually matched.
	 */
	private static function rewrite_meta_and_options( $loser_id, $keeper_id, $url_search, $url_replace ) {
		global $wpdb;

		$touched   = 0;
		$row_limit = 2000;
		$id_like   = 'i:' . $loser_id . ';';

		$like_clauses = array( 'meta_value LIKE %s' );
		$like_params  = array( '%' . $wpdb->esc_like( $id_like ) . '%' );
		if ( $url_search ) {
			$like_clauses[] = 'meta_value LIKE %s';
			$like_params[]  = '%' . $wpdb->esc_like( $url_search[0] ) . '%';
		}
		$like_params[] = (string) $loser_id;
		$like_params[] = $row_limit;

		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				 WHERE ( " . implode( ' OR ', $like_clauses ) . " ) OR meta_value = %s
				 LIMIT %d",
				$like_params
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $meta_rows as $row ) {
			$value = $row->meta_value;

			if ( $url_search ) {
				$value = storage_sherpa_replace_in_value( $value, $url_search, $url_replace );
			}
			$value = storage_sherpa_replace_id_in_value( $value, $loser_id, $keeper_id );

			if ( $value === $row->meta_value ) {
				continue;
			}

			update_metadata( 'post', $row->post_id, $row->meta_key, maybe_unserialize( $value ), maybe_unserialize( $row->meta_value ) );
			++$touched;
		}

		$like_clauses = array( 'option_value LIKE %s' );
		$like_params  = array( '%' . $wpdb->esc_like( $id_like ) . '%' );
		if ( $url_search ) {
			$like_clauses[] = 'option_value LIKE %s';
			$like_params[]  = '%' . $wpdb->esc_like( $url_search[0] ) . '%';
		}
		$like_params[] = (string) $loser_id;
		$like_params[] = $row_limit;

		$option_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE ( " . implode( ' OR ', $like_clauses ) . " ) OR option_value = %s
				 LIMIT %d",
				$like_params
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $option_rows as $row ) {
			$value = $row->option_value;

			if ( $url_search ) {
				$value = storage_sherpa_replace_in_value( $value, $url_search, $url_replace );
			}
			$value = storage_sherpa_replace_id_in_value( $value, $loser_id, $keeper_id );

			if ( $value === $row->option_value ) {
				continue;
			}

			update_option( $row->option_name, maybe_unserialize( $value ) );
			++$touched;
		}

		return $touched;
	}

	public static function potential_savings() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(file_size) FROM {$wpdb->prefix}ss_media_findings WHERE finding_type = %s AND status = 'duplicate'",
				SS_Media_Findings::TYPE_DUPLICATE
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
