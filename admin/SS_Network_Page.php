<?php
/**
 * Module 32 — Multisite Network Scanning.
 *
 * Storage Sherpa's custom tables already key off $wpdb->prefix, so each
 * site's scan data is already correctly isolated per-site on a multisite
 * network with no schema changes needed — this page is purely the missing
 * aggregation layer: loop every site, pull its already-scanned totals via
 * switch_to_blog(), and show one network-wide view with drill-down links
 * into each site's own Storage Analyzer.
 *
 * "Scan All Sites Now" runs synchronously within one request rather than
 * building a distributed cross-site job queue — deliberately scoped to
 * what a single admin-triggered click can safely do; a very large network
 * is bounded by a time budget and told to re-click to continue, the same
 * "each request only does bounded work" principle Module 24's background
 * scanner already uses within a single site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Network_Page {

	public static function init() {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Storage Sherpa — Network', 'storage-sherpa' ),
			__( 'Storage Sherpa', 'storage-sherpa' ),
			'manage_network',
			'storage-sherpa-network',
			array( __CLASS__, 'render' ),
			'dashicons-cloud-upload',
			80
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_network' ) ) {
			return;
		}

		$scanned = null;

		if ( isset( $_POST['storage_sherpa_scan_all'] ) && check_admin_referer( 'storage_sherpa_network_scan' ) ) {
			$scanned = self::scan_all_sites();
		}

		$rows        = self::gather_site_totals();
		$grand_total = array_sum( wp_list_pluck( $rows, 'total_size' ) );
		$grand_trash = array_sum( wp_list_pluck( $rows, 'trash_size' ) );
		?>
		<?php SS_Admin::header( __( 'Storage Sherpa — Network', 'storage-sherpa' ) ); ?>

			<?php if ( null !== $scanned ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						printf(
							/* translators: %d: number of sites scanned */
							esc_html__( 'Scanned %d site(s). Re-click "Scan All Sites Now" if any were left over the time budget.', 'storage-sherpa' ),
							(int) $scanned
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="ss-stat-grid">
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Sites', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></div>
				</div>
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Network Total Storage', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( storage_sherpa_format_bytes( $grand_total ) ); ?></div>
				</div>
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Network Safe Trash', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( storage_sherpa_format_bytes( $grand_trash ) ); ?></div>
				</div>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'storage_sherpa_network_scan' ); ?>
				<p class="ss-toolbar">
					<button type="submit" name="storage_sherpa_scan_all" value="1" class="button button-primary">
						<?php esc_html_e( 'Scan All Sites Now', 'storage-sherpa' ); ?>
					</button>
					<span class="ss-muted"><?php esc_html_e( 'Runs synchronously and may take a while on a large network.', 'storage-sherpa' ); ?></span>
				</p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Total Storage', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Safe Trash', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Last Scan', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No sites found.', 'storage-sherpa' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $row['total_size'] ) ); ?></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $row['trash_size'] ) ); ?></td>
							<td><?php echo esc_html( $row['last_scan'] ? $row['last_scan'] : __( 'Never', 'storage-sherpa' ) ); ?></td>
							<td><a href="<?php echo esc_url( $row['admin_url'] ); ?>" class="button button-small"><?php esc_html_e( 'View Site', 'storage-sherpa' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php SS_Admin::footer(); ?>
		<?php
	}

	/**
	 * Every site's already-scanned totals, pulled via switch_to_blog() —
	 * this only reads data each site's own scan (manual or scheduled)
	 * already produced, it doesn't scan anything itself.
	 */
	private static function gather_site_totals() {
		$rows = array();

		foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$totals = class_exists( 'SS_Storage_Analyzer' ) ? SS_Storage_Analyzer::get_latest_snapshot_totals() : array();

			$rows[] = array(
				'blog_id'    => (int) $site->blog_id,
				'name'       => get_bloginfo( 'name' ),
				'total_size' => array_sum( $totals ),
				'trash_size' => class_exists( 'SS_Trash' ) ? SS_Trash::total_trash_size() : 0,
				'last_scan'  => get_option( 'storage_sherpa_last_scan' ),
				'admin_url'  => admin_url( 'admin.php?page=storage-sherpa' ),
			);

			restore_current_blog();
		}

		return $rows;
	}

	private static function scan_all_sites( $time_budget = 45 ) {
		$start   = microtime( true );
		$scanned = 0;

		foreach ( get_sites( array( 'number' => 500 ) ) as $site ) {
			if ( ( microtime( true ) - $start ) > $time_budget ) {
				break;
			}

			switch_to_blog( (int) $site->blog_id );

			if ( class_exists( 'SS_Storage_Analyzer' ) ) {
				SS_Storage_Analyzer::run_full_scan();
				++$scanned;
			}

			restore_current_blog();
		}

		return $scanned;
	}
}
