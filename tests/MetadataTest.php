<?php

use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase {

	public function test_brand_and_ownership_metadata_match_the_wordpress_profile() {
		$plugin = file_get_contents( dirname( __DIR__ ) . '/slug-sync.php' );
		$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$pot    = file_get_contents( dirname( __DIR__ ) . '/languages/slug-sync.pot' );

		$this->assertStringContainsString( 'Plugin URI:        https://slugsync.com/', $plugin );
		$this->assertStringContainsString( 'Author:            slug-sync', $plugin );
		$this->assertStringContainsString( 'Author URI:        https://slugsync.com/', $plugin );
		$this->assertStringContainsString( 'Contributors: arminkapetanovic', $readme );
		// The header, not the word: readme prose is free to mention an author.
		$this->assertDoesNotMatchRegularExpression( '/^Author:/m', $readme );
		$this->assertStringNotContainsString( 'Contributors: slug-sync', $readme );
		$this->assertStringContainsString( "#. Author of the plugin\nmsgid \"slug-sync\"", $pot );
		$this->assertStringNotContainsString( "#. Author of the plugin\nmsgid \"Armin Kapetanovic\"", $pot );
	}
}
