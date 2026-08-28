<?php

use PHPUnit\Framework\TestCase;

final class CompatibilityPreflightTest extends TestCase {

	private $preflight;
	private $form;

	protected function setUp(): void {
		$reflection      = new ReflectionClass( 'Slug_Sync' );
		$this->preflight = $reflection->getMethod( 'environment_preflight' );
		$this->preflight->setAccessible( true );
		$this->form = $reflection->getMethod( 'form' );
		$this->form->setAccessible( true );
		$GLOBALS['slug_sync_test_options'] = array();
		$GLOBALS['slug_sync_test_filters'] = array();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	private function notices_by_code() {
		$indexed = array();

		foreach ( $this->preflight->invoke( null ) as $notice ) {
			$indexed[ $notice['code'] ] = $notice;
		}

		return $indexed;
	}

	private function render_form() {
		ob_start();
		$this->form->invoke( null );
		return ob_get_clean();
	}

	public function test_known_url_language_redirect_and_cache_plugins_are_explained() {
		$GLOBALS['slug_sync_test_options']['active_plugins'] = array(
			'permalink-manager/permalink-manager.php',
			'polylang/polylang.php',
			'redirection/redirection.php',
			'wp-rocket/wp-rocket.php',
		);

		$notices = $this->notices_by_code();

		$this->assertSame( array( 'public_url_override', 'multilingual', 'redirection', 'cache' ), array_keys( $notices ) );
		$this->assertSame( 'warning', $notices['public_url_override']['level'] );
		$this->assertStringContainsString( 'changing post_name may not change', $notices['public_url_override']['body'] );
		$this->assertStringContainsString( 'does not coordinate translation relationships', $notices['multilingual']['body'] );
		$this->assertSame( 'https://example.test/wp-admin/tools.php?page=redirection.php&sub=import', $notices['redirection']['action_url'] );
		$this->assertStringContainsString( 'Purge', $notices['cache']['body'] );
	}

	public function test_redirection_handoff_is_visible_but_never_claims_to_write_its_data() {
		$GLOBALS['slug_sync_test_options']['active_plugins'] = array( 'redirection/redirection.php' );

		$html = $this->render_form();

		$this->assertStringContainsString( 'Compatibility preflight', $html );
		$this->assertStringContainsString( 'Redirection is ready for the redirect report', $html );
		$this->assertStringContainsString( 'Slug Sync does not write Redirection&#039;s tables or settings.', $html );
		$this->assertStringContainsString( 'tools.php?page=redirection.php&amp;sub=import', $html );
		$this->assertStringContainsString( '<li class="slug-sync-card">', $html );
		$this->assertStringNotContainsString( 'slug-sync-preflight-item is-info', $html );
		$this->assertStringContainsString( 'class="button button-small button-primary"', $html );
		$this->assertStringContainsString( 'Open Redirection import', $html );
	}

	public function test_clear_result_does_not_promise_custom_or_server_compatibility() {
		$html = $this->render_form();

		$this->assertSame( array(), $this->preflight->invoke( null ) );
		$this->assertStringContainsString( 'No known integration warning was found.', $html );
		$this->assertStringContainsString( 'Custom code and server/CDN configuration still require your own review.', $html );
	}
}
