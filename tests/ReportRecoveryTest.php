<?php

use PHPUnit\Framework\TestCase;

final class ReportRecoveryTest extends TestCase {

	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function temporary_file() {
		$file = tempnam( sys_get_temp_dir(), 'slug-sync-test-' );
		$this->files[] = $file;
		return $file;
	}

	private function write_changes( $file, $rows ) {
		$handle = fopen( $file, 'w' );
		fputcsv( $handle, array( 'id', 'post_type', 'status', 'title', 'old_slug', 'new_slug', 'old_url', 'new_url', 'note', 'post_parent' ), ',', '"', '' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '' );
		}

		fclose( $handle );
	}

	public function test_final_redirect_report_uses_last_committed_row_per_post() {
		$changes  = $this->temporary_file();
		$redirect = $this->temporary_file();
		$this->files[] = $redirect . '.tmp-testtoken.csv';

		$this->write_changes(
			$changes,
			array(
				array( 10, 'post', 'publish', 'Title', 'old', 'new', 'https://example.test/old', 'https://example.test/new', '', 0 ),
				array( 10, 'post', 'publish', 'Title', 'old', 'new-final', 'https://example.test/old', 'https://example.test/new-final', 'adjusted', 0 ),
				array( 11, 'post', 'draft', 'Draft', 'draft-old', 'draft-new', 'https://example.test/?p=11', 'https://example.test/?p=11', '', 0 ),
			)
		);

		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'rebuild_redirect_report' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $changes, $redirect ) );

		$handle = fopen( $redirect, 'r' );
		$this->assertSame( array( '/old', '/new-final' ), fgetcsv( $handle, 0, ',', '"', '' ) );
		$this->assertFalse( fgetcsv( $handle, 0, ',', '"', '' ) );
		fclose( $handle );
	}

	public function test_existing_report_is_replaced_with_the_windows_safe_fallback() {
		$source      = $this->temporary_file();
		$destination = $this->temporary_file();
		file_put_contents( $source, 'new report' );
		file_put_contents( $destination, 'old report' );

		$method = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'replace_report_file' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, $source, $destination, false ) );
		$this->assertSame( 'new report', file_get_contents( $destination ) );
		$this->assertFileDoesNotExist( $source );
		$this->assertSame( array(), glob( $destination . '.bak-*.csv' ) );
	}
}
