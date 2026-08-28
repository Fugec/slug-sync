<?php

use PHPUnit\Framework\TestCase;

final class FreeFeaturesUiTest extends TestCase {

	private $form;

	protected function setUp(): void {
		$this->form = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'form' );
		$this->form->setAccessible( true );
		$GLOBALS['slug_sync_test_options'] = array();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	private function render_form() {
		ob_start();
		$this->form->invoke( null );
		return ob_get_clean();
	}

	public function test_free_transformations_are_a_visible_guided_step() {
		$html = $this->render_form();

		$this->assertStringNotContainsString( 'data-workflow-complete', $html );
		$this->assertSame( 5, substr_count( $html, 'class="slug-sync-workflow-step"' ) );
		$this->assertStringContainsString( 'data-slug-sync-step="1"', $html );
		$this->assertStringContainsString( '<span class="slug-sync-workflow-title">What should I tidy up?</span>', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="2"', $html );
		$this->assertStringContainsString( '<span class="slug-sync-workflow-title">How should the new slugs be built?</span>', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="3"', $html );
		$this->assertStringContainsString( '<span class="slug-sync-workflow-title">Preview first, or apply now?</span>', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="4"', $html );
		$this->assertStringContainsString( '<span class="slug-sync-workflow-title">How it runs</span>', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="5"', $html );
		$this->assertStringContainsString( '<span class="slug-sync-number slug-sync-number--bonus">Bonus</span>', $html );
		$this->assertStringContainsString( '<span class="slug-sync-workflow-title">What is included</span>', $html );
		$this->assertStringNotContainsString( '<span class="slug-sync-number">5</span>', $html );
		$this->assertStringContainsString( 'Nothing to pick here — the default scope suits most sites', $html );
		$this->assertStringContainsString( '<div class="slug-sync-optional-note">', $html );
		$this->assertStringContainsString( 'Nothing here has to be picked.', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="1" open', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-slug-sync-step="[2-5]" open/', $html );
		$this->assertStringContainsString( 'name="transliterate"', $html );
		$this->assertStringContainsString( 'name="sku_mode" value="keep"', $html );
		$this->assertStringContainsString( 'name="sku_mode" value="remove"', $html );
		$this->assertStringContainsString( 'name="sku_mode" value="include"', $html );
		$this->assertStringContainsString( 'Included in Free', $html );
		$this->assertStringContainsString( 'Кофеварка', $html );
		$this->assertStringContainsString( 'SKU BCS-500', $html );
		$this->assertStringContainsString( 'Blue Shirt · {sku}', $html );
		$this->assertStringContainsString( 'Product data → Inventory → SKU', $html );
		$this->assertStringContainsString( 'Product titles, SKUs and other stored content stay exactly as they are.', $html );
		$this->assertStringContainsString( 'id="slug-sync-post-type" class="slug-sync-select" required', $html );
		$this->assertMatchesRegularExpression( '/<option value=""[^>]*selected="selected"[^>]*disabled>Choose content<\/option>/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<option value="product"[^>]*selected="selected"/', $html );
		$this->assertStringContainsString( 'id="slug-sync-sku-options" aria-disabled="true" disabled', $html );
		$this->assertSame( 0, preg_match_all( '/name="(?:sku_mode|mode|write)"[^>]*checked="checked"/', $html ) );
		$this->assertMatchesRegularExpression( '/name="sku_mode" value="keep" required/', $html );
		$this->assertMatchesRegularExpression( '/name="mode" value="dry" required/', $html );
		$this->assertMatchesRegularExpression( '/name="write" value="quiet" required/', $html );
		$this->assertSame( 3, substr_count( $html, '>Recommended</span>' ) );
		$this->assertStringNotContainsString( 'name="remove_sku"', $html );
	}

	public function test_completed_preview_restores_transformations_for_apply() {
		$run = array(
			'id'            => 'preview-restore',
			'status'        => 'completed',
			'mode'          => 'dry',
			'post_type'     => 'product',
			'transliterate' => true,
			'remove_sku'    => true,
			'drafts'        => true,
			'suffixed'      => true,
		);
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( 'preview-restore' => $run );
		$_GET['slug_sync_preview'] = 'preview-restore';
		$_GET['_wpnonce']          = 'testnonce';

		$html = $this->render_form();

		$this->assertStringContainsString( 'Your preview choices are restored below.', $html );
		$this->assertStringContainsString( 'data-slug-sync-step="1" open', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-slug-sync-step="[2-5]" open/', $html );
		$this->assertMatchesRegularExpression( '/<option value="product"[^>]*selected="selected"/', $html );
		$this->assertStringContainsString( 'id="slug-sync-sku-options" aria-disabled="false"', $html );
		$this->assertMatchesRegularExpression( '/name="transliterate"[^>]*checked="checked"/', $html );
		$this->assertMatchesRegularExpression( '/name="sku_mode" value="remove"[^>]*checked="checked"/', $html );
		$this->assertMatchesRegularExpression( '/name="write" value="quiet"[^>]*checked="checked"/', $html );
		$this->assertMatchesRegularExpression( '/name="drafts"[^>]*checked="checked"/', $html );
		$this->assertMatchesRegularExpression( '/name="suffixed"[^>]*checked="checked"/', $html );
	}

	public function test_completed_preview_restores_sku_inclusion_for_apply() {
		$run = array(
			'id'          => 'preview-include',
			'status'      => 'completed',
			'mode'        => 'dry',
			'post_type'   => 'product',
			'include_sku' => true,
		);
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array( 'preview-include' => $run );
		$_GET['slug_sync_preview'] = 'preview-include';
		$_GET['_wpnonce']          = 'testnonce';

		$html = $this->render_form();

		$this->assertMatchesRegularExpression( '/name="sku_mode" value="include"[^>]*checked="checked"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="sku_mode" value="remove"[^>]*checked="checked"/', $html );
	}

	public function test_completed_run_still_opens_only_step_one_on_the_ordinary_homepage() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'apply-complete' => array(
				'id'     => 'apply-complete',
				'status' => 'completed',
				'mode'   => 'apply',
			),
		);

		$html = $this->render_form();

		$this->assertStringContainsString( 'data-slug-sync-step="1" open', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-slug-sync-step="[2-5]" open/', $html );
	}

	public function test_a_first_visit_still_gets_the_three_steps_explained() {
		$html = $this->render_form();

		$this->assertStringContainsString( 'class="slug-sync-intro"', $html );
		$this->assertStringContainsString( 'class="slug-sync-steps"', $html );
		$this->assertStringNotContainsString( 'What Slug Sync Pro adds', $html );
	}

	public function test_a_run_that_has_not_finished_leaves_the_introduction_alone() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'still-going' => array( 'id' => 'still-going', 'status' => 'paused', 'mode' => 'dry' ),
		);

		$html = $this->render_form();

		$this->assertStringContainsString( 'class="slug-sync-intro"', $html );
		$this->assertStringNotContainsString( 'What Slug Sync Pro adds', $html );
	}

	public function test_a_stopped_run_leaves_the_introduction_alone_too() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'gave-up' => array( 'id' => 'gave-up', 'status' => 'canceled', 'mode' => 'dry' ),
		);

		$html = $this->render_form();

		$this->assertStringContainsString( 'class="slug-sync-intro"', $html );
		$this->assertStringNotContainsString( 'What Slug Sync Pro adds', $html );
	}

