<?php
/**
 * Plugin Name: Storage Sherpa
 * Plugin URI: https://example.com/storage-sherpa
 * Description: The smart WordPress storage optimizer. Scan the entire install, find orphan/duplicate media, clean the database, and reclaim disk space — safely, with every deletion recoverable from a Safe Trash before it's ever permanent.
 * Version: 1.0.0
 * Author: Storage Sherpa
 * Text Domain: storage-sherpa
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STORAGE_SHERPA_PLUGIN_FILE', __FILE__ );
define( 'STORAGE_SHERPA_PLUGIN_DIR', __DIR__ );
define( 'STORAGE_SHERPA_PLUGIN_URL', plugins_url( '', __FILE__ ) );
define( 'STORAGE_SHERPA_PLUGIN_VERSION', '1.0.0' );
define( 'STORAGE_SHERPA_DB_VERSION', '1.0.0' );

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Functions.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Install.php';

register_activation_hook( STORAGE_SHERPA_PLUGIN_FILE, array( 'SS_Install', 'activate' ) );
register_deactivation_hook( STORAGE_SHERPA_PLUGIN_FILE, array( 'SS_Install', 'deactivate' ) );

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Ignore_Rules.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Trash.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Notifications.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Cron.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_Background_Process.php';

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/analyzer/class-ss-storage-analyzer.php';

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-media-findings.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-orphan-media-scanner.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-duplicate-finder.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-large-file-scanner.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-broken-media.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/media/class-ss-image-optimizer.php';

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/filesystem/class-ss-empty-folder-cleaner.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/filesystem/class-ss-filetype-analyzer.php';

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/database/class-ss-database-cleanup.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/database/class-ss-orphan-tables.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/database/class-ss-autoload-analyzer.php';

// Modules 10, 11 (Plugin/Theme Cleanup), 18 (Security Cleanup), 21 (Reports)
// and 25 (WP-CLI) are deliberately deferred — see CLAUDE.md "Not yet built".
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/backups/class-ss-backup-cleanup.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/cache/class-ss-cache-cleaner.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/logs/class-ss-log-cleaner.php';
require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/modules/cron/class-ss-cron-manager.php';

require_once STORAGE_SHERPA_PLUGIN_DIR . '/inc/SS_REST_API.php';

if ( is_admin() ) {
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Admin.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Dashboard.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Scan_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Media_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Database_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Tables_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Backups_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Cache_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Logs_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Cron_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Autoload_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Filetypes_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/SS_Recovery_Page.php';
	require_once STORAGE_SHERPA_PLUGIN_DIR . '/admin/settings/SS_Settings_Page.php';
}

add_action( 'plugins_loaded', 'storage_sherpa_init' );

/**
 * Boots every module once all class files above have been loaded.
 */
function storage_sherpa_init() {
	load_plugin_textdomain( 'storage-sherpa', false, dirname( plugin_basename( STORAGE_SHERPA_PLUGIN_FILE ) ) . '/languages' );

	SS_Ignore_Rules::init();
	SS_Trash::init();
	SS_Notifications::init();
	SS_Cron::init();
	SS_Background_Process::init();
	SS_REST_API::init();

	if ( is_admin() ) {
		SS_Admin::init();
	}
}
