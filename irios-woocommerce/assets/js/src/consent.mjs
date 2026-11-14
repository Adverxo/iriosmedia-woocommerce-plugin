import { cfg, state } from './config.mjs';
import { readCookie } from './identity.mjs';

// ---- Consent ----------------------------------------------------------------------------------------------------

export function consentType() {
	if ( typeof window.wp_consent_type !== 'undefined' ) {
		return window.wp_consent_type;
	}
	return window.wp_fallback_consent_type;
}

/**
 * Without a banner that defines the consent type, the WP Consent API treats everything as consented; not here.
 */
export function hasConsent() {
	if ( cfg.consentMode === 'always' ) {
		return true;
	}
	if ( cfg.consentMode === 'asesor_cookies' ) {
		return asesorConsent();
	}
	return !! consentType() && typeof window.wp_has_consent === 'function' && window.wp_has_consent( cfg.consentCategory );
}

/**
 * Identification for requesting and tracking ads. With consent, uids and session; without it, contextual ads
 * (based on the search or page): consent.gdpr = false and the Irios script neither reads nor stores cookies or IDs.
 */
export function adUser() {
	return hasConsent()
		? { uids: state.uids, sessionId: state.sessionId, consent: { gdpr: true } }
		: { consent: { gdpr: false } };
}

/** "Asesor de Cookies RGPD" banner: decision stored in the JSON cookie `cdp_cookie_consent` (or the one it configures). */
export function asesorConsent() {
	var name = ( window.cdpCookiesConfig && window.cdpCookiesConfig.cookieName ) || cfg.asesorCookie;
	var raw = readCookie( name );
	if ( ! raw ) {
		return false;
	}
	try {
		return JSON.parse( raw )[ cfg.consentCategory ] === true;
	} catch ( e ) {
		return false;
	}
}

