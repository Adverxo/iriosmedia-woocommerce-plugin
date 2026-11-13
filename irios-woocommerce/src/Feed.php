<?php

namespace Irios\WooCommerce;

/**
 * Read-only API for the IRIOS Media sync service, with the shopper's identifiers already hashed here, in the store
 * (SHA-256 of the normalized email and of the phone in E.164, the same rules as the storefront): names, emails,
 * phones, addresses and IP addresses never leave WooCommerce.
 *
 *   GET /wp-json/irios/v1/orders?status=processing,completed&modified_after=&created_after=&created_before=&page=&per_page=
 *   GET /wp-json/irios/v1/orders/<id>
 *   GET /wp-json/irios/v1/customers?page=&per_page=   (registered customers with an order placed with consent)
 *   GET /wp-json/irios/v1/customers/<id>
 *
 * Paginated like the WooCommerce REST API (X-WP-Total, X-WP-TotalPages). Authenticated with a WooCommerce REST API key
 * with read access, like the wc/v3 API, and only for users who can read the shop's orders.
 */
final class Feed {

	public const NAMESPACE = 'irios/v1';
	private const PAID     = array( 'processing', 'completed' );
	private const MAX_PAGE = 100;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		// WooCommerce authenticates its API keys only on its own routes: also on these
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( self::class, 'is_feed_request' ) );
	}

	/** @param bool $is_wc_request */
	public static function is_feed_request( $is_wc_request ): bool {
		if ( $is_wc_request ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return 1 === preg_match( '#/' . preg_quote( self::NAMESPACE, '#' ) . '/(orders|customers)(/|\?|$)#', $uri )
			|| 1 === preg_match( '#[?&]rest_route=/' . preg_quote( self::NAMESPACE, '#' ) . '/(orders|customers)#', rawurldecode( $uri ) );
	}

	public static function routes(): void {
		$page = array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 50,
				'minimum' => 1,
				'maximum' => self::MAX_PAGE,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'orders' ),
				'permission_callback' => array( self::class, 'allowed' ),
				'args'                => $page + array(
					'status'         => array(
						'type'    => 'string',
						'default' => implode( ',', self::PAID ),
					),
					'modified_after' => array( 'type' => 'string' ),
					'created_after'  => array( 'type' => 'string' ),
					'created_before' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'order' ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/customers',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'customers' ),
				'permission_callback' => array( self::class, 'allowed' ),
				'args'                => $page,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/customers/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'customer' ),
				'permission_callback' => array( self::class, 'allowed' ),
			)
		);
	}

	/** The API key's user must be able to read the shop's orders (a shop manager or an administrator). */
	public static function allowed(): bool {
		return current_user_can( 'read_private_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function orders( \WP_REST_Request $request ): \WP_REST_Response {
		$statuses = array_values( array_intersect( self::PAID, array_map( 'trim', explode( ',', (string) $request['status'] ) ) ) );
		$args     = array(
			'status'   => $statuses ? $statuses : self::PAID,
			'type'     => 'shop_order',
			'limit'    => (int) $request['per_page'],
			'paged'    => (int) $request['page'],
			'orderby'  => 'date_modified',
			'order'    => 'ASC',
			'paginate' => true,
		);
		$modified = self::timestamp( $request['modified_after'] );
		if ( null !== $modified ) {
			$args['date_modified'] = '>' . $modified;
		}
		$after  = self::timestamp( $request['created_after'] );
		$before = self::timestamp( $request['created_before'] );
		if ( null !== $after && null !== $before ) {
			$args['date_created'] = $after . '...' . $before;
		} elseif ( null !== $after ) {
			$args['date_created'] = '>' . $after;
		} elseif ( null !== $before ) {
			$args['date_created'] = '<' . $before;
		}
		$result = wc_get_orders( $args );
		return self::page( array_map( array( self::class, 'order_data' ), $result->orders ), (int) $result->total, (int) $result->max_num_pages );
	}

	public static function order( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error( 'irios_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( self::order_data( $order ) );
	}

	public static function customers( \WP_REST_Request $request ): \WP_REST_Response {
		$ids      = self::consented_customer_ids();
		$per_page = (int) $request['per_page'];
		$slice    = array_slice( $ids, ( (int) $request['page'] - 1 ) * $per_page, $per_page );
		$data     = array();
		foreach ( $slice as $id ) {
			$customer = self::customer_data( $id );
			if ( null !== $customer ) {
				$data[] = $customer;
			}
		}
		return self::page( $data, count( $ids ), (int) ceil( count( $ids ) / $per_page ) );
	}

	public static function customer( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$customer = in_array( $id, self::consented_customer_ids(), true ) ? self::customer_data( $id ) : null;
		if ( null === $customer ) {
			return new \WP_Error( 'irios_not_found', 'Customer not found or without an order placed with consent.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $customer );
	}

	/**
	 * An order for IRIOS Media: status, dates (GMT), amounts and lines as WooCommerce has them. Only with consent: the
	 * hashed identifiers (customer ID, billing email and phone), the IRIOS browser ID and the billing country, state
	 * and postcode.
	 */
	public static function order_data( \WC_Order $order ): array {
		$consent  = Orders::consented( $order );
		$created  = $order->get_date_created();
		$modified = $order->get_date_modified();
		$items    = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'subtotal'     => wc_format_decimal( $item->get_subtotal() ),
				'subtotal_tax' => wc_format_decimal( $item->get_subtotal_tax() ),
				'total'        => wc_format_decimal( $item->get_total() ),
				'total_tax'    => wc_format_decimal( $item->get_total_tax() ),
			);
		}
		$shipping = $order->get_shipping_methods();
		$method   = $shipping ? reset( $shipping ) : null;
		return array(
			'id'                   => (int) $order->get_id(),
			'number'               => (string) $order->get_order_number(),
			'status'               => (string) $order->get_status(),
			'date_created_gmt'     => $created ? gmdate( 'Y-m-d\TH:i:s', $created->getTimestamp() ) : null,
			'date_modified_gmt'    => $modified ? gmdate( 'Y-m-d\TH:i:s', $modified->getTimestamp() ) : null,
			'consent'              => $consent,
			'customer_id'          => $consent && $order->get_customer_id() > 0 ? (int) $order->get_customer_id() : null,
			'uids'                 => $consent ? self::uids(
				(int) $order->get_customer_id(),
				$order->get_billing_email(),
				$order->get_billing_phone(),
				$order->get_billing_country(),
				(string) $order->get_meta( Orders::META )
			) : null,
			'address'              => $consent ? array(
				'country'  => $order->get_billing_country(),
				'state'    => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
			) : null,
			'currency'             => $order->get_currency(),
			'prices_include_tax'   => (bool) $order->get_prices_include_tax(),
			'total'                => wc_format_decimal( $order->get_total() ),
			'total_tax'            => wc_format_decimal( $order->get_total_tax() ),
			'shipping_total'       => wc_format_decimal( $order->get_shipping_total() ),
			'shipping_tax'         => wc_format_decimal( $order->get_shipping_tax() ),
			'discount_total'       => wc_format_decimal( $order->get_discount_total() ),
			'discount_tax'         => wc_format_decimal( $order->get_discount_tax() ),
			'payment_method_title' => $order->get_payment_method_title(),
			'shipping_method'      => $method ? $method->get_method_title() : null,
			'line_items'           => $items,
		);
	}

	/** A registered customer: customer ID, hashed account email and billing phone, billing country, state and postcode. */
	private static function customer_data( int $id ): ?array {
		$customer = new \WC_Customer( $id );
		if ( ! $customer->get_id() ) {
			return null;
		}
		$email   = $customer->get_email() ? $customer->get_email() : $customer->get_billing_email();
		$created = $customer->get_date_created();
		return array(
			'id'               => $id,
			'date_created_gmt' => $created ? gmdate( 'Y-m-d\TH:i:s', $created->getTimestamp() ) : null,
			'uids'             => self::uids( $id, $email, $customer->get_billing_phone(), $customer->get_billing_country(), '' ),
			'address'          => array(
				'country'  => $customer->get_billing_country(),
				'state'    => $customer->get_billing_state(),
				'postcode' => $customer->get_billing_postcode(),
			),
		);
	}

	/** @return int[] registered customers with at least one order placed with consent, by ID */
	private static function consented_customer_ids(): array {
		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => -1,
				'return'     => 'objects',
				'meta_key'   => Orders::CONSENT_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$ids = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order && $order->get_customer_id() > 0 ) {
				$ids[ (int) $order->get_customer_id() ] = true;
			}
		}
		$ids = array_keys( $ids );
		sort( $ids );
		return $ids;
	}

	/**
	 * Hashed identifiers. Keys: crm, email_sha256, phone_sha256 (E.164 with the billing country, else the store's) and
	 * browser_id (UUID of the irs1P cookie).
	 *
	 * @return array<string,string|null>
	 */
	private static function uids( int $customer_id, ?string $email, ?string $phone, ?string $country, string $browser_id ): array {
		$store = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : null;
		return array(
			'crm'          => $customer_id > 0 ? (string) $customer_id : null,
			'email_sha256' => Identity::email_hash( $email ),
			'phone_sha256' => Identity::phone_hash( $phone, $country, $store ),
			'browser_id'   => 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $browser_id ) ? $browser_id : null,
		);
	}

	private static function page( array $data, int $total, int $pages ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $response;
	}

	/** Unix timestamp of an ISO 8601 date (without a zone, GMT), or null. */
	private static function timestamp( $value ): ?int {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$time = strtotime( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ? $value : $value . 'Z' );
		return false === $time ? null : $time;
	}
}
