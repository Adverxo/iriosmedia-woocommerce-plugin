<?php

namespace Irios\WooCommerce;

/**
 * Store events.
 *
 * - Page events (visitor-independent and cacheable): page-view, category-view, search, product-view, checkout-step.
 *   They are printed on the page and sent by the JS.
 * - Cart events (visitor-dependent): add-to-cart, remove-from-cart, cart-view. Generated server-side from WooCommerce
 *   hooks (which carry variation_id and quantity in classic AJAX, blocks and forms), stored per visitor and collected
 *   by the JS through the context endpoint. A single source: no double sends.
 *
 *   They are not stored in the WooCommerce session: it is written in full at the end of each request, and the context
 *   request, concurrent with an "add to cart", would overwrite the cart or the events. They go in a per-customer
 *   transient.
 * - Never purchase: that is sent by the sync service.
 */
final class Events {

	private const TRANSIENT_PREFIX = 'irios_ev_';
	private const TTL = HOUR_IN_SECONDS;
	private const MAX_PENDING = 20;

	/** Lines whose quantity change already produced an event in this request (the classic add of an existing line
	 *  changes the quantity and then fires woocommerce_add_to_cart: a single event). */
	private static array $quantity_handled = array();

	public static function register(): void {
		add_action( 'woocommerce_add_to_cart', array( self::class, 'on_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_cart_item_removed', array( self::class, 'on_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( self::class, 'on_quantity_update' ), 10, 4 );
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ) );
		add_filter( 'woocommerce_redirect_single_search_result', array( self::class, 'on_single_search_result' ), 20 );
	}

	/** Page data for the JS (no visitor data). */
	public static function page_data( Settings $settings ): array {
		$data = array( 'type' => 'page' );

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			$view    = $product instanceof \WC_Product ? Catalog::view_product( $product ) : null;
			if ( $view ) {
				$data = array(
					'type'     => 'product',
					'product'  => Catalog::line( $view ),
					'variable' => $product->is_type( 'variable' ),
				);
			}
		} elseif ( is_search() && 'product' === get_query_var( 'post_type' ) ) {
			// Before category: a search filtered by category (?s=…&product_cat=…) is also is_product_category().
			$query = trim( (string) get_search_query( false ) );
			$data  = array(
				'type'  => 'search',
				'value' => $query,
			);
			$ads   = self::search_ads( $settings, $query );
			if ( $ads ) {
				$data['ads'] = $ads;
			}
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$data = array(
					'type'  => 'category',
					'value' => Catalog::join_path( Catalog::category_names( $term ) ),
				);
			}
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			$data = array(
				'type'  => 'checkout',
				'value' => 'checkout',
			);
		}

