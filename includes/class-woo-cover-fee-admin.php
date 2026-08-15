<?php
/**
 * Admin settings screen.
 *
 * Menu sits under WooCommerce and is gated on manage_woocommerce, so shop
 * managers can reach it without being full administrators.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Fee_Admin {

	const CAP         = 'manage_woocommerce';
	const SLUG        = 'woo-cover-processing-fee';
	const SAVE_ACTION = 'woo_cover_fee_save';
	const NONCE       = 'woo_cover_fee_settings';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WOO_COVER_FEE_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Shared guard for the settings write.
	 *
	 * Unlike the checkout endpoint this one is authenticated, so it gets both
	 * halves: nonce and capability.
	 */
	private function guard() {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change these settings.', 'woo-cover-processing-fee' ),
				esc_html__( 'Unauthorized', 'woo-cover-processing-fee' ),
				array( 'response' => 403 )
			);
		}
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Cover Processing Fee', 'woo-cover-processing-fee' ),
			__( 'Processing Fee', 'woo-cover-processing-fee' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woo-cover-processing-fee' ) . '</a>'
		);
		return $links;
	}

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) return;

		wp_enqueue_style(
			'woo-cover-fee-admin',
			WOO_COVER_FEE_URL . 'assets/css/admin.css',
			array(),
			WOO_COVER_FEE_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------------ */

	public function handle_save() {
		$this->guard();

		// Passed through raw — sanitize() unslashes and validates per field.
		$raw = ( isset( $_POST['wcf'] ) && is_array( $_POST['wcf'] ) ) ? $_POST['wcf'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		update_option( WOO_Cover_Fee_Settings::OPTION, WOO_Cover_Fee_Settings::sanitize( $raw ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'updated' => 'true' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * View
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'woo-cover-processing-fee' ) );
		}

		$s        = WOO_Cover_Fee_Settings::get();
		$currency = html_entity_decode( get_woocommerce_currency_symbol() );

		// Worked example so the gross-up is legible rather than theoretical.
		$example_base = 100.0;
		$example_fee  = WOO_Cover_Fee_Settings::processing_fee( $example_base, $s['rate'], $s['flat'] );
		?>
		<div class="wrap wcf-admin">

			<div class="wcf-page-header">
				<h1><?php esc_html_e( 'Cover Processing Fee', 'woo-cover-processing-fee' ); ?></h1>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'woo-cover-processing-fee' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<!-- ------------------------------------------------ Availability -->
				<div class="wcf-card">
					<div class="wcf-card-body">
						<label class="wcf-toggle">
							<input type="checkbox" name="wcf[enabled]" value="1" <?php checked( $s['enabled'] ); ?> />
							<span class="wcf-toggle-text">
								<strong><?php esc_html_e( 'Offer customers the option to cover processing', 'woo-cover-processing-fee' ); ?></strong>
								<span class="wcf-help">
									<?php esc_html_e( 'When off, the checkbox disappears from checkout and no fee is added — including for shoppers who had already ticked it earlier in their session.', 'woo-cover-processing-fee' ); ?>
								</span>
							</span>
						</label>
					</div>
				</div>

				<!-- ------------------------------------------- Processor terms -->
				<div class="wcf-card">
					<div class="wcf-card-header">
						<h2><?php esc_html_e( 'Processor terms', 'woo-cover-processing-fee' ); ?></h2>
					</div>
					<div class="wcf-card-body">

						<p class="wcf-help wcf-help-lead">
							<?php esc_html_e( 'Enter the rate your payment processor charges. These two numbers are the only inputs to the fee calculation.', 'woo-cover-processing-fee' ); ?>
						</p>

						<div class="wcf-field-row">
							<div class="wcf-field">
								<label for="wcf-rate"><?php esc_html_e( 'Percentage', 'woo-cover-processing-fee' ); ?></label>
								<div class="wcf-input-affix">
									<input type="number" step="0.001" min="0" max="<?php echo esc_attr( WOO_Cover_Fee_Settings::MAX_RATE ); ?>"
										id="wcf-rate" name="wcf[rate]" value="<?php echo esc_attr( $s['rate'] ); ?>" />
									<span class="wcf-affix wcf-affix-suffix">%</span>
								</div>
							</div>
							<div class="wcf-field">
								<label for="wcf-flat"><?php esc_html_e( 'Flat amount', 'woo-cover-processing-fee' ); ?></label>
								<div class="wcf-input-affix">
									<span class="wcf-affix wcf-affix-prefix"><?php echo esc_html( $currency ); ?></span>
									<input type="number" step="0.01" min="0"
										id="wcf-flat" name="wcf[flat]" value="<?php echo esc_attr( $s['flat'] ); ?>" />
								</div>
							</div>
						</div>

						<div class="wcf-callout">
							<strong><?php esc_html_e( 'How this is calculated', 'woo-cover-processing-fee' ); ?></strong>
							<p>
								<?php
								printf(
									/* translators: 1: base amount, 2: calculated fee, 3: resulting total */
									esc_html__( 'The fee is grossed up so the full amount reaches you after the processor takes its cut of the larger total. On a %1$s order the customer is charged %2$s extra, for a total of %3$s.', 'woo-cover-processing-fee' ),
									'<strong>' . wp_kses_post( wc_price( $example_base ) ) . '</strong>',
									'<strong>' . wp_kses_post( wc_price( $example_fee ) ) . '</strong>',
									'<strong>' . wp_kses_post( wc_price( $example_base + $example_fee ) ) . '</strong>'
								);
								?>
							</p>
						</div>

					</div>
				</div>

				<!-- ------------------------------------------------- Wording -->
				<div class="wcf-card">
					<div class="wcf-card-header">
						<h2><?php esc_html_e( 'Wording', 'woo-cover-processing-fee' ); ?></h2>
					</div>
					<div class="wcf-card-body">

						<div class="wcf-field">
							<label for="wcf-statement"><?php esc_html_e( 'Checkout statement', 'woo-cover-processing-fee' ); ?></label>
							<textarea id="wcf-statement" name="wcf[statement]" rows="4"><?php
								echo esc_textarea( $s['statement'] );
							?></textarea>
							<p class="wcf-help">
								<?php
								printf(
									/* translators: %s: list of allowed HTML tags */
									esc_html__( 'Shown next to the checkbox at checkout. Links and light formatting are allowed: %s. Anything else is stripped when you save.', 'woo-cover-processing-fee' ),
									'<code>&lt;a&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;span&gt;</code>'
								);
								?>
							</p>
							<p class="wcf-help">
								<?php esc_html_e( 'Example: …so 100% of my payment supports our mission. <a href="/processing-fees">Why we ask</a>', 'woo-cover-processing-fee' ); ?>
							</p>
						</div>

						<div class="wcf-field">
							<label for="wcf-fee-label"><?php esc_html_e( 'Order line label', 'woo-cover-processing-fee' ); ?></label>
							<input type="text" id="wcf-fee-label" name="wcf[fee_label]"
								value="<?php echo esc_attr( $s['fee_label'] ); ?>" />
							<p class="wcf-help">
								<?php esc_html_e( 'How the fee appears on the order totals, the receipt, and in the admin. Plain text only.', 'woo-cover-processing-fee' ); ?>
							</p>
						</div>

					</div>
				</div>

				<p class="wcf-actions">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'woo-cover-processing-fee' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
