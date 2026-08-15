<?php
/**
 * Test bootstrap.
 *
 * No WordPress load — the settings class is deliberately structured so its
 * calculation and validation helpers depend only on the handful of WP
 * functions stubbed below.
 *
 * Ported from woo-donation-cover-processing-fee, which shares this plugin's
 * settings class lineage. The differences are noted where they occur.
 */

define( 'ABSPATH', true );

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

/**
 * Not present in the donation plugin's bootstrap, because its defaults() uses
 * plain strings. This plugin's defaults() builds the statement with __() and
 * sprintf(), so the stub is required or defaults() fatals.
 */
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

/**
 * Passthrough stub — NOT a reimplementation of wp_kses().
 *
 * The allowlist argument is ignored, so tag filtering of the statement field is
 * NOT covered by this suite; it relies entirely on the real WordPress function
 * at runtime. Do not read a passing suite as evidence that `statement` is
 * sanitized. What the suite does cover is the surrounding fallback logic.
 */
if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( $str, $allowed = array(), $protocols = array() ) {
		return (string) $str;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) {
		return strip_tags( (string) $str );
	}
}

if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $number, $dp = false ) {
		$number = (float) str_replace( array( ',', '$' ), '', (string) $number );
		return ( false !== $dp ) ? number_format( $number, $dp, '.', '' ) : (string) $number;
	}
}

/**
 * WOO_COVER_FEE_ORG_NAME is deliberately left undefined so defaults() takes its
 * 'us' fallback. In production the plugin bootstrap defines it from
 * get_bloginfo('name'); pinning it here keeps the expected statement
 * deterministic and independent of any site title.
 */

/** Replace the stored settings for a single test. */
function _test_set_options( array $settings ) {
	$GLOBALS['_test_options'][ WOO_Cover_Fee_Settings::OPTION ] = $settings;
}

function _test_reset_options() {
	$GLOBALS['_test_options'] = array();
}

require_once __DIR__ . '/../includes/class-woo-cover-fee-settings.php';

_test_reset_options();
