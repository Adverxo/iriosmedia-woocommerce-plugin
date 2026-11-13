<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Plugin;
use Irios\WooCommerce\Settings;

/**
 * What the shop manager is told when the plugin is active but nothing is going to be sent: the store looks fine and
 * the ads simply never show up, so the reason has to be on screen.
 */
final class PluginNoticesTest extends WordPressTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn ( string $p = '' ) => 'https://shop.test/wp-admin/' . $p );
		Functions\when( 'get_option' )->alias(
			fn ( string $name, $default = false ) => Settings::OPTION === $name ? $this->settings : ( 'active_plugins' === $name ? $this->active_plugins : $default )
		);
	}

	/** @var string[] */
	private array $active_plugins = array();

	private function notice(): string {
		ob_start();
		Plugin::notice_configuration();
		return (string) ob_get_clean();
	}

	public function test_turned_off_on_purpose_says_nothing(): void {
		$this->settings = array( 'enabled' => 'no', 'js_domain' => '', 'store_id' => '' );

		$this->assertSame( '', $this->notice() );
	}

	public function test_without_the_domain_or_the_store_it_says_it_is_not_configured(): void {
		$this->settings = array( 'enabled' => 'yes', 'js_domain' => '', 'store_id' => '' );

		$notice = $this->notice();

		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'not configured', $notice );
		$this->assertStringContainsString( 'tab=integration&section=irios', $notice, 'with a link to the settings' );
	}

	public function test_with_the_asesor_banner_chosen_but_not_active_it_says_so(): void {
		$this->settings = array(
			'enabled'      => 'yes',
			'js_domain'    => 'shop.iriosmedia.online',
			'store_id'     => '33',
			'consent_mode' => Settings::CONSENT_ASESOR_COOKIES,
		);
		$this->active_plugins = array( 'woocommerce/woocommerce.php' );

		$this->assertStringContainsString( 'Asesor de Cookies', $this->notice() );
	}

	public function test_with_the_asesor_banner_active_there_is_nothing_to_warn_about(): void {
		$this->settings = array(
			'enabled'      => 'yes',
			'js_domain'    => 'shop.iriosmedia.online',
			'store_id'     => '33',
			'consent_mode' => Settings::CONSENT_ASESOR_COOKIES,
		);
		$this->active_plugins = array( 'asesor-cookies-para-la-ley-en-espana/asesor-cookies.php' );

		$this->assertSame( '', $this->notice() );
	}

	public function test_nobody_who_cannot_configure_the_store_is_bothered(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->settings = array( 'enabled' => 'yes', 'js_domain' => '', 'store_id' => '' );

		$this->assertSame( '', $this->notice() );
	}

	public function test_without_woocommerce_it_says_the_plugin_needs_it(): void {
		ob_start();
		Plugin::notice_missing_woocommerce();
		$notice = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'WooCommerce', $notice );
	}
}
