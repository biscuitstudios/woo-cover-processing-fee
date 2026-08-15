<?php
// Direct web access exits cleanly. Under PHPUnit, tests/bootstrap.php defines
// ABSPATH before this file loads, so the suite runs normally; fetched over
// HTTP, ABSPATH is undefined and this returns nothing instead of a fatal that
// would leak the absolute server path.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;

/**
 * Ported from woo-donation-cover-processing-fee.
 *
 * The Math section is a direct port — processing_fee() is byte-identical in
 * both plugins, so those cases are the same assertions against the same code.
 *
 * The donation plugin's "Amount validation" section has no counterpart here:
 * this plugin has no is_valid_amount(), amounts(), or other_min/other_max —
 * its checkout control is a boolean checkbox, never a client-supplied amount.
 * There is nothing to validate, which is also why this plugin has a smaller
 * attack surface.
 *
 * The Sanitization section is rewritten for this plugin's five-key schema
 * (enabled, statement, fee_label, rate, flat) rather than the donation
 * plugin's nineteen.
 */
class SettingsTest extends TestCase {

	protected function setUp(): void {
		_test_reset_options();
	}

	/* ---------------------------------------------------------------- Math */

	/**
	 * The whole point of the gross-up: after the processor takes its cut of
	 * the larger total, the store nets exactly the original base.
	 *
	 * @dataProvider baseAmounts
	 */
	public function test_gross_up_leaves_store_whole( $base ) {
		$rate = 2.9;
		$flat = 0.30;

		$fee     = WOO_Cover_Fee_Settings::processing_fee( $base, $rate, $flat );
		$charged = $base + $fee;
		$cut     = round( $charged * ( $rate / 100 ) + $flat, 2 );
		$net     = round( $charged - $cut, 2 );

		$this->assertEqualsWithDelta( $base, $net, 0.01, "Store did not net the base on {$base}" );
	}

	public function baseAmounts() {
		return array( array( 10 ), array( 25 ), array( 100 ), array( 250.50 ), array( 1000 ), array( 5000 ) );
	}

	public function test_gross_up_beats_naive_formula() {
		// Naive: 100 * 0.029 + 0.30 = 3.20, which under-recovers by ~0.10.
		$this->assertSame( 3.30, WOO_Cover_Fee_Settings::processing_fee( 100, 2.9, 0.30 ) );
	}

