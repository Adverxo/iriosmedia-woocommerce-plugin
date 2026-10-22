<?php

namespace Irios\WooCommerce;

/**
 * Catalog rules shared with the sync service: product ids (variation or simple, never the variable parent), category
 * paths and displayed price (including VAT according to the store settings).
 */
final class Catalog {

	public const PATH_SEPARATOR = ' > ';

	/**
	 * Representative variation of a variable product (design decision D4), from already sorted candidates: the
	 * default one if published and in stock; otherwise the first published and in stock; otherwise the first
	 * published.
	 *
	 * @param array<int,array{id:int,published:bool,in_stock:bool,is_default:bool}> $candidates
	 */
	public static function choose_representative( array $candidates ): ?int {
		foreach ( $candidates as $c ) {
			if ( $c['is_default'] && $c['published'] && $c['in_stock'] ) {
				return $c['id'];
			}
		}
		foreach ( $candidates as $c ) {
			if ( $c['published'] && $c['in_stock'] ) {
				return $c['id'];
			}
		}
		foreach ( $candidates as $c ) {
			if ( $c['published'] ) {
				return $c['id'];
			}
		}
		return null;
	}

	/** @param string[] $names Names, root → leaf. */
	public static function join_path( array $names ): string {
		return implode( self::PATH_SEPARATOR, array_map( 'trim', $names ) );
	}


	// ---- With WordPress/WooCommerce ----------------------------------------------------------------------------------

	/** @return string[] Names of the category and its ancestors, root → leaf. */
	public static function category_names( \WP_Term $term ): array {
		$names = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof \WP_Term ) {
				$names[] = $ancestor->name;
			}
		}
		$names[] = $term->name;
		return $names;
	}

	/** Product representing the product page: the simple one, or the variable's representative variation. */
	public static function view_product( \WC_Product $product ): ?\WC_Product {
		if ( ! $product->is_type( 'variable' ) ) {
			return $product;
		}
		$default_id = self::default_variation_id( $product );
		$candidates = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product ) {
				continue;
			}
			$candidates[] = array(
				'id'         => $variation->get_id(),
				'published'  => 'publish' === $variation->get_status(),
				'in_stock'   => $variation->is_in_stock(),
				'is_default' => $variation->get_id() === $default_id,
			);
		}
		$chosen = self::choose_representative( $candidates );
		return null === $chosen ? null : wc_get_product( $chosen );
	}

	/** Product line for an event: catalog id, displayed price and quantity. */
	public static function line( \WC_Product $product, int $quantity = 1 ): array {
		return array(
			'id'       => (string) $product->get_id(),
			'price'    => round( (float) wc_get_price_to_display( $product ), 2 ),
			'quantity' => max( 1, $quantity ),
		);
	}

	private static function default_variation_id( \WC_Product $product ): ?int {
		$defaults = $product->get_default_attributes();
		if ( empty( $defaults ) ) {
			return null;
		}
		$attributes = array();
		foreach ( $defaults as $name => $value ) {
			$attributes[ 'attribute_' . sanitize_title( $name ) ] = $value;
		}
		$id = ( new \WC_Product_Data_Store_CPT() )->find_matching_product_variation( $product, $attributes );
		return $id ? (int) $id : null;
	}
}
