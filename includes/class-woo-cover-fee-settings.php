<?php
/**
 * Settings storage, sanitization, and the fee math.
 *
 * Everything here is static and side-effect free apart from the option
 * read/write, so the calculation helpers can be reasoned about (and unit
 * tested) without a WordPress bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

class WOO_Cover_Fee_Settings {

	const OPTION = 'woo_cover_fee_settings';

	/** Hard ceiling on the configurable rate — guards the gross-up divisor. */
	const MAX_RATE = 90.0;

	/**
	 * Shipped defaults. Also the fallback for any key missing from a saved
	 * option array, so adding a key here is enough to roll it out to sites
	 * that saved their settings before the key existed.
	 */
	public static function defaults() {
		// Seeded from the legacy per-deployment constant so a site that has
		// never opened the settings screen reads exactly as it did in 1.0.0.
		$org = defined( 'WOO_COVER_FEE_ORG_NAME' ) ? WOO_COVER_FEE_ORG_NAME : 'us';

		$statement = sprintf(
			/* translators: %s: organization name, e.g. "Acme Foundation" */
			__( "I'd like to add a small amount to cover payment processing so 100%% of my payment supports %s.", 'woo-cover-processing-fee' ),
			$org
		);

		return array(
			'enabled'   => 1,
			'statement' => $statement,
			'fee_label' => __( 'Cover payment processing (thank you!)', 'woo-cover-processing-fee' ),
			'rate'      => 2.9,
			'flat'      => 0.30,
		);
	}

	/**
	 * Saved settings merged over defaults.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Single setting with default fallback.
	 */
	public static function value( $key ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * HTML permitted in the checkout statement.
	 *
	 * Deliberately tighter than wp_kses_post() — this string renders inside a
	 * <label>, so block-level and media tags have no business here. The same
	 * list is used on save and on output, so what is stored is what renders.
	 */
	public static function allowed_html() {
		return array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
				'rel'    => array(),
				'class'  => array(),
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'br'     => array(),
			'span'   => array( 'class' => array() ),
		);
	}

	/**
	 * Gross-up processing fee.
	 *
	 * Solves for the surcharge that leaves the store whole after the processor
	 * takes its cut of the *grossed-up* total:
	 *
	 *   net   = total - (total * rate) - flat
	 *   total = (base + flat) / (1 - rate)
	 *   fee   = total - base
	 *
	 * At 2.9% + $0.30 on a $100 base this returns 3.30, not 3.20 — the naive
	 * `base * rate + flat` under-recovers because the processor also charges
	 * its percentage on the surcharge itself.
	 *
	 * Pure function: no WP dependencies.
	 *
	 * @param float $base         Amount to be made whole (subtotal + shipping + tax + other fees).
	 * @param float $rate_percent Processor percentage, e.g. 2.9.
	 * @param float $flat         Processor flat fee, e.g. 0.30.
	 * @return float Fee rounded to 2dp, never negative.
	 */
	public static function processing_fee( $base, $rate_percent, $flat ) {
		$base = (float) $base;
		$flat = max( 0.0, (float) $flat );
		$rate = (float) $rate_percent;

		if ( $base <= 0 ) {
			return 0.0;
		}

		// Clamp before dividing — a rate of 100 would blow up the divisor.
		$rate = min( max( $rate, 0.0 ), self::MAX_RATE ) / 100;

		$total = ( $base + $flat ) / ( 1 - $rate );
		$fee   = round( $total - $base, 2 );

		return $fee > 0 ? $fee : 0.0;
	}

	/**
	 * Sanitize a raw $_POST settings array.
	 *
	 * Every value is coerced to its expected type and range here; callers may
	 * hand this untrusted input directly.
	 */
	public static function sanitize( $raw ) {
		$defaults = self::defaults();
		$clean    = array();

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		// --- Toggle: absent checkbox means unchecked ---
		$clean['enabled'] = empty( $raw['enabled'] ) ? 0 : 1;

		// --- Statement: links and light inline markup allowed, scripts not ---
		$statement = isset( $raw['statement'] )
			? wp_kses( wp_unslash( $raw['statement'] ), self::allowed_html() )
			: '';
		// An empty-after-stripping value means the admin cleared the field or
		// tried to save markup-only content; fall back rather than ship a
		// checkbox with no explanation next to it.
		$clean['statement'] = ( '' === trim( wp_strip_all_tags( $statement ) ) )
			? $defaults['statement']
			: $statement;

		// --- Order line label: plain text, no markup (renders in emails/admin) ---
		$label              = isset( $raw['fee_label'] ) ? sanitize_text_field( wp_unslash( $raw['fee_label'] ) ) : '';
		$clean['fee_label'] = ( '' === $label ) ? $defaults['fee_label'] : $label;

		// --- Processor terms ---
		$rate          = isset( $raw['rate'] ) ? (float) wc_format_decimal( wp_unslash( $raw['rate'] ) ) : 0.0;
		$clean['rate'] = min( max( round( $rate, 3 ), 0.0 ), self::MAX_RATE );

		$flat          = isset( $raw['flat'] ) ? (float) wc_format_decimal( wp_unslash( $raw['flat'] ) ) : 0.0;
		$clean['flat'] = max( 0.0, round( $flat, 2 ) );

		return $clean;
	}
}
