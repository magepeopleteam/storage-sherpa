<?php
/**
 * Module 2 — Orphan Media Scanner.
 *
 * Finds attachments that aren't referenced anywhere. Definitive sources
 * (featured image, WooCommerce gallery meta, gallery shortcode ids, a
 * literal upload URL match, site icon / custom logo, media widgets) mark an
 * attachment "used". Everything else is checked against one generic
 * regex pass over serialized/JSON postmeta and options text — this is the
 * single mechanism that covers ACF, Elementor, Bricks, Oxygen, Beaver
 * Builder, Meta Box, and JetEngine at once, since none of those plugins are
 * installed in this environment to build/verify eight bespoke parsers
 * against. A URL match found this way still counts as "used" (it's a real
 * upload path); a bare numeric "id" match with no URL confirmation is
 * marked "possibly_used" rather than "used", so it's never silently treated
 * as safe to delete. See CLAUDE.md → Module 2 for the full reasoning.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Orphan_Media_Scanner {

	/** @var array|null relative-upload-path => attachment_id, built once per scan. */
	private static $url_map = null;

	/** @var array attachment_id => array('status' => used|possibly_used, 'reason' => string) */
	private static $found = array();

	public static function run_scan() {
		$rows = self::scan();
		SS_Media_Findings::replace_all( SS_Media_Findings::TYPE_ORPHAN, $rows );
		return $rows;
	}

	public static function scan( $time_budget = 25 ) {
		$start = microtime( true );

		self::$url_map = self::build_url_map();
		self::$found    = array();

		self::scan_featured_images();
		self::scan_woocommerce_gallery();
		self::scan_theme_options();
		self::scan_widgets();
		self::scan_post_content( $start, $time_budget * 0.5 );
		self::scan_generic_meta_and_options( $start, $time_budget );

		return self::build_results();
	}

	private static function mark( $attachment_id, $status, $reason ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || SS_Ignore_Rules::is_attachment_ignored( $attachment_id ) ) {
			return;
		}

		// A definitive "used" always wins over a heuristic "possibly_used".
		if ( isset( self::$found[ $attachment_id ] ) && 'used' === self::$found[ $attachment_id ]['status'] ) {
			return;
		}

		self::$found[ $attachment_id ] = array(
			'status' => $status,
			'reason' => $reason,
		);
	}

	/**
	 * Maps every attachment's base file *and* every registered thumbnail
	 * size to that same attachment id, so a reference to an "-150x150"
	 * thumbnail URL still resolves back to the original attachment.
	 */
	private static function build_url_map() {
		global $wpdb;

		$map  = array();
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$id  = (int) $row->post_id;
			$dir = trailingslashit( dirname( $row->meta_value ) );
			if ( '.' === rtrim( $dir, '/' ) ) {
				$dir = '';
			}

			$map[ $row->meta_value ] = $id;

			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$map[ $dir . $size['file'] ] = $id;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Pulls every wp-content/uploads/... path out of a text blob and
	 * resolves each through the url map. Returns matched attachment ids.
	 */
	private static function extract_url_ids( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'uploads' ) ) {
			return array();
		}

		if ( ! preg_match_all( '#uploads/([^"\'\s\\\\)]+\.[a-zA-Z0-9]{2,5})#i', $text, $matches ) ) {
			return array();
		}

		$ids = array();

		foreach ( $matches[1] as $relative ) {
			$relative = rawurldecode( $relative );

			if ( isset( self::$url_map[ $relative ] ) ) {
				$ids[] = self::$url_map[ $relative ];
				continue;
			}

			// Strip a "-{width}x{height}" thumbnail suffix and retry against the base file.
			$base = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]{2,5})$/', '$1', $relative );
			if ( $base !== $relative && isset( self::$url_map[ $base ] ) ) {
				$ids[] = self::$url_map[ $base ];
			}
		}

		return array_unique( $ids );
	}

	/**
	 * Heuristic pass for page-builder JSON blobs: wp-image-N classes,
	 * attachment_N anchors, and bare "id":N / 'id' => N key-value pairs.
	 */
	private static function extract_heuristic_ids( $text ) {
		if ( ! is_string( $text ) ) {
			return array();
		}

		$ids = array();

		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		if ( preg_match_all( '/attachment_(\d+)/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		if ( preg_match_all( '/["\']id["\']\s*[:=][>]?\s*["\']?(\d+)["\']?/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		return array_unique( array_map( 'intval', $ids ) );
	}

	private static function scan_featured_images() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			self::mark( $row->meta_value, 'used', 'featured_image:post#' . $row->post_id );
		}
	}

	private static function scan_woocommerce_gallery() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			foreach ( array_filter( explode( ',', $row->meta_value ) ) as $id ) {
				self::mark( $id, 'used', 'woocommerce_gallery:product#' . $row->post_id );
			}
		}
	}

	private static function scan_theme_options() {
		$site_icon = (int) get_option( 'site_icon' );
		if ( $site_icon ) {
			self::mark( $site_icon, 'used', 'site_icon' );
		}

		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			self::mark( $logo, 'used', 'custom_logo' );
		}

		$header = get_theme_mod( 'header_image_data' );
		if ( is_object( $header ) && ! empty( $header->attachment_id ) ) {
			self::mark( $header->attachment_id, 'used', 'header_image' );
		}
	}

	private static function scan_widgets() {
		foreach ( array( 'widget_media_image', 'widget_media_gallery' ) as $option_name ) {
			$widget = get_option( $option_name );
			if ( ! is_array( $widget ) ) {
				continue;
			}

			foreach ( $widget as $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}
				if ( ! empty( $instance['attachment_id'] ) ) {
					self::mark( $instance['attachment_id'], 'used', 'widget:' . $option_name );
				}
				if ( ! empty( $instance['ids'] ) && is_array( $instance['ids'] ) ) {
					foreach ( $instance['ids'] as $id ) {
						self::mark( $id, 'used', 'widget:' . $option_name );
					}
				}
			}
		}

		$blocks = get_option( 'widget_block' );
		if ( is_array( $blocks ) ) {
			foreach ( $blocks as $instance ) {
				if ( ! empty( $instance['content'] ) ) {
					foreach ( self::extract_url_ids( $instance['content'] ) as $id ) {
						self::mark( $id, 'used', 'widget:widget_block' );
					}
					foreach ( self::extract_heuristic_ids( $instance['content'] ) as $id ) {
						self::mark( $id, 'possibly_used', 'widget:widget_block(heuristic)' );
					}
				}
			}
		}
	}

	/**
	 * Every post's content — this single pass already covers the classic
	 * editor, Gutenberg blocks (image URLs + wp-image-N classes appear
	 * directly in the saved HTML), the [gallery] shortcode, and nav menu
	 * items (nav_menu_item is a post type, so it's included here too).
	 */
	private static function scan_post_content( $start, $budget ) {
		global $wpdb;

		$last_id = 0;

		while ( ( microtime( true ) - $start ) < $budget ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content, post_type FROM {$wpdb->posts}
					 WHERE ID > %d AND post_status != 'auto-draft' AND post_content != ''
					 ORDER BY ID ASC LIMIT 200",
					$last_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->ID;

				foreach ( self::extract_url_ids( $row->post_content ) as $id ) {
					self::mark( $id, 'used', 'post_content:' . $row->post_type . '#' . $row->ID );
				}

				if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([0-9,]+)["\']/', $row->post_content, $m ) ) {
					foreach ( $m[1] as $id_list ) {
						foreach ( array_filter( explode( ',', $id_list ) ) as $id ) {
							self::mark( $id, 'used', 'gallery_shortcode:' . $row->post_type . '#' . $row->ID );
						}
					}
				}

				foreach ( self::extract_heuristic_ids( $row->post_content ) as $id ) {
					self::mark( $id, 'possibly_used', 'post_content(heuristic):' . $row->post_type . '#' . $row->ID );
				}
			}
		}
	}

	/**
	 * Generic sweep of postmeta + options whose value text looks like it
	 * might reference an upload — the single mechanism standing in for
	 * per-builder parsers (ACF, Elementor, Bricks, Oxygen, Beaver Builder,
	 * Meta Box, JetEngine, Customizer theme_mods). SQL LIKE pre-filters the
	 * candidate rows so we're not pulling every meta row into PHP.
	 */
	private static function scan_generic_meta_and_options( $start, $budget ) {
		global $wpdb;

		$like_uploads = '%uploads/%';
		$like_wpimage  = '%wp-image-%';

		$last_id = 0;
		while ( ( microtime( true ) - $start ) < $budget ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_id > %d AND meta_key NOT IN ('_thumbnail_id','_wp_attached_file','_product_image_gallery')
					 AND ( meta_value LIKE %s OR meta_value LIKE %s )
					 ORDER BY meta_id ASC LIMIT 300",
					$last_id,
					$like_uploads,
					$like_wpimage
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->meta_id;

				foreach ( self::extract_url_ids( $row->meta_value ) as $id ) {
					self::mark( $id, 'used', 'meta:builder-content' );
				}
				foreach ( self::extract_heuristic_ids( $row->meta_value ) as $id ) {
					self::mark( $id, 'possibly_used', 'meta:possible-builder-reference' );
				}
			}

			if ( ( microtime( true ) - $start ) >= $budget ) {
				return;
			}
		}

		$last_id = 0;
		while ( ( microtime( true ) - $start ) < $budget ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_value FROM {$wpdb->options}
					 WHERE option_id > %d AND ( option_value LIKE %s OR option_value LIKE %s )
					 ORDER BY option_id ASC LIMIT 200",
					$last_id,
					$like_uploads,
					$like_wpimage
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->option_id;

				foreach ( self::extract_url_ids( $row->option_value ) as $id ) {
					self::mark( $id, 'used', 'option:theme-or-plugin-setting' );
				}
			}
		}
	}

	private static function build_results() {
		global $wpdb;

		$attachment_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();

		foreach ( $attachment_ids as $id ) {
			$id = (int) $id;

			if ( isset( self::$found[ $id ] ) ) {
				$status = self::$found[ $id ]['status'];
				$reason = self::$found[ $id ]['reason'];
			} else {
				$status = 'unused';
				$reason = __( 'No reference found in content, meta, or options.', 'storage-sherpa' );
			}

			$file = get_attached_file( $id );
			$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;

			$rows[] = array(
				'attachment_id' => $id,
				'file_path'     => $file ? $file : null,
				'status'        => $status,
				'reason'        => $reason,
				'file_size'     => $size,
			);
		}

		return $rows;
	}
}
