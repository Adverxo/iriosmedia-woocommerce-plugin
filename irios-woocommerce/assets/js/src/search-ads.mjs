import { addLabel, trackAd, wireBlockAddToCart } from './ad-info.mjs';
import { cfg, debug, debugTiming, now, page, unitsLabel, warnTheme } from './config.mjs';
import { adUser } from './consent.mjs';
import { loadLoader, revealResults } from './tracker.mjs';

// ---- Sponsored Products (native) -----------------------------------------------------------------------------------

/**
 * Native ads in search: the auction is requested with IriosLoader and the ads are read with getAds() (API
 * documented at doc.iriosmedia.com, "Retrieving ads without rendering"), without render(); the winning products'
 * cards are inserted at the top of the results with the "Sponsored" label:
 *   - with a shop grid (.products or template block), the theme renders the card (GET irios/v1/cards);
 *   - if the theme shows search results differently (e.g. as blog posts), the
 *     results are found by their links (page.ads.results) and one is cloned, swapping link, image, title, excerpt and
 *     date with the advertised product's (cards?layout=data). This way the ad looks the same as the results.
 * Load, viewability and click are tracked with IriosTracker (bundled in irios-loader.js) using each ad's tracking
 * URLs. Only with consent, ad unit and auth.
 */
// Product grid: ul.products (WooCommerce), div.products (some themes) or the template block.
/**
 * Where each thing is in this theme's HTML. PHP serves them (Selectors), so a theme can be adapted with the
 * irios_woocommerce_selectors filter without waiting for a version of the plugin; the values here are the same
 * defaults, kept for a store whose page was printed by an older version.
 */
export function selector( name, fallback ) {
	var given = cfg.selectors && cfg.selectors[ name ];
	return typeof given === 'string' && given.trim() ? given : fallback;
}

export var GRID_SELECTOR = selector( 'grid', '.products, .wc-block-product-template' );
export var TITLE_SELECTOR = selector( 'title', '.woocommerce-loop-product__title, .product-title, .post-title, .entry-title, .wp-block-post-title, h2, h3' );
// Add-to-cart links (they don't navigate to the product page): not counted as ad clicks.
export var CART_LINK_SELECTOR = '.ajax_add_to_cart, [href*="add-to-cart="]';

/** Creative of a product ad: "native" (documented API); "nativee" with older loaders. */
export function nativeOf( ad ) {
	return ad ? ad.native || ad.nativee : null;
}

/**
 * Search with no results: empty shop grid above the WooCommerce notice ("No products were
 * found…"), for that search's sponsored products, if any. Removed if none arrive.
 */
export function emptyResultsGrid() {
	var notice = document.querySelector( '.woocommerce-no-products-found' ) || document.querySelector( '.woocommerce-info' );
	var parent = notice ? notice.parentNode : document.querySelector( 'main, #main, #primary, .site-main' );
	if ( ! parent ) {
		return null;
	}
	var grid = document.createElement( 'ul' );
	grid.className = 'products columns-4 irios-empty-results';
	parent.insertBefore( grid, notice || parent.firstChild );
	return grid;
}

export function renderSearchAds() {
	var ads = page.ads;
	var grid = ads ? findGrid() || findResultsGrid( ads.results ) || ( ads.empty ? emptyResultsGrid() : null ) : null;
	if ( ads && ! grid ) {
		warnTheme( 'search-grid', 'Sponsored Products: no results grid found on this search page, no ads shown' );
	}
	if ( ! grid || grid.getAttribute( 'data-irios-ads' ) ) {
		revealResults();
		return;
	}
	grid.setAttribute( 'data-irios-ads', '1' );
	var layout = grid.matches( '.wc-block-product-template' ) ? 'block' : grid.matches( GRID_SELECTOR ) ? 'classic' : 'data';

	var timing = {};
	requestAds( ads, 'SEARCH', ads.searchQuery, layout, timing, 'data' === layout ? imageSizeOf( grid ) : '' )
		.then( function ( result ) {
			if ( ! result && grid.classList.contains( 'irios-empty-results' ) ) {
				grid.remove(); // no sponsored products for a search with no results
			}
			if ( result ) {
				if ( 'data' === layout ) {
					insertClonedResults( grid, result.placements, result.cards, ads.results || {} );
				} else {
					insertCards( grid, result.placements, result.cards );
				}
			}
			debugTiming( unitsLabel( ads.units ), 'search ' + JSON.stringify( ads.searchQuery ), timing, !! result, true );
		} )
		.catch( function ( e ) {
			if ( window.console ) {
				window.console.warn( '[irios] ads not available', e );
			}
		} )
		.then( revealResults );
}

