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

/** WordPress error: the code only asks whether it is one. */
class WP_Error {
	public function __construct(public string $code = 'error', public string $message = '') {}
}

/** A WordPress theme: only its name, which is what the compatibility warning shows. */
class WP_Theme {
	public function __construct(private string $name = 'Storefront') {}

	public function get( string $field ) {
		return 'Name' === $field ? $this->name : '';
	}
}

/** Base class of a WooCommerce integration: only what Integration reads from it. */
class WC_Integration {
	public $id = '';
	public $method_title = '';
	public $method_description = '';
	public array $form_fields = array();
	public array $settings = array();

	public function init_settings(): void {}

	public function get_option( string $key, $default = '' ) {
		return $this->settings[ $key ] ?? $default;
	}

	/** Name of the field in the form, as WooCommerce builds it. */
	public function get_field_key( string $key ): string {
		return 'woocommerce_' . $this->id . '_' . $key;
	}
}

/** Errors WooCommerce shows at the top of the settings screen. */
class WC_Admin_Settings {
	/** @var string[] */
	public static array $errors = array();

	public static function add_error( string $message ): void {
		self::$errors[] = $message;
	}
}

class WC_Product {
	private string $status = 'publish';
	private bool $visible = true;
	private int $parent = 0;

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

	public function with( string $status, bool $visible = true, int $parent = 0 ): self {
		$this->status = $status;
		$this->visible = $visible;
		$this->parent = $parent;
		return $this;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function is_visible(): bool {
		return $this->visible;
	}

	public function get_parent_id(): int {
		return $this->parent;
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

	public function get_description(): string {
		return 'Descripción del producto ' . $this->id;
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

/** WordPress database access: records the SQL the code builds and answers with what the test sets. */
class WPDB_Fake {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	/** @var string[] SQL actually run */
	public array $queries = array();
	/** @var mixed[] rows for get_col */
	public array $col = array();
	/** @var mixed value for get_var */
	public $var = null;

	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_col( string $sql ): array {
		$this->queries[] = $sql;
		return $this->col;
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		return $this->var;
	}
}

/** A registered customer, as the feed reads them. An id of 0 means there is no such customer. */
class WC_Customer {
	public function __construct( private int $id ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_email(): string {
		return 'cliente' . $this->id . '@example.com';
	}

	public function get_billing_email(): string {
		return '';
	}

	public function get_billing_phone(): string {
		return '';
	}

	public function get_billing_country(): string {
		return 'ES';
	}

	public function get_billing_state(): string {
		return 'M';
	}

	public function get_billing_postcode(): string {
		return '28001';
	}

	public function get_date_created() {
		return null;
	}
}
