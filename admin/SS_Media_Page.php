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
	private function current_status_filter() {
		$requested = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$counts    = SS_Media_Findings::counts( $this->finding_type );

		return isset( $counts[ $requested ] ) ? $requested : '';
	}

	public function get_columns() {
		$columns = array(
			'cb'     => '<input type="checkbox" data-ss-select-all />',
			'file'   => __( 'File', 'storage-sherpa' ),
			'status' => __( 'Status', 'storage-sherpa' ),
			'reason' => __( 'Reason', 'storage-sherpa' ),
			'size'   => __( 'Size', 'storage-sherpa' ),
		);

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

	public function prepare_items() {
		$per_page = 20;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$status   = $this->current_status_filter();

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$rows = SS_Media_Findings::query(
			$this->finding_type,
			array(
				'status' => $status,
				'limit'  => $per_page,
				'offset' => ( $paged - 1 ) * $per_page,
			)
		);

		$counts = SS_Media_Findings::counts( $this->finding_type );
		$total  = $status && isset( $counts[ $status ] )
			? $counts[ $status ]['count']
			: array_sum( wp_list_pluck( $counts, 'count' ) );

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
			if ( $thumb ) {
				$img = '<img class="ss-thumb" src="' . esc_url( $thumb[0] ) . '" alt="" /> ';
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

	public function column_status( $item ) {
		return '<span class="ss-badge ss-badge-' . esc_attr( $item->status ) . '">' . esc_html( $item->status ) . '</span>';
	}

	public function column_reason( $item ) {
		return esc_html( $item->reason );
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

		list( , $finding_type ) = $tabs[ $active_tab ];

		$table = new SS_Media_Findings_Table( $finding_type );
		$table->prepare_items();
		?>
		<div class="wrap storage-sherpa-wrap">
			<h1><?php esc_html_e( 'Media Findings', 'storage-sherpa' ); ?></h1>

			<nav class="ss-tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a class="<?php echo $slug === $active_tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=storage-sherpa-media&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $tab[0] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php $table->views(); ?>

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
			<?php endif; ?>

			<div class="ss-toolbar">
				<button class="button button-primary" data-ss-action="/storage-sherpa/v1/media/<?php echo esc_attr( $active_tab ); ?>/scan">
					<?php esc_html_e( 'Scan Now', 'storage-sherpa' ); ?>
				</button>
				<span class="ss-status"></span>
			</div>

			<form data-ss-bulk-action="/storage-sherpa/v1/media/trash">
				<div class="tablenav top">
					<div class="alignleft actions">
						<select name="ss_bulk_action">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'storage-sherpa' ); ?></option>
							<option value="trash"><?php esc_html_e( 'Move to Safe Trash', 'storage-sherpa' ); ?></option>
						</select>
						<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'storage-sherpa' ); ?>" />
					</div>
				</div>
				<?php $table->display(); ?>
			</form>

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
		</div>
		<?php
	}
}