/**
 * Sponsored Products auction for a term plus the winners' cards (GET irios/v1/cards). Resolves to null if there
 * are no winners. pageType: SEARCH on the results page, SEARCH_BAR in the live search (IRIOS pageType; no category,
 * IRIOS ignores it on search pages). config: { units: [{ adUnit, auth, positions }], language }: all ad units go in the same
 * auction and each one requests as many ads as it has positions. Each ad unit's winners, in order, fill
 * its positions from lowest to highest (no product repeated across ad units). timing (optional) collects timings.
 *
 * Resolves to { placements: [{ position, response }] (by position), cards, timing }.
 */
export function requestAds( config, pageType, searchQuery, layout, timing, imageSize ) {
	timing = timing || {};
	timing.start = now();
	var units = ( config.units || [] ).filter( function ( u ) {
		return u && u.adUnit && u.auth && u.positions && u.positions.length;
	} );
	if ( ! units.length ) {
		return Promise.resolve( null );
	}
	return loadLoader()
		.then( function () {
			timing.loader = now() - timing.start;
			timing.auctionStart = now();
			var loader = new window.IriosLoader( Object.assign( adUser(), {
				pageType: pageType,
				searchQuery: String( searchQuery ).slice( 0, 255 ),
				language: config.language,
				adUnits: units.map( function ( u ) {
					return { id: u.adUnit, auth: u.auth, type: 'product', maxAds: u.positions.length };
				} ),
			} ) );
			if ( typeof loader.getAds === 'function' ) {
				return Promise.all( units.map( function ( u ) {
					return loader.getAds( u.adUnit );
				} ) ).then( function ( lists ) {
					return [].concat.apply( [], lists );
				} );
			}
			return loader.adResponsesPromise; // loaders older than getAds(): all responses at once
		} )
		.then( function ( responses ) {
			timing.answeredAt = now();
			timing.auction = timing.answeredAt - timing.auctionStart;
			var placements = placeWinners( units, responses || [] );
			timing.winners = placements.length;
			if ( ! placements.length ) {
				return null;
			}
			var cardsStart = now();
			var ids = placements.map( function ( p ) {
				return String( nativeOf( p.response ).product.id );
			} );
			return window
				.fetch( cfg.cardsUrl + '?layout=' + layout + '&ids=' + encodeURIComponent( ids.join( ',' ) ) + ( imageSize ? '&imageSize=' + encodeURIComponent( imageSize ) : '' ), {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' },
				} )
				.then( function ( r ) {
					return r.ok ? r.json() : { cards: [] };
				} )
				.catch( function () {
					return { cards: [] }; // empty or non-JSON response
				} )
				.then( function ( body ) {
					timing.cards = now() - cardsStart;
					var cards = body.cards || [];
					if ( 'data' === layout ) {
						// Without a price the cloned card would keep the cloned result's price: use the Irios one.
						cards.forEach( function ( card ) {
							if ( card.priceHtml ) {
								return;
							}
							var placement = placements.find( function ( p ) {
								return String( nativeOf( p.response ).product.id ) === String( card.id );
							} );
							card.priceHtml = placement ? nativeCard( placement.response, config.language ).priceHtml : '';
						} );
					}
					if ( ! cards.length && 'data' === layout ) {
						// WordPress did not return the cards (e.g. another plugin blocks the request): render with
						// the catalogue data Irios provides, so the ad is not lost.
						cards = placements.map( function ( p ) {
							return nativeCard( p.response, config.language );
						} );
						debug( 'cards not available from WordPress: ad rendered with Irios catalog data' );
					}
					return { placements: placements, cards: cards, timing: timing };
				} );
		} );
}

/**
 * Assigns winners to positions: each ad unit's winners, in Irios order, fill its positions from lowest to
 * highest. A product that already won in another ad unit is not repeated (its position goes to the organic result).
 */
export function placeWinners( units, responses ) {
	var used = {};
	var placements = [];
	units.forEach( function ( unit ) {
		var positions = unit.positions.slice().sort( function ( a, b ) {
			return a - b;
		} );
		var winners = responses
			.filter( function ( r ) {
				var creative = nativeOf( r );
				return r && Number( r.adUnitId ) === Number( unit.adUnit ) && creative && creative.product && creative.product.id;
			} )
			.sort( function ( a, b ) {
				return ( a.position || 0 ) - ( b.position || 0 );
			} );
		var next = 0;
		winners.forEach( function ( r ) {
			var id = String( nativeOf( r ).product.id );
			if ( next >= positions.length || used[ id ] ) {
				return;
			}
			used[ id ] = true;
			placements.push( { position: positions[ next++ ], response: r } );
		} );
	} );
	return placements.sort( function ( a, b ) {
		return a.position - b.position;
	} );
}

