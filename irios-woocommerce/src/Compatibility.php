<?php

namespace Irios\WooCommerce;

/**
 * WordPress themes tested with the plugin. Ads are placed in the HTML each theme renders (results grid, live search,
 * product page tabs…): with an untested theme some may not show up. The list comes from the compatibility matrix
 * (compat/ in the repo) and is checked against the active theme and its parent theme, so a child theme of a tested one
 * counts as tested.
 */
final class Compatibility {

	/**
	 * Theme slug (folder in wp-content/themes) → name. Those passing everything in compat/RESULTS.md, plus the
	 * supported paid themes, which the matrix cannot install.
	 */
	public const SUPPORTED_THEMES = array(
		'astra'            => 'Astra',
		'blocksy'          => 'Blocksy',
		'generatepress'    => 'GeneratePress',
		'kadence'          => 'Kadence',
		'neve'             => 'Neve',
		'oceanwp'          => 'OceanWP',
		'ridestyle'        => 'Ridestyle',
		'storefront'       => 'Storefront',
		'twentytwentyfive' => 'Twenty Twenty-Five',
	);

	/** Is the theme (or its parent theme) tested? */
	public static function is_supported( string $stylesheet, string $template ): bool {
		return isset( self::SUPPORTED_THEMES[ strtolower( $stylesheet ) ] ) || isset( self::SUPPORTED_THEMES[ strtolower( $template ) ] );
	}

	/** The active WordPress theme is tested. */
	public static function active_theme_supported(): bool {
		return self::is_supported( (string) get_stylesheet(), (string) get_template() );
	}

	/** "Name (slug)" of the active theme and, if it is a child theme, of its parent. */
	public static function theme_label(): string {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return '';
		}
		$theme = wp_get_theme();
		$label = $theme->get( 'Name' ) . ' (' . get_stylesheet() . ')';
		if ( get_template() !== get_stylesheet() ) {
			$parent = wp_get_theme( get_template() );
			$label .= ', ' . __( 'child theme of', 'irios-woocommerce' ) . ' ' . $parent->get( 'Name' ) . ' (' . get_template() . ')';
		}
		return $label;
	}

	/** Untested-theme warning for the settings screen; empty if the theme is tested. */
	public static function warning_html(): string {
		if ( self::active_theme_supported() ) {
			return '';
		}
		return sprintf(
			'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: name and slug of the active theme */
					__( 'Unsupported theme: %s.', 'irios-woocommerce' ),
					self::theme_label()
				)
			),
			esc_html__( 'This theme is not among the themes tested with Irios Media, so some ads (Sponsored Products in search or in the live search, or banners in automatic positions) may not be shown or may not be placed correctly. Please contact Irios support.', 'irios-woocommerce' )
		);
	}
}
