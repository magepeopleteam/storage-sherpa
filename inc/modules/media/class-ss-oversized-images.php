<?php
/**
 * Module 30 — Oversized Image Detector.
 *
 * True per-placement "this 4000px image is displayed at 300px" detection
 * needs a browser/JS crawler to know the actual rendered CSS size on every
 * page an image appears on — out of scope for a PHP-only plugin. The
 * practical proxy used here: compare each image's full-size dimensions
 * against the *largest* dimensions among every currently-registered image
 * size (core sizes + every add_image_size() call) — a strong signal for
 * "this was very likely never displayed at anywhere near its full
 * resolution" without needing to crawl a single page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Oversized_Images {

	public static function run_scan( $time_budget = 20 ) {
		$rows = self::scan( $time_budget );
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_OVERSIZED, $rows );
		return $rows;
	}

	public static function scan( $time_budget = 20, $threshold_multiplier = 1.5 ) {
		$start = microtime( true );

		list( $max_w, $max_h ) = self::largest_registered_dimensions();

		// A site with no registered sizes at all falls back to a generous
		// fixed ceiling rather than flagging literally every image.
		$max_w = $max_w > 0 ? $max_w : 1920;
		$max_h = $max_h > 0 ? $max_h : 1920;

		global $wpdb;

		$attachment_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();

		foreach ( $attachment_ids as $id ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			$id = (int) $id;

			if ( storage_sherpa_attachment_is_offloaded( $id ) ) {
				continue;
			}

			$metadata = wp_get_attachment_metadata( $id );

			if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
				continue;
			}

			$width  = (int) $metadata['width'];
			$height = (int) $metadata['height'];

			if ( $width <= $max_w * $threshold_multiplier && $height <= $max_h * $threshold_multiplier ) {
				continue;
			}

			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) || storage_sherpa_is_ignored_path( $file ) ) {
				continue;
			}

			$rows[] = array(
				'attachment_id' => $id,
				'file_path'     => $file,
				'status'        => 'oversized',
				'reason'        => sprintf(
					/* translators: 1: actual width, 2: actual height, 3: largest width used on this site, 4: largest height used on this site */
					__( '%1$d×%2$d — largest size actually used on this site is %3$d×%4$d', 'storage-sherpa' ),
					$width,
					$height,
					$max_w,
					$max_h
				),
				'file_size'  => filesize( $file ),
				'confidence' => 70,
			);
		}

		return $rows;
	}

	/**
	 * The widest/tallest dimensions among every currently-registered image
	 * size, plus the theme's declared content width when set — the two
	 * most reliable "this site never needs an image bigger than X" signals
	 * available without crawling rendered pages.
	 */
	private static function largest_registered_dimensions() {
		$sizes = wp_get_registered_image_subsizes();

		$max_w = 0;
		$max_h = 0;

		foreach ( $sizes as $size ) {
			if ( ! empty( $size['width'] ) ) {
				$max_w = max( $max_w, (int) $size['width'] );
			}
			if ( ! empty( $size['height'] ) ) {
				$max_h = max( $max_h, (int) $size['height'] );
			}
		}

		$content_width = isset( $GLOBALS['content_width'] ) ? (int) $GLOBALS['content_width'] : 0;
		if ( $content_width > 0 ) {
			$max_w = max( $max_w, $content_width );
		}

		return array( $max_w, $max_h );
	}
}
