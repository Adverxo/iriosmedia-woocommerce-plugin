<?php

namespace Irios\WooCommerce;

/**
 * Server-side visitor consent. In WP Consent API mode, without the API installed or without a cookie banner that uses
 * it there is no consent: nothing is tracked by default.
 *
 * "Asesor de Cookies RGPD" mode: the banner stores the choice in the `cdp_cookie_consent` cookie as JSON
 * (`{"necessary":true,"analytics":false,"marketing":true,"personalization":false,…}`); only `true` in the configured
 * category counts as consent.
 */
final class Consent {

	public const ASESOR_COOKIE = 'cdp_cookie_consent';

	/** Cookie category the visitor must accept: Irios does advertising and audiences. It exists under this name in
	 *  the WP Consent API and in Asesor de Cookies RGPD. */
	public const CATEGORY = 'marketing';

	/** "Asesor de Cookies RGPD" banner choice for a category. No cookie or unreadable: no consent. */
	public static function asesor_allows( ?string $raw, string $category ): bool {
		if ( null === $raw || '' === $raw ) {
			return false;
		}
		$consent = json_decode( $raw, true );
		if ( ! is_array( $consent ) ) {
			$consent = json_decode( rawurldecode( $raw ), true );
		}
		return is_array( $consent ) && true === ( $consent[ $category ] ?? null );
	}

	public static function api_available(): bool {
		return function_exists( 'wp_has_consent' );
	}

	/** A compatible cookie banner has registered its consent type (optin, optout…). */
	public static function banner_registered(): bool {
		return function_exists( 'wp_get_consent_type' ) && '' !== (string) wp_get_consent_type();
	}

	/**
	 * With no banner registered, the WP Consent API treats everything as consented ("no consent management"): not here.
	 * There is consent only if a banner manages it and grants it.
	 */
	public static function granted( Settings $settings ): bool {
		if ( Settings::CONSENT_ALWAYS === $settings->consent_mode() ) {
			return true;
		}
		if ( Settings::CONSENT_ASESOR_COOKIES === $settings->consent_mode() ) {
			$raw = isset( $_COOKIE[ self::ASESOR_COOKIE ] ) ? wp_unslash( (string) $_COOKIE[ self::ASESOR_COOKIE ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validated when decoded
			return self::asesor_allows( $raw, self::CATEGORY );
		}
		return self::api_available() && self::banner_registered() && (bool) wp_has_consent( self::CATEGORY );
	}
}
