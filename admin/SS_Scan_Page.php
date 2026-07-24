<?php
/**
 * Module 1 / 17 — Storage Analyzer detail screen: category breakdown,
 * largest directories, and the File Type Analyzer's storage-by-type table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Scan_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$results   = SS_Storage_Analyzer::get_latest_results();
		$largest   = SS_Storage_Analyzer::get_largest_directories( WP_CONTENT_DIR, 15 );
		$filetypes = array();
		$labels    = SS_Filetype_Analyzer::labels();
		?>
		<?php SS_Admin::header( __( 'Storage Analyzer', 'storage-sherpa' ) ); ?>

			<div class="ss-toolbar">
				<button class="button button-primary" data-ss-scan data-ss-progress-target="ss-scan-progress">
					<?php esc_html_e( 'Run Full Scan', 'storage-sherpa' ); ?>
				</button>
				<span id="ss-scan-progress" class="ss-progress"></span>
				<button class="button" id="ss-filetype-scan">
					<?php esc_html_e( 'Scan File Types', 'storage-sherpa' ); ?>
				</button>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Storage by Category', 'storage-sherpa' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td><?php echo esc_html( storage_sherpa_format_bytes( $row['size'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Largest Directories (under wp-content)', 'storage-sherpa' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Folder', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Files', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $largest ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No data yet — run a scan.', 'storage-sherpa' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $largest as $dir ) : ?>
							<tr>
								<td><?php echo esc_html( $dir['label'] ); ?></td>
								<td><?php echo esc_html( $dir['files'] ); ?></td>
								<td><?php echo esc_html( storage_sherpa_format_bytes( $dir['size'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Folder-Size Treemap (Uploads)', 'storage-sherpa' ); ?></h2>
				<p class="ss-muted"><?php esc_html_e( 'Tile area is proportional to folder size. Hover or focus a tile for details.', 'storage-sherpa' ); ?></p>
				<ul id="ss-treemap-legend" class="ss-legend ss-treemap-legend"></ul>
				<div
					id="ss-treemap-root"
					class="ss-treemap-root"
					role="img"
					aria-label="<?php esc_attr_e( 'Uploads folder size treemap', 'storage-sherpa' ); ?>"
				>
					<p class="ss-muted ss-treemap-loading"><?php esc_html_e( 'Loading treemap…', 'storage-sherpa' ); ?></p>
				</div>
				<details class="ss-treemap-table-toggle">
					<summary><?php esc_html_e( 'View as table', 'storage-sherpa' ); ?></summary>
					<table class="widefat striped" id="ss-treemap-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Folder', 'storage-sherpa' ); ?></th>
								<th><?php esc_html_e( 'Files', 'storage-sherpa' ); ?></th>
								<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td colspan="3"><?php esc_html_e( 'Loading…', 'storage-sherpa' ); ?></td></tr>
						</tbody>
					</table>
				</details>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Storage by File Type', 'storage-sherpa' ); ?></h2>
				<p class="ss-muted"><?php esc_html_e( 'Click "Scan File Types" above to refresh this table.', 'storage-sherpa' ); ?></p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Files', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						</tr>
					</thead>
					<tbody id="ss-filetype-body">
						<?php foreach ( $labels as $key => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td><?php echo esc_html( isset( $filetypes[ $key ] ) ? $filetypes[ $key ]['count'] : 0 ); ?></td>
								<td><?php echo esc_html( storage_sherpa_format_bytes( isset( $filetypes[ $key ] ) ? $filetypes[ $key ]['size'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php SS_Admin::footer(); ?>
		<script>
		document.getElementById('ss-filetype-scan').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			StorageSherpaApi.fetch('/storage-sherpa/v1/filetypes').then(function (res) {
				var body = document.getElementById('ss-filetype-body');
				var rows = '';
				Object.keys(res.labels).forEach(function (key) {
					var t = res.totals[key] || { size: 0, count: 0 };
					rows += '<tr><td>' + res.labels[key] + '</td><td>' + t.count + '</td><td>' + StorageSherpaApi.formatBytes(t.size) + '</td></tr>';
				});
				body.innerHTML = rows;
				btn.disabled = false;
			});
		});
		</script>
		<?php
	}
}
