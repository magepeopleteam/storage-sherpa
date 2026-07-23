<?php
/**
 * Module 14 — Log Cleaner screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Logs_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$logs = SS_Log_Cleaner::find_logs();
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Log Cleaner', 'storage-sherpa' ); ?></h1>
			<p class="ss-muted"><?php esc_html_e( 'Only scans inside wp-content — server-level Apache/NGINX logs live outside the WordPress install and are out of reach here.', 'storage-sherpa' ); ?></p>

			<div class="ss-toolbar">
				<button class="button button-primary" data-ss-action="/storage-sherpa/v1/logs/delete-all" data-ss-confirm="<?php esc_attr_e( 'Move every detected log file to Safe Trash?', 'storage-sherpa' ); ?>">
					<?php esc_html_e( 'Clear All Logs', 'storage-sherpa' ); ?>
				</button>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Modified', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No log files found.', 'storage-sherpa' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><code><?php echo esc_html( str_replace( storage_sherpa_normalize_path( WP_CONTENT_DIR ), '', storage_sherpa_normalize_path( $log['path'] ) ) ); ?></code></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $log['size'] ) ); ?></td>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i', $log['modified'] ) ); ?></td>
							<td>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/logs/delete"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'path' => $log['path'] ) ) ); ?>'
									data-ss-confirm="<?php esc_attr_e( 'Move to Safe Trash?', 'storage-sherpa' ); ?>">
									<?php esc_html_e( 'Trash', 'storage-sherpa' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
