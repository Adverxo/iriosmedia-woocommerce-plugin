<?php

namespace Irios\WooCommerce;

/**
 * Plugin settings (option `woocommerce_irios_settings`, the one managed by the WooCommerce integration).
 * Validation is pure so it can be tested without WordPress.
 */
final class Settings {

	public const OPTION = 'woocommerce_irios_settings';

	public const CONSENT_WP_CONSENT_API = 'wp_consent_api';
	public const CONSENT_ALWAYS         = 'always';
	/** "Asesor de Cookies RGPD" banner (slug asesor-cookies-para-la-ley-en-espana): does not use the WP Consent API. */
	public const CONSENT_ASESOR_COOKIES = 'asesor_cookies';

	/** Automatic positions for a banner (besides the shortcode, which always works). */
	public const POSITION_NONE                       = '';
	public const POSITION_CATEGORY_AFTER_PRODUCTS    = 'category_after_products';
	public const POSITION_PRODUCT_BEFORE_DESCRIPTION = 'product_before_description';
	public const POSITIONS                           = array(
		self::POSITION_NONE,
		self::POSITION_CATEGORY_AFTER_PRODUCTS,
		self::POSITION_PRODUCT_BEFORE_DESCRIPTION,
	);

	private const DEFAULTS = array(
		'js_domain'                => '',
		'store_id'                 => '',
		'sponsored_enabled'        => 'no',
		'banners_enabled'          => 'no',
		'banners'                  => '[]',
		'sponsored_slots'          => '', // JSON; empty = never saved (the legacy configuration is used)
		// Legacy configuration (a single ad unit at the top): still read if no slots have been set up.
		'search_ad_unit'           => '',
		'search_ad_unit_auth'      => '',
		'sponsored_label'          => '', // empty: "Sponsored" in the store's language
		'keep_single_result'       => 'yes',
		'max_ads'                  => '4',
		'consent_mode'             => self::CONSENT_WP_CONSENT_API,
	);

	/** @var array<string,string> */
	private array $values;

	/**
	 * @param array<string,mixed> $values
	 */
	public function __construct( array $values ) {
		$this->values = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value                = $values[ $key ] ?? $default;
			$this->values[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : $default;
		}
	}

	public static function load(): self {
		$stored = get_option( self::OPTION, array() );
		return new self( is_array( $stored ) ? $stored : array() );
	}

	/** @return array<string,string> */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/** Without a JS domain or a storeId nothing is loaded. */
	public function is_configured(): bool {
		return '' !== $this->js_domain() && null !== $this->store_id();
	}

	public function js_domain(): string {
		return self::normalize_domain( $this->values['js_domain'] );
	}

	public function store_id(): ?int {
		return ctype_digit( $this->values['store_id'] ) ? (int) $this->values['store_id'] : null;
	}


	public function search_ad_unit(): ?int {
		return ctype_digit( $this->values['search_ad_unit'] ) ? (int) $this->values['search_ad_unit'] : null;
	}

	/** The ad unit's `auth` key (required by irios-loader.js). Not a secret: it travels in the browser. */
	public function search_ad_unit_auth(): string {
		return $this->values['search_ad_unit_auth'];
	}

	/**
	 * Banners set up in the settings (tag → ad unit), stored as JSON.
	 *
	 * @return array<int,array{tag:string,id:int,auth:string,position:string}>
	 */
	public function banners(): array {
		$rows = json_decode( $this->values['banners'], true );
		return is_array( $rows ) ? self::sanitize_banners( $rows )['banners'] : array();
	}

	/** Banner by its tag ([irios_banner tag="portada"]), case-insensitive; null if it does not exist. */
	public function banner( string $tag ): ?array {
		$tag = strtolower( trim( $tag ) );
		foreach ( $this->banners() as $banner ) {
			if ( $banner['tag'] === $tag ) {
				return $banner;
			}
		}
		return null;
	}

	/**
	 * Banners with an automatic position on a page (e.g. at the end of a category listing).
	 *
	 * @return array<int,array{tag:string,id:int,auth:string,position:string}>
	 */
	public function banners_at( string $position ): array {
		if ( self::POSITION_NONE === $position ) {
			return array();
		}
		return array_values(
			array_filter(
				$this->banners(),
				static function ( array $banner ) use ( $position ): bool {
					return $banner['position'] === $position;
				}
			)
		);
	}

