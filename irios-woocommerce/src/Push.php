<?php

namespace Irios\WooCommerce;

/**
 * IRIOS Media push notifications: serves the service worker at the root of the site (PATH), which a service worker
 * needs to receive the notifications of the whole site (a file under /wp-content/plugins/ could only cover that
 * folder). It is answered by WordPress itself, without rewrite rules: the request reaches index.php like any other.
 *
 * In IRIOS Media, the push ad unit must point to this service worker ("/irios-sw.js", or with the site's subfolder).
 * The subscription itself is made by the IRIOS loader from the storefront JS, only with consent and at the moment set
 * in the settings.
 */
final class Push {

	public const PATH = 'irios-sw.js';

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_serve' ), 0 );
	}

	/** Public URL of the service worker. */
	public static function url(): string {
		return home_url( '/' . self::PATH );
	}

	/** Scope of the service worker: the whole site (its home path). */
	public static function scope(): string {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return '' === $path ? '/' : trailingslashit( $path );
	}

	public static function maybe_serve(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( (string) wp_parse_url( $uri, PHP_URL_PATH ) !== self::scope() . self::PATH ) {
			return;
		}
		$settings = Settings::load();
		if ( ! $settings->is_configured() || null === $settings->push() ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput -- JavaScript; the values are JSON-encoded
		exit;
	}

	/** The service worker, with the site's name and icon as fallback for notifications without them. */
	public static function script(): string {
		$defaults = array(
			'title' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'icon'  => function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 192 ) : '',
		);
		$source = (string) file_get_contents( dirname( IRIOS_WC_FILE ) . '/assets/js/irios-sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 'self.IRIOS_DEFAULTS = ' . wp_json_encode( $defaults ) . ";\n" . $source;
	}
}
