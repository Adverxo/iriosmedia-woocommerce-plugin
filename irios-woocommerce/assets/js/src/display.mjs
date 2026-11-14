import { adInfoButton } from './ad-info.mjs';
import { DEBUG, answerBreakdown, cfg, debug, loaderReady, ms, now, page, warnTheme } from './config.mjs';
import { adUser } from './consent.mjs';
import { findGrid, selector } from './search-ads.mjs';
import { loadLoader } from './tracker.mjs';

// ---- Display ([irios_display tag="…"], resolved in PHP to format, device, id and auth) -----------------------------

/** Controls placed after the grid (sorting, result count, pagination): the listing banner goes below them. */
export var LISTING_CONTROLS_SELECTOR = selector( 'listingControls',
	'.storefront-sorting, .woocommerce-ordering, .woocommerce-result-count, .woocommerce-pagination, nav.pagination, .pagi-nav' );

	/** Product page tabs (description, additional information…): classic WooCommerce, custom themes and blocks. */
export var PRODUCT_TABS_SELECTOR = selector( 'productTabs', '.woocommerce-tabs, .detail-tabs, .wp-block-woocommerce-product-details' );

/** Related and upsell products of the product page: classic WooCommerce, custom themes and blocks. */
export var RELATED_SELECTOR = selector( 'related', 'section.related.products, .related.products, section.upsells.products, .upsells.products, .wp-block-woocommerce-related-products' );

/** Shop sidebar: classic themes (Storefront and most) and block themes. */
export var SIDEBAR_SELECTOR = selector( 'sidebar', '#secondary, aside.widget-area, .widget-area, aside.sidebar, #sidebar, .shop-sidebar' );

/** Desktop from this width (the IRIOS Media standard), mobile below it. */
export var DESKTOP_FROM = 768;

/** Whether a placement is for the device the page is shown on (data-device: all, desktop or mobile). */
export function forThisDevice( slot ) {
	var device = slot.getAttribute( 'data-device' ) || 'all';
	if ( device === 'all' ) {
		return true;
	}
	return ( window.innerWidth >= DESKTOP_FROM ) === ( device === 'desktop' );
}

/**
 * Spot inside the category grid after its Nth product (cfg.gridAfter): a grid item the full width of the grid
 * (an <li> in a <ul> grid), so that the placement does not take the place of a product card.
 */
export function inGridTarget() {
	var grid = findGrid();
	if ( ! grid ) {
		return null;
	}
	var products = Array.prototype.filter.call( grid.children, function ( child ) {
		return child.matches( '.product, .wc-block-product' );
	} );
	var after = products[ Math.min( Number( cfg.gridAfter ) || 6, products.length ) - 1 ];
	if ( ! after || after === products[ products.length - 1 ] ) {
		return null; // fewer products than N: there is no "inside" the grid
	}
	var item = document.createElement( grid.tagName === 'UL' || grid.tagName === 'OL' ? 'li' : 'div' );
	item.className = 'irios-banner-grid-item';
	item.style.cssText = 'grid-column:1/-1;width:100%;flex:0 0 100%;max-width:100%;float:none;clear:both;margin:1em 0;list-style:none;';
	grid.insertBefore( item, after.nextSibling );
	return { parent: item, before: null };
}

/**
 * Places auto-positioned banners (printed hidden in the footer), centred: at the end of the category listing
 * (after the grid and its sorting and pagination controls), or before the product page tabs (the
 * description being the first). If there is no spot, the slot is removed.
 */
export function placeBanners() {
	Array.prototype.forEach.call( document.querySelectorAll( '.irios-banner[data-irios-position]' ), function ( slot ) {
		var position = slot.getAttribute( 'data-irios-position' );
		slot.removeAttribute( 'data-irios-position' );
		var target = null;
		if ( ! forThisDevice( slot ) ) {
			slot.parentNode.removeChild( slot );
			return;
		}
		if ( position === 'category_sidebar' ) {
			var sidebar = document.querySelector( SIDEBAR_SELECTOR );
			target = sidebar && { parent: sidebar, before: sidebar.firstChild };
		} else if ( position === 'category_in_grid' ) {
			target = inGridTarget();
		} else if ( position === 'product_before_related' ) {
			var related = document.querySelector( RELATED_SELECTOR );
			target = related && { parent: related.parentNode, before: related };
		} else if ( position === 'category_after_products' ) {
			var grid = findGrid();
			var after = grid;
			while ( after && after.nextElementSibling && after.nextElementSibling.matches( LISTING_CONTROLS_SELECTOR ) ) {
				after = after.nextElementSibling;
			}
			target = after && { parent: after.parentNode, before: after.nextSibling };
		} else if ( position === 'product_before_description' ) {
			var tabs = Array.prototype.find.call( document.querySelectorAll( PRODUCT_TABS_SELECTOR ), function ( el ) {
				return ! el.closest( '.related, .upsells, .widget' );
			} );
			target = tabs && { parent: tabs.parentNode, before: tabs };
		}
		if ( ! target ) {
			warnTheme( 'banner-' + position, 'banner "' + ( slot.getAttribute( 'data-tag' ) || slot.getAttribute( 'data-ad-unit' ) ) +
				'": automatic position "' + position + '" not found on this page, banner not shown' );
			slot.parentNode.removeChild( slot );
			return;
		}
		target.parent.insertBefore( slot, target.before );
		slot.removeAttribute( 'hidden' );
		// Centred and spaced from the content (the creative may be narrower than the column).
		// As a column: the label («Patrocinado ⓘ») above the creative, both centred
		slot.style.display = 'flex';
		slot.style.flexDirection = 'column';
		slot.style.alignItems = 'center';
		slot.style.clear = 'both';
		slot.style.margin = '1.5em 0';
	} );
}

