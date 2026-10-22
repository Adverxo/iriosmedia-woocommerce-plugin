<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

/** Error messages are translatable (__()): WordPressTestCase stubs the translation functions. */
final class SettingsTest extends WordPressTestCase {

	public function test_defaults_when_empty(): void {
		$s = new Settings( array() );

		$this->assertFalse( $s->is_configured() );
		$this->assertSame( 4, $s->max_ads() );
		$this->assertNull( $s->search_ad_unit() );
		$this->assertSame( Settings::CONSENT_WP_CONSENT_API, $s->consent_mode() );
	}

	public function test_configured_with_domain_and_store(): void {
		$s = new Settings(
			array(
				'js_domain'                => 'https://Example.IriosMedia.online/',
				'store_id'                 => '1234',
				'search_ad_unit'           => '233',
				'max_ads'                  => '50',
				'consent_mode'             => 'always',
			)
		);

		$this->assertTrue( $s->is_configured() );
		$this->assertSame( 'example.iriosmedia.online', $s->js_domain() );
		$this->assertSame( 1234, $s->store_id() );
		$this->assertSame( 233, $s->search_ad_unit() );
		$this->assertSame( 20, $s->max_ads() );
		$this->assertSame( Settings::CONSENT_ALWAYS, $s->consent_mode() );
	}

	public function test_unknown_consent_mode_falls_back_to_consent_api(): void {
		$this->assertSame( Settings::CONSENT_WP_CONSENT_API, ( new Settings( array( 'consent_mode' => 'nunca' ) ) )->consent_mode() );
	}

	public function test_non_numeric_store_is_not_configured(): void {
		$this->assertFalse( ( new Settings( array( 'js_domain' => 'a.iriosmedia.online', 'store_id' => 'abc' ) ) )->is_configured() );
	}

	#[DataProvider( 'domains' )]
	public function test_normalize_domain( string $input, string $expected ): void {
		$this->assertSame( $expected, Settings::normalize_domain( $input ) );
	}

	public static function domains(): array {
		return array(
			array( 'example.iriosmedia.online', 'example.iriosmedia.online' ),
			array( 'https://example.iriosmedia.online/', 'example.iriosmedia.online' ),
			array( 'http://EXAMPLE.iriosmedia.online', 'example.iriosmedia.online' ),
			array( 'example.iriosmedia.online/v1/irios-loader.js', '' ),
			array( 'javascript:alert(1)', '' ),
			array( 'localhost', '' ),
			array( '', '' ),
		);
	}

	#[DataProvider( 'languages' )]
	public function test_language_format( string $value, bool $valid ): void {
		$this->assertSame( $valid, Settings::is_valid_language( $value ) );
	}

	public static function languages(): array {
		return array(
			array( 'es-ES', true ),
			array( 'es', true ),
			array( 'en-GB', true ),
			array( 'es_ES', false ),
			array( 'ES-es', false ),
			array( '', false ),
		);
	}

	#[DataProvider( 'wordpressLocales' )]
	public function test_wordpress_locale_to_bcp47( string $locale, ?string $expected ): void {
		$this->assertSame( $expected, Settings::bcp47_language( $locale ) );
	}

	public static function wordpressLocales(): array {
		return array(
			array( 'es_ES', 'es-ES' ),
			array( 'en_US', 'en-US' ),
			array( 'pt_PT_ao90', 'pt-PT' ),
			array( 'de_DE_formal', 'de-DE' ),
			array( 'ca', 'ca' ),
			array( 'es-ES', null ),
			array( '', null ),
		);
	}

	public function test_positive_int(): void {
		$this->assertTrue( Settings::is_positive_int( '233' ) );
		$this->assertFalse( Settings::is_positive_int( '0' ) );
		$this->assertFalse( Settings::is_positive_int( '-1' ) );
		$this->assertFalse( Settings::is_positive_int( '12a' ) );
	}

