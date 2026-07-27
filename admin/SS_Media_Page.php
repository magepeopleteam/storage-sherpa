<?php
/**
 * Modules 2/3/4/7 — Orphan Media, Duplicate Finder, Large File Scanner,
 * Broken Media. One tabbed screen, one WP_List_Table class parametrized by
 * finding_type, since all four share the {prefix}ss_media_findings table
 * and the same "select rows → bulk move to Safe Trash" interaction.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SS_Media_Findings_Table extends WP_List_Table {

	private $finding_type;

	public function __construct( $finding_type ) {
		$this->finding_type = $finding_type;
		parent::__construct(
			array(
				'singular' => 'finding',
				'plural'   => 'findings',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Human labels for every status value that can appear across the four
	 * finding types (orphan: used/possibly_used/unused; duplicate:
	 * original/duplicate; large: large; broken: broken) — one shared map
	 * since none of the values collide across types.
	 */
	private static function status_labels() {
		return array(
			'used'          => __( 'Used', 'storage-sherpa' ),
			'possibly_used' => __( 'Possibly Used', 'storage-sherpa' ),
			'unused'        => __( 'Unused', 'storage-sherpa' ),
			'original'      => __( 'Original', 'storage-sherpa' ),
			'duplicate'     => __( 'Duplicate', 'storage-sherpa' ),
			'large'         => __( 'Large', 'storage-sherpa' ),
			'broken'        => __( 'Broken', 'storage-sherpa' ),
			'unused_size'   => __( 'Unused Size', 'storage-sherpa' ),
			'broken_link'   => __( 'Broken Link', 'storage-sherpa' ),
			'oversized'     => __( 'Oversized', 'storage-sherpa' ),
		);
	}

	/**
	 * The status this request is filtered to — validated against the
	 * statuses that actually exist for this finding type, so a status left
	 * over from switching tabs (e.g. "used" while on the Large Files tab)
	 * silently falls back to "All" instead of returning an empty table.
	 */
	public function current_status_filter() {
		$requested = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$counts    = SS_Media_Findings::counts( $this->finding_type );

		return isset( $counts[ $requested ] ) ? $requested : '';
	}

	public function get_columns() {
		$columns = array(
			'cb'   => '<input type="checkbox" data-ss-select-all />',
			'file' => __( 'File', 'storage-sherpa' ),
		);

		// Large Files is the one tab where knowing exactly where a big file
		// lives (it can be anywhere under wp-content, not just uploads — see
		// SS_Large_File_Scanner) is worth its own column rather than just the
		// basename the File column already shows.
		if ( SS_Media_Findings::TYPE_LARGE === $this->finding_type ) {
			$columns['path'] = __( 'Path', 'storage-sherpa' );
		}

		$columns['status'] = __( 'Status', 'storage-sherpa' );
		$columns['reason'] = __( 'Reason', 'storage-sherpa' );
		$columns['size']   = __( 'Size', 'storage-sherpa' );

		// Confidence only means anything for orphan findings — every other
		// finding type leaves the column at its default 0 and would just
		// show a meaningless "0%" here.
		if ( SS_Media_Findings::TYPE_ORPHAN === $this->finding_type ) {
			$columns['confidence'] = __( 'Confidence', 'storage-sherpa' );
		}

		return $columns;
	}

	/**
	 * The "All | Used (12) | Unused (40 · 128 MB) | ..." filter links WP
	 * natively renders above the table (same mechanism as Posts' "All |
	 * Published | Draft | Trash"), built from whatever statuses actually
	 * exist for this finding type rather than a hardcoded list.
	 */
	public function get_views() {
		$labels        = self::status_labels();
		$counts        = SS_Media_Findings::counts( $this->finding_type );
		$active_status = $this->current_status_filter();
		$base_url      = remove_query_arg( array( 'status', 'paged' ) );

		$views = array(
			'all' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $base_url ),
				'' === $active_status ? 'current' : '',
				esc_html__( 'All', 'storage-sherpa' ),
				array_sum( wp_list_pluck( $counts, 'count' ) )
			),
		);

		foreach ( $counts as $status => $data ) {
			if ( empty( $data['count'] ) ) {
				continue;
			}

			$label = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );

			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d &middot; %s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base_url ) ),
				$status === $active_status ? 'current' : '',
				esc_html( $label ),
				$data['count'],
				esc_html( storage_sherpa_format_bytes( $data['bytes'] ) )
			);
		}

		return $views;
	}

	/**
	 * The current file-name search term — a single read-only source both
	 * prepare_items() and SS_Media_Page::render() (for the search input's
	 * value and the AJAX endpoints' filter args) pull from.
	 */
	public function current_search() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	/**
	 * The current file-type category filter (images/videos/pdfs/zip/… — see
	 * SS_Filetype_Analyzer::categories(), plus "unknown") — validated against
	 * the known category keys so a stray/old value in the URL silently falls
	 * back to "All types" instead of returning an empty table. Only ever
	 * exposed as a control on the Orphan Media tab (see
	 * SS_Media_Page::render()), but honored here for any finding type since
	 * the underlying query support is generic.
	 */
	public function current_file_type_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change.
		$requested = isset( $_GET['file_type'] ) ? sanitize_key( $_GET['file_type'] ) : '';
		$valid     = array_keys( SS_Filetype_Analyzer::labels() );

		return in_array( $requested, $valid, true ) ? $requested : '';
	}

	public function prepare_items() {
		$per_page  = 20;
		$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$status    = $this->current_status_filter();
		$search    = $this->current_search();
		$file_type = $this->current_file_type_filter();

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$rows = SS_Media_Findings::query(
			$this->finding_type,
			array(
				'status'    => $status,
				'search'    => $search,
				'file_type' => $file_type,
				'limit'     => $per_page,
				'offset'    => ( $paged - 1 ) * $per_page,
			)
		);

		$total = SS_Media_Findings::count_matching(
			$this->finding_type,
			array( 'status' => $status, 'search' => $search, 'file_type' => $file_type )
		);

		$this->items = $rows;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ss_ids[]" value="%d" />', $item->id );
	}

	public function column_file( $item ) {
		$name = $item->file_path ? basename( $item->file_path ) : ( '#' . $item->attachment_id );
		$img  = '';

		if ( $item->attachment_id && wp_attachment_is_image( $item->attachment_id ) ) {
			$thumb = wp_get_attachment_image_src( $item->attachment_id, 'thumbnail' );
			$full  = wp_get_attachment_image_src( $item->attachment_id, 'full' );
			if ( $thumb ) {
				$img = '<img class="ss-thumb"'
					. ( $full ? ' data-ss-zoom="' . esc_url( $full[0] ) . '"' : '' )
					. ' src="' . esc_url( $thumb[0] ) . '" alt="" /> ';
			}
		}

		return $img . esc_html( $name ) . $this->break_test_button( $item );
	}

	/**
	 * Not offered for unused_size (needs the metadata-patching trash path,
	 * not a plain file quarantine — see SS_REST_API::break_test_start()) or
	 * broken_link (there's no real file left to quarantine).
	 */
	private function break_test_button( $item ) {
		if ( ! $item->file_path || in_array( $this->finding_type, array( SS_Media_Findings::TYPE_UNUSED_SIZE, SS_Media_Findings::TYPE_BROKEN_LINK ), true ) ) {
			return '';
		}

		return sprintf(
			' <button type="button" class="button button-small" data-ss-action="/storage-sherpa/v1/break-test/start" data-ss-body=\'%s\' data-ss-confirm="%s">%s</button>',
			esc_attr( wp_json_encode( array( 'finding_id' => (int) $item->id ) ) ),
			esc_attr__( 'Quarantine this file and watch for real traffic for 48 hours before treating it as confirmed safe?', 'storage-sherpa' ),
			esc_html__( 'Break Test', 'storage-sherpa' )
		);
	}

	/**
	 * Large Files walks all of wp-content, not just uploads (see
	 * SS_Large_File_Scanner), so the path is worth showing on its own —
	 * relative to wp-content, matching how the Log Cleaner screen already
	 * displays paths, rather than the full absolute server path.
	 */
	public function column_path( $item ) {
		if ( ! $item->file_path ) {
			return '';
		}

		$relative = str_replace(
			storage_sherpa_normalize_path( WP_CONTENT_DIR ),
			'',
			storage_sherpa_normalize_path( $item->file_path )
		);

		return '<code>' . esc_html( $relative ) . '</code>';
	}

	public function column_status( $item ) {
		return '<span class="ss-badge ss-badge-' . esc_attr( $item->status ) . '">' . esc_html( $item->status ) . '</span>';
	}

	/**
	 * For "used"/"possibly_used" orphan findings, this is "where" — a
	 * friendly label (Featured image, EDD download file, ACF field, …) plus,
	 * when the reason names a post, a link to it, rather than the raw
	 * internal reason string (e.g. "edd_download_file:download#123").
	 * Every other status (unused, duplicate, large, broken, …) keeps the
	 * plain reason text exactly as before — those never carry a post
	 * reference in this shape.
	 */
	public function column_reason( $item ) {
		if ( ! in_array( $item->status, array( 'used', 'possibly_used' ), true ) || ! class_exists( 'SS_Orphan_Media_Scanner' ) ) {
			return esc_html( $item->reason );
		}

		$described = SS_Orphan_Media_Scanner::describe_reason( $item->reason );

		if ( ! $described['label'] ) {
			return esc_html( $item->reason );
		}

		if ( ! $described['post_id'] ) {
			return esc_html( $described['label'] );
		}

		$post = get_post( $described['post_id'] );

		if ( ! $post ) {
			// The reason was recorded against a post that's since been
			// deleted or trashed — still say where, just without a dead link.
			return esc_html(
				sprintf(
					/* translators: 1: usage location label, 2: post ID */
					__( '%1$s (post #%2$d, no longer exists)', 'storage-sherpa' ),
					$described['label'],
					$described['post_id']
				)
			);
		}

		$title     = get_the_title( $post );
		$title     = $title ? $title : sprintf( '#%d', $post->ID );
		$edit_link = get_edit_post_link( $post );

		if ( ! $edit_link ) {
			return esc_html( $described['label'] ) . ' — ' . esc_html( $title );
		}

		return esc_html( $described['label'] ) . ' — <a href="' . esc_url( $edit_link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
	}

	/**
	 * "100% safe to delete" reads very differently than a bare orphan/used
	 * verdict — see SS_Media_Findings::confidence_label() and
	 * SS_Orphan_Media_Scanner::safe_to_delete_confidence() for how the
	 * score is derived. Badge colors deliberately mirror the Status
	 * column's existing convention (red = "unused"/safe to clean up,
	 * green = "used"/leave it alone) rather than inventing a new color
	 * language, so the two columns never visually disagree on the same row.
	 */
	public function column_confidence( $item ) {
		$confidence = isset( $item->confidence ) ? (int) $item->confidence : 0;
		$class      = $confidence >= 95 ? 'ss-badge-unused' : ( $confidence >= 70 ? 'ss-badge-possibly_used' : 'ss-badge-used' );

		return '<span class="ss-badge ' . esc_attr( $class ) . '">' . esc_html( SS_Media_Findings::confidence_label( $confidence ) ) . '</span>';
	}

	public function column_size( $item ) {
		return esc_html( storage_sherpa_format_bytes( $item->file_size ) );
	}

	/**
	 * WP_List_Table's own hook for extra controls in the tablenav row —
	 * called between the (empty, since we don't define get_bulk_actions())
	 * bulkactions div and the pagination links, on both the top and bottom
	 * tablenav. Only rendered once (top) to avoid a duplicate #ss-media-filetype
	 * id, and only on the Orphan Media tab where this filter applies.
	 */
	/**
	 * WP_List_Table's own hook for extra controls in the tablenav row —
	 * called between the (empty, since we don't define get_bulk_actions())
	 * bulkactions div and the pagination links, on both the top and bottom
	 * tablenav. Only rendered once (top) so the "Bulk actions" select/Apply
	 * and the file-type filter land in the exact same row as the pagination
	 * — the views()/subsubsub list above it is the only other row on this
	 * screen, matching the standard WP admin list-table layout (subsubsub
	 * row, then one tablenav row with actions + filters + pagination).
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions ss-tablenav-actions">
			<select name="ss_bulk_action" class="ss-tablenav-select">
				<option value="-1"><?php esc_html_e( 'Bulk actions', 'storage-sherpa' ); ?></option>
				<option value="trash"><?php esc_html_e( 'Move to Safe Trash', 'storage-sherpa' ); ?></option>
			</select>
			<input type="submit" class="button action ss-tablenav-select" value="<?php esc_attr_e( 'Apply', 'storage-sherpa' ); ?>" />
		</div>
		<?php if ( SS_Media_Findings::TYPE_ORPHAN === $this->finding_type ) : ?>
			<?php $file_type_filter = $this->current_file_type_filter(); ?>
			<div class="alignleft actions ss-tablenav-actions">
				<select id="ss-media-filetype" name="file_type" class="ss-tablenav-select" aria-label="<?php esc_attr_e( 'Filter by file type', 'storage-sherpa' ); ?>">
					<option value=""><?php esc_html_e( 'All file types', 'storage-sherpa' ); ?></option>
					<?php foreach ( SS_Filetype_Analyzer::labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $file_type_filter, $key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>
		<?php
	}
}

class SS_Media_Page {

	private static function tabs() {
		return array(
			'orphan'      => array( __( 'Orphan Media', 'storage-sherpa' ), SS_Media_Findings::TYPE_ORPHAN ),
			'duplicate'   => array( __( 'Duplicates', 'storage-sherpa' ), SS_Media_Findings::TYPE_DUPLICATE ),
			'large'       => array( __( 'Large Files', 'storage-sherpa' ), SS_Media_Findings::TYPE_LARGE ),
			'broken'      => array( __( 'Broken Media', 'storage-sherpa' ), SS_Media_Findings::TYPE_BROKEN ),
			'unused_size' => array( __( 'Unused Sizes', 'storage-sherpa' ), SS_Media_Findings::TYPE_UNUSED_SIZE ),
			'broken_link' => array( __( 'Broken Links', 'storage-sherpa' ), SS_Media_Findings::TYPE_BROKEN_LINK ),
			'oversized'   => array( __( 'Oversized Images', 'storage-sherpa' ), SS_Media_Findings::TYPE_OVERSIZED ),
		);
	}

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$tabs       = self::tabs();
		$active_tab = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'orphan'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$is_duplicates_tab = ( 'duplicate' === $active_tab );

		list( , $finding_type ) = $tabs[ $active_tab ];

		// The Duplicates tab uses its own grouped visual-compare view below
		// (render_duplicates_view()) instead of the shared flat findings
		// table every other tab uses — a duplicate group only makes sense
		// shown side by side, not as independent rows.
		if ( ! $is_duplicates_tab ) {
			$table = new SS_Media_Findings_Table( $finding_type );
			$table->prepare_items();
			$search           = $table->current_search();
			$status_filter    = $table->current_status_filter();
			$file_type_filter = $table->current_file_type_filter();
		}
		?>
		<?php SS_Admin::header( __( 'Media Findings', 'storage-sherpa' ) ); ?>

			<nav class="ss-tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a class="<?php echo $slug === $active_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=storage-sherpa-media&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $tab[0] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'orphan' === $active_tab ) : ?>
				<?php
					$integrations = SS_Orphan_Media_Scanner::active_integrations();
					$detected     = __( 'detected, files checked', 'storage-sherpa' );
					$not_detected = __( 'not detected', 'storage-sherpa' );
				?>
				<p class="ss-muted ss-integrations-note">
					<?php esc_html_e( 'Downloadable-file protection —', 'storage-sherpa' ); ?>
					<?php
					echo esc_html(
						/* translators: %s: "detected, files checked" or "not detected" */
						sprintf( __( 'WooCommerce: %s', 'storage-sherpa' ), $integrations['woocommerce'] ? $detected : $not_detected )
					);
					?>
					&middot;
					<?php
					echo esc_html(
						/* translators: %s: "detected, files checked" or "not detected" */
						sprintf( __( 'Easy Digital Downloads: %s', 'storage-sherpa' ), $integrations['edd'] ? $detected : $not_detected )
					);
					?>
				</p>
				<?php if ( get_option( SS_Orphan_Media_Scanner::INCOMPLETE_OPTION ) ) : ?>
					<p class="ss-muted ss-scan-incomplete-note">
						<?php esc_html_e( 'The last scan didn\'t have time to check every post/page before its time budget ran out — on a large site that can take more than one pass. Files this scanner already confirmed "Used" stay protected in the meantime. Click "Scan Now" again to keep checking the rest of your content.', 'storage-sherpa' ); ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="ss-toolbar">
				<button class="button button-primary" data-ss-action="/storage-sherpa/v1/media/<?php echo esc_attr( $active_tab ); ?>/scan">
					<?php esc_html_e( 'Scan Now', 'storage-sherpa' ); ?>
				</button>
				<span class="ss-status"></span>

				<?php if ( ! $is_duplicates_tab ) : ?>
					<form id="ss-media-search-form" class="ss-media-search-w" method="get">
						<input type="hidden" name="page" value="storage-sherpa-media" />
						<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>" />
						<?php if ( '' !== $status_filter ) : ?>
							<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>" />
						<?php endif; ?>
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input
							type="search"
							id="ss-media-search"
							name="s"
							value="<?php echo esc_attr( $search ); ?>"
							placeholder="<?php esc_attr_e( 'Search by file name…', 'storage-sherpa' ); ?>"
						/>
						<button type="submit" class="screen-reader-text"><?php esc_html_e( 'Search', 'storage-sherpa' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( $is_duplicates_tab ) : ?>

				<?php self::render_duplicates_view(); ?>

			<?php else : ?>

				<div id="ss-media-selection-bar" class="ss-media-selection-bar" hidden>
					<span id="ss-media-selection-text"></span>
					<button type="button" id="ss-media-select-all-matching" class="button-link" hidden></button>
					<button type="button" id="ss-media-clear-selection" class="button-link"><?php esc_html_e( 'Clear selection', 'storage-sherpa' ); ?></button>
				</div>

				<div id="ss-media-progress" class="ss-media-progress" hidden role="status" aria-live="polite">
					<div class="ss-media-progress-track"><div class="ss-media-progress-fill"></div></div>
					<span id="ss-media-progress-label" class="ss-media-progress-label"></span>
					<button type="button" id="ss-media-progress-cancel" class="button-link"><?php esc_html_e( 'Cancel', 'storage-sherpa' ); ?></button>
				</div>

				<div
					id="ss-media-table-region"
					data-tab="<?php echo esc_attr( $active_tab ); ?>"
					data-status="<?php echo esc_attr( $status_filter ); ?>"
					data-search="<?php echo esc_attr( $search ); ?>"
					data-file-type="<?php echo esc_attr( $file_type_filter ); ?>"
					data-total-items="<?php echo (int) $table->get_pagination_arg( 'total_items' ); ?>"
				>
					<?php $table->views(); ?>

					<form data-ss-bulk-trash="/storage-sherpa/v1/media/trash" id="ss-media-bulk-form">
						<?php $table->display(); ?>
					</form>
				</div>

			<?php endif; ?>

			<?php if ( class_exists( 'SS_Break_Test' ) ) : ?>
				<?php $running_tests = SS_Break_Test::list_running(); ?>
				<?php if ( ! empty( $running_tests ) ) : ?>
					<div class="ss-section">
						<h2><?php esc_html_e( 'Break Tests in Progress', 'storage-sherpa' ); ?></h2>
						<p class="ss-muted"><?php esc_html_e( 'Files currently quarantined and being watched for real traffic before they\'re treated as confirmed safe.', 'storage-sherpa' ); ?></p>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'File', 'storage-sherpa' ); ?></th>
									<th><?php esc_html_e( 'Started', 'storage-sherpa' ); ?></th>
									<th><?php esc_html_e( 'Watching Until', 'storage-sherpa' ); ?></th>
									<th><?php esc_html_e( 'Hits So Far', 'storage-sherpa' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $running_tests as $test ) : ?>
									<tr>
										<td><?php echo esc_html( basename( $test->original_path ) ); ?></td>
										<td><?php echo esc_html( $test->started_at ); ?></td>
										<td><?php echo esc_html( $test->expires_at ); ?></td>
										<td>
											<?php echo esc_html( number_format_i18n( $test->hit_count ) ); ?>
											<?php if ( $test->hit_count > 0 ) : ?>
												<span class="ss-badge ss-badge-broken"><?php esc_html_e( 'In use — will auto-restore', 'storage-sherpa' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php SS_Admin::footer(); ?>
		<?php
	}

	/**
	 * The Duplicates tab's grouped visual-compare view — one card per
	 * group_hash with every copy shown side by side (thumbnail for images,
	 * a generic file icon for the non-image types this scanner also covers:
	 * PDFs, video, audio, documents, archives). A radio per item lets the
	 * admin pick which copy to keep (defaulting to the oldest upload, the
	 * same "original" SS_Duplicate_Finder::scan() already picked), then
	 * either:
	 *  - "Keep selected — merge & trash the rest": the recommended path,
	 *    re-points known-safe references before trashing (see
	 *    SS_Duplicate_Finder::merge_attachment()) — matters here because,
	 *    unlike Orphan Media, this scanner never checks whether a given copy
	 *    is itself in use anywhere.
	 *  - "Trash unselected (no re-pointing)": a plain Safe Trash delete via
	 *    the same /media/trash endpoint every other tab uses, for admins who
	 *    already know none of the other copies are referenced elsewhere.
	 */
	private static function render_duplicates_view() {
		$groups = SS_Duplicate_Finder::grouped_findings();
		?>
		<?php if ( empty( $groups ) ) : ?>
			<div class="ss-section">
				<p class="ss-muted"><?php esc_html_e( 'No duplicate files found. Run a scan to check again.', 'storage-sherpa' ); ?></p>
			</div>
		<?php endif; ?>

		<?php foreach ( $groups as $group_hash => $items ) : ?>
			<?php
			$per_copy_size = $items ? (int) $items[0]->file_size : 0;
			$reclaimable   = $per_copy_size * max( 0, count( $items ) - 1 );
			?>
			<div class="ss-dup-group" data-group-hash="<?php echo esc_attr( $group_hash ); ?>">
				<div class="ss-dup-group-header">
					<span>
						<?php
						printf(
							/* translators: 1: number of copies found, 2: space that could be reclaimed by merging */
							esc_html__( '%1$d copies of this file · reclaim %2$s by merging', 'storage-sherpa' ),
							count( $items ),
							esc_html( storage_sherpa_format_bytes( $reclaimable ) )
						);
						?>
					</span>
				</div>
				<div class="ss-dup-group-items">
					<?php foreach ( $items as $item ) : ?>
						<?php
						$is_image = $item->attachment_id && wp_attachment_is_image( $item->attachment_id );
						$thumb    = $is_image ? wp_get_attachment_image_src( $item->attachment_id, 'medium' ) : false;
						$full     = $is_image ? wp_get_attachment_image_src( $item->attachment_id, 'full' ) : false;
						$post     = $item->attachment_id ? get_post( $item->attachment_id ) : null;
						?>
						<label
							class="ss-dup-item"
							data-attachment-id="<?php echo (int) $item->attachment_id; ?>"
							data-finding-id="<?php echo (int) $item->id; ?>"
						>
							<input
								type="radio"
								name="ss-dup-keep-<?php echo esc_attr( $group_hash ); ?>"
								value="<?php echo (int) $item->attachment_id; ?>"
								<?php checked( 'original' === $item->status ); ?>
							/>
							<span class="ss-dup-thumb">
								<?php if ( $thumb ) : ?>
									<img
										src="<?php echo esc_url( $thumb[0] ); ?>"
										alt=""
										<?php if ( $full ) : ?>data-ss-zoom="<?php echo esc_url( $full[0] ); ?>"<?php endif; ?>
									/>
								<?php else : ?>
									<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
								<?php endif; ?>
							</span>
							<span class="ss-dup-meta">
								<span class="ss-dup-filename"><?php echo esc_html( basename( $item->file_path ) ); ?></span>
								<span class="ss-dup-details">
									<?php echo esc_html( storage_sherpa_format_bytes( $item->file_size ) ); ?>
									<?php if ( $post ) : ?>
										&middot; <?php echo esc_html( mysql2date( get_option( 'date_format' ), $post->post_date ) ); ?>
									<?php endif; ?>
								</span>
								<?php if ( 'original' === $item->status ) : ?>
									<span class="ss-badge ss-badge-original"><?php esc_html_e( 'Oldest upload', 'storage-sherpa' ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="ss-dup-group-actions">
					<button type="button" class="button button-primary" data-ss-merge-group>
						<?php esc_html_e( 'Keep selected — merge & trash the rest', 'storage-sherpa' ); ?>
					</button>
					<button
						type="button"
						class="button"
						data-ss-trash-others
						data-ss-confirm="<?php esc_attr_e( 'Move the unselected copies to Safe Trash without re-pointing any references to them? Only do this if you already know none of them are used elsewhere on your site.', 'storage-sherpa' ); ?>"
					>
						<?php esc_html_e( 'Trash unselected (no re-pointing)', 'storage-sherpa' ); ?>
					</button>
					<span class="ss-status"></span>
				</div>
			</div>
		<?php endforeach; ?>
		<?php
	}
}
