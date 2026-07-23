<?php
/**
 * Module 5 — Image Optimization.
 *
 * Built entirely on WP's own wp_get_image_editor() abstraction (GD or
 * Imagick, whichever the server has) — no bundled compression binaries.
 * Three genuinely different operations, each with a different effect on
 * disk usage — worth keeping distinct rather than one "optimize" button:
 *
 * - compress(): re-encodes the existing file in place at a lower quality.
 *   The only one of the three that actually *reduces* this attachment's
 *   footprint. Routed through Safe Trash first (the pre-compression bytes
 *   are the "original" a restore brings back).
 * - generate_webp()/generate_avif(): write a smaller *sibling* file next to
 *   the original for `<picture>`-style delivery. These ADD a file — they
 *   improve page-weight for visitors, not this attachment's stored bytes.
 *   AVIF is feature-detected (PHP's GD only gained imageavif() in 8.1;
 *   Imagick needs a libheif-enabled build) and returns a clear WP_Error
 *   when the server can't encode it, rather than pretending to.
 * - remove_unused_thumbnails(): deletes size files on disk that don't match
 *   any currently-registered image size (leftovers from a since-removed
 *   add_image_size() call, e.g. after a theme switch) — a real space win.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Image_Optimizer {

	const DEFAULT_OVERSIZED_THRESHOLD = 307200; // 300 KB.

	public static function avif_supported() {
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				if ( in_array( 'AVIF', Imagick::queryFormats( 'AVIF' ), true ) ) {
					return true;
				}
			} catch ( Exception $e ) {
				// Fall through to the GD check below.
			}
		}

		return function_exists( 'imageavif' );
	}

	public static function webp_supported() {
		return ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) || function_exists( 'imagewebp' );
	}

	private static function image_attachments() {
		global $wpdb;

		return $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Scans every JPEG/PNG attachment for: oversized (base file above the
	 * configured threshold), missing WebP sibling, missing AVIF sibling.
	 */
	public static function scan( $time_budget = 20 ) {
		$start     = microtime( true );
		$settings  = storage_sherpa_get_settings();
		$threshold = isset( $settings['oversized_threshold'] ) ? (int) $settings['oversized_threshold'] : self::DEFAULT_OVERSIZED_THRESHOLD;

		$out = array(
			'oversized'    => array(),
			'missing_webp' => array(),
			'missing_avif' => array(),
		);

		foreach ( self::image_attachments() as $id ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$size = filesize( $file );

			if ( $size > $threshold ) {
				$out['oversized'][] = array(
					'attachment_id' => $id,
					'file_path'     => $file,
					'file_size'     => $size,
				);
			}

			$webp_path = self::sibling_path( $file, 'webp' );
			if ( ! file_exists( $webp_path ) ) {
				$out['missing_webp'][] = array(
					'attachment_id' => $id,
					'file_path'     => $file,
					'file_size'     => $size,
				);
			}

			$avif_path = self::sibling_path( $file, 'avif' );
			if ( ! file_exists( $avif_path ) ) {
				$out['missing_avif'][] = array(
					'attachment_id' => $id,
					'file_path'     => $file,
					'file_size'     => $size,
				);
			}
		}

		return $out;
	}

	private static function sibling_path( $file, $ext ) {
		return preg_replace( '/\.[a-zA-Z0-9]+$/', '.' . $ext, $file );
	}

	public static function compress( $attachment_id, $quality = 82 ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'ss_missing_file', __( 'File not found.', 'storage-sherpa' ) );
		}

		$before = filesize( $file );

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->set_quality( max( 1, min( 100, (int) $quality ) ) );

		// Back up the pre-compression bytes to Safe Trash before overwriting.
		$backup_id = SS_Trash::trash_file( self::copy_to_temp( $file ), 'image_optimizer', basename( $file ) . ' (pre-compression backup)' );

		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		clearstatcache( true, $file );
		$after = filesize( $file );

		storage_sherpa_log_cleanup( 'image_optimizer', 'compress', 1, max( 0, $before - $after ) );

		return array(
			'before'    => $before,
			'after'     => $after,
			'saved'     => max( 0, $before - $after ),
			'backup_id' => is_int( $backup_id ) ? $backup_id : null,
		);
	}

	/**
	 * trash_file() moves (not copies) the original path, so compress() first
	 * parks a throwaway copy of the pre-edit bytes for it to move instead.
	 */
	private static function copy_to_temp( $file ) {
		$tmp = wp_tempnam( basename( $file ) );
		copy( $file, $tmp );
		return $tmp;
	}

	public static function generate_webp( $attachment_id ) {
		if ( ! self::webp_supported() ) {
			return new WP_Error( 'ss_unsupported', __( 'This server\'s GD/Imagick build does not support WebP encoding.', 'storage-sherpa' ) );
		}

		return self::generate_sibling( $attachment_id, 'webp', 'image/webp' );
	}

	public static function generate_avif( $attachment_id ) {
		if ( ! self::avif_supported() ) {
			return new WP_Error( 'ss_unsupported', __( 'This server\'s GD/Imagick build does not support AVIF encoding.', 'storage-sherpa' ) );
		}

		return self::generate_sibling( $attachment_id, 'avif', 'image/avif' );
	}

	private static function generate_sibling( $attachment_id, $ext, $mime ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'ss_missing_file', __( 'File not found.', 'storage-sherpa' ) );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$dest = self::sibling_path( $file, $ext );
		$saved = $editor->save( $dest, $mime );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'path' => $dest,
			'size' => filesize( $dest ),
		);
	}

	/**
	 * Deletes on-disk size files that don't correspond to any currently
	 * registered image size (leftovers from removed add_image_size() calls).
	 */
	public static function remove_unused_thumbnails( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return array(
				'removed' => 0,
				'bytes'   => 0,
			);
		}

		global $_wp_additional_image_sizes;
		$registered = array_merge( array( 'thumbnail', 'medium', 'medium_large', 'large' ), array_keys( (array) $_wp_additional_image_sizes ) );

		$dir     = trailingslashit( dirname( get_attached_file( $attachment_id ) ) );
		$removed = 0;
		$bytes   = 0;

		foreach ( $meta['sizes'] as $size_name => $size_data ) {
			if ( in_array( $size_name, $registered, true ) || empty( $size_data['file'] ) ) {
				continue;
			}

			$path = $dir . $size_data['file'];
			if ( file_exists( $path ) ) {
				$bytes += filesize( $path );
				$trash_id = SS_Trash::trash_file( $path, 'image_optimizer', $size_data['file'] );
				if ( ! is_wp_error( $trash_id ) ) {
					++$removed;
					unset( $meta['sizes'][ $size_name ] );
				}
			}
		}

		if ( $removed > 0 ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
			storage_sherpa_log_cleanup( 'image_optimizer', 'remove_unused_thumbnails', $removed, $bytes );
		}

		return array(
			'removed' => $removed,
			'bytes'   => $bytes,
		);
	}

	public static function regenerate_thumbnails( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'ss_missing_file', __( 'Full-size file not found — cannot regenerate.', 'storage-sherpa' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		wp_update_attachment_metadata( $attachment_id, $meta );

		return true;
	}
}
