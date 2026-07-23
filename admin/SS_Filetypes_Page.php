<?php
/**
 * Module 17 — File Type Analyzer screen. Runs the scan on page load (it's
 * bounded to a 20s time budget, see SS_Filetype_Analyzer::scan()) rather
 * than needing a separate "scan" click, since it's a read-only report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Filetypes_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$totals = SS_Filetype_Analyzer::scan();
		$labels = SS_Filetype_Analyzer::labels();
		$grand  = array_sum( wp_list_pluck( $totals, 'size' ) );
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'File Type Analyzer', 'storage-sherpa' ); ?></h1>
			<p class="ss-muted"><?php esc_html_e( 'Storage under wp-content, broken down by file extension.', 'storage-sherpa' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Files', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Share', 'storage-sherpa' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<?php $row = $totals[ $key ] ?? array( 'size' => 0, 'count' => 0 ); ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><?php echo esc_html( $row['count'] ); ?></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $row['size'] ) ); ?></td>
							<td><?php echo esc_html( $grand > 0 ? round( ( $row['size'] / $grand ) * 100, 1 ) . '%' : '0%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
