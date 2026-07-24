<?php
/**
 * Module 12 — Backup Cleanup screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Backups_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$groups = SS_Backup_Cleanup::find_backup_dirs();
		?>
		<?php SS_Admin::header( __( 'Backup Cleanup', 'storage-sherpa' ) ); ?>
			<p class="ss-muted"><?php esc_html_e( 'Detects UpdraftPlus, Duplicator, Solid Backup/BackupBuddy, BackWPup, All-in-One WP Migration, and WPvivid backup folders by their default locations.', 'storage-sherpa' ); ?></p>

			<div class="ss-toolbar">
				<button class="button" data-ss-action="/storage-sherpa/v1/backups/delete-old" data-ss-body='{"days":30}' data-ss-confirm="<?php esc_attr_e( 'Move every backup older than 30 days to Safe Trash?', 'storage-sherpa' ); ?>">
					<?php esc_html_e( 'Delete Backups Older Than 30 Days', 'storage-sherpa' ); ?>
				</button>
			</div>

			<?php if ( empty( $groups ) ) : ?>
				<p><?php esc_html_e( 'No backup folders detected.', 'storage-sherpa' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $groups as $group ) : ?>
				<div class="ss-section">
					<h2><?php echo esc_html( $group['plugin'] ); ?> — <?php echo esc_html( storage_sherpa_format_bytes( $group['size'] ) ); ?></h2>
					<p class="ss-muted"><code><?php echo esc_html( $group['path'] ); ?></code></p>
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
							<?php foreach ( $group['files'] as $file ) : ?>
								<tr>
									<td><?php echo esc_html( $file['label'] ); ?></td>
									<td><?php echo esc_html( storage_sherpa_format_bytes( $file['size'] ) ); ?></td>
									<td><?php echo esc_html( gmdate( 'Y-m-d', $file['modified'] ) ); ?></td>
									<td>
										<button class="button"
											data-ss-action="/storage-sherpa/v1/backups/delete"
											data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'path' => $file['path'] ) ) ); ?>'
											data-ss-confirm="<?php esc_attr_e( 'Move to Safe Trash? You can restore it later from Recovery Center.', 'storage-sherpa' ); ?>">
											<?php esc_html_e( 'Trash', 'storage-sherpa' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		<?php SS_Admin::footer(); ?>
		<?php
	}
}
