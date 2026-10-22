<?php

namespace Irios\WooCommerce;

/**
 * Loads the storefront JS. Irios scripts are NOT enqueued here: the plugin JS loads them itself once there is consent.
 * The config printed on the page does not depend on the visitor (the page may be cached).
 */
final class Assets {

	public const HANDLE = 'irios-woocommerce';

	public static function enqueue(): void {
		$settings = Settings::load();
		if ( ! $settings->is_configured() || is_admin() ) {
			return;
		}

		$deps = array();
		if ( Settings::CONSENT_WP_CONSENT_API === $settings->consent_mode() && wp_script_is( 'wp-consent-api', 'registered' ) ) {
			$deps[] = 'wp-consent-api';
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/js/irios-woocommerce.js', IRIOS_WC_FILE ),
			$deps,
			// Version + file mtime: a JS change is not stuck in the browser or CDN cache.
			IRIOS_WC_VERSION . '.' . (string) filemtime( dirname( IRIOS_WC_FILE ) . '/assets/js/irios-woocommerce.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script( self::HANDLE, 'iriosWooConfig', self::config( $settings ) );
		wp_localize_script( self::HANDLE, 'iriosWooPage', Events::page_data( $settings ) );
	}

	/** Maximum time search results stay hidden while waiting for the ads (ms). */
	public const ANTI_FLICKER_MS = 1500;

	/** Result grids: classic shop, blocks and blog-style search. */
	private const RESULTS_SELECTOR = '.products, .wc-block-product-template, .list-post-wrap';

	/**
	 * In the <head> of a search with ads: hides the results until the ads arrive (the JS removes the class) or for at
	 * most ANTI_FLICKER_MS. With or without consent: without it there are ads too (contextual). Without this, results
	 * are painted and the ad pushes them down when it arrives. Must be inline in <head>: the plugin JS runs after the
	 * first paint.
	 */
	public static function anti_flicker(): void {
		$settings = Settings::load();
		if ( ! $settings->is_configured() || ! $settings->search_ads_enabled() || ! is_search() || 'product' !== get_query_var( 'post_type' ) ) {
			return;
		}
		echo self::anti_flicker_html(); // phpcs:ignore
	}

	public static function anti_flicker_html(): string {
		$style = sprintf(
			'%1$s{transition:opacity .2s ease-in}html.irios-ads-pending :is(%1$s){opacity:0}',
			self::RESULTS_SELECTOR
		);
		$script = sprintf(
			'(function(d){var h=d.documentElement;h.classList.add("irios-ads-pending");'
			. 'setTimeout(function(){h.classList.remove("irios-ads-pending")},%d)})(document);',
			self::ANTI_FLICKER_MS
		);
		return '<style id="irios-anti-flicker">' . $style . '</style><script id="irios-anti-flicker-js">' . $script . '</script>' . "\n";
	}

	/** @return array<string,mixed> */
	public static function config( Settings $settings ): array {
		return array(
			'version'         => IRIOS_WC_VERSION,
			'jsDomain'        => $settings->js_domain(),
			'storeId'         => (string) $settings->store_id(),
			'consentMode'     => $settings->consent_mode(),
			'consentCategory' => Consent::CATEGORY,
			'asesorCookie'    => Consent::ASESOR_COOKIE,
			'contextUrl'      => esc_url_raw( rest_url( 'irios/v1/context' ) ),
			'restNonce'       => wp_create_nonce( 'wp_rest' ),
			'cardsUrl'        => esc_url_raw( rest_url( 'irios/v1/cards' ) ),
			'storeApiUrl'     => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'sponsoredLabel'  => $settings->sponsored_label(),
			// To render an ad from Irios data if WordPress does not return its card.
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR',
			'productBase'     => self::product_base(),
			'liveSearchAds'   => self::live_search_ads( $settings ),
			'bannersEnabled'  => $settings->banners_enabled(),
			'adInfo'          => self::ad_info_texts(),
			// Active theme (and its parent), for the debug-mode compatibility warnings
			'theme'           => Compatibility::theme_label(),
		);
	}

	/**
	 * "About this ad" texts (DSA, art. 26.1): the reason per Irios targeting type (targetingTypes) and the advertiser
	 * (advertiserName). With profiling, links to the privacy policy to change it.
	 *
	 * @return array<string,mixed>
	 */
	private static function ad_info_texts(): array {
		return array(
			'button'      => __( 'Information about this ad', 'irios-woocommerce' ),
			'title'       => __( 'About this ad', 'irios-woocommerce' ),
			'why'         => __( 'Why am I seeing this ad?', 'irios-woocommerce' ),
			'because'     => __( 'You are seeing this ad because of:', 'irios-woocommerce' ),
			'noTargeting' => __( 'The ad space of the store where it appears: it does not use information about you.', 'irios-woocommerce' ),
			'reasons'     => array(
				'SEARCH'              => __( 'The search terms you have used.', 'irios-woocommerce' ),
				'STORE_CATEGORY'      => __( 'The content or products you are viewing on this page.', 'irios-woocommerce' ),
				'LOCATION'            => __( 'Your approximate location (country).', 'irios-woocommerce' ),
				'LANGUAGE'            => __( 'Your language.', 'irios-woocommerce' ),
				'DEVICE'              => __( 'Your device, operating system, browser or carrier.', 'irios-woocommerce' ),
				'CUSTOM_PARAM'        => __( 'Information about you held by the store.', 'irios-woocommerce' ),
				'SEGMENT'             => __( 'Your activity and history in this store.', 'irios-woocommerce' ),
				'ADVERTISER_AUDIENCE' => __( 'Your previous interaction with the advertiser.', 'irios-woocommerce' ),
			),
			'profiling'   => __( 'Information about you was used to choose it. You can change this as explained in the', 'irios-woocommerce' ),
			'privacy'     => __( 'privacy policy', 'irios-woocommerce' ),
			'privacyUrl'  => function_exists( 'get_privacy_policy_url' ) ? esc_url_raw( get_privacy_policy_url() ) : '',
			'advertiser'  => __( 'About the advertiser', 'irios-woocommerce' ),
			'name'        => __( 'Advertiser name', 'irios-woocommerce' ),
			'payer'       => __( 'Who paid for this ad', 'irios-woocommerce' ),
			'unknown'     => __( 'Not available', 'irios-woocommerce' ),
			'ok'          => __( 'Got it', 'irios-woocommerce' ),
		);
	}

	/** Product page base path (/product/), to recognise product links on the page. */
	private static function product_base(): string {
		$structure = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
		$slug      = trim( (string) ( $structure['product_rewrite_slug'] ?? 'product' ), '/' );
		return '/' . $slug . '/';
	}

	/**
	 * Sponsored Products in the theme's live search (results as you type): the same slots as the search page, each ad
	 * at its position in the list. Null if ads are disabled or the language is not valid.
	 */
	private static function live_search_ads( Settings $settings ): ?array {
		$language = Settings::bcp47_language( determine_locale() );
		if ( ! $settings->search_ads_enabled() || null === $language ) {
			return null;
		}
		return array(
			'units'    => $settings->sponsored_units(),
			'language' => $language,
		);
	}
}
