<?php

use PHPUnit\Framework\TestCase;

final class FindingsTest extends TestCase {

	private $merge;
	private $render;

	protected function setUp(): void {
		$reflection = new ReflectionClass( 'Slug_Sync' );
		$this->merge = $reflection->getMethod( 'merge_recent_findings' );
		$this->merge->setAccessible( true );
		$this->render = $reflection->getMethod( 'render_findings' );
		$this->render->setAccessible( true );
	}

	private function finding( $id, $title = '' ) {
		return array(
			'id'    => $id,
			'title' => $title ? $title : 'Item ' . $id,
			'old'   => 'old-' . $id,
			'new'   => 'new-' . $id,
			'note'  => '',
		);
	}

	public function test_recent_feed_is_deduplicated_and_bounded() {
		$batch = array();
		for ( $id = 1; $id <= 35; $id++ ) {
			$batch[] = $this->finding( $id );
		}
		$batch[] = $this->finding( 35, 'Updated item 35' );

		$result = $this->merge->invoke( null, array(), $batch );

		$this->assertCount( 30, $result );
		$this->assertSame( 6, $result[0]['id'] );
		$this->assertSame( 35, $result[29]['id'] );
		$this->assertSame( 'Updated item 35', $result[29]['title'] );
	}

	public function test_accumulated_items_are_visible_and_current_batch_is_marked() {
		$old = $this->finding( 1 );
		$new = $this->finding( 2 );
		$run = array(
			'changed'         => 2,
			'recent_findings' => array( $old, $new ),
		);

		ob_start();
		$this->render->invoke( null, $run, array( $new ), array() );
		$html = ob_get_clean();

		$this->assertStringContainsString( '2 found so far', $html );
		$this->assertStringContainsString( '#1 Item 1', $html );
		$this->assertStringContainsString( '#2 Item 2', $html );
		$this->assertSame( 1, substr_count( $html, 'New this batch' ) );
		$this->assertStringNotContainsString( '<details', $html );
	}
}
