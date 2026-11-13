<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Banners;
use Irios\WooCommerce\Catalog;
use Irios\WooCommerce\Events;

/**
 * IRIOS page context of the ad requests (pageType and, where the type needs it, the category path) and the banner
 * shortcode's page_type.
 */
final class PageContextTest extends WordPressTestCase {

	/** Categories: 1 Hockey > 2 Sticks > 3 Composite; 4 Promociones (root); 5 Junior (root). */
	private array $terms;

	protected function setUp(): void {
		parent::setUp();
		$this->terms = array(
			1 => new \WP_Term( 1, 'Hockey' ),
			2 => new \WP_Term( 2, 'Sticks' ),
			3 => new \WP_Term( 3, 'Composite' ),
			4 => new \WP_Term( 4, 'Promociones' ),
			5 => new \WP_Term( 5, 'Junior' ),
		);
		$parents = array( 2 => array( 1 ), 3 => array( 2, 1 ) );
		Functions\when( 'get_ancestors' )->alias( fn ( $id ) => $parents[ $id ] ?? array() );
		Functions\when( 'get_term' )->alias( fn ( $id ) => $this->terms[ $id ] ?? null );
		Functions\when( 'get_query_var' )->justReturn( '' );
		$this->given_page();
	}

	/** The WordPress conditionals that are true on the page being viewed (all the others, false). */
	private function given_page( string ...$true ): void {
		foreach ( array( 'is_search', 'is_product_category', 'is_product', 'is_front_page', 'is_order_received_page', 'is_cart', 'is_checkout', 'is_shop', 'is_product_taxonomy' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( in_array( $conditional, $true, true ) );
		}
	}

	public function test_home_page(): void {
		$this->given_page( 'is_front_page' );
		$this->assertSame( array( 'pageType' => 'HOMEPAGE', 'category' => array() ), Events::page_context() );
	}

	public function test_product_search_even_filtered_by_category(): void {
		$this->given_page( 'is_search', 'is_product_category' );
		Functions\when( 'get_query_var' )->alias( fn ( $name ) => 'post_type' === $name ? 'product' : '' );
		$this->assertSame( array( 'pageType' => 'SEARCH', 'category' => array() ), Events::page_context() );
	}

	public function test_category_page_with_its_path(): void {
		$this->given_page( 'is_product_category' );
		Functions\when( 'get_queried_object' )->justReturn( $this->terms[3] );
		$this->assertSame(
			array( 'pageType' => 'CATEGORY', 'category' => array( 'Hockey', 'Sticks', 'Composite' ) ),
			Events::page_context()
		);
	}

	public function test_product_page_with_its_deepest_category(): void {
		$this->given_page( 'is_product' );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );
		Functions\when( 'get_the_terms' )->justReturn( array( $this->terms[4], $this->terms[2], $this->terms[5] ) );
		$this->assertSame(
			array( 'pageType' => 'PRODUCT_DETAIL', 'category' => array( 'Hockey', 'Sticks' ) ),
			Events::page_context()
		);
	}

	public function test_product_without_category_has_no_type(): void {
		$this->given_page( 'is_product' );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );
		Functions\when( 'get_the_terms' )->justReturn( false );
		$this->assertSame( array( 'pageType' => '', 'category' => array() ), Events::page_context() );
	}

	public function test_cart_checkout_confirmation_and_listings(): void {
		$this->given_page( 'is_cart' );
		$this->assertSame( 'CHECKOUT', Events::page_context()['pageType'] );
		$this->given_page( 'is_checkout' );
		$this->assertSame( 'CHECKOUT', Events::page_context()['pageType'] );
		$this->given_page( 'is_checkout', 'is_order_received_page' );
		$this->assertSame( 'CONFIRMATION', Events::page_context()['pageType'] );
		$this->given_page( 'is_shop' );
		$this->assertSame( 'MERCHANDISING', Events::page_context()['pageType'] );
		$this->given_page( 'is_product_taxonomy' );
		$this->assertSame( 'MERCHANDISING', Events::page_context()['pageType'] );
	}

	public function test_other_pages_have_no_type(): void {
		$this->assertSame( array( 'pageType' => '', 'category' => array() ), Events::page_context() );
	}

	public function test_deepest_category_ties_go_to_the_lowest_id(): void {
		Functions\when( 'get_the_terms' )->justReturn( array( $this->terms[5], $this->terms[4] ) );
		$this->assertSame( array( 'Promociones' ), Catalog::product_category_names( 10 ) );
	}

	public function test_banner_shortcode_page_type(): void {
		$this->settings = array(
			'enabled'         => 'yes',
			'js_domain'       => 'tracker.irios.test',
			'store_id'        => '1234',
			'banners_enabled' => 'yes',
			'banners'         => json_encode( array( array( 'tag' => 'promo', 'id' => '1001', 'auth' => 'abc' ) ) ),
		);
		Functions\when( 'shortcode_atts' )->alias( fn ( $pairs, $atts ) => array_merge( $pairs, array_intersect_key( $atts, $pairs ) ) );
		Functions\when( 'esc_html' )->returnArg();

		$this->assertSame(
			'<div class="irios-banner" data-page-type="PROMOTIONS" data-ad-unit="1001" data-auth="abc" data-type="banner" data-device="all" data-tag="promo"></div>',
			Banners::shortcode( array( 'tag' => 'promo', 'page_type' => 'promotions' ) )
		);
		// Only the types that need no search or category: the others are left to the page
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="1001" data-auth="abc" data-type="banner" data-device="all" data-tag="promo"></div>',
			Banners::shortcode( array( 'tag' => 'promo', 'page_type' => 'CATEGORY' ) )
		);
		$this->assertSame(
			'<div class="irios-banner" data-ad-unit="1001" data-auth="abc" data-type="banner" data-device="all" data-tag="promo"></div>',
			Banners::shortcode( array( 'tag' => 'promo' ) )
		);
	}
}