/**
 * Places the ad at its position (1 = first) counting only the container's visible elements; if there are fewer,
 * at the end. Ads are placed from lowest to highest position, so each one counts the previous ones.
 */
export function placeAt( container, item, position ) {
	var visible = Array.prototype.filter.call( container.children, function ( el ) {
		return el.style.display !== 'none' && ! el.hidden && el.tagName !== 'SCRIPT' && el.tagName !== 'STYLE' && el.tagName !== 'TEMPLATE';
	} );
	var before = visible[ position - 1 ];
	if ( before ) {
		container.insertBefore( item, before );
	} else {
		container.appendChild( item );
	}
}

/** First product grid on the page (not the "related products" one nor widget grids). */
export function findGrid() {
	return Array.prototype.find.call( document.querySelectorAll( GRID_SELECTOR ), function ( el ) {
		return ! el.closest( '.related, .upsells, .cross-sells, .widget' ) &&
			Array.prototype.some.call( el.children, function ( child ) {
				return child.matches( '.product, .wc-block-product' );
			} );
	} ) || null;
}

/** URL without protocol, query, hash or trailing slash, for comparing links. */
export function normalizeUrl( url ) {
	try {
		var u = new URL( url, window.location.href );
		return ( u.host + u.pathname ).replace( /\/+$/, '' );
	} catch ( e ) {
		return '';
	}
}

/**
 * Results container when the theme does not use a shop grid: the element whose children link to the
 * products of this search (links provided by PHP). Null if not found.
 */
export function findResultsGrid( results ) {
	var wanted = {};
	Object.keys( results || {} ).forEach( function ( link ) {
		wanted[ normalizeUrl( link ) ] = true;
	} );
	var links = Array.prototype.filter.call( document.querySelectorAll( 'a[href]' ), function ( a ) {
		return wanted[ normalizeUrl( a.href ) ] && ! a.closest( 'header, footer, nav, .widget' );
	} );
	if ( ! links.length ) {
		return null;
	}
	var other = links.find( function ( a ) {
		return normalizeUrl( a.href ) !== normalizeUrl( links[ 0 ].href );
	} );
	if ( ! other ) {
		// Single result: its block is the child of the closest container with more than one child.
		var node = links[ 0 ];
		while ( node.parentElement && node.parentElement.children.length < 2 ) {
			node = node.parentElement;
		}
		return node.parentElement;
	}
	var container = links[ 0 ].parentElement;
	while ( container && ! container.contains( other ) ) {
		container = container.parentElement;
	}
	return container;
}

/** Direct child of the container that contains the element. */
export function itemOf( grid, el ) {
	while ( el && el.parentElement !== grid ) {
		el = el.parentElement;
	}
	return el;
}

/** Organic result on the page that links to that product (or null). */
export function resultItem( grid, results, productId ) {
	var link = Object.keys( results ).find( function ( url ) {
		return Number( results[ url ] ) === Number( productId );
	} );
	if ( ! link ) {
		return null;
	}
	var a = Array.prototype.find.call( grid.querySelectorAll( 'a[href]' ), function ( el ) {
		return normalizeUrl( el.href ) === normalizeUrl( link );
	} );
	return a ? itemOf( grid, a ) : null;
}

export function insertClonedResults( grid, placements, cards, results ) {
	var byId = {};
	cards.forEach( function ( card ) {
		byId[ card.id ] = card;
	} );
	// Template: the first organic result (its links, title and image are replaced with the ad's).
	var templateLink = Array.prototype.find.call( grid.querySelectorAll( 'a[href]' ), function ( a ) {
		return Object.keys( results ).some( function ( url ) {
			return normalizeUrl( url ) === normalizeUrl( a.href );
		} );
	} );
	var template = templateLink ? itemOf( grid, templateLink ) : null;
	if ( ! template ) {
		return;
	}
	var templateUrl = normalizeUrl( templateLink.href );
	var titleText = resultTitle( template, templateUrl );

	// First clone and hide the duplicated organic results, then place the ads: this way positions count the
	// grid as it ends up (and the template is cloned before it can get hidden).
	var items = [];
	placements.forEach( function ( p ) {
		var card = byId[ String( nativeOf( p.response ).product.id ) ];
		if ( ! card ) {
			return;
		}
		items.push( { item: cloneResult( template, templateUrl, titleText, card ), placement: p } );
		var duplicate = resultItem( grid, results, card.productId );
		if ( duplicate ) {
			duplicate.style.display = 'none';
		}
	} );
	items.forEach( function ( entry ) {
		placeAt( grid, entry.item, entry.placement.position );
		addLabel( entry.item, entry.placement.response );
		trackAd( entry.item, entry.placement.response );
	} );
}

