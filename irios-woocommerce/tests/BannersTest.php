<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Banners;
use PHPUnit\Framework\TestCase;

final class BannersTest extends TestCase {

	public function test_enabled_placement_leaves_a_slot_with_its_ad_unit_type_and_device(): void {
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="55308" data-auth="ab&quot;c" data-type="video" data-device="mobile" data-tag="home"></div>',
			Banners::placeholder( true, array( 'tag' => 'home', 'type' => 'video', 'device' => 'mobile', 'id' => ' 55308 ', 'auth' => 'ab"c' ) )
		);
	}

	public function test_a_row_saved_by_1_0_is_an_image_for_all_devices(): void {
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="1" data-auth="a" data-type="banner" data-device="all" data-tag=""></div>',
			Banners::placeholder( true, array( 'id' => 1, 'auth' => 'a' ) )
		);
	}

	public function test_disabled_or_invalid_placement_renders_nothing(): void {
		$this->assertSame( '', Banners::placeholder( false, array( 'id' => '55308', 'auth' => 'abc' ) ) );
		$this->assertSame( '', Banners::placeholder( true, array( 'id' => 'x', 'auth' => 'abc' ) ) );
		$this->assertSame( '', Banners::placeholder( true, array( 'id' => '55308', 'auth' => '' ) ) );
	}

	public function test_positioned_placement_is_hidden_until_placed(): void {
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="1001" data-auth="abc" data-type="banner" data-device="all" data-tag="plp" data-irios-position="category_in_grid" hidden></div>',
			Banners::placeholder( true, array( 'tag' => 'plp', 'type' => 'banner', 'device' => 'all', 'id' => 1001, 'auth' => 'abc' ), 'category_in_grid' )
		);
	}
}
