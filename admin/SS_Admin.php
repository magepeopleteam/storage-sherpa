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
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
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
			'storage-sherpa-reports' => array( __( 'Reports', 'storage-sherpa' ), array( 'SS_Reports_Page', 'render' ) ),
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

	/**
	 * Adds a scoping body class on every Storage Sherpa screen (regular admin
	 * and Network Admin alike) so the app-shell CSS never leaks onto other
	 * plugins' admin pages — same technique LatePoint uses with its own
	 * `latepoint-admin` class.
	 */
	public static function body_class( $classes ) {
		if ( self::is_plugin_screen() ) {
			$classes .= ' storage-sherpa-admin ';
		}
		return $classes;
	}

	/**
	 * Left sidebar nav: slug => [ label, dashicon ]. Mirrors register_menu()
	 * above one-for-one (Settings is rendered separately, pinned to the
	 * bottom of the sidebar).
	 */
	public static function primary_menu_items() {
		return array(
			'storage-sherpa'           => array( __( 'Dashboard', 'storage-sherpa' ), 'dashicons-dashboard' ),
			'storage-sherpa-scan'      => array( __( 'Storage Analyzer', 'storage-sherpa' ), 'dashicons-chart-bar' ),
			'storage-sherpa-media'     => array( __( 'Media Findings', 'storage-sherpa' ), 'dashicons-format-image' ),
			'storage-sherpa-database'  => array( __( 'Database Cleanup', 'storage-sherpa' ), 'dashicons-database' ),
			'storage-sherpa-tables'    => array( __( 'Orphan Tables', 'storage-sherpa' ), 'dashicons-editor-table' ),
			'storage-sherpa-backups'   => array( __( 'Backups', 'storage-sherpa' ), 'dashicons-backup' ),
			'storage-sherpa-cache'     => array( __( 'Cache', 'storage-sherpa' ), 'dashicons-performance' ),
			'storage-sherpa-logs'      => array( __( 'Logs', 'storage-sherpa' ), 'dashicons-media-text' ),
			'storage-sherpa-cron'      => array( __( 'Cron Manager', 'storage-sherpa' ), 'dashicons-clock' ),
			'storage-sherpa-autoload'  => array( __( 'Autoload Options', 'storage-sherpa' ), 'dashicons-controls-repeat' ),
			'storage-sherpa-filetypes' => array( __( 'File Types', 'storage-sherpa' ), 'dashicons-category' ),
			'storage-sherpa-recovery'  => array( __( 'Recovery Center', 'storage-sherpa' ), 'dashicons-undo' ),
			'storage-sherpa-reports'   => array( __( 'Reports', 'storage-sherpa' ), 'dashicons-chart-area' ),
		);
	}

	public static function footer_menu_items() {
		return array(
			'storage-sherpa-settings' => array( __( 'Settings', 'storage-sherpa' ), 'dashicons-admin-generic' ),
		);
	}

	private static function current_page_slug() {
		global $plugin_page;
		if ( ! empty( $plugin_page ) ) {
			return $plugin_page;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only used to highlight the active sidebar item.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::MENU_SLUG;
	}

	private static function render_menu_item( $slug, $item, $current ) {
		printf(
			'<li class="%1$s"><a href="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><span>%4$s</span></a></li>',
			esc_attr( $slug === $current ? 'is-active' : '' ),
			esc_url( admin_url( 'admin.php?page=' . $slug ) ),
			esc_attr( $item[1] ),
			esc_html( $item[0] )
		);
	}

	/**
	 * Opens the shared app-shell: top bar + left sidebar nav + main content
	 * area, styled after LatePoint's admin layout. Every admin page's
	 * render() calls this instead of hand-rolling `<div class="wrap">`, and
	 * closes it with footer() below. On Network Admin (SS_Network_Page) the
	 * sidebar is skipped since none of these per-site screens apply there.
	 */
	public static function header( $title ) {
		$is_network = function_exists( 'is_network_admin' ) && is_network_admin();
		$current    = self::current_page_slug();
		?>
		<div class="ss-app-wrap">
			<div class="ss-topbar">
				<div class="ss-topbar-brand">
					<span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
					<span class="ss-topbar-title"><?php esc_html_e( 'Storage Sherpa', 'storage-sherpa' ); ?></span>
				</div>
				<a class="ss-topbar-link" href="<?php echo esc_url( $is_network ? network_admin_url() : admin_url() ); ?>">
					<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Back to WordPress', 'storage-sherpa' ); ?></span>
				</a>
			</div>
			<div class="ss-body">
				<?php if ( ! $is_network ) : ?>
					<nav class="ss-sidebar" aria-label="<?php esc_attr_e( 'Storage Sherpa navigation', 'storage-sherpa' ); ?>">
						<ul class="ss-sidebar-menu">
							<?php foreach ( self::primary_menu_items() as $slug => $item ) : ?>
								<?php self::render_menu_item( $slug, $item, $current ); ?>
							<?php endforeach; ?>
						</ul>
						<ul class="ss-sidebar-menu ss-sidebar-menu-footer">
							<?php foreach ( self::footer_menu_items() as $slug => $item ) : ?>
								<?php self::render_menu_item( $slug, $item, $current ); ?>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
				<main class="ss-main">
					<div class="ss-page-header">
						<h1><?php echo esc_html( $title ); ?></h1>
					</div>
					<div class="ss-content">
		<?php
	}

	public static function footer() {
		?>
					</div>
				</main>
			</div>
		</div>
		<?php
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

		if ( 'storage-sherpa-scan' === $plugin_page ) {
			wp_enqueue_script(
				'storage-sherpa-treemap',
				STORAGE_SHERPA_PLUGIN_URL . '/assets/admin/js/storage-sherpa-treemap.js',
				array( 'wp-api-fetch', 'storage-sherpa-admin' ),
				STORAGE_SHERPA_PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'storage-sherpa-treemap',
				'StorageSherpaTreemap',
				array(
					'i18n' => array(
						'file'  => __( 'file', 'storage-sherpa' ),
						'files' => __( 'files', 'storage-sherpa' ),
						'other' => __( 'Other', 'storage-sherpa' ),
						'empty' => __( 'No data yet — run a scan.', 'storage-sherpa' ),
						'error' => __( 'Could not load the treemap.', 'storage-sherpa' ),
					),
				)
			);
		}

		if ( 'storage-sherpa-media' === $plugin_page ) {
			wp_enqueue_script(
				'storage-sherpa-media',
				STORAGE_SHERPA_PLUGIN_URL . '/assets/admin/js/storage-sherpa-media.js',
				array( 'wp-api-fetch', 'storage-sherpa-admin' ),
				STORAGE_SHERPA_PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'storage-sherpa-media',
				'StorageSherpaMedia',
				array(
					'i18n' => array(
						/* translators: %d: number of items currently checked */
						'nSelected'         => __( '%d selected', 'storage-sherpa' ),
						/* translators: %d: total number of items matching the current filter */
						'selectAllMatching' => __( 'Select all %d items matching this filter', 'storage-sherpa' ),
						/* translators: %d: total number of items matching the current filter */
						'allSelected'       => __( 'All %d items selected.', 'storage-sherpa' ),
						/* translators: %d: total number of items about to be trashed */
						'confirmAll'        => __( 'Move all %d matching items to Safe Trash? You can restore them later from Recovery Center.', 'storage-sherpa' ),
						'fetchingIds'       => __( 'Finding matching items…', 'storage-sherpa' ),
						/* translators: %1$d: items processed so far, %2$d: total items being deleted */
						'progress'          => __( '%1$d of %2$d processed…', 'storage-sherpa' ),
					),
				)
			);
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
					'movedToTrash'   => __( 'Moved to Safe Trash.', 'storage-sherpa' ),
					'undo'           => __( 'Undo', 'storage-sherpa' ),
				),
			)
		);
	}
}
