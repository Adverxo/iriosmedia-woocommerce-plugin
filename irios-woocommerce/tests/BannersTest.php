<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Banners;
use PHPUnit\Framework\TestCase;

final class BannersTest extends TestCase {

	public function test_enabled_banner_leaves_a_placeholder(): void {
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="55308" data-auth="ab&quot;c"></div>',
			Banners::placeholder( true, ' 55308 ', 'ab"c' )
		);
	}

	public function test_disabled_or_invalid_banner_renders_nothing(): void {
		$this->assertSame( '', Banners::placeholder( false, '55308', 'abc' ) );
		$this->assertSame( '', Banners::placeholder( true, 'x', 'abc' ) );
		$this->assertSame( '', Banners::placeholder( true, '55308', '' ) );
	}

	public function test_positioned_banner_is_hidden_until_placed(): void {
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="1001" data-auth="abc" data-irios-position="category_after_products" hidden></div>',
			Banners::placeholder( true, '1001', 'abc', 'category_after_products' )
		);
	}
}
