<?php
/**
 * Plugin Name:       Woo Cover Processing Fee
 * Plugin URI:        https://github.com/biscuitstudios/woo-cover-processing-fee
 * Description:       Adds a voluntary checkbox at checkout ("cover payment processing") that grosses up a configurable processor rate onto the cart total. Classic (shortcode) checkout only.
 * Version:           1.3.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-cover-processing-fee
 * Update URI:        https://github.com/biscuitstudios/woo-cover-processing-fee
 */

if ( ! defined( 'ABSPATH' ) ) exit; // no direct access

/**
 * Per-deployment org name, used only to seed the default checkout statement.
 *
 * As of 1.1.0 the whole statement is editable under WooCommerce → Processing
 * Fee, so this only matters on a site that has never opened the settings
 * screen. Once settings are saved it has no further effect.
 *
 * Defaults to the site's own name so the plugin carries no client-specific
 * copy. Override in wp-config.php when the legal or public-facing name differs
 * from the WordPress site title:
 *
 *   define( 'WOO_COVER_FEE_ORG_NAME', 'Acme Foundation' );
 */
if ( ! defined( 'WOO_COVER_FEE_ORG_NAME' ) ) {
	define( 'WOO_COVER_FEE_ORG_NAME', get_bloginfo( 'name' ) );
}

/**
 * Sibling-plugin note.
 *
 * woo-cover-processing-fee and woo-donation-cover-processing-fee share the
 * WOO_COVER_FEE_* constant prefix and the WOO_Cover_Fee_Admin /
 * WOO_Cover_Fee_Settings class names. The class declarations are unguarded, so
 * activating BOTH on one site is a fatal error ("Cannot declare class ...").
 *
 * That is accepted by design: the two are variants of the same plugin and are
 * never intended to run together — pick one per site. Only
 * WOO_DONATION_COVER_FEE_VERSION was renamed apart, so the two build artifacts
 * can be told apart by version. If they ever DO need to coexist, both the
 * remaining constants and the class names must be namespaced first.
 */
define( 'WOO_COVER_FEE_VERSION', '1.3.0' );
define( 'WOO_COVER_FEE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_COVER_FEE_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_COVER_FEE_BASENAME', plugin_basename( __FILE__ ) );

require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-fee-settings.php';
require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-processing-fee.php';
require_once WOO_COVER_FEE_PATH . 'includes/class-woo-cover-fee-admin.php';

/**
 * HPOS compatibility. This plugin only adds a cart fee and never touches the
 * orders tables directly, so it works either way — declaring it stops
 * WooCommerce listing the plugin as incompatible on the HPOS screen.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

/**
 * Boot the plugin once WooCommerce is confirmed active.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Woo Cover Processing Fee requires WooCommerce to be active.', 'woo-cover-processing-fee' ) .
				'</p></div>';
		} );
		return;
	}

	new WOO_Cover_Processing_Fee();

	if ( is_admin() ) {
		( new WOO_Cover_Fee_Admin() )->init();
	}
} );
