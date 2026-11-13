<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Assets;
use Irios\WooCommerce\Settings;

/**
 * What the store prints in the page for the storefront JS. It travels in HTML that may be cached, so it carries only
 * settings of the store, never anything about the visitor: that one comes from /context.
 */
final class AssetsConfigTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_url' )->alias( fn ( string $path ) => 'https://shop.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'un-nonce' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
		Functions\when( 'wp_get_theme' )->alias( fn ( string $slug = '' ) => new \WP_Theme( '' === $slug ? 'Storefront' : ucfirst( $slug ) ) );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( fn ( string $p = '' ) => 'https://shop.test' . $p );
		Functions\when( 'wp_parse_url' )->alias( fn ( $url, $c ) => parse_url( $url, $c ) );
		Functions\when( 'trailingslashit' )->alias( fn ( string $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'determine_locale' )->justReturn( 'es_ES' );
		Functions\when( 'get_stylesheet' )->justReturn( 'storefront' );
		Functions\when( 'get_template' )->justReturn( 'storefront' );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://shop.test/shop/' );
	}

	private function config( array $settings ): array {
		return Assets::config( new Settings( $settings ) );
	}

	public function test_it_carries_the_store_settings_and_the_endpoints(): void {
		$config = $this->config(
			array(
				'js_domain'       => 'shop.iriosmedia.online',
				'store_id'        => '33',
				'consent_mode'    => Settings::CONSENT_ASESOR_COOKIES,
				'sponsored_label' => 'Patrocinado',
			)
		);

		$this->assertSame( 'shop.iriosmedia.online', $config['jsDomain'] );
		$this->assertSame( '33', $config['storeId'] );
		$this->assertSame( Settings::CONSENT_ASESOR_COOKIES, $config['consentMode'] );
		$this->assertSame( 'Patrocinado', $config['sponsoredLabel'] );
		$this->assertSame( 'https://shop.test/wp-json/irios/v1/context', $config['contextUrl'] );
		$this->assertSame( 'https://shop.test/wp-json/irios/v1/cards', $config['cardsUrl'] );
	}

	public function test_nothing_about_the_visitor_travels_in_the_page(): void {
		$config = $this->config( array( 'js_domain' => 'shop.iriosmedia.online', 'store_id' => '33' ) );

		$printed = (string) json_encode( $config );
		foreach ( array( 'uid', 'irs1P', 'consentGranted', 'events', 'customer' ) as $visitor ) {
			$this->assertStringNotContainsString( $visitor, $printed, "«{$visitor}» cannot travel in a cached page" );
		}
	}

	public function test_the_about_this_ad_texts_cover_every_targeting_reason(): void {
		$config = $this->config( array( 'js_domain' => 'shop.iriosmedia.online', 'store_id' => '33' ) );

		$this->assertArrayHasKey( 'PAGE_TYPE', $config['adInfo']['reasons'] );
		$this->assertArrayHasKey( 'SEARCH', $config['adInfo']['reasons'] );
		$this->assertNotSame( '', $config['adInfo']['title'] );
	}

	public function test_without_push_configured_nothing_is_asked(): void {
		$config = $this->config( array( 'js_domain' => 'shop.iriosmedia.online', 'store_id' => '33', 'push_enabled' => 'no' ) );

		$this->assertNull( $config['push'] );
	}

	public function test_with_push_configured_it_points_to_the_service_worker_of_the_store(): void {
		$config = $this->config(
			array(
				'js_domain'    => 'shop.iriosmedia.online',
				'store_id'     => '33',
				'push_enabled' => 'yes',
				'push_ad_unit' => '3263',
				'push_auth'    => 'abc',
			)
		);

		$this->assertSame( 3263, $config['push']['id'] );
		$this->assertSame( 'https://shop.test/irios-sw.js', $config['push']['serviceWorker'] );
		$this->assertSame( '/', $config['push']['scope'] );
	}

	public function test_the_anti_flicker_hides_the_results_and_lets_them_go_on_its_own(): void {
		$html = Assets::anti_flicker_html();

		$this->assertStringContainsString( 'irios-ads-pending', $html );
		$this->assertStringContainsString( 'setTimeout', $html, 'if the auction never answers, the results are shown anyway' );
		$this->assertStringContainsString( 'opacity:0', $html );
	}
}
