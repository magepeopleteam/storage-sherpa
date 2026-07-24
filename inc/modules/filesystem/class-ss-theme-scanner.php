<?php
/**
 * Module 27 — CSS/Theme File Scanner.
 *
 * Every other Module 2 scan looks at the database (post content, postmeta,
 * options) — this is the one pass that looks at the filesystem instead.
 * Theme/child-theme template files (.php) and stylesheets (.css) can
 * hardcode `background-image: url(...)`, `content: url(...)`, or a raw
 * `wp-content/uploads/...` path with no corresponding DB row anywhere, and
 * every one of those would otherwise be silently flagged as an orphan.
 * Only reads files already trusted as part of the site (the active theme
 * and its parent) — never anything derived from request input.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Theme_File_Scanner {

	/**
	 * Every distinct "uploads/..." relative path referenced anywhere in the
	 * active theme's (and parent theme's, if a child theme is active)
	 * template and stylesheet files — resolved against Module 2's URL map
	 * by the caller, not here, since this class has no notion of attachment
	 * ids on its own.
	 */
	public static function find_referenced_relative_paths( $max_seconds = 15 ) {
		$start = microtime( true );
		$paths = array();

		foreach ( self::theme_directories() as $dir ) {
			if ( ( microtime( true ) - $start ) > $max_seconds ) {
				break;
			}

			$paths = array_merge( $paths, self::scan_directory( $dir, $start, $max_seconds ) );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * The active theme's directory, plus its parent theme's directory when
	 * a child theme is active (get_stylesheet_directory() and
	 * get_template_directory() are identical for a non-child theme, so this
	 * never double-scans the same directory).
	 */
	private static function theme_directories() {
		$dirs = array( get_stylesheet_directory() );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$dirs[] = get_template_directory();
		}

		return array_filter( $dirs, 'is_dir' );
	}

	private static function scan_directory( $dir, $start, $max_seconds ) {
		$paths = array();

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return $paths;
		}

		foreach ( $iterator as $file ) {
			if ( ( microtime( true ) - $start ) > $max_seconds ) {
				break;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'css', 'php' ), true ) ) {
				continue;
			}

			// node_modules/vendor inside a theme are never hand-authored
			// template/style sources — skip them to avoid wasted reads on
			// large bundled dependency trees.
			if ( false !== strpos( $file->getPathname(), 'node_modules' ) || false !== strpos( $file->getPathname(), '/vendor/' ) ) {
				continue;
			}

			$contents = @file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort read, a locked/unreadable file just contributes nothing.

			if ( false === $contents ) {
				continue;
			}

			$paths = array_merge( $paths, self::extract_upload_paths( $contents ) );
		}

		return $paths;
	}

	/**
	 * Pulls every "uploads/..." relative path out of CSS url(...) values,
	 * PHP string literals, and bare text — deliberately loose (same pattern
	 * Module 2 already uses for post content) since a theme file can
	 * reference an upload through any of those forms.
	 */
	private static function extract_upload_paths( $text ) {
		if ( false === strpos( $text, 'uploads' ) ) {
			return array();
		}

		if ( ! preg_match_all( '#uploads/([^"\'\s\\\\)]+\.[a-zA-Z0-9]{2,5})#i', $text, $matches ) ) {
			return array();
		}

		return array_unique( array_map( 'rawurldecode', $matches[1] ) );
	}
}
