<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Consent;
use Irios\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

final class ConsentTest extends TestCase {

	public function test_asesor_cookie_with_marketing_accepted(): void {
		$raw = '{"necessary":true,"analytics":true,"marketing":true,"personalization":true,"updatedAt":"2026-10-09T10:00:00.000Z"}';
		$this->assertTrue( Consent::asesor_allows( $raw, 'marketing' ) );
	}

	public function test_asesor_cookie_with_marketing_rejected(): void {
		$raw = '{"necessary":true,"analytics":true,"marketing":false,"personalization":false}';
		$this->assertFalse( Consent::asesor_allows( $raw, 'marketing' ) );
		$this->assertTrue( Consent::asesor_allows( $raw, 'analytics' ) );
	}

	public function test_asesor_cookie_url_encoded(): void {
		$this->assertTrue( Consent::asesor_allows( rawurlencode( '{"marketing":true}' ), 'marketing' ) );
	}

	public function test_no_cookie_or_garbage_is_no_consent(): void {
		$this->assertFalse( Consent::asesor_allows( null, 'marketing' ) );
		$this->assertFalse( Consent::asesor_allows( '', 'marketing' ) );
		$this->assertFalse( Consent::asesor_allows( 'yes', 'marketing' ) );
		$this->assertFalse( Consent::asesor_allows( '{"marketing":"true"}', 'marketing' ) ); // only boolean true
	}

	public function test_asesor_mode_is_a_valid_setting(): void {
		$this->assertSame( Settings::CONSENT_ASESOR_COOKIES, ( new Settings( array( 'consent_mode' => 'asesor_cookies' ) ) )->consent_mode() );
	}
}
