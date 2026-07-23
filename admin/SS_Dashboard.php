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
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Storage Sherpa', 'storage-sherpa' ); ?></h1>
			<div id="storage-sherpa-dashboard-root" class="storage-sherpa-dashboard-root">
				<p class="storage-sherpa-loading"><?php esc_html_e( 'Loading…', 'storage-sherpa' ); ?></p>
			</div>
		</div>
		<?php
	}
}
