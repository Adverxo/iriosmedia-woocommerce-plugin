import { cfg, isConfigured } from './config.mjs';
import { asesorConsent, hasConsent } from './consent.mjs';
import { renderAds, start, stop } from './tracker.mjs';
// Only for what it hooks on load: the listeners that watch the theme's search box
import './live-search.mjs';

/**
 * What runs when the page loads and when consent changes. Without the IRIOS Media domain and the store the plugin
 * does nothing at all, so the store is exactly as it would be without it.
 */
if ( ! isConfigured() ) {
	// Nothing is hooked: no listeners, no ads, no requests
} else {


// ---- Startup and consent changes ----------------------------------------------------------------------------------

// The banner may define the consent type after this script loads.
document.addEventListener( 'wp_consent_type_defined', start );

// Asesor de Cookies fires no events: poll its cookie to start on accept and stop on withdrawal.
if ( cfg.consentMode === 'asesor_cookies' ) {
	var lastConsent = asesorConsent();
	window.setInterval( function () {
		var current = asesorConsent();
		if ( current === lastConsent ) {
			return;
		}
		lastConsent = current;
		if ( current ) {
			start();
		} else {
			stop();
		}
	}, 1000 );
}

document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
	var changes = e.detail || {};
	if ( changes[ cfg.consentCategory ] === 'allow' ) {
		start();
	} else if ( changes[ cfg.consentCategory ] === 'deny' ) {
		stop();
	}
} );

if ( hasConsent() ) {
	start();
} else {
	// Without consent, contextual ads only: no tracker, no events, no identifiers
	renderAds();
}
}
