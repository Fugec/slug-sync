<?php

use PHPUnit\Framework\TestCase;

final class VisualStylesTest extends TestCase {

	private function styles() {
		return file_get_contents( dirname( __DIR__ ) . '/assets/admin.css' );
	}

	public function test_brand_pass_keeps_the_screen_full_width() {
		$this->assertStringContainsString( 'max-width: none', $this->styles() );
	}

	public function test_actions_and_latest_run_have_a_clear_hierarchy() {
		$styles = $this->styles();

		$this->assertStringContainsString( 'min-height: 42px', $styles );
		$this->assertStringContainsString( '.slug-sync-history tbody tr.slug-sync-latest-run > td', $styles );
		$this->assertStringContainsString( 'background: var(--ss-accent-soft) !important', $styles );
		$this->assertStringContainsString( '.slug-sync-latest-run .slug-sync-report-button--changes', $styles );
		$this->assertStringContainsString( '.slug-sync-pro-cta', $styles );
		$this->assertStringContainsString( 'grid-auto-columns: calc((100% - 36px) / 4)', $styles );
		$this->assertStringContainsString( 'background: var(--ss-accent-soft)', $styles );
		$this->assertStringContainsString( 'width: fit-content', $styles );
	}

	public function test_pro_examples_and_findings_use_one_consistent_visual_language() {
		$styles = $this->styles();

		$this->assertStringContainsString( '.slug-sync-admin code { background: #fff; border-radius: 4px; }', $styles );
		$this->assertStringContainsString( '.slug-sync-pro-example {', $styles );
		$this->assertStringContainsString( 'overflow-x: auto', $styles );
		$this->assertStringContainsString( 'white-space: nowrap', $styles );
		$this->assertStringContainsString( '.slug-sync-card > .slug-sync-pro-status {', $styles );
		$this->assertStringContainsString( 'position: absolute', $styles );
		$this->assertStringContainsString( 'right: 24px', $styles );
		$this->assertStringNotContainsString( 'border-left:', $styles );
		$this->assertStringNotContainsString( 'box-shadow: inset 3px 0', $styles );
		$this->assertStringNotContainsString( 'box-shadow: inset 4px 0', $styles );
		$this->assertMatchesRegularExpression( '/\.slug-sync-finding \{[^}]*background: #fff;[^}]*border: 1px solid var\(--ss-line\)/s', $styles );
		$this->assertStringNotContainsString( '.slug-sync-finding.is-new', $styles );
		$this->assertStringNotContainsString( '--ss-accent-softer', $styles );
		$this->assertStringNotContainsString( '.slug-sync-pro-preview', $styles );
	}
}
