<?php

namespace Irios\WooCommerce;

/**
 * Where in a theme's HTML each thing is: the results grid, the product title, the sidebar, the live search dropdown…
 * Ads are placed by finding these, and a theme that names them differently needs them changed.
 *
 * They are served to the storefront JS so that a theme can be adapted from its own functions.php, without waiting for
 * a version of the plugin:
 *
 *     add_filter( 'irios_woocommerce_selectors', function ( array $selectors ) {
 *         $selectors['grid'] = '.mi-rejilla';
 *         return $selectors;
 *     } );
 *
 * The JS keeps the same defaults, so a store that saved its settings before this still works.
 */
final class Selectors {

	/**
	 * Defaults, tested against the themes in the compatibility matrix.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULTS = array(
		// Results and category grid: classic WooCommerce and block themes.
		'grid'            => '.products, .wc-block-product-template',
		// Title inside a result, to copy the ad's into the cloned card.
		'title'           => '.woocommerce-loop-product__title, .product-title, .post-title, .entry-title, .wp-block-post-title, h2, h3',
		// Sorting, result count and pagination of a listing: a placement goes after them, not between them.
		'listingControls' => '.storefront-sorting, .woocommerce-ordering, .woocommerce-result-count, .woocommerce-pagination, nav.pagination, .pagi-nav',
		// Product page tabs (description, additional information…).
		'productTabs'     => '.woocommerce-tabs, .detail-tabs, .wp-block-woocommerce-product-details',
		// Related and upsell products of the product page.
		'related'         => 'section.related.products, .related.products, section.upsells.products, .upsells.products, .wp-block-woocommerce-related-products',
		// Shop sidebar.
		'sidebar'         => '#secondary, aside.widget-area, .widget-area, aside.sidebar, #sidebar, .shop-sidebar',
		// Product lists that are not results and must never get ads (the mini cart, above all).
		'notResults'      => '[class*="mini-cart"], [class*="minicart"], .widget_shopping_cart, .woocommerce-mini-cart, .cart_list',
		// Live search dropdowns the theme renders outside the search form.
		'liveDropdown'    => '[class*="suggestion"], [class*="autocomplete"], [class*="search-result"], [class*="live-search"], [class*="ajax-search"]',
	);

	/** @return array<string,string> */
	public static function all(): array {
		$selectors = apply_filters( 'irios_woocommerce_selectors', self::DEFAULTS );
		$out       = array();
		foreach ( self::DEFAULTS as $name => $default ) {
			$value        = is_array( $selectors ) && isset( $selectors[ $name ] ) ? $selectors[ $name ] : $default;
			$out[ $name ] = is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $default;
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function defaults(): array {
		return self::DEFAULTS;
	}
}
