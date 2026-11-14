import { addLabel, trackAd } from './ad-info.mjs';
import { cfg, debugTiming, state, unitsLabel, warnTheme } from './config.mjs';
import { hasConsent } from './consent.mjs';
import { PENDING_SEARCH_KEY } from './events.mjs';
import { cloneResult, itemOf, nativeOf, normalizeUrl, placeAt, requestAds, resultTitle, selector } from './search-ads.mjs';
import { loadLoader } from './tracker.mjs';

// ---- Theme live search (results while typing) ---------------------------------------------------------------------

/**
 * Many themes show results in a dropdown while the user types, and the customer
 * goes to the product page without visiting the results page. Theme-independent:
 *   - the dropdown is a list of links to product pages inside a search box (something with an "s" field);
 *   - clicking a result stores the term and `search` is sent on the next page (sending it on click would be
 *     lost when leaving the page);
 *   - Sponsored Products are requested for the term (while typing, alongside the theme's search; one auction per
 *     rendered list, never reusing earlier ads) and placed at their positions by cloning a list row; if the
 *     list appears before the ads, it is briefly hidden (no layout jump).
 */
export var live = { timer: null, prefetch: null, ads: {}, typedInput: null, watch: null, found: false };
// Links that are not search results even though they sit in its area: the header mini-cart, for example.
export var NOT_RESULTS_SELECTOR = selector( 'notResults', '[class*="mini-cart"], [class*="minicart"], .widget_shopping_cart, .woocommerce-mini-cart, .cart_list' );
export var LIVE_HOLD_MS = 600; // max time the list stays hidden waiting for ads
export var SEARCH_INPUT = 'input[name="s"], input[type="search"]';
// Live search suggestion containers that are not inside the form
export var LIVE_DROPDOWN_SELECTOR = selector( 'liveDropdown',
	'[class*="suggestion"], [class*="autocomplete"], [class*="search-result"], [class*="live-search"], [class*="ajax-search"]' );

/** With consent, once the customer's uids are available; without it, immediately (contextual ads). */
export function liveSearchEnabled() {
	return !! cfg.liveSearchAds && ( hasConsent() ? state.ready : true );
}

/** Live search auction for a term. Resolves to null when there are no ads. */
export function requestLiveAds( term ) {
	var timing = {};
	var pending = requestAds( cfg.liveSearchAds, 'SEARCH_BAR', term, 'data', timing ).catch( function () {
		return null;
	} );
	pending.timing = timing;
	return pending;
}

/**
 * The term's auction is prefetched while the customer types (alongside the theme's AJAX search), only for the
 * latest term; the list rendered for that term uses it and discards it.
 */
export function prefetchLiveAds( term ) {
	live.ads = {};
	live.ads[ term ] = requestLiveAds( term );
}

/**
 * Auction for the list being rendered: the prefetched one for that term if any (then discarded), or a new one.
 * No caching: each rendered list is an auction, even if the term was searched before (Irios charges per event).
 */
export function takeLiveAds( term ) {
	var pending = live.ads[ term ] || requestLiveAds( term );
	delete live.ads[ term ];
	return pending;
}

export function isSearchInput( el ) {
	return el instanceof Element && el.matches( SEARCH_INPUT );
}

// Focusing the search box already downloads the loader; typing prefetches the term's auction.
document.addEventListener(
	'focusin',
	function ( e ) {
		if ( isSearchInput( e.target ) && liveSearchEnabled() ) {
			loadLoader().catch( function () {} );
		}
	},
	true
);
document.addEventListener(
	'input',
	function ( e ) {
		if ( ! isSearchInput( e.target ) || ! liveSearchEnabled() ) {
			return;
		}
		// Only a search box the customer has typed in counts: some themes prefill it ("Search...", or the
		// term on the results page) and without this any nearby product list would look like its dropdown.
		live.typedInput = e.target;
		watchLiveSearch( e.target );
		var term = e.target.value.trim();
		window.clearTimeout( live.prefetch );
		// Debug mode: if no dropdown with products appears after typing, warn (theme without live
		// search or with one the plugin does not recognise)
		window.clearTimeout( live.watch );
		if ( term.length >= 3 && ! live.found ) {
			live.watch = window.setTimeout( function () {
				if ( ! live.found ) {
					warnTheme( 'live-search', 'live search: no dropdown with product links found next to the search field, no ads shown there' );
				}
			}, 2500 );
		}
		if ( term ) {
			live.prefetch = window.setTimeout( function () {
				prefetchLiveAds( term );
			}, 250 );
		}
	},
	true
);