	public function test_search_ads_need_ad_unit_and_auth(): void {
		$on = array( 'sponsored_enabled' => 'yes' );
		$this->assertFalse( ( new Settings( $on + array( 'search_ad_unit' => '7' ) ) )->search_ads_enabled() );
		$this->assertFalse( ( new Settings( $on + array( 'search_ad_unit_auth' => 'abc' ) ) )->search_ads_enabled() );
		$settings = new Settings( $on + array( 'search_ad_unit' => '7', 'search_ad_unit_auth' => ' abc ' ) );
		$this->assertTrue( $settings->search_ads_enabled() );
		$this->assertSame( 'abc', $settings->search_ad_unit_auth() );
	}

	public function test_sponsored_products_are_off_until_enabled(): void {
		$configured = array( 'search_ad_unit' => '7', 'search_ad_unit_auth' => 'abc' );
		$this->assertFalse( ( new Settings( $configured ) )->sponsored_enabled() );
		$this->assertFalse( ( new Settings( $configured ) )->search_ads_enabled() );
		$this->assertFalse( ( new Settings( $configured ) )->keep_single_result() );
		$this->assertTrue( ( new Settings( $configured + array( 'sponsored_enabled' => 'yes' ) ) )->search_ads_enabled() );
	}

	public function test_sponsored_label_never_empty(): void {
		$this->assertSame( 'Sponsored', ( new Settings( array( 'sponsored_label' => '' ) ) )->sponsored_label() );
		$this->assertSame( 'Anuncio', ( new Settings( array( 'sponsored_label' => 'Anuncio' ) ) )->sponsored_label() );
	}


	public function test_banners_are_resolved_by_tag(): void {
		$s = new Settings( array( 'banners' => '[{"tag":"home","id":"101","auth":"abc"},{"tag":"categoria-sticks","id":102,"auth":"def"}]' ) );

		$this->assertSame( array( 'tag' => 'home', 'id' => 101, 'auth' => 'abc', 'position' => '' ), $s->banner( 'Home ' ) );
		$this->assertSame( 102, $s->banner( 'categoria-sticks' )['id'] );
		$this->assertNull( $s->banner( 'no-existe' ) );
		$this->assertSame( array(), ( new Settings( array() ) )->banners() );
		$this->assertSame( array(), ( new Settings( array( 'banners' => 'not json' ) ) )->banners() );
	}

	public function test_invalid_banner_rows_are_dropped_with_an_error(): void {
		$result = Settings::sanitize_banners(
			array(
				array( 'tag' => ' Home ', 'id' => '101', 'auth' => ' abc ' ),
				array( 'tag' => 'home', 'id' => '102', 'auth' => 'x' ),      // duplicate
				array( 'tag' => 'with space', 'id' => '103', 'auth' => 'x' ),  // invalid tag
				array( 'tag' => 'sin-auth', 'id' => '104', 'auth' => '' ),
				array( 'tag' => 'sin-id', 'id' => 'abc', 'auth' => 'x' ),
				array( 'tag' => '', 'id' => '', 'auth' => '' ),                  // empty row: ignored
			)
		);

		$this->assertSame( array( array( 'tag' => 'home', 'id' => 101, 'auth' => 'abc', 'position' => '' ) ), $result['banners'] );
		$this->assertCount( 4, $result['errors'] );
	}

	public function test_banners_with_automatic_position(): void {
		$s = new Settings(
			array(
				'banners' => json_encode(
					array(
						array( 'tag' => 'listado', 'id' => 1001, 'auth' => 'a', 'position' => Settings::POSITION_CATEGORY_AFTER_PRODUCTS ),
						array( 'tag' => 'ficha', 'id' => 1002, 'auth' => 'b', 'position' => Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION ),
						array( 'tag' => 'home', 'id' => 101, 'auth' => 'c' ),
					)
				),
			)
		);

		$this->assertSame( array( 1001 ), array_column( $s->banners_at( Settings::POSITION_CATEGORY_AFTER_PRODUCTS ), 'id' ) );
		$this->assertSame( array( 1002 ), array_column( $s->banners_at( Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION ), 'id' ) );
		$this->assertSame( array(), $s->banners_at( Settings::POSITION_NONE ) );
		$this->assertSame( 101, $s->banner( 'home' )['id'] );
	}

