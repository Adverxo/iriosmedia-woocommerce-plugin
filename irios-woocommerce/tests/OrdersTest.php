<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Identity;
use Irios\WooCommerce\Orders;

/** Consent and, only with consent, the browser identifier saved on the order (purchase attribution). */
final class OrdersTest extends WordPressTestCase {

	private const UUID = '0F8FAD5B-D9CB-469F-A165-70867728950E';

	protected function setUp(): void {
		parent::setUp();
		$this->settings['js_domain'] = 'shop.irios.test';
		$this->settings['store_id']  = '42';
	}

	public function test_saves_nothing_until_irios_media_is_configured(): void {
		$this->given_consent();
		$this->settings['store_id']  = '';
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;
		$order                       = new \WC_Order();

		Orders::capture( $order );

		$this->assertSame( array(), $order->meta );
		$this->assertSame( 0, $order->saves );
	}

	public function test_saves_the_browser_uid_with_consent(): void {
		$this->given_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;
		$order                       = new \WC_Order();

		Orders::capture( $order );

		$this->assertSame( array( Orders::CONSENT_META => 'yes', Orders::META => strtolower( self::UUID ) ), $order->meta );
		$this->assertSame( 1, $order->saves );
		$this->assertTrue( Orders::consented( $order ) );
	}

	public function test_without_consent_saves_only_the_answer(): void {
		$this->given_no_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;
		$order                       = new \WC_Order();

		Orders::capture( $order );

		$this->assertSame( array( Orders::CONSENT_META => 'no' ), $order->meta );
		$this->assertFalse( Orders::consented( $order ) );
	}

	public function test_with_consent_and_no_valid_cookie_saves_only_the_answer(): void {
		$this->given_consent();
		$order = new \WC_Order();
		Orders::capture( $order );
		$this->assertSame( array( Orders::CONSENT_META => 'yes' ), $order->meta );

		$_COOKIE[ Identity::COOKIE ] = 'fc:not-a-uuid';
		Orders::capture( $order );

		$this->assertSame( array( Orders::CONSENT_META => 'yes' ), $order->meta );
	}

	public function test_orders_placed_before_the_consent_answer_count_as_without_consent(): void {
		$this->assertFalse( Orders::consented( new \WC_Order() ) );
	}

	public function test_ignores_anything_that_is_not_an_order(): void {
		$this->given_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;

		Orders::capture( null );
		Orders::capture( 123 );

		$this->addToAssertionCount( 1 ); // no errors
	}
}
