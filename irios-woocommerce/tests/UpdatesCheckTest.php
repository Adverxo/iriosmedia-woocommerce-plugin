<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Updates;

/**
 * Looking for a new version: GitHub's public list of releases, at most once every 12 hours and only when an admin
 * page is opened. No data of the store is sent, and a failure never stops the admin.
 */
final class UpdatesCheckTest extends WordPressTestCase {

	/** @var array<string,mixed> what was asked of GitHub */
	private array $asked = array();
	private $answer = null;

	protected function setUp(): void {
		parent::setUp();
		$this->asked = array();
		$this->answer = array( 'response' => array( 'code' => 200 ), 'body' => '' );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args = array() ) {
				$this->asked = array( 'url' => $url ) + $args;
				return $this->answer;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn ( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn ( $r ) => is_array( $r ) ? $r['response']['code'] : 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn ( $r ) => is_array( $r ) ? $r['body'] : '' );
	}

	private function given_github_answers( array $release ): void {
		$this->answer = array( 'response' => array( 'code' => 200 ), 'body' => (string) json_encode( $release ) );
	}

	private function release( array $overrides = array() ): array {
		return array_merge(
			array(
				'tag_name' => '1.2.0',
				'html_url' => 'https://github.com/' . Updates::REPOSITORY . '/releases/tag/1.2.0',
				'assets'   => array(
					array(
						'name'                 => Updates::ZIP_ASSET,
						'browser_download_url' => 'https://github.com/' . Updates::REPOSITORY . '/releases/download/1.2.0/irios-woocommerce.zip',
					),
				),
			),
			$overrides
		);
	}

	public function test_it_asks_github_and_keeps_the_answer_for_twelve_hours(): void {
		$this->given_github_answers( $this->release() );

		$release = Updates::latest();

		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( 'https://api.github.com/repos/' . Updates::REPOSITORY . '/releases/latest', $this->asked['url'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $this->transient_ttl( 'irios_wc_latest_release' ) );
	}

	public function test_nothing_of_the_store_is_sent(): void {
		$this->given_github_answers( $this->release() );

		Updates::latest();

		$sent = (string) json_encode( $this->asked );
		$this->assertStringNotContainsString( 'shop.test', $sent );
		$this->assertStringNotContainsString( 'irs1P', $sent );
		$this->assertSame( 'irios-woocommerce/' . IRIOS_WC_VERSION, $this->asked['headers']['User-Agent'], 'only the plugin identifies itself' );
	}

	public function test_the_answer_already_kept_is_not_asked_for_again(): void {
		$this->transients['irios_wc_latest_release'] = array( 'release' => array( 'version' => '9.9.9', 'url' => 'u', 'zip' => '' ) );

		$this->assertSame( '9.9.9', Updates::latest()['version'] );
		$this->assertSame( array(), $this->asked, 'GitHub is not asked again' );
	}

	public function test_if_github_fails_it_is_retried_in_an_hour_instead_of_twelve(): void {
		$this->answer = array( 'response' => array( 'code' => 503 ), 'body' => '' );

		$this->assertNull( Updates::latest() );
		$this->assertSame( HOUR_IN_SECONDS, $this->transient_ttl( 'irios_wc_latest_release' ) );
	}

	public function test_a_network_error_does_not_break_anything(): void {
		$this->answer = new \WP_Error();

		$this->assertNull( Updates::latest() );
	}

	public function test_a_draft_or_a_prerelease_is_not_offered(): void {
		$this->assertNull( Updates::parse_release( $this->release( array( 'draft' => true ) ) ) );
		$this->assertNull( Updates::parse_release( $this->release( array( 'prerelease' => true ) ) ) );
	}

	public function test_only_a_newer_version_than_the_installed_one_notifies(): void {
		$this->assertTrue( Updates::is_newer( '1.1.0', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '1.2.0', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '1.3.0', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '0.0.0-dev', '1.2.0' ), 'the source code never notifies' );
		$this->assertFalse( Updates::is_newer( '1.1.0-3-gabc', '1.2.0' ), 'nor a zip built outside a tag' );
	}
}
