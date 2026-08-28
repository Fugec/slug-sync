<?php

use PHPUnit\Framework\TestCase;

final class PreviewRiskTest extends TestCase {

	private $risk;
	private $render;
	private $change_row;

	protected function setUp(): void {
		$reflection   = new ReflectionClass( 'Slug_Sync' );
		$this->risk   = $reflection->getMethod( 'risk_from_rows' );
		$this->render = $reflection->getMethod( 'render_preview_risk' );
		$this->change_row = $reflection->getMethod( 'change_row' );
		$this->risk->setAccessible( true );
		$this->render->setAccessible( true );
		$this->change_row->setAccessible( true );
	}

	private function row( $id, $status = 'publish', $conflict = '' ) {
		return array(
			$id,
			'post',
			$status,
			'Title',
			'old-' . $id,
			'new-' . $id,
			'https://example.test/old-' . $id,
			'https://example.test/new-' . $id,
			'',
			0,
			$conflict,
		);
	}

	public function test_flat_preview_counts_public_protection_unpublished_rows_and_adjustments() {
		$risk = $this->risk->invoke(
			null,
			array(
				1 => $this->row( 1 ),
				2 => $this->row( 2, 'draft' ),
				3 => $this->row( 3, 'publish', 'existing_slug' ),
			),
			'post'
		);

		$this->assertSame(
			array(
				'changes'             => 3,
				'published'           => 2,
				'unpublished'         => 1,
				'url_changes'         => 2,
				'automatic_redirects' => 2,
				'manual_redirects'    => 0,
				'adjusted'            => 1,
			),
			$risk
		);
	}

	public function test_hierarchical_published_rows_require_redirect_import() {
		$risk = $this->risk->invoke( null, array( 1 => $this->row( 1 ) ), 'page' );

		$this->assertSame( 0, $risk['automatic_redirects'] );
		$this->assertSame( 1, $risk['manual_redirects'] );
	}

	public function test_slug_change_without_public_url_change_does_not_claim_a_redirect() {
		$row    = $this->row( 4 );
		$row[7] = $row[6];
		$risk   = $this->risk->invoke( null, array( 4 => $row ), 'post' );

		$this->assertSame( 1, $risk['published'] );
		$this->assertSame( 0, $risk['url_changes'] );
		$this->assertSame( 0, $risk['automatic_redirects'] );
		$this->assertSame( 0, $risk['manual_redirects'] );
	}

	public function test_empty_old_slug_cannot_use_core_old_slug_redirect() {
		$row    = $this->row( 5 );
		$row[4] = '';
		$risk   = $this->risk->invoke( null, array( 5 => $row ), 'post' );

		$this->assertSame( 1, $risk['url_changes'] );
		$this->assertSame( 0, $risk['automatic_redirects'] );
		$this->assertSame( 1, $risk['manual_redirects'] );
	}

	public function test_conflict_reason_is_appended_without_moving_undo_columns() {
		$post = (object) array(
			'ID'          => 42,
			'post_status' => 'publish',
			'post_title'  => 'Title',
			'post_parent' => 7,
		);
		$row = $this->change_row->invoke(
			null,
			$post,
			'page',
			'old',
			'new-2',
			'https://example.test/old',
			'https://example.test/new-2',
			'conflict note',
			'Existing_Slug!'
		);

		$this->assertSame( 'old', $row[4] );
		$this->assertSame( 'new-2', $row[5] );
		$this->assertSame( 7, $row[9] );
		$this->assertSame( 'existing_slug', $row[10] );
	}

	public function test_completed_preview_renders_factual_counts_and_review_actions() {
		$run = array(
			'id'      => 'risk-preview',
			'mode'    => 'dry',
			'status'  => 'completed',
			'errors'  => 2,
			'changed' => 7,
			'risk'    => array(
				'changes'             => 7,
				'published'           => 5,
				'unpublished'         => 2,
				'url_changes'         => 5,
				'automatic_redirects' => 0,
				'manual_redirects'    => 5,
				'adjusted'            => 1,
			),
		);

		ob_start();
		$this->render->invoke( null, $run );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'URL change summary', $html );
		$this->assertStringContainsString( 'measured results from this Preview, not a guessed SEO score', $html );
		$this->assertStringContainsString( '>7</strong><span>proposed changes', $html );
		$this->assertStringContainsString( '>5</strong><span>public URL changes', $html );
		$this->assertStringContainsString( '>5</strong><span>need redirect import', $html );
		$this->assertStringContainsString( 'Redirect action required:', $html );
		$this->assertStringContainsString( 'conflict_reason', $html );
	}

	public function test_apply_run_does_not_render_a_preview_risk_card() {
		ob_start();
		$this->render->invoke( null, array( 'mode' => 'apply', 'status' => 'completed' ) );

		$this->assertSame( '', ob_get_clean() );
	}
}
