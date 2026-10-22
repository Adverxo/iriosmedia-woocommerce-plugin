<?php

namespace Irios\WooCommerce;

/**
 * Visitor identifiers. The browser one is the Irios `irs1P` cookie (created by its script, only with consent); here
 * the logged-in user's identifiers are computed with the same normalization as the sync service, so that Irios can
 * link the browser identity with the purchase identity.
 */
final class Identity {

	/** Irios cookie holding the browser identifier: "fc:<uuid>". */
	public const COOKIE = 'irs1P';
	private const COOKIE_PREFIX = 'fc:';

	/** Irios identifier namespaces, the same ones used by the sync service. */
	public const EMAIL_NAMESPACE = 'EMAIL_SHA_256';
	public const PHONE_NAMESPACE = 'PHONE_SHA_256';

	/** With no known country, only phone numbers with an international prefix (+…) are accepted. */
	private const UNKNOWN_REGION = 'ZZ';

	/**
	 * uids of a logged-in customer: CRM (customer id), hashed email and hashed phone (in that order, like the
	 * service).
	 *
	 * @param string|null $phone         Billing phone (free text).
	 * @param string|null $country       Customer's billing country (ISO 3166-1 alpha-2).
	 * @param string|null $store_country Store country (Settings → General), for customers without a country.
	 * @return array<int,array{namespace:string,uid:string}>
	 */
	public static function customer_uids( int $customer_id, ?string $email, ?string $phone = null, ?string $country = null, ?string $store_country = null ): array {
		$uids = array();
		if ( $customer_id > 0 ) {
			$uids[] = array(
				'namespace' => 'CRM',
				'uid'       => (string) $customer_id,
			);
		}
		$hash = self::email_hash( $email );
		if ( null !== $hash ) {
			$uids[] = array(
				'namespace' => self::EMAIL_NAMESPACE,
				'uid'       => $hash,
			);
		}
		$phone_hash = self::phone_hash( $phone, $country, $store_country );
		if ( null !== $phone_hash ) {
			$uids[] = array(
				'namespace' => self::PHONE_NAMESPACE,
				'uid'       => $phone_hash,
			);
		}
		return $uids;
	}

	/**
	 * SHA-256 of the phone in E.164 (+34600123456), with the same rule as the sync service (IdentifierHasher): a
	 * number without an international prefix is parsed with the customer's country or, failing that, the store's;
	 * with neither, only numbers starting with + are accepted. Null if it is not a valid number.
	 */
	public static function phone_hash( ?string $phone, ?string $country, ?string $store_country ): ?string {
		$e164 = self::e164( $phone, $country, $store_country );
		return null === $e164 ? null : hash( 'sha256', $e164 );
	}

	public static function e164( ?string $phone, ?string $country, ?string $store_country ): ?string {
		if ( null === $phone || '' === trim( $phone ) || ! class_exists( \libphonenumber\PhoneNumberUtil::class ) ) {
			return null;
		}
		$region = self::region( $country ) ?? self::region( $store_country ) ?? self::UNKNOWN_REGION;
		try {
			$util   = \libphonenumber\PhoneNumberUtil::getInstance();
			$number = $util->parse( $phone, $region );
			return $util->isValidNumber( $number ) ? $util->format( $number, \libphonenumber\PhoneNumberFormat::E164 ) : null;
		} catch ( \libphonenumber\NumberParseException $e ) {
			return null;
		}
	}

	private static function region( ?string $country ): ?string {
		$country = null === $country ? '' : strtoupper( trim( $country ) );
		return '' === $country ? null : $country;
	}

	/** Hex SHA-256 of the trimmed, lowercased email; null if it is not an email. */
	public static function email_hash( ?string $email ): ?string {
		if ( null === $email ) {
			return null;
		}
		$email = trim( $email );
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return null;
		}
		return hash( 'sha256', mb_strtolower( $email, 'UTF-8' ) );
	}

	/**
	 * Browser uuid from the `irs1P` cookie value ("fc:<uuid>"), lowercased and without the prefix (Irios adds the
	 * namespace prefix on receipt). Null if there is no cookie or it has another format: tampered values are not sent.
	 */
	public static function browser_uid( ?string $cookie ): ?string {
		if ( null === $cookie || 0 !== strpos( $cookie, self::COOKIE_PREFIX ) ) {
			return null;
		}
		$uuid = substr( $cookie, strlen( self::COOKIE_PREFIX ) );
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid )
			? strtolower( $uuid )
			: null;
	}
}
