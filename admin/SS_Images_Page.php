<?php
/**
 * Module 5 — Image Optimizer screen. Every action here already existed as
 * a real, working REST route (SS_Image_Optimizer::compress()/generate_webp()/
 * generate_avif()/remove_unused_thumbnails() via inc/SS_REST_API.php) — this
 * is the admin surface that was missing: a list of images that actually need
 * one of those actions, with both per-row buttons and a bulk WebP/AVIF/
 * compress action across a checked selection.
 *
 * No persisted findings table here (unlike Media Findings) — scan_merged()
 * is a live, time-bounded read (same "bounded scan, no pagination" precedent
 * as the File Type Analyzer screen) since generating a sibling file or
 * re-encoding in place isn't a "delete candidate" Safe Trash needs to track.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Images_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$rows           = SS_Image_Optimizer::scan_merged();
		$webp_supported = SS_Image_Optimizer::webp_supported();
		$avif_supported = SS_Image_Optimizer::avif_supported();

		$counts = array(
			'oversized'    => 0,
			'missing_webp' => 0,
			'missing_avif' => 0,
		);
		foreach ( $rows as $row ) {
			foreach ( array_keys( $counts ) as $key ) {
				if ( ! empty( $row[ $key ] ) ) {
					++$counts[ $key ];
				}
			}
		}

		// scan_merged() itself isn't paginated (it's a live, time-bounded scan
		// of every image attachment — see the class docblock), but with a
		// large media library the resulting list can still be long, so the
		// *display* of it is paginated here, the same 20-per-page WP_List_Table
		// tables elsewhere in this plugin use.
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$total_items = count( $rows );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$paged       = min( $paged, $total_pages );
		$page_rows   = array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page );
		?>
		<?php SS_Admin::header( __( 'Image Optimizer', 'storage-sherpa' ) ); ?>

			<p class="ss-muted">
				<?php esc_html_e( 'Every JPEG/PNG attachment checked against three things: oversized (base file over the configured threshold), missing a WebP sibling, missing an AVIF sibling. Generating WebP/AVIF adds a smaller sibling file for modern browsers to prefer — it does not touch or replace the original. Compress re-encodes the original in place at a lower quality and is backed up to Safe Trash first.', 'storage-sherpa' ); ?>
			</p>

			<div class="ss-stat-grid">
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Oversized', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( number_format_i18n( $counts['oversized'] ) ); ?></div>
				</div>
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Missing WebP', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( number_format_i18n( $counts['missing_webp'] ) ); ?></div>
				</div>
				<div class="ss-card">
					<div class="ss-card-label"><?php esc_html_e( 'Missing AVIF', 'storage-sherpa' ); ?></div>
					<div class="ss-card-value"><?php echo esc_html( number_format_i18n( $counts['missing_avif'] ) ); ?></div>
				</div>
			</div>

			<?php if ( ! $webp_supported || ! $avif_supported ) : ?>
				<p class="ss-muted">
					<?php if ( ! $webp_supported ) : ?>
						<?php esc_html_e( 'WebP encoding is not available on this server\'s GD/Imagick build.', 'storage-sherpa' ); ?>
					<?php endif; ?>
					<?php if ( ! $avif_supported ) : ?>
						<?php esc_html_e( 'AVIF encoding is not available on this server\'s GD/Imagick build (GD needs PHP 8.1+, or Imagick needs a libheif-enabled build).', 'storage-sherpa' ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<div id="ss-images-progress" class="ss-bulk-progress" hidden role="status" aria-live="polite">
				<div class="ss-bulk-progress-track"><div class="ss-bulk-progress-fill"></div></div>
				<span id="ss-images-progress-label" class="ss-bulk-progress-label"></span>
				<button type="button" id="ss-images-progress-cancel" class="button-link"><?php esc_html_e( 'Cancel', 'storage-sherpa' ); ?></button>
			</div>

			<form id="ss-images-bulk-form">
				<div class="tablenav top">
					<div class="alignleft actions">
						<select name="ss_bulk_action" id="ss-images-bulk-action">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'storage-sherpa' ); ?></option>
							<?php if ( $webp_supported ) : ?>
								<option value="webp"><?php esc_html_e( 'Generate WebP', 'storage-sherpa' ); ?></option>
							<?php endif; ?>
							<?php if ( $avif_supported ) : ?>
								<option value="avif"><?php esc_html_e( 'Generate AVIF', 'storage-sherpa' ); ?></option>
							<?php endif; ?>
							<?php if ( $webp_supported && $avif_supported ) : ?>
								<option value="webp_avif"><?php esc_html_e( 'Generate WebP + AVIF', 'storage-sherpa' ); ?></option>
							<?php endif; ?>
							<option value="compress"><?php esc_html_e( 'Compress (re-encode at lower quality)', 'storage-sherpa' ); ?></option>
						</select>
						<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'storage-sherpa' ); ?>" />
					</div>
					<?php SS_Admin::render_pagination( $total_items, $paged, $total_pages ); ?>
				</div>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:32px;"><input type="checkbox" data-ss-select-all /></th>
							<th style="width:220px;"><?php esc_html_e( 'File', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Path', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
							<th><?php esc_html_e( 'Flags', 'storage-sherpa' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $page_rows ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No images need attention right now — everything is within your size threshold and already has WebP/AVIF siblings.', 'storage-sherpa' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $page_rows as $row ) : ?>
							<?php $id = $row['attachment_id']; ?>
							<tr>
								<td><input type="checkbox" name="ss_ids[]" value="<?php echo (int) $id; ?>" /></td>
								<td>
									<?php $thumb = wp_get_attachment_image_src( $id, 'thumbnail' ); ?>
									<?php $full = wp_get_attachment_image_src( $id, 'full' ); ?>
									<?php if ( $thumb ) : ?>
										<img
											class="ss-thumb"
											src="<?php echo esc_url( $thumb[0] ); ?>"
											alt=""
											<?php if ( $full ) : ?>data-ss-zoom="<?php echo esc_url( $full[0] ); ?>"<?php endif; ?>
										/>
									<?php endif; ?>
									<span class="ss-filename" title="<?php echo esc_attr( basename( $row['file_path'] ) ); ?>"><?php echo esc_html( basename( $row['file_path'] ) ); ?></span>
								</td>
								<td>
									<?php
									$relative_path = str_replace(
										storage_sherpa_normalize_path( WP_CONTENT_DIR ),
										'',
										storage_sherpa_normalize_path( dirname( $row['file_path'] ) )
									);
									?>
									<code><?php echo esc_html( $relative_path ); ?></code>
								</td>
								<td><?php echo esc_html( storage_sherpa_format_bytes( $row['file_size'] ) ); ?></td>
								<td>
									<?php if ( $row['oversized'] ) : ?>
										<span class="ss-badge ss-badge-oversized"><?php esc_html_e( 'Oversized', 'storage-sherpa' ); ?></span>
									<?php endif; ?>
									<?php if ( $row['missing_webp'] ) : ?>
										<span class="ss-badge ss-badge-unused"><?php esc_html_e( 'No WebP', 'storage-sherpa' ); ?></span>
									<?php endif; ?>
									<?php if ( $row['missing_avif'] ) : ?>
										<span class="ss-badge ss-badge-unused"><?php esc_html_e( 'No AVIF', 'storage-sherpa' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="ss-flex">
									<?php if ( $row['missing_webp'] && $webp_supported ) : ?>
										<button class="button button-small" data-ss-action="/storage-sherpa/v1/images/<?php echo (int) $id; ?>/webp">
											<?php esc_html_e( 'WebP', 'storage-sherpa' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( $row['missing_avif'] && $avif_supported ) : ?>
										<button class="button button-small" data-ss-action="/storage-sherpa/v1/images/<?php echo (int) $id; ?>/avif">
											<?php esc_html_e( 'AVIF', 'storage-sherpa' ); ?>
										</button>
									<?php endif; ?>
									<?php if ( $row['oversized'] ) : ?>
										<button class="button button-small"
											data-ss-action="/storage-sherpa/v1/images/<?php echo (int) $id; ?>/compress"
											data-ss-confirm="<?php esc_attr_e( 'Re-encode this image at a lower quality? The original is backed up to Safe Trash first.', 'storage-sherpa' ); ?>">
											<?php esc_html_e( 'Compress', 'storage-sherpa' ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="tablenav bottom">
					<?php SS_Admin::render_pagination( $total_items, $paged, $total_pages ); ?>
				</div>
			</form>
		<?php SS_Admin::footer(); ?>
		<?php
	}
}
