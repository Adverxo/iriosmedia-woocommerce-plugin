<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Integration;

/**
 * What the settings screen accepts when the shop manager saves. A wrong value must not leave the store sending ads
 * with a broken configuration: it is refused, the previous value is kept and the reason is shown.
 *
 * The integration is built without its constructor: that one draws the whole form, and what is tested here is only
 * what it does with each value.
 */
final class IntegrationValidationTest extends WordPressTestCase {

	private Integration $integration;

	protected function setUp(): void {
		parent::setUp();
		\WC_Admin_Settings::$errors = array();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn ( $v ) => json_encode( $v ) );
		$this->integration = ( new \ReflectionClass( Integration::class ) )->newInstanceWithoutConstructor();
	}

	private function given_saved( array $settings ): void {
		$this->integration->settings = $settings;
	}

	private function errors(): array {
		return \WC_Admin_Settings::$errors;
	}

	public function test_a_domain_that_is_not_a_domain_keeps_the_one_already_saved(): void {
		$this->given_saved( array( 'js_domain' => 'shop.iriosmedia.online' ) );

		$kept = $this->integration->validate_js_domain_field( 'js_domain', 'no es un dominio' );

		$this->assertSame( 'shop.iriosmedia.online', $kept );
		$this->assertCount( 1, $this->errors() );
	}

	public function test_the_scheme_and_the_trailing_slash_are_dropped(): void {
		$this->assertSame( 'shop.iriosmedia.online', $this->integration->validate_js_domain_field( 'js_domain', 'HTTPS://Shop.IriosMedia.online/' ) );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_a_domain_with_a_path_is_refused_because_the_field_is_only_the_domain(): void {
		$this->given_saved( array( 'js_domain' => 'shop.iriosmedia.online' ) );

		$kept = $this->integration->validate_js_domain_field( 'js_domain', 'https://shop.iriosmedia.online/v1/' );

		$this->assertSame( 'shop.iriosmedia.online', $kept );
		$this->assertCount( 1, $this->errors() );
	}

	public function test_emptying_the_domain_turns_the_integration_off_without_complaining(): void {
		$this->given_saved( array( 'js_domain' => 'shop.iriosmedia.online' ) );

		$this->assertSame( '', $this->integration->validate_js_domain_field( 'js_domain', '  ' ) );
		$this->assertSame( array(), $this->errors() );
	}

	public function test_the_store_id_has_to_be_a_number(): void {
		$this->given_saved( array( 'store_id' => '33' ) );

		$this->assertSame( '42', $this->integration->validate_store_id_field( 'store_id', ' 42 ' ) );
		$this->assertSame( array(), $this->errors() );

		$this->assertSame( '33', $this->integration->validate_store_id_field( 'store_id', 'treinta y tres' ) );
		$this->assertCount( 1, $this->errors(), 'the one saved is kept and the reason is shown' );
	}

	public function test_the_push_ad_unit_has_to_be_a_number(): void {
		$this->given_saved( array( 'push_ad_unit' => '3263' ) );

		$this->assertSame( '3263', $this->integration->validate_push_ad_unit_field( 'push_ad_unit', 'abc' ) );
		$this->assertCount( 1, $this->errors() );
	}

	public function test_the_numbers_with_a_default_fall_back_to_it_instead_of_refusing(): void {
		$this->assertSame( '8', $this->integration->validate_display_in_grid_after_field( 'display_in_grid_after', '8' ) );
		$this->assertSame( '6', $this->integration->validate_display_in_grid_after_field( 'display_in_grid_after', '-1' ) );

		$this->assertSame( '3', $this->integration->validate_push_after_pages_field( 'push_after_pages', '3' ) );
		$this->assertSame( '2', $this->integration->validate_push_after_pages_field( 'push_after_pages', 'x' ) );

		$this->assertSame( '0', $this->integration->validate_push_repeat_days_field( 'push_repeat_days', '0' ), 'zero: on every visit' );
		$this->assertSame( '30', $this->integration->validate_push_repeat_days_field( 'push_repeat_days', '-5' ) );
		$this->assertSame( array(), $this->errors(), 'these do not stop the save' );
	}

	public function test_the_sponsored_positions_are_saved_as_json_and_the_wrong_rows_are_dropped(): void {
		$rows = array(
			array( 'position' => '1', 'id' => '3263', 'auth' => 'abc' ),
			array( 'position' => '1', 'id' => '3264', 'auth' => 'def' ), // repeated position
			array( 'position' => '2', 'id' => '', 'auth' => 'ghi' ),     // no ad unit
		);

		$saved = json_decode( $this->integration->validate_sponsored_slots_field( 'sponsored_slots', $rows ), true );

		$this->assertSame( array( array( 'position' => 1, 'id' => 3263, 'auth' => 'abc' ) ), $saved );
		$this->assertCount( 2, $this->errors(), 'one reason per row dropped' );
	}

	public function test_the_display_placements_are_saved_as_json_and_the_wrong_rows_are_dropped(): void {
		$rows = array(
			array( 'tag' => 'portada', 'id' => '3260', 'auth' => 'abc', 'position' => '' ),
			array( 'tag' => 'PORTADA MAL', 'id' => '3261', 'auth' => 'def', 'position' => '' ), // tag with spaces and capitals
		);

		$saved = json_decode( $this->integration->validate_banners_field( 'banners', $rows ), true );

		$this->assertCount( 1, $saved );
		$this->assertSame( 'portada', $saved[0]['tag'] );
		$this->assertCount( 1, $this->errors() );
	}

	public function test_a_table_with_nothing_in_it_saves_an_empty_list(): void {
		$this->assertSame( '[]', $this->integration->validate_sponsored_slots_field( 'sponsored_slots', null ) );
		$this->assertSame( '[]', $this->integration->validate_banners_field( 'banners', 'not a table' ) );
		$this->assertSame( array(), $this->errors() );
	}
}
