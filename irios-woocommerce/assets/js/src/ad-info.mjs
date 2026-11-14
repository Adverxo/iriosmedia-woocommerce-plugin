import { cfg } from './config.mjs';
import { adUser } from './consent.mjs';
import { CART_LINK_SELECTOR, TITLE_SELECTOR, nativeOf } from './search-ads.mjs';
import { loadTracker } from './tracker.mjs';

// ---- "About this ad" (DSA, Art. 26(1)) --------------------------------------------------------------------------

/** Criteria that use information about the person: in that case they are told how to change it. */
export var PROFILING = [ 'CUSTOM_PARAM', 'SEGMENT', 'ADVERTISER_AUDIENCE' ];

/**
 * ⓘ button next to the ad label. It may end up inside the product page link or the element with Irios click
 * tracking: it handles its click on its own node and stops propagation (no navigation and no ad click counted).
 */
export function adInfoButton( response ) {
	var texts = cfg.adInfo || {};
	var button = document.createElement( 'span' );
	button.className = 'irios-ad-info';
	button.setAttribute( 'role', 'button' );
	button.setAttribute( 'tabindex', '0' );
	button.setAttribute( 'aria-label', texts.button || 'Information about this ad' );
	button.title = texts.button || '';
	button.style.cssText = 'display:inline-flex;vertical-align:middle;margin-left:0.3em;cursor:pointer;';
	button.innerHTML =
		'<svg width="1.1em" height="1.1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
		'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01" stroke-linecap="round"/></svg>';
	var open = function ( event ) {
		event.preventDefault();
		event.stopPropagation();
		showAdInfo( response );
	};
	button.addEventListener( 'click', open );
	button.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			open( event );
		}
	} );
	return button;
}

