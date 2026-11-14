import { DEDUP_MS, cfg, state } from './config.mjs';
import { hasConsent } from './consent.mjs';
import { start } from './tracker.mjs';

// ---- API for store events -----------------------------------------------------------------------------------------

export function enqueue( event ) {
	if ( state.ready ) {
		send( event );
	} else {
		state.queue.push( event );
	}
}

export function isDuplicate( event ) {
	var key = JSON.stringify( [ event.id, event.value, event.products ] );
	var now = Date.now();
	var last = state.recent[ key ];
	// Anything older than DEDUP_MS cannot be a duplicate any more: dropped so the map does not grow for the
	// whole visit (a long browse is hundreds of different events)
	Object.keys( state.recent ).forEach( function ( old ) {
		if ( now - state.recent[ old ] >= DEDUP_MS ) {
			delete state.recent[ old ];
		}
	} );
	state.recent[ key ] = now;
	return last !== undefined && now - last < DEDUP_MS;
}

export function send( event ) {
	if ( isDuplicate( event ) ) {
		return;
	}
	var payload = Object.assign( {}, event, {
		storeId: cfg.storeId,
		sessionId: state.sessionId,
		uids: state.uids,
		consent: { gdpr: true }, // only sent with consent
	} );
	try {
		new window.IriosEvent( [ payload ] );
	} catch ( e ) {
		if ( window.console ) {
			window.console.warn( '[irios] event not sent', payload.id, e );
		}
	}
}

window.IriosWoo = {
	/** Queues or sends an event ({id, value} or {id, products}). Without consent, it is dropped. */
	track: function ( event ) {
		if ( ! hasConsent() ) {
			return;
		}
		enqueue( event );
		start();
	},
	context: function () {
		return { ready: state.ready, uids: state.uids.slice(), sessionId: state.sessionId };
	},
};

