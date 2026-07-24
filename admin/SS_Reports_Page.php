<?php
/**
 * Module 21 — Reports screen: the "space saved" hero figure, a savings
 * trend, and a by-module breakdown, all read from data every other module
 * already writes at cleanup time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Reports_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$total_saved = SS_Reports::total_saved();
		$total_items = SS_Reports::total_items_cleaned();
		$by_module   = SS_Reports::by_module();
		$history     = SS_Reports::savings_history( 30 );
		$recent      = SS_Reports::recent( 25 );

		$max_daily = 0;
		foreach ( $history as $day ) {
			$max_daily = max( $max_daily, $day['bytes'] );
		}
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Reports', 'storage-sherpa' ); ?></h1>

			<div class="ss-stat-grid">
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Total Space Saved', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( storage_sherpa_format_bytes( $total_saved ) ); ?></div>
				</div>
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Items Cleaned', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( number_format_i18n( $total_items ) ); ?></div>
				</div>
			</div>

			<div class="ss-panel-grid">
				<div class="ss-panel">
					<h2><?php esc_html_e( 'Space Saved — Last 30 Days', 'storage-sherpa' ); ?></h2>
					<?php if ( empty( $history ) ) : ?>
						<p class="ss-muted"><?php esc_html_e( 'No cleanup activity recorded yet.', 'storage-sherpa' ); ?></p>
					<?php else : ?>
						<div class="ss-bar-chart">
							<?php foreach ( $history as $day ) : ?>
								<?php $pct = $max_daily > 0 ? round( ( $day['bytes'] / $max_daily ) * 100 ) : 0; ?>
								<div
									class="ss-bar"
									style="height: <?php echo (int) max( 2, $pct ); ?>%"
									title="<?php echo esc_attr( $day['date'] . ' — ' . storage_sherpa_format_bytes( $day['bytes'] ) ); ?>"
								></div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<div class="ss-panel">
					<h2><?php esc_html_e( 'Savings by Module', 'storage-sherpa' ); ?></h2>
					<?php if ( empty( $by_module ) ) : ?>
						<p class="ss-muted"><?php esc_html_e( 'No cleanup activity recorded yet.', 'storage-sherpa' ); ?></p>
					<?php else : ?>
						<table class="ss-table">
							<tbody>
								<?php foreach ( $by_module as $row ) : ?>
									<tr>
										<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $row['module'] ) ) ); ?></td>
										<td><?php echo esc_html( storage_sherpa_format_bytes( $row['bytes'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Recent Cleanup Activity', 'storage-sherpa' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Module', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Action', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Items', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Freed', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Run', 'storage-sherpa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $recent ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Nothing cleaned up yet.', 'storage-sherpa' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->created_at ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $row->module ) ) ); ?></td>
								<td><?php echo esc_html( $row->action ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->items_count ) ); ?></td>
								<td><?php echo esc_html( storage_sherpa_format_bytes( $row->bytes_freed ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $row->run_type ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
