/**
 * What PHP printed in the page (Assets::config) and what page it is. It travels in HTML that may be cached, so it is
 * only about the store and the page, never about the visitor: that comes from /wp-json/irios/v1/context.
 */

export var cfg = window.iriosWooConfig || {};

/** Without the IRIOS Media domain and the store, the plugin does nothing at all (startup.mjs checks it). */
export function isConfigured() {
	return !! ( window.iriosWooConfig && cfg.jsDomain && cfg.storeId );
}

export var SESSION_KEY = 'irios_session_id';
export var DEDUP_MS = 2000;

export var state = {
	started: false,
	ready: false,
	queue: [],
	// uids of the logged-in customer (CRM, email, phone). The browser one (irs1P cookie) is added by the Irios script to
	// its requests; the server stores it on the order (Orders.php)
	uids: [],
	sessionId: null,
	trackerPromise: null,
	loaderPromise: null,
	recent: {},
	lastVariation: null,
	flushWhenReady: false,
};
export var page = window.iriosWooPage || { type: 'page' };

/** Debug mode, same as Irios: ?irios_debug=1, localStorage irios_debug=1 or window.IRIOS_DEBUG. */
export var DEBUG = ( function () {
	try {
		return (
			/[?&]irios_debug=1\b/.test( window.location.search ) ||
			window.localStorage.getItem( 'irios_debug' ) === '1' ||
			window.IRIOS_DEBUG === true
		);
	} catch ( e ) {
		return window.IRIOS_DEBUG === true;
	}
} )();

export function debug() {
	if ( DEBUG && window.console ) {
		window.console.log.apply( window.console, [ '[irios-woo]' ].concat( Array.prototype.slice.call( arguments ) ) );
	}
}

/**
 * Debug mode: warning (once per page) that the plugin cannot find where to place an ad in the theme. Without it
 * the ad simply does not show up; this reveals which theme or page is not supported.
 */
export var warned = {};
export function warnTheme( key, message ) {
	if ( ! DEBUG || ! window.console || warned[ key ] ) {
		return;
	}
	warned[ key ] = true;
	window.console.warn( '[irios-woo] ' + message + ' (theme: ' + ( cfg.theme || '?' ) + ')' );
}

debug( 'IRIOS Media for WooCommerce ' + ( cfg.version || '?' ) );
if ( DEBUG && window.performance && typeof window.performance.setResourceTimingBufferSize === 'function' ) {
	// With many images the buffer (250 by default) fills up and the /v1/ads call would be missing from the breakdown.
	window.performance.setResourceTimingBufferSize( 5000 );
}

export function now() {
	return window.performance && window.performance.now ? window.performance.now() : Date.now();
}

export function ms( value ) {
	return Math.round( value ) + ' ms';
}

/**
 * Debug mode: one line per ad request, covering only what depends on Irios.
 *   [irios-woo] ad unit 1001 · search "stick" · Irios answered in 411 ms with 1 ad · ad visible 967 ms after the page started loading
 * - Irios answered in: from requesting the ads from the loader until Irios returns them (the loader's preparation
 *   before the request, plus /v1/ads).
 * - irios-loader.js ready in: how long the loader takes to be ready before requesting (from the browser cache or
 *   downloaded, plus execution time); only if it was not already loaded.
 * - rendered in: from the Irios response until the ad is rendered (for Sponsored Products it includes fetching the
 *   WooCommerce product card; for banners, the time the loader takes to render the creative).
 * - ad visible … after the page started loading: when the customer sees the ad, counted from the start of the
 *   page load (not for live search, which depends on when the user types).
 */
/**
 * Breakdown of "Irios answered in" using the browser's network timings: time the loader spends preparing the
 * request (including the /iuid call), /v1/ads and the time spent processing the response. Empty if not found.
 */
export function answerBreakdown( from, to ) {
	if ( ! window.performance || typeof window.performance.getEntriesByType !== 'function' ) {
		return '';
	}
	var entries = window.performance.getEntriesByType( 'resource' );
	var ads = entries.filter( function ( e ) {
		return /\/v1\/ads\b/.test( e.name ) && e.startTime >= from - 1 && e.responseEnd <= to + 1;
	} ).pop();
	if ( ! ads ) {
		return '';
	}
	var iuid = entries.filter( function ( e ) {
		return /\/iuid\b/.test( e.name ) && e.startTime >= from - 1 && e.responseEnd <= ads.startTime + 1;
	} ).pop();
	return ' (before the request ' + ms( ads.startTime - from ) + ( iuid ? ', incl. /iuid ' + ms( iuid.duration ) : '' ) +
		' · /v1/ads ' + ms( ads.responseEnd - ads.startTime ) + ' · after the response ' + ms( to - ads.responseEnd ) + ')';
}

/** "irios-loader.js ready in 55 ms (from cache)": where the loader came from, based on the browser's network timings. */
export function loaderReady( loaderMs ) {
	var entry = window.performance && typeof window.performance.getEntriesByType === 'function'
		? window.performance.getEntriesByType( 'resource' ).filter( function ( e ) {
			return /\/irios-loader\.js/.test( e.name );
		} ).pop()
		: null;
	var source = '';
	if ( entry ) {
		var fetchMs = entry.responseEnd - entry.startTime;
		if ( entry.decodedBodySize > 0 ) {
			// With Timing-Allow-Origin on the response the browser tells if it came from cache (0 bytes transferred).
			source = 0 === entry.transferSize ? ' (from browser cache)' : ' (downloaded in ' + ms( fetchMs ) + ')';
		} else {
			// Without that header only the duration is known: ≈0 means memory cache; otherwise disk cache or network (the
			// Size column in the Network tab tells which).
			source = fetchMs < 5 ? ' (from memory cache)' : ' (fetched in ' + ms( fetchMs ) + ')';
		}
	}
	return 'irios-loader.js ready in ' + ms( loaderMs ) + source;
}

/** "ad unit 1" or "ad units 1, 2": log label for the ad units of an auction. */
export function unitsLabel( units ) {
	var ids = ( units || [] ).map( function ( u ) {
		return u.adUnit;
	} );
	return 'ad unit' + ( ids.length > 1 ? 's ' : ' ' ) + ids.join( ', ' );
}

export function debugTiming( label, where, timing, visible, pageTime ) {
	if ( ! DEBUG || timing.logged ) {
		return;
	}
	timing.logged = true;
	var winners = timing.winners || 0;
	var parts = [ label, where ];
	if ( timing.loader > 5 ) {
		parts.push( loaderReady( timing.loader ) );
	}
	parts.push(
		'Irios answered in ' + ms( timing.auction || 0 ) + answerBreakdown( timing.auctionStart, timing.answeredAt ) +
			' with ' + ( winners ? winners + ' ad' + ( winners > 1 ? 's' : '' ) : 'no ads' )
	);
	if ( visible ) {
		if ( timing.answeredAt ) {
			parts.push( 'rendered in ' + ms( now() - timing.answeredAt ) );
		}
		if ( pageTime ) {
			parts.push( 'ad visible ' + ms( now() ) + ' after the page started loading' );
		}
	}
	debug( parts.join( ' · ' ) );
}

