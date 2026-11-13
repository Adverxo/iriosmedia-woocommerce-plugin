<?php
/**
 * Local Docker environment only: replaces the Irios scripts with stubs.
 *
 * - irios-tracker.js → window.IriosEvent stores events in window.__iriosEvents and logs them to the console.
 * - irios-loader.js  → window.IriosLoader (with getAds()) resolves ads with "winning" ads taken from the store itself
 *   (Store API, same search term; for a variable product its last variation is advertised), in the format of the
 *   real response (adUnitId, position, native.product.id, native.trackingUrls).
 *   With `irios_ads=13,10` in the URL those catalog ids (simple or variation) are advertised, in that order.
 * - IriosTracker     → logs load, view and click to the console.
 */
add_action(
	'wp_head',
	function () {
		?>
<script>
window.__iriosEvents = [];
window.IriosEvent = function ( e ) {
	window.__iriosEvents.push( e[ 0 ] );
	console.log( '[irios-stub]', JSON.stringify( e[ 0 ] ) );
};
window.IriosLoader = function ( config ) {
	console.log( '[irios-stub] IriosLoader', JSON.stringify( config ) );
	// Like the IRIOS loader: pageType is mandatory when a banner, video or product ad unit is present (not for push),
	// and some page types need a category or a search
	const pageTypes = [ 'HOMEPAGE', 'SEARCH', 'SEARCH_BAR', 'CATEGORY', 'PRODUCT_DETAIL', 'MERCHANDISING', 'CHECKOUT',
		'CONFIRMATION', 'AI_ASSISTANT', 'PROMOTIONS' ];
	const pageType = typeof config.pageType === 'string' ? config.pageType.toUpperCase() : null;
	const auctioned = ( config.adUnits || [] ).some( ( u ) => [ 'banner', 'video', 'product' ].includes( u.type ) );
	if ( auctioned && ( ! pageType || ! pageTypes.includes( pageType ) ) ) {
		throw new TypeError( 'pageType is mandatory when a banner, video or product adUnit is present' );
	}
	if ( [ 'CATEGORY', 'PRODUCT_DETAIL' ].includes( pageType ) && ! ( Array.isArray( config.category ) && config.category.length ) ) {
		throw new TypeError( 'category must be a non-empty array when pageType is ' + pageType );
	}
	if ( [ 'SEARCH', 'SEARCH_BAR' ].includes( pageType ) && ! config.searchQuery ) {
		throw new TypeError( 'searchQuery is mandatory when pageType is ' + pageType );
	}
	this.config = config;
	const unit = config.adUnits[ 0 ];
	config.adUnits.forEach( ( u ) => {
		if ( typeof u.auth !== 'string' ) {
			throw new TypeError( 'auth must be of type string' ); // like IriosAdUnit
		}
	} );
	if ( unit.type === 'push' ) {
		// Push: no auction; render() would ask for the browser permission (here it only logs it)
		this.adResponsesPromise = Promise.resolve( [] );
		return;
	}
	if ( unit.type === 'banner' || unit.type === 'video' ) {
		// Display: one test creative per ad unit (image or video, as its type), as the auction would respond.
		this.adResponsesPromise = Promise.resolve( config.adUnits.map( ( u, i ) => {
			const creative = { width: 970, height: 250, trackingUrls: { loadTrackingUrl: [ 'https://tracker.irios.test/' + u.type + '-load?au=' + u.id ],
				viewTrackingUrl: [ 'https://tracker.irios.test/' + u.type + '-view?au=' + u.id ], clickTrackingUrl: [ 'https://tracker.irios.test/' + u.type + '-click?au=' + u.id ] } };
			return Object.assign( {
				adUnitId: u.id, position: 0, requestId: 'stub-' + u.type + '-' + i,
				// "About this ad" (DSA): advertiser and targeting types, as returned by Irios
				advertiserName: 'Test Advertiser Ltd', brandName: 'Test Brand',
				targetingTypes: [ 'PAGE_TYPE' ].concat( ( config.category || [] ).length ? [ 'STORE_CATEGORY' ] : [] ),
			}, u.type === 'video' ? { video: creative } : { banner: creative } );
		} ) );
		return;
	}
	const forced = new URLSearchParams( location.search ).get( 'irios_ads' );
	// Sponsored Products: search products are split across the ad units, in order, maxAds each.
	const total = config.adUnits.reduce( ( sum, u ) => sum + ( u.maxAds || 0 ), 0 );
	const url = '/wp-json/wc/store/v1/products?per_page=' + total + '&search=' + encodeURIComponent( config.searchQuery );
	// Advertised product with the base catalog fields, as returned by Irios (id, name, price, imageUrl, clickUrl…).
	const toAd = ( p ) => ( {
		id: String( p.variations && p.variations.length ? p.variations[ p.variations.length - 1 ].id : p.id ),
		name: p.name,
		price: p.prices ? Number( p.prices.price ) / Math.pow( 10, p.prices.currency_minor_unit || 0 ) : undefined,
		imageUrl: p.images && p.images[ 0 ] ? p.images[ 0 ].src : undefined,
		clickUrl: p.permalink,
	} );
	const products = forced
		? Promise.all( forced.split( ',' ).map( ( x ) => x.trim() ).filter( Boolean ).slice( 0, total ).map(
			( id ) => fetch( '/wp-json/wc/store/v1/products/' + id ).then( ( r ) => r.json() ).then( toAd, () => ( { id } ) )
		) )
		: fetch( url ).then( ( r ) => r.json() ).then( ( list ) => list.reverse().map( toAd ) );
	this.adResponsesPromise = products
		.then( ( list ) => list.map( ( product, i ) => {
			const t = ( kind ) => [ 'https://tracker.irios.test/' + kind + '?ad=' + product.id ];
			let index = i;
			const owner = config.adUnits.find( ( u ) => index < u.maxAds || ( ( index -= u.maxAds ), false ) ) || unit;
			return {
				adUnitId: owner.id,
				position: index + 1,
				requestId: 'stub-' + i,
				advertiserName: 'Test Advertiser Ltd',
				brandName: 'Test Brand',
				targetingTypes: [ 'SEARCH' ],
				native: { product, trackingUrls: { loadTrackingUrl: t( 'load' ), viewTrackingUrl: t( 'view' ), clickTrackingUrl: t( 'click' ) } },
			};
		} ) );
};
// render(): renders into the parent of the calling <script> (document.currentScript), like the real loader.
window.IriosLoader.prototype.render = async function ( adUnitId ) {
	const parent = document.currentScript.parentElement;
	const unit = this.config.adUnits.find( ( u ) => u.id === adUnitId );
	if ( unit && unit.type === 'push' ) {
		console.log( '[irios-stub] render push', adUnitId, '(the real loader asks for the browser permission here)' );
		return;
	}
	const ads = ( ( await this.adResponsesPromise ) || [] ).filter( ( a ) => a.adUnitId === adUnitId && ( a.banner || a.video ) );
	const category = this.config.pageType + ' · ' + ( ( this.config.category || [] ).join( ' > ' ) || 'no category' );
	ads.forEach( ( ad ) => {
		const el = document.createElement( 'a' );
		el.href = '#';
		el.className = 'irios-stub-banner';
		el.style.cssText = 'display:flex;align-items:center;justify-content:center;flex-direction:column;aspect-ratio:970/250;width:100%;max-width:970px;background:repeating-linear-gradient(45deg,#ffd80a,#ffd80a 20px,#ffe766 20px,#ffe766 40px);color:#000;font:700 22px/1.3 Roboto,sans-serif;text-decoration:none;text-align:center';
		const creative = ad.banner || ad.video;
		if ( ad.video ) {
			el.style.background = '#111';
			el.style.color = '#fff';
		}
		el.innerHTML = ( ad.video ? '▶ TEST VIDEO' : 'TEST BANNER' ) + ' · ad unit ' + adUnitId + '<br><small style="font-weight:400;font-size:14px">context: ' + category + '</small>';
		parent.appendChild( el );
		window.IriosTracker( [ { element: el, 'tracking-load': creative.trackingUrls.loadTrackingUrl, 'tracking-view': creative.trackingUrls.viewTrackingUrl, 'tracking-click': creative.trackingUrls.clickTrackingUrl } ] );
	} );
	console.log( '[irios-stub] render banner', adUnitId, ads.length );
};
window.IriosLoader.prototype.getAds = async function ( adUnitId ) {
	const ads = ( await this.adResponsesPromise ) || [];
	return ads.filter( ( a ) => adUnitId === undefined || a.adUnitId === adUnitId ).sort( ( a, b ) => a.position - b.position );
};
window.IriosTracker = function ( units ) {
	units.forEach( ( u ) => {
		( u[ 'tracking-load' ] || [] ).forEach( ( x ) => console.log( '[irios-stub] tracking', x ) );
		if ( u[ 'tracking-view' ] ) {
			new IntersectionObserver( ( es, o ) => es.forEach( ( e ) => {
				if ( e.isIntersecting ) { u[ 'tracking-view' ].forEach( ( x ) => console.log( '[irios-stub] tracking', x ) ); o.disconnect(); }
			} ) ).observe( u.element );
		}
		if ( u[ 'tracking-click' ] ) {
			u.element.addEventListener( 'click', () => u[ 'tracking-click' ].forEach( ( x ) => console.log( '[irios-stub] tracking', x ) ) );
		}
	} );
};
</script>
		<?php
	},
	1
);
