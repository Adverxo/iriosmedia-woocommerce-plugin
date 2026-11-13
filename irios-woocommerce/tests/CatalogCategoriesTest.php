<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Catalog;

/**
 * The category path IRIOS Media is told on a product page. A product usually sits in several categories, and the rule
 * is the same in every IRIOS Media integration: the deepest one and, when two are equally deep, the one with the
 * lowest id, so the same product always reports the same path.
 */
final class CatalogCategoriesTest extends WordPressTestCase {

	/** @var array<int,array{name:string,parents:int[]}> */
	private array $terms = array();
	/** @var int[] categories of the product */
	private array $of_product = array();

	protected function setUp(): void {
		parent::setUp();
		$this->terms = array();
		$this->of_product = array();
		Functions\when( 'get_ancestors' )->alias( fn ( int $id ) => $this->terms[ $id ]['parents'] ?? array() );
		Functions\when( 'get_term' )->alias( fn ( int $id ) => $this->term( $id ) );
		Functions\when( 'get_the_terms' )->alias(
			fn () => array() === $this->of_product ? false : array_map( fn ( int $id ) => $this->term( $id ), $this->of_product )
		);
	}

	/** @param int[] $parents leaf → root, as WordPress returns them */
	private function given_category( int $id, string $name, array $parents = array() ): void {
		$this->terms[ $id ] = array( 'name' => $name, 'parents' => $parents );
	}

	private function term( int $id ): \WP_Term {
		return new \WP_Term( $id, $this->terms[ $id ]['name'] ?? '' );
	}

	public function test_the_path_goes_from_the_root_down_to_the_category(): void {
		$this->given_category( 1, 'Sticks' );
		$this->given_category( 2, 'JR Sticks', array( 1 ) );
		$this->of_product = array( 2 );

		$this->assertSame( array( 'Sticks', 'JR Sticks' ), Catalog::product_category_names( 10 ) );
	}

	public function test_of_two_categories_the_deepest_one_wins(): void {
		$this->given_category( 1, 'Sticks' );
		$this->given_category( 2, 'JR Sticks', array( 1 ) );
		$this->given_category( 3, 'Promociones' );
		$this->of_product = array( 3, 2 );

		$this->assertSame( array( 'Sticks', 'JR Sticks' ), Catalog::product_category_names( 10 ) );
	}

	public function test_equally_deep_the_lowest_id_wins_so_it_is_always_the_same(): void {
		$this->given_category( 5, 'Promociones' );
		$this->given_category( 4, 'Novedades' );
		$this->of_product = array( 5, 4 );

		$this->assertSame( array( 'Novedades' ), Catalog::product_category_names( 10 ) );
		$this->of_product = array( 4, 5 ); // the other way round: the same answer
		$this->assertSame( array( 'Novedades' ), Catalog::product_category_names( 10 ) );
	}

	public function test_a_product_without_categories_reports_none(): void {
		$this->assertSame( array(), Catalog::product_category_names( 10 ) );
	}

	public function test_the_path_is_joined_with_the_separator_irios_media_expects(): void {
		$this->assertSame( 'Sticks > JR Sticks', Catalog::join_path( array( ' Sticks ', 'JR Sticks' ) ) );
		$this->assertSame( '', Catalog::join_path( array() ) );
	}

	public function test_the_variation_shown_on_a_product_page_is_the_default_one_if_it_can_be_bought(): void {
		$this->assertSame(
			7694,
			Catalog::choose_representative(
				array(
					array( 'id' => 7693, 'published' => true, 'in_stock' => true, 'is_default' => false ),
					array( 'id' => 7694, 'published' => true, 'in_stock' => true, 'is_default' => true ),
				)
			)
		);
	}

	public function test_without_a_default_the_first_one_in_stock_is_shown(): void {
		$this->assertSame(
			7695,
			Catalog::choose_representative(
				array(
					array( 'id' => 7694, 'published' => true, 'in_stock' => false, 'is_default' => false ),
					array( 'id' => 7695, 'published' => true, 'in_stock' => true, 'is_default' => false ),
				)
			)
		);
	}

	public function test_an_unpublished_variation_is_never_the_one_shown(): void {
		$this->assertNull(
			Catalog::choose_representative( array( array( 'id' => 7694, 'published' => false, 'in_stock' => true, 'is_default' => true ) ) )
		);
	}
}
