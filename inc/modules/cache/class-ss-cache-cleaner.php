<?php
/**
 * Module 13 — Cache Cleaner.
 *
 * Each integration calls that plugin's own *documented* public purge
 * function/hook — never a hand-rolled guess at its cache folder structure.
 * If a listed plugin isn't active, its entry is simply omitted from
 * available_targets() rather than shown as a fake no-op button. Cache
 * content is regenerable by definition, so purge actions here run directly
 * rather than routing through Safe Trash (there's nothing meaningful to
 * "restore" — the plugin rebuilds it on the next request) — the one other
 * module besides Empty Folder Cleaner that makes this call, and for the
 * same reason.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Cache_Cleaner {

	public static function available_targets() {
		$targets = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			$targets['wp_rocket'] = __( 'WP Rocket', 'storage-sherpa' );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			$targets['w3_total_cache'] = __( 'W3 Total Cache', 'storage-sherpa' );
		}
		if ( has_action( 'litespeed_purge_all' ) || defined( 'LSCWP_V' ) ) {
			$targets['litespeed'] = __( 'LiteSpeed Cache', 'storage-sherpa' );
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$targets['wp_fastest_cache'] = __( 'WP Fastest Cache', 'storage-sherpa' );
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			$targets['breeze'] = __( 'Breeze', 'storage-sherpa' );
		}
		if ( has_action( 'sg_cachepress_purge_cache' ) || class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			$targets['sg_optimizer'] = __( 'SG Optimizer', 'storage-sherpa' );
		}
		if ( class_exists( 'FlyingPress\Purge' ) ) {
			$targets['flyingpress'] = __( 'FlyingPress', 'storage-sherpa' );
		}
		if ( wp_using_ext_object_cache() ) {
			$targets['object_cache'] = __( 'Persistent Object Cache', 'storage-sherpa' );
		}
		if ( function_exists( 'opcache_reset' ) && ini_get( 'opcache.enable' ) ) {
			$targets['opcache'] = __( 'OPcache', 'storage-sherpa' );
		}

		$targets['cache_folder'] = __( 'wp-content/cache folder', 'storage-sherpa' );

		return $targets;
	}

	public static function purge( $target ) {
		switch ( $target ) {
			case 'wp_rocket':
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
					return true;
				}
				break;

			case 'w3_total_cache':
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					return true;
				}
				break;

			case 'litespeed':
				do_action( 'litespeed_purge_all' );
				return true;

			case 'wp_fastest_cache':
				global $wp_fastest_cache;
				if ( is_object( $wp_fastest_cache ) && method_exists( $wp_fastest_cache, 'deleteCache' ) ) {
					$wp_fastest_cache->deleteCache( true );
					return true;
				}
				break;

			case 'breeze':
				do_action( 'breeze_clear_all_cache' );
				return true;

			case 'sg_optimizer':
				do_action( 'sg_cachepress_purge_cache' );
				return true;

			case 'flyingpress':
				if ( class_exists( 'FlyingPress\Purge' ) && method_exists( 'FlyingPress\Purge', 'purge_everything' ) ) {
					\FlyingPress\Purge::purge_everything();
					return true;
				}
				break;

			case 'object_cache':
				return (bool) wp_cache_flush();

			case 'opcache':
				if ( function_exists( 'opcache_reset' ) ) {
					return (bool) opcache_reset();
				}
				break;

			case 'cache_folder':
				return self::clear_cache_directory();
		}

		return new WP_Error( 'ss_unavailable', __( 'That cache target is not active on this site.', 'storage-sherpa' ) );
	}

	public static function purge_all() {
		$results = array();
		foreach ( array_keys( self::available_targets() ) as $target ) {
			$results[ $target ] = self::purge( $target );
		}
		return $results;
	}

	private static function clear_cache_directory() {
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'cache';

		if ( ! is_dir( $dir ) || ! storage_sherpa_path_is_safe( $dir ) ) {
			return array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$before = storage_sherpa_dir_stats( $dir );
		$count  = 0;

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( storage_sherpa_is_ignored_path( $path ) ) {
				continue;
			}

			if ( is_dir( $path ) ) {
				storage_sherpa_rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
			++$count;
		}

		storage_sherpa_log_cleanup( 'cache', 'clear_cache_folder', $count, $before['size'] );

		return array(
			'count' => $count,
			'bytes' => $before['size'],
		);
	}
}
