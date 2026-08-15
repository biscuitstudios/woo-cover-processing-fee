<?php
/**
 * Front-end: the checkout checkbox and the cart fee it drives.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Processing_Fee {

	const SESSION_KEY = 'woo_cover_fee';

	/** Nonce action for the front-end toggle endpoint. */
	const NONCE = 'woo_cover_fee_toggle';

	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_fee' ), 20, 1 );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkbox' ), 50 );
		add_action( 'wp_ajax_woo_cover_fee', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_woo_cover_fee', array( $this, 'ajax_toggle' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Guard for the public toggle endpoint.
	 *
	 * Nonce only — deliberately no capability check. This endpoint is reachable
	 * by logged-out shoppers by design, and the only thing it can touch is the
	 * caller's own WooCommerce session. The nonce is what stops a third-party
	 * page from silently flipping a shopper's fee state (CSRF).
	 */
	private function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Is the offer switched on?
	 *
	 * Checked in every entry point rather than at hook-registration time, so
	 * flipping the setting takes effect immediately — including for shoppers
	 * mid-checkout whose session still carries an opt-in from before.
	 */
	private function is_enabled() {
		return (bool) WOO_Cover_Fee_Settings::value( 'enabled' );
	}

	/**
	 * The amount the processor will actually charge its percentage against.
	 *
	 * Subtotal + shipping + their taxes + any fees added by earlier callbacks
	 * (a donation add-on, for example). WooCommerce clears and rebuilds fees on
	 * every calculation pass, so get_fees() here returns only fees registered
	 * before this one — never our own from a previous pass.
	 */
	private function fee_base( $cart ) {
		$base = $cart->get_subtotal()
			+ $cart->get_shipping_total()
			+ $cart->get_subtotal_tax()
			+ $cart->get_shipping_tax();

		foreach ( $cart->get_fees() as $fee ) {
			$base += (float) $fee->amount;
		}

		/**
		 * Filter the amount the processing fee is calculated against.
		 *
		 * @param float   $base
		 * @param WC_Cart $cart
		 */
		return (float) apply_filters( 'woo_cover_fee_base', $base, $cart );
	}

	/**
	 * Add the fee to the cart if the session flag is set.
	 */
	public function add_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
		if ( ! $this->is_enabled() ) return;
		if ( ! WC()->session ) return;
		if ( ! WC()->session->get( self::SESSION_KEY ) ) return;

		$settings = WOO_Cover_Fee_Settings::get();

		$fee = WOO_Cover_Fee_Settings::processing_fee(
			$this->fee_base( $cart ),
			$settings['rate'],
			$settings['flat']
		);

		if ( $fee <= 0 ) return;

		// false = non-taxable voluntary contribution. Confirm with the client's
		// accountant if this ever needs to change.
		$cart->add_fee( $settings['fee_label'], $fee, false );
	}

	/**
	 * Render the checkbox on the checkout page, above the payment section.
	 * The wrapper div is what checkout.js repositions and style.css styles.
	 */
	public function render_checkbox() {
		if ( ! $this->is_enabled() ) return;
		if ( ! WC()->session ) return;

		$checked   = (bool) WC()->session->get( self::SESSION_KEY );
		$statement = WOO_Cover_Fee_Settings::value( 'statement' );

		/**
		 * Filter the full checkbox statement. Use this if a site needs
		 * phrasing the settings screen can't express.
		 *
		 * @param string $statement
		 */
		$statement = apply_filters( 'woo_cover_fee_label', $statement );
		?>
		<div id="woo-cover-fee-wrapper" class="woo-cover-fee-box">
			<p class="form-row woo-cover-fee-row">
				<label for="woo_cover_fee">
					<input type="checkbox" id="woo_cover_fee" name="woo_cover_fee" <?php checked( $checked ); ?> />
					<span class="woo-cover-fee-statement"><?php
						// Links are permitted here. Per the HTML spec a click on
						// interactive content (an <a>) inside a <label> does not
						// activate the labelled control, so links can be nested
						// without hijacking the checkbox.
						echo wp_kses( $statement, WOO_Cover_Fee_Settings::allowed_html() );
					?></span>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: store the checkbox state in the WC session.
	 */
	public function ajax_toggle() {
		$this->guard();

		if ( ! $this->is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'This option is not available.', 'woo-cover-processing-fee' ) ), 403 );
		}

		if ( ! WC()->session ) {
			wp_send_json_error( array( 'message' => __( 'No active session.', 'woo-cover-processing-fee' ) ), 400 );
		}

		$raw     = isset( $_POST['checked'] ) ? sanitize_text_field( wp_unslash( $_POST['checked'] ) ) : '';
		$checked = ( 'true' === $raw || '1' === $raw );

		WC()->session->set( self::SESSION_KEY, $checked );

		wp_send_json_success( array( 'checked' => $checked ) );
	}

	/**
	 * Enqueue the checkout CSS/JS only on the checkout page.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
		if ( ! $this->is_enabled() ) return;

		wp_enqueue_style(
			'woo-cover-processing-fee',
			WOO_COVER_FEE_URL . 'assets/css/style.css',
			array(),
			WOO_COVER_FEE_VERSION
		);

		wp_enqueue_script(
			'woo-cover-processing-fee',
			WOO_COVER_FEE_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			WOO_COVER_FEE_VERSION,
			true
		);

		wp_localize_script( 'woo-cover-processing-fee', 'wooCoverFee', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
		) );
	}
}
