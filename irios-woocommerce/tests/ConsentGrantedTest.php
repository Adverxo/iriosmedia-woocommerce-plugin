<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Consent;
use Irios\WooCommerce\Settings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Final consent decision (Consent::granted) in each mode: without clear consent, nothing is ever tracked. */
final class ConsentGrantedTest extends WordPressTestCase {

	private function granted( string $mode ): bool {
		return Consent::granted( new Settings( array( 'consent_mode' => $mode ) ) );
	}

	/** WP Consent API installed, with or without a banner that uses it, and the visitor's decision for "marketing". */
	private function given_consent_api( string $consent_type, bool $marketing ): void {
		Functions\when( 'wp_get_consent_type' )->justReturn( $consent_type );
		Functions\when( 'wp_has_consent' )->alias( fn ( $category ) => 'marketing' === $category && $marketing );
	}

	public function test_always_mode_does_not_need_a_banner(): void {
		$this->assertTrue( $this->granted( Settings::CONSENT_ALWAYS ) );
	}

	public function test_asesor_banner_needs_marketing_accepted(): void {
		$_COOKIE[ Consent::ASESOR_COOKIE ] = '{"necessary":true,"marketing":true}';
		$this->assertTrue( $this->granted( Settings::CONSENT_ASESOR_COOKIES ) );

		$_COOKIE[ Consent::ASESOR_COOKIE ] = rawurlencode( '{"necessary":true,"marketing":true}' );
		$this->assertTrue( $this->granted( Settings::CONSENT_ASESOR_COOKIES ) );

		$_COOKIE[ Consent::ASESOR_COOKIE ] = '{"necessary":true,"analytics":true,"marketing":false}';
		$this->assertFalse( $this->granted( Settings::CONSENT_ASESOR_COOKIES ) );
	}

	public function test_asesor_banner_without_decision_is_no_consent(): void {
		$this->assertFalse( $this->granted( Settings::CONSENT_ASESOR_COOKIES ) );

		$_COOKIE[ Consent::ASESOR_COOKIE ] = 'not json';
		$this->assertFalse( $this->granted( Settings::CONSENT_ASESOR_COOKIES ) );
	}

	public function test_consent_api_with_banner_follows_the_visitor_decision(): void {
		$this->given_consent_api( 'optin', true );
		$this->assertTrue( $this->granted( Settings::CONSENT_WP_CONSENT_API ) );

		$this->given_consent_api( 'optin', false );
		$this->assertFalse( $this->granted( Settings::CONSENT_WP_CONSENT_API ) );
	}

	/** Without a banner, the WP Consent API treats everything as consented: the plugin does not. */
	public function test_consent_api_without_banner_is_no_consent(): void {
		$this->given_consent_api( '', true );
		$this->assertFalse( $this->granted( Settings::CONSENT_WP_CONSENT_API ) );
	}

	public function test_unknown_mode_falls_back_to_the_consent_api(): void {
		$this->given_consent_api( '', true );
		$_COOKIE[ Consent::ASESOR_COOKIE ] = '{"marketing":true}';
		$this->assertFalse( $this->granted( 'whatever' ) );
	}

	/** In a separate process: in this one, other tests have already defined wp_has_consent(). */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_consent_api_not_installed_is_no_consent(): void {
		$this->assertFalse( function_exists( 'wp_has_consent' ) );
		$this->assertFalse( $this->granted( Settings::CONSENT_WP_CONSENT_API ) );
	}
}
