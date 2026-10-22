<?php

namespace Irios\WooCommerce;

/**
 * Bootstrap: registers the settings, the context endpoint, the storefront JS and the new-version notice.
 */
final class Plugin {

	public static function boot(): void {
		Updates::register();
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'notice_missing_woocommerce' ) );
			return;
		}

		add_filter(
			'woocommerce_integrations',
			static function ( array $integrations ): array {
				$integrations[] = Integration::class;
				return $integrations;
			}
		);
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		Events::register();
		Banners::register();
		Orders::register();
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		add_action( 'wp_head', array( Assets::class, 'anti_flicker' ), 1 );
		add_action( 'admin_notices', array( self::class, 'notice_configuration' ) );
	}

	private static function asesor_cookies_active(): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( 0 === strpos( (string) $plugin, 'asesor-cookies-para-la-ley-en-espana/' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function notice_missing_woocommerce(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Irios for WooCommerce requires WooCommerce to be active.', 'irios-woocommerce' ) . '</p></div>';
	}

	public static function notice_configuration(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = Settings::load();
		$url      = admin_url( 'admin.php?page=wc-settings&tab=integration&section=irios' );
		if ( ! $settings->is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Irios for WooCommerce is not configured: the JS domain or the Store ID is missing, so nothing is sent to Irios.', 'irios-woocommerce' ),
				esc_url( $url ),
				esc_html__( 'Configure', 'irios-woocommerce' )
			);
		} elseif ( Settings::CONSENT_ASESOR_COOKIES === $settings->consent_mode() && ! self::asesor_cookies_active() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Irios for WooCommerce uses the "Asesor de Cookies RGPD" banner, but it is not active: without it there is no consent, so only contextual ads are shown and no events are sent.', 'irios-woocommerce' )
			);
		} elseif ( Settings::CONSENT_WP_CONSENT_API === $settings->consent_mode() && ! Consent::api_available() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Irios for WooCommerce uses the WP Consent API, but it is not installed: without it there is no consent, so only contextual ads are shown and no events are sent. Install the "WP Consent API" plugin (and a compatible cookie banner) or change the consent mode.', 'irios-woocommerce' )
			);
		} elseif ( Settings::CONSENT_WP_CONSENT_API === $settings->consent_mode() && ! Consent::banner_registered() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Irios for WooCommerce: the WP Consent API is installed, but no cookie banner uses it. Without a banner there is no consent, so only contextual ads are shown and no events are sent. Activate a compatible banner (Complianz, CookieYes, Cookiebot…) or change the consent mode.', 'irios-woocommerce' )
			);
		}
	}
}
