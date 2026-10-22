<?php

namespace Irios\WooCommerce;

/**
 * Irios banners via the [irios_banner tag="TAG"] shortcode (the tag is resolved in the settings: ad unit and auth): it
 * outputs a slot that the JS fills with a single
 * auction for all banners on the page (with consent, the visitor's uids and the category as context).
 * With the Banners section disabled, or the plugin not configured, the shortcode renders nothing.
 *
 * Banners with automatic placement (at the end of a category listing, before the description on the product page): they
 * are printed hidden in the footer and the JS moves them into place, looking for the theme's grid or tabs (themes do
 * not always use the WooCommerce hooks: some render the listing with their own template).
 */
final class Banners {

	public const SHORTCODE = 'irios_banner';

	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'wp_footer', array( self::class, 'positioned' ) );
	}

	/** Slots for the auto-placed banners on this page (category or product page). */
	public static function positioned(): void {
		$settings = Settings::load();
		if ( ! $settings->is_configured() || ! $settings->banners_enabled() ) {
			return;
		}
		$position = self::page_position();
		foreach ( $settings->banners_at( $position ) as $banner ) {
			echo self::placeholder( true, (string) $banner['id'], $banner['auth'], $position ); // phpcs:ignore
		}
	}

	private static function page_position(): string {
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return Settings::POSITION_CATEGORY_AFTER_PRODUCTS;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION;
		}
		return Settings::POSITION_NONE;
	}

	/** [irios_banner tag="portada"]: the banner is resolved by its tag in the settings (id, ad unit auth…). */
	public static function shortcode( $atts ): string {
		$atts     = shortcode_atts( array( 'tag' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$settings = Settings::load();
		$banner   = $settings->banner( (string) $atts['tag'] );
		if ( ! $settings->is_configured() || ! $settings->banners_enabled() ) {
			return '';
		}
		if ( null === $banner ) {
			return '<!-- irios_banner: there is no banner with the tag "' . esc_html( (string) $atts['tag'] ) . '" in the settings -->';
		}
		return self::placeholder( true, (string) $banner['id'], $banner['auth'] );
	}

	/**
	 * Banner slot; empty if disabled or the ad unit is not valid. With a position, it is hidden until the JS moves it
	 * into place.
	 */
	public static function placeholder( bool $enabled, string $id, string $auth, string $position = '' ): string {
		$id   = trim( $id );
		$auth = trim( $auth );
		if ( ! $enabled || ! Settings::is_positive_int( $id ) || '' === $auth ) {
			return '';
		}
		if ( '' !== $position ) {
			return sprintf(
				'<div class="irios-banner" data-ad-unit="%s" data-auth="%s" data-irios-position="%s" hidden></div>',
				htmlspecialchars( $id, ENT_QUOTES ),
				htmlspecialchars( $auth, ENT_QUOTES ),
				htmlspecialchars( $position, ENT_QUOTES )
			);
		}
		return sprintf(
			'<div class="irios-banner" data-ad-unit="%s" data-auth="%s"></div>',
			htmlspecialchars( $id, ENT_QUOTES ),
			htmlspecialchars( $auth, ENT_QUOTES )
		);
	}
}
