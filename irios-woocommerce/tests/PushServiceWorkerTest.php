<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Push;

/**
 * The service worker the browser registers for push notifications. Its path and its scope have to match exactly what
 * the push ad unit points to in IRIOS Media, also in a store installed in a subdirectory.
 */
final class PushServiceWorkerTest extends WordPressTestCase {

	private function given_store_at( string $home ): void {
		Functions\when( 'home_url' )->alias( fn ( string $path = '' ) => rtrim( $home, '/' ) . $path );
		Functions\when( 'wp_parse_url' )->alias( fn ( $url, $component ) => parse_url( $url, $component ) );
		Functions\when( 'trailingslashit' )->alias( fn ( string $s ) => rtrim( $s, '/' ) . '/' );
	}

	public function test_at_the_root_of_the_domain_the_scope_is_the_whole_store(): void {
		$this->given_store_at( 'https://shop.test' );

		$this->assertSame( '/', Push::scope() );
		$this->assertSame( 'https://shop.test/irios-sw.js', Push::url() );
	}

	public function test_a_store_in_a_subdirectory_keeps_its_own_scope(): void {
		$this->given_store_at( 'https://example.test/shop' );

		$this->assertSame( '/shop/', Push::scope() );
		$this->assertSame( 'https://example.test/shop/irios-sw.js', Push::url() );
	}

	public function test_only_its_own_path_is_served(): void {
		$this->given_store_at( 'https://shop.test' );

		$this->assertTrue( Push::is_service_worker( '/irios-sw.js' ) );
		$this->assertTrue( Push::is_service_worker( '/irios-sw.js?ver=2' ), 'the query string does not count' );
		$this->assertFalse( Push::is_service_worker( '/' ) );
		$this->assertFalse( Push::is_service_worker( '/sw.js' ) );
		$this->assertFalse( Push::is_service_worker( '/x/irios-sw.js' ) );
		$this->assertFalse( Push::is_service_worker( '/irios-sw.js.map' ) );
	}

	public function test_in_a_subdirectory_the_path_of_the_root_is_not_served(): void {
		$this->given_store_at( 'https://example.test/shop' );

		$this->assertTrue( Push::is_service_worker( '/shop/irios-sw.js' ) );
		$this->assertFalse( Push::is_service_worker( '/irios-sw.js' ) );
	}

	public function test_the_script_carries_the_store_name_and_icon_for_the_notification(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Hockey &amp; Market' );
		Functions\when( 'wp_specialchars_decode' )->alias( fn ( string $s ) => html_entity_decode( $s, ENT_QUOTES ) );
		Functions\when( 'wp_json_encode' )->alias( fn ( $v ) => json_encode( $v ) );

		$script = Push::script();

		$this->assertStringStartsWith( 'self.IRIOS_DEFAULTS = {', $script );
		$this->assertStringContainsString( '"title":"Hockey & Market"', $script );
		$this->assertStringContainsString( 'addEventListener', $script, 'the service worker itself comes after' );
	}
}
