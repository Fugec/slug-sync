<?php

use PHPUnit\Framework\TestCase;

final class RunControlsTest extends TestCase {

	private $controls;

	protected function setUp(): void {
		$this->controls = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_controls' );
		$this->controls->setAccessible( true );
	}

	private function render_controls( $auto ) {
		ob_start();
		$this->controls->invoke( null, array( 'id' => 'run-123' ), $auto );
		return ob_get_clean();
	}

	public function test_auto_continue_leaves_a_stop_window_and_cancels_for_stop_interactions() {
		$html = $this->render_controls( true );
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );

		$this->assertStringContainsString( 'id="slug-sync-next"', $html );
		$this->assertStringContainsString( 'class="slug-sync-stop-form', $html );
		$this->assertStringContainsString( "document.addEventListener('focusin', stopAuto)", $script );
		$this->assertStringContainsString( "document.addEventListener('pointerdown', stopAuto)", $script );
		$this->assertStringContainsString( "document.addEventListener('submit', stopAuto)", $script );
		$this->assertStringContainsString( 'data-auto-stopped', $script );
		$this->assertStringContainsString( '}, 5000);', $script );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_manual_controls_do_not_schedule_another_batch() {
		$html = $this->render_controls( false );

		$this->assertStringNotContainsString( 'id="slug-sync-next"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'name="slug_sync_cancel"', $html );
	}
}
