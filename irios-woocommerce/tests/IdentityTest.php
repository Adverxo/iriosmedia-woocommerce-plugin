<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Identity;
use PHPUnit\Framework\TestCase;

/**
 * The email hash must match the one from the sync service (IdentifierHasher), or Irios will not link the browser
 * identity to the purchase identity.
 */
final class IdentityTest extends TestCase {

	public function test_email_hash_matches_sync_service(): void {
		$expected = hash( 'sha256', 'juan@example.com' );

		$this->assertSame( $expected, Identity::email_hash( ' Juan@Example.COM ' ) );
		$this->assertSame( $expected, Identity::email_hash( 'juan@example.com' ) );
		$this->assertSame( 64, strlen( $expected ) );
	}

	public function test_invalid_emails_have_no_hash(): void {
		$this->assertNull( Identity::email_hash( '' ) );
		$this->assertNull( Identity::email_hash( 'sin-arroba' ) );
		$this->assertNull( Identity::email_hash( null ) );
	}

	public function test_customer_uids(): void {
		$uids = Identity::customer_uids( 42, 'Juan@Example.com' );

		$this->assertSame(
			array(
				array( 'namespace' => 'CRM', 'uid' => '42' ),
				array( 'namespace' => 'EMAIL_SHA_256', 'uid' => hash( 'sha256', 'juan@example.com' ) ),
			),
			$uids
		);
	}

	public function test_guest_without_email_has_no_uids(): void {
		$this->assertSame( array(), Identity::customer_uids( 0, '' ) );
	}

	public function test_browser_uid_comes_from_the_irios_cookie(): void {
		$this->assertSame( '6f1c2b7a-0d3e-4c5b-9a8f-1e2d3c4b5a69', Identity::browser_uid( 'fc:6f1c2b7a-0d3e-4c5b-9a8f-1e2d3c4b5a69' ) );
		$this->assertSame( '6f1c2b7a-0d3e-4c5b-9a8f-1e2d3c4b5a69', Identity::browser_uid( 'fc:6F1C2B7A-0D3E-4C5B-9A8F-1E2D3C4B5A69' ) );
		// Without the Irios prefix, with another prefix or tampered with: discarded
		$this->assertNull( Identity::browser_uid( '6f1c2b7a-0d3e-4c5b-9a8f-1e2d3c4b5a69' ) );
		$this->assertNull( Identity::browser_uid( 'tc:6f1c2b7a-0d3e-4c5b-9a8f-1e2d3c4b5a69' ) );
		$this->assertNull( Identity::browser_uid( 'fc:<script>' ) );
		$this->assertNull( Identity::browser_uid( '' ) );
		$this->assertNull( Identity::browser_uid( null ) );
	}

	// Same cases as the service's IdentifierHasherTest: the hashes must match.

	public function test_phone_without_prefix_uses_the_customer_country(): void {
		$this->assertSame( '+34600123456', Identity::e164( '600 12 34 56', 'ES', null ) );
		$this->assertSame( '+351912345678', Identity::e164( '912 345 678', 'PT', null ) );
		$this->assertSame( '+33612345678', Identity::e164( '06 12 34 56 78', 'fr', null ) );
		$this->assertSame( hash( 'sha256', '+34600123456' ), Identity::phone_hash( '600123456', 'ES', null ) );
	}

	public function test_phone_with_international_prefix_ignores_the_country(): void {
		$this->assertSame( '+34600123456', Identity::e164( '+34 600-12-34-56', 'PT', null ) );
		$this->assertSame( '+351912345678', Identity::e164( '+351 912 345 678', 'ES', null ) );
		$this->assertSame( '+34600123456', Identity::e164( '0034600123456', 'ES', null ) );
	}

	public function test_customer_without_country_uses_the_store_country(): void {
		$this->assertSame( '+34600123456', Identity::e164( '600 12 34 56', null, 'ES' ) );
		$this->assertSame( '+34600123456', Identity::e164( '600 12 34 56', ' ', 'ES' ) );
	}

	public function test_without_any_country_only_international_numbers_are_accepted(): void {
		$this->assertNull( Identity::e164( '600 12 34 56', null, null ) );
		$this->assertSame( '+34600123456', Identity::e164( '+34 600 12 34 56', null, null ) );
	}

	public function test_invalid_phone_is_skipped(): void {
		$this->assertNull( Identity::phone_hash( 'abc', 'ES', null ) );
		$this->assertNull( Identity::phone_hash( '123', 'ES', null ) );
		$this->assertNull( Identity::phone_hash( ' ', 'ES', null ) );
		$this->assertNull( Identity::phone_hash( null, 'ES', 'ES' ) );
	}

	public function test_customer_uids_include_the_phone_after_the_email(): void {
		$uids = Identity::customer_uids( 42, 'juan@example.com', '600 12 34 56', null, 'ES' );

		$this->assertSame( array( 'CRM', 'EMAIL_SHA_256', 'PHONE_SHA_256' ), array_column( $uids, 'namespace' ) );
		$this->assertSame( hash( 'sha256', '+34600123456' ), $uids[2]['uid'] );
	}
}
