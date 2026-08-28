<?php

use PHPUnit\Framework\TestCase;

final class ProPreviewTest extends TestCase {

	private $context;
	private $returned;

	protected function setUp(): void {
		$this->context = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'pro_preview_context' );
		$this->context->setAccessible( true );
		$this->returned = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'returned_preview' );
		$this->returned->setAccessible( true );
		$GLOBALS['slug_sync_test_options'] = array();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	private function context( array $run ) {
		return $this->context->invoke( null, $run );
	}

	private function render_upsell( array $run ) {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'upsell_card' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( null, $run );
		return ob_get_clean();
	}

	public function test_product_preview_is_contextual_even_without_title_signals() {
		$result = $this->context(
			array(
				'mode'            => 'dry',
				'post_type'       => 'product',
				'sig_code'        => 0,
				'sig_stopword'    => 0,
				'sig_non_latin'   => 0,
			)
		);

		$this->assertTrue( $result['product'] );
	}

	public function test_plain_post_run_has_no_commercial_surface() {
		$this->assertSame(
			array(),
			$this->context(
				array(
					'mode'          => 'dry',
					'post_type'     => 'post',
					'sig_code'      => 0,
					'sig_stopword'  => 0,
					'sig_non_latin' => 0,
				)
			)
		);
	}

	public function test_non_product_run_qualifies_when_its_titles_expose_a_rule() {
		$result = $this->context(
			array(
				'mode'            => 'dry',
				'post_type'       => 'page',
				'sig_code'        => 2,
				'sig_stopword'    => 3,
				'sig_non_latin'   => 4,
			)
		);

		$this->assertFalse( $result['product'] );
		$this->assertSame( 2, $result['code'] );
		$this->assertSame( 3, $result['stopword'] );
		$this->assertArrayNotHasKey( 'non_latin', $result );
	}

	public function test_non_latin_titles_alone_do_not_upsell_a_feature_free_now_includes() {
		$this->assertSame(
			array(),
			$this->context(
				array(
					'mode'            => 'dry',
					'post_type'       => 'post',
					'sig_code'        => 0,
					'sig_stopword'    => 0,
					'sig_non_latin'   => 4,
					'transliterate'   => true,
				)
			)
		);
	}

	public function test_apply_run_never_shows_the_commercial_surface() {
		$this->assertSame(
			array(),
			$this->context(
				array(
					'mode'            => 'apply',
					'post_type'       => 'product',
					'sig_code'        => 5,
					'sig_stopword'    => 5,
					'sig_non_latin'   => 5,
				)
			)
		);
	}

	public function test_completed_contextual_preview_can_follow_the_back_link() {
		$run = array(
			'id'              => 'preview-123',
			'status'          => 'completed',
			'mode'            => 'dry',
			'post_type'       => 'product',
			'sig_code'        => 0,
			'sig_stopword'    => 0,
			'sig_non_latin'   => 0,
		);
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( 'preview-123' => $run );
		$_GET['slug_sync_preview'] = 'preview-123';
		$_GET['_wpnonce']          = 'testnonce';

		$this->assertSame( $run, $this->returned->invoke( null ) );
	}

	public function test_completed_plain_preview_can_restore_its_apply_choices() {
		$run = array(
			'id'            => 'preview-plain',
			'status'        => 'completed',
			'mode'          => 'dry',
			'post_type'     => 'post',
			'transliterate' => true,
			'remove_sku'    => false,
		);
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( 'preview-plain' => $run );
		$_GET['slug_sync_preview'] = 'preview-plain';
		$_GET['_wpnonce']          = 'testnonce';

		$this->assertSame( $run, $this->returned->invoke( null ) );
	}

	public function test_back_link_requires_its_preview_nonce() {
		$run = array(
			'id'            => 'preview-123',
			'status'        => 'completed',
			'mode'          => 'dry',
			'post_type'     => 'product',
			'sig_code'      => 0,
			'sig_stopword'  => 0,
			'sig_non_latin' => 0,
		);
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( 'preview-123' => $run );
		$_GET['slug_sync_preview'] = 'preview-123';

		$this->assertNull( $this->returned->invoke( null ) );

		$_GET['_wpnonce'] = 'invalid';
		$this->assertNull( $this->returned->invoke( null ) );
	}

	public function test_back_link_cannot_surface_apply_or_unfinished_runs() {
		foreach ( array( 'apply', 'running' ) as $case ) {
			$run = array(
				'id'              => $case,
				'status'          => 'running' === $case ? 'running' : 'completed',
				'mode'            => 'apply' === $case ? 'apply' : 'dry',
				'post_type'       => 'product',
				'sig_code'        => 1,
				'sig_stopword'    => 0,
				'sig_non_latin'   => 0,
			);
			$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( $case => $run );
			$_GET['slug_sync_preview'] = $case;
			$_GET['_wpnonce']          = 'testnonce';

			$this->assertNull( $this->returned->invoke( null ) );
		}
	}

	public function test_contextual_pro_panel_has_a_clear_primary_screen_link() {
		$html = $this->render_upsell(
			array(
				'mode'          => 'dry',
				'post_type'     => 'product',
				'sig_code'      => 0,
				'sig_stopword'  => 0,
				'sig_non_latin' => 0,
			)
		);

		$this->assertStringContainsString( 'class="slug-sync-card"', $html );
		$this->assertStringNotContainsString( 'slug-sync-pro-preview', $html );
		$this->assertLessThan( strpos( $html, 'class="slug-sync-eyebrow"' ), strpos( $html, 'class="slug-sync-pro-status"' ) );
		$this->assertStringContainsString( 'class="slug-sync-pro-grid"', $html );
		$this->assertSame( 8, substr_count( $html, 'class="slug-sync-pro-icon"' ) );
		$this->assertStringNotContainsString( 'Remove unwanted text from URLs', $html );
		$this->assertStringNotContainsString( 'Transliterate complete product and term URLs', $html );
		$this->assertStringContainsString( 'id="slug-sync-pro-slider"', $html );
		$this->assertStringContainsString( 'id="slug-sync-pro-prev"', $html );
		$this->assertStringContainsString( 'id="slug-sync-pro-next"', $html );
		$this->assertStringContainsString( 'class="button button-primary slug-sync-pro-cta"', $html );
		$this->assertStringContainsString( 'See all Pro features', $html );
		$this->assertStringContainsString( 'https://slugsync.com/#gap', $html );
		$this->assertStringNotContainsString( 'https://slugsync.com/pro/', $html );
		$this->assertStringContainsString( 'class="slug-sync-pro-footer"', $html );
		$this->assertStringContainsString( 'Opens slugsync.com', $html );
		// The one control that leaves WordPress says so, and says where to.
		$this->assertLessThan( strpos( $html, 'Opens slugsync.com' ), strpos( $html, 'class="slug-sync-pro-grid"' ) );
	}

	public function test_reading_list_stops_selling_pro_once_pro_is_installed() {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_modal_reading' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null, 0 );
		$free_only = ob_get_clean();

		$GLOBALS['slug_sync_test_filters']['slug_sync_source_title'] = true;
		ob_start();
		$method->invoke( null, 0 );
		$with_pro = ob_get_clean();
		$GLOBALS['slug_sync_test_filters'] = array();

		$this->assertStringContainsString( 'class="slug-sync-modal-reading"', $free_only );
		$this->assertStringNotContainsString( 'slug-sync-modal-read--pro', $with_pro );
		$this->assertStringNotContainsString( '<a ', $free_only );
	}
}
