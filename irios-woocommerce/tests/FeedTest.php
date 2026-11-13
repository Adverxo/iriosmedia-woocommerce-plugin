<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Feed;
use Irios\WooCommerce\Orders;

/** API for the sync service: identifiers leave the store already hashed, and only with consent. */
final class FeedTest extends WordPressTestCase {

	private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wc_format_decimal' )->alias( fn ( $v ) => (string) $v );
		Functions\when( 'WC' )->justReturn( (object) array( 'countries' => new class() {
			public function get_base_country(): string {
				return 'ES';
			}
		} ) );
	}

	private function order( bool $consent ): \WC_Order {
		$order = new class() extends \WC_Order {
			public function __call( string $name, array $args ) {
				$values = array(
					'get_id'                   => 7752,
					'get_order_number'         => '7752',
					'get_status'               => 'processing',
					'get_date_created'         => new \DateTimeImmutable( '2026-11-11T14:48:16Z' ),
					'get_date_modified'        => new \DateTimeImmutable( '2026-11-11T14:50:00Z' ),
					'get_customer_id'          => 5,
					'get_billing_email'        => ' Feed.Test@Example.com ',
					'get_billing_phone'        => '600 123 456',
					'get_billing_country'      => 'ES',
					'get_billing_state'        => 'CA',
					'get_billing_postcode'     => '11001',
					'get_currency'             => 'EUR',
					'get_prices_include_tax'   => true,
					'get_total'                => '49.90',
					'get_total_tax'            => '8.66',
					'get_shipping_total'       => '4.13',
					'get_shipping_tax'         => '0.87',
					'get_discount_total'       => '0',
					'get_discount_tax'         => '0',
					'get_payment_method_title' => 'Tarjeta',
					'get_shipping_methods'     => array(),
				);
				if ( 'get_items' === $name ) {
					return array(
						new \WC_Order_Item_Product(
							array(
								'product_id'   => 12,
								'variation_id' => 34,
								'quantity'     => 2,
								'subtotal'     => '33.06',
								'subtotal_tax' => '6.94',
								'total'        => '33.06',
								'total_tax'    => '6.94',
							)
						),
					);
				}
				return $values[ $name ] ?? null;
			}

			public function get_id() {
				return 7752;
			}

			public function get_customer_id() {
				return 5;
			}
		};
		$order->meta[ Orders::CONSENT_META ] = $consent ? 'yes' : 'no';
		if ( $consent ) {
			$order->meta[ Orders::META ] = self::UUID;
		}
		return $order;
	}

	public function test_an_order_with_consent_has_its_identifiers_hashed_and_no_personal_data(): void {
		$data = Feed::order_data( $this->order( true ) );

		$this->assertTrue( $data['consent'] );
		$this->assertSame(
			array(
				'crm'          => '5',
				'email_sha256' => hash( 'sha256', 'feed.test@example.com' ),
				'phone_sha256' => class_exists( \libphonenumber\PhoneNumberUtil::class ) ? hash( 'sha256', '+34600123456' ) : null,
				'browser_id'   => self::UUID,
			),
			$data['uids']
		);
		$this->assertSame( array( 'country' => 'ES', 'state' => 'CA', 'postcode' => '11001' ), $data['address'] );
		$this->assertSame( 34, $data['line_items'][0]['variation_id'] );
		$this->assertSame( '2026-11-11T14:48:16', $data['date_created_gmt'] );
		$json = wp_json_encode( $data );
		$this->assertStringNotContainsStringIgnoringCase( 'example.com', $json );
		$this->assertStringNotContainsString( '600 123 456', $json );
	}

	public function test_an_order_without_consent_has_no_identifiers(): void {
		$data = Feed::order_data( $this->order( false ) );

		$this->assertFalse( $data['consent'] );
		$this->assertNull( $data['uids'] );
		$this->assertNull( $data['address'] );
		$this->assertNull( $data['customer_id'] );
		$this->assertSame( '49.90', $data['total'] );
	}

	public function test_woocommerce_authenticates_its_api_keys_on_the_feed_routes_only(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$_SERVER['REQUEST_URI'] = '/wp-json/irios/v1/orders?page=2';
		$this->assertTrue( Feed::is_feed_request( false ) );
		$_SERVER['REQUEST_URI'] = '/?rest_route=/irios/v1/customers/5';
		$this->assertTrue( Feed::is_feed_request( false ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/irios/v1/context';
		$this->assertFalse( Feed::is_feed_request( false ) );
		$this->assertTrue( Feed::is_feed_request( true ) );
	}
}
