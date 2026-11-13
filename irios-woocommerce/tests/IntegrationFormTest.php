<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Integration;
use Irios\WooCommerce\Settings;

/**
 * The settings screen: the sections and fields the shop manager sees, and the two tables (Sponsored Products
 * positions and Display placements) that the plugin draws itself.
 */
final class IntegrationFormTest extends WordPressTestCase {

	private Integration $integration;

	protected function setUp(): void {
		parent::setUp();
		\WC_Admin_Settings::$errors = array();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'selected' )->alias( fn ( $a, $b, $echo = true ) => (string) $a === (string) $b ? ' selected' : '' );
		Functions\when( 'checked' )->alias( fn ( $a, $b = true, $echo = true ) => (string) $a === (string) $b ? ' checked' : '' );
		Functions\when( 'wc_enqueue_js' )->justReturn( null );
		Functions\when( 'home_url' )->alias( fn ( string $p = '' ) => 'https://shop.test' . $p );
		Functions\when( 'wp_parse_url' )->alias( fn ( $url, $c ) => parse_url( $url, $c ) );
		Functions\when( 'trailingslashit' )->alias( fn ( string $s ) => rtrim( $s, '/' ) . '/' );
		Functions\when( 'get_stylesheet' )->justReturn( 'storefront' );
		Functions\when( 'get_template' )->justReturn( 'storefront' );
		Functions\when( 'wp_get_theme' )->alias( fn ( string $slug = '' ) => new \WP_Theme( 'Storefront' ) );

		$this->integration = new Integration();
	}

	public function test_it_shows_the_sections_every_irios_media_integration_shares(): void {
		$titles = array_values(
			array_map(
				static fn ( array $field ) => (string) ( $field['title'] ?? '' ),
				array_filter( $this->integration->form_fields, static fn ( array $f ) => 'title' === ( $f['type'] ?? '' ) )
			)
		);

		$this->assertContains( 'General', $titles );
		$this->assertContains( 'Sponsored Products', $titles );
		$this->assertContains( 'Display (Image and Video)', $titles );
		$this->assertContains( 'Push Notifications', $titles );
	}

	public function test_the_settings_that_decide_whether_anything_is_sent_are_there(): void {
		foreach ( array( 'enabled', 'js_domain', 'store_id', 'consent_mode' ) as $key ) {
			$this->assertArrayHasKey( $key, $this->integration->form_fields );
		}
		$this->assertSame(
			array( Settings::CONSENT_WP_CONSENT_API, Settings::CONSENT_ASESOR_COOKIES, Settings::CONSENT_ALWAYS ),
			array_keys( $this->integration->form_fields['consent_mode']['options'] )
		);
	}

	public function test_the_table_of_positions_draws_a_row_per_saved_position_and_an_empty_one(): void {
		$this->integration->settings = array(
			'sponsored_slots' => (string) json_encode( array( array( 'position' => 1, 'id' => 3263, 'auth' => 'abc' ) ) ),
		);

		$html = $this->integration->generate_irios_slots_html( 'sponsored_slots', array( 'title' => 'Search results positions' ) );

		$this->assertStringContainsString( 'value="3263"', $html );
		$this->assertStringContainsString( 'value="abc"', $html );
		$this->assertStringContainsString( 'irios-row-template', $html, 'the empty row to add another one' );
	}

	public function test_the_table_of_placements_offers_every_shared_position(): void {
		$this->integration->settings = array(
			'banners' => (string) json_encode(
				array( array( 'tag' => 'portada', 'id' => 3260, 'auth' => 'abc', 'position' => Settings::POSITION_CATEGORY_AFTER_PRODUCTS ) )
			),
		);

		$html = $this->integration->generate_irios_banners_html( 'banners', array( 'title' => 'Placements' ) );

		$this->assertStringContainsString( 'value="portada"', $html );
		foreach ( Settings::POSITIONS as $position ) {
			$this->assertStringContainsString( 'value="' . $position . '"', $html, "the position «{$position}» can be chosen" );
		}
		$this->assertStringContainsString( ' selected', $html, 'the saved one comes selected' );
	}

	public function test_an_empty_table_still_lets_a_row_be_added(): void {
		$this->integration->settings = array();

		$this->assertStringContainsString( 'irios-row-template', $this->integration->generate_irios_slots_html( 'sponsored_slots', array( 'title' => 'Search results positions' ) ) );
		$this->assertStringContainsString( 'irios-row-template', $this->integration->generate_irios_banners_html( 'banners', array( 'title' => 'Placements' ) ) );
	}
}
