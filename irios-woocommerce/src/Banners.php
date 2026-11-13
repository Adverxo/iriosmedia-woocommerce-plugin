<?php

namespace Irios\WooCommerce;

/**
 * IRIOS Media Display (image and video ads) via the [irios_display tag="TAG"] shortcode ([irios_banner], its 1.0 name,
 * still works): the tag is resolved in the settings (format, device, ad unit and auth) and the shortcode outputs a slot
 * that the JS fills with a single auction for all the placements of the page (with consent, the visitor's uids, and
 * the page type as context). With Display disabled, or the plugin not configured, the shortcode renders nothing.
 *
 * Placements with an automatic position (category: sidebar, inside the grid, after the products; product page: before
 * the description, before the related products) are printed hidden in the footer and the JS moves them into place,
 * looking for the theme's sidebar, grid, tabs or related products (themes do not always use the WooCommerce hooks).
 */
final class Banners {

	public const SHORTCODE        = 'irios_display';
	public const LEGACY_SHORTCODE = 'irios_banner';

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_shortcode( self::LEGACY_SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'wp_footer', array( self::class, 'positioned' ) );
	}

	/** Slots for the auto-placed placements on this page (category or product page). */
	public static function positioned(): void {
		$settings = Settings::load();
		if ( ! $settings->is_configured() || ! $settings->banners_enabled() ) {
			return;
		}
		foreach ( self::page_positions() as $position ) {
			foreach ( $settings->banners_at( $position ) as $banner ) {
				echo self::placeholder( true, $banner, $position ); // phpcs:ignore
			}
		}
	}

	/** @return string[] */
	private static function page_positions(): array {
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return Settings::CATEGORY_POSITIONS;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return Settings::PRODUCT_POSITIONS;
		}
		return array();
	}

	/**
	 * IRIOS page types (pageType) that a banner can set with page_type: those that need no search or category (the
	 * search, category and product pages already send theirs).
	 */
	public const SHORTCODE_PAGE_TYPES = array( 'HOMEPAGE', 'MERCHANDISING', 'CHECKOUT', 'CONFIRMATION', 'AI_ASSISTANT', 'PROMOTIONS' );

	/**
	 * [irios_display tag="home"]: the placement is resolved by its tag in the settings (format, device, ad unit…).
	 * Optional page_type="PROMOTIONS" (an IRIOS page type) for pages the plugin cannot classify (WordPress pages, blog):
	 * on shop pages (home, search, category, product, cart…) the page's own type is used.
	 */
	public static function shortcode( $atts ): string {
		$atts     = shortcode_atts(
			array(
				'tag'       => '',
				'page_type' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$settings = Settings::load();
		$banner   = $settings->banner( (string) $atts['tag'] );
		if ( ! $settings->is_configured() || ! $settings->banners_enabled() ) {
			return '';
		}
		if ( null === $banner ) {
			return '<!-- irios_display: there is no placement with the tag "' . esc_html( (string) $atts['tag'] ) . '" in the settings -->';
		}
		$html      = self::placeholder( true, $banner );
		$page_type = strtoupper( trim( (string) $atts['page_type'] ) );
		if ( in_array( $page_type, self::SHORTCODE_PAGE_TYPES, true ) ) {
			$html = str_replace( '<div class="irios-banner"', '<div class="irios-banner" data-page-type="' . $page_type . '"', $html );
		}
		return $html;
	}

	/**
	 * Slot of a placement ({tag, type, device, id, auth}); empty if disabled or the ad unit is not valid. With a
	 * position, it is hidden until the JS moves it into place. The JS requests it with its IRIOS ad unit type (banner or
	 * video) and leaves it out on the other devices.
	 *
	 * @param array<string,mixed> $banner
	 */
	public static function placeholder( bool $enabled, array $banner, string $position = '' ): string {
		$id   = trim( (string) ( $banner['id'] ?? '' ) );
		$auth = trim( (string) ( $banner['auth'] ?? '' ) );
		if ( ! $enabled || ! Settings::is_positive_int( $id ) || '' === $auth ) {
			return '';
		}
		$attributes = array(
			'data-ad-unit' => $id,
			'data-auth'    => $auth,
			'data-type'    => (string) ( $banner['type'] ?? 'banner' ),
			'data-device'  => (string) ( $banner['device'] ?? 'all' ),
			'data-tag'     => (string) ( $banner['tag'] ?? '' ),
		);
		if ( '' !== $position ) {
			$attributes['data-irios-position'] = $position;
		}
		$html = '<div class="irios-banner"';
		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES ) . '"';
		}
		return $html . ( '' !== $position ? ' hidden' : '' ) . '></div>';
	}
}