/** Title of a result: the longest text among its links to the product page. */
export function resultTitle( item, url ) {
	return Array.prototype.reduce.call( item.querySelectorAll( 'a[href]' ), function ( best, a ) {
		var text = a.textContent.trim();
		return normalizeUrl( a.href ) === url && text.length > best.length ? text : best;
	}, '' );
}

/**
 * Copy of a result on the page filled with the ad's data: links, image, title, price, excerpt and date
 * (whatever the result has). This way the ad looks like the results, whatever the theme.
 */
/**
 * Fallback card with the product data Irios provides (from the synced catalogue): name, image, price
 * and link. No excerpt or date, and no parent id (the duplicated organic result is not hidden).
 */
export function nativeCard( response, language ) {
	var product = nativeOf( response ).product;
	var price = '';
	if ( product.price != null && window.Intl ) {
		try {
			price = new window.Intl.NumberFormat( language || 'es-ES', { style: 'currency', currency: cfg.currency || 'EUR' } ).format( Number( product.price ) );
		} catch ( e ) {}
	}
	var span = document.createElement( 'span' );
	span.className = 'woocommerce-Price-amount amount';
	span.textContent = price;
	return {
		id: String( product.id ),
		productId: 0,
		link: product.clickUrl || '',
		title: product.name || '',
		image: product.imageUrl || '',
		excerpt: '',
		date: '',
		priceHtml: price ? span.outerHTML : '',
	};
}

/** Image size of the results ("270x145" from the "size-270x145" class), to request the ad's image in the same size. */
export function imageSizeOf( grid ) {
	var img = grid.querySelector( 'img[class*="size-"]' );
	var match = img ? /(?:^|\s)size-([a-z0-9_-]+)/.exec( img.className ) : null;
	return match ? match[ 1 ] : '';
}

/**
 * Shape of the results' images on screen: the template's image or, if it is hidden (a hidden duplicate), another
 * visible result's. Falls back to the width/height attributes when nothing is rendered yet.
 */
export function renderedImageBox( template ) {
	var own = template.querySelector( 'img' );
	var candidates = [ own ];
	if ( template.parentElement ) {
		Array.prototype.forEach.call( template.parentElement.children, function ( sibling ) {
			if ( sibling !== template && ! sibling.classList.contains( 'irios-sponsored' ) ) {
				candidates.push( sibling.querySelector( 'img' ) );
			}
		} );
	}
	for ( var i = 0; i < candidates.length; i++ ) {
		var c = candidates[ i ];
		if ( c && c.offsetWidth > 0 && c.offsetHeight > 0 ) {
			var fit = window.getComputedStyle( c ).objectFit;
			return { w: c.offsetWidth, h: c.offsetHeight, fit: fit && fit !== 'fill' ? fit : 'cover' };
		}
	}
	var w = own ? Number( own.getAttribute( 'width' ) ) : 0;
	var h = own ? Number( own.getAttribute( 'height' ) ) : 0;
	return w && h ? { w: w, h: h, fit: 'cover' } : null;
}

export function cloneResult( template, templateUrl, titleText, card ) {
	var item = template.cloneNode( true );
	item.classList.add( 'irios-sponsored' );
	item.removeAttribute( 'id' );
	item.style.display = '';
	Array.prototype.forEach.call( item.querySelectorAll( 'a[href]' ), function ( a ) {
		if ( normalizeUrl( a.href ) === templateUrl ) {
			a.setAttribute( 'href', card.link );
		}
	} );
	var img = item.querySelector( 'img' );
	if ( img && card.image ) {
		var box = renderedImageBox( template );
		img.setAttribute( 'src', card.image );
		img.removeAttribute( 'srcset' );
		img.removeAttribute( 'sizes' );
		img.setAttribute( 'alt', card.title );
		if ( box ) {
			// Same box as the results' images as the theme renders them (its CSS may fix the height, so the width/height
			// attributes are not the shape on screen), even if the ad's image has a different aspect ratio.
			img.style.aspectRatio = box.w + ' / ' + box.h;
			img.style.objectFit = box.fit;
			img.style.height = 'auto';
		}
	}
	Array.prototype.forEach.call( item.querySelectorAll( '*' ), function ( el ) {
		if ( titleText && ! el.children.length && el.textContent.trim() === titleText ) {
			el.textContent = card.title;
		}
	} );
	var price = item.querySelector( '[class*="price"]' );
	if ( price ) {
		price.innerHTML = card.priceHtml || ''; // never the cloned result's price
	}
	var excerpt = item.querySelector( '[class*="excerpt"], .entry-summary, .post-content p, p.desc, .desc' );
	if ( excerpt ) {
		var text = ( card.excerpt || '' ).replace( /\u00a0/g, ' ' ).trim();
		excerpt.textContent = text;
		excerpt.style.display = text ? '' : 'none'; // no excerpt, nothing (never the cloned result's)
	}
	var date = item.querySelector( '[class*="date"]' );
	if ( date && card.date ) {
		date.textContent = card.date;
	}
	return item;
}

