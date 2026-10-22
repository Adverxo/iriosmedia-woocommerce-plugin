<?php

namespace Irios\WooCommerce;

/**
 * Stores the browser's Irios identifier (`irs1P` cookie) on the order, in the `_irios_uid` meta, so the sync service
 * sends it with the purchase and Irios can attribute it to its ads and events, even if the buyer is anonymous.
 */
final class Orders {

	public const META = '_irios_uid';

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'capture' ) );                // classic checkout
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'capture' ) );    // block checkout
	}

	/** @param \WC_Order $order */
	public static function capture( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Consent::granted( Settings::load() ) ) {
			return;
		}
		$cookie = isset( $_COOKIE[ Identity::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Identity::COOKIE ] ) ) : null;
		$uid    = Identity::browser_uid( $cookie );
		if ( null === $uid ) {
			return;
		}
		$order->update_meta_data( self::META, $uid );
		$order->save_meta_data();
	}
}
