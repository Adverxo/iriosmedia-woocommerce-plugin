<?php
// Minimal doubles of the WordPress and WooCommerce classes the plugin uses: only what the code and the tests read
// (WordPressTestCase). WordPress functions are mocked with Brain Monkey in each test.

// phpcs:disable Generic.Files.OneObjectStructurePerFile

class WP_REST_Response {
	public $data;
	public array $headers = array();

	public function __construct( $data = null ) {
		$this->data = $data;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	public function get_data() {
		return $this->data;
	}
}

class WP_REST_Request implements ArrayAccess {
	public function __construct( private array $params = array() ) {}

	public function get_param( string $name ) {
		return $this->params[ $name ] ?? null;
	}

	public function offsetExists( $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->params[ $offset ] ?? null;
	}

	public function offsetSet( $offset, $value ): void {
		$this->params[ $offset ] = $value;
	}

	public function offsetUnset( $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

class WC_Product {
	public function __construct( private int $id, private float $price = 10.0, private string $type = 'simple' ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_price(): float {
		return $this->price;
	}

	public function is_type( string $type ): bool {
		return $type === $this->type;
	}

	public function get_status(): string {
		return 'publish';
	}

	public function is_visible(): bool {
		return true;
	}

	public function get_name(): string {
		return 'Product ' . $this->id;
	}

	public function get_permalink(): string {
		return 'https://shop.test/product/p' . $this->id . '/';
	}

	public function get_image_id(): int {
		return 0;
	}

	public function get_short_description(): string {
		return '';
	}

	public function get_date_created() {
		return null;
	}

	public function get_price_html(): string {
		return '';
	}
}

class WC_Order {
	public array $meta  = array();
	public int $saves   = 0;

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data(): void {
		++$this->saves;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}
}

class WC_Order_Item_Product {
	public function __construct( private array $data ) {
	}

	public function __call( string $name, array $args ) {
		return $this->data[ substr( $name, 4 ) ] ?? null;
	}
}

class WC_Cart {
	public array $cart_contents         = array();
	public array $removed_cart_contents = array();

	public function is_empty(): bool {
		return array() === $this->cart_contents;
	}

	public function get_cart(): array {
		return $this->cart_contents;
	}
}

/** The visitor's WooCommerce session (WC()->session). */
class WC_Session_Fake {
	public bool $has_session    = true;
	public int $cookies_created = 0;

	public function __construct( public string $customer_id = 'visitor-1' ) {}

	public function has_session(): bool {
		return $this->has_session;
	}

	public function set_customer_session_cookie( bool $set ): void {
		$this->has_session = true;
		++$this->cookies_created;
	}

	public function get_customer_id(): string {
		return $this->customer_id;
	}
}

/** WooCommerce session cookie: [ customer id, expiry, … ] or false when there is no cookie. */
class WC_Session_Handler {
	public static $cookie = false;

	public function get_session_cookie() {
		return self::$cookie;
	}
}

class WP_Term {
	public int $term_id;
	public string $name;

	public function __construct( int $term_id, string $name ) {
		$this->term_id = $term_id;
		$this->name    = $name;
	}
}
