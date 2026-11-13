<?php

namespace Irios\WooCommerce;

/**
 * Keeps the settings when another plugin's uninstall deletes them. The previous version of this plugin ("Irios for
 * WooCommerce", folder irios-for-woocommerce) used the same option and its uninstall.php deletes it: deleting that old
 * plugin from Plugins after installing this one would wipe this one's settings. While another plugin is being
 * uninstalled, the option is saved before it is deleted and written back right after.
 */
final class SettingsGuard {

	/** Settings saved while another plugin's uninstall deletes them (null: nothing to restore). */
	private static ?array $kept = null;

	public static function register(): void {
		add_action( 'delete_option', array( self::class, 'before_delete' ) );
		add_action( 'delete_option_' . Settings::OPTION, array( self::class, 'after_delete' ) );
	}

	public static function before_delete( $option ): void {
		self::$kept = null;
		if ( ! self::should_keep( (string) $option, self::uninstalling_plugin(), plugin_basename( IRIOS_WC_FILE ) ) ) {
			return;
		}
		$value      = get_option( Settings::OPTION, null );
		self::$kept = is_array( $value ) ? $value : null;
	}

	public static function after_delete(): void {
		if ( null === self::$kept ) {
			return;
		}
		$value      = self::$kept;
		self::$kept = null;
		update_option( Settings::OPTION, $value );
	}

	/**
	 * The settings option is being deleted by the uninstall of a plugin that is not this one ($own: this plugin's
	 * basename). This plugin's own uninstall deletes them for good.
	 */
	public static function should_keep( string $option, ?string $uninstalling, string $own ): bool {
		return Settings::OPTION === $option && null !== $uninstalling && '' !== $uninstalling && $uninstalling !== $own;
	}

	/** Basename of the plugin whose uninstall.php is running (WordPress defines WP_UNINSTALL_PLUGIN), or null. */
	private static function uninstalling_plugin(): ?string {
		return defined( 'WP_UNINSTALL_PLUGIN' ) ? (string) constant( 'WP_UNINSTALL_PLUGIN' ) : null;
	}
}
