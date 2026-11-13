<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Cards;
use Irios\WooCommerce\Rest;

/**
 * Who may call /context and /cards. They answer for the visitor the request comes from, and /context clears the
 * pending cart events, so a page of another site must not reach them.
 */
final class RestAccessTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_REFERER'] ); // each test is a new request
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://shop.test' );
		Functions\when( 'wp_parse_url' )->alias( fn ( $url, $component ) => parse_url( $url, $component ) );
	}

	public function test_a_request_from_a_page_of_the_store_is_allowed(): void {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$this->assertTrue( Rest::from_the_store() );
	}

	public function test_a_request_from_another_site_is_refused(): void {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
		$this->assertFalse( Rest::from_the_store() );
	}

	public function test_a_request_the_browser_calls_same_site_is_allowed(): void {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-site'; // another subdomain of the store
		$this->assertTrue( Rest::from_the_store() );
	}

	public function test_without_sec_fetch_site_the_referer_decides(): void {
		$_SERVER['HTTP_REFERER'] = 'https://shop.test/product/stick/';
		$this->assertTrue( Rest::from_the_store() );

		$_SERVER['HTTP_REFERER'] = 'https://evil.test/page/';
		$this->assertFalse( Rest::from_the_store() );
	}

	public function test_a_request_with_neither_header_is_allowed(): void {
		// It cannot come from another site's page: curl, a monitor or the sync service.
		$this->assertTrue( Rest::from_the_store() );
	}

	public function test_the_diagnostics_are_only_for_whoever_can_configure_the_store(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->given_products( new \WC_Product( 10, 25.5 ) );

		$body = Rest::cards( new \WP_REST_Request( array( 'ids' => '10', 'layout' => Cards::LAYOUT_DATA, 'irios_debug' => '1' ) ) )->get_data();

		$this->assertArrayNotHasKey( 'irios_debug', $body, 'an anonymous visitor must not get file names or lines' );
	}

	public function test_a_shop_manager_does_get_the_diagnostics(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->given_products( new \WC_Product( 10, 25.5 ) );

		$body = Rest::cards( new \WP_REST_Request( array( 'ids' => '10', 'layout' => Cards::LAYOUT_DATA, 'irios_debug' => '1' ) ) )->get_data();

		$this->assertArrayHasKey( 'irios_debug', $body );
	}
}
