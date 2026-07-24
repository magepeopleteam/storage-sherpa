<?php
/**
 * Module 16 — Autoload Option Analyzer screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Autoload_Page {

	public static function render() {
		if ( ! storage_sherpa_current_user_can() ) {
			return;
		}

		$options = SS_Autoload_Analyzer::scan( 100 );
		$total   = SS_Autoload_Analyzer::total_autoload_size();
		?>
		<?php SS_Admin::header( __( 'Autoload Option Analyzer', 'storage-sherpa' ) ); ?>
			<p class="ss-muted">
				<?php
				printf(
					/* translators: %s: total autoloaded size */
					esc_html__( 'Every autoloaded option is loaded into memory on every single request. Total autoloaded size: %s', 'storage-sherpa' ),
					esc_html( storage_sherpa_format_bytes( $total ) )
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Option', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Size', 'storage-sherpa' ); ?></th>
						<th><?php esc_html_e( 'Likely Owner', 'storage-sherpa' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $options as $option ) : ?>
						<tr>
							<td><code><?php echo esc_html( $option['option_name'] ); ?></code></td>
							<td><?php echo esc_html( storage_sherpa_format_bytes( $option['size'] ) ); ?></td>
							<td><?php echo esc_html( $option['owner'] ); ?></td>
							<td>
								<button class="button"
									data-ss-action="/storage-sherpa/v1/autoload/toggle"
									data-ss-body='<?php echo esc_attr( wp_json_encode( array( 'option_name' => $option['option_name'], 'autoload' => false ) ) ); ?>'
									data-ss-confirm="<?php esc_attr_e( 'Disable autoloading for this option? The option itself is not deleted and this can be reversed anytime.', 'storage-sherpa' ); ?>">
									<?php esc_html_e( 'Disable Autoload', 'storage-sherpa' ); ?>
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
