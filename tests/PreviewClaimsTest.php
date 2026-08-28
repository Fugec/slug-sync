<?php

use PHPUnit\Framework\TestCase;

final class PreviewClaimsTest extends TestCase {

	private $claim;
	private $claim_result;

	protected function setUp(): void {
		$GLOBALS['slug_sync_test_posts']      = array();
		$GLOBALS['slug_sync_test_transients'] = array();
		$GLOBALS['slug_sync_test_filters']    = array();
		$reflection = new ReflectionClass( 'Slug_Sync' );
		$this->claim = $reflection->getMethod( 'claim' );
		$this->claim->setAccessible( true );
		$this->claim_result = $reflection->getMethod( 'claim_result' );
		$this->claim_result->setAccessible( true );
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

	private function preview_result( $target, $post, $run_id ) {
		return $this->claim_result->invoke(
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

	public function test_prior_batch_claims_are_restored_from_loaded_report_rows_without_a_transient() {
		$this->post( 10, 'vacated' );
		$second = $this->post( 11, 'other' );
		$rows   = array(
			10 => array( 10, 'post', 'publish', 'First', 'vacated', 'replacement', '/vacated', '/replacement', '', 0 ),
		);

		$restore = ( new ReflectionClass( 'Slug_Sync' ) )->getMethod( 'restore_claims' );
		$restore->setAccessible( true );
		$restore->invoke( null, 'report-restore', $rows );

		$this->assertSame( 'vacated', $this->preview( 'vacated', $second, 'report-restore' ) );
		$this->assertArrayNotHasKey( 'slug_sync_claimed_report-restore', $GLOBALS['slug_sync_test_transients'] );
	}

	public function test_existing_database_slug_has_a_specific_reason() {
		$this->post( 20, 'shared' );
		$post = $this->post( 21, 'old' );

		$this->assertSame(
			array( 'slug' => 'shared-2', 'reason' => 'existing_slug' ),
			$this->preview_result( 'shared', $post, 'existing-reason' )
		);
	}

	public function test_another_item_in_the_same_preview_has_a_specific_reason() {
		$first  = $this->post( 22, 'old-a' );
		$second = $this->post( 23, 'old-b' );

		$this->assertSame( array( 'slug' => 'shared', 'reason' => '' ), $this->preview_result( 'shared', $first, 'preview-reason' ) );
		$this->assertSame(
			array( 'slug' => 'shared-2', 'reason' => 'preview_claim' ),
			$this->preview_result( 'shared', $second, 'preview-reason' )
		);
	}

	public function test_reserved_and_date_archive_targets_have_distinct_reasons() {
		$reserved = $this->post( 24, 'old-feed' );
		$archive  = $this->post( 25, 'old-number' );

		$this->assertSame(
			array( 'slug' => 'feed-2', 'reason' => 'reserved_word' ),
			$this->preview_result( 'feed', $reserved, 'reserved-reason' )
		);
		$this->assertSame(
			array( 'slug' => '12-2', 'reason' => 'date_archive' ),
			$this->preview_result( '12', $archive, 'archive-reason' )
		);
	}

	public function test_custom_bad_slug_filter_is_identified() {
		$GLOBALS['slug_sync_test_filters']['wp_unique_post_slug_is_bad_flat_slug'] = array(
			static function () {
				return true;
			},
		);
		$post = $this->post( 26, 'old-filtered' );

		$this->assertSame(
			array( 'slug' => 'filtered-2', 'reason' => 'custom_filter' ),
			$this->preview_result( 'filtered', $post, 'filter-reason' )
		);
	}
}
