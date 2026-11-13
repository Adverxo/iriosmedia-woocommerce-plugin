<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Feed;

/**
 * Who may read the orders and the customers of the store, and what the sync service actually asks WooCommerce for.
 * This is the boundary that keeps names, emails, phones and addresses inside the store, so it is also the one that
 * must not answer anybody else.
 */
final class FeedAccessTest extends WordPressTestCase {

	/** @var array<string,bool> capabilities of the user making the request */
	private array $can = array();
	/** @var array<string,mixed> arguments the code passed to wc_get_orders */
	private array $asked = array();

	protected function setUp(): void {
		parent::setUp();
		$this->can = array();
		$this->asked = array();
		Functions\when( 'current_user_can' )->alias( fn ( string $cap ) => $this->can[ $cap ] ?? false );
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) {
				$this->asked = $args;
				return (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 );
			}
		);
	}

	public function test_a_shop_manager_may_read_the_feed(): void {
		$this->can = array( 'manage_woocommerce' => true );
		$this->assertTrue( Feed::allowed() );
	}

	public function test_a_user_who_can_read_the_private_orders_may_read_the_feed(): void {
		$this->can = array( 'read_private_shop_orders' => true );
		$this->assertTrue( Feed::allowed() );
	}

	public function test_nobody_else_may_read_the_feed(): void {
		$this->assertFalse( Feed::allowed(), 'an anonymous visitor' );

		$this->can = array( 'read' => true, 'edit_posts' => true, 'manage_options' => false );
		$this->assertFalse( Feed::allowed(), 'a subscriber or an author' );
	}

	public function test_only_paid_orders_are_asked_for_whatever_the_caller_sends(): void {
		Feed::orders( new \WP_REST_Request( array( 'status' => 'completed,cancelled,refunded', 'page' => 1, 'per_page' => 50 ) ) );
		$this->assertSame( array( 'completed' ), $this->asked['status'], 'the statuses it does not accept are dropped' );

		Feed::orders( new \WP_REST_Request( array( 'status' => 'cancelled', 'page' => 1, 'per_page' => 50 ) ) );
		$this->assertSame( array( 'processing', 'completed' ), $this->asked['status'], 'no valid status: the paid ones' );
	}

	public function test_the_dates_are_read_as_gmt_and_turned_into_a_range(): void {
		Feed::orders(
			new \WP_REST_Request(
				array(
					'status'         => 'completed',
					'page'           => 2,
					'per_page'       => 10,
					'modified_after' => '2026-11-01T00:00:00',
					'created_after'  => '2026-01-01T00:00:00',
					'created_before' => '2026-02-01T00:00:00',
				)
			)
		);

		$this->assertSame( 10, $this->asked['limit'] );
		$this->assertSame( 2, $this->asked['paged'] );
		$this->assertSame( 'date_modified', $this->asked['orderby'] );
		$this->assertSame( '>' . strtotime( '2026-11-01T00:00:00Z' ), $this->asked['date_modified'] );
		$this->assertSame(
			strtotime( '2026-01-01T00:00:00Z' ) . '...' . strtotime( '2026-02-01T00:00:00Z' ),
			$this->asked['date_created'],
			'a date without a zone is GMT, as WooCommerce stores them'
		);
	}

	public function test_a_date_that_is_not_a_date_is_ignored_instead_of_filtering_by_nothing(): void {
		Feed::orders( new \WP_REST_Request( array( 'status' => 'completed', 'page' => 1, 'per_page' => 50, 'created_after' => 'ayer' ) ) );

		$this->assertArrayNotHasKey( 'date_created', $this->asked );
	}

	public function test_the_answer_says_how_many_there_are_and_is_never_cached(): void {
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'orders' => array(), 'total' => 7, 'max_num_pages' => 4 ) );

		$response = Feed::orders( new \WP_REST_Request( array( 'status' => 'completed', 'page' => 1, 'per_page' => 2 ) ) );

		$this->assertSame( '7', $response->headers['X-WP-Total'] );
		$this->assertSame( '4', $response->headers['X-WP-TotalPages'] );
		$this->assertSame( 'no-store, private, max-age=0', $response->headers['Cache-Control'] );
	}
}
