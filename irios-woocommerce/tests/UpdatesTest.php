<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Updates;
use PHPUnit\Framework\TestCase;

final class UpdatesTest extends TestCase {

	private function release( array $overrides = array() ): array {
		return array_merge(
			array(
				'tag_name'   => '1.2.0',
				'html_url'   => 'https://github.com/' . Updates::REPOSITORY . '/releases/tag/1.2.0',
				'draft'      => false,
				'prerelease' => false,
				'assets'     => array(
					array(
						'name'                 => 'irios-woocommerce-1.2.0.zip',
						'browser_download_url' => 'https://github.com/' . Updates::REPOSITORY . '/releases/download/1.2.0/irios-woocommerce-1.2.0.zip',
					),
					array(
						'name'                 => Updates::ZIP_ASSET,
						'browser_download_url' => 'https://github.com/' . Updates::REPOSITORY . '/releases/download/1.2.0/irios-woocommerce.zip',
					),
				),
			),
			$overrides
		);
	}

	public function test_parses_version_page_and_fixed_name_zip(): void {
		$this->assertSame(
			array(
				'version' => '1.2.0',
				'url'     => 'https://github.com/' . Updates::REPOSITORY . '/releases/tag/1.2.0',
				'zip'     => 'https://github.com/' . Updates::REPOSITORY . '/releases/download/1.2.0/irios-woocommerce.zip',
			),
			Updates::parse_release( $this->release() )
		);
	}

	public function test_release_without_zip_still_links_to_its_page(): void {
		$this->assertSame( '', Updates::parse_release( $this->release( array( 'assets' => array() ) ) )['zip'] );
	}

	public function test_ignores_drafts_prereleases_other_tags_and_foreign_links(): void {
		$this->assertNull( Updates::parse_release( $this->release( array( 'draft' => true ) ) ) );
		$this->assertNull( Updates::parse_release( $this->release( array( 'prerelease' => true ) ) ) );
		$this->assertNull( Updates::parse_release( $this->release( array( 'tag_name' => 'v1.2.0' ) ) ) );
		$this->assertNull( Updates::parse_release( $this->release( array( 'html_url' => 'https://example.com/1.2.0' ) ) ) );
		$this->assertNull( Updates::parse_release( array( 'message' => 'Not Found' ) ) );
		$this->assertNull( Updates::parse_release( null ) );
		$foreign_zip = $this->release(
			array( 'assets' => array( array( 'name' => Updates::ZIP_ASSET, 'browser_download_url' => 'https://example.com/irios-woocommerce.zip' ) ) )
		);
		$this->assertSame( '', Updates::parse_release( $foreign_zip )['zip'] );
	}

	public function test_only_a_newer_version_is_announced(): void {
		$this->assertTrue( Updates::is_newer( '1.1.9', '1.2.0' ) );
		$this->assertTrue( Updates::is_newer( '1.2.0', '1.10.0' ) );
		$this->assertFalse( Updates::is_newer( '1.2.0', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '1.3.0', '1.2.0' ) );
	}

	public function test_source_code_and_untagged_builds_are_not_announced(): void {
		$this->assertFalse( Updates::is_newer( '0.0.0-dev', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '1.1.0-3-gabc1234', '1.2.0' ) );
		$this->assertFalse( Updates::is_newer( '0.0.0-abc1234.dirty', '1.2.0' ) );
	}
}