/** Hides the list without transition (it was just rendered) and shows it with a fade-in. */
export function holdList( container ) {
	container.style.transition = 'none';
	container.style.opacity = '0';
}

export function releaseList( container ) {
	container.style.transition = 'opacity .15s ease-in';
	container.style.opacity = '';
}

export function isProductLink( a ) {
	try {
		var u = new URL( a.href, window.location.href );
		return u.host === window.location.host && u.pathname.indexOf( cfg.productBase || '/product/' ) === 0;
	} catch ( e ) {
		return false;
	}
}

/** Search box an element belongs to: the closest form or "search" block with a search field. */
export function searchScope( el ) {
	var scope = el.closest( 'form, [class*="search"]' );
	while ( scope && scope !== document.body && scope.tagName !== 'MAIN' ) {
		var input = scope.querySelector( 'input[name="s"], input[type="search"]' );
		if ( input ) {
			return { el: scope, input: input };
		}
		scope = scope.parentElement && scope.parentElement.closest( 'form, [class*="search"]' );
	}
	// Dropdowns the search box renders outside its form (e.g. FiboSearch, at the end of body): attributed to the
	// search box the customer just typed in
	var dropdown = el.closest( LIVE_DROPDOWN_SELECTOR );
	if ( dropdown && live.typedInput && live.typedInput.value.trim() ) {
		return { el: dropdown, input: live.typedInput };
	}
	return null;
}

document.addEventListener(
	'click',
	function ( e ) {
		var a = e.target instanceof Element ? e.target.closest( 'a[href]' ) : null;
		var scope = a && isProductLink( a ) ? searchScope( a ) : null;
		var term = scope ? scope.input.value.trim() : '';
		if ( term && hasConsent() ) {
			try {
				window.sessionStorage.setItem( PENDING_SEARCH_KEY, term );
			} catch ( err ) {}
		}
	},
	true
);

