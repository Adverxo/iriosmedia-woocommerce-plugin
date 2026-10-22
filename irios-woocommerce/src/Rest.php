<?php

namespace Irios\WooCommerce;

/**
 * Visitor-dependent data, served by a request that is never cached (pages may be cached):
 * `GET /wp-json/irios/v1/context` → uids of the logged-in customer and the session's pending cart events.
 *
 * And `GET /wp-json/irios/v1/cards?ids=…&layout=classic|block` → product cards for the winning ads, rendered by the
 * store's theme (see Cards).
 */
final class Rest {

	public static function register(): void {
		register_rest_route(
			'irios/v1',
			'/context',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'context' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'irios/v1',
			'/cards',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'cards' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'layout' => array(
						'type'    => 'string',
						'enum'    => array( Cards::LAYOUT_CLASSIC, Cards::LAYOUT_BLOCK, Cards::LAYOUT_DATA ),
						'default' => Cards::LAYOUT_CLASSIC,
					),
					'imageSize' => array( // image size of the cloned result (layout=data)
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{0,40}$',
						'default' => '',
					),
				),
			)
		);
	}

	public static function cards( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = Cards::parse_ids( (string) $request->get_param( 'ids' ), max( 1, count( Settings::load()->sponsored_slots() ) ) );
		if ( '1' === (string) $request->get_param( 'irios_debug' ) ) {
			self::diagnose_abort();
		}
		$cards = Cards::render( $ids, (string) $request->get_param( 'layout' ), (string) $request->get_param( 'imageSize' ) );
		Cards::$step = 'done';
		$body        = array( 'cards' => $cards );
		if ( '1' === (string) $request->get_param( 'irios_debug' ) ) {
			$body['irios_debug'] = array( 'errors' => Cards::$errors ); // fields that failed and why
		}
		$response = new \WP_REST_Response( $body );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' ); // up-to-date price and stock
		return $response;
	}

	/**
	 * /cards diagnostics with ?irios_debug=1, without access to the server logs: if another plugin ends execution
	 * (exit) while the cards are being built, the response arrives empty; PHP still runs shutdown functions, and this
	 * one prints the last step reached and the PHP error, if any.
	 */
	private static function diagnose_abort(): void {
		register_shutdown_function(
			static function (): void {
				if ( 'done' === Cards::$step ) {
					return;
				}
				$error = error_get_last();
				echo wp_json_encode( // phpcs:ignore
					array(
						'irios_debug' => 'the response was cut off while building the cards',
						'step'        => Cards::$step,
						'php_error'   => $error ? $error['message'] . ' (' . basename( (string) $error['file'] ) . ':' . $error['line'] . ')' : null,
					)
				);
			}
		);
	}

	public static function context(): \WP_REST_Response {
		$settings = Settings::load();
		$uids     = array();
		$events   = array();
		if ( Consent::granted( $settings ) ) {
			if ( is_user_logged_in() ) {
				$user     = wp_get_current_user();
				$customer = class_exists( \WC_Customer::class ) ? new \WC_Customer( (int) $user->ID ) : null;
				$uids     = Identity::customer_uids(
					(int) $user->ID,
					$user->user_email,
					$customer ? $customer->get_billing_phone() : null,
					$customer ? $customer->get_billing_country() : null,
					function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : null
				);
			}
			$events = Events::take_pending();
		}
		$response = new \WP_REST_Response(
			array(
				'uids'   => $uids,
				'events' => $events,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}
}
