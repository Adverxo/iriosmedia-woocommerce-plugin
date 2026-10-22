<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Events;
use Irios\WooCommerce\Identity;
use Irios\WooCommerce\Rest;

/** GET /wp-json/irios/v1/context: customer identifiers and pending events, only with consent and never cached. */
final class RestContextTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->given_products( new \WC_Product( 10, 25.5 ) );
		Functions\when( 'wp_get_current_user' )->alias(
			fn () => (object) array(
				'ID'         => $this->user_id,
				'user_email' => 'Ana@Example.com',
			)
		);
	}

	private function context(): array {
		$response = Rest::context();
		$this->assertSame( 'no-store, private, max-age=0', $response->headers['Cache-Control'] ?? null );
		return $response->get_data();
	}

	public function test_logged_in_customer_with_consent(): void {
		$this->given_consent();
		$this->user_id     = 7;
		$this->wc->session = new \WC_Session_Fake( '7' );
		Events::on_add_to_cart( 'line-a', 10, 1, 0 );

		$data = $this->context();

		$this->assertSame(
			array(
				array( 'namespace' => 'CRM', 'uid' => '7' ),
				array( 'namespace' => Identity::EMAIL_NAMESPACE, 'uid' => Identity::email_hash( 'Ana@Example.com' ) ),
			),
			$data['uids']
		);
		$this->assertSame( array( 'add-to-cart' ), array_column( $data['events'], 'id' ) );
		$this->assertSame( array(), $this->pending_for( '7' ), 'delivered events are removed' );
	}

	public function test_anonymous_visitor_gets_events_but_no_uids(): void {
		$this->given_consent();
		Events::on_add_to_cart( 'line-a', 10, 1, 0 );
		\WC_Session_Handler::$cookie = array( 'visitor-1', time() + 3600 );

		$data = $this->context();

		$this->assertSame( array(), $data['uids'] );
		$this->assertCount( 1, $data['events'] );
	}

	/** Without consent nothing is returned (pending events are untouched: there are none, and they are not read). */
	public function test_without_consent_returns_nothing(): void {
		$this->user_id                         = 7;
		$this->transients[ 'irios_ev_' . md5( '7' ) ] = array( array( 'id' => 'add-to-cart' ) );
		$this->given_no_consent();

		$data = $this->context();

		$this->assertSame( array( 'uids' => array(), 'events' => array() ), $data );
		$this->assertCount( 1, $this->pending_for( '7' ) );
	}
}
