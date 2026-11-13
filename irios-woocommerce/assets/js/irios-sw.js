/*
 * IRIOS Media push notifications: service worker served at the site root by the plugin (src/Push.php), for the
 * shoppers who subscribe with their permission. It only shows the notifications sent by IRIOS Media and opens their
 * link when clicked: it caches nothing, intercepts no request of the site and loads no third-party code.
 * self.IRIOS_DEFAULTS (prepended by Push::script()): the site's name and icon, for notifications without them.
 */
'use strict';

var VERSION = '1';
var DEFAULTS = self.IRIOS_DEFAULTS || {};

/** Image URL with the version, so that the browser does not use an old cached one. */
function withVersion( url ) {
	if ( typeof url !== 'string' || url === '' ) {
		return undefined;
	}
	return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'v=' + VERSION;
}

self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil( self.clients.claim() );
} );

self.addEventListener( 'push', function ( event ) {
	var data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch ( e ) {
		return;
	}
	// IRIOS format (tit, bdy, img, icn, bdg, tag, ntf) and the classic one (title, body, image, icon)
	var tasks = [];
	if ( typeof data.ntf === 'string' && data.ntf.indexOf( 'https://' ) === 0 ) {
		// Tells IRIOS that the notification has been shown
		tasks.push( fetch( data.ntf, { cache: 'no-store', credentials: 'omit' } ).catch( function () {} ) );
	}
	tasks.push(
		self.registration.showNotification( data.tit || data.title || DEFAULTS.title || '', {
			body: data.bdy || data.body || '',
			image: withVersion( data.img || data.image ),
			icon: withVersion( data.icn || data.icon ) || DEFAULTS.icon || undefined,
			badge: withVersion( data.bdg ),
			tag: String( data.tag || 'irios' ),
			renotify: true,
			requireInteraction: true,
			data: { url: data.url },
		} )
	);
	event.waitUntil( Promise.all( tasks ) );
} );

self.addEventListener( 'notificationclick', function ( event ) {
	event.notification.close();
	var url = event.notification.data && event.notification.data.url;
	// Only https links (the IRIOS one records the click and goes to the product or the brand's site)
	if ( typeof url !== 'string' || url.indexOf( 'https://' ) !== 0 ) {
		return;
	}
	event.waitUntil( self.clients.openWindow( url ).then( function ( client ) {
		return client ? client.focus() : null;
	} ) );
} );
