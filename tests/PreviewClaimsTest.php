<?php

use PHPUnit\Framework\TestCase;

final class PreviewClaimsTest extends TestCase {

	private $claim;

	protected function setUp(): void {
		$GLOBALS['slug_sync_test_posts']      = array();
		$GLOBALS['slug_sync_test_transients'] = array();
		$this->claim = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'claim' );
		$this->claim->setAccessible( true );
	}

	private function post( $id, $slug, $type = 'post', $status = 'publish', $parent = 0 ) {
		$post = (object) array(
			'ID'          => $id,
			'post_name'   => $slug,
			'post_type'   => $type,
			'post_status' => $status,
			'post_parent' => $parent,
		);
		$GLOBALS['slug_sync_test_posts'][ $id ] = $post;
		return $post;
	}

	private function preview( $target, $post, $run_id ) {
		return $this->claim->invoke(
			null,
			$target,
			$post->ID,
			$post->post_status,
			$post->post_type,
			$post->post_parent,
			true,
			$run_id,
			$post->post_name
		);
	}

	public function test_hierarchical_slugs_are_scoped_to_their_parent() {
		$first  = $this->post( 1, 'old-a', 'page', 'publish', 10 );
		$second = $this->post( 2, 'old-b', 'page', 'publish', 20 );

		$this->assertSame( 'shared', $this->preview( 'shared', $first, 'hierarchical' ) );
		$this->assertSame( 'shared', $this->preview( 'shared', $second, 'hierarchical' ) );
	}

	public function test_drafts_match_wordpress_no_uniqueness_rule() {
		$first  = $this->post( 3, 'draft-old-a', 'post', 'draft' );
		$second = $this->post( 4, 'draft-old-b', 'post', 'draft' );

		$this->assertSame( 'draft-shared', $this->preview( 'draft-shared', $first, 'drafts' ) );
		$this->assertSame( 'draft-shared', $this->preview( 'draft-shared', $second, 'drafts' ) );
	}

	public function test_existing_suffixes_increment_like_wordpress() {
		$this->post( 5, 'taken' );
		$first  = $this->post( 6, 'source-a' );
		$second = $this->post( 7, 'source-b' );

		$this->assertSame( 'taken-2', $this->preview( 'taken', $first, 'suffixes' ) );
		$this->assertSame( 'taken-3', $this->preview( 'taken', $second, 'suffixes' ) );
	}

	public function test_an_earlier_transition_releases_its_old_slug() {
		$first  = $this->post( 8, 'vacated' );
		$second = $this->post( 9, 'other' );

		$this->assertSame( 'replacement', $this->preview( 'replacement', $first, 'release' ) );
		$this->assertSame( 'vacated', $this->preview( 'vacated', $second, 'release' ) );
	}
}
