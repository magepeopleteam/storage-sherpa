<?php
/**
 * Module 9 — Orphan Database Tables. Deliberately no bulk "delete all"
 * action — the spec calls this "delete manually", and drop_table() is a
 * genuinely destructive whole-table operation, so each row gets its own
 * confirm + explicit backup-then-drop.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Tables_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$tables = SS_Orphan_Tables::scan();
		?>
		<?php SS_Admin::header( __( 'Orphan Database Tables', 'storage-sherpa' ) ); ?>
			<p class="ss-muted">
				<?php esc_html_e( 'Best-effort detection: tables that don\'t match any currently active plugin or theme. Always review before dropping — "estimated plugin" is a guess, not a certainty.', 'storage-sherpa' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Estimated Plugin', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Last Modified', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $tables ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No orphan tables found.', 'storage-sherpa' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $tables as $table ) : ?>
						<tr>
							<td><code><?php echo esc_html( $table['table'] ); ?></code></td>
							<td><?php echo esc_html( $table['rows'] ); ?></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $table['size'] ) ); ?></td>
							<td><?php echo esc_html( $table['estimated_plugin'] ); ?></td>
							<td><?php echo esc_html( $table['last_modified'] ); ?></td>
							<td>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/tables/drop"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'table' => $table['table'] ) ) ); ?>'
									data-ss-confirm="<?php echo esc_attr( storage_sherpa_confirm_drop_text() ); ?>">
									<?php esc_html_e( 'Backup & Drop', 'storage-sherpa' ); ?>
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

if ( ! function_exists( 'storage_sherpa_confirm_drop_text' ) ) {
	function storage_sherpa_confirm_drop_text() {
		return __( 'This will take a full backup (schema + rows) before dropping the table. Continue?', 'storage-sherpa' );
	}
}
