<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Cards;

/**
 * The cards of the winning ads, built by the store itself so the ad looks like any other product. A product that is
 * no longer on sale must not be advertised, even if IRIOS Media still has it in its catalog.
 */
final class CardsRenderTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( fn ( string $s ) => strip_tags( $s ) );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'date_i18n' )->justReturn( '' );
		Functions\when( 'wc_price' )->alias( fn ( $v ) => '<span>' . $v . '</span>' );
	}

	/** @param int[] $ids */
	private function ids_rendered( array $ids ): array {
		return array_map( static fn ( array $card ) => $card['id'], Cards::render( $ids, Cards::LAYOUT_DATA ) );
	}

	public function test_an_unpublished_product_is_not_advertised(): void {
		$this->given_products(
			( new \WC_Product( 10 ) )->with( 'publish' ),
			( new \WC_Product( 11 ) )->with( 'draft' ),
			( new \WC_Product( 12 ) )->with( 'trash' )
		);

		$this->assertSame( array( '10' ), $this->ids_rendered( array( 10, 11, 12 ) ) );
	}

	public function test_a_hidden_product_is_not_advertised(): void {
		$this->given_products(
			( new \WC_Product( 10 ) )->with( 'publish', true ),
			( new \WC_Product( 11 ) )->with( 'publish', false )
		);

		$this->assertSame( array( '10' ), $this->ids_rendered( array( 10, 11 ) ) );
	}

	public function test_a_product_the_store_no_longer_has_is_skipped_without_breaking_the_rest(): void {
		$this->given_products( ( new \WC_Product( 10 ) )->with( 'publish' ) );

		$this->assertSame( array( '10' ), $this->ids_rendered( array( 999, 10 ) ) );
	}

	public function test_a_variation_is_judged_by_its_parent_but_keeps_its_own_id(): void {
		$this->given_products(
			( new \WC_Product( 7693 ) )->with( 'publish' ),
			( new \WC_Product( 7694, 10.0, 'variation' ) )->with( 'publish', true, 7693 )
		);

		$cards = Cards::render( array( 7694 ), Cards::LAYOUT_DATA );

		$this->assertSame( '7694', $cards[0]['id'], 'the ad is the variation' );
		$this->assertSame( 7693, $cards[0]['productId'], 'but the card is the product of the store' );
	}

	public function test_a_variation_of_an_unpublished_product_is_not_advertised(): void {
		$this->given_products(
			( new \WC_Product( 7693 ) )->with( 'draft' ),
			( new \WC_Product( 7694, 10.0, 'variation' ) )->with( 'publish', true, 7693 )
		);

		$this->assertSame( array(), Cards::render( array( 7694 ), Cards::LAYOUT_DATA ) );
	}

	public function test_only_as_many_ids_as_positions_are_accepted(): void {
		$this->assertSame( array( 7, 8 ), Cards::parse_ids( '7,8,9,10', 2 ) );
		$this->assertSame( array( 7 ), Cards::parse_ids( '7,7,7', 3 ), 'a repeated id counts once' );
		$this->assertSame( array(), Cards::parse_ids( '', 3 ) );
		$this->assertSame( array( 9 ), Cards::parse_ids( '0,-1,abc,9.5,9', 3 ), 'only whole positive numbers' );
	}
}