	/**
	 * Validates the banner rows from the settings screen: short (a-z, 0-9, - and _) unique tag, required numeric id and
	 * auth, and optional automatic position. Empty rows are ignored; invalid ones are dropped with their error.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{banners:array<int,array{tag:string,id:int,auth:string,position:string}>,errors:string[]}
	 */
	public static function sanitize_banners( array $rows ): array {
		$banners = array();
		$errors  = array();
		foreach ( $rows as $row ) {
			$tag  = strtolower( trim( (string) ( $row['tag'] ?? '' ) ) );
			$id   = trim( (string) ( $row['id'] ?? '' ) );
			$auth = trim( (string) ( $row['auth'] ?? '' ) );
			$pos  = trim( (string) ( $row['position'] ?? '' ) );
			if ( '' === $tag && '' === $id && '' === $auth ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $tag ) ) {
				$errors[] = sprintf(
					/* translators: %s: banner tag */
					__( 'Invalid tag "%s": use lowercase letters, numbers, - or _', 'irios-woocommerce' ),
					$tag
				);
				continue;
			}
			if ( isset( $banners[ $tag ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: banner tag */
					__( 'Duplicate tag "%s"', 'irios-woocommerce' ),
					$tag
				);
				continue;
			}
			if ( ! self::is_positive_int( $id ) || '' === $auth ) {
				$errors[] = sprintf(
					/* translators: %s: banner tag */
					__( 'Banner "%s": the numeric ad unit id or its auth is missing', 'irios-woocommerce' ),
					$tag
				);
				continue;
			}
			if ( ! in_array( $pos, self::POSITIONS, true ) ) {
				$errors[] = sprintf(
					/* translators: 1: banner tag, 2: position */
					__( 'Banner "%1$s": unknown position "%2$s"', 'irios-woocommerce' ),
					$tag,
					$pos
				);
				continue;
			}
			$banners[ $tag ] = array(
				'tag'      => $tag,
				'id'       => (int) $id,
				'auth'     => $auth,
				'position' => $pos,
			);
		}
		return array(
			'banners' => array_values( $banners ),
			'errors'  => $errors,
		);
	}

	/** "Enable" checkbox of the Banners section: without it, [irios_banner] shortcodes render nothing. */
	public function banners_enabled(): bool {
		return 'yes' === $this->values['banners_enabled'];
	}

	/** "Enable" checkbox of the Sponsored Products section. */
	public function sponsored_enabled(): bool {
		return 'yes' === $this->values['sponsored_enabled'];
	}

	/** Search ads: enabled and with at least one slot set up. */
	public function search_ads_enabled(): bool {
		return $this->sponsored_enabled() && array() !== $this->sponsored_slots();
	}

	/** Maximum number of Sponsored Products slots on a page. */
	public const MAX_SLOTS = 50;

	/**
	 * Sponsored Products slots in the results grid: each one is an ad from an ad unit at a position (1 = top left,
	 * following the grid's reading order). Sorted by position. If the slots have never been saved, the legacy
	 * configuration's: its ad unit at positions 1…"Maximum ads". If saved with no rows ("[]") there are none, even if
	 * the legacy configuration is still stored.
	 *
	 * @return array<int,array{position:int,id:int,auth:string}>
	 */
	public function sponsored_slots(): array {
		$rows  = json_decode( $this->values['sponsored_slots'], true );
		$slots = is_array( $rows ) ? self::sanitize_sponsored_slots( $rows )['slots'] : array();
		if ( '' !== $this->values['sponsored_slots'] || null === $this->search_ad_unit() || '' === $this->search_ad_unit_auth() ) {
			return $slots;
		}
		$legacy = array();
		for ( $position = 1; $position <= $this->max_ads(); $position++ ) {
			$legacy[] = array(
				'position' => $position,
				'id'       => $this->search_ad_unit(),
				'auth'     => $this->search_ad_unit_auth(),
			);
		}
		return $legacy;
	}

	/**
	 * Slots grouped by ad unit, for the auction: each ad unit requests as many ads as it has slots, and its winners
	 * fill those positions in order.
	 *
	 * @return array<int,array{adUnit:int,auth:string,positions:int[]}>
	 */
	public function sponsored_units(): array {
		$units = array();
		foreach ( $this->sponsored_slots() as $slot ) {
			if ( ! isset( $units[ $slot['id'] ] ) ) {
				$units[ $slot['id'] ] = array(
					'adUnit'    => $slot['id'],
					'auth'      => $slot['auth'],
					'positions' => array(),
				);
			}
			$units[ $slot['id'] ]['positions'][] = $slot['position'];
		}
		return array_values( $units );
	}

