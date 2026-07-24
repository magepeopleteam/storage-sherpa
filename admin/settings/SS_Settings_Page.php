<?php
/**
 * Settings — combines General (retention), Module 20 (Scheduled Scans),
 * Module 22 (Notifications), and Module 23 (Ignore Rules) on one screen,
 * same "collapse related settings onto one page until there's enough per
 * group to split" call the sibling passpress plugin made for its own
 * Settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Settings_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$settings = storage_sherpa_get_settings();
		$rules    = SS_Ignore_Rules::all();
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Storage Sherpa Settings', 'storage-sherpa' ); ?></h1>

			<div class="ss-section">
				<h2><?php esc_html_e( 'General & Recovery', 'storage-sherpa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ss-retention"><?php esc_html_e( 'Permanent delete after (days)', 'storage-sherpa' ); ?></label></th>
						<td><input type="number" min="1" id="ss-retention" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" /></td>
					</tr>
				</table>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Scheduled Scans', 'storage-sherpa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ss-frequency"><?php esc_html_e( 'Frequency', 'storage-sherpa' ); ?></label></th>
						<td>
							<select id="ss-frequency">
								<option value="daily" <?php selected( $settings['scan_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'storage-sherpa' ); ?></option>
								<option value="weekly" <?php selected( $settings['scan_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'storage-sherpa' ); ?></option>
								<option value="monthly" <?php selected( $settings['scan_frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'storage-sherpa' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Auto cleanup (optional)', 'storage-sherpa' ); ?></th>
						<td>
							<?php
							$auto_options = array(
								'database'            => __( 'Database Cleanup categories', 'storage-sherpa' ),
								'empty_folders'       => __( 'Empty Folders', 'storage-sherpa' ),
								'logs'                => __( 'Log files', 'storage-sherpa' ),
								'trash_sweep'         => __( 'Sweep expired Safe Trash immediately', 'storage-sherpa' ),
								'orphan_media'        => __( 'Orphan media (gated by confidence + age below)', 'storage-sherpa' ),
								'post_delete_cleanup' => __( 'Orphans left behind by a permanently deleted post', 'storage-sherpa' ),
							);
							foreach ( $auto_options as $key => $label ) :
								?>
								<label style="display:block;">
									<input type="checkbox" class="ss-auto-cleanup" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $settings['auto_cleanup'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Nothing runs automatically unless checked here — every category is still routed through Safe Trash either way.', 'storage-sherpa' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ss-orphan-min-confidence"><?php esc_html_e( 'Orphan auto-clean: minimum confidence', 'storage-sherpa' ); ?></label></th>
						<td>
							<input type="number" min="0" max="100" id="ss-orphan-min-confidence" value="<?php echo esc_attr( $settings['orphan_min_confidence'] ); ?>" /> %
							<p class="description"><?php esc_html_e( 'Only auto-clean orphans at or above this "safe to delete" confidence score.', 'storage-sherpa' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ss-orphan-min-age"><?php esc_html_e( 'Orphan auto-clean: minimum age', 'storage-sherpa' ); ?></label></th>
						<td>
							<input type="number" min="0" id="ss-orphan-min-age" value="<?php echo esc_attr( $settings['orphan_min_age_days'] ); ?>" /> <?php esc_html_e( 'days since upload', 'storage-sherpa' ); ?>
						</td>
					</tr>
				</table>
			</div>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Notification Center', 'storage-sherpa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Email reports & alerts', 'storage-sherpa' ); ?></th>
						<td><label><input type="checkbox" id="ss-notify-on" <?php checked( $settings['notify_on_scan'] ); ?> /> <?php esc_html_e( 'Enabled', 'storage-sherpa' ); ?></label></td>
					</tr>
					<tr>
						<th><label for="ss-notify-email"><?php esc_html_e( 'Notification email', 'storage-sherpa' ); ?></label></th>
						<td><input type="email" id="ss-notify-email" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ss-notify-growth"><?php esc_html_e( 'Alert when storage grows more than (%)', 'storage-sherpa' ); ?></label></th>
						<td><input type="number" min="1" id="ss-notify-growth" value="<?php echo esc_attr( $settings['notify_growth_percent'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ss-notify-orphans"><?php esc_html_e( 'Alert when unused media exceeds', 'storage-sherpa' ); ?></label></th>
						<td><input type="number" min="0" id="ss-notify-orphans" value="<?php echo esc_attr( $settings['notify_min_orphans'] ); ?>" /> <?php esc_html_e( 'files', 'storage-sherpa' ); ?></td>
					</tr>
					<tr>
						<th><label for="ss-notify-logs"><?php esc_html_e( 'Alert when logs exceed', 'storage-sherpa' ); ?></label></th>
						<td><input type="number" min="1" id="ss-notify-logs" value="<?php echo esc_attr( $settings['notify_min_log_mb'] ); ?>" /> MB</td>
					</tr>
				</table>
			</div>

			<p>
				<button id="ss-settings-save" class="button button-primary"><?php esc_html_e( 'Save Settings', 'storage-sherpa' ); ?></button>
				<span id="ss-settings-status" class="ss-status"></span>
			</p>

			<div class="ss-section">
				<h2><?php esc_html_e( 'Ignore Rules', 'storage-sherpa' ); ?></h2>
				<p class="ss-muted"><?php esc_html_e( 'Anything matching a rule here is skipped by every scanner and cleanup module.', 'storage-sherpa' ); ?></p>

				<form id="ss-ignore-form" class="ss-flex">
					<select id="ss-ignore-type">
						<?php foreach ( SS_Ignore_Rules::TYPES as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" id="ss-ignore-value" placeholder="<?php esc_attr_e( 'Value (path, extension, table name, ID, or slug)', 'storage-sherpa' ); ?>" class="regular-text" />
					<button type="submit" class="button"><?php esc_html_e( 'Add Rule', 'storage-sherpa' ); ?></button>
				</form>

				<table class="widefat striped" style="margin-top:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Type', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Value', 'storage-sherpa' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rules as $type => $values ) : ?>
							<?php foreach ( $values as $rule ) : ?>
								<tr>
									<td><?php echo esc_html( ucfirst( $type ) ); ?></td>
									<td><code><?php echo esc_html( $rule['value'] ); ?></code></td>
									<td>
										<button class="button" data-ss-action="/storage-sherpa/v1/ignore-rules/<?php echo (int) $rule['id']; ?>" data-ss-method="DELETE">
											<?php esc_html_e( 'Remove', 'storage-sherpa' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<script>
		document.getElementById('ss-settings-save').addEventListener('click', function () {
			var btn = this;
			var statusEl = document.getElementById('ss-settings-status');
			btn.disabled = true;
			statusEl.textContent = StorageSherpa.i18n.working;

			var autoCleanup = Array.prototype.slice
				.call(document.querySelectorAll('.ss-auto-cleanup:checked'))
				.map(function (cb) { return cb.value; });

			StorageSherpaApi.fetch('/storage-sherpa/v1/settings', {
				method: 'POST',
				data: {
					retention_days: parseInt(document.getElementById('ss-retention').value, 10),
					scan_frequency: document.getElementById('ss-frequency').value,
					auto_cleanup: autoCleanup,
					notify_on_scan: document.getElementById('ss-notify-on').checked,
					notify_email: document.getElementById('ss-notify-email').value,
					notify_growth_percent: parseFloat(document.getElementById('ss-notify-growth').value),
					notify_min_orphans: parseInt(document.getElementById('ss-notify-orphans').value, 10),
					notify_min_log_mb: parseInt(document.getElementById('ss-notify-logs').value, 10),
					orphan_min_confidence: parseInt(document.getElementById('ss-orphan-min-confidence').value, 10),
					orphan_min_age_days: parseInt(document.getElementById('ss-orphan-min-age').value, 10),
				},
			}).then(function () {
				statusEl.textContent = StorageSherpa.i18n.done;
				btn.disabled = false;
			}).catch(function (err) {
				statusEl.textContent = (err && err.message) || StorageSherpa.i18n.error;
				btn.disabled = false;
			});
		});

		document.getElementById('ss-ignore-form').addEventListener('submit', function (e) {
			e.preventDefault();
			var type = document.getElementById('ss-ignore-type').value;
			var value = document.getElementById('ss-ignore-value').value.trim();
			if (!value) {
				return;
			}
			StorageSherpaApi.fetch('/storage-sherpa/v1/ignore-rules', {
				method: 'POST',
				data: { rule_type: type, value: value },
			}).then(function () {
				window.location.reload();
			});
		});
		</script>
		<?php
	}
}
