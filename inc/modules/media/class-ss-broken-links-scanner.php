<?php
/**
 * Module 31 — Broken Link Scanner (the reverse problem).
 *
 * Module 7 (Broken Media) finds attachment DB rows whose physical file is
 * missing. This is the opposite direction entirely: content that *points
 * at* an uploads/ path with no attachment row and no file behind it at
 * all — a hardcoded `<img src>` or CSS `url(...)` left over after a manual
 * file deletion, a botched migration, or a theme change. Scoped to
 * post_content for this first pass (the same surface Module 2's generic
 * sweep already reads); postmeta/page-builder data would reuse the same
 * extraction if a wider pass is wanted later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Broken_Links_Scanner {

	public static function run_scan( $time_budget = 20 ) {
		$rows = self::scan( $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_BROKEN_LINK, $rows );
		return $rows;
	}

	public static function scan( $time_budget = 20 ) {
		$start      = microtime( true );
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );

		global $wpdb;

		$seen    = array();
		$rows    = array();
		$last_id = 0;

		while ( ( microtime( true ) - $start ) < $time_budget ) {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_type, post_content FROM {$wpdb->posts}
					 WHERE ID > %d AND post_status != 'auto-draft' AND post_content != ''
					 ORDER BY ID ASC LIMIT 200",
					$last_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$last_id = (int) $post->ID;

				foreach ( self::extract_referenced_paths( $post->post_content ) as $relative ) {
					if ( isset( $seen[ $relative ] ) ) {
						continue;
					}

					$path = $base . $relative;

					if ( file_exists( $path ) || storage_sherpa_is_ignored_path( $path ) ) {
						continue;
					}

					$seen[ $relative ] = true;

					$rows[] = array(
						'attachment_id' => 0,
						'file_path'     => $relative,
						'status'        => 'broken_link',
						'reason'        => sprintf(
							/* translators: 1: post type, 2: post ID */
							__( 'referenced in %1$s #%2$d — file missing', 'storage-sherpa' ),
							$post->post_type,
							$post->ID
						),
						'file_size'  => 0,
						'confidence' => 0,
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Same loose "uploads/..." extraction Module 2 already uses for post
	 * content — deliberately not narrowed to this site's exact domain,
	 * since a relative or protocol-less URL is common and still valid here.
	 */
	private static function extract_referenced_paths( $text ) {
		if ( false === strpos( $text, 'uploads' ) ) {
			return array();
		}

		if ( ! preg_match_all( '#uploads/([^"\'\s\\\\)]+\.[a-zA-Z0-9]{2,5})#i', $text, $matches ) ) {
			return array();
		}

		return array_unique( array_map( 'rawurldecode', $matches[1] ) );
	}
}