		return $data;
	}

	/**
	 * Sponsored Products in search: only with a search term and configured slots (each ad unit with its positions). The
	 * language is that of the page being viewed in WordPress (with WPML or Polylang, that of the store version the
	 * visitor is searching in).
	 */
	private static function search_ads( Settings $settings, string $query ): ?array {
		$language = Settings::bcp47_language( determine_locale() );
		if ( ! $settings->search_ads_enabled() || '' === $query || null === $language ) {
			return null;
		}
		$category = array();
		$slug     = get_query_var( 'product_cat' );
		if ( is_string( $slug ) && '' !== $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$category = Catalog::category_names( $term );
			}
		}
		return array(
			'units'       => $settings->sponsored_units(),
			'searchQuery' => mb_substr( $query, 0, 255 ),
			'language'    => $language,
			'category'    => $category,
			'results'     => self::result_links(),
			// With no results, the search's sponsored products go in their own grid above the WooCommerce notice
			'empty'       => 0 === (int) ( $GLOBALS['wp_query']->found_posts ?? 0 ),
		);
	}

	/**
	 * Products in this page's results (link → id). The JS uses them to locate the results on the page even when the
	 * theme does not use the shop grid (e.g. themes that show search results as blog posts).
	 *
	 * @return array<string,int>
	 */
	private static function result_links(): array {
		global $wp_query;
		$links = array();
		foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
			if ( $post instanceof \WP_Post && 'product' === $post->post_type ) {
				$links[ untrailingslashit( (string) get_permalink( $post ) ) ] = (int) $post->ID;
			}
		}
		return $links;
	}

	// ---- Cart events (server) --------------------------------------------------------------------------------------

	/** @param string $cart_item_key @param int $product_id @param int $quantity @param int $variation_id */
	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id ): void {
		if ( isset( self::$quantity_handled[ $cart_item_key ] ) ) {
			unset( self::$quantity_handled[ $cart_item_key ] );
			return;
		}
		$product = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
		if ( $product instanceof \WC_Product ) {
			self::push( 'add-to-cart', array( Catalog::line( $product, (int) $quantity ) ) );
		}
	}

	/** @param string $cart_item_key @param \WC_Cart $cart */
	public static function on_cart_item_removed( $cart_item_key, $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		if ( ! is_array( $item ) ) {
			return;
		}
		$id      = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) ( $item['product_id'] ?? 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product instanceof \WC_Product ) {
			self::push( 'remove-from-cart', array( Catalog::line( $product, (int) ( $item['quantity'] ?? 1 ) ) ) );
		}
	}

	/**
	 * Quantity change on an existing line: adding something already in the cart (the Store API does not fire
	 * woocommerce_add_to_cart in that case) or changing the quantity in the cart. Positive difference → add-to-cart,
	 * negative → remove-from-cart. Setting 0 removes the line, which woocommerce_cart_item_removed covers.
	 *
	 * @param string $cart_item_key @param int $quantity @param int $old_quantity @param \WC_Cart $cart
	 */
	public static function on_quantity_update( $cart_item_key, $quantity, $old_quantity, $cart ): void {
		$delta   = (int) $quantity - (int) $old_quantity;
		$product = $cart->cart_contents[ $cart_item_key ]['data'] ?? null;
		if ( 0 === $delta || ! $product instanceof \WC_Product ) {
			return;
		}
		self::$quantity_handled[ $cart_item_key ] = true;
		self::push( $delta > 0 ? 'add-to-cart' : 'remove-from-cart', array( Catalog::line( $product, abs( $delta ) ) ) );
	}

	/**
	 * Search with a single result: WooCommerce redirects to the product page and the results page (which sends
	 * `search`) is never shown. Either the page is kept (setting, with ads) or `search` is left pending for the
	 * product page.
	 *
	 * @param bool $redirect
	 */
	public static function on_single_search_result( $redirect ): bool {
		if ( ! $redirect ) {
			return false;
		}
		$settings = Settings::load();
		if ( $settings->keep_single_result() ) {
			return false;
		}
		$query = trim( (string) get_search_query( false ) );
		if ( '' !== $query ) {
			self::push_event( array( 'id' => 'search', 'value' => $query ) );
		}
		return true;
	}

	public static function on_template_redirect(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$lines = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$lines[] = Catalog::line( $product, (int) $item['quantity'] );
			}
		}
		if ( $lines ) {
			self::push( 'cart-view', $lines );
		}
	}

	/** Pending events of the visitor identified by the WooCommerce session cookie; cleared once read. */
	public static function take_pending(): array {
		$customer = self::customer_from_cookie();
		if ( null === $customer ) {
			return array();
		}
		$key     = self::key( $customer );
		$pending = get_transient( $key );
		if ( false === $pending ) {
			return array();
		}
		delete_transient( $key );
		return is_array( $pending ) ? array_values( $pending ) : array();
	}

	/**
	 * Customer id of the WC session, without loading the session or the cart (nothing is saved at the end). Same rule
	 * as WooCommerce: for a logged-in user, their id; otherwise, the one from the session cookie.
	 */
	private static function customer_from_cookie(): ?string {
		if ( is_user_logged_in() ) {
			return (string) get_current_user_id();
		}
		if ( ! class_exists( \WC_Session_Handler::class ) ) {
			return null;
		}
		$cookie = ( new \WC_Session_Handler() )->get_session_cookie();
		return is_array( $cookie ) && ! empty( $cookie[0] ) ? (string) $cookie[0] : null;
	}

	private static function key( string $customer ): string {
		return self::TRANSIENT_PREFIX . md5( $customer );
	}

	private static function push( string $event_id, array $products ): void {
		self::push_event(
			array(
				'id'       => $event_id,
				'products' => $products,
			)
		);
	}

	private static function push_event( array $event ): void {
		if ( ! Consent::granted( Settings::load() ) || ! WC()->session ) {
			return;
		}
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$key       = self::key( (string) WC()->session->get_customer_id() );
		$pending   = get_transient( $key );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = $event;
		set_transient( $key, array_slice( $pending, -self::MAX_PENDING ), self::TTL );
	}
}