export function insertCards( grid, placements, cards ) {
	var byId = {};
	cards.forEach( function ( card ) {
		byId[ card.id ] = card;
	} );
	// First hide the duplicated organic results, then place the ads: positions count the grid as it ends up.
	var items = [];
	placements.forEach( function ( p ) {
		var card = byId[ String( nativeOf( p.response ).product.id ) ];
		if ( ! card ) {
			return; // product not visible in the store: neither rendered nor tracked
		}
		var holder = document.createElement( 'div' );
		holder.innerHTML = card.html;
		var item = holder.querySelector( '.product, .wc-block-product' ) || holder.firstElementChild;
		if ( ! item ) {
			return;
		}
		// Same product among this page's organic results: hide it to avoid a duplicate.
		var duplicate = grid.querySelector( ':scope > .post-' + card.productId + ':not(.irios-sponsored)' );
		if ( duplicate ) {
			duplicate.style.display = 'none';
		}
		item.classList.add( 'irios-sponsored' );
		wireBlockAddToCart( item );
		items.push( { item: item, placement: p } );
	} );
	var layout = loopLayoutClasses( grid );
	items.forEach( function ( entry ) {
		layout.forEach( function ( c ) {
			entry.item.classList.add( c );
		} );
		placeAt( grid, entry.item, entry.placement.position );
		addLabel( entry.item, entry.placement.response ); // already on the page: inherits the title's computed alignment
		trackAd( entry.item, entry.placement.response );
	} );
	fixLoopClasses( grid );
}

// Card classes that belong to its product (not to the listing layout)
export var PRODUCT_CLASS = /^(post-\d+|product_cat-|product_tag-|product-type-|status-|pa_|irios-)|^(instock|outofstock|onbackorder|first|last|sale|featured|downloadable|virtual|purchasable|shipping-taxable|taxable|has-post-thumbnail)$/;

/**
 * Classes the theme adds to every listing card when rendering the page (e.g. Astra: ast-grid-common-col…) but
 * not to one rendered alone via REST: those shared by all organic cards, minus the per-product ones.
 */
export function loopLayoutClasses( grid ) {
	var organic = Array.prototype.filter.call( grid.children, function ( el ) {
		return el.matches( '.product' ) && ! el.classList.contains( 'irios-sponsored' );
	} );
	if ( ! organic.length ) {
		return [];
	}
	return Array.prototype.filter.call( organic[ 0 ].classList, function ( c ) {
		return ! PRODUCT_CLASS.test( c ) && organic.every( function ( el ) {
			return el.classList.contains( c );
		} );
	} );
}

/**
 * Classic themes: WooCommerce marks the first and last card of each row with `first`/`last` and many themes
 * (Storefront included) break rows with them. They are recalculated after inserting or hiding cards.
 */
export function fixLoopClasses( grid ) {
	if ( grid.matches( '.wc-block-product-template' ) ) {
		return;
	}
	// Columns: columns-N on the grid (WooCommerce) or list-N-item on the cards (some themes).
	var match = ( grid.className || '' ).match( /columns-(\d+)/ ) ||
		( ( grid.firstElementChild && grid.firstElementChild.className ) || '' ).match( /list-(\d+)-item/ );
	if ( ! match ) {
		return;
	}
	var columns = Number( match[ 1 ] );
	var visible = Array.prototype.filter.call( grid.children, function ( li ) {
		return li.style.display !== 'none';
	} );
	visible.forEach( function ( li, i ) {
		li.classList.toggle( 'first', i % columns === 0 );
		li.classList.toggle( 'last', i % columns === columns - 1 );
	} );
}

