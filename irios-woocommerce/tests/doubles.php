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
