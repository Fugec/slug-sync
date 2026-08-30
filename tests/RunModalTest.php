<?php
/**
 * The run overlay: what a run looks like while it works, and what it hands
 * over when it stops.
 *
 * @package Slug_Sync
 */

use PHPUnit\Framework\TestCase;

final class RunModalTest extends TestCase {

	private $modal;

	protected function setUp(): void {
		$this->modal = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_modal' );
		$this->modal->setAccessible( true );
		$GLOBALS['slug_sync_test_options'] = array();
		$GLOBALS['slug_sync_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['slug_sync_test_filters'] = array();

		foreach ( glob( $this->reports() . '/*.csv' ) as $file ) {
			unlink( $file );
		}
	}

	private function reports() {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'reports' );
		$method->setAccessible( true );
		$reports = $method->invoke( null );

		return rtrim( $reports['path'], '/' );
	}

	private function write_reports( $run_id ) {
		file_put_contents( $this->reports() . '/slug-changes-' . $run_id . '.csv', "id,old,new\n" );
		file_put_contents( $this->reports() . '/slug-redirects-' . $run_id . '.csv', "old,new\n" );
	}

	private function findings( $count ) {
		$findings = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$findings[] = array(
				'id'    => $i,
				'title' => 'Item ' . $i,
				'old'   => '/old-' . $i . '/',
				'new'   => '/new-' . $i . '/',
				'note'  => '',
			);
		}

