import { page, state } from './config.mjs';
import { hasConsent } from './consent.mjs';
import { send } from './events-api.mjs';
import { fetchContext } from './identity.mjs';

// ---- Store events -----------------------------------------------------------------------------------------------

export var PENDING_SEARCH_KEY = 'irios_pending_search';

export function pageEvents() {
	var events = [ { id: 'page-view', value: window.location.pathname } ];
	// Search made in live search and finished by clicking a result (see liveSearch).
	var pending = null;
	try {
		pending = window.sessionStorage.getItem( PENDING_SEARCH_KEY );
		window.sessionStorage.removeItem( PENDING_SEARCH_KEY );
	} catch ( e ) {}
	if ( pending ) {
		events.push( { id: 'search', value: pending } );
	}
	if ( page.type === 'category' && page.value ) {
		events.push( { id: 'category-view', value: page.value } );
	} else if ( page.type === 'search' && page.value ) {
		events.push( { id: 'search', value: page.value } );
	} else if ( page.type === 'checkout' ) {
		events.push( { id: 'checkout-step', value: page.value } );
	} else if ( page.type === 'product' && page.product ) {
		state.lastVariation = page.variable ? page.product.id : null;
		events.push( { id: 'product-view', products: [ page.product ] } );
	}
	return events;
}

/** Variable product: event for the selected variation, unless it is the same as the previous one. */
export function onVariation( variation ) {
	if ( ! variation || ! variation.variation_id || ! page.product ) {
		return;
	}
	var id = String( variation.variation_id );
	if ( id === state.lastVariation ) {
		return;
	}
	state.lastVariation = id;
	var price = Number( variation.display_price );
	window.IriosWoo.track( {
		id: 'product-view',
		products: [ { id: id, price: isFinite( price ) ? price : page.product.price, quantity: 1 } ],
	} );
}

/** After an AJAX cart change: collects the events the server left in the session. */
export function flushCartEvents() {
	if ( ! hasConsent() ) {
		return;
	}
	if ( ! state.ready ) {
		state.flushWhenReady = true; // cart changed before startup finished
		return;
	}
	fetchContext().then( function ( ctx ) {
		ctx.events.forEach( send );
	} );
}

if ( window.jQuery ) {
	window.jQuery( document.body ).on( 'found_variation', 'form.variations_form', function ( e, variation ) {
		onVariation( variation );
	} );
	window.jQuery( document.body ).on( 'added_to_cart removed_from_cart updated_cart_totals', flushCartEvents );
}
document.body.addEventListener( 'wc-blocks_added_to_cart', flushCartEvents );
document.body.addEventListener( 'wc-blocks_removed_from_cart', flushCartEvents );

/**
 * The Cart block (and other blocks) change the cart via the Store API without firing DOM events: watch the
 * `wc/store/cart` store and, when its line items change, collect the events from the session.
 */
export function watchBlocksCart() {
	var data = window.wp && window.wp.data;
	if ( ! data || typeof data.select !== 'function' || ! data.select( 'wc/store/cart' ) ) {
		return;
	}
	var signature = function () {
		var cart = data.select( 'wc/store/cart' ).getCartData();
		return ( cart && cart.items ? cart.items : [] ).map( function ( item ) {
			return item.key + ':' + item.quantity;
		} ).join( '|' );
	};
	var last = signature();
	var timer = null;
	data.subscribe( function () {
		var current = signature();
		if ( current === last ) {
			return;
		}
		last = current;
		window.clearTimeout( timer );
		timer = window.setTimeout( flushCartEvents, 300 );
	} );
}

if ( document.readyState === 'complete' ) {
	watchBlocksCart();
} else {
	window.addEventListener( 'load', watchBlocksCart );
}