	public function test_zero_base_yields_no_fee() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( 0, 2.9, 0.30 ) );
	}

	public function test_negative_base_yields_no_fee() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( -50, 2.9, 0.30 ) );
	}

	public function test_negative_inputs_are_clamped_not_propagated() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( 100, -5, -1 ) );
	}

	/** A 100% rate must not divide by zero. */
	public function test_extreme_rate_does_not_blow_up() {
		$fee = WOO_Cover_Fee_Settings::processing_fee( 100, 100, 0.30 );
		$this->assertIsFloat( $fee );
		$this->assertTrue( is_finite( $fee ) );
	}

	/** The clamp is MAX_RATE, so 100 and MAX_RATE must agree. */
	public function test_rate_above_maximum_behaves_as_maximum() {
		$this->assertSame(
			WOO_Cover_Fee_Settings::processing_fee( 100, WOO_Cover_Fee_Settings::MAX_RATE, 0.30 ),
			WOO_Cover_Fee_Settings::processing_fee( 100, 999, 0.30 )
		);
	}

	/** A fee is never negative, whatever the inputs. */
	public function test_fee_is_never_negative() {
		foreach ( array( 0.01, 1, 100, 9999 ) as $base ) {
			foreach ( array( 0, 2.9, 50, 90 ) as $rate ) {
				foreach ( array( 0, 0.30, 10 ) as $flat ) {
					$fee = WOO_Cover_Fee_Settings::processing_fee( $base, $rate, $flat );
					$this->assertGreaterThanOrEqual( 0.0, $fee, "Negative fee at base={$base} rate={$rate} flat={$flat}" );
				}
			}
		}
	}

	/** A zero rate and zero flat cost the customer nothing. */
	public function test_no_processor_terms_means_no_fee() {
		$this->assertSame( 0.0, WOO_Cover_Fee_Settings::processing_fee( 100, 0, 0 ) );
	}

	/* -------------------------------------------------------- Sanitization */

	public function test_sanitize_clamps_rate_to_maximum() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'rate' => '9999' ) );
		$this->assertSame( WOO_Cover_Fee_Settings::MAX_RATE, $clean['rate'] );
	}

	public function test_sanitize_rejects_negative_rate() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'rate' => '-5' ) );
		$this->assertSame( 0.0, $clean['rate'] );
	}

	public function test_sanitize_rejects_negative_flat_fee() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'flat' => '-5' ) );
		$this->assertSame( 0.0, $clean['flat'] );
	}

	public function test_sanitize_coerces_non_numeric_terms_to_zero() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'rate' => 'abc', 'flat' => 'xyz' ) );
		$this->assertSame( 0.0, $clean['rate'] );
		$this->assertSame( 0.0, $clean['flat'] );
	}

	/** Currency symbols and thousands separators are tolerated on input. */
	public function test_sanitize_accepts_formatted_currency_input() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'flat' => '$1,250.50' ) );
		$this->assertSame( 1250.50, $clean['flat'] );
	}

	public function test_sanitize_unchecked_box_becomes_zero() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array() );
		$this->assertSame( 0, $clean['enabled'] );
	}

	public function test_sanitize_checked_box_becomes_one() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'enabled' => '1' ) );
		$this->assertSame( 1, $clean['enabled'] );
	}

	/**
	 * A blank statement would leave a bare checkbox with no explanation of what
	 * ticking it does — worse than a generic default.
	 */
	public function test_sanitize_falls_back_when_statement_is_blank() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'statement' => '' ) );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['statement'], $clean['statement'] );
	}

	/** Markup with no text is equally useless next to the checkbox. */
	public function test_sanitize_falls_back_when_statement_is_markup_only() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'statement' => '<strong></strong><br />' ) );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['statement'], $clean['statement'] );
	}

	public function test_sanitize_keeps_a_real_statement() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'statement' => 'Cover our processing costs.' ) );
		$this->assertSame( 'Cover our processing costs.', $clean['statement'] );
	}

	public function test_sanitize_falls_back_when_fee_label_is_blank() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'fee_label' => '' ) );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['fee_label'], $clean['fee_label'] );
	}

	/** The label lands in emails and the admin, so it must be plain text. */
	public function test_sanitize_strips_markup_from_fee_label() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'fee_label' => 'Fee <b>now</b>' ) );
		$this->assertSame( 'Fee now', $clean['fee_label'] );
	}

	/** Nothing outside the five known keys survives a save. */
	public function test_sanitize_discards_unknown_keys() {
		$clean = WOO_Cover_Fee_Settings::sanitize( array( 'evil' => 'payload', 'enabled' => '1' ) );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame(
			array( 'enabled', 'statement', 'fee_label', 'rate', 'flat' ),
			array_keys( $clean )
		);
	}

	public function test_sanitize_tolerates_a_non_array() {
		$clean = WOO_Cover_Fee_Settings::sanitize( 'not an array' );
		$this->assertSame( 0, $clean['enabled'] );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['statement'], $clean['statement'] );
	}

	/* -------------------------------------------------------------- Reading */

	public function test_get_returns_defaults_when_nothing_saved() {
		$this->assertSame( WOO_Cover_Fee_Settings::defaults(), WOO_Cover_Fee_Settings::get() );
	}

	public function test_get_merges_saved_values_over_defaults() {
		_test_set_options( array( 'rate' => 1.5 ) );
		$all = WOO_Cover_Fee_Settings::get();
		$this->assertSame( 1.5, $all['rate'] );
		$this->assertSame( WOO_Cover_Fee_Settings::defaults()['flat'], $all['flat'], 'Unsaved keys fall back' );
	}

	/** A corrupt option must not take the checkout down. */
	public function test_get_survives_a_corrupt_option() {
		$GLOBALS['_test_options'][ WOO_Cover_Fee_Settings::OPTION ] = 'not an array';
		$this->assertSame( WOO_Cover_Fee_Settings::defaults(), WOO_Cover_Fee_Settings::get() );
	}

	public function test_value_reads_a_single_setting() {
		_test_set_options( array( 'rate' => 3.25 ) );
		$this->assertSame( 3.25, WOO_Cover_Fee_Settings::value( 'rate' ) );
	}

	public function test_value_returns_null_for_an_unknown_key() {
		$this->assertNull( WOO_Cover_Fee_Settings::value( 'no_such_key' ) );
	}

	/* ------------------------------------------------------- Allowed markup */

	/**
	 * Contract check on the allowlist itself, not on filtering behaviour —
	 * wp_kses() is a passthrough stub here (see tests/bootstrap.php), so this
	 * suite cannot and does not prove that disallowed tags are removed.
	 */
	public function test_allowed_html_permits_links_and_inline_markup_only() {
		$allowed = WOO_Cover_Fee_Settings::allowed_html();

		foreach ( array( 'a', 'strong', 'b', 'em', 'i', 'br', 'span' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $allowed );
		}
		foreach ( array( 'script', 'iframe', 'style', 'img', 'div', 'p', 'form', 'object' ) as $tag ) {
			$this->assertArrayNotHasKey( $tag, $allowed );
		}
		$this->assertArrayHasKey( 'href', $allowed['a'] );
	}
}
