<?php

use PHPUnit\Framework\TestCase;

final class AssetLoadingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['slug_sync_test_styles']      = array();
		$GLOBALS['slug_sync_test_scripts']     = array();
		$GLOBALS['slug_sync_test_script_data'] = array();
	}

	public function test_assets_are_not_loaded_on_unrelated_admin_screens() {
		Slug_Sync::enqueue_assets( 'dashboard_page_home' );

		$this->assertSame( array(), $GLOBALS['slug_sync_test_styles'] );
		$this->assertSame( array(), $GLOBALS['slug_sync_test_scripts'] );
		$this->assertSame( array(), $GLOBALS['slug_sync_test_script_data'] );
	}

	public function test_local_assets_and_translated_data_load_on_the_tools_screen() {
		Slug_Sync::enqueue_assets( 'tools_page_slug-sync' );

		$this->assertSame(
			'https://example.test/wp-content/plugins/slug-sync/assets/admin.css',
			$GLOBALS['slug_sync_test_styles']['slug-sync-admin']['src']
		);
		$this->assertSame( SLUG_SYNC_VERSION, $GLOBALS['slug_sync_test_styles']['slug-sync-admin']['version'] );
		$this->assertSame(
			'https://example.test/wp-content/plugins/slug-sync/assets/admin.js',
			$GLOBALS['slug_sync_test_scripts']['slug-sync-admin']['src']
		);
		$this->assertTrue( $GLOBALS['slug_sync_test_scripts']['slug-sync-admin']['in_footer'] );
		$this->assertSame( 'SlugSyncAdmin', $GLOBALS['slug_sync_test_script_data']['slug-sync-admin']['object_name'] );
		$this->assertTrue( $GLOBALS['slug_sync_test_script_data']['slug-sync-admin']['data']['hierarchical']['page'] );
		$this->assertFalse( $GLOBALS['slug_sync_test_script_data']['slug-sync-admin']['data']['hierarchical']['post'] );
		$this->assertSame(
			'Apply slug changes',
			$GLOBALS['slug_sync_test_script_data']['slug-sync-admin']['data']['text']['apply_button']
		);
	}

	public function test_assets_also_load_for_the_noncanonical_fallback_screen() {
		Slug_Sync::enqueue_assets( 'admin_page_slug-sync' );

		$this->assertArrayHasKey( 'slug-sync-admin', $GLOBALS['slug_sync_test_styles'] );
		$this->assertArrayHasKey( 'slug-sync-admin', $GLOBALS['slug_sync_test_scripts'] );
	}
}
