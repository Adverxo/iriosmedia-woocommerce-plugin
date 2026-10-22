<?php

namespace Irios\WooCommerce;

/**
 * New version notice: the plugin does not update itself (it is not part of the WordPress update system). The latest
 * GitHub release is checked every 12 hours and, if newer than the installed one, a notice is shown on the dashboard and
 * in Plugins with links to the notes and the zip; updating is up to the administrator (upload the zip, "Replace").
 * The request to api.github.com carries no store data. Disabled with the `irios_woocommerce_check_updates` filter.
 */
final class Updates {

	public const REPOSITORY = 'Adverxo/iriosmedia-woocommerce-plugin';
	/** Release asset with a fixed name (see .github/workflows/release.yml). */
	public const ZIP_ASSET = 'irios-woocommerce.zip';

	private const TRANSIENT = 'irios_wc_latest_release'; // also in uninstall.php
	/** User meta: version whose notice was dismissed (the next version's notice shows again). */
	private const DISMISSED_META = 'irios_wc_dismissed_release'; // also in uninstall.php
	private const DISMISS_ACTION = 'irios_wc_dismiss_release';
	/** Screens where the notice is shown (and where the check runs, at most once every 12 hours). */
	private const SCREENS = array( 'dashboard', 'plugins', 'update-core', 'woocommerce_page_wc-settings' );

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'after_plugin_row_' . plugin_basename( IRIOS_WC_FILE ), array( self::class, 'plugin_row' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( self::class, 'dismiss' ) );
	}

	/**
	 * Published GitHub release (`GET /repos/{repo}/releases/latest`) → version, release page and zip.
	 * Null if it is not an X.Y.Z version or the links are not on github.com.
	 *
	 * @param mixed $data Decoded response.
	 * @return array{version: string, url: string, zip: string}|null
	 */
	public static function parse_release( $data ): ?array {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = (string) ( $data['tag_name'] ?? '' );
		$url     = (string) ( $data['html_url'] ?? '' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || 0 !== strpos( $url, 'https://github.com/' ) ) {
			return null;
		}
		$zip = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$download = is_array( $asset ) ? (string) ( $asset['browser_download_url'] ?? '' ) : '';
			if ( self::ZIP_ASSET === ( $asset['name'] ?? '' ) && 0 === strpos( $download, 'https://github.com/' ) ) {
				$zip = $download;
			}
		}
		return array(
			'version' => $version,
			'url'     => $url,
			'zip'     => $zip,
		);
	}

	/** Notify? Only with an installed X.Y.Z version: source code (0.0.0-dev) and untagged zips never notify. */
	public static function is_newer( string $installed, string $available ): bool {
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/', $installed ) && version_compare( $available, $installed, '>' );
	}

	/** Latest release, cached for 12 hours (1 hour if GitHub does not respond). */
	public static function latest(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached['release'] ?? null;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'irios-woocommerce/' . IRIOS_WC_VERSION,
				),
			)
		);
		$failed  = is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 500;
		$release = $failed ? null : self::parse_release( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		set_transient( self::TRANSIENT, array( 'release' => $release ), $failed ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Release newer than the installed one, if the current user should be notified. */
	private static function available(): ?array {
		// Without an installed X.Y.Z version GitHub is not queried (there would be no notice)
		if ( ! current_user_can( 'update_plugins' ) || ! preg_match( '/^\d+\.\d+\.\d+$/', IRIOS_WC_VERSION ) ) {
			return null;
		}
		if ( ! apply_filters( 'irios_woocommerce_check_updates', true ) ) {
			return null;
		}
		$release = self::latest();
		return $release && self::is_newer( IRIOS_WC_VERSION, $release['version'] ) ? $release : null;
	}

	/** "Release notes · Download the zip" */
	private static function links( array $release ): string {
		$links = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $release['url'] ), esc_html__( 'Release notes', 'irios-woocommerce' ) );
		if ( '' !== $release['zip'] ) {
			$links .= sprintf( ' · <a href="%s">%s</a>', esc_url( $release['zip'] ), esc_html__( 'Download the zip', 'irios-woocommerce' ) );
		}
		return $links;
	}

	private static function message( array $release ): string {
		return esc_html(
			sprintf(
				/* translators: 1: new version, 2: installed version */
				__( 'Irios for WooCommerce %1$s is available (installed: %2$s).', 'irios-woocommerce' ),
				$release['version'],
				IRIOS_WC_VERSION
			)
		);
	}

	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}
		$release = self::available();
		if ( ! $release || get_user_meta( get_current_user_id(), self::DISMISSED_META, true ) === $release['version'] ) {
			return;
		}
		$dismiss = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::DISMISS_ACTION,
					'version' => $release['version'],
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION
		);
		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p>%3$s · <a href="%4$s">%5$s</a></p></div>',
			self::message( $release ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in message()
			esc_html__( 'The plugin does not update automatically: to update, download the zip and upload it in Plugins → Add New → Upload Plugin (WordPress offers to replace the installed version and the settings are kept).', 'irios-woocommerce' ),
			self::links( $release ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in links()
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'irios-woocommerce' )
		);
	}

	/** Row below the plugin in Plugins, like the WordPress update row (cannot be dismissed). */
	public static function plugin_row(): void {
		$release = self::available();
		if ( ! $release ) {
			return;
		}
		$table = function_exists( '_get_list_table' ) ? _get_list_table( 'WP_Plugins_List_Table' ) : null;
		printf(
			'<tr class="plugin-update-tr active"><td colspan="%1$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%2$s %3$s</p></div></td></tr>',
			$table ? (int) $table->get_column_count() : 4,
			self::message( $release ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in message()
			self::links( $release ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in links()
		);
	}

	public static function dismiss(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		$version = sanitize_text_field( wp_unslash( (string) ( $_GET['version'] ?? '' ) ) );
		if ( preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			update_user_meta( get_current_user_id(), self::DISMISSED_META, $version );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