/**
 * IRIOS page context of the banners' auction: the page's type (pageType) and what that type requires, the search
 * (SEARCH) or the category path (CATEGORY, PRODUCT_DETAIL). null when there is no type (a page the plugin does not
 * classify and whose banners give none) or the type lacks what it requires: then no ads are requested.
 */
export function bannerPage( slots ) {
	var pageType = page.pageType;
	if ( ! pageType ) {
		// A page the plugin does not classify: the type given to a banner ([irios_banner page_type="…"]), if any
		slots.some( function ( slot ) {
			pageType = slot.getAttribute( 'data-page-type' ) || '';
			return !! pageType;
		} );
	}
	if ( ! pageType ) {
		return null;
	}
	var context = { pageType: pageType };
	if ( pageType === 'SEARCH' ) {
		// Banners on the search page: the search goes too (IRIOS requires it for this page type)
		context.searchQuery = String( page.value || '' ).trim().slice( 0, 255 );
		if ( ! context.searchQuery ) {
			return null;
		}
	} else if ( pageType === 'CATEGORY' || pageType === 'PRODUCT_DETAIL' ) {
		context.category = page.category || [];
		if ( ! context.category.length ) {
			return null;
		}
	}
	return context;
}

/**
 * A single auction for all banner slots on the page (IriosLoader with all their ad units), with the visitor's uids
 * and the page as context (bannerPage). Each banner is rendered with render() called from a <script> inside its
 * slot: the loader renders into the parent of document.currentScript.
 */
export function renderBanners() {
	if ( ! cfg.bannersEnabled || cfg.bannersEnabled === '0' ) {
		return;
	}
	placeBanners();
	var slots = Array.prototype.filter.call( document.querySelectorAll( '.irios-banner[data-ad-unit]' ), function ( slot ) {
		// A placement for another device is not requested (the same spot may have a row for each device)
		return ! slot.hasAttribute( 'data-irios-rendered' ) && forThisDevice( slot );
	} );
	if ( ! slots.length ) {
		return;
	}
	var units = {};
	slots.forEach( function ( slot ) {
		slot.setAttribute( 'data-irios-rendered', '1' );
		// type: the IRIOS ad unit type of the placement's format (banner for images, video): the loader renders
		// an image or a video player depending on it
		units[ slot.getAttribute( 'data-ad-unit' ) ] = {
			auth: slot.getAttribute( 'data-auth' ),
			type: slot.getAttribute( 'data-type' ) === 'video' ? 'video' : 'banner',
		};
	} );
	var context = bannerPage( slots );
	if ( ! context ) {
		return; // no IRIOS page type for this page: no auction (the slots stay empty)
	}
	var start = now();
	loadLoader()
		.then( function () {
			var loaderMs = now() - start;
			var auctionStart = now(); // the auction starts in the loader constructor
			window.__iriosWooBanners = new window.IriosLoader( Object.assign( adUser(), context, {
				adUnits: Object.keys( units ).map( function ( id ) {
					return { id: Number( id ), auth: units[ id ].auth, type: units[ id ].type };
				} ),
			} ) );
			slots.forEach( function ( slot ) {
				var script = document.createElement( 'script' );
				script.text = 'window.__iriosWooBanners.render(' + Number( slot.getAttribute( 'data-ad-unit' ) ) + ');';
				debugBanner( slot, start, loaderMs, auctionStart );
				slot.appendChild( script );
				labelBanner( slot );
			} );
		} )
		.catch( function ( e ) {
			if ( window.console ) {
				window.console.warn( '[irios] banners not available', e );
			}
		} );
}

/**
 * Banner label with its "About this ad" button, above the slot and only if Irios returns an ad. The
 * creative is rendered by the loader (render(), in an iframe): getAds() is the same auction, with advertiser data.
 */
