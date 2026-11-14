import { SESSION_KEY, cfg, page, state } from './config.mjs';
import { hasConsent } from './consent.mjs';
import { renderBanners } from './display.mjs';
import { flushCartEvents, pageEvents } from './events.mjs';
import { enqueue, send } from './events-api.mjs';
import { fetchContext, sessionId } from './identity.mjs';
import { cancelPush, pushOnPage } from './push.mjs';
import { renderSearchAds } from './search-ads.mjs';

// ---- Irios tracker ----------------------------------------------------------------------------------------------

export function loadScript( src ) {
	return new Promise( function ( resolve, reject ) {
		var script = document.createElement( 'script' );
		script.src = src;
		script.async = true;
		script.onload = resolve;
		script.onerror = reject;
		document.head.appendChild( script );
	} );
}

/** irios-loader.js, downloaded once per page (search ads, live search and banners). */
export function loadLoader() {
	if ( ! state.loaderPromise ) {
		state.loaderPromise = window.IriosLoader
			? Promise.resolve()
			: loadScript( 'https://' + cfg.jsDomain + '/v1/irios-loader.js' );
	}
	return state.loaderPromise;
}

/** Shows the results hidden in <head> while the ads were loading (see Assets::anti_flicker). */
export function revealResults() {
	document.documentElement.classList.remove( 'irios-ads-pending' );
}

export function loadTracker() {
	if ( ! state.trackerPromise ) {
		state.trackerPromise = window.IriosEvent
			? Promise.resolve()
			: loadScript( 'https://' + cfg.jsDomain + '/v1/irios-tracker.js' );
	}
	return state.trackerPromise;
}

export function start() {
	if ( state.started || ! hasConsent() ) {
		return;
	}
	state.started = true;
	state.sessionId = sessionId();

	pageEvents().forEach( enqueue );

	// The (heavy) loader downloads alongside the context and tracker, not after them: ads show up sooner.
	if ( page.ads || document.querySelector( '.irios-banner[data-ad-unit]' ) ) {
		loadLoader().catch( function () {} );
	}

	// The context only returns uids for logged-in customers (body.logged-in, which a page cached for anonymous users
	// lacks): for anonymous visitors ads are requested right away, without waiting for it (≈125 ms saved).
	var anonymous = ! document.body.classList.contains( 'logged-in' );
	state.uids = [];
	if ( anonymous ) {
		renderAds();
	}

	Promise.all( [ fetchContext(), loadTracker() ] )
		.then( function ( results ) {
			if ( ! state.started ) {
				return; // consent withdrawn while loading
			}
			state.uids = results[ 0 ].uids;
			state.ready = true;
			var pending = state.queue.concat( results[ 0 ].events );
			state.queue = [];
			pending.forEach( send );
			if ( state.flushWhenReady ) {
				state.flushWhenReady = false;
				flushCartEvents();
			}
			if ( ! anonymous ) {
				renderAds();
			}
			pushOnPage();
			document.dispatchEvent( new CustomEvent( 'irios:ready', { detail: { uids: state.uids, sessionId: state.sessionId } } ) );
		} )
		.catch( function () {
			// Tracker blocked (adblock) or down: nothing is sent on this page.
			revealResults();
			state.started = false;
			state.queue = [];
		} );
}

export function renderAds() {
	renderSearchAds();
	renderBanners();
}

export function stop() {
	state.started = false;
	state.ready = false;
	state.queue = [];
	state.uids = [];
	state.recent = {};
	state.lastVariation = null;
	try {
		// Irios cookies on the store (set by its script with Path=/); its own-domain one (irs3P) is unreachable
		[ 'irs1P', 'irsUserData' ].forEach( function ( name ) {
			document.cookie = name + '=; Max-Age=0; Path=/';
		} );
		window.sessionStorage.removeItem( SESSION_KEY );
	} catch ( e ) {}
	cancelPush();
}

