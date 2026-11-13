<?php

namespace Irios\WooCommerce;

/**
 * At checkout, stores on the order whether there was IRIOS Media consent (`_irios_consent`: yes/no) and, only with
 * consent, the browser's IRIOS identifier (`irs1P` cookie, `_irios_uid`), so the sync service sends the purchase (only
 * with consent) and IRIOS Media can attribute it to its ads and events, even if the buyer is anonymous. Order meta is
 * WooCommerce's own extension mechanism (also with HPOS): no table is altered.
 */
final class Orders {

	public const META         = '_irios_uid';
	public const CONSENT_META = '_irios_consent';

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'capture' ) );                // classic checkout
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'capture' ) );    // block checkout
	}

	/** @param \WC_Order $order */
	public static function capture( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$settings = Settings::load();
		if ( ! $settings->is_configured() ) {
			return;
		}
		$granted = Consent::granted( $settings );
		$order->update_meta_data( self::CONSENT_META, $granted ? 'yes' : 'no' );
		$cookie = isset( $_COOKIE[ Identity::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ Identity::COOKIE ] ) ) : null;
		$uid    = $granted ? Identity::browser_uid( $cookie ) : null;
		if ( null !== $uid ) {
			$order->update_meta_data( self::META, $uid );
		}
		$order->save_meta_data();
	}

	/** Whether the order was placed with IRIOS Media consent (orders placed before 1.1.0 have no answer: no). */
	public static function consented( \WC_Order $order ): bool {
		return 'yes' === $order->get_meta( self::CONSENT_META );
	}
}