export function labelBanner( slot ) {
	var loader = window.__iriosWooBanners;
	if ( ! loader || typeof loader.getAds !== 'function' ) {
		return;
	}
	loader.getAds( Number( slot.getAttribute( 'data-ad-unit' ) ) ).then( function ( ads ) {
		if ( ! ads || ! ads.length || slot.querySelector( '.irios-sponsored-label' ) ) {
			return;
		}
		var label = document.createElement( 'span' );
		label.className = 'irios-sponsored-label';
		label.textContent = cfg.sponsoredLabel || 'Sponsored';
		// Above the banner, at its top-right corner (same width as the rendered creative) and without wrapping
		// «Patrocinado ⓘ»
		label.style.cssText = 'display:block;white-space:nowrap;text-align:right;font-size:11px;opacity:0.7;margin:0 0 0.25em;box-sizing:border-box;align-self:center;max-width:100%;';
		var creative = ads[ 0 ].banner || ads[ 0 ].video || {};
		var width = Number( creative.width );
		if ( width ) {
			label.style.width = width + 'px'; // until the creative is rendered
		}
		label.appendChild( adInfoButton( ads[ 0 ] ) );
		slot.insertBefore( label, slot.firstChild );
		fitLabelToCreative( slot, label );
	}, function () {} );
}

/**
 * The label takes the creative's actual width (the loader renders it later and the theme may scale it), so it sits at
 * its top-right corner. Adjusted when the creative appears and whenever it is resized.
 */
export function fitLabelToCreative( slot, label ) {
	var fit = function () {
		var creative = Array.prototype.find.call( slot.querySelectorAll( 'iframe, img, a, ins, div' ), function ( el ) {
			return ! label.contains( el ) && ! el.contains( label ) && el.getBoundingClientRect().width > 0;
		} );
		if ( ! creative ) {
			return false;
		}
		label.style.width = Math.round( creative.getBoundingClientRect().width ) + 'px';
		if ( window.ResizeObserver && ! label.__iriosResize ) {
			label.__iriosResize = new window.ResizeObserver( fit );
			label.__iriosResize.observe( creative );
		}
		return true;
	};
	if ( fit() || ! window.MutationObserver ) {
		return;
	}
	var observer = new window.MutationObserver( function () {
		if ( fit() ) {
			observer.disconnect();
		}
	} );
	observer.observe( slot, { childList: true, subtree: true } );
	window.setTimeout( function () {
		observer.disconnect();
	}, 10000 );
}

/** Debug mode: when the creative appears in the slot (the loader renders it without notifying). */
export function debugBanner( slot, start, loaderMs, auctionStart ) {
	if ( ! DEBUG || ! window.MutationObserver ) {
		return;
	}
	var id = slot.getAttribute( 'data-ad-unit' );
	var renderAt = auctionStart;
	var answeredAt = null;
	var loader = window.__iriosWooBanners;
	if ( loader && typeof loader.getAds === 'function' ) {
		// Same auction as render(): getAds() only tells when Irios has responded.
		loader.getAds( Number( id ) ).then( function ( ads ) {
			answeredAt = now();
			if ( ! ads || ! ads.length ) {
				observer.disconnect();
				window.clearTimeout( giveUp );
				debug( 'ad unit ' + id + ' · banner · Irios answered in ' + ms( answeredAt - renderAt ) + answerBreakdown( renderAt, answeredAt ) + ' with no ads' );
			}
		}, function () {} );
	}
	var observer = new window.MutationObserver( function () {
		var painted = Array.prototype.some.call( slot.querySelectorAll( '*' ), function ( el ) {
			return el.tagName !== 'SCRIPT' && ( el.tagName === 'IFRAME' || el.tagName === 'IMG' || el.offsetHeight > 0 );
		} );
		if ( painted ) {
			observer.disconnect();
			window.clearTimeout( giveUp );
			debug(
				'ad unit ' + id + ' · banner' + ( loaderMs > 5 ? ' · ' + loaderReady( loaderMs ) : '' ) +
					( answeredAt
						? ' · Irios answered in ' + ms( answeredAt - renderAt ) + answerBreakdown( renderAt, answeredAt ) + ' · rendered in ' + ms( now() - answeredAt )
						: ' · Irios answered and rendered in ' + ms( now() - renderAt ) ) +
					' · ad visible ' + ms( now() ) + ' after the page started loading'
			);
		}
	} );
	var giveUp = window.setTimeout( function () {
		observer.disconnect();
		debug( 'ad unit ' + id + ' · banner · no ad after ' + ms( now() - start ) );
	}, 10000 );
	observer.observe( slot, { childList: true, subtree: true, attributes: true } );
}

