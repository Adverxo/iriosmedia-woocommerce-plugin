<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Compatibility;

/**
 * Ads are placed in the HTML each theme renders, so with a theme nobody has tried some may not show up. The settings
 * screen warns about it; a child theme of a tested one counts as tested.
 */
final class CompatibilityTestedThemesTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->returnArg();
	}

	private function given_theme( string $stylesheet, string $template = '' ): void {
		$template = '' === $template ? $stylesheet : $template;
		Functions\when( 'get_stylesheet' )->justReturn( $stylesheet );
		Functions\when( 'get_template' )->justReturn( $template );
		Functions\when( 'wp_get_theme' )->alias( fn ( string $slug = '' ) => new \WP_Theme( ucfirst( '' === $slug ? $stylesheet : $slug ) ) );
	}

	public function test_a_tested_theme_raises_no_warning(): void {
		$this->given_theme( 'storefront' );

		$this->assertTrue( Compatibility::active_theme_supported() );
		$this->assertSame( '', Compatibility::warning_html() );
	}

	public function test_the_slug_is_read_without_minding_capitals(): void {
		$this->assertTrue( Compatibility::is_supported( 'StoreFront', 'StoreFront' ) );
	}

	public function test_a_child_theme_of_a_tested_one_counts_as_tested(): void {
		$this->given_theme( 'mi-tema-hijo', 'storefront' );

		$this->assertTrue( Compatibility::active_theme_supported() );
	}

	public function test_an_untested_theme_is_warned_about_with_its_name(): void {
		$this->given_theme( 'un-tema-cualquiera' );

		$this->assertFalse( Compatibility::active_theme_supported() );
		$warning = Compatibility::warning_html();
		$this->assertStringContainsString( 'notice-warning', $warning );
		$this->assertStringContainsString( 'un-tema-cualquiera', $warning );
	}

	public function test_the_label_names_the_parent_theme_when_there_is_one(): void {
		$this->given_theme( 'mi-hijo', 'storefront' );

		$label = Compatibility::theme_label();

		$this->assertStringContainsString( 'mi-hijo', $label );
		$this->assertStringContainsString( 'storefront', $label );
	}

	public function test_the_label_of_a_theme_without_a_parent_names_it_only_once(): void {
		$this->given_theme( 'storefront' );

		$this->assertSame( 'Storefront (storefront)', Compatibility::theme_label() );
	}
}