	public function test_unknown_banner_position_is_an_error(): void {
		$result = Settings::sanitize_banners( array( array( 'tag' => 'x', 'id' => '1', 'auth' => 'a', 'position' => 'cabecera' ) ) );

		$this->assertSame( array(), $result['banners'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_sponsored_slots_sorted_and_grouped_by_ad_unit(): void {
		$s = new Settings(
			array(
				'sponsored_enabled' => 'yes',
				'sponsored_slots'   => json_encode(
					array(
						array( 'position' => 5, 'id' => 20, 'auth' => 'b' ),
						array( 'position' => 1, 'id' => 10, 'auth' => 'a' ),
						array( 'position' => 3, 'id' => 10, 'auth' => 'a' ),
					)
				),
			)
		);

		$this->assertTrue( $s->search_ads_enabled() );
		$this->assertSame( array( 1, 3, 5 ), array_column( $s->sponsored_slots(), 'position' ) );
		$this->assertSame(
			array(
				array( 'adUnit' => 10, 'auth' => 'a', 'positions' => array( 1, 3 ) ),
				array( 'adUnit' => 20, 'auth' => 'b', 'positions' => array( 5 ) ),
			),
			$s->sponsored_units()
		);
	}

	public function test_sponsored_slots_never_saved_use_legacy_ad_unit(): void {
		$s = new Settings(
			array(
				'sponsored_enabled'   => 'yes',
				'search_ad_unit'      => '233',
				'search_ad_unit_auth' => 'abc',
				'max_ads'             => '2',
			)
		);

		$this->assertSame(
			array(
				array( 'position' => 1, 'id' => 233, 'auth' => 'abc' ),
				array( 'position' => 2, 'id' => 233, 'auth' => 'abc' ),
			),
			$s->sponsored_slots()
		);
		$this->assertSame( array( array( 'adUnit' => 233, 'auth' => 'abc', 'positions' => array( 1, 2 ) ) ), $s->sponsored_units() );
	}

	public function test_sponsored_slots_saved_empty_ignore_legacy_ad_unit(): void {
		$s = new Settings(
			array(
				'sponsored_enabled'   => 'yes',
				'sponsored_slots'     => '[]',
				'search_ad_unit'      => '233',
				'search_ad_unit_auth' => 'abc',
			)
		);

		$this->assertSame( array(), $s->sponsored_slots() );
		$this->assertFalse( $s->search_ads_enabled() );
	}

	public function test_sanitize_sponsored_slots(): void {
		$result = Settings::sanitize_sponsored_slots(
			array(
				array( 'position' => ' 4 ', 'id' => '7', 'auth' => ' x ' ),
				array( 'position' => '', 'id' => '', 'auth' => '' ),
				array( 'position' => '4', 'id' => '8', 'auth' => 'y' ),
				array( 'position' => '0', 'id' => '8', 'auth' => 'y' ),
				array( 'position' => '51', 'id' => '8', 'auth' => 'y' ),
				array( 'position' => '2', 'id' => 'abc', 'auth' => 'y' ),
				array( 'position' => '3', 'id' => '9', 'auth' => '' ),
				array( 'position' => '1', 'id' => '9', 'auth' => 'z' ),
			)
		);

		$this->assertSame(
			array(
				array( 'position' => 1, 'id' => 9, 'auth' => 'z' ),
				array( 'position' => 4, 'id' => 7, 'auth' => 'x' ),
			),
			$result['slots']
		);
		$this->assertCount( 5, $result['errors'] );
	}
}
