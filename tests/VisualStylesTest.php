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
		$this->assertMatchesRegularExpression( '/\.slug-sync-latest-run \.slug-sync-report-button--redirects \{[^}]*background: var\(--ss-accent\);/s', $styles );
		$this->assertStringContainsString( '.slug-sync-latest-run .slug-sync-report-button--changes { border-color: var(--ss-accent); color: var(--ss-accent-ink); }', $styles );
		$this->assertStringContainsString( '.slug-sync-pro-cta', $styles );
		$this->assertStringContainsString( 'grid-auto-columns: calc((100% - 36px) / 4)', $styles );
		$this->assertStringContainsString( '@media (min-width: 1441px)', $styles );
		$this->assertStringContainsString( 'grid-auto-columns: calc((100% - 48px) / 4.2)', $styles );
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

	public function test_form_interactions_reuse_the_brand_palette_and_cards() {
		$styles = $this->styles();

		$this->assertMatchesRegularExpression( '/\.slug-sync-choice:hover,[^{]+\{[^}]*border-color: var\(--ss-accent\);[^}]*box-shadow: 0 0 0 1px var\(--ss-accent\);/s', $styles );
		$this->assertStringContainsString( '.slug-sync-product-unavailable { color: #0a0e42;', $styles );
		$this->assertStringContainsString( '.slug-sync-select { font-size: 14px; margin-top: 8px;', $styles );
		$this->assertStringContainsString( '.slug-sync-preflight-list > .slug-sync-card {', $styles );
		$this->assertStringNotContainsString( '.slug-sync-preflight-item', $styles );
	}

	public function test_the_reading_line_is_visible_and_carries_no_stale_rotator() {
		$styles = $this->styles();

		// The superseded carousel left an opacity: 0 rule behind it once, which
		// hid the line entirely. Nothing may set the line transparent at rest.
		$this->assertStringNotContainsString( '--ss-read-index', $styles );
		$this->assertStringNotContainsString( '--ss-read-count', $styles );
		$this->assertDoesNotMatchRegularExpression( '/\.slug-sync-modal-read \{[^}]*opacity: 0;/s', $styles );
		$this->assertDoesNotMatchRegularExpression( '/\.slug-sync-modal-read \{[^}]*position: absolute;/s', $styles );
		$this->assertSame( 1, preg_match_all( '/^\.slug-sync-modal-read \{/m', $styles ) );
		$this->assertSame( 1, preg_match_all( '/^@keyframes slug-sync-read \{/m', $styles ) );
		$this->assertSame( 1, preg_match_all( '/^\.slug-sync-modal-note \{?/m', $styles ) );
	}

	public function test_the_run_overlay_centres_without_clipping_a_tall_card() {
		$styles = $this->styles();

		// Auto margins, not align-items: a card taller than the viewport must
		// still scroll from its top rather than have it cut off.
		$this->assertMatchesRegularExpression( '/\.slug-sync-modal-card \{[^}]*margin: auto;/s', $styles );
		$this->assertDoesNotMatchRegularExpression( '/\.slug-sync-modal \{[^}]*align-items:/s', $styles );
		$this->assertMatchesRegularExpression( '/\.slug-sync-modal \{[^}]*justify-content: center;/s', $styles );
	}

	public function test_check_boxes_are_round_and_the_bonus_step_wears_a_pill() {
		$styles = $this->styles();

		$this->assertMatchesRegularExpression( '/\.slug-sync-choice input\[type="checkbox"\] \{[^}]*appearance: none;[^}]*border-radius: 50%;/s', $styles );
		$this->assertMatchesRegularExpression( '/\.slug-sync-choice input\[type="checkbox"\]:checked \{[^}]*background: var\(--ss-accent\);/s', $styles );
		$this->assertStringContainsString( 'transform: translate(-50%, -60%) rotate(-45deg);', $styles );
		$this->assertMatchesRegularExpression( '/\.slug-sync-number--bonus \{[^}]*border-radius: 999px;[^}]*width: auto;/s', $styles );
	}
}
