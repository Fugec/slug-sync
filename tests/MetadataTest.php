<?php

use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase {

	public function test_brand_and_ownership_metadata_match_the_wordpress_profile() {
		$plugin = file_get_contents( dirname( __DIR__ ) . '/slugsync.php' );
		$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$pot    = file_get_contents( dirname( __DIR__ ) . '/languages/slugsync.pot' );

		$this->assertStringContainsString( 'Plugin URI:        https://slugsync.com/', $plugin );
		$this->assertStringContainsString( 'Plugin Name:       SlugSync', $plugin );
		$this->assertStringContainsString( 'Author:            SlugSync', $plugin );
		$this->assertStringNotContainsString( 'Author URI:', $plugin );
		$this->assertStringNotContainsString( '#. Author URI of the plugin', $pot );
		$this->assertStringContainsString( 'Contributors: arminkapetanovic', $readme );
		// The header, not the word: readme prose is free to mention an author.
		$this->assertDoesNotMatchRegularExpression( '/^Author:/m', $readme );
		$this->assertStringNotContainsString( 'Contributors: slug-sync', $readme );
		$this->assertStringContainsString( "#. Plugin Name of the plugin\n#. Author of the plugin\n", $pot );
		$this->assertStringContainsString( '# Copyright (C) 2026 SlugSync', $pot );
		$this->assertStringContainsString( 'Text Domain:       slugsync', $plugin );
		$this->assertStringContainsString( 'X-Domain: slugsync', $pot );
		$this->assertStringContainsString( '=== SlugSync ===', $readme );
		$this->assertFileExists( dirname( __DIR__ ) . '/slugsync.php' );
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/slug-sync.php' );
		// The old admin page identifier remains for URL compatibility; it must not remain as a gettext domain.
		$this->assertSame( 1, substr_count( $plugin, "'slug-sync'" ) );
		$this->assertStringContainsString( "const PAGE       = 'slug-sync';", $plugin );
		// The display name, never the slug: "slugsync" is the text domain.
		$this->assertStringNotContainsString( "#. Author of the plugin\nmsgid \"slugsync\"", $pot );
		$this->assertStringNotContainsString( "#. Author of the plugin\nmsgid \"Armin Kapetanovic\"", $pot );
	}
}