export function scanLiveSearch() {
	if ( ! liveSearchEnabled() ) {
		return;
	}
	if ( ! live.typedInput ) {
		return; // the customer has not typed in any search box yet
	}
	var links = Array.prototype.filter.call( document.querySelectorAll( 'a[href]' ), function ( a ) {
		return isProductLink( a ) && a.offsetParent !== null && ! a.closest( '.irios-sponsored, [data-irios-ads]' ) && ! a.closest( NOT_RESULTS_SELECTOR );
	} );
	var scope = null;
	var inScope = links.filter( function ( a ) {
		var s2 = searchScope( a );
		if ( s2 && s2.input === live.typedInput && s2.input.value.trim() && ( ! scope || scope.el === s2.el ) ) {
			scope = s2;
			return true;
		}
		return false;
	} );
	if ( ! scope || ! inScope.length ) {
		return; // no visible dropdown
	}
	// List: common container of the results (or that of the only row).
	var container = inScope[ 0 ].parentElement;
	var other = inScope.find( function ( a ) {
		return normalizeUrl( a.href ) !== normalizeUrl( inScope[ 0 ].href );
	} );
	if ( other ) {
		while ( container && ! container.contains( other ) ) {
			container = container.parentElement;
		}
	} else {
		var node = inScope[ 0 ];
		while ( node.parentElement && node.parentElement.children.length < 2 && node.parentElement !== scope.el ) {
			node = node.parentElement;
		}
		container = node.parentElement;
	}
	var term = scope.input.value.trim();
	var template = container && itemOf( container, inScope[ 0 ] );
	// Already done for this term and this list. If the theme re-rendered the list (its AJAX response arrives
	// after the customer keeps typing), the rows are different and it is done again.
	if ( ! container || ( container.getAttribute( 'data-irios-live-term' ) === term && container.__iriosRow && container.__iriosRow.parentElement === container ) ) {
		return;
	}
	container.setAttribute( 'data-irios-live-term', term );
	live.found = true;
	Array.prototype.forEach.call( container.querySelectorAll( ':scope > .irios-sponsored' ), function ( el ) {
		el.remove();
	} );
	Array.prototype.forEach.call( container.querySelectorAll( ':scope > [data-irios-hidden]' ), function ( el ) {
		el.style.display = '';
		el.removeAttribute( 'data-irios-hidden' );
	} );

	container.__iriosRow = template;
	var templateUrl = normalizeUrl( inScope[ 0 ].href );
	var titleText = resultTitle( template, templateUrl );
	// If the auction already finished, then() runs before painting and the list never gets hidden.
	holdList( container );
	var hold = window.setTimeout( function () {
		releaseList( container );
	}, LIVE_HOLD_MS );
	var pending = takeLiveAds( term );
	pending
		.then( function ( result ) {
			window.clearTimeout( hold );
			var where = 'live search ' + JSON.stringify( term );
			if ( ! result ) {
				debugTiming( unitsLabel( cfg.liveSearchAds.units ), where, pending.timing, false );
				return; // no ads
			}
			if ( container.getAttribute( 'data-irios-live-term' ) !== term || container.__iriosRow !== template || ! container.isConnected ) {
				return; // the customer kept typing: it goes into the new list
			}
			var byId = {};
			result.cards.forEach( function ( card ) {
				byId[ card.id ] = card;
			} );
			// Each ad at its list position (1 = first row), after hiding rows of the same product.
			var items = [];
			result.placements.forEach( function ( p ) {
				var card = byId[ String( nativeOf( p.response ).product.id ) ];
				if ( ! card ) {
					return;
				}
				items.push( { item: cloneResult( template, templateUrl, titleText, card ), placement: p } );
				Array.prototype.forEach.call( container.children, function ( row ) {
					var link = row.querySelector( 'a[href]' );
					if ( link && ! row.classList.contains( 'irios-sponsored' ) && Number( card.productId ) && isSameProduct( link.href, card ) ) {
						row.style.display = 'none';
						row.setAttribute( 'data-irios-hidden', '1' );
					}
				} );
			} );
			items.forEach( function ( entry ) {
				placeAt( container, entry.item, entry.placement.position );
				addLabel( entry.item, entry.placement.response );
				trackAd( entry.item, entry.placement.response );
			} );
			debugTiming( unitsLabel( cfg.liveSearchAds.units ), where, pending.timing, true );
		} )
		.catch( function () {} )
		.then( function () {
			releaseList( container );
		} );
}

/** The row links to the same product as the ad (same product page, ignoring the selected variation). */
export function isSameProduct( href, card ) {
	return normalizeUrl( href ) === normalizeUrl( card.link );
}

/**
 * Watches for the dropdown the theme renders while the customer types. It is only installed after the first
 * keystroke in a search box, and only over that box's own corner of the page: watching the whole document from
 * the start meant walking every link of it on every change, for the whole life of the page.
 */
export function watchLiveSearch( input ) {
	if ( ! window.MutationObserver || live.observer ) {
		return;
	}
	live.observer = new window.MutationObserver( function () {
		window.clearTimeout( live.timer );
		live.timer = window.setTimeout( scanLiveSearch, 50 ); // batches changes from the same render
	} );
	// The dropdown is a sibling of the search box, or lands next to its form; body only as a last resort
	var scope = input.closest( 'form' );
	scope = ( scope && scope.parentElement ) || input.parentElement || document.body;
	live.observer.observe( scope, { childList: true, subtree: true } );
}

// There is no unwatch: without consent the dropdown still gets ads, contextual ones, so the watch stays.

