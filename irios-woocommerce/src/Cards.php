<?php

namespace Irios\WooCommerce;

/**
 * Sponsored Products ad cards rendered by the theme itself, so they are identical to the organic results (native
 * ad): the JS inserts them into the grid and only adds the "Sponsored" label.
 *
 * - Classic theme (or classic template): `content-product.php`, the same template as the listing.
 * - Block theme: the `woocommerce/product-template` block from the theme's search results template, inside a
 *   collection with the hand-picked product.
 *
 * An ad for a variation is rendered with the parent product's card (that is what the listing shows) and its links
 * point to the variation.
 */
final class Cards {

	public const LAYOUT_CLASSIC = 'classic';
	public const LAYOUT_BLOCK   = 'block';
	/** No store grid: the JS clones a result on the page and fills in this data (link, title, image…). */
	public const LAYOUT_DATA = 'data';

	/** Last step reached while building the cards: shown by /cards diagnostics if another plugin halts execution. */
	public static string $step = '';

	/** Field errors from the last request ("product 6172 · priceHtml: …"), for the /cards diagnostics. */
	public static array $errors = array();

	/** @return int[] Unique positive ids, at most $max. */
	public static function parse_ids( string $value, int $max ): array {
		$ids = array();
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( 1 === preg_match( '/^[1-9][0-9]*$/', $part ) ) {
				$ids[ (int) $part ] = (int) $part;
			}
		}
		return array_slice( array_values( $ids ), 0, max( 1, $max ) );
	}

	/**
	 * @param int[] $ids Catalog ids (simple or variation), in ad order.
	 * @return array<int,array<string,mixed>> With html (classic/block) or with the ad data (data).
	 */
	public static function render( array $ids, string $layout, string $image_size = '' ): array {
		$cards = array();
		foreach ( $ids as $id ) {
			self::$step = 'product ' . $id;
			$ad_product = wc_get_product( $id );
			if ( ! $ad_product instanceof \WC_Product ) {
				continue;
			}
			$product = $ad_product->is_type( 'variation' ) ? wc_get_product( $ad_product->get_parent_id() ) : $ad_product;
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
				continue; // unpublished or hidden: not advertised even if Irios still has it
			}
			if ( self::LAYOUT_DATA === $layout ) {
				$cards[] = self::data( $ad_product, $product, $image_size );
				continue;
			}
			$html = self::LAYOUT_BLOCK === $layout ? self::block_card( $product ) : self::classic_card( $product );
			if ( '' === trim( $html ) ) {
				continue;
			}
			if ( $ad_product->get_id() !== $product->get_id() ) {
				$html = self::link_to( $html, $product->get_permalink(), $ad_product->get_permalink() );
			}
			$cards[] = array(
				'id'        => (string) $ad_product->get_id(),
				'productId' => $product->get_id(),
				'html'      => $html,
			);
		}
		return $cards;
	}

	/**
	 * Ad data for cloning a result: the product shown is the parent (or the simple product), and the link points to the
	 * advertised variation.
	 *
	 * @return array{id:string,productId:int,link:string,title:string,image:string,excerpt:string,date:string}
	 */
	private static function data( \WC_Product $ad_product, \WC_Product $product, string $image_size = '' ): array {
		// Each field separately: if one fails (other plugins hooked into its filters), it is left empty and the rest
		// still go out.
		$id = $product->get_id();
		return array(
			'id'        => (string) $ad_product->get_id(),
			'productId' => $id,
			'link'      => self::field( 'link', static fn() => (string) $ad_product->get_permalink() ),
			'title'     => self::field( 'title', static fn() => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ) ),
			'image'     => self::field(
				'image',
				static function () use ( $product, $image_size ): string {
					$image = wp_get_attachment_image_src( (int) $product->get_image_id(), self::image_size( $image_size ) );
					return $image ? (string) $image[0] : '';
				}
			),
			'excerpt'   => self::field(
				'excerpt',
				static function () use ( $product ): string {
					// The short description if it has text (not just spaces or &nbsp;); otherwise the long one.
					$plain = static fn( string $html ): string => trim(
						str_replace( "\u{00a0}", ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) )
					);
					$text = $plain( (string) $product->get_short_description() );
					if ( '' === $text ) {
						$text = $plain( (string) $product->get_description() );
					}
					return html_entity_decode( wp_trim_words( $text, 18 ), ENT_QUOTES, 'UTF-8' ); // plain text, not HTML
				}
			),
			'date'      => self::field( 'date', static fn() => (string) date_i18n( 'd M Y', (int) get_post_time( 'U', false, $id ) ) ),
			'priceHtml' => self::price_html( $ad_product, $product ),
		);
	}

	/**
	 * Price as the store renders it. If that fails (e.g. a discount plugin that needs the cart, which is not loaded in
	 * REST), the advertised product's price formatted by WooCommerce, without the sale HTML.
	 */
	private static function price_html( \WC_Product $ad_product, \WC_Product $product ): string {
		$html = self::field( 'priceHtml', static fn() => (string) $product->get_price_html() );
		if ( '' !== trim( $html ) ) {
			return $html;
		}
		return self::field(
			'priceHtml (wc_price)',
			static function () use ( $ad_product ): string {
				$price = $ad_product->get_price( 'edit' ); // no filters: failing ones usually hook the 'view' context
				return '' === (string) $price ? '' : (string) wc_price( (float) $price );
			}
		);
	}

	/** Value of a card field, or '' if it fails (with its error in the PHP log). Records the step for diagnostics. */
	private static function field( string $name, callable $value ): string {
		self::$step = preg_replace( '/ · .*$/', '', self::$step ) . ' · ' . $name;
		try {
			$result = (string) $value();
		} catch ( \Throwable $e ) {
			$message        = $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			self::$errors[] = self::$step . ': ' . get_class( $e ) . ': ' . $message;
			error_log( '[irios-woocommerce] /cards, ' . self::$step . ': ' . $message ); // phpcs:ignore
			$result = '';
		}
		// Text with non-UTF-8 bytes would break the JSON of the whole response.
		return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $result, true ) : $result;
	}

	/**
	 * Image size of the cloned result ("size-270x145" in its class): a registered one by name, or "WIDTHxHEIGHT" as a
	 * custom size; otherwise the WooCommerce thumbnail.
	 *
	 * @return string|int[]
	 */
	public static function image_size( string $size ) {
		if ( '' !== $size && function_exists( 'has_image_size' ) && ( has_image_size( $size ) || in_array( $size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) ) {
			return $size;
		}
		if ( 1 === preg_match( '/^([1-9][0-9]{0,3})x([1-9][0-9]{0,3})$/', $size, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		return 'woocommerce_thumbnail';
	}

	/** Replaces links to the parent's product page with the variation's (same product, variant preselected). */
	public static function link_to( string $html, string $from, string $to ): string {
		return (string) preg_replace_callback(
			'/href=(["\'])([^"\']*)\1/',
			static function ( array $m ) use ( $from, $to ): string {
				$url = html_entity_decode( $m[2], ENT_QUOTES );
				if ( rtrim( $url, '/' ) !== rtrim( $from, '/' ) ) {
					return $m[0]; // other links (add to cart, etc.) left untouched
				}
				return 'href=' . $m[1] . htmlspecialchars( $to, ENT_QUOTES ) . $m[1];
			},
			$html
		);
	}

	private static function classic_card( \WC_Product $product ): string {
		global $post;
		$previous_post    = $post;
		$previous_product = $GLOBALS['product'] ?? null;

		$post               = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $product;
		setup_postdata( $post );

		ob_start();
		wc_get_template_part( 'content', 'product' );
		$html = (string) ob_get_clean();

		$post               = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $previous_product;
		wp_reset_postdata();
		return $html;
	}

	private static function block_card( \WC_Product $product ): string {
		$template = self::search_product_template();
		if ( null === $template ) {
			return '';
		}
		$attributes = array(
			'queryId'    => 0,
			'query'      => array(
				'perPage'                       => 1,
				'pages'                         => 1,
				'offset'                        => 0,
				'postType'                      => 'product',
				'order'                         => 'asc',
				'orderBy'                       => 'title',
				'search'                        => '',
				'exclude'                       => array(),
				'inherit'                       => false,
				'taxQuery'                      => array(),
				'isProductCollectionBlock'      => true,
				'woocommerceOnSale'             => false,
				'woocommerceStockStatus'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'woocommerceAttributes'         => array(),
				'woocommerceHandPickedProducts' => array( (string) $product->get_id() ),
			),
			'tagName'    => 'div',
			'collection' => 'woocommerce/product-collection/hand-picked',
		);
		$markup = '<!-- wp:woocommerce/product-collection ' . wp_json_encode( $attributes ) . ' -->'
			. '<div class="wp-block-woocommerce-product-collection">' . serialize_block( $template ) . '</div>'
			. '<!-- /wp:woocommerce/product-collection -->';
		return do_blocks( $markup );
	}

	/** `woocommerce/product-template` block from the active theme's search (or catalog) template. */
	private static function search_product_template(): ?array {
		foreach ( array( 'product-search-results', 'archive-product' ) as $slug ) {
			$templates = get_block_templates( array( 'slug__in' => array( $slug ) ), 'wp_template' );
			foreach ( $templates as $template ) {
				$found = self::find_block( parse_blocks( (string) $template->content ), 'woocommerce/product-template' );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	private static function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $name ) {
				return $block;
			}
			// Templates that only reference parts or patterns: resolve them before searching.
			if ( 'core/pattern' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['slug'] ) ) {
				$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $block['attrs']['slug'] );
				if ( $pattern ) {
					$found = self::find_block( parse_blocks( $pattern['content'] ), $name );
					if ( null !== $found ) {
						return $found;
					}
				}
			}
			$found = self::find_block( $block['innerBlocks'] ?? array(), $name );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}
}
