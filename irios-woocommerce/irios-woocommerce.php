<?php
/**
 * Plugin Name:          Irios for WooCommerce
 * Description:          Irios Media browsing and product events, visitor identity, Sponsored Products and banners for WooCommerce stores.
 * Version:              0.0.0-dev
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.5
 * Author:               Kimia / Adverxo
 * Update URI:           https://github.com/Adverxo/iriosmedia-woocommerce-plugin
 * Text Domain:          irios-woocommerce
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

const IRIOS_WC_VERSION = '0.0.0-dev'; // bin/package.sh sets the repo version (git tags)
const IRIOS_WC_FILE    = __FILE__;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Irios\\WooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// Production dependencies (libphonenumber to normalise phone numbers), bundled in the zip by bin/package.sh.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Declared compatibility: HPOS order storage (we store a meta on the order) and block cart/checkout.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IRIOS_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', IRIOS_WC_FILE, true );
		}
	}
);

// English strings; translations bundled in languages/ (es_ES)
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'irios-woocommerce', false, dirname( plugin_basename( IRIOS_WC_FILE ) ) . '/languages' );
	}
);
add_action( 'plugins_loaded', array( \Irios\WooCommerce\Plugin::class, 'boot' ) );