	public function test_an_undone_run_still_counts_as_having_been_through_the_loop() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'undone' => array( 'id' => 'undone', 'status' => 'rolled_back', 'mode' => 'apply' ),
		);

		$html = $this->render_form();

		$this->assertStringNotContainsString( 'class="slug-sync-intro"', $html );
		$this->assertStringContainsString( 'What Slug Sync Pro adds', $html );
	}

	public function test_a_finished_run_swaps_the_introduction_for_the_pro_card() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'done' => array( 'id' => 'done', 'status' => 'completed', 'mode' => 'apply' ),
		);

		$html = $this->render_form();

		$this->assertStringNotContainsString( 'class="slug-sync-intro"', $html );
		$this->assertStringContainsString( 'What Slug Sync Pro adds', $html );
		$this->assertStringContainsString( 'Slug Sync Free is complete on its own', $html );

		// One Pro card on the screen, never two.
		$this->assertSame( 1, substr_count( $html, 'What Slug Sync Pro adds' ) );
	}

	public function test_the_returning_preview_gets_the_contextual_pro_card_only_once() {
		$GLOBALS['slug_sync_test_options']['slug_sync_runs'] = array(
			'preview-done' => array(
				'id'           => 'preview-done',
				'status'       => 'completed',
				'mode'         => 'dry',
				'post_type'    => 'product',
				'sig_code'     => 2,
				'sig_stopword' => 0,
			),
		);
		$_GET['slug_sync_preview'] = 'preview-done';
		$_GET['_wpnonce']          = 'testnonce';

		$html = $this->render_form();

		$this->assertSame( 1, substr_count( $html, 'What Slug Sync Pro adds' ) );
		$this->assertStringContainsString( 'What this preview noticed', $html );
		$this->assertStringContainsString( 'Free completed this URL preview.', $html );
		$this->assertStringNotContainsString( 'Slug Sync Free is complete on its own', $html );
		$this->assertStringContainsString( 'Your preview choices are restored below.', $html );
	}

	public function test_product_availability_is_updated_without_inline_script() {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );

		$this->assertStringContainsString( "typeSelect.value === (config.productType || 'product')", $script );
		$this->assertStringContainsString( 'skuOptions.disabled = !isProduct', $script );
		$this->assertStringContainsString( "skuOptions.classList.toggle('is-disabled', !isProduct)", $script );
		$this->assertStringNotContainsString( '<script', $this->render_form() );
	}

	public function test_workflow_script_progresses_without_forcing_dropdowns_open_on_load() {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );

		$this->assertStringContainsString( "form.querySelectorAll('[data-slug-sync-step]')", $script );
		$this->assertStringNotContainsString( 'localStorage', $script );
		$this->assertStringNotContainsString( 'data-workflow-complete', $script );
		$this->assertStringContainsString( 'next.open = true', $script );
		$this->assertStringContainsString( "form.addEventListener('change'", $script );
		$this->assertStringContainsString( 'input[type="radio"]:checked', $script );
	}
}
