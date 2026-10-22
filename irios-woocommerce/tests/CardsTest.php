<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Cards;
use PHPUnit\Framework\TestCase;

final class CardsTest extends TestCase {

	public function test_parse_ids_keeps_order_drops_invalid_and_duplicates(): void {
		$this->assertSame( array( 16, 10, 7 ), Cards::parse_ids( '16, 10,abc,0,-3,16,7', 20 ) );
	}

	public function test_parse_ids_limited_to_max_ads(): void {
		$this->assertSame( array( 1, 2 ), Cards::parse_ids( '1,2,3,4', 2 ) );
		$this->assertSame( array(), Cards::parse_ids( '', 4 ) );
	}

	public function test_variation_ad_links_to_the_variation(): void {
		$html = '<li><a href="https://shop.test/product/stick/" class="link">Stick</a>'
			. '<a href="https://shop.test/product/stick" class="button">Elegir</a>'
			. '<a href="https://shop.test/other/">Otro</a></li>';

		$out = Cards::link_to( $html, 'https://shop.test/product/stick/', 'https://shop.test/product/stick/?attribute_lado=RIGHT&x=1' );

		$this->assertSame(
			'<li><a href="https://shop.test/product/stick/?attribute_lado=RIGHT&amp;x=1" class="link">Stick</a>'
			. '<a href="https://shop.test/product/stick/?attribute_lado=RIGHT&amp;x=1" class="button">Elegir</a>'
			. '<a href="https://shop.test/other/">Otro</a></li>',
			$out
		);
	}
}
