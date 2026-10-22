<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase {

	private static function candidate( int $id, bool $published = true, bool $in_stock = true, bool $is_default = false ): array {
		return array(
			'id'         => $id,
			'published'  => $published,
			'in_stock'   => $in_stock,
			'is_default' => $is_default,
		);
	}

	public function test_default_variation_wins(): void {
		$this->assertSame( 3, Catalog::choose_representative( array( self::candidate( 2 ), self::candidate( 3, true, true, true ) ) ) );
	}

	public function test_default_out_of_stock_falls_back_to_first_in_stock(): void {
		$this->assertSame(
			4,
			Catalog::choose_representative( array( self::candidate( 2, true, false ), self::candidate( 3, true, false, true ), self::candidate( 4 ) ) )
		);
	}

	public function test_without_default_first_in_stock(): void {
		$this->assertSame( 5, Catalog::choose_representative( array( self::candidate( 4, true, false ), self::candidate( 5 ) ) ) );
	}

	public function test_nothing_in_stock_first_published(): void {
		$this->assertSame(
			7,
			Catalog::choose_representative( array( self::candidate( 6, false, false ), self::candidate( 7, true, false ), self::candidate( 8, true, false ) ) )
		);
	}

	public function test_unpublished_default_is_ignored(): void {
		$this->assertSame( 9, Catalog::choose_representative( array( self::candidate( 8, false, true, true ), self::candidate( 9 ) ) ) );
	}

	public function test_no_published_variation(): void {
		$this->assertNull( Catalog::choose_representative( array( self::candidate( 1, false ) ) ) );
		$this->assertNull( Catalog::choose_representative( array() ) );
	}

	public function test_path_joins_root_to_leaf(): void {
		$this->assertSame( 'Sticks > SR Sticks', Catalog::join_path( array( 'Sticks', ' SR Sticks ' ) ) );
		$this->assertSame( 'Promociones', Catalog::join_path( array( 'Promociones' ) ) );
	}

}
