<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Identity;
use Irios\WooCommerce\Orders;

/** Browser identifier saved on the order (purchase attribution), only with consent. */
final class OrdersTest extends WordPressTestCase {

	private const UUID = '0F8FAD5B-D9CB-469F-A165-70867728950E';

	public function test_saves_the_browser_uid_with_consent(): void {
		$this->given_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;
		$order                       = new \WC_Order();

		Orders::capture( $order );

		$this->assertSame( array( Orders::META => strtolower( self::UUID ) ), $order->meta );
		$this->assertSame( 1, $order->saves );
	}

	public function test_saves_nothing_without_consent(): void {
		$this->given_no_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;
		$order                       = new \WC_Order();

		Orders::capture( $order );

		$this->assertSame( array(), $order->meta );
		$this->assertSame( 0, $order->saves );
	}

	public function test_saves_nothing_without_a_valid_cookie(): void {
		$this->given_consent();
		$order = new \WC_Order();
		Orders::capture( $order );

		$_COOKIE[ Identity::COOKIE ] = 'fc:not-a-uuid';
		Orders::capture( $order );

		$this->assertSame( array(), $order->meta );
		$this->assertSame( 0, $order->saves );
	}

	public function test_ignores_anything_that_is_not_an_order(): void {
		$this->given_consent();
		$_COOKIE[ Identity::COOKIE ] = 'fc:' . self::UUID;

		Orders::capture( null );
		Orders::capture( 123 );

		$this->addToAssertionCount( 1 ); // no errors
	}
}