	/**
	 * Validates the slot rows from the settings screen: unique position (1…MAX_SLOTS), numeric ad unit id and its
	 * auth. Empty rows are ignored; invalid ones are dropped with their error.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{slots:array<int,array{position:int,id:int,auth:string}>,errors:string[]}
	 */
	public static function sanitize_sponsored_slots( array $rows ): array {
		$slots  = array();
		$errors = array();
		foreach ( $rows as $row ) {
			$position = trim( (string) ( $row['position'] ?? '' ) );
			$id       = trim( (string) ( $row['id'] ?? '' ) );
			$auth     = trim( (string) ( $row['auth'] ?? '' ) );
			if ( '' === $position && '' === $id && '' === $auth ) {
				continue;
			}
			if ( ! self::is_positive_int( $position ) || (int) $position > self::MAX_SLOTS ) {
				$errors[] = sprintf(
					/* translators: 1: position entered, 2: highest position */
					__( 'Invalid position "%1$s": a number from 1 to %2$d', 'irios-woocommerce' ),
					$position,
					self::MAX_SLOTS
				);
				continue;
			}
			if ( isset( $slots[ (int) $position ] ) ) {
				$errors[] = sprintf(
					/* translators: %d: position */
					__( 'Duplicate position %d', 'irios-woocommerce' ),
					(int) $position
				);
				continue;
			}
			if ( ! self::is_positive_int( $id ) || '' === $auth ) {
				$errors[] = sprintf(
					/* translators: %d: position */
					__( 'Position %d: the numeric ad unit id or its auth is missing', 'irios-woocommerce' ),
					(int) $position
				);
				continue;
			}
			$slots[ (int) $position ] = array(
				'position' => (int) $position,
				'id'       => (int) $id,
				'auth'     => $auth,
			);
		}
		ksort( $slots );
		return array(
			'slots'  => array_values( $slots ),
			'errors' => $errors,
		);
	}

	/** Text that tells an ad apart from organic results (required for advertising transparency). */
	public function sponsored_label(): string {
		if ( '' !== $this->values['sponsored_label'] ) {
			return $this->values['sponsored_label'];
		}
		return function_exists( '__' ) ? __( 'Sponsored', 'irios-woocommerce' ) : 'Sponsored';
	}

	/**
	 * WooCommerce redirects to the product page when a search returns a single result: no results page, no ads.
	 * With ads configured and this setting on, the results page is kept.
	 */
	public function keep_single_result(): bool {
		return 'no' !== $this->values['keep_single_result'] && $this->search_ads_enabled();
	}

	public function max_ads(): int {
		$max = (int) $this->values['max_ads'];
		return $max > 0 ? min( $max, 20 ) : 4;
	}


	public function consent_mode(): string {
		$mode = $this->values['consent_mode'];
		return in_array( $mode, array( self::CONSENT_ALWAYS, self::CONSENT_ASESOR_COOKIES ), true ) ? $mode : self::CONSENT_WP_CONSENT_API;
	}



	// ---- Validation (used by the settings screen) ------------------------------------------------------------------

	/** Domain without scheme or path: `example.iriosmedia.online`. Returns '' if invalid. */
	public static function normalize_domain( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '#^https?://#', '', $value );
		$value = rtrim( (string) $value, '/' );
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $value ) ? $value : '';
	}

	/**
	 * WordPress locale (es_ES, pt_PT_ao90, de_DE_formal…) as BCP 47 with language and region (es-ES, pt-PT, de-DE),
	 * the format required by irios-loader.js; null if it cannot be converted.
	 */
	public static function bcp47_language( string $wp_locale ): ?string {
		$parts    = explode( '_', trim( $wp_locale ) );
		$language = strtolower( $parts[0] );
		$region   = isset( $parts[1] ) ? strtoupper( $parts[1] ) : '';
		$tag      = '' === $region ? $language : $language . '-' . $region;
		return self::is_valid_language( $tag ) ? $tag : null;
	}

	public static function is_valid_language( string $value ): bool {
		// Same format that irios-loader.js validates.
		return 1 === preg_match( '/^[a-z]{2,3}(-[A-Z]{2,3}(-[a-zA-Z0-9]+)?)?$/', $value );
	}

	public static function is_positive_int( string $value ): bool {
		return 1 === preg_match( '/^[1-9][0-9]*$/', trim( $value ) );
	}
}
