<?php
/**
 * Dashboard (Overview) screen — just a mount point. All rendering happens
 * in assets/admin/js/storage-sherpa-dashboard.js against the REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Dashboard {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		SS_Admin::header( __( 'Dashboard', 'storage-sherpa' ) );
		?>
			<div id="storage-sherpa-dashboard-root" class="storage-sherpa-dashboard-root">
				<p class="storage-sherpa-loading"><?php esc_html_e( 'Loading…', 'storage-sherpa' ); ?></p>
			</div>
		<?php
		SS_Admin::footer();
	}
}
