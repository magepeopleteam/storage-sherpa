<?php
/**
 * Module 19 — Recovery Center. Every deletion from every module lands
 * here (files, DB rows, and whole table dumps alike) until its retention
 * window expires — see SS_Trash for the underlying mechanism.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Recovery_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$items = SS_Trash::query(
			array(
				'limit'  => 20,
				'offset' => ( $paged - 1 ) * 20,
			)
		);
		$settings = storage_sherpa_get_settings();
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Recovery Center', 'storage-sherpa' ); ?></h1>
			<p class="ss-muted">
				<?php
				printf(
					/* translators: %d: retention days */
					esc_html__( 'Trashed items are permanently deleted automatically after %d days (configurable in Settings). Total in Safe Trash: %s', 'storage-sherpa' ),
					(int) $settings['retention_days'],
					esc_html( storage_sherpa_format_bytes( SS_Trash::total_trash_size() ) )
				);
				?>
			</p>

			<div class="ss-toolbar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'storage_sherpa_export_trash' ); ?>
					<input type="hidden" name="action" value="storage_sherpa_export_trash" />
					<button type="submit" class="button">
						<?php esc_html_e( 'Download All as ZIP', 'storage-sherpa' ); ?>
					</button>
				</form>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Item', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Module', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Type', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Deleted', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'Safe Trash is empty.', 'storage-sherpa' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item->label ); ?></td>
							<td><?php echo esc_html( $item->module ); ?></td>
							<td><?php echo esc_html( $item->item_type ); ?></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $item->size_bytes ) ); ?></td>
							<td><?php echo esc_html( $item->deleted_at ); ?></td>
							<td><?php echo esc_html( $item->expires_at ); ?></td>
							<td class="ss-flex">
								<button class="button button-primary"
									data-ss-action="/storage-sherpa/v1/trash/<?php echo (int) $item->id; ?>/restore">
									<?php esc_html_e( 'Restore', 'storage-sherpa' ); ?>
								</button>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/trash/<?php echo (int) $item->id; ?>"
									data-ss-method="DELETE"
									data-ss-confirm="<?php esc_attr_e( 'Permanently delete? This cannot be undone.', 'storage-sherpa' ); ?>">
									<?php esc_html_e( 'Delete Permanently', 'storage-sherpa' ); ?>
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
