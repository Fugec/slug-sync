<?php
/**
 * PHPUnit bootstrap.
 *
 * A deliberately small WordPress double. Signal tests stay pure, while the
 * preview regression tests load the real plugin class and supply only the core
 * functions its virtual slug simulator calls.
 *
 * @package Slug_Sync
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['slug_sync_test_transients'] = array();
$GLOBALS['slug_sync_test_posts']      = array();
$GLOBALS['wp_rewrite']                = (object) array(
	'feeds'           => array( 'feed', 'rss2' ),
	'pagination_base' => 'page',
);

function add_action() {}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_post_type_hierarchical( $post_type ) {
	return 'page' === $post_type;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['slug_sync_test_transients'] ) ? $GLOBALS['slug_sync_test_transients'][ $key ] : false;
}

function set_transient( $key, $value ) {
	$GLOBALS['slug_sync_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['slug_sync_test_transients'][ $key ] );
	return true;
}

function apply_filters( $hook, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $value;
}

function get_post( $post_id ) {
	return isset( $GLOBALS['slug_sync_test_posts'][ $post_id ] ) ? $GLOBALS['slug_sync_test_posts'][ $post_id ] : null;
}

function get_option( $option ) {
	return 'permalink_structure' === $option ? '/%postname%/' : '';
}

function _truncate_post_slug( $slug, $length = 200 ) {
	return rtrim( substr( $slug, 0, $length ), '-' );
}

function wp_generate_password() {
	return 'testtoken';
}

function wp_make_link_relative( $url ) {
	$parts = parse_url( $url );
	return isset( $parts['path'] ) ? $parts['path'] : $url;
}

/** Minimal wpdb collision double used by PreviewClaimsTest. */
final class Slug_Sync_Test_WPDB {
	public $posts = 'wp_posts';

	public function prepare( $query, ...$args ) {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_col( $prepared ) {
		$query = $prepared['query'];
		$args  = $prepared['args'];
		$slug  = $args[0];
		$ids   = array();

		foreach ( $GLOBALS['slug_sync_test_posts'] as $post ) {
			if ( $post->post_name !== $slug ) {
				continue;
			}

			if ( false !== strpos( $query, 'post_type IN' ) ) {
				if ( ( $post->post_type === $args[1] || 'attachment' === $post->post_type )
					&& (int) $post->ID !== (int) $args[2]
					&& (int) $post->post_parent === (int) $args[3]
				) {
					$ids[] = $post->ID;
				}
			} elseif ( false !== strpos( $query, 'post_type = %s' ) ) {
				if ( $post->post_type === $args[1] && (int) $post->ID !== (int) $args[2] ) {
					$ids[] = $post->ID;
				}
			} elseif ( (int) $post->ID !== (int) $args[1] ) {
				$ids[] = $post->ID;
			}
		}

		return $ids;
	}
}

$GLOBALS['wpdb'] = new Slug_Sync_Test_WPDB();

require_once __DIR__ . '/../slug-sync.php';
