<?php
/**
 * Admin menu registration + shared asset enqueue.
 *
 * The Dashboard (Overview) screen is a wp-element (React, bundled with core
 * — @wordpress/element/@wordpress/components/@wordpress/api-fetch — no
 * build step needed) single-page app talking to the REST API. Every other
 * screen is server-rendered PHP (WP_List_Table where it's a list of
 * findings) with a shared vanilla-JS helper for AJAX actions — same "no
 * build tooling" choice the sibling passpress plugin made for its blocks,
 * applied here to the whole admin area. See CLAUDE.md → "Frontend: React
 * without a build step" for the full reasoning.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Admin {

	const MENU_SLUG = 'storage-sherpa';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		$cap = storage_sherpa_capability();

		add_menu_page(
			__( 'Storage Sherpa', 'storage-sherpa' ),
			__( 'Storage Sherpa', 'storage-sherpa' ),
			$cap,
			self::MENU_SLUG,
			array( 'SS_Dashboard', 'render' ),
			'dashicons-cloud-upload',
			80
		);

		$pages = array(
			'storage-sherpa'          => array( __( 'Dashboard', 'storage-sherpa' ), array( 'SS_Dashboard', 'render' ) ),
			'storage-sherpa-scan'     => array( __( 'Storage Analyzer', 'storage-sherpa' ), array( 'SS_Scan_Page', 'render' ) ),
			'storage-sherpa-media'    => array( __( 'Media Findings', 'storage-sherpa' ), array( 'SS_Media_Page', 'render' ) ),
			'storage-sherpa-database' => array( __( 'Database Cleanup', 'storage-sherpa' ), array( 'SS_Database_Page', 'render' ) ),
			'storage-sherpa-tables'   => array( __( 'Orphan Tables', 'storage-sherpa' ), array( 'SS_Tables_Page', 'render' ) ),
			'storage-sherpa-backups'  => array( __( 'Backups', 'storage-sherpa' ), array( 'SS_Backups_Page', 'render' ) ),
			'storage-sherpa-cache'    => array( __( 'Cache', 'storage-sherpa' ), array( 'SS_Cache_Page', 'render' ) ),
			'storage-sherpa-logs'     => array( __( 'Logs', 'storage-sherpa' ), array( 'SS_Logs_Page', 'render' ) ),
			'storage-sherpa-cron'     => array( __( 'Cron Manager', 'storage-sherpa' ), array( 'SS_Cron_Page', 'render' ) ),
			'storage-sherpa-autoload' => array( __( 'Autoload Options', 'storage-sherpa' ), array( 'SS_Autoload_Page', 'render' ) ),
			'storage-sherpa-filetypes' => array( __( 'File Types', 'storage-sherpa' ), array( 'SS_Filetypes_Page', 'render' ) ),
			'storage-sherpa-recovery' => array( __( 'Recovery Center', 'storage-sherpa' ), array( 'SS_Recovery_Page', 'render' ) ),
			'storage-sherpa-settings' => array( __( 'Settings', 'storage-sherpa' ), array( 'SS_Settings_Page', 'render' ) ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page( self::MENU_SLUG, $page[0], $page[0], $cap, $slug, $page[1] );
		}
	}

	public static function is_plugin_screen() {
		$screen = get_current_screen();
		return $screen && false !== strpos( $screen->id, self::MENU_SLUG );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! self::is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style(
			'storage-sherpa-admin',
			STORAGE_SHERPA_PLUGIN_URL . '/assets/admin/css/storage-sherpa-admin.css',
			array(),
			STORAGE_SHERPA_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'storage-sherpa-admin',
			STORAGE_SHERPA_PLUGIN_URL . '/assets/admin/js/storage-sherpa-admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			STORAGE_SHERPA_PLUGIN_VERSION,
			true
		);

		// Standard core pattern for wiring wp.apiFetch to this site's REST root + nonce.
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) ); wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );',
				wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
				wp_json_encode( esc_url_raw( get_rest_url() ) )
			),
			'after'
		);

		global $plugin_page;
		if ( self::MENU_SLUG === $plugin_page || empty( $plugin_page ) ) {
			wp_enqueue_script(
				'storage-sherpa-dashboard',
				STORAGE_SHERPA_PLUGIN_URL . '/assets/admin/js/storage-sherpa-dashboard.js',
				array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'storage-sherpa-admin' ),
				STORAGE_SHERPA_PLUGIN_VERSION,
				true
			);
			wp_enqueue_style( 'wp-components' );
		}

		wp_localize_script(
			'storage-sherpa-admin',
			'StorageSherpa',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'storage-sherpa/v1' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'formatSize' => 'bytes',
				'i18n'       => array(
					'confirmDelete'  => __( 'Move to Safe Trash? You can restore it later from Recovery Center.', 'storage-sherpa' ),
					'confirmDrop'    => __( 'This will drop the table after taking a backup. Continue?', 'storage-sherpa' ),
					'confirmPermanent' => __( 'Permanently delete? This cannot be undone.', 'storage-sherpa' ),
					'working'        => __( 'Working…', 'storage-sherpa' ),
					'done'           => __( 'Done.', 'storage-sherpa' ),
					'error'          => __( 'Something went wrong.', 'storage-sherpa' ),
				),
			)
		);
	}
}
