<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Irios\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the code that calls WordPress and WooCommerce, without installing them: functions are mocked with Brain
 * Monkey (settings and transients in memory, WC() with each test's session and cart) and classes are the doubles in
 * tests/doubles.php.
 */
abstract class WordPressTestCase extends TestCase {

	/** Plugin settings (woocommerce_irios_settings option). */
	protected array $settings = array();
	/** Stored transients: key → value. */
	protected array $transients = array();
	/** What WC() returns. */
	protected \stdClass $wc;
	protected int $user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_COOKIE                    = array();
		\WC_Session_Handler::$cookie = false;
		// Events state that lasts one request in WordPress: each test is a new request
		( new \ReflectionProperty( \Irios\WooCommerce\Events::class, 'quantity_handled' ) )->setValue( null, array() );
		$this->wc                   = (object) array(
			'session'   => new \WC_Session_Fake(),
			'cart'      => new \WC_Cart(),
			'countries' => null,
		);

		Functions\when( 'get_option' )->alias(
			fn ( $name, $default = false ) => Settings::OPTION === $name ? $this->settings : $default
		);
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'WC' )->alias( fn () => $this->wc );
		Functions\when( 'is_user_logged_in' )->alias( fn () => $this->user_id > 0 );
		Functions\when( 'get_current_user_id' )->alias( fn () => $this->user_id );
		Functions\when( 'wc_get_price_to_display' )->alias( fn ( \WC_Product $product ) => $product->get_price() );
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_COOKIE = array();
		parent::tearDown();
	}

	/** Visitor who accepted marketing cookies ("Asesor de Cookies RGPD" banner). */
	protected function given_consent(): void {
		$this->settings['consent_mode']                       = Settings::CONSENT_ASESOR_COOKIES;
		$_COOKIE[ \Irios\WooCommerce\Consent::ASESOR_COOKIE ] = '{"necessary":true,"marketing":true}';
	}

	/** Visitor who rejected marketing cookies. */
	protected function given_no_consent(): void {
		$this->settings['consent_mode']                       = Settings::CONSENT_ASESOR_COOKIES;
		$_COOKIE[ \Irios\WooCommerce\Consent::ASESOR_COOKIE ] = '{"necessary":true,"marketing":false}';
	}

	/** Products returned by wc_get_product(), by id. */
	protected function given_products( \WC_Product ...$products ): void {
		$by_id = array();
		foreach ( $products as $product ) {
			$by_id[ $product->get_id() ] = $product;
		}
		Functions\when( 'wc_get_product' )->alias( fn ( $id ) => $by_id[ (int) $id ] ?? false );
	}

	/** Pending events stored for a WooCommerce session customer. */
	protected function pending_for( string $customer ): array {
		return $this->transients[ 'irios_ev_' . md5( $customer ) ] ?? array();
	}
}
