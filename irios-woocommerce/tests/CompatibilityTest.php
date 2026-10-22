<?php

namespace Irios\WooCommerce\Tests;

use Irios\WooCommerce\Compatibility;
use PHPUnit\Framework\TestCase;

final class CompatibilityTest extends TestCase {

	public function test_supported_themes_are_lowercase_slugs(): void {
		foreach ( array_keys( Compatibility::SUPPORTED_THEMES ) as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $slug );
		}
	}

	public function test_unknown_theme_is_not_supported(): void {
		$this->assertFalse( Compatibility::is_supported( 'mi-tema-propio', 'mi-tema-propio' ) );
	}

	public function test_a_child_theme_counts_as_its_parent(): void {
		$tested = array_key_first( Compatibility::SUPPORTED_THEMES );
		if ( null === $tested ) {
			$this->markTestSkipped( 'No tested themes yet' );
		}
		$this->assertTrue( Compatibility::is_supported( 'child-of-' . $tested, $tested ) );
		$this->assertTrue( Compatibility::is_supported( strtoupper( $tested ), strtoupper( $tested ) ) );
	}
}
