<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Feed;

/**
 * The registered customers the sync service reads: those with an order placed with consent. The list is asked of the
 * database one page at a time — loading every order of the store to read its customer would run out of memory on a
 * store with a few years of history.
 */
final class FeedCustomersTest extends WordPressTestCase {

	private \WPDB_Fake $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new \WPDB_Fake();
		$GLOBALS['wpdb'] = $this->wpdb;
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false; // store with the older order storage
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function sql(): string {
		return implode( "\n", $this->wpdb->queries );
	}

	public function test_only_the_page_asked_for_is_read_from_the_database(): void {
		$this->wpdb->var = 120;
		$this->wpdb->col = array( '7', '9' );

		$response = Feed::customers( new \WP_REST_Request( array( 'page' => 3, 'per_page' => 2 ) ) );

		$this->assertStringContainsString( 'LIMIT 2 OFFSET 4', $this->sql() );
		$this->assertSame( '120', $response->headers['X-WP-Total'] );
		$this->assertSame( '60', $response->headers['X-WP-TotalPages'] );
	}

	public function test_only_customers_with_consent_and_a_paid_order_count(): void {
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 50 ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString( "'_irios_consent'", $sql );
		$this->assertStringContainsString( "'yes'", $sql );
		$this->assertStringContainsString( "'wc-processing','wc-completed'", $sql, 'a cancelled order does not make a customer' );
		$this->assertStringContainsString( 'AS UNSIGNED) > 0', $sql, 'a guest has no customer to send' );
	}

	public function test_a_customer_without_a_consented_order_is_not_found(): void {
		$this->wpdb->var = null;

		$result = Feed::customer( new \WP_REST_Request( array( 'id' => 7 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'customer = 7', $this->sql() );
		$this->assertStringContainsString( 'LIMIT 1', $this->sql(), 'one row is enough: the list is not built' );
	}

	public function test_an_id_that_is_not_a_customer_is_refused_without_touching_the_database(): void {
		$result = Feed::customer( new \WP_REST_Request( array( 'id' => 0 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_with_the_newer_order_storage_its_own_tables_are_read(): void {
		\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;
		$this->wpdb->var = 0;
		$this->wpdb->col = array();

		Feed::customers( new \WP_REST_Request( array( 'page' => 1, 'per_page' => 10 ) ) );

		$sql = $this->sql();
		$this->assertStringContainsString( 'wp_wc_orders', $sql );
		$this->assertStringNotContainsString( 'wp_posts', $sql );
	}
}
