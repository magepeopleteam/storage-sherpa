<?php
/**
 * Module 15 — Cron Manager screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Cron_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$events = SS_Cron_Manager::list_events();
		?>
		<?php SS_Admin::header( __( 'Cron Manager', 'storage-sherpa' ) ); ?>
			<p class="ss-muted"><?php esc_html_e( '"Overdue" is a heuristic (scheduled time is over an hour in the past) — WordPress itself doesn\'t record whether a cron run succeeded.', 'storage-sherpa' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hook', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Next Run', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Owner', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Status', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No scheduled events found.', 'storage-sherpa' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td><code><?php echo esc_html( $event['hook'] ); ?></code></td>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $event['timestamp'] ) ); ?></td>
							<td><?php echo esc_html( $event['schedule'] ); ?></td>
							<td><?php echo esc_html( $event['owner'] ); ?></td>
							<td><?php echo $event['overdue'] ? '<span class="ss-badge ss-badge-unused">' . esc_html__( 'Overdue', 'storage-sherpa' ) . '</span>' : '<span class="ss-badge ss-badge-used">' . esc_html__( 'OK', 'storage-sherpa' ) . '</span>'; ?></td>
							<td class="ss-flex">
								<button class="button"
									data-ss-action="/storage-sherpa/v1/cron/run"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'hook' => $event['hook'], 'args' => $event['args'] ) ) ); ?>'
									data-ss-reload="false">
									<?php esc_html_e( 'Run Now', 'storage-sherpa' ); ?>
								</button>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/cron/delete"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'hook' => $event['hook'], 'timestamp' => $event['timestamp'], 'args' => $event['args'] ) ) ); ?>'
									data-ss-confirm="<?php esc_attr_e( 'Unschedule this event?', 'storage-sherpa' ); ?>">
									<?php esc_html_e( 'Delete', 'storage-sherpa' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php SS_Admin::footer(); ?>
		<?php
	}
}