export function showAdInfo( response ) {
	var texts = cfg.adInfo || {};
	var reasons = texts.reasons || {};
	var types = ( response && Array.isArray( response.targetingTypes ) ? response.targetingTypes : [] ).filter( function ( t, i, all ) {
		return reasons[ t ] && all.indexOf( t ) === i;
	} );
	// DSA art. 26.1: on whose behalf the ad is shown (the brand) and who paid for it (the advertiser); they can differ
	var brand = ( response && ( response.brandName || response.advertiserName ) ) || texts.unknown || '';
	var payer = ( response && ( response.advertiserName || response.brandName ) ) || texts.unknown || '';
	var esc = function ( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text == null ? '' : String( text );
		return div.innerHTML;
	};
	var profiling = types.some( function ( t ) {
		return PROFILING.indexOf( t ) !== -1;
	} );
	var overlay = document.createElement( 'div' );
	overlay.className = 'irios-ad-info-overlay';
	overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;';
	overlay.innerHTML =
		'<div role="dialog" aria-modal="true" aria-labelledby="irios-ad-info-title" style="background:#fff;color:#222;max-width:480px;width:100%;border-radius:12px;padding:24px;position:relative;font-size:15px;line-height:1.55;box-shadow:0 10px 40px rgba(0,0,0,.25)">' +
		'<button type="button" data-close aria-label="' + esc( texts.ok ) + '" style="position:absolute;top:12px;right:12px;background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:#333">×</button>' +
		'<h2 id="irios-ad-info-title" style="margin:0 0 14px;font-size:20px">' + esc( texts.title ) + '</h2>' +
		'<p style="margin:0"><strong>' + esc( texts.why ) + '</strong><br>' + esc( texts.because ) + '</p>' +
		'<ul style="margin:4px 0 0;padding-left:1.2em">' +
		( types.length ? types.map( function ( t ) {
			return '<li>' + esc( reasons[ t ] ) + '</li>';
		} ).join( '' ) : '<li>' + esc( texts.noTargeting ) + '</li>' ) +
		'</ul>' +
		( profiling ? '<p style="margin:8px 0 0;opacity:.8">' + esc( texts.profiling ) + ' ' +
			( texts.privacyUrl ? '<a href="' + esc( texts.privacyUrl ) + '">' + esc( texts.privacy ) + '</a>' : esc( texts.privacy ) ) + '.</p>' : '' ) +
		'<p style="margin:16px 0 0"><strong>' + esc( texts.advertiser ) + '</strong></p>' +
		'<ul style="margin:4px 0 0;padding-left:1.2em"><li>' + esc( texts.name ) + ': <strong>' + esc( brand ) + '</strong></li>' +
		'<li>' + esc( texts.payer ) + ': <strong>' + esc( payer ) + '</strong></li></ul>' +
		'<div style="text-align:right;margin-top:20px"><button type="button" data-close class="button" style="cursor:pointer">' + esc( texts.ok ) + '</button></div>' +
		'</div>';
	var previous = document.activeElement;
	var close = function () {
		overlay.remove();
		document.removeEventListener( 'keydown', onKey, true );
		if ( previous && previous.focus ) {
			previous.focus();
		}
	};
	var onKey = function ( event ) {
		if ( event.key === 'Escape' ) {
			close();
		}
	};
	overlay.addEventListener( 'click', function ( event ) {
		if ( event.target === overlay || ( event.target.closest && event.target.closest( '[data-close]' ) ) ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', onKey, true );
	document.body.appendChild( overlay );
	var ok = overlay.querySelector( '.button[data-close]' );
	if ( ok ) {
		ok.focus();
	}
}

export function addLabel( item, response ) {
	var label = document.createElement( 'span' );
	label.className = 'irios-sponsored-label';
	label.textContent = cfg.sponsoredLabel || 'Sponsored';
	label.appendChild( adInfoButton( response ) );
	label.style.cssText = 'display:block;white-space:nowrap;font-size:0.75em;opacity:0.7;margin:0.25em 0;';
	var title = item.querySelector( TITLE_SELECTOR );
	if ( title && title.parentNode ) {
		var titleStyle = window.getComputedStyle( title );
		label.style.textAlign = titleStyle.textAlign || '';
		label.style.color = titleStyle.color || '';
		label.style.fontSize = Math.max( 11, parseFloat( titleStyle.fontSize ) * 0.75 ) + 'px';
		title.parentNode.insertBefore( label, title );
	} else {
		item.insertBefore( label, item.firstChild );
	}
}

/**
 * Block themes: the "Add to cart" button works through the Interactivity API, which does not hydrate HTML inserted
 * after page load. Its behaviour is replicated with the Store API and the mini-cart is notified with
 * `wc-blocks_added_to_cart`. Not needed in classic themes: WooCommerce's AJAX listens on document.body.
 */
export function wireBlockAddToCart( item ) {
	Array.prototype.forEach.call( item.querySelectorAll( 'button[data-product_id][data-wp-on--click]' ), function ( button ) {
		button.removeAttribute( 'data-wp-on--click' );
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( button.disabled ) {
				return;
			}
			button.disabled = true;
			button.classList.add( 'loading' );
			storeApiAddItem( Number( button.getAttribute( 'data-product_id' ) ) )
				.then( function ( ok ) {
					button.classList.remove( 'loading' );
					button.disabled = false;
					if ( ok ) {
						button.classList.add( 'added' );
						document.body.dispatchEvent( new CustomEvent( 'wc-blocks_added_to_cart', { bubbles: true } ) );
					} else {
						window.location.href = button.closest( 'li' ).querySelector( 'a[href]' ).href; // product page
					}
				} );
		} );
	} );
}

export function storeApiAddItem( productId ) {
	var base = cfg.storeApiUrl;
	return window
		.fetch( base + 'cart', { credentials: 'same-origin' } )
		.then( function ( r ) {
			return window.fetch( base + 'cart/add-item', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', Nonce: r.headers.get( 'Nonce' ) || '' },
				body: JSON.stringify( { id: productId, quantity: 1 } ),
			} );
		} )
		.then( function ( r ) {
			return r.ok;
		} )
		.catch( function () {
			return false;
		} );
}

/**
 * Load and viewability on the card; clicks only on the product page links. IriosTracker turns any click
 * on a link into a navigation, and the add-to-cart button must keep working via AJAX.
 */
/** Tracks ad load, viewability and click; waits for the tracker (the ad may arrive first). */
export function trackAd( item, response ) {
	loadTracker()
		.then( function () {
			trackAdNow( item, response );
		} )
		.catch( function () {} );
}

export function trackAdNow( item, response ) {
	var urls = nativeOf( response ).trackingUrls || {};
	if ( typeof window.IriosTracker !== 'function' ) {
		return;
	}
	// Documented fields (tracking-*) and, while the previous tracker is still deployed, the ones it read (*Urls).
	var user = adUser();
	var units = [
		Object.assign( {}, user, {
			element: item,
			'tracking-load': urls.loadTrackingUrl,
			'tracking-view': urls.viewTrackingUrl,
			loadUrls: urls.loadTrackingUrl,
			viewUrls: urls.viewTrackingUrl,
		} ),
	];
	if ( urls.clickTrackingUrl ) {
		Array.prototype.forEach.call( item.querySelectorAll( 'a[href]' ), function ( link ) {
			if ( ! link.matches( CART_LINK_SELECTOR ) ) {
				units.push( Object.assign( {}, user, {
					element: link,
					'tracking-click': urls.clickTrackingUrl,
					clickUrls: urls.clickTrackingUrl,
				} ) );
			}
		} );
	}
	try {
		new window.IriosTracker( units );
	} catch ( e ) {}
}

