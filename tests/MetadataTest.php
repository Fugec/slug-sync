<?php

use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase {

	public function test_brand_and_ownership_metadata_match_the_wordpress_profile() {
		$plugin = file_get_contents( dirname( __DIR__ ) . '/slug-sync.php' );
		$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$pot    = file_get_contents( dirname( __DIR__ ) . '/languages/slug-sync.pot' );

		$this->assertStringContainsString( 'Plugin URI:        https://slugsync.com/', $plugin );
		$this->assertStringContainsString( 'Author:            Slug Sync', $plugin );
		$this->assertStringContainsString( 'Author URI:        https://slugsync.com/', $plugin );
		$this->assertStringContainsString( 'Contributors: arminkapetanovic', $readme );
		// The header, not the word: readme prose is free to mention an author.
		$this->assertDoesNotMatchRegularExpression( '/^Author:/m', $readme );
		$this->assertStringNotContainsString( 'Contributors: slug-sync', $readme );
		$this->assertStringContainsString( "#. Plugin Name of the plugin\n#. Author of the plugin\n", $pot );
		$this->assertStringContainsString( '# Copyright (C) 2026 Slug Sync', $pot );
		// The display name, never the slug: "slug-sync" is the text domain.
		$this->assertStringNotContainsString( "#. Author of the plugin\nmsgid \"slug-sync\"", $pot );
		$this->assertStringNotContainsString( "#. Author of the plugin\nmsgid \"Armin Kapetanovic\"", $pot );
	}
}
