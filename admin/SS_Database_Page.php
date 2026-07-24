<?php
/**
 * Module 8 — Database Cleanup screen: a checklist of categories with live
 * counts, each individually run (or "Clean Selected" for several at once).
 * Table maintenance (OPTIMIZE/REPAIR/ANALYZE) is a separate section since
 * it doesn't delete rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Database_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$summary = SS_Database_Cleanup::summary();
		?>
		<?php SS_Admin::header( __( 'Database Cleanup', 'storage-sherpa' ) ); ?>

			<div class="ss-section">
				<form id="ss-db-form">
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:32px;"></th>
								<th><?php esc_html_e( 'Category', 'storage-sherpa' ); ?></th>
								<th><?php esc_html_e( 'Rows Found', 'storage-sherpa' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $summary as $key => $row ) : ?>
								<tr>
									<td><input type="checkbox" name="categories[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $row['count'] > 0 ); ?> /></td>
									<td><?php echo esc_html( $row['label'] ); ?></td>
									<td><?php echo esc_html( $row['count'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="button" id="ss-db-clean" class="button button-primary"><?php esc_html_e( 'Clean Selected', 'storage-sherpa' ); ?></button>
						<span id="ss-db-status" class="ss-status"></span>
					</p>
					<p class="ss-muted"><?php esc_html_e( 'Every removed row is backed up to Safe Trash first and restorable from the Recovery Center.', 'storage-sherpa' ); ?></p>
				</form>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Table Maintenance', 'storage-sherpa' ); ?></h2>
				<p class="ss-muted"><?php esc_html_e( 'Runs OPTIMIZE/REPAIR/ANALYZE across every table Storage Sherpa recognizes as belonging to this site. Does not delete any data.', 'storage-sherpa' ); ?></p>
				<p class="ss-flex">
					<button class="button" data-ss-action="/storage-sherpa/v1/database/maintenance" data-ss-body='{"action":"OPTIMIZE"}' data-ss-reload="false"><?php esc_html_e( 'Optimize Tables', 'storage-sherpa' ); ?></button>
					<button class="button" data-ss-action="/storage-sherpa/v1/database/maintenance" data-ss-body='{"action":"REPAIR"}' data-ss-reload="false"><?php esc_html_e( 'Repair Tables', 'storage-sherpa' ); ?></button>
					<button class="button" data-ss-action="/storage-sherpa/v1/database/maintenance" data-ss-body='{"action":"ANALYZE"}' data-ss-reload="false"><?php esc_html_e( 'Analyze Tables', 'storage-sherpa' ); ?></button>
					<span class="ss-status"></span>
				</p>
			</div>
		<?php SS_Admin::footer(); ?>
		<script>
		document.getElementById('ss-db-clean').addEventListener('click', function () {
			var btn = this;
			var statusEl = document.getElementById('ss-db-status');
			var categories = Array.prototype.slice
				.call(document.querySelectorAll('input[name="categories[]"]:checked'))
				.map(function (cb) { return cb.value; });

			if (!categories.length) {
				return;
			}
			if (!window.confirm(StorageSherpa.i18n.confirmDelete)) {
				return;
			}

			btn.disabled = true;
			statusEl.textContent = StorageSherpa.i18n.working;

			StorageSherpaApi.fetch('/storage-sherpa/v1/database/run', { method: 'POST', data: { categories: categories } })
				.then(function () {
					window.location.reload();
				})
				.catch(function (err) {
					statusEl.textContent = (err && err.message) || StorageSherpa.i18n.error;
					btn.disabled = false;
				});
		});
		</script>
		<?php
	}
}
