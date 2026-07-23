<?php
/**
 * Module 13 — Cache Cleaner screen. Only shows targets available on this
 * site (see SS_Cache_Cleaner::available_targets()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Cache_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$targets = SS_Cache_Cleaner::available_targets();
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Cache Cleaner', 'storage-sherpa' ); ?></h1>

			<div class="ss-toolbar">
				<button class="button button-primary" data-ss-action="/storage-sherpa/v1/cache/purge" data-ss-reload="false">
					<?php esc_html_e( 'Purge All Detected Caches', 'storage-sherpa' ); ?>
				</button>
				<span class="ss-status"></span>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Target', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $targets as $key => $label ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/cache/purge"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'target' => $key ) ) ); ?>'
									data-ss-reload="false">
									<?php esc_html_e( 'Purge', 'storage-sherpa' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="ss-muted"><?php esc_html_e( 'Only cache mechanisms actually detected as active on this site are listed. Cache purges run immediately (not routed through Safe Trash) — caches are regenerable by definition.', 'storage-sherpa' ); ?></p>
		</div>
		<?php
	}
}
