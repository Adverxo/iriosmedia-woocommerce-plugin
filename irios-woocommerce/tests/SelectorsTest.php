<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Filters;
use Irios\WooCommerce\Selectors;

/**
 * Ads are placed by finding things in the theme's HTML, so a theme that names them differently needs them changed.
 * That has to be possible from the site itself: otherwise every theme means a new version of the plugin.
 */
final class SelectorsTest extends WordPressTestCase {

	public function test_a_theme_can_change_where_its_results_grid_is(): void {
		Filters\expectApplied( 'irios_woocommerce_selectors' )->andReturnUsing(
			static function ( array $selectors ): array {
				$selectors['grid'] = '.mi-rejilla';
				return $selectors;
			}
		);

		$selectors = Selectors::all();

		$this->assertSame( '.mi-rejilla', $selectors['grid'] );
		$this->assertSame( Selectors::defaults()['sidebar'], $selectors['sidebar'], 'the rest keep theirs' );
	}

	public function test_what_the_filter_does_not_give_keeps_the_tested_default(): void {
		Filters\expectApplied( 'irios_woocommerce_selectors' )->andReturn( array( 'grid' => '.mi-rejilla' ) );

		$this->assertSame( Selectors::defaults()['title'], Selectors::all()['title'] );
	}

	public function test_an_empty_or_broken_value_is_ignored_instead_of_leaving_the_store_without_ads(): void {
		Filters\expectApplied( 'irios_woocommerce_selectors' )->andReturn(
			array( 'grid' => '  ', 'title' => null, 'sidebar' => array( 'nope' ) )
		);

		$selectors = Selectors::all();

		$this->assertSame( Selectors::defaults()['grid'], $selectors['grid'] );
		$this->assertSame( Selectors::defaults()['title'], $selectors['title'] );
		$this->assertSame( Selectors::defaults()['sidebar'], $selectors['sidebar'] );
	}

	public function test_a_filter_that_returns_anything_else_changes_nothing(): void {
		Filters\expectApplied( 'irios_woocommerce_selectors' )->andReturn( 'not a list' );

		$this->assertSame( Selectors::defaults(), Selectors::all() );
	}

	public function test_every_place_the_js_looks_for_is_there(): void {
		$this->assertSame(
			array( 'grid', 'title', 'listingControls', 'productTabs', 'related', 'sidebar', 'notResults', 'liveDropdown' ),
			array_keys( Selectors::defaults() )
		);
	}
}
