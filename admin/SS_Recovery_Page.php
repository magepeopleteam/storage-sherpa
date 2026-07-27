<?php
/**
 * Module 19 — Recovery Center. Every deletion from every module lands
 * here (files, DB rows, and whole table dumps alike) until its retention
 * window expires — see SS_Trash for the underlying mechanism.
 *
 * Selection/bulk-action UX deliberately mirrors the Media Findings screen
 * (AJAX search, checkbox selection with "select all N items matching this
 * filter", chunked bulk actions with a progress bar) — see
 * assets/admin/js/storage-sherpa-recovery.js for the client-side half.
 * Restore and Delete Permanently are both offered as bulk actions here,
 * unlike Media Findings' single "Move to Safe Trash" — this screen is the
 * one place both directions of the safety pipeline meet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Recovery_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination/search, not a state change.
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change.
		$requested_file_type = isset( $_GET['file_type'] ) ? sanitize_key( $_GET['file_type'] ) : '';
		$file_type            = in_array( $requested_file_type, array_keys( SS_Filetype_Analyzer::labels() ), true ) ? $requested_file_type : '';

		$total_items = SS_Trash::count_matching( array( 'search' => $search, 'file_type' => $file_type ) );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$paged       = min( $paged, $total_pages );

		$items = SS_Trash::query(
			array(
				'search'    => $search,
				'file_type' => $file_type,
				'limit'     => $per_page,
				'offset'    => ( $paged - 1 ) * $per_page,
			)
		);
		$settings = storage_sherpa_get_settings();
		?>
		<?php SS_Admin::header( __( 'Recovery Center', 'storage-sherpa' ) ); ?>
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

				<form id="ss-recovery-search-form" class="ss-media-search-w" method="get">
					<input type="hidden" name="page" value="storage-sherpa-recovery" />
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input
						type="search"
						id="ss-recovery-search"
						name="s"
						value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Search by file name or path…', 'storage-sherpa' ); ?>"
					/>
					<button type="submit" class="screen-reader-text"><?php esc_html_e( 'Search', 'storage-sherpa' ); ?></button>
				</form>
			</div>

			<div id="ss-recovery-selection-bar" class="ss-media-selection-bar" hidden>
				<span id="ss-recovery-selection-text"></span>
				<button type="button" id="ss-recovery-select-all-matching" class="button-link" hidden></button>
				<button type="button" id="ss-recovery-clear-selection" class="button-link"><?php esc_html_e( 'Clear selection', 'storage-sherpa' ); ?></button>
			</div>

			<div id="ss-recovery-progress" class="ss-media-progress" hidden role="status" aria-live="polite">
				<div class="ss-media-progress-track"><div class="ss-media-progress-fill"></div></div>
				<span id="ss-recovery-progress-label" class="ss-media-progress-label"></span>
				<button type="button" id="ss-recovery-progress-cancel" class="button-link"><?php esc_html_e( 'Cancel', 'storage-sherpa' ); ?></button>
			</div>

			<div id="ss-recovery-table-region" data-search="<?php echo esc_attr( $search ); ?>" data-file-type="<?php echo esc_attr( $file_type ); ?>" data-total-items="<?php echo (int) $total_items; ?>">
				<form id="ss-recovery-bulk-form" data-ss-bulk-recovery="1">
					<div class="tablenav top">
						<div class="alignleft actions ss-tablenav-actions">
							<select name="ss_bulk_action" id="ss-recovery-bulk-action" class="ss-tablenav-select">
								<option value="-1"><?php esc_html_e( 'Bulk actions', 'storage-sherpa' ); ?></option>
								<option value="restore"><?php esc_html_e( 'Restore', 'storage-sherpa' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete Permanently', 'storage-sherpa' ); ?></option>
							</select>
							<input type="submit" class="button action ss-tablenav-select" value="<?php esc_attr_e( 'Apply', 'storage-sherpa' ); ?>" />
						</div>
						<div class="alignleft actions ss-tablenav-actions">
							<select id="ss-recovery-filetype" name="file_type" class="ss-tablenav-select" aria-label="<?php esc_attr_e( 'Filter by file type', 'storage-sherpa' ); ?>">
								<option value=""><?php esc_html_e( 'All file types', 'storage-sherpa' ); ?></option>
								<?php foreach ( SS_Filetype_Analyzer::labels() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $file_type, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php SS_Admin::render_pagination( $total_items, $paged, $total_pages ); ?>
					</div>

					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:32px;"><input type="checkbox" data-ss-select-all /></th>
								<th><?php esc_html_e( 'Item', 'storage-sherpa' ); ?></th>
								<th><?php esc_html_e( 'File', 'storage-sherpa' ); ?></th>
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
								<tr>
									<td colspan="9">
										<?php echo $search ? esc_html__( 'No trashed items match your search.', 'storage-sherpa' ) : esc_html__( 'Safe Trash is empty.', 'storage-sherpa' ); ?>
									</td>
								</tr>
							<?php endif; ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td><input type="checkbox" name="ss_ids[]" value="<?php echo (int) $item->id; ?>" /></td>
									<td><?php echo esc_html( $item->label ); ?></td>
									<td>
										<?php if ( SS_Trash_Preview::is_previewable( $item ) ) : ?>
											<?php $preview_url = SS_Trash_Preview::preview_url( $item->id ); ?>
											<img class="ss-thumb" src="<?php echo esc_url( $preview_url ); ?>" data-ss-zoom="<?php echo esc_url( $preview_url ); ?>" alt="" />
										<?php else : ?>
											<?php $relative_path = self::relative_path( $item->original_path ); ?>
											<?php if ( $relative_path ) : ?>
												<code><?php echo esc_html( $relative_path ); ?></code>
											<?php else : ?>
												&#8212;
											<?php endif; ?>
										<?php endif; ?>
									</td>
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
										<button class="button ss-btn-danger"
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

					<div class="tablenav bottom">
						<?php SS_Admin::render_pagination( $total_items, $paged, $total_pages ); ?>
					</div>
				</form>
			</div>
		<?php SS_Admin::footer(); ?>
		<?php
	}

	/**
	 * original_path is an absolute server path (only ever set for
	 * item_type = 'file' — NULL for db_row/table_dump entries, which have
	 * no file of their own). Shown relative to wp-content when the path is
	 * inside it (true for the overwhelming majority — uploads, cache, logs,
	 * backups) to match every other "Path" column in this plugin; falls
	 * back to relative-to-ABSPATH, then the raw path, for the rare item
	 * this plugin trashed from somewhere else under the WP install.
	 */
	private static function relative_path( $path ) {
		if ( ! $path ) {
			return '';
		}

		$normalized  = storage_sherpa_normalize_path( $path );
		$content_dir = storage_sherpa_normalize_path( WP_CONTENT_DIR );

		if ( 0 === strpos( $normalized, $content_dir ) ) {
			return str_replace( $content_dir, '', $normalized );
		}

		$abspath = storage_sherpa_normalize_path( ABSPATH );

		if ( 0 === strpos( $normalized, $abspath ) ) {
			return '/' . ltrim( str_replace( $abspath, '', $normalized ), '/' );
		}

		return $normalized;
	}
}