		return $findings;
	}

	private function render( array $overrides = array() ) {
		$defaults = array(
			'run'      => array(
				'id'        => 'run-one',
				'mode'      => 'dry',
				'post_type' => 'post',
				'changed'   => 3,
			),
			'run_id'   => 'run-one',
			'apply'    => false,
			'quiet'    => true,
			'done'     => 40,
			'total'    => 200,
			'findings' => array(),
			'log'      => array(),
			'finished' => false,
			'paused'   => false,
		);

		ob_start();
		$this->modal->invoke( null, array_merge( $defaults, $overrides ) );

		return ob_get_clean();
	}

	public function test_a_working_run_is_a_dialog_with_progress_and_something_to_read() {
		$html = $this->render();

		$this->assertStringContainsString( 'id="slug-sync-run-modal"', $html );
		$this->assertStringContainsString( 'data-state="running"', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-labelledby="slug-sync-modal-title"', $html );

		// aria-modal asserts the page behind is inert. The admin bar is left
		// reachable on purpose, so the claim would be false and a keyboard
		// user could tab into content a screen reader was told is hidden.
		$this->assertStringNotContainsString( 'aria-modal', $html );
		$this->assertStringContainsString( 'class="slug-sync-modal-backdrop"', $html );
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'Building your new URLs', $html );
		$this->assertStringContainsString( 'class="slug-sync-modal-reading"', $html );

		// Nothing to download yet, and no way to dismiss the run itself.
		$this->assertStringNotContainsString( 'slug-sync-modal-downloads', $html );
		$this->assertStringNotContainsString( 'id="slug-sync-modal-close"', $html );
	}

	public function test_an_apply_run_names_the_saving_method_rather_than_the_preview_note() {
		$html = $this->render(
			array(
				'run'   => array( 'id' => 'run-one', 'mode' => 'apply', 'post_type' => 'post' ),
				'apply' => true,
				'quiet' => false,
			)
		);

		$this->assertStringContainsString( 'Rewriting your URLs', $html );
		$this->assertStringContainsString( 'Standard WordPress update', $html );
		$this->assertStringNotContainsString( 'No slugs or URLs are being changed', $html );
	}

	public function test_a_finished_preview_puts_both_reports_in_front_of_the_user() {
		$this->write_reports( 'run-one' );

		$html = $this->render( array( 'finished' => true, 'done' => 200 ) );

		$this->assertStringContainsString( 'data-state="finished"', $html );
		$this->assertStringContainsString( 'Your preview is ready', $html );
		$this->assertStringContainsString( 'Download changes CSV', $html );
		$this->assertStringContainsString( 'Download redirect CSV', $html );
		$this->assertStringContainsString( 'report=changes', $html );
		$this->assertStringContainsString( 'report=redirects', $html );
		$this->assertStringContainsString( 'ready to import into Redirection', $html );
		$this->assertStringContainsString( 'id="slug-sync-modal-close"', $html );

		// A finished run has nothing left to read or to continue.
		$this->assertStringNotContainsString( 'class="slug-sync-modal-reading"', $html );
		$this->assertStringNotContainsString( 'id="slug-sync-next"', $html );
	}

	public function test_a_finished_preview_hands_its_choices_back_to_the_form() {
		$this->write_reports( 'run-one' );

		$html = $this->render( array( 'finished' => true ) );

		$this->assertStringContainsString( 'slug_sync_preview=run-one', $html );
		$this->assertStringContainsString( 'Back to the setup form', $html );
	}

	public function test_a_nesting_content_type_is_warned_about_where_the_redirect_file_is() {
		$this->write_reports( 'run-two' );

		$html = $this->render(
			array(
				'run'      => array( 'id' => 'run-two', 'mode' => 'apply', 'post_type' => 'page' ),
				'run_id'   => 'run-two',
				'apply'    => true,
				'finished' => true,
			)
		);

		$this->assertStringContainsString( 'class="slug-sync-modal-warning"', $html );
		$this->assertStringContainsString( 'nests inside a parent', $html );
		$this->assertStringContainsString( 'Your new URLs are live', $html );
		$this->assertStringContainsString( 'Back to SlugSync', $html );
	}

	public function test_a_paused_run_can_download_what_it_has_and_still_resume() {
		$this->write_reports( 'run-one' );

		$html = $this->render( array( 'paused' => true ) );

		$this->assertStringContainsString( 'data-state="paused"', $html );
		$this->assertStringContainsString( 'The partial reports so far', $html );
		$this->assertStringContainsString( 'Download changes CSV', $html );
		$this->assertStringContainsString( 'Resume run', $html );
		$this->assertStringContainsString( 'Stop run', $html );
	}

	public function test_the_overlay_tickers_the_newest_three_matches_not_the_stored_thirty() {
		$html = $this->render(
			array(
				'run' => array(
					'id'              => 'run-one',
					'mode'            => 'dry',
					'post_type'       => 'post',
					'changed'         => 12,
					'recent_findings' => $this->findings( 12 ),
				),
			)
		);

		$this->assertSame( 3, substr_count( $html, '<li class="slug-sync-finding' ) );
		$this->assertStringContainsString( 'Item 12', $html );
		$this->assertStringContainsString( 'Item 10', $html );
		$this->assertStringNotContainsString( 'Item 9', $html );
		$this->assertStringContainsString( 'Showing the 3 most recent matches. The changes report contains all 12.', $html );
	}

	public function test_one_line_shows_per_batch_and_the_next_batch_moves_on() {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_modal_reading' );
		$method->setAccessible( true );

		$render = function ( $done ) use ( $method ) {
			ob_start();
			$method->invoke( null, $done, 'run-one' );

			return ob_get_clean();
		};

		$first = $render( 0 );
		$next  = $render( 100 );

		// One line only: a batch page does not live long enough to read more.
		$this->assertSame( 1, substr_count( $first, 'slug-sync-modal-read--' ) );
		$this->assertSame( 1, substr_count( $first, 'class="slug-sync-modal-read-icon"' ) );
		$this->assertNotSame( $first, $next );
		$this->assertStringNotContainsString( '--ss-read-index', $first );
		$this->assertStringNotContainsString( '<a ', $first );
	}

	public function test_two_runs_do_not_open_on_the_same_line() {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_modal_reading' );
		$method->setAccessible( true );

		$seen = array();

		foreach ( array( 'run-one', 'run-two', 'run-three', 'run-four' ) as $run_id ) {
			ob_start();
			$method->invoke( null, 0, $run_id );
			$seen[] = ob_get_clean();
		}

		$this->assertGreaterThan( 1, count( array_unique( $seen ) ) );
	}

	public function test_the_reading_lines_cover_what_the_plugin_actually_does() {
		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'run_modal_reading' );
		$method->setAccessible( true );

		$all = '';

		for ( $batch = 0; $batch < 40; $batch++ ) {
			ob_start();
			$method->invoke( null, $batch * 100, '' );
			$all .= ob_get_clean();
		}

		foreach ( array( 'redirects old post and product links', 'last 50 runs', 'Duplicate URLs', 'Quiet update', 'Nothing is tracked' ) as $free ) {
			$this->assertStringContainsString( $free, $all );
		}

		foreach ( array( 'Pro reads Chinese', 'Pro adds categories and tags', 'Pro maps name text', 'Cloudflare caches' ) as $pro ) {
			$this->assertStringContainsString( $pro, $all );
		}
	}

	public function test_dismissing_the_overlay_hands_focus_back_to_the_page() {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );

		$this->assertStringContainsString( "querySelector('.slug-sync-admin .slug-sync-brand')", $script );
		$this->assertStringContainsString( 'heading.focus()', $script );
	}

	public function test_the_overlay_carries_its_own_scroll_across_a_batch_reload() {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/admin.js' );

		$this->assertStringContainsString( 'slugSyncModalScroll', $script );
		$this->assertStringContainsString( 'modal.scrollTop = parseInt(stored, 10)', $script );
		$this->assertStringContainsString( "modal.addEventListener('scroll'", $script );
		$this->assertStringNotContainsString( 'localStorage', $script );
	}
}
