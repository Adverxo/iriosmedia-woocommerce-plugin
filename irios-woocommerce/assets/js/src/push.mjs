import { cfg, page } from './config.mjs';
import { adUser, hasConsent } from './consent.mjs';
import { loadLoader } from './tracker.mjs';

// ---- Push notifications ---------------------------------------------------------------------------------------

export var PUSH_PAGES_KEY = 'irios_push_pages'; // pages seen in this visit (sessionStorage)
export var PUSH_VISIT_KEY = 'irios_push_visit'; // already tried in this visit (sessionStorage)
export var PUSH_ASKED_KEY = 'irios_push_asked'; // when the browser prompt was last shown (localStorage)
export var DAY_MS = 24 * 3600 * 1000;

export function stored( storage, key ) {
	try {
		return window[ storage ].getItem( key );
	} catch ( e ) {
		return null;
	}
}

export function store( storage, key, value ) {
	try {
		window[ storage ].setItem( key, value );
	} catch ( e ) {}
}

export function pushSupported() {
	return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * With consent, on every page: counts the page and asks the shopper to subscribe to push notifications at the
 * moment set in the settings (cfg.push.trigger): after a purchase (order received page) or after a number of pages
 * of the visit. At most once per visit and, if the shopper did not answer, not again until cfg.push.repeatDays have
 * passed (0: on every visit). Blocked in the browser: nothing. The IRIOS loader does the subscription (render() of
 * the push ad unit), once the service worker served at the site root is registered (its render() waits for one).
 */
export function pushOnPage() {
	var push = cfg.push;
	if ( ! push || ! hasConsent() || ! pushSupported() ) {
		return;
	}
	try {
		var pages = Number( stored( 'sessionStorage', PUSH_PAGES_KEY ) || 0 ) + 1;
		store( 'sessionStorage', PUSH_PAGES_KEY, String( pages ) );
		if ( window.Notification.permission === 'denied' || stored( 'sessionStorage', PUSH_VISIT_KEY ) ) {
			return;
		}
		var asked = Number( stored( 'localStorage', PUSH_ASKED_KEY ) || 0 );
		if ( window.Notification.permission === 'default' && Date.now() - asked < Number( push.repeatDays ) * DAY_MS ) {
			return;
		}
		var moment = push.trigger === 'after_pages'
			? pages >= Number( push.afterPages )
			: page.pageType === 'CONFIRMATION';
		if ( ! moment ) {
			return;
		}
		store( 'sessionStorage', PUSH_VISIT_KEY, '1' );
		if ( window.Notification.permission === 'default' ) {
			store( 'localStorage', PUSH_ASKED_KEY, String( Date.now() ) );
		}
		navigator.serviceWorker.register( push.serviceWorker, { scope: push.scope } )
			.then( function () {
				return loadLoader();
			} )
			.then( function () {
				if ( ! window.IriosLoader || ! hasConsent() ) {
					return;
				}
				window.__iriosWooPush = new window.IriosLoader( Object.assign( adUser(), {
					adUnits: [ { id: Number( push.id ), auth: push.auth, type: 'push' } ],
				} ) );
				// The IRIOS loader renders from a <script> (document.currentScript)
				var script = document.createElement( 'script' );
				script.text = 'window.__iriosWooPush && window.__iriosWooPush.render(' + Number( push.id ) + ');';
				document.body.appendChild( script );
			} )
			.catch( function () {} );
	} catch ( e ) {}
}

/** Consent withdrawn: the push subscription of this browser is cancelled. */
export function cancelPush() {
	if ( ! cfg.push || ! ( 'serviceWorker' in navigator ) ) {
		return;
	}
	try {
		navigator.serviceWorker.getRegistration( cfg.push.serviceWorker )
			.then( function ( registration ) {
				return registration && registration.pushManager.getSubscription();
			} )
			.then( function ( subscription ) {
				return subscription && subscription.unsubscribe();
			} )
			.catch( function () {} );
	} catch ( e ) {}
}

