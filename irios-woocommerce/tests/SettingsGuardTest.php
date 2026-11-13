<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Settings;
use Irios\WooCommerce\SettingsGuard;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The previous version of the plugin ("Irios for WooCommerce") deletes the shared settings option in its uninstall.php:
 * deleting it from Plugins must not wipe this plugin's settings.
 */
final class SettingsGuardTest extends WordPressTestCase {

	private const OWN = 'irios-woocommerce/irios-woocommerce.php';
	private const OLD = 'irios-for-woocommerce/irios-for-woocommerce.php';

	public function test_keeps_the_settings_only_when_another_plugin_uninstalls_them(): void {
		$this->assertTrue( SettingsGuard::should_keep( Settings::OPTION, self::OLD, self::OWN ) );
		$this->assertFalse( SettingsGuard::should_keep( Settings::OPTION, self::OWN, self::OWN ), 'own uninstall deletes them for good' );
		$this->assertFalse( SettingsGuard::should_keep( Settings::OPTION, null, self::OWN ), 'not during an uninstall' );
		$this->assertFalse( SettingsGuard::should_keep( 'other_option', self::OLD, self::OWN ), 'other options are not touched' );
	}

	/** Same order of calls as WordPress's delete_option(): action delete_option, row deleted, delete_option_{option}. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_old_plugins_uninstall_leaves_the_settings_as_they_were(): void {
		define( 'IRIOS_WC_FILE', '/var/www/html/wp-content/plugins/' . self::OWN );
		define( 'WP_UNINSTALL_PLUGIN', self::OLD );
		Functions\when( 'plugin_basename' )->justReturn( self::OWN );
		$this->settings = array( 'js_domain' => 'shop.iriosmedia.online', 'store_id' => '42' );
		$written        = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$written ) {
				$written[ $name ] = $value;
				return true;
			}
		);

		SettingsGuard::before_delete( Settings::OPTION );
		$this->settings = array(); // the old plugin's uninstall.php deletes the row
		SettingsGuard::after_delete();

		$this->assertSame( array( Settings::OPTION => array( 'js_domain' => 'shop.iriosmedia.online', 'store_id' => '42' ) ), $written );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_outside_an_uninstall_deleting_the_settings_deletes_them(): void {
		define( 'IRIOS_WC_FILE', '/var/www/html/wp-content/plugins/' . self::OWN );
		Functions\when( 'plugin_basename' )->justReturn( self::OWN );
		$this->settings = array( 'store_id' => '42' );
		Functions\expect( 'update_option' )->never();

		SettingsGuard::before_delete( Settings::OPTION );
		SettingsGuard::after_delete();

		$this->addToAssertionCount( 1 );
	}
}
