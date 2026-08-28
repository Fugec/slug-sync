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
$GLOBALS['slug_sync_test_options']    = array();
$GLOBALS['slug_sync_test_meta']       = array();
$GLOBALS['slug_sync_test_styles']     = array();
$GLOBALS['slug_sync_test_scripts']    = array();
$GLOBALS['slug_sync_test_script_data'] = array();
$GLOBALS['slug_sync_test_filters']    = array();
$GLOBALS['slug_sync_test_uploads']    = sys_get_temp_dir() . '/slug-sync-tests-' . getmypid();
$GLOBALS['wp_rewrite']                = (object) array(
	'feeds'           => array( 'feed', 'rss2' ),
	'pagination_base' => 'page',
);

function add_action() {}

function plugins_url( $path ) {
	return 'https://example.test/wp-content/plugins/slug-sync/' . ltrim( $path, '/' );
}

function wp_enqueue_style( $handle, $src, $dependencies, $version ) {
	$GLOBALS['slug_sync_test_styles'][ $handle ] = compact( 'src', 'dependencies', 'version' );
}

function wp_enqueue_script( $handle, $src, $dependencies, $version, $in_footer ) {
	$GLOBALS['slug_sync_test_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'in_footer' );
}

function wp_localize_script( $handle, $object_name, $data ) {
	$GLOBALS['slug_sync_test_script_data'][ $handle ] = compact( 'object_name', 'data' );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function __( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return esc_html( $text );
}

function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

function has_filter( $hook, $callback = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return empty( $GLOBALS['slug_sync_test_filters'][ $hook ] ) ? false : 10;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr_e( $text ) {
	echo esc_attr( $text );
}

function esc_attr__( $text ) {
	return esc_attr( $text );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function number_format_i18n( $number ) {
	return number_format( (float) $number, 0, '.', ',' );
}

function esc_html_e( $text ) {
	echo esc_html( $text );
}

function checked( $checked, $current = true, $display = true ) {
	$result = (string) $checked === (string) $current ? 'checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? 'selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}

function esc_js( $text ) {
	return str_replace( array( "\\", "'", "\r", "\n" ), array( "\\\\", "\\'", '\\r', '\\n' ), (string) $text );
}

function esc_url( $url ) {
	return esc_attr( $url );
}

function wp_nonce_field() {
	echo '<input type="hidden" name="_wpnonce" value="testnonce">';
}

function add_query_arg( $key, $value = null, $url = '' ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url  = is_array( $key ) ? (string) $value : (string) $url;

	foreach ( $args as $name => $arg ) {
		$separator = false === strpos( $url, '?' ) ? '?' : '&';
		$url      .= $separator . rawurlencode( $name ) . '=' . rawurlencode( (string) $arg );
	}

	return $url;
}

function trailingslashit( $path ) {
	return rtrim( (string) $path, '/\\' ) . '/';
}

function wp_upload_dir() {
	return array( 'basedir' => $GLOBALS['slug_sync_test_uploads'] );
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function update_option( $name, $value ) {
	$GLOBALS['slug_sync_test_options'][ $name ] = $value;
	return true;
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$separator = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $separator . rawurlencode( $name ) . '=testnonce';
}

function wp_verify_nonce( $nonce, $action = -1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return 'testnonce' === (string) $nonce ? 1 : false;
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function is_post_type_hierarchical( $post_type ) {
	return 'page' === $post_type;
}

function get_post_types() {
	return array(
		'post'       => (object) array( 'labels' => (object) array( 'name' => 'Posts' ) ),
		'page'       => (object) array( 'labels' => (object) array( 'name' => 'Pages' ) ),
		'product'    => (object) array( 'labels' => (object) array( 'name' => 'Products' ) ),
		'attachment' => (object) array( 'labels' => (object) array( 'name' => 'Media' ) ),
	);
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


function apply_filters( $hook, $value, ...$args ) {
	foreach ( isset( $GLOBALS['slug_sync_test_filters'][ $hook ] ) ? (array) $GLOBALS['slug_sync_test_filters'][ $hook ] : array() as $callback ) {
		if ( is_callable( $callback ) ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function get_post( $post_id ) {
	return isset( $GLOBALS['slug_sync_test_posts'][ $post_id ] ) ? $GLOBALS['slug_sync_test_posts'][ $post_id ] : null;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( ! isset( $GLOBALS['slug_sync_test_meta'][ $post_id ] ) ) {
		return $single ? '' : array();
	}

	if ( '' === $key ) {
		return $GLOBALS['slug_sync_test_meta'][ $post_id ];
	}

	if ( ! array_key_exists( $key, $GLOBALS['slug_sync_test_meta'][ $post_id ] ) ) {
		return $single ? '' : array();
	}

	$value = $GLOBALS['slug_sync_test_meta'][ $post_id ][ $key ];
	return $single ? $value : array( $value );
}

function get_option( $option, $default = false ) {
	if ( 'permalink_structure' === $option ) {
		return '/%postname%/';
	}

	return array_key_exists( $option, $GLOBALS['slug_sync_test_options'] )
		? $GLOBALS['slug_sync_test_options'][ $option ]
		: $default;
}

function wp_unslash( $value ) {
	return $value;
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
