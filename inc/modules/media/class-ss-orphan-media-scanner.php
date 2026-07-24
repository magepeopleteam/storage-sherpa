<?php
/**
 * Module 2 — Orphan Media Scanner.
 *
 * Finds attachments that aren't referenced anywhere. Definitive sources
 * (featured image, WooCommerce gallery meta, WooCommerce/EDD downloadable
 * files, gallery shortcode ids, a literal upload URL match, site icon /
 * custom logo, media widgets, theme template/CSS files, and now bespoke
 * Elementor/Divi/WPBakery/Beaver Builder/ACF field parsers) mark an
 * attachment "used". Everything else still falls back to one generic regex
 * pass over serialized/JSON postmeta and options text — the safety net for
 * every page builder that doesn't have (or doesn't need) its own parser. A
 * URL match found this way still counts as "used" (it's a real upload
 * path); a bare numeric "id" match with no URL confirmation is marked
 * "possibly_used" rather than "used", so it's never silently treated as
 * safe to delete. This scanner already covers every attachment post
 * regardless of mime type (PDFs, video, audio, documents, archives) —
 * nothing here is image-specific. See CLAUDE.md → Module 2 for the full
 * reasoning.
 *
 * Confidence score: every finding row also carries a 0-100 "safe to
 * delete" confidence (SS_Media_Findings::confidence_label()) instead of a
 * bare orphan/used verdict — 0 for anything matched as "used", a low band
 * for "possibly_used" that drops further the more independent heuristics
 * agree it's in use, and a high band for "unused" that's tempered by
 * upload recency (a file uploaded 3 days ago is more likely "not placed
 * yet" than truly orphaned).
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
		self::scan_woocommerce_downloads();
		self::scan_edd_downloads();
		self::scan_theme_options();
		self::scan_widgets();
		self::scan_acf_fields( $start, $time_budget * 0.25 );
		self::scan_page_builders( $start, $time_budget * 0.25 );
		self::scan_theme_files();
		self::scan_post_content( $start, $time_budget * 0.5 );
		self::scan_generic_meta_and_options( $start, $time_budget );

		return self::build_results();
	}

	/**
	 * A cheap, targeted check for one specific attachment id — used by
	 * SS_Post_Delete_Hooks rather than the full scan(), since running the
	 * entire site-wide scan synchronously inside a before_delete_post
	 * callback would be far too slow. Deliberately loose in the "still
	 * referenced" direction (a generic postmeta substring match can false-
	 * positive) because that direction is safe here: a false "still in
	 * use" only means the file is skipped, never wrongly deleted.
	 */
	public static function is_attachment_referenced_elsewhere( $attachment_id, $exclude_post_id ) {
		global $wpdb;

		$attachment_id   = (int) $attachment_id;
		$exclude_post_id = (int) $exclude_post_id;

		$featured_elsewhere = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id != %d",
				$attachment_id,
				$exclude_post_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $featured_elsewhere > 0 ) {
			return true;
		}

		$gallery_elsewhere = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND post_id != %d AND FIND_IN_SET(%d, meta_value)",
				$exclude_post_id,
				$attachment_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $gallery_elsewhere > 0 ) {
			return true;
		}

		$file          = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$basename      = $file ? basename( $file ) : '';
		$like_patterns = array( '%wp-image-' . $attachment_id . '%', '%attachment_' . $attachment_id . '%' );

		if ( $basename ) {
			$like_patterns[] = '%' . $wpdb->esc_like( $basename ) . '%';
		}

		foreach ( $like_patterns as $pattern ) {
			$hit = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID != %d AND post_status != 'auto-draft' AND post_content LIKE %s",
					$exclude_post_id,
					$pattern
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $hit > 0 ) {
				return true;
			}
		}

		$meta_hit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_value LIKE %s",
				$exclude_post_id,
				'%' . $wpdb->esc_like( (string) $attachment_id ) . '%'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $meta_hit > 0;
	}

	/**
	 * Which e-commerce plugins this scan checks for downloadable-file
	 * references — surfaced in the admin UI so it's clear whether
	 * WooCommerce/EDD download files are actually being protected on this
	 * install, rather than silently no-op'ing when neither is active.
	 */
	public static function active_integrations() {
		return array(
			'woocommerce' => defined( 'WC_VERSION' ),
			'edd'         => defined( 'EDD_VERSION' ),
		);
	}

	/**
	 * Turns one of this class's own internal `reason` strings (e.g.
	 * "edd_download_file:download#123", "acf_field:hero_image:post#45") into
	 * a human label plus the referencing post id, if any — so the Media
	 * Findings screen can show *where* a "used" file is actually used
	 * instead of just the raw machine-readable reason. Kept here rather than
	 * in the admin screen since this is the one place that knows every shape
	 * a reason string can take.
	 *
	 * @return array{label: string, post_id: int}
	 */
	public static function describe_reason( $reason ) {
		$result = array(
			'label'   => '',
			'post_id' => 0,
		);

		if ( ! $reason ) {
			return $result;
		}

		if ( preg_match( '/#(\d+)$/', $reason, $m ) ) {
			$result['post_id'] = (int) $m[1];
			$reason            = substr( $reason, 0, -strlen( $m[0] ) );
		}

		$parts  = explode( ':', $reason );
		$source = array_shift( $parts );
		$extra  = isset( $parts[0] ) ? $parts[0] : '';

		switch ( $source ) {
			case 'featured_image':
				$label = __( 'Featured image', 'storage-sherpa' );
				break;
			case 'woocommerce_gallery':
				$label = __( 'WooCommerce product gallery', 'storage-sherpa' );
				break;
			case 'woocommerce_downloadable_file':
				$label = __( 'WooCommerce downloadable file', 'storage-sherpa' );
				break;
			case 'edd_download_file':
				$label = __( 'Easy Digital Downloads file', 'storage-sherpa' );
				break;
			case 'elementor':
				$label = __( 'Elementor', 'storage-sherpa' );
				break;
			case 'divi_gallery':
				$label = __( 'Divi gallery', 'storage-sherpa' );
				break;
			case 'wpbakery_single_image':
				$label = __( 'WPBakery image', 'storage-sherpa' );
				break;
			case 'wpbakery_gallery':
				$label = __( 'WPBakery gallery', 'storage-sherpa' );
				break;
			case 'beaver_builder':
				$label = __( 'Beaver Builder (possible match)', 'storage-sherpa' );
				break;
			case 'theme_file':
				$label = __( 'Theme template/stylesheet', 'storage-sherpa' );
				break;
			case 'site_icon':
				$label = __( 'Site icon', 'storage-sherpa' );
				break;
			case 'custom_logo':
				$label = __( 'Custom logo', 'storage-sherpa' );
				break;
			case 'header_image':
				$label = __( 'Header image', 'storage-sherpa' );
				break;
			case 'post_content':
				$label = __( 'Post content', 'storage-sherpa' );
				break;
			case 'post_content(heuristic)':
				$label = __( 'Post content (possible match)', 'storage-sherpa' );
				break;
			case 'gallery_shortcode':
				$label = __( '[gallery] shortcode', 'storage-sherpa' );
				break;
			case 'acf_field':
				$label = $extra
					? sprintf(
						/* translators: %s: ACF field name */
						__( 'ACF field "%s"', 'storage-sherpa' ),
						str_replace( '_', ' ', $extra )
					)
					: __( 'ACF field', 'storage-sherpa' );
				break;
			case 'widget':
				$label = $extra
					? sprintf(
						/* translators: %s: widget type */
						__( 'Widget (%s)', 'storage-sherpa' ),
						str_replace( '_', ' ', $extra )
					)
					: __( 'Widget', 'storage-sherpa' );
				break;
			case 'meta':
				$label = ( 'builder-content' === $extra )
					? __( 'Page builder or plugin data', 'storage-sherpa' )
					: __( 'Page builder or plugin data (possible match)', 'storage-sherpa' );
				break;
			case 'option':
				$label = __( 'Theme or plugin setting', 'storage-sherpa' );
				break;
			default:
				$label = ucfirst( str_replace( '_', ' ', $source ) );
		}

		$result['label'] = $label;

		return $result;
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

		// Each additional independent "possibly_used" hit on the same
		// attachment raises confidence it's really in use somewhere, even
		// though no single hit was definitive on its own — see
		// safe_to_delete_confidence().
		$hits = isset( self::$found[ $attachment_id ]['hits'] ) ? self::$found[ $attachment_id ]['hits'] + 1 : 1;

		self::$found[ $attachment_id ] = array(
			'status' => $status,
			'reason' => $reason,
			'hits'   => $hits,
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

	/**
	 * WooCommerce downloadable products store their files as a serialized
	 * array of `array( 'name' => ..., 'file' => $url )` in `_downloadable_files`
	 * postmeta — no attachment id is kept, only the URL, so
	 * resolve_download_file_attachment_id() is the way back to the
	 * attachment (a non-media-library URL simply won't resolve, which is
	 * correct — there's no attachment to mark as used).
	 */
	private static function scan_woocommerce_downloads() {
		if ( ! defined( 'WC_VERSION' ) ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_downloadable_files'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$files = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$url = is_array( $file ) && ! empty( $file['file'] ) ? $file['file'] : '';

				if ( ! $url ) {
					continue;
				}

				$attachment_id = self::resolve_download_file_attachment_id( $url );

				if ( $attachment_id ) {
					self::mark( $attachment_id, 'used', 'woocommerce_downloadable_file:product#' . $row->post_id );
				}
			}
		}
	}

	/**
	 * Easy Digital Downloads stores its files the same shape, on `download`
	 * posts under `edd_download_files` — an explicit `attachment_id` is
	 * checked first on the (rare) file rows that carry one, but EDD's own
	 * file-picker generally only saves `file` (the URL) and `name`, so in
	 * practice almost every row falls through to
	 * resolve_download_file_attachment_id() below.
	 */
	private static function scan_edd_downloads() {
		if ( ! defined( 'EDD_VERSION' ) ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'edd_download_files'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$files = maybe_unserialize( $row->meta_value );

			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				if ( ! is_array( $file ) ) {
					continue;
				}

				if ( ! empty( $file['attachment_id'] ) ) {
					self::mark( $file['attachment_id'], 'used', 'edd_download_file:download#' . $row->post_id );
					continue;
				}

				$url = ! empty( $file['file'] ) ? $file['file'] : '';

				if ( ! $url ) {
					continue;
				}

				$attachment_id = self::resolve_download_file_attachment_id( $url );

				if ( $attachment_id ) {
					self::mark( $attachment_id, 'used', 'edd_download_file:download#' . $row->post_id );
				}
			}
		}
	}

	/**
	 * attachment_url_to_postid() is a strict SQL lookup against the exact
	 * stored URL — it misses a real media-library file whenever the saved
	 * URL doesn't match byte-for-byte, which is common for downloadable-file
	 * URLs specifically: an `&#038;`-encoded query string EDD/WooCommerce
	 * appended, a scheme (http/https) mismatch, or a CDN/rewritten host.
	 * (This is the actual cause behind a real attachment showing as
	 * "unused" even though it's clearly attached to a download.) Falling
	 * back to the same uploads-path matching every other source in this
	 * scanner already uses — extract_url_ids() against self::$url_map — only
	 * needs the "uploads/…something.ext" portion of the URL to resolve, so
	 * it survives all of the above.
	 *
	 * A third, last-resort fallback matches by filename alone: if the file
	 * was ever moved to a different uploads subfolder after EDD recorded
	 * this URL — a media reorganization plugin, a manual move, a domain/URL
	 * migration that only rewrote some tables — the recorded relative path
	 * no longer matches self::$url_map either, even though a file with the
	 * exact same name is still a real, current attachment. Matching on
	 * filename alone is inherently looser than the two path-exact checks
	 * above, so it only runs when both of them have already failed.
	 */
	private static function resolve_download_file_attachment_id( $url ) {
		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		$decoded = html_entity_decode( $url );
		$ids     = self::extract_url_ids( $decoded );

		if ( $ids ) {
			return (int) reset( $ids );
		}

		return self::resolve_by_basename( $decoded );
	}

	/**
	 * Matches a URL's bare filename against every known attachment's
	 * (base file + every thumbnail size) relative path in self::$url_map —
	 * see resolve_download_file_attachment_id() for why this exists. Only
	 * matches when the filename is unambiguous (belongs to exactly one
	 * attachment among the paths this scan already indexed); an ambiguous
	 * filename is left unresolved rather than guessing which attachment it
	 * really is.
	 */
	private static function resolve_by_basename( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return 0;
		}

		$basename = wp_basename( $path );

		if ( ! $basename || false === strpos( $basename, '.' ) ) {
			return 0;
		}

		$matches = array();

		foreach ( (array) self::$url_map as $relative => $id ) {
			if ( wp_basename( $relative ) === $basename ) {
				$matches[ (int) $id ] = true;
			}
		}

		return 1 === count( $matches ) ? array_key_first( $matches ) : 0;
	}

	/**
	 * ACF stores each field's value under a plain meta_key (e.g.
	 * "hero_image") with a companion "_hero_image" row whose value is the
	 * ACF field key ("field_60f1234abcd") — that companion row is what
	 * reliably identifies "this meta_key is an ACF field" without having to
	 * know every field name in advance. Image/file fields store an
	 * attachment id (or an array/URL depending on the field's return
	 * format); gallery fields store an array of ids. Only runs if ACF is
	 * active — the sibling-row lookup below only means something if ACF's
	 * own conventions are actually in play.
	 */
	private static function scan_acf_fields( $start, $budget ) {
		if ( ! class_exists( 'ACF' ) && ! function_exists( 'acf_get_field' ) ) {
			return;
		}

		global $wpdb;

		$meta_key_pattern  = $wpdb->esc_like( '_' ) . '%';
		$field_key_pattern = $wpdb->esc_like( 'field_' ) . '%';

		$last_id = 0;

		while ( ( microtime( true ) - $start ) < $budget ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta}
					 WHERE meta_id > %d AND meta_key LIKE %s AND meta_value LIKE %s
					 ORDER BY meta_id ASC LIMIT 300",
					$last_id,
					$meta_key_pattern,
					$field_key_pattern
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->meta_id;

				$field_name = ltrim( $row->meta_key, '_' );
				if ( '' === $field_name ) {
					continue;
				}

				$value = get_post_meta( $row->post_id, $field_name, true );

				foreach ( self::extract_acf_attachment_ids( $value ) as $id ) {
					self::mark( $id, 'used', 'acf_field:' . $field_name . ':post#' . $row->post_id );
				}
			}
		}
	}

	private static function extract_acf_attachment_ids( $value ) {
		$ids = array();

		if ( is_numeric( $value ) ) {
			$ids[] = (int) $value;
		} elseif ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) ) {
				$ids[] = (int) $value['ID'];
			} elseif ( isset( $value['id'] ) ) {
				$ids[] = (int) $value['id'];
			} else {
				foreach ( $value as $item ) {
					$ids = array_merge( $ids, self::extract_acf_attachment_ids( $item ) );
				}
			}
		} elseif ( is_string( $value ) ) {
			$ids = array_merge( $ids, self::extract_url_ids( $value ) );
		}

		return array_unique( array_filter( $ids ) );
	}

	/**
	 * Bespoke, structural parsers for the four page builders most likely to
	 * cause false positives on a generic regex sweep — each one understands
	 * that builder's actual data shape well enough to mark a match "used"
	 * with real confidence. None of these plugins are installed in this
	 * dev environment to verify against a live site, so Beaver Builder's
	 * serialized settings objects (the least certain shape of the four)
	 * land as "possibly_used" rather than "used" until confirmed against a
	 * real install — same caution the generic heuristic sweep already
	 * applies elsewhere in this file.
	 */
	private static function scan_page_builders( $start, $budget ) {
		self::scan_elementor();
		self::scan_divi_galleries();
		self::scan_wpbakery();
		self::scan_beaver_builder( $start, $budget );
	}

	private static function scan_elementor() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$data = json_decode( $row->meta_value, true );

			if ( ! is_array( $data ) ) {
				// Elementor occasionally stores this double-escaped; fall
				// back to the generic URL extraction rather than skipping.
				foreach ( self::extract_url_ids( $row->meta_value ) as $id ) {
					self::mark( $id, 'used', 'elementor:post#' . $row->post_id );
				}
				continue;
			}

			foreach ( self::extract_elementor_ids( $data ) as $id ) {
				self::mark( $id, 'used', 'elementor:post#' . $row->post_id );
			}
		}
	}

	/**
	 * Elementor's image/background controls save as { "url": "...", "id": N }
	 * together inside an element's "settings" object — recursing the
	 * decoded JSON tree for any sub-array carrying both keys at once is far
	 * more reliable than regexing the raw text, since a bare "id" key alone
	 * appears constantly for unrelated things (element ids, widget ids)
	 * throughout the same document.
	 */
	private static function extract_elementor_ids( $node ) {
		$ids = array();

		if ( ! is_array( $node ) ) {
			return $ids;
		}

		if ( isset( $node['id'], $node['url'] ) && is_numeric( $node['id'] ) && is_string( $node['url'] ) && false !== strpos( $node['url'], 'uploads' ) ) {
			$ids[] = (int) $node['id'];
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$ids = array_merge( $ids, self::extract_elementor_ids( $value ) );
			}
		}

		return $ids;
	}

	/**
	 * Divi's own image/background URLs already resolve through the generic
	 * post_content pass (any literal uploads/ URL is caught there, including
	 * inside a Divi shortcode attribute) — the real gap is et_pb_gallery's
	 * "gallery_ids" attribute, which stores raw attachment ids with no URL
	 * for the generic pass to find.
	 */
	private static function scan_divi_galleries() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%et_pb_gallery%' OR post_content LIKE '%et_pb_slider%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			if ( preg_match_all( '/gallery_ids=["\']([0-9,\s]+)["\']/', $row->post_content, $m ) ) {
				foreach ( $m[1] as $id_list ) {
					foreach ( array_filter( array_map( 'trim', explode( ',', $id_list ) ) ) as $id ) {
						self::mark( $id, 'used', 'divi_gallery:post#' . $row->ID );
					}
				}
			}
		}
	}

	/**
	 * WPBakery (Visual Composer) shortcodes carry attachment ids directly
	 * in "image"/"images" attributes — vc_single_image's single id,
	 * vc_gallery / vc_images_carousel's comma-separated list.
	 */
	private static function scan_wpbakery() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%vc_single_image%' OR post_content LIKE '%vc_gallery%' OR post_content LIKE '%vc_images_carousel%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			if ( preg_match_all( '/\[vc_single_image[^\]]*\bimage=["\']?(\d+)["\']?/', $row->post_content, $m ) ) {
				foreach ( $m[1] as $id ) {
					self::mark( $id, 'used', 'wpbakery_single_image:post#' . $row->ID );
				}
			}

			if ( preg_match_all( '/\[vc_(?:gallery|images_carousel)[^\]]*\bimages=["\']?([0-9,\s]+)["\']?/', $row->post_content, $m ) ) {
				foreach ( $m[1] as $id_list ) {
					foreach ( array_filter( array_map( 'trim', explode( ',', $id_list ) ) ) as $id ) {
						self::mark( $id, 'used', 'wpbakery_gallery:post#' . $row->ID );
					}
				}
			}
		}
	}

	/**
	 * Beaver Builder's `_fl_builder_data` postmeta is a PHP-serialized array
	 * of node objects; image/background settings live under varying
	 * property names across module types (photo, bg_image, src...).
	 * Structural but not verified against a live Beaver Builder install —
	 * see the class docblock note on why matches land as "possibly_used".
	 */
	private static function scan_beaver_builder( $start, $budget ) {
		global $wpdb;

		$last_id = 0;

		while ( ( microtime( true ) - $start ) < $budget ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta}
					 WHERE post_id > %d AND meta_key = '_fl_builder_data'
					 ORDER BY post_id ASC LIMIT 100",
					$last_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->post_id;

				$data = maybe_unserialize( $row->meta_value );

				foreach ( self::extract_beaver_builder_ids( $data ) as $id ) {
					self::mark( $id, 'possibly_used', 'beaver_builder:post#' . $row->post_id );
				}
			}
		}
	}

	private static function extract_beaver_builder_ids( $node ) {
		$ids       = array();
		$id_fields = array( 'photo', 'photo_src', 'bg_image', 'bg_image_src', 'src' );

		if ( is_object( $node ) ) {
			$node = (array) $node;
		}

		if ( ! is_array( $node ) ) {
			return $ids;
		}

		foreach ( $id_fields as $field ) {
			if ( isset( $node[ $field ] ) && is_numeric( $node[ $field ] ) ) {
				$ids[] = (int) $node[ $field ];
			}
		}

		foreach ( $node as $value ) {
			if ( is_object( $value ) || is_array( $value ) ) {
				$ids = array_merge( $ids, self::extract_beaver_builder_ids( $value ) );
			}
		}

		return $ids;
	}

	/**
	 * Module 27 — CSS/theme file scanner. Background images and other
	 * uploads/ references hardcoded into the active theme's templates or
	 * stylesheets are invisible to every DB-only scan above; this is the
	 * one place that looks at the filesystem instead of the database.
	 */
	private static function scan_theme_files() {
		if ( ! class_exists( 'SS_Theme_File_Scanner' ) ) {
			return;
		}

		foreach ( SS_Theme_File_Scanner::find_referenced_relative_paths() as $relative ) {
			if ( isset( self::$url_map[ $relative ] ) ) {
				self::mark( self::$url_map[ $relative ], 'used', 'theme_file' );
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
				$hits   = self::$found[ $id ]['hits'];
			} else {
				$status = 'unused';
				$reason = __( 'No reference found in content, meta, or options.', 'storage-sherpa' );
				$hits   = 0;
			}

			$file = get_attached_file( $id );
			$size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;

			$rows[] = array(
				'attachment_id' => $id,
				'file_path'     => $file ? $file : null,
				'status'        => $status,
				'reason'        => $reason,
				'file_size'     => $size,
				'confidence'    => self::safe_to_delete_confidence( $status, $hits, $id ),
			);
		}

		return $rows;
	}

	/**
	 * "Safe to delete" confidence (0-100) — the opposite framing from a
	 * match's internal strength: a definitive "used" match is 0% safe to
	 * delete, an unmatched attachment is high-confidence safe, tempered by
	 * upload recency since a brand-new upload is more likely "not placed
	 * yet in content" than truly orphaned.
	 */
	private static function safe_to_delete_confidence( $status, $hits, $attachment_id ) {
		if ( 'used' === $status ) {
			return 0;
		}

		if ( 'possibly_used' === $status ) {
			return max( 10, 45 - ( ( $hits - 1 ) * 10 ) );
		}

		$uploaded = get_post_field( 'post_date', $attachment_id );
		$age_days = $uploaded ? ( time() - strtotime( $uploaded ) ) / DAY_IN_SECONDS : 999;

		if ( $age_days < 7 ) {
			return 60;
		}

		if ( $age_days < 30 ) {
			return 80;
		}

		return 95;
	}
}
