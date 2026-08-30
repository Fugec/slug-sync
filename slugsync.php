<?php
/**
 * Plugin Name:       SlugSync
 * Plugin URI:        https://slugsync.com/
 * Description:       Previews and safely regenerates content slugs from titles, with optional transliteration and WooCommerce SKU cleanup, redirects, reports and undo.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            SlugSync
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slugsync
 * Domain Path:       /languages
 *
 * @package Slug_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * A second copy of the plugin -- a manual install left in place beside the
 * directory version, say -- would otherwise fatal on the duplicate class
 * declaration, before WordPress could report which plugin caused it.
 *
 * The test is the constant, not class_exists( 'Slug_Sync' ). PHP early-binds a
 * top-level class that has no parent, so Slug_Sync exists the moment this file
 * is compiled -- before line one of it runs. Testing for the class therefore
 * returned true on this file's own first load, and every load after: the
 * constant below was never defined, the signals class below it was never
 * required, and Slug_Sync::run() fatalled on its first row with
 * "Class Slug_Sync_Signals not found". The guard silently disabled the plugin
 * it was written to protect.
 *
 * The constant has neither problem. It is defined by whichever copy loads
 * first, and a second copy sees it and returns before its own class
 * declaration is reached -- which is also the point at which PHP would
 * otherwise refuse the duplicate.
 */
if ( defined( 'SLUG_SYNC_VERSION' ) ) {
	return;
}

define( 'SLUG_SYNC_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-slug-sync-signals.php';
require_once __DIR__ . '/includes/class-slug-sync-transforms.php';

/**
 * Rewrites slugs to match titles, in batches, with a preview and a rollback.
 */
class Slug_Sync {

	const CAP        = 'manage_options';
	const PAGE       = 'slug-sync';
	const CLAIM_KEY  = 'slug_sync_claimed';
	const TOKEN_OPT  = 'slug_sync_token';
	const RUNS_OPT   = 'slug_sync_runs';
	const ACTIVE_OPT = 'slug_sync_active_run';
	const LOCK_OPT   = 'slug_sync_lock';
	const LOCK_TTL   = 15 * MINUTE_IN_SECONDS;

	/** post_name is varchar(200); leave headroom for -NN uniqueness suffixes. */
	const MAX_SLUG = 190;

	/** Finished runs kept in history before the oldest are pruned with their reports. */
	const MAX_RUNS = 50;

	/** Recent matches kept on screen while the complete result remains in CSV. */
	const MAX_RECENT_FINDINGS = 30;

	/**
	 * Matches shown in the run overlay. The thirty kept above are for the
	 * page; the overlay is a ticker and only needs the newest few.
	 *
	 * @var int
	 */
	const MODAL_FINDINGS = 3;

	/** Advertised Pro price, kept out of the translatable sentence around it. */
	const PRO_PRICE = '$79.99';

	/**
	 * In-request virtual slug transitions for a preview run, keyed by run ID.
	 *
	 * Each run contains a post map and an occupied-slug index. Keeping the post
	 * map is what lets a preview treat an old slug as released after an earlier
	 * row moves away from it; keeping the index avoids scanning the whole run for
	 * every collision check.
	 *
	 * The durable changes report is already read once at the start of every
	 * batch. Preview state is reconstructed from those rows instead of storing
	 * an ever-growing serialized map in a transient.
	 *
	 * @var array<string,array{posts:array<int,array<string,mixed>>,occupied:array<string,array<string,array<int,bool>>}>
	 */
	private static $claims = array();

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_to_tools_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_slug_sync_download', array( __CLASS__, 'download_report' ) );
	}

	/**
	 * Redirect noncanonical plugin launch URLs to the registered Tools screen.
	 *
	 * Some launchers assume every plugin screen lives below admin.php, while
	 * multisite launchers can retain a network-admin path. A page added with
	 * add_management_page() is registered only below the site admin's tools.php,
	 * so WordPress otherwise responds with "Cannot load slug-sync." before the
	 * page callback can run.
	 */
	public static function redirect_to_tools_page() {
		global $pagenow;

		// Nonce verification is not needed for this read-only route check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE !== $page ) {
			return;
		}

		$is_canonical = 'tools.php' === $pagenow && ! is_network_admin() && ! is_user_admin();

		if ( $is_canonical ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slugsync' ) );
		}

		if ( wp_safe_redirect( self::page_url() ) ) {
			exit;
		}
	}

	/**
	 * Canonical URL for the plugin screen.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	/**
	 * Register the Tools screen.
	 */
	public static function menu() {
		/*
		 * Register a hidden admin.php route as a fallback for launchers that use
		 * admin.php?page=slug-sync. The redirect above normally canonicalizes
		 * that request; this registration also prevents WordPress from rejecting
		 * it if another plugin suppresses redirects during admin_init.
		 *
		 * Register this first so add_management_page() remains the page's canonical
		 * parent in WordPress's internal menu map.
		 */
		add_submenu_page(
			'admin.php',
			__( 'SlugSync', 'slugsync' ),
			__( 'SlugSync', 'slugsync' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		add_management_page(
			__( 'SlugSync', 'slugsync' ),
			__( 'SlugSync', 'slugsync' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * How many rows to process per request.
	 *
	 * @return int
	 */
	private static function batch_size() {
		/**
		 * Filters the number of posts processed per batch.
		 *
		 * @param int $size Default 100.
		 */
		return max( 10, (int) apply_filters( 'slug_sync_batch_size', 100 ) );
	}

	/**
	 * Post types the tool can operate on.
	 *
	 * @return WP_Post_Type[] Keyed by name.
	 */
	private static function post_types() {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		unset( $types['attachment'] );

		/**
		 * Filters the selectable post types.
		 *
		 * @param WP_Post_Type[] $types Post type objects keyed by name.
		 */
		return apply_filters( 'slug_sync_post_types', $types );
	}

	/**
	 * Default post type for the form.
	 *
	 * @return string
	 */
	private static function default_type() {
		$types = self::post_types();
		if ( isset( $types['product'] ) ) {
			return 'product';
		}
		return isset( $types['post'] ) ? 'post' : (string) key( $types );
	}

	/**
	 * Read a validated post type out of the request.
	 *
	 * @return string
	 */
	private static function requested_type() {
		$types = self::post_types();
		// Nonce is verified by the calling method before this runs.
		$type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $types[ $type ] ) ? $type : self::default_type();
	}

	/* ----------------------------------------------------------- run state */

	/**
	 * Stored run records, keyed by run ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function runs() {
		$runs = get_option( self::RUNS_OPT, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * Read one stored run.
	 *
	 * @param string $run_id Run ID.
	 * @return array<string,mixed>|null
	 */
	private static function get_run( $run_id ) {
		$run_id = sanitize_key( $run_id );
		$runs   = self::runs();
		return isset( $runs[ $run_id ] ) && is_array( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
	}

	/**
	 * Store one run record.
	 *
	 * @param array<string,mixed> $run Run record.
	 * @return bool Whether WordPress persisted the new record.
	 */
	private static function save_run( $run ) {
		if ( empty( $run['id'] ) ) {
			return false;
		}

		$run_id = sanitize_key( $run['id'] );
		$runs   = self::runs();

		$run['id']       = $run_id;
		$runs[ $run_id ] = $run;
		return (bool) update_option( self::RUNS_OPT, $runs, false );
	}

	/**
	 * Keep the stored run history bounded.
	 *
	 * Every run adds an entry to one option and two CSVs under uploads, and
	 * nothing removed either before uninstall. A site that runs this weekly for
	 * a year would carry fifty megabytes of stale reports it never asked for, so
	 * finished runs past MAX_RUNS are dropped together with their files.
	 *
	 * Unfinished runs are never pruned: one of them may still be resumable, and
	 * deleting its reports would strand it. Runs are stored oldest first, which
	 * is the order they are dropped in.
	 *
	 * @param string $keep_id Run ID that must survive pruning.
	 */
	private static function prune_runs( $keep_id = '' ) {
		$runs = self::runs();

		if ( count( $runs ) <= self::MAX_RUNS ) {
			return;
		}

		$keep_id = sanitize_key( $keep_id );
		$stale   = array_slice( $runs, 0, count( $runs ) - self::MAX_RUNS, true );
		$pruned  = false;

		foreach ( array_keys( $stale ) as $run_id ) {
			if ( $run_id === $keep_id || ! self::run_is_finished( $runs[ $run_id ] ) ) {
				continue;
			}

			foreach ( array( 'changes', 'redirects', 'journal' ) as $report ) {
				$path = self::report_path( $run_id, $report );

				if ( $path && is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $path );
				}
			}

			self::reset_claims( $run_id );
			unset( $runs[ $run_id ] );
			$pruned = true;
		}

		if ( $pruned ) {
			update_option( self::RUNS_OPT, $runs, false );
		}
	}

	/**
	 * Whether a run has reached a terminal state.
	 *
	 * @param array<string,mixed> $run Run record.
	 * @return bool
	 */
	private static function run_is_finished( $run ) {
		return isset( $run['status'] ) && in_array( $run['status'], array( 'completed', 'canceled', 'rolled_back' ), true );
	}

	/**
	 * Whether any stored run has been carried through to a result.
	 *
	 * The introduction is for somebody who has not been through the loop yet,
	 * so this is deliberately narrower than run_is_finished(): a run still
	 * going has shown them no result, and a run they stopped part-way is not
	 * evidence they know how this works either. Only a completed run, or one
	 * completed and then undone, retires the three steps.
	 *
	 * @return bool
	 */
	private static function has_finished_run() {
		foreach ( self::runs() as $run ) {
			if ( ! is_array( $run ) || ! isset( $run['status'] ) ) {
				continue;
			}

			if ( in_array( $run['status'], array( 'completed', 'rolled_back' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the active run, cleaning up stale pointers to completed runs.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function active_run() {
		$run_id = sanitize_key( (string) get_option( self::ACTIVE_OPT, '' ) );

		if ( ! $run_id ) {
			return null;
		}

		$run = self::get_run( $run_id );

		if ( ! $run || self::run_is_finished( $run ) ) {
			delete_option( self::ACTIVE_OPT );
			return null;
		}

		return $run;
	}

	/**
	 * Clear the active pointer if it still belongs to the supplied run.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function clear_active_run( $run_id ) {
		if ( sanitize_key( (string) get_option( self::ACTIVE_OPT, '' ) ) === sanitize_key( $run_id ) ) {
			delete_option( self::ACTIVE_OPT );
		}
	}

	/**
	 * Start and persist a new run from the submitted settings.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function create_run() {
		if ( self::active_run() ) {
			return null;
		}

		$runs      = self::runs();
		$post_type = self::requested_type();
		$sku_mode  = isset( $_POST['sku_mode'] ) ? sanitize_key( wp_unslash( $_POST['sku_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$write     = isset( $_POST['write'] ) ? sanitize_key( wp_unslash( $_POST['write'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $mode, array( 'dry', 'apply' ), true ) || ! in_array( $write, array( 'quiet', 'hooks' ), true ) ) {
			return null;
		}

		if ( 'product' === $post_type && ! in_array( $sku_mode, array( 'keep', 'remove', 'include' ), true ) ) {
			return null;
		}

		if ( 'product' !== $post_type ) {
			$sku_mode = 'keep';
		}

		do {
			$run_id = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		} while ( isset( $runs[ $run_id ] ) );

		// Nonce is verified by render() before this method runs.
		$run = array(
			'id'                => $run_id,
			'created_at'        => time(),
			'updated_at'        => time(),
			'completed_at'      => 0,
			'user_id'           => get_current_user_id(),
			'post_type'         => $post_type,
			'mode'              => $mode,
			'write'             => $write,
			'addon_rules'       => (bool) has_filter( 'slug_sync_source_title' ),
			'transliterate'     => ! empty( $_POST['transliterate'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'remove_sku'        => 'remove' === $sku_mode,
			'include_sku'       => 'include' === $sku_mode,
			'drafts'            => ! empty( $_POST['drafts'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'suffixed'          => ! empty( $_POST['suffixed'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'pause_after_batch' => ! empty( $_POST['testonly'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'status'            => 'running',
			'last_id'           => 0,
			'done'              => 0,
			'total'             => 0,
			'changed'           => 0,
			'errors'            => 0,
			'sig_code'          => 0,
			'sig_stopword'      => 0,
			'sig_non_latin'     => 0,
			'risk'              => self::risk_from_rows( array(), $post_type ),
			'recent_findings'   => array(),
		);

		if ( ! self::add_option_once( self::ACTIVE_OPT, $run_id ) ) {
			return null;
		}

		if ( ! self::save_run( $run ) ) {
			self::clear_active_run( $run_id );
			return null;
		}
		self::prune_runs( $run_id );

		return $run;
	}

	/**
	 * Create an option row only if it does not exist yet, atomically.
	 *
	 * add_option() cannot be used to claim a lock. It guards with a non-atomic
	 * get_option() check and then runs INSERT ... ON DUPLICATE KEY UPDATE, which
	 * overwrites an existing row rather than failing, so two concurrent requests
	 * can both come away believing they created it. INSERT IGNORE leans on the
	 * unique index on option_name instead, so exactly one request can ever win.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool True when this request created the row.
	 */
	private static function add_option_once( $option, $value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$option,
				maybe_serialize( $value )
			)
		);

		self::forget_cached_option( $option );

		return 1 === (int) $inserted;
	}

	/**
	 * Delete an option only while it still holds the exact value just read.
	 *
	 * Expires a stale lock without discarding a fresh one that another request
	 * acquired between the read and the delete.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $expected Value the row must still hold.
	 * @return bool True when the row was removed.
	 */
	private static function delete_option_if( $option, $expected ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option,
				'option_value' => maybe_serialize( $expected ),
			),
			array( '%s', '%s' )
		);

		self::forget_cached_option( $option );

		return (bool) $deleted;
	}

	/**
	 * Drop a cached option value after writing the row behind get_option()'s back.
	 *
	 * Both the cached value and the "this option does not exist" marker have to go,
	 * or the next read returns the pre-write state.
	 *
	 * @param string $option Option name.
	 */
	private static function forget_cached_option( $option ) {
		wp_cache_delete( $option, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			unset( $notoptions[ $option ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * Acquire a short-lived atomic lock for one batch or maintenance action.
	 *
	 * @param string $run_id Run or operation ID.
	 * @return string Lock token, or an empty string when another request owns it.
	 */
	private static function acquire_lock( $run_id ) {
		$now   = time();
		$token = wp_generate_password( 20, false, false );
		$lock  = array(
			'run_id'      => sanitize_key( $run_id ),
			'token'       => $token,
			'acquired_at' => $now,
		);

		if ( self::add_option_once( self::LOCK_OPT, $lock ) ) {
			self::release_lock_on_shutdown( $token );
			return $token;
		}

		$current = get_option( self::LOCK_OPT, array() );

		if ( ! is_array( $current ) || empty( $current['acquired_at'] ) || (int) $current['acquired_at'] < $now - self::LOCK_TTL ) {
			// Retire that exact row. A compare-and-delete keeps two requests that
			// both saw the same stale lock from deleting each other's replacement.
			self::delete_option_if( self::LOCK_OPT, $current );

			if ( self::add_option_once( self::LOCK_OPT, $lock ) ) {
				self::release_lock_on_shutdown( $token );
				return $token;
			}
		}

		return '';
	}

	/**
	 * Drop the lock if the request dies before it is released normally.
	 *
	 * rollback() walks an entire changes report in one request, so on a large
	 * catalogue it can exceed max_execution_time. Shutdown functions still run
	 * when that happens. Without one the abandoned lock stays fresh for the
	 * whole of LOCK_TTL, and the retry that would have finished the job -- undo
	 * is idempotent, so a retry resumes cleanly -- is refused for fifteen
	 * minutes. release_lock() only deletes a row this request still owns, so
	 * running it a second time on the normal path is a no-op.
	 *
	 * @param string $token Lock token.
	 */
	private static function release_lock_on_shutdown( $token ) {
		register_shutdown_function(
			static function () use ( $token ) {
				self::release_lock( $token );
			}
		);
	}

	/**
	 * Release a lock only when this request still owns it.
	 *
	 * @param string $token Lock token.
	 */
	private static function release_lock( $token ) {
		$lock = get_option( self::LOCK_OPT, array() );

		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
			self::delete_option_if( self::LOCK_OPT, $lock );
		}
	}

	/**
	 * Legacy transient key used for cleanup after upgrading from an earlier
	 * preview-state implementation.
	 *
	 * @param string $run_id Run ID.
	 * @return string
	 */
	private static function claim_key( $run_id ) {
		return self::CLAIM_KEY . '_' . sanitize_key( $run_id );
	}

	/**
	 * Empty virtual-slug state for one preview run.
	 *
	 * @return array{posts:array<int,array<string,mixed>>,occupied:array<string,array<string,array<int,bool>>}
	 */
	private static function empty_claims() {
		return array(
			'posts'    => array(),
			'occupied' => array(),
		);
	}

	/**
	 * Initialize a run's claims in this request.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function load_claims( $run_id ) {
		$key = sanitize_key( $run_id );

		if ( ! isset( self::$claims[ $key ] ) ) {
			self::$claims[ $key ] = self::empty_claims();
		}
	}

	/**
	 * Collision namespace used by WordPress for a post slug.
	 *
	 * Attachments are global, hierarchical types are scoped to a parent, and
	 * flat types are scoped to their post type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent post ID.
	 * @return string
	 */
	private static function claim_namespace( $post_type, $parent ) {
		if ( 'attachment' === $post_type ) {
			return 'attachment';
		}

		if ( is_post_type_hierarchical( $post_type ) ) {
			return 'hier:' . $post_type . ':' . absint( $parent );
		}

		return 'flat:' . $post_type;
	}

	/**
	 * Record how one post would move during a preview.
	 *
	 * @param string $run_id    Run ID.
	 * @param int    $post_id   Post ID.
	 * @param string $old_slug  Current slug in the database.
	 * @param string $new_slug  Simulated new slug.
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent post ID.
	 */
	private static function remember_claim( $run_id, $post_id, $old_slug, $new_slug, $status, $post_type, $parent ) {
		self::load_claims( $run_id );

		$key       = sanitize_key( $run_id );
		$post_id   = absint( $post_id );
		$namespace = self::claim_namespace( $post_type, $parent );
		$state     = &self::$claims[ $key ];

		if ( isset( $state['posts'][ $post_id ] ) ) {
			$previous = $state['posts'][ $post_id ];
			$old_ns   = isset( $previous['namespace'] ) ? $previous['namespace'] : '';
			$old_new  = isset( $previous['new_slug'] ) ? $previous['new_slug'] : '';

			if ( isset( $state['occupied'][ $old_ns ][ $old_new ][ $post_id ] ) ) {
				unset( $state['occupied'][ $old_ns ][ $old_new ][ $post_id ] );
			}
		}

		$state['posts'][ $post_id ] = array(
			'old_slug'  => (string) $old_slug,
			'new_slug'  => (string) $new_slug,
			'status'    => (string) $status,
			'post_type' => (string) $post_type,
			'parent'    => absint( $parent ),
			'namespace' => $namespace,
		);
		$state['occupied'][ $namespace ][ $new_slug ][ $post_id ] = true;
	}

	/**
	 * Drop a run's claims from both the request and storage.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function reset_claims( $run_id ) {
		$key = sanitize_key( $run_id );

		unset( self::$claims[ $key ] );
		delete_transient( self::claim_key( $run_id ) );
	}

	/**
	 * Rebuild preview collision claims from rows already loaded from the durable
	 * changes report for idempotency checks at the start of each batch.
	 *
	 * @param string                                      $run_id Run ID.
	 * @param array<int,array<int,string|int|float|null>> $rows   Last committed row for each post ID.
	 */
	private static function restore_claims( $run_id, $rows ) {
		$key = sanitize_key( $run_id );
		self::$claims[ $key ] = self::empty_claims();

		foreach ( $rows as $row ) {
			if ( count( $row ) >= 6 ) {
				$post_id = absint( $row[0] );
				$parent  = isset( $row[9] ) ? absint( $row[9] ) : absint( get_post_field( 'post_parent', $post_id ) );

				self::remember_claim( $run_id, $post_id, $row[4], $row[5], $row[2], $row[1], $parent );
			}
		}

		// Remove an obsolete value left by a run started on an earlier version.
		delete_transient( self::claim_key( $run_id ) );
	}

	/* ------------------------------------------------------------- reports */

	/**
	 * Report directory, named with a random token so the export is not guessable.
	 *
	 * @return array{path:string}
	 */
	private static function reports() {
		static $reports = null;

		if ( null !== $reports ) {
			return $reports;
		}

		$token = get_option( self::TOKEN_OPT );

		if ( ! $token ) {
			$token = wp_generate_password( 20, false, false );
			update_option( self::TOKEN_OPT, $token, false );
		}

		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'slug-sync-' . $token;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! is_file( $dir . '/index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.html', '' );
		}

		/*
		 * Reports are served through admin-post.php behind a capability and nonce
		 * check, so nothing legitimate ever fetches these files directly.
		 * "Options -Indexes" on its own only hides the listing and left the CSVs
		 * readable by anyone who learned the path, so deny the directory outright.
		 * Rewritten whenever the rules change so existing installs pick this up.
		 * Apache only; on nginx the unguessable directory name is what protects it.
		 */
		$htaccess = $dir . '/.htaccess';
		$rules    = "Options -Indexes\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_file( $htaccess ) || file_get_contents( $htaccess ) !== $rules ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, $rules );
		}

		$reports = array( 'path' => trailingslashit( $dir ) );

		return $reports;
	}

	/**
	 * Safe filename for one report in a run.
	 *
	 * @param string $run_id Run ID, or an empty string for legacy reports.
	 * @param string $report Report key: changes or redirects.
	 * @return string
	 */
	private static function report_filename( $run_id, $report ) {
		$prefixes = array(
			'changes'   => 'slug-changes',
			'redirects' => 'slug-redirects',
			'journal'   => 'slug-journal',
		);

		if ( ! isset( $prefixes[ $report ] ) ) {
			return '';
		}

		$run_id = sanitize_key( $run_id );
		return $prefixes[ $report ] . ( $run_id ? '-' . $run_id : '' ) . '.csv';
	}

	/**
	 * Filesystem path for one report.
	 *
	 * @param string $run_id Run ID, or an empty string for legacy reports.
	 * @param string $report Report key.
	 * @return string
	 */
	private static function report_path( $run_id, $report ) {
		$filename = self::report_filename( $run_id, $report );

		if ( ! $filename ) {
			return '';
		}

		$reports = self::reports();
		return $reports['path'] . $filename;
	}

	/**
	 * Authenticated admin URL for downloading a report.
	 *
	 * @param string $report Report key: changes or redirects.
	 * @param string $run_id Run ID, or an empty string for legacy reports.
	 * @return string
	 */
	private static function report_download_url( $report, $run_id = '' ) {
		$run_id = sanitize_key( $run_id );
		$url = add_query_arg(
			array(
				'action' => 'slug_sync_download',
				'report' => $report,
				'run_id' => $run_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'slug_sync_download_' . $report . '_' . ( $run_id ? $run_id : 'legacy' ) );
	}

	/**
	 * Send a CSV report through WordPress instead of linking to uploads directly.
	 */
	public static function download_report() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to download this report.', 'slugsync' ) );
		}

		// Nonce is checked below after the report and run keys are validated.
		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = isset( $_GET['run_id'] ) ? sanitize_key( wp_unslash( $_GET['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$files  = array( 'changes', 'redirects' );

		if ( ! in_array( $report, $files, true ) || ( $run_id && ! self::get_run( $run_id ) ) ) {
			wp_die( esc_html__( 'Invalid report.', 'slugsync' ) );
		}

		check_admin_referer( 'slug_sync_download_' . $report . '_' . ( $run_id ? $run_id : 'legacy' ) );

		$filename = self::report_filename( $run_id, $report );
		$path     = self::report_path( $run_id, $report );

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The report file is not available.', 'slugsync' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Neutralize a spreadsheet formula trigger in a free-text CSV column.
	 *
	 * A post title is author-supplied, and any contributor can save one starting
	 * with =, +, - or @. Excel and Sheets evaluate such a cell as a formula when
	 * the export is opened, so the leading character is quoted off as text.
	 *
	 * Only the title column is treated this way. The slug columns are read back
	 * verbatim by rollback() to decide what to restore, so they have to stay
	 * byte-exact, and sanitize_title() cannot produce a leading trigger anyway.
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	private static function csv_text( $value ) {
		$value = (string) $value;

		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Append and flush one CSV row before any dependent state is changed.
	 *
	 * @param resource $handle Open file handle.
	 * @param array    $row    CSV fields.
	 * @return bool
	 */
	private static function write_csv_row( $handle, $row ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		$written = fputcsv( $handle, $row, ',', '"', '' );

		if ( false === $written ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush
		return fflush( $handle );
	}

	/**
	 * Read a changes or journal CSV into a map keyed by post ID.
	 *
	 * A repeated journal entry supersedes the earlier plan for the same post.
	 *
	 * @param string $file Report path.
	 * @return array<int,array<int,string>>
	 */
	private static function report_rows_by_id( $file ) {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return array();
		}

		$rows = array();
		fgetcsv( $handle, 0, ',', '"', '' ); // Header row.

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			if ( count( $row ) >= 9 && absint( $row[0] ) ) {
				$rows[ absint( $row[0] ) ] = $row;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * Build one durable changes/journal row.
	 *
	 * The parent and machine-readable conflict reason are appended so the first
	 * nine columns remain compatible with reports created before hierarchical
	 * preview claims and detailed risk summaries were added.
	 *
	 * @param object $post     Database row.
	 * @param string $post_type Post type.
	 * @param string $old_slug Old slug.
	 * @param string $new_slug New slug.
	 * @param string $old_url  Old URL.
	 * @param string $new_url  New URL.
	 * @param string $note     Report note.
	 * @param string $conflict Machine-readable conflict or adjustment reason.
	 * @return array<int,mixed>
	 */
	private static function change_row( $post, $post_type, $old_slug, $new_slug, $old_url, $new_url, $note, $conflict = '' ) {
		return array(
			$post->ID,
			$post_type,
			$post->post_status,
			self::csv_text( $post->post_title ),
			$old_slug,
			$new_slug,
			$old_url,
			$new_url,
			$note,
			absint( $post->post_parent ),
			sanitize_key( $conflict ),
		);
	}

	/**
	 * Reduce durable report rows to factual URL-change counts.
	 *
	 * This is recalculated from the committed changes report after every batch.
	 * A request that dies after writing a row but before saving the run option is
	 * therefore counted correctly when Resume reads the report again.
	 *
	 * @param array<int,array<int,mixed>> $rows      Last committed row per post.
	 * @param string                      $post_type Run post type.
	 * @return array<string,int>
	 */
	private static function risk_from_rows( $rows, $post_type ) {
		$risk = array(
			'changes'             => 0,
			'published'           => 0,
			'unpublished'         => 0,
			'url_changes'         => 0,
			'automatic_redirects' => 0,
			'manual_redirects'    => 0,
			'adjusted'            => 0,
		);
		$hierarchical = is_post_type_hierarchical( $post_type );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 9 ) {
				continue;
			}

			$risk['changes']++;

			if ( 'publish' === (string) $row[2] ) {
				$risk['published']++;

				if ( (string) $row[6] !== (string) $row[7] ) {
					$risk['url_changes']++;

					if ( $hierarchical || '' === (string) $row[4] ) {
						$risk['manual_redirects']++;
					} else {
						$risk['automatic_redirects']++;
					}
				}
			} else {
				$risk['unpublished']++;
			}

			if ( ! empty( $row[10] ) ) {
				$risk['adjusted']++;
			}
		}

		return $risk;
	}

	/**
	 * Rebuild the import-ready redirect file from committed change rows.
	 *
	 * This removes duplicates left by an interrupted append and guarantees the
	 * final redirect map contains exactly the rows that have an Undo record.
	 *
	 * @param string $changes_path Changes report path.
	 * @param string $redirect_path Redirect report path.
	 * @return bool
	 */
	private static function rebuild_redirect_report( $changes_path, $redirect_path ) {
		$rows = self::report_rows_by_id( $changes_path );
		// Keep the temporary artifact within the uninstall cleanup glob even if PHP
		// exits between writing and the final replacement.
		$temp = $redirect_path . '.tmp-' . strtolower( wp_generate_password( 8, false, false ) ) . '.csv';
		$handle = fopen( $temp, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return false;
		}

		$ok = true;

		foreach ( $rows as $row ) {
			if ( 'publish' === $row[2] ) {
				$ok = self::write_csv_row(
					$handle,
					array( wp_make_link_relative( $row[6] ), wp_make_link_relative( $row[7] ) )
				);

				if ( ! $ok ) {
					break;
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $ok && self::replace_report_file( $temp, $redirect_path ) ) {
			return true;
		}

		if ( is_file( $temp ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $temp );
		}

		return false;
	}

	/**
	 * Move a completed report over its previous version on every supported OS.
	 *
	 * PHP can replace an existing destination with rename() on POSIX systems,
	 * but not on Windows. The portable fallback first moves the old destination
	 * aside, restores it if the second move fails, and removes it after success.
	 *
	 * @param string    $source      Completed temporary report.
	 * @param string    $destination Final report path.
	 * @param bool|null $can_replace Whether rename() can replace a destination; null detects the OS.
	 * @return bool
	 */
	private static function replace_report_file( $source, $destination, $can_replace = null ) {
		if ( null === $can_replace ) {
			$can_replace = 'Windows' !== PHP_OS_FAMILY;
		}

		if ( ! is_file( $destination ) || $can_replace ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( rename( $source, $destination ) ) {
				return true;
			}
		}

		if ( ! is_file( $source ) || ! is_file( $destination ) ) {
			return false;
		}

		$backup = $destination . '.bak-' . strtolower( wp_generate_password( 8, false, false ) ) . '.csv';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! rename( $destination, $backup ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( rename( $source, $destination ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $backup );
			return true;
		}

		// Best-effort restoration preserves the last complete report on failure.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $backup, $destination );
		return false;
	}

	/* ---------------------------------------------------------- slug logic */

	/**
	 * Trim an over-long slug at a word boundary instead of letting MySQL cut it.
	 *
	 * @param string $slug Candidate slug.
	 * @return string
	 */
	private static function cap_length( $slug ) {
		if ( strlen( $slug ) <= self::MAX_SLUG ) {
			return $slug;
		}

		$cut = substr( $slug, 0, self::MAX_SLUG );
		$pos = strrpos( $cut, '-' );

		if ( false !== $pos && $pos > 20 ) {
			$cut = substr( $cut, 0, $pos );
		}

		// sanitize_title() percent-encodes non-Latin titles, so a title in a script
		// without word separators can pass MAX_SLUG with no dash to cut at. The
		// byte-level cut above then lands inside a %XX sequence and leaves a bare
		// "%" or "%d" tail, which is not a usable slug. Drop that partial escape.
		$cut = preg_replace( '/%[0-9a-f]?$/i', '', $cut );

		return rtrim( $cut, '-' );
	}

	/**
	 * Apply the optional Free transformations to one filtered title source.
	 *
	 * This runs after slug_sync_source_title so the user's final choices apply to
	 * values composed by an add-on too. It changes only the in-memory source used
	 * for this run; neither the post title nor the WooCommerce SKU is written.
	 *
	 * @param string $source        Filtered title source.
	 * @param object $row           Database row with an ID.
	 * @param string $post_type     Selected post type.
	 * @param bool   $transliterate Use Latin characters.
	 * @param bool   $remove_sku    Leave the exact assigned SKU out.
	 * @param bool   $include_sku   Add the exact assigned SKU when absent.
	 * @return array{source:string,notes:array<int,string>}
	 */
	private static function transform_source_title( $source, $row, $post_type, $transliterate, $remove_sku, $include_sku ) {
		$source = (string) $source;
		$notes  = array();

		if ( 'product' === $post_type && is_object( $row ) && ! empty( $row->ID ) && ( $remove_sku || $include_sku ) ) {
			$sku = get_post_meta( (int) $row->ID, '_sku', true );

			if ( $remove_sku ) {
				$without_sku = Slug_Sync_Transforms::remove_exact_sku( $source, $sku );

				if ( $without_sku !== $source ) {
					$source  = $without_sku;
					$notes[] = __( 'assigned SKU removed', 'slugsync' );
				}
			} elseif ( $include_sku ) {
				$with_sku = Slug_Sync_Transforms::add_exact_sku( $source, $sku );

				if ( $with_sku !== $source ) {
					$source  = $with_sku;
					$notes[] = __( 'assigned SKU added', 'slugsync' );
				}
			}
		}

		if ( $transliterate ) {
			$latin = Slug_Sync_Transforms::transliterate( $source );

			if ( $latin !== $source ) {
				$source  = $latin;
				$notes[] = __( 'transliterated to Latin', 'slugsync' );
			}
		}

		return array(
			'source' => $source,
			'notes'  => $notes,
		);
	}

	/**
	 * Human-readable report note for a machine-readable conflict reason.
	 *
	 * @param string $reason Conflict reason returned by claim_result().
	 * @return string
	 */
	private static function conflict_note( $reason ) {
		$notes = array(
			'existing_slug'        => __( 'target already used by existing content, suffixed', 'slugsync' ),
			'preview_claim'        => __( 'target also proposed for another item in this Preview, suffixed', 'slugsync' ),
			'reserved_word'        => __( 'target reserved by WordPress, suffixed', 'slugsync' ),
			'date_archive'         => __( 'target conflicts with a date archive, suffixed', 'slugsync' ),
			'custom_filter'        => __( 'target adjusted by a WordPress or plugin slug rule', 'slugsync' ),
			'wordpress_adjustment' => __( 'target adjusted by WordPress for uniqueness', 'slugsync' ),
		);

		return isset( $notes[ $reason ] ) ? $notes[ $reason ] : __( 'target adjusted to keep the URL unique', 'slugsync' );
	}

	/**
	 * Work out the URL a post would get, without writing anything.
	 *
	 * Apply mode can just re-read get_permalink() after the write. A preview has
	 * to derive the URL from the old one, and only the post's own slug may be
	 * substituted:
	 *
	 * - Drafts and pending posts carry an empty post_name, so there is no segment
	 *   to swap. Their permalink is a query string before and after the run, which
	 *   is what apply mode reports too, so the URL is returned unchanged.
	 * - A child page may repeat its parent's slug (/about/about/). Replacing every
	 *   match rewrites the parent, so only the final segment is substituted.
	 * - Under plain permalinks, or a structure that does not end in the slug, no
	 *   segment matches and the original URL is returned rather than a guess.
	 * - A structure that does not end in "/" (for example /%postname%) produces
	 *   URLs with no trailing slash, so the slash added to delimit the match has
	 *   to come back off again.
	 *
	 * @param string $old_url  Current permalink.
	 * @param string $old_slug Current slug.
	 * @param string $new_slug Proposed slug.
	 * @return string
	 */
	private static function preview_url( $old_url, $old_slug, $new_slug ) {
		if ( '' === $old_slug ) {
			return $old_url;
		}

		// Matching a whole segment needs a delimiter on both sides, but the result
		// must keep the site's own convention. Without this, on a structure with no
		// trailing slash every previewed URL -- and every target in the preview's
		// redirect CSV -- gains a slash the applied URL will not have.
		$had_slash = '/' === substr( $old_url, -1 );

		$url    = trailingslashit( $old_url );
		$needle = '/' . $old_slug . '/';
		$at     = strrpos( $url, $needle );

		if ( false === $at ) {
			return $old_url;
		}

		$url = substr_replace( $url, '/' . $new_slug . '/', $at, strlen( $needle ) );

		return $had_slash ? $url : untrailingslashit( $url );
	}

	/**
	 * Resolve the slug a post should end up with.
	 *
	 * A preview writes nothing, so wp_unique_post_slug() cannot see slugs occupied
	 * or released earlier in the simulated run. The preview implementation below
	 * mirrors WordPress's namespaces and reserved-slug checks against a virtual
	 * view of those earlier transitions.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $post_id   Post ID.
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent ID.
	 * @param bool   $simulate  True while previewing.
	 * @param string $run_id    Run ID used to isolate preview claims.
	 * @param string $old_slug  Current database slug.
	 * @return string
	 */
	private static function claim( $slug, $post_id, $status, $post_type, $parent, $simulate, $run_id, $old_slug = '' ) {
		$result = self::claim_result( $slug, $post_id, $status, $post_type, $parent, $simulate, $run_id, $old_slug );

		return $result['slug'];
	}

	/**
	 * Resolve a slug together with the reason WordPress had to adjust it.
	 *
	 * Apply still delegates uniqueness to WordPress itself. Preview returns a
	 * more exact reason because its virtual catalogue can distinguish a slug in
	 * the database from one claimed earlier in this same proposed run.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $post_id   Post ID.
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent ID.
	 * @param bool   $simulate  True while previewing.
	 * @param string $run_id    Preview run ID.
	 * @param string $old_slug  Current database slug.
	 * @return array{slug:string,reason:string}
	 */
	private static function claim_result( $slug, $post_id, $status, $post_type, $parent, $simulate, $run_id, $old_slug = '' ) {
		if ( ! $simulate ) {
			$unique = wp_unique_post_slug( $slug, $post_id, $status, $post_type, $parent );

			return array(
				'slug'   => $unique,
				'reason' => $unique === $slug ? '' : 'wordpress_adjustment',
			);
		}

		$result = self::preview_unique_post_slug_result( $slug, $post_id, $status, $post_type, $parent, $run_id );
		self::remember_claim( $run_id, $post_id, $old_slug, $result['slug'], $status, $post_type, $parent );

		return $result;
	}

	/**
	 * Whether a slug is occupied in the database as it would look after all
	 * earlier previewed transitions had been applied.
	 *
	 * @param string $slug      Candidate slug.
	 * @param int    $post_id   Post ID being checked.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent post ID.
	 * @param string $run_id    Preview run ID.
	 * @return string Empty when available; otherwise a machine-readable reason.
	 */
	private static function virtual_slug_collision( $slug, $post_id, $post_type, $parent, $run_id ) {
		global $wpdb;

		self::load_claims( $run_id );

		$key       = sanitize_key( $run_id );
		$post_id   = absint( $post_id );
		$namespace = self::claim_namespace( $post_type, $parent );
		$state     = self::$claims[ $key ];
		$occupied  = isset( $state['occupied'][ $namespace ][ $slug ] ) ? $state['occupied'][ $namespace ][ $slug ] : array();

		foreach ( array_keys( $occupied ) as $claimed_id ) {
			if ( (int) $claimed_id !== $post_id ) {
				return 'preview_claim';
			}
		}

		if ( 'attachment' === $post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID != %d",
					$slug,
					$post_id
				)
			);
		} elseif ( is_post_type_hierarchical( $post_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ( %s, 'attachment' ) AND ID != %d AND post_parent = %d",
					$slug,
					$post_type,
					$post_id,
					absint( $parent )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d",
					$slug,
					$post_type,
					$post_id
				)
			);
		}

		foreach ( (array) $ids as $found_id ) {
			$found_id = absint( $found_id );

			if ( isset( $state['posts'][ $found_id ] ) ) {
				// The row still has its old slug in the real database. In the virtual
				// catalogue it only collides when its simulated new slug is this one.
				if ( $state['posts'][ $found_id ]['new_slug'] === $slug ) {
					return 'preview_claim';
				}
				continue;
			}

			return 'existing_slug';
		}

		return '';
	}

	/**
	 * Boolean compatibility wrapper used while testing numbered alternatives.
	 *
	 * @param string $slug      Candidate slug.
	 * @param int    $post_id   Post ID being checked.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent post ID.
	 * @param string $run_id    Preview run ID.
	 * @return bool
	 */
	private static function virtual_slug_is_taken( $slug, $post_id, $post_type, $parent, $run_id ) {
		return '' !== self::virtual_slug_collision( $slug, $post_id, $post_type, $parent, $run_id );
	}

	/**
	 * Preview equivalent of wp_unique_post_slug().
	 *
	 * This follows the WordPress 5.6+ algorithm and its public filters, replacing
	 * only the database collision lookup with virtual_slug_is_taken().
	 *
	 * @param string $slug        Desired slug.
	 * @param int    $post_id     Post ID.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Parent post ID.
	 * @param string $run_id      Preview run ID.
	 * @return string
	 */
	private static function preview_unique_post_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $run_id ) {
		$result = self::preview_unique_post_slug_result( $slug, $post_id, $post_status, $post_type, $post_parent, $run_id );

		return $result['slug'];
	}

	/**
	 * Preview equivalent of wp_unique_post_slug(), including adjustment reason.
	 *
	 * @param string $slug        Desired slug.
	 * @param int    $post_id     Post ID.
	 * @param string $post_status Post status.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Parent post ID.
	 * @param string $run_id      Preview run ID.
	 * @return array{slug:string,reason:string}
	 */
	private static function preview_unique_post_slug_result( $slug, $post_id, $post_status, $post_type, $post_parent, $run_id ) {
		global $wp_rewrite;

		if ( in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ), true )
			|| ( 'inherit' === $post_status && 'revision' === $post_type )
			|| 'user_request' === $post_type
		) {
			return array( 'slug' => $slug, 'reason' => '' );
		}

		/** This filter is documented in wp-includes/post.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors the WordPress core uniqueness filter.
		$override_slug = apply_filters( 'pre_wp_unique_post_slug', null, $slug, $post_id, $post_status, $post_type, $post_parent );

		if ( null !== $override_slug ) {
			$override_slug = (string) $override_slug;

			return array(
				'slug'   => $override_slug,
				'reason' => $override_slug === $slug ? '' : 'custom_filter',
			);
		}

		$original_slug = $slug;
		$feeds         = isset( $wp_rewrite->feeds ) && is_array( $wp_rewrite->feeds ) ? $wp_rewrite->feeds : array();
		$needs_suffix  = false;
		$reason        = '';

		if ( 'attachment' === $post_type ) {
			/** This filter is documented in wp-includes/post.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors the WordPress core uniqueness filter.
			$is_bad_slug = apply_filters( 'wp_unique_post_slug_is_bad_attachment_slug', false, $slug );
			$collision = self::virtual_slug_collision( $slug, $post_id, $post_type, $post_parent, $run_id );

			if ( $collision ) {
				$reason = $collision;
			} elseif ( in_array( $slug, $feeds, true ) || 'embed' === $slug ) {
				$reason = 'reserved_word';
			} elseif ( $is_bad_slug ) {
				$reason = 'custom_filter';
			}

			$needs_suffix = '' !== $reason;
		} elseif ( is_post_type_hierarchical( $post_type ) ) {
			if ( 'nav_menu_item' === $post_type ) {
				return array( 'slug' => $slug, 'reason' => '' );
			}

			/** This filter is documented in wp-includes/post.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors the WordPress core uniqueness filter.
			$is_bad_slug = apply_filters( 'wp_unique_post_slug_is_bad_hierarchical_slug', false, $slug, $post_type, $post_parent );
			$pagination  = isset( $wp_rewrite->pagination_base ) ? $wp_rewrite->pagination_base : 'page';
			$collision   = self::virtual_slug_collision( $slug, $post_id, $post_type, $post_parent, $run_id );

			if ( $collision ) {
				$reason = $collision;
			} elseif ( in_array( $slug, $feeds, true ) || 'embed' === $slug || preg_match( '@^(' . preg_quote( $pagination, '@' ) . ')?\d+$@', $slug ) ) {
				$reason = 'reserved_word';
			} elseif ( $is_bad_slug ) {
				$reason = 'custom_filter';
			}

			$needs_suffix = '' !== $reason;
		} else {
			$post                        = get_post( $post_id );
			$conflicts_with_date_archive = false;

			if ( 'post' === $post_type && ( ! $post || $post->post_name !== $slug ) && preg_match( '/^[0-9]+$/', $slug ) ) {
				$slug_num = (int) $slug;

				if ( $slug_num ) {
					$permastructs   = array_values( array_filter( explode( '/', get_option( 'permalink_structure' ) ) ) );
					$postname_index = array_search( '%postname%', $permastructs, true );

					if ( 0 === $postname_index
						|| ( $postname_index && '%year%' === $permastructs[ $postname_index - 1 ] && 13 > $slug_num )
						|| ( $postname_index && '%monthnum%' === $permastructs[ $postname_index - 1 ] && 32 > $slug_num )
					) {
						$conflicts_with_date_archive = true;
					}
				}
			}

			/** This filter is documented in wp-includes/post.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors the WordPress core uniqueness filter.
			$is_bad_slug = apply_filters( 'wp_unique_post_slug_is_bad_flat_slug', false, $slug, $post_type );
			$collision   = self::virtual_slug_collision( $slug, $post_id, $post_type, $post_parent, $run_id );

			if ( $collision ) {
				$reason = $collision;
			} elseif ( in_array( $slug, $feeds, true ) || 'embed' === $slug ) {
				$reason = 'reserved_word';
			} elseif ( $conflicts_with_date_archive ) {
				$reason = 'date_archive';
			} elseif ( $is_bad_slug ) {
				$reason = 'custom_filter';
			}

			$needs_suffix = '' !== $reason;
		}

		if ( $needs_suffix ) {
			$suffix = 2;

			do {
				$alt_slug = _truncate_post_slug( $slug, 200 - ( strlen( $suffix ) + 1 ) ) . '-' . $suffix;
				$suffix++;
			} while ( self::virtual_slug_is_taken( $alt_slug, $post_id, $post_type, $post_parent, $run_id ) );

			$slug = $alt_slug;
		}

		/** This filter is documented in wp-includes/post.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Mirrors the WordPress core uniqueness filter.
		$filtered = (string) apply_filters( 'wp_unique_post_slug', $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug );

		if ( $filtered !== $slug && '' === $reason ) {
			$reason = 'custom_filter';
		}

		if ( $filtered === $original_slug ) {
			$reason = '';
		}

		return array(
			'slug'   => $filtered,
			'reason' => $reason,
		);
	}

	/**
	 * Yoast's indexable table name, or an empty string when Yoast is absent.
	 *
	 * Resolved once per request. The lookup previously ran for every post written,
	 * adding a SHOW TABLES query per row on top of the slug write itself.
	 *
	 * @return string
	 */
	private static function yoast_indexable_table() {
		global $wpdb;

		static $table = null;

		if ( null === $table ) {
			$indexable = $wpdb->prefix . 'yoast_indexable';

			// Underscores are LIKE wildcards, and the prefix is full of them.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $indexable ) ) );
			$table = ( $found === $indexable ) ? $indexable : '';
		}

		return $table;
	}

	/**
	 * Write a slug.
	 *
	 * Quiet mode goes straight to the posts table, skipping save_post. On a large
	 * store that avoids firing WooCommerce webhooks and integration syncs once per
	 * product, and leaves post_modified alone so the whole catalogue does not get
	 * a fresh sitemap lastmod on the same day. Yoast's indexable row is dropped so
	 * it regenerates rather than serving a stale canonical.
	 *
	 * Quiet mode also records the previous slug in _wp_old_slug by hand. Core
	 * normally does this in wp_check_for_changed_slugs() on post_updated, which a
	 * direct write never fires, and without it WordPress loses its built-in 301
	 * from the old URL to the new one.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $new_slug  New slug.
	 * @param bool   $quiet     Skip hooks.
	 * @param string $old_slug  Previous slug, for the old-slug record.
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 * @return true|string True, or an error message.
	 */
	private static function write_slug( $post_id, $new_slug, $quiet, $old_slug = '', $status = '', $post_type = '' ) {
		global $wpdb;

		if ( ! $quiet ) {
			$result = wp_update_post(
				array(
					'ID'        => $post_id,
					'post_name' => $new_slug,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result->get_error_message();
			}

			clean_post_cache( $post_id );
			$saved_slug = (string) get_post_field( 'post_name', $post_id );

			/** This action is documented below on the quiet-write path. */
			do_action( 'slug_sync_slug_updated', $post_id, $saved_slug ? $saved_slug : $new_slug );

			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$written = $wpdb->update(
			$wpdb->posts,
			array( 'post_name' => $new_slug ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $written ) {
			return $wpdb->last_error ? $wpdb->last_error : __( 'Database write failed.', 'slugsync' );
		}

		clean_post_cache( $post_id );

		// Mirror wp_check_for_changed_slugs(): only published, non-hierarchical
		// posts get an old-slug record, because that is all core will redirect.
		if ( '' !== $old_slug && 'publish' === $status && $post_type && ! is_post_type_hierarchical( $post_type ) ) {
			$old_slugs = (array) get_post_meta( $post_id, '_wp_old_slug' );

			if ( ! in_array( $old_slug, $old_slugs, true ) ) {
				add_post_meta( $post_id, '_wp_old_slug', $old_slug );
			}

			// If the incoming slug was itself retired earlier, it is current again.
			if ( in_array( $new_slug, $old_slugs, true ) ) {
				delete_post_meta( $post_id, '_wp_old_slug', $new_slug );
			}
		}

		$indexable = self::yoast_indexable_table();

		if ( $indexable ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$indexable,
				array(
					'object_id'   => $post_id,
					'object_type' => 'post',
				),
				array( '%d', '%s' )
			);
		}

		/**
		 * Fires after a slug has been rewritten.
		 *
		 * @param int    $post_id  Post ID.
		 * @param string $new_slug The new slug.
		 */
		do_action( 'slug_sync_slug_updated', $post_id, $new_slug );

		return true;
	}

	/* ------------------------------------------------------------ screens */

	/**
	 * Load the plugin's local assets only on its own Tools screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'tools_page_' . self::PAGE, 'admin_page_' . self::PAGE ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'slug-sync-admin',
			plugins_url( 'assets/admin.css', __FILE__ ),
			array(),
			SLUG_SYNC_VERSION
		);

		wp_enqueue_script(
			'slug-sync-admin',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array(),
			SLUG_SYNC_VERSION,
			true
		);

		$hierarchical = array();

		foreach ( array_keys( self::post_types() ) as $type_name ) {
			$hierarchical[ $type_name ] = is_post_type_hierarchical( $type_name );
		}

		wp_localize_script(
			'slug-sync-admin',
			'SlugSyncAdmin',
			array(
				'text'         => array(
					'preview_button' => __( 'Create preview', 'slugsync' ),
					'apply_button'   => __( 'Apply slug changes', 'slugsync' ),
					'preview_write'  => __( 'This choice has no effect during a preview because nothing is saved.', 'slugsync' ),
					'apply_write'    => __( 'This choice controls how each slug is saved during Apply.', 'slugsync' ),
					'confirm_apply'  => __( 'Apply will begin changing slugs immediately. Have you reviewed a preview and taken a database backup?', 'slugsync' ),
				),
				'hierarchical' => $hierarchical,
				'productType'  => 'product',
			)
		);
	}

	/**
	 * Active plugin basenames from the current site and network.
	 *
	 * @return array<int,string>
	 */
	private static function active_plugin_files() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );

			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$files = array();

		foreach ( $active as $file ) {
			if ( is_string( $file ) && '' !== trim( $file ) ) {
				$files[] = strtolower( ltrim( trim( $file ), '/' ) );
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Labels for known plugin directories present in an active-plugin list.
	 *
	 * Directory-prefix matching survives ordinary plugin file renames without
	 * loading another plugin's classes or relying on its implementation details.
	 *
	 * @param array<string,string> $definitions Directory to display-label map.
	 * @param array<int,string>    $active      Active plugin basenames.
	 * @return array<int,string>
	 */
	private static function detected_plugins( $definitions, $active ) {
		$found = array();

		foreach ( $definitions as $directory => $label ) {
			$prefix = strtolower( trim( (string) $directory, '/' ) ) . '/';

			foreach ( $active as $file ) {
				if ( 0 === strpos( $file, $prefix ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Read-only compatibility findings for common URL-related integrations.
	 *
	 * Presence is evidence for a warning, not a compatibility claim. Custom code,
	 * server-level caches and Cloudflare zones configured without a WordPress
	 * plugin are deliberately outside this local check.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function environment_preflight() {
		$active  = self::active_plugin_files();
		$notices = array();

		$overrides = self::detected_plugins(
			array(
				'permalink-manager'     => __( 'Permalink Manager', 'slugsync' ),
				'permalink-manager-pro' => __( 'Permalink Manager Pro', 'slugsync' ),
				'custom-permalinks'     => __( 'Custom Permalinks', 'slugsync' ),
			),
			$active
		);

		if ( $overrides ) {
			$notices[] = array(
				'code'  => 'public_url_override',
				'level' => 'warning',
				'title' => __( 'Another plugin may control the public URL', 'slugsync' ),
				/* translators: %s: comma-separated plugin names. */
				'body'  => sprintf( __( '%s is active. It can override the native WordPress permalink, so changing post_name may not change the address visitors see. Preview first and verify several resulting URLs before Apply.', 'slugsync' ), implode( ', ', $overrides ) ),
			);
		}

		$languages = self::detected_plugins(
			array(
				'sitepress-multilingual-cms' => __( 'WPML', 'slugsync' ),
				'polylang'                    => __( 'Polylang', 'slugsync' ),
				'polylang-pro'                => __( 'Polylang Pro', 'slugsync' ),
			),
			$active
		);

		if ( $languages ) {
			$notices[] = array(
				'code'  => 'multilingual',
				'level' => 'warning',
				'title' => __( 'Translated URLs need separate review', 'slugsync' ),
				/* translators: %s: comma-separated plugin names. */
				'body'  => sprintf( __( '%s is active. SlugSync processes matching WordPress posts but does not coordinate translation relationships or language URL structures. Review every language represented in the changes report.', 'slugsync' ), implode( ', ', $languages ) ),
			);
		}

		$redirection = self::detected_plugins( array( 'redirection' => __( 'Redirection', 'slugsync' ) ), $active );

		if ( $redirection ) {
			$notices[] = array(
				'code'         => 'redirection',
				'level'        => 'info',
				'title'        => __( 'Redirection is ready for the redirect report', 'slugsync' ),
				'body'         => __( 'After Apply, download SlugSync\'s two-column redirect CSV and import it into Redirection. SlugSync does not write Redirection\'s tables or settings.', 'slugsync' ),
				'action_url'   => admin_url( 'tools.php?page=redirection.php&sub=import' ),
				'action_label' => __( 'Open Redirection import', 'slugsync' ),
			);
		}

		$other_redirects = self::detected_plugins(
			array(
				'seo-by-rank-math'     => __( 'Rank Math SEO', 'slugsync' ),
				'wordpress-seo-premium' => __( 'Yoast SEO Premium', 'slugsync' ),
			),
			$active
		);

		if ( $other_redirects ) {
			$notices[] = array(
				'code'  => 'other_redirect_manager',
				'level' => 'info',
				'title' => __( 'An SEO redirect tool is active', 'slugsync' ),
				/* translators: %s: comma-separated plugin names. */
				'body'  => sprintf( __( '%s is active. SlugSync creates a portable redirect CSV but does not assume that its redirect module is enabled or write into it automatically.', 'slugsync' ), implode( ', ', $other_redirects ) ),
			);
		}

		$caches = self::detected_plugins(
			array(
				'cloudflare'      => __( 'Cloudflare', 'slugsync' ),
				'wp-rocket'       => __( 'WP Rocket', 'slugsync' ),
				'w3-total-cache'  => __( 'W3 Total Cache', 'slugsync' ),
				'wp-super-cache'  => __( 'WP Super Cache', 'slugsync' ),
				'litespeed-cache' => __( 'LiteSpeed Cache', 'slugsync' ),
				'sg-cachepress'   => __( 'Speed Optimizer', 'slugsync' ),
				'breeze'          => __( 'Breeze', 'slugsync' ),
				'flying-press'    => __( 'FlyingPress', 'slugsync' ),
			),
			$active
		);

		if ( $caches ) {
			$notices[] = array(
				'code'  => 'cache',
				'level' => 'info',
				'title' => __( 'Plan a cache purge after Apply', 'slugsync' ),
				/* translators: %s: comma-separated plugin names. */
				'body'  => sprintf( __( '%s is active. Purge its page/CDN cache after the run finishes, then test old and new URLs. This check cannot see server-level caches or a Cloudflare zone configured without a WordPress plugin.', 'slugsync' ), implode( ', ', $caches ) ),
			);
		}

		return $notices;
	}

	/**
	 * Render the local, read-only compatibility preflight.
	 */
	private static function render_environment_preflight() {
		$notices = self::environment_preflight();
		?>
		<section class="slug-sync-preflight" aria-labelledby="slug-sync-preflight-heading">
			<div class="slug-sync-preflight-head">
				<div>
					<span class="slug-sync-eyebrow"><?php esc_html_e( 'Read-only check', 'slugsync' ); ?></span>
					<h2 id="slug-sync-preflight-heading"><?php esc_html_e( 'Compatibility preflight', 'slugsync' ); ?></h2>
				</div>
				<span class="slug-sync-preflight-count">
					<?php
					printf(
						/* translators: %d: number of compatibility notices. */
						esc_html( _n( '%d notice', '%d notices', count( $notices ), 'slugsync' ) ),
						absint( count( $notices ) )
					);
					?>
				</span>
			</div>
			<p><?php esc_html_e( 'SlugSync checked active plugins that commonly affect public URLs, translations, redirects or caching. Nothing was changed.', 'slugsync' ); ?></p>

			<?php if ( $notices ) : ?>
				<ul class="slug-sync-preflight-list">
					<?php foreach ( $notices as $notice ) : ?>
						<li class="slug-sync-card<?php echo 'warning' === $notice['level'] ? ' slug-sync-preflight-warning' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed class names. ?>">
							<strong><?php echo esc_html( $notice['title'] ); ?></strong>
							<span><?php echo esc_html( $notice['body'] ); ?></span>
							<?php if ( ! empty( $notice['action_url'] ) && ! empty( $notice['action_label'] ) ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( $notice['action_url'] ); ?>"><?php echo esc_html( $notice['action_label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="slug-sync-preflight-clear"><strong><?php esc_html_e( 'No known integration warning was found.', 'slugsync' ); ?></strong> <?php esc_html_e( 'Custom code and server/CDN configuration still require your own review.', 'slugsync' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Normalized risk counts stored with a run.
	 *
	 * @param array<string,mixed> $run Run record.
	 * @return array<string,int>
	 */
	private static function run_risk( $run ) {
		$risk = array(
			'changes'             => isset( $run['changed'] ) ? absint( $run['changed'] ) : 0,
			'published'           => 0,
			'unpublished'         => 0,
			'url_changes'         => 0,
			'automatic_redirects' => 0,
			'manual_redirects'    => 0,
			'adjusted'            => 0,
		);

		if ( ! empty( $run['risk'] ) && is_array( $run['risk'] ) ) {
			foreach ( array_keys( $risk ) as $key ) {
				if ( isset( $run['risk'][ $key ] ) ) {
					$risk[ $key ] = absint( $run['risk'][ $key ] );
				}
			}
		}

		return $risk;
	}

	/**
	 * Render factual completed-Preview counts without inventing an SEO score.
	 *
	 * @param array<string,mixed> $run Completed run record.
	 */
	private static function render_preview_risk( $run ) {
		if ( ! isset( $run['mode'], $run['status'] ) || 'dry' !== $run['mode'] || 'completed' !== $run['status'] ) {
			return;
		}

		if ( empty( $run['risk'] ) || ! is_array( $run['risk'] ) ) {
			return;
		}

		$risk   = self::run_risk( $run );
		$errors = isset( $run['errors'] ) ? absint( $run['errors'] ) : 0;
		$same_public_url = max( 0, $risk['published'] - $risk['url_changes'] );
		?>
		<section class="slug-sync-risk" aria-labelledby="slug-sync-risk-heading">
			<span class="slug-sync-eyebrow"><?php esc_html_e( 'Preview result', 'slugsync' ); ?></span>
			<h2 id="slug-sync-risk-heading"><?php esc_html_e( 'URL change summary', 'slugsync' ); ?></h2>
			<p><?php esc_html_e( 'These are measured results from this Preview, not a guessed SEO score.', 'slugsync' ); ?></p>
			<div class="slug-sync-risk-grid">
				<div><strong><?php echo esc_html( number_format_i18n( $risk['changes'] ) ); ?></strong><span><?php esc_html_e( 'proposed changes', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $risk['published'] ) ); ?></strong><span><?php esc_html_e( 'published items', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $risk['url_changes'] ) ); ?></strong><span><?php esc_html_e( 'public URL changes', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $risk['automatic_redirects'] ) ); ?></strong><span><?php esc_html_e( 'covered by WordPress old-slug redirects', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $risk['manual_redirects'] ) ); ?></strong><span><?php esc_html_e( 'need redirect import', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $risk['adjusted'] ) ); ?></strong><span><?php esc_html_e( 'adjusted for conflicts or slug rules', 'slugsync' ); ?></span></div>
				<div><strong><?php echo esc_html( number_format_i18n( $errors ) ); ?></strong><span><?php esc_html_e( 'run errors', 'slugsync' ); ?></span></div>
			</div>

			<?php if ( $same_public_url ) : ?>
				<p class="slug-sync-risk-note">
					<?php
					printf(
						/* translators: %d: number of published slug changes whose public URL is unchanged. */
						esc_html( _n( '%d published slug change keeps the same previewed public URL under the current permalink behavior.', '%d published slug changes keep the same previewed public URL under the current permalink behavior.', $same_public_url, 'slugsync' ) ),
						absint( $same_public_url )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $risk['unpublished'] ) : ?>
				<p class="slug-sync-risk-note">
					<?php
					printf(
						/* translators: %d: number of unpublished items in the preview. */
						esc_html( _n( '%d proposed change is for an unpublished item and has no public redirect row.', '%d proposed changes are for unpublished items and have no public redirect rows.', $risk['unpublished'], 'slugsync' ) ),
						absint( $risk['unpublished'] )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $risk['manual_redirects'] ) : ?>
				<p class="slug-sync-risk-warning"><strong><?php esc_html_e( 'Redirect action required:', 'slugsync' ); ?></strong> <?php esc_html_e( 'Import the redirect report after Apply because WordPress does not redirect old hierarchical URLs on its own.', 'slugsync' ); ?></p>
			<?php endif; ?>

			<?php if ( $risk['adjusted'] ) : ?>
				<p class="slug-sync-risk-warning"><strong><?php esc_html_e( 'Manual review required:', 'slugsync' ); ?></strong> <?php esc_html_e( 'Check the note and conflict_reason columns for every adjusted target before Apply.', 'slugsync' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Route the Tools screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slugsync' ) );
		}

		echo '<div class="wrap slug-sync-admin">';

		printf(
			'<h1 class="slug-sync-brand"><img src="%s" alt="%s" width="400" height="130"></h1>',
			esc_url( plugins_url( 'assets/logo.png', __FILE__ ) ),
			esc_attr__( 'SlugSync', 'slugsync' )
		);
		$ran_batch = false;

		if ( isset( $_POST['slug_sync_cancel'] ) ) {
			check_admin_referer( 'slug_sync' );
			self::cancel_run();
		} elseif ( isset( $_POST['slug_sync_rollback'] ) ) {
			check_admin_referer( 'slug_sync' );
			self::rollback();
		} elseif ( isset( $_POST['slug_sync_run'] ) ) {
			check_admin_referer( 'slug_sync' );
			self::run();
			$ran_batch = true;
		}

		if ( ! $ran_batch ) {
			self::form();
		}

		self::run_history();
		self::legacy_reports();

		echo '</div>';
	}

	/**
	 * Status label for a stored run.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'running'     => __( 'In progress', 'slugsync' ),
			'paused'      => __( 'Paused', 'slugsync' ),
			'completed'   => __( 'Completed', 'slugsync' ),
			'canceled'    => __( 'Stopped', 'slugsync' ),
			'rolled_back' => __( 'Undone', 'slugsync' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'slugsync' );
	}

	/**
	 * Human-readable summary of the slug-building choices stored with a run.
	 *
	 * @param array<string,mixed> $run Run record.
	 * @return string
	 */
	private static function transformation_label( $run ) {
		$labels = array();

		if ( ! empty( $run['addon_rules'] ) ) {
			$labels[] = __( 'add-on rules', 'slugsync' );
		}

		if ( ! empty( $run['transliterate'] ) ) {
			$labels[] = __( 'Latin transliteration', 'slugsync' );
		}

		if ( ! empty( $run['remove_sku'] ) ) {
			$labels[] = __( 'exact SKU removal', 'slugsync' );
		}

		if ( ! empty( $run['include_sku'] ) ) {
			$labels[] = __( 'assigned SKU included', 'slugsync' );
		}

		return $labels ? implode( ' · ', $labels ) : __( 'Title only', 'slugsync' );
	}

	/**
	 * Render a progress bar for a run.
	 *
	 * @param int  $done    Items scanned so far.
	 * @param int  $total   Items in the run.
	 * @param bool $working Whether a further batch is still queued.
	 */
	private static function progress_bar( $done, $total, $working = false ) {
		$done    = max( 0, (int) $done );
		$total   = max( 0, (int) $total );
		$percent = $total > 0 ? (int) floor( ( $done / $total ) * 100 ) : 0;
		$percent = min( 100, max( 0, $percent ) );

		$fill_class = 'slug-sync-progress-fill' . ( $working ? ' is-working' : '' );
		?>
		<div class="slug-sync-progress">
			<div class="slug-sync-progress-track" role="progressbar" aria-valuenow="<?php echo esc_attr( $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Run progress', 'slugsync' ); ?>">
				<div class="<?php echo esc_attr( $fill_class ); ?>" style="width:<?php echo esc_attr( $percent ); ?>%"></div>
			</div>
			<div class="slug-sync-progress-meta" aria-live="polite">
				<strong><?php echo esc_html( $percent ); ?>%</strong>
				<span>
					<?php
					printf(
						/* translators: 1: items scanned, 2: total items. */
						esc_html__( '%1$s of %2$s scanned', 'slugsync' ),
						esc_html( number_format_i18n( $done ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Reduce one report row to the small shape retained for the live findings UI.
	 *
	 * @param array<int,mixed> $row Changes-report row.
	 * @return array<string,mixed>|null
	 */
	private static function finding_from_change_row( $row ) {
		if ( ! is_array( $row ) || count( $row ) < 9 ) {
			return null;
		}

		return array(
			'id'    => absint( $row[0] ),
			'title' => (string) $row[3],
			'old'   => (string) $row[4],
			'new'   => (string) $row[5],
			'note'  => (string) $row[8],
		);
	}

	/**
	 * Stable identity for one displayed slug transition.
	 *
	 * @param array<string,mixed> $finding Finding record.
	 * @return string
	 */
	private static function finding_key( $finding ) {
		return absint( isset( $finding['id'] ) ? $finding['id'] : 0 ) . '|' .
			md5( (string) ( isset( $finding['old'] ) ? $finding['old'] : '' ) . "\0" . (string) ( isset( $finding['new'] ) ? $finding['new'] : '' ) );
	}

	/**
	 * Append one batch to the bounded, deduplicated live findings list.
	 *
	 * The CSV is the complete result. Keeping only a small display window avoids
	 * growing the runs option or repainting thousands of rows on every refresh.
	 *
	 * @param mixed $stored Previously retained findings.
	 * @param mixed $batch  Findings from the current batch.
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_recent_findings( $stored, $batch ) {
		$merged = array();

		foreach ( array_merge( is_array( $stored ) ? $stored : array(), is_array( $batch ) ? $batch : array() ) as $finding ) {
			if ( ! is_array( $finding ) || empty( $finding['id'] ) || ! isset( $finding['old'], $finding['new'] ) ) {
				continue;
			}

			$finding = array(
				'id'    => absint( $finding['id'] ),
				'title' => isset( $finding['title'] ) ? (string) $finding['title'] : '',
				'old'   => (string) $finding['old'],
				'new'   => (string) $finding['new'],
				'note'  => isset( $finding['note'] ) ? (string) $finding['note'] : '',
			);
			$key     = self::finding_key( $finding );

			// Reinsert a repeated transition at the end so it reads as recent.
			unset( $merged[ $key ] );
			$merged[ $key ] = $finding;
		}

		return array_slice( array_values( $merged ), -self::MAX_RECENT_FINDINGS );
	}

	/**
	 * Render the accumulated live findings and visibly mark this batch.
	 *
	 * @param array<string,mixed>              $run            Updated run record.
	 * @param array<int,array<string,mixed>>   $batch_findings Findings from this response.
	 * @param array<int,string>                $messages       Batch errors or recovery notes.
	 * @param int                              $limit          Newest matches to show, or 0 for all kept.
	 */
	private static function render_findings( $run, $batch_findings, $messages, $limit = 0 ) {
		$recent = self::merge_recent_findings( isset( $run['recent_findings'] ) ? $run['recent_findings'] : array(), array() );

		if ( $limit > 0 ) {
			$recent = array_slice( $recent, -$limit );
		}
		$new     = array();

		foreach ( self::merge_recent_findings( array(), $batch_findings ) as $finding ) {
			$new[ self::finding_key( $finding ) ] = true;
		}

		$found = isset( $run['changed'] ) ? absint( $run['changed'] ) : 0;
		?>
		<div class="slug-sync-findings">
			<div class="slug-sync-findings-head">
				<h3><?php esc_html_e( 'Items found', 'slugsync' ); ?></h3>
				<span class="slug-sync-findings-count">
					<?php
					printf(
						/* translators: %s: number of matching items found so far. */
						esc_html__( '%s found so far', 'slugsync' ),
						esc_html( number_format_i18n( $found ) )
					);
					?>
				</span>
			</div>

			<?php if ( $recent ) : ?>
				<p class="slug-sync-findings-note">
					<?php
						printf(
							/* translators: 1: recent items shown on screen, 2: total items found. */
							esc_html__( 'Showing the %1$d most recent matches. The changes report contains all %2$d.', 'slugsync' ),
							absint( count( $recent ) ),
							absint( $found )
						);
					?>
				</p>
				<ol class="slug-sync-findings-list">
					<?php foreach ( array_reverse( $recent ) as $finding ) : ?>
						<?php $is_new = isset( $new[ self::finding_key( $finding ) ] ); ?>
						<li class="slug-sync-finding<?php echo $is_new ? ' is-new' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>">
							<span class="slug-sync-finding-title">#<?php echo absint( $finding['id'] ); ?> <?php echo esc_html( $finding['title'] ); ?></span>
							<span class="slug-sync-finding-route">
								<code><?php echo esc_html( $finding['old'] ); ?></code>
								<span class="slug-sync-finding-arrow" aria-hidden="true">→</span>
								<code><?php echo esc_html( $finding['new'] ); ?></code>
								<?php if ( $finding['note'] ) : ?><span class="description"><?php echo esc_html( $finding['note'] ); ?></span><?php endif; ?>
							</span>
							<?php if ( $is_new ) : ?><span class="slug-sync-finding-new"><?php esc_html_e( 'New this batch', 'slugsync' ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="slug-sync-findings-note"><?php esc_html_e( 'No matching slug changes have been found yet.', 'slugsync' ); ?></p>
			<?php endif; ?>

			<?php if ( $messages ) : ?>
				<div class="slug-sync-batch-messages">
					<strong><?php esc_html_e( 'Batch messages', 'slugsync' ); ?></strong>
					<ul>
						<?php foreach ( array_slice( $messages, 0, 10 ) as $message ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resume and cancel controls for an unfinished run.
	 *
	 * @param array<string,mixed> $run  Run record.
	 * @param bool                $auto Submit the resume form automatically.
	 */
	private static function run_controls( $run, $auto = false ) {
		$run_id  = sanitize_key( $run['id'] );
		$form_id = $auto ? ' id="slug-sync-next"' : '';
		?>
		<div class="slug-sync-controls">
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>"<?php echo $form_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>>
				<?php wp_nonce_field( 'slug_sync' ); ?>
				<input type="hidden" name="slug_sync_run" value="1">
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>">
				<button class="button button-primary"><?php echo esc_html( $auto ? __( 'Continue', 'slugsync' ) : __( 'Resume run', 'slugsync' ) ); ?></button>
			</form>
			<form class="slug-sync-stop-form slug-sync-confirm-form" method="post" action="<?php echo esc_url( self::page_url() ); ?>" data-confirm="<?php echo esc_attr__( 'Stop this run? Work already completed will not be reversed, and the partial reports will be kept.', 'slugsync' ); ?>">
				<?php wp_nonce_field( 'slug_sync' ); ?>
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>">
				<button class="button" name="slug_sync_cancel" value="1"><?php esc_html_e( 'Stop run', 'slugsync' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Display stored runs and their reports/actions.
	 */
	private static function run_history() {
		$runs = array_reverse( array_values( self::runs() ) );

		if ( ! $runs ) {
			return;
		}

		// This read-only display choice does not change site state.
		$show_all = isset( $_GET['slug_sync_history'] ) && 'all' === sanitize_key( wp_unslash( $_GET['slug_sync_history'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$visible  = $show_all ? $runs : array_slice( $runs, 0, 20 );
		$types    = self::post_types();

		echo '<section class="slug-sync-history-panel">';
		echo '<h2>' . esc_html__( 'Previous runs', 'slugsync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each row is one preview or apply run. Reports can be downloaded at any time; only applied changes can be undone.', 'slugsync' ) . '</p>';
		echo '<div class="slug-sync-table-wrap"><table class="widefat striped slug-sync-history"><thead><tr>';
		echo '<th>' . esc_html__( 'Started', 'slugsync' ) . '</th>';
		echo '<th>' . esc_html__( 'What ran', 'slugsync' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'slugsync' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'slugsync' ) . '</th>';
		echo '<th>' . esc_html__( 'Reports', 'slugsync' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'slugsync' ) . '</th>';
		echo '</tr></thead><tbody>';

		$latest_rendered = false;

		foreach ( $visible as $run ) {
			if ( empty( $run['id'] ) ) {
				continue;
			}

			$is_latest       = ! $latest_rendered;
			$latest_rendered = true;
			$run_id          = sanitize_key( $run['id'] );
			$post_type       = isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '';
			$type_label      = isset( $types[ $post_type ] ) ? $types[ $post_type ]->labels->singular_name : $post_type;
			$created_at      = isset( $run['created_at'] ) ? (int) $run['created_at'] : 0;
			$status          = isset( $run['status'] ) ? sanitize_key( $run['status'] ) : '';
			$done            = isset( $run['done'] ) ? (int) $run['done'] : 0;
			$total           = isset( $run['total'] ) ? (int) $run['total'] : 0;
			$changed         = isset( $run['changed'] ) ? (int) $run['changed'] : 0;
			$errors          = isset( $run['errors'] ) ? (int) $run['errors'] : 0;
			$changes         = self::report_path( $run_id, 'changes' );
			$redirects       = self::report_path( $run_id, 'redirects' );
			$is_active       = ! self::run_is_finished( $run );
			$is_apply        = isset( $run['mode'] ) && 'apply' === $run['mode'];
			$write_label     = isset( $run['write'] ) && 'hooks' === $run['write'] ? __( 'standard WordPress update', 'slugsync' ) : __( 'quiet update', 'slugsync' );
			$transform_label = self::transformation_label( $run );
			?>
			<tr<?php echo $is_latest ? ' class="slug-sync-latest-run"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>>
				<td>
					<?php if ( $is_latest ) : ?>
						<span class="slug-sync-latest-badge"><?php esc_html_e( 'Latest run', 'slugsync' ); ?></span><br>
					<?php endif; ?>
					<?php echo esc_html( $created_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_at ) : '—' ); ?>
				</td>
				<td>
					<strong><?php echo esc_html( $type_label ); ?></strong><br>
					<span class="description"><?php echo esc_html( $is_apply ? __( 'Apply', 'slugsync' ) . ' / ' . $write_label : __( 'Preview', 'slugsync' ) ); ?></span><br>
					<span class="description"><?php echo esc_html( $transform_label ); ?></span>
					<details class="slug-sync-technical"><summary><?php esc_html_e( 'Technical ID', 'slugsync' ); ?></summary><code><?php echo esc_html( $run_id ); ?></code></details>
				</td>
				<td><strong><?php echo esc_html( self::status_label( $status ) ); ?></strong></td>
				<td>
					<?php
					printf(
						/* translators: 1: scanned posts, 2: total posts. */
						esc_html__( '%1$d / %2$d scanned', 'slugsync' ),
						absint( $done ),
						absint( $total )
					);
					?><br>
					<span class="description">
						<?php
						printf(
							/* translators: 1: changed posts, 2: errors. */
							esc_html__( '%1$d changes, %2$d errors', 'slugsync' ),
							absint( $changed ),
							absint( $errors )
						);
						?>
					</span>
				</td>
				<td>
					<div class="slug-sync-report-actions">
					<?php if ( is_file( $changes ) ) : ?>
						<a class="button button-small slug-sync-report-button slug-sync-report-button--changes" href="<?php echo esc_url( self::report_download_url( 'changes', $run_id ) ); ?>"><?php esc_html_e( 'Download changes', 'slugsync' ); ?></a>
					<?php endif; ?>
					<?php if ( is_file( $redirects ) ) : ?>
						<a class="button button-small slug-sync-report-button slug-sync-report-button--redirects" href="<?php echo esc_url( self::report_download_url( 'redirects', $run_id ) ); ?>"><?php esc_html_e( 'Download redirects', 'slugsync' ); ?></a>
					<?php endif; ?>
					</div>
				</td>
				<td>
					<?php if ( $is_active ) : ?>
						<?php self::run_controls( $run ); ?>
					<?php elseif ( $is_apply && $changed > 0 && 'rolled_back' !== $status && is_file( $changes ) ) : ?>
						<form class="slug-sync-confirm-form" method="post" action="<?php echo esc_url( self::page_url() ); ?>" data-confirm="<?php echo esc_attr__( 'Undo the slug changes from this run? Items edited since the run will be left unchanged.', 'slugsync' ); ?>">
							<?php wp_nonce_field( 'slug_sync' ); ?>
							<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>">
							<button class="button button-small" name="slug_sync_rollback" value="1"><?php esc_html_e( 'Undo changes', 'slugsync' ); ?></button>
						</form>
					<?php else : ?>
						<span aria-hidden="true">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		echo '</tbody></table></div>';
		echo '<p class="description"><strong>' . esc_html__( 'Changes report:', 'slugsync' ) . '</strong> ' .
			esc_html__( 'shows each old and proposed/new URL and supports Undo.', 'slugsync' ) . ' <strong>' .
			esc_html__( 'Redirect report:', 'slugsync' ) . '</strong> ' .
			esc_html__( 'contains source and destination pairs for published items, ready for a redirect tool.', 'slugsync' ) . '</p>';

		if ( count( $runs ) > 20 ) {
			if ( $show_all ) {
				printf(
					'<p><a class="button" href="%s">%s</a></p>',
					esc_url( self::page_url() ),
					esc_html__( 'Show the latest 20 runs', 'slugsync' )
				);
			} else {
				printf(
					'<p><a class="button" href="%s">%s</a></p>',
					esc_url( add_query_arg( 'slug_sync_history', 'all', self::page_url() ) ),
					esc_html__( 'Show all run history', 'slugsync' )
				);
			}
		}

		echo '</section>';
	}

	/**
	 * Download and rollback controls for reports created before run history.
	 */
	private static function legacy_reports() {
		$changes   = self::report_path( '', 'changes' );
		$redirects = self::report_path( '', 'redirects' );

		if ( ! is_file( $changes ) ) {
			return;
		}

		echo '<hr><h2>' . esc_html__( 'Reports from an earlier version', 'slugsync' ) . '</h2><p>';

		printf(
			'<a class="button slug-sync-report-button slug-sync-report-button--changes" href="%s">%s</a> ',
			esc_url( self::report_download_url( 'changes' ) ),
			esc_html__( 'Download changes', 'slugsync' )
		);

		if ( is_file( $redirects ) ) {
			printf(
				'<a class="button slug-sync-report-button slug-sync-report-button--redirects" href="%s">%s</a>',
				esc_url( self::report_download_url( 'redirects' ) ),
				esc_html__( 'Download redirects', 'slugsync' )
			);
		}

		echo '</p><p class="description">' .
			esc_html__( 'These files were created before the Previous runs screen was introduced.', 'slugsync' ) .
			'</p>';

		?>
		<form class="slug-sync-confirm-form" method="post" action="<?php echo esc_url( self::page_url() ); ?>" data-confirm="<?php echo esc_attr__( 'Undo the slug changes recorded in this earlier report?', 'slugsync' ); ?>">
			<?php wp_nonce_field( 'slug_sync' ); ?>
			<p><button class="button" name="slug_sync_rollback" value="1"><?php esc_html_e( 'Undo changes from this report', 'slugsync' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * The settings form.
	 */
	private static function form() {
		$active = self::active_run();

		if ( $active ) {
			$done      = isset( $active['done'] ) ? (int) $active['done'] : 0;
			$total     = isset( $active['total'] ) ? (int) $active['total'] : 0;
			$post_type = isset( $active['post_type'] ) ? sanitize_key( $active['post_type'] ) : '';
			$types     = self::post_types();
			$type_name = isset( $types[ $post_type ] ) ? $types[ $post_type ]->labels->name : $post_type;
			$is_apply  = isset( $active['mode'] ) && 'apply' === $active['mode'];
			?>
			<div class="slug-sync-active">
				<h2><?php esc_html_e( 'Finish your current run', 'slugsync' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: run type, 2: content type, 3: scanned count, 4: total count. */
						esc_html__( 'An unfinished %1$s for %2$s has scanned %3$d of %4$d items. Resume continues from the last completed batch; it does not start over.', 'slugsync' ),
						$is_apply ? esc_html__( 'Apply run', 'slugsync' ) : esc_html__( 'Preview', 'slugsync' ),
						esc_html( $type_name ),
						absint( $done ),
						absint( $total )
					);
					?>
				</p>
				<?php self::progress_bar( $done, $total ); ?>
				<p class="description"><strong><?php esc_html_e( 'Slug building:', 'slugsync' ); ?></strong> <?php echo esc_html( self::transformation_label( $active ) ); ?></p>
				<?php if ( $is_apply ) : ?>
					<p class="description"><?php esc_html_e( 'Stopping keeps changes already made and preserves the partial reports. It does not undo completed batches.', 'slugsync' ); ?></p>
				<?php endif; ?>
				<?php self::run_controls( $active ); ?>
			</div>
			<?php
			return;
		}

		$types              = self::post_types();
		$default            = self::default_type();
		$batch_size         = self::batch_size();
		$returned_preview   = self::returned_preview();
		$use_transliterate  = false;
		$sku_mode           = '';
		$write_mode         = '';
		$include_drafts     = false;
		$include_suffixed   = false;

		if ( $returned_preview ) {
			$preview_type = isset( $returned_preview['post_type'] ) ? sanitize_key( $returned_preview['post_type'] ) : '';

			if ( isset( $types[ $preview_type ] ) ) {
				$default = $preview_type;
			}

			$use_transliterate = ! empty( $returned_preview['transliterate'] );

			if ( 'product' === $default ) {
				if ( ! empty( $returned_preview['include_sku'] ) ) {
					$sku_mode = 'include';
				} elseif ( ! empty( $returned_preview['remove_sku'] ) ) {
					$sku_mode = 'remove';
				} else {
					$sku_mode = 'keep';
				}
			}

			$write_mode = isset( $returned_preview['write'] ) && 'hooks' === $returned_preview['write'] ? 'hooks' : 'quiet';

			$include_drafts    = ! empty( $returned_preview['drafts'] );
			$include_suffixed  = ! empty( $returned_preview['suffixed'] );
		}

		$selected_type = $returned_preview ? $default : '';
		?>
		<?php if ( self::has_finished_run() ) : ?>
			<?php
			/*
			 * Somebody who has finished a run does not need the three steps
			 * explained again, so the space carries the Pro card instead. On
			 * the screen a finished preview returns to, that card is the
			 * contextual one; it is not repeated further down.
			 */
			self::pro_card( $returned_preview ? $returned_preview : array() );
			?>
		<?php else : ?>
			<div class="slug-sync-intro">
				<p><strong><?php esc_html_e( 'Build clean URL slugs from content titles—safely and in batches.', 'slugsync' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'A slug is the last part of a URL. For example, “Blue Cotton Shirt” normally becomes “blue-cotton-shirt”. SlugSync can also transliterate the title, leave its assigned WooCommerce SKU out, or add that SKU from product data.', 'slugsync' ); ?>
				</p>
				<div class="slug-sync-steps">
					<div class="slug-sync-step"><strong><?php esc_html_e( '1. Preview', 'slugsync' ); ?></strong><?php esc_html_e( 'See every proposed old and new URL without saving changes.', 'slugsync' ); ?></div>
					<div class="slug-sync-step"><strong><?php esc_html_e( '2. Review', 'slugsync' ); ?></strong><?php esc_html_e( 'Download the changes report and check duplicate-title notes.', 'slugsync' ); ?></div>
					<div class="slug-sync-step"><strong><?php esc_html_e( '3. Apply', 'slugsync' ); ?></strong><?php esc_html_e( 'Run again with Apply selected when the preview looks correct.', 'slugsync' ); ?></div>
				</div>
			</div>
		<?php endif; ?>

		<?php self::render_environment_preflight(); ?>

		<?php
		if ( $returned_preview ) {
			self::render_preview_risk( $returned_preview );
			?>
			<div class="slug-sync-preview-ready" role="status">
				<strong><?php esc_html_e( 'Your preview choices are restored below.', 'slugsync' ); ?></strong>
				<span><?php esc_html_e( 'Keep them selected so Apply produces the URLs you just reviewed.', 'slugsync' ); ?></span>
			</div>
			<?php
		}
		?>

		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" id="slug-sync-start-form">
			<?php wp_nonce_field( 'slug_sync' ); ?>

			<details class="slug-sync-workflow-step" data-slug-sync-step="1" open>
				<summary id="slug-sync-content-heading"><span class="slug-sync-number">1</span><span class="slug-sync-workflow-title"><?php esc_html_e( 'What should I tidy up?', 'slugsync' ); ?></span></summary>
				<div class="slug-sync-card slug-sync-workflow-body" role="region" aria-labelledby="slug-sync-content-heading">
				<p><?php esc_html_e( 'Only the selected content type is processed. Attachments and product variations are never included.', 'slugsync' ); ?></p>
				<label for="slug-sync-post-type"><strong><?php esc_html_e( 'Content type', 'slugsync' ); ?></strong></label><br>
				<select name="post_type" id="slug-sync-post-type" class="slug-sync-select" required>
					<option value="" <?php selected( '', $selected_type ); ?> disabled><?php esc_html_e( 'Choose content', 'slugsync' ); ?></option>
					<?php foreach ( $types as $name => $object ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $name, $selected_type ); ?>>
							<?php echo esc_html( $object->labels->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div class="slug-sync-hierarchy-note" id="slug-sync-hierarchy-note" <?php echo $selected_type && is_post_type_hierarchical( $selected_type ) ? '' : 'hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>>
					<strong><?php esc_html_e( 'This content type nests inside a parent, so plan redirects yourself.', 'slugsync' ); ?></strong>
					<p><?php esc_html_e( 'WordPress only redirects an old URL by itself for content that does not nest, such as posts and products. It does not do so for pages, whatever tool changes the slug, so import the redirect report into a redirect plugin after applying.', 'slugsync' ); ?></p>
					<p><?php esc_html_e( 'A nested URL also contains its parents\' slugs. Renaming a parent therefore changes the URL of everything beneath it, and those child URLs are not in the reports, which list only items whose own slug changed. Check what sits under anything you rename.', 'slugsync' ); ?></p>
				</div>
				</div>
			</details>

			<details class="slug-sync-workflow-step" data-slug-sync-step="2">
				<summary id="slug-sync-rules-heading"><span class="slug-sync-number">2</span><span class="slug-sync-workflow-title"><?php esc_html_e( 'How should the new slugs be built?', 'slugsync' ); ?></span></summary>
				<div class="slug-sync-card slug-sync-workflow-body" role="region" aria-labelledby="slug-sync-rules-heading">
				<p><?php esc_html_e( 'These options change only the proposed URL slug. Product titles, SKUs and other stored content stay exactly as they are.', 'slugsync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="checkbox" name="transliterate" value="1" <?php checked( $use_transliterate ); ?>>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Use readable Latin characters', 'slugsync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Included in Free', 'slugsync' ); ?></span></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Creates readable Latin slugs from Cyrillic and Greek titles, and gives the same result on every host. Greek follows the ELOT 743 standard, so Ναύπλιο becomes nafplio. PHP international text support adds further scripts where the server provides it.', 'slugsync' ); ?></span>
							<span class="slug-sync-transform-example"><code>Кофеварка</code><span aria-hidden="true">→</span><code>kofevarka</code></span>
						</span>
					</label>
					<fieldset class="slug-sync-sku-options<?php echo 'product' === $selected_type ? '' : ' is-disabled'; ?>" id="slug-sync-sku-options" aria-disabled="<?php echo 'product' === $selected_type ? 'false' : 'true'; ?>"<?php echo 'product' === $selected_type ? '' : ' disabled'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attributes. ?>>
						<legend><strong><?php esc_html_e( 'Assigned SKU in product slugs', 'slugsync' ); ?></strong><span class="slug-sync-badge"><?php esc_html_e( 'Products only', 'slugsync' ); ?></span></legend>
						<span class="slug-sync-choice-help"><?php esc_html_e( 'Choose one treatment for the exact SKU saved in WooCommerce product data.', 'slugsync' ); ?></span>
						<div class="slug-sync-choices">
							<label class="slug-sync-choice">
								<input type="radio" name="sku_mode" value="keep" required <?php checked( 'keep', $sku_mode ); ?>>
								<span>
									<strong class="slug-sync-choice-title"><?php esc_html_e( 'Use the product title as it is', 'slugsync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Recommended', 'slugsync' ); ?></span></strong>
									<span class="slug-sync-choice-help"><?php esc_html_e( 'The SKU affects the URL only when it is already part of the product title.', 'slugsync' ); ?></span>
								</span>
							</label>
							<label class="slug-sync-choice">
								<input type="radio" name="sku_mode" value="remove" <?php checked( 'remove', $sku_mode ); ?>>
								<span>
									<strong class="slug-sync-choice-title"><?php esc_html_e( 'Leave the assigned SKU out', 'slugsync' ); ?></strong>
									<span class="slug-sync-choice-help"><?php esc_html_e( 'When the exact SKU appears in the title, it is left out of the URL. Other model numbers and codes remain, and an unclear name is left alone.', 'slugsync' ); ?></span>
									<span class="slug-sync-transform-example"><code>Blue Shirt · SKU BCS-500</code><span aria-hidden="true">→</span><code>blue-shirt</code></span>
								</span>
							</label>
							<label class="slug-sync-choice">
								<input type="radio" name="sku_mode" value="include" <?php checked( 'include', $sku_mode ); ?>>
								<span>
									<strong class="slug-sync-choice-title"><?php esc_html_e( 'Add the assigned SKU', 'slugsync' ); ?></strong>
									<span class="slug-sync-choice-help"><?php esc_html_e( 'Fills the built-in {sku} placeholder from Product data → Inventory → SKU and places it after the title. A code already present is not added twice.', 'slugsync' ); ?></span>
									<span class="slug-sync-transform-example"><code>Blue Shirt · {sku}</code><span aria-hidden="true">→</span><code>blue-shirt-bcs-500</code></span>
								</span>
							</label>
						</div>
					</fieldset>
					<span class="slug-sync-product-unavailable" id="slug-sync-sku-unavailable" <?php echo 'product' === $selected_type ? 'hidden' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute. ?>><?php esc_html_e( 'Select Products in Step 1 to choose how assigned SKUs affect slugs.', 'slugsync' ); ?></span>
				</div>
				</div>
			</details>

			<details class="slug-sync-workflow-step" data-slug-sync-step="3">
				<summary id="slug-sync-action-heading"><span class="slug-sync-number">3</span><span class="slug-sync-workflow-title"><?php esc_html_e( 'Preview first, or apply now?', 'slugsync' ); ?></span></summary>
				<div class="slug-sync-card slug-sync-workflow-body" role="region" aria-labelledby="slug-sync-action-heading">
				<p><?php esc_html_e( 'Start with a preview. Apply uses the same matching rules but saves the proposed slugs.', 'slugsync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="radio" name="mode" value="dry" required>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Create a preview', 'slugsync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Recommended', 'slugsync' ); ?></span></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Reads the selected content and creates reports. No slugs, URLs, posts, or products are changed.', 'slugsync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="radio" name="mode" value="apply">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Apply the slug changes', 'slugsync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Changes matching slugs in batches and records reports for downloads and undo. Use this after reviewing a preview.', 'slugsync' ); ?></span>
						</span>
					</label>
				</div>
				<div class="slug-sync-apply-note" id="slug-sync-apply-note" hidden>
					<strong><?php esc_html_e( 'Apply will change live URLs.', 'slugsync' ); ?></strong>
					<?php esc_html_e( 'Take a database backup first. Existing indexed URLs are recorded for redirection, and a redirect CSV is created as a second layer.', 'slugsync' ); ?>
				</div>
				</div>
			</details>

			<details class="slug-sync-workflow-step" data-slug-sync-step="4">
			<summary id="slug-sync-run-heading"><span class="slug-sync-number">4</span><span class="slug-sync-workflow-title"><?php esc_html_e( 'How it runs', 'slugsync' ); ?></span><span class="slug-sync-workflow-hint"><?php esc_html_e( 'Sensible defaults already chosen — most people never need this', 'slugsync' ); ?></span></summary>
			<div class="slug-sync-card slug-sync-workflow-body" role="region" aria-labelledby="slug-sync-run-heading">

			<section class="slug-sync-sub" aria-labelledby="slug-sync-write-heading">
				<h2 id="slug-sync-write-heading"><?php esc_html_e( 'How each change is saved', 'slugsync' ); ?></h2>
				<p id="slug-sync-write-help"><?php esc_html_e( 'This choice has no effect during a preview because nothing is saved.', 'slugsync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="radio" name="write" value="quiet" required <?php checked( 'quiet', $write_mode ); ?>>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Quiet update', 'slugsync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Recommended', 'slugsync' ); ?></span></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Best for most sites and large stores. Changes only the URL slug, keeps the existing modified date, and avoids triggering save automations, webhooks, or integration syncs for every item. Redirect protections and reports are still created.', 'slugsync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="radio" name="write" value="hooks">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Standard WordPress update', 'slugsync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Saves every item through WordPress normally. This updates its modified date and runs plugins, webhooks, and automations connected to saving. Choose this only when another integration must react to each slug change; it can be much slower.', 'slugsync' ); ?></span>
						</span>
					</label>
				</div>
			</section>
			</div>
			</details>

			<details class="slug-sync-workflow-step" data-slug-sync-step="5">
				<summary id="slug-sync-scope-heading"><span class="slug-sync-number slug-sync-number--bonus"><?php esc_html_e( 'Bonus', 'slugsync' ); ?></span><span class="slug-sync-workflow-title"><?php esc_html_e( 'What is included', 'slugsync' ); ?></span><span class="slug-sync-workflow-hint"><?php esc_html_e( 'Nothing to pick here — the default scope suits most sites', 'slugsync' ); ?></span></summary>
				<div class="slug-sync-card slug-sync-workflow-body" role="region" aria-labelledby="slug-sync-scope-heading">
				<div class="slug-sync-optional-note">
					<strong><?php esc_html_e( 'Nothing here has to be picked.', 'slugsync' ); ?></strong>
					<span><?php esc_html_e( 'Every option below widens the default scope. Leave them all unchecked and continue straight to the button at the end.', 'slugsync' ); ?></span>
				</div>
				<p><?php esc_html_e( 'By default, only published items whose slug clearly differs from the title are included.', 'slugsync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="checkbox" name="drafts" value="1" <?php checked( $include_drafts ); ?>>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Also include unpublished items', 'slugsync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Includes drafts, pending-review items, and private items. Leave off if you only want to change URLs that visitors can currently access.', 'slugsync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="checkbox" name="suffixed" value="1" <?php checked( $include_suffixed ); ?>>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Recheck numbered slugs', 'slugsync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Includes slugs that already match the title except for a number. These are skipped by default because the number often marks a duplicate title. WordPress may add a number again when it is needed to keep the URL unique.', 'slugsync' ); ?></span>
							<span class="slug-sync-example"><?php esc_html_e( 'Example: blue-cotton-shirt-2', 'slugsync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="checkbox" name="testonly" value="1">
						<span>
							<strong class="slug-sync-choice-title">
								<?php
								/* translators: %d: number of items in one batch. */
								printf( esc_html__( 'Pause after the first %d items', 'slugsync' ), (int) $batch_size );
								?>
								<span class="slug-sync-badge"><?php esc_html_e( 'Useful for first Apply', 'slugsync' ); ?></span>
							</strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Processes one batch, then pauses so you can inspect the partial report. Resume continues with the next item. Stopping does not undo the first batch.', 'slugsync' ); ?></span>
						</span>
					</label>
				</div>
				</div>
			</details>

			<div class="slug-sync-safety" id="slug-sync-safety" hidden>
				<h3><?php esc_html_e( 'Before you apply', 'slugsync' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Take a current database backup.', 'slugsync' ); ?></li>
					<li><?php esc_html_e( 'Run and review a complete preview first.', 'slugsync' ); ?></li>
					<li><?php esc_html_e( 'After Apply finishes, purge page/CDN caches and test several old URLs.', 'slugsync' ); ?></li>
				</ul>
			</div>

			<div class="slug-sync-actions">
				<button class="button button-primary" id="slug-sync-start-button" name="slug_sync_run" value="1"><?php esc_html_e( 'Create preview', 'slugsync' ); ?></button>
				<span class="description"><?php esc_html_e( 'Large sites continue automatically in small batches.', 'slugsync' ); ?></span>
			</div>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------- run */

	/**
	 * Process one batch.
	 */
	private static function run() {
		global $wpdb;

		// Nonce is verified by render() before this method runs.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run    = $run_id ? self::get_run( $run_id ) : self::create_run();

		if ( ! $run ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'A run could not be started. Resume or stop the active run before trying again.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$run_id = sanitize_key( $run['id'] );

		if ( self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run has already finished.', 'slugsync' ) . '</p></div>';
			return;
		}

		$active = self::active_run();

		if ( ! $active ) {
			if ( ! self::add_option_once( self::ACTIVE_OPT, $run_id ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Another run became active. Reload this page and try again.', 'slugsync' ) . '</p></div>';
				return;
			}
		} elseif ( sanitize_key( $active['id'] ) !== $run_id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Another run is active. Finish or stop it first.', 'slugsync' ) . '</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( $run_id );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Another batch is still processing. Wait a moment, then use Resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		// Reload after acquiring the lock so a cancel or completed request cannot be overwritten.
		$run = self::get_run( $run_id );

		if ( ! $run || self::run_is_finished( $run ) ) {
			self::release_lock( $lock_token );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run is no longer available to resume.', 'slugsync' ) . '</p></div>';
			return;
		}

		$last_id       = isset( $run['last_id'] ) ? absint( $run['last_id'] ) : 0;
		$done          = isset( $run['done'] ) ? absint( $run['done'] ) : 0;
		$changed       = isset( $run['changed'] ) ? absint( $run['changed'] ) : 0;
		$errors        = isset( $run['errors'] ) ? absint( $run['errors'] ) : 0;
		$sig_code      = isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0;
		$sig_stopword  = isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0;
		$sig_non_latin = isset( $run['sig_non_latin'] ) ? absint( $run['sig_non_latin'] ) : 0;
		$apply          = isset( $run['mode'] ) && 'apply' === $run['mode'];
		$quiet          = ! isset( $run['write'] ) || 'quiet' === $run['write'];
		$transliterate  = ! empty( $run['transliterate'] );
		$remove_sku     = ! empty( $run['remove_sku'] );
		$include_sku    = ! empty( $run['include_sku'] );
		$drafts         = ! empty( $run['drafts'] );
		$suffixed       = ! empty( $run['suffixed'] );
		$pause          = ! empty( $run['pause_after_batch'] );
		$post_type      = isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '';
		$statuses      = $drafts
			? array( 'publish', 'draft', 'pending', 'private' )
			: array( 'publish' );

		$batch         = self::batch_size();
		$is_first      = ( 0 === $last_id );
		$changes_path  = self::report_path( $run_id, 'changes' );
		$redirect_path = self::report_path( $run_id, 'redirects' );
		$journal_path  = self::report_path( $run_id, 'journal' );

		if ( $is_first ) {
			delete_transient( self::CLAIM_KEY ); // Clean up the older global claim transient.

			if ( ! is_file( $changes_path ) || 0 === (int) filesize( $changes_path ) ) {
				self::reset_claims( $run_id );
			}
		} elseif ( ! is_file( $changes_path ) || ! is_file( $redirect_path ) ) {
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'This run cannot resume because one of its report files is missing. Stop it before starting another run.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		// $statuses is a hardcoded literal array (see above), so $placeholders is only
		// ever a list of "%s" tokens -- no caller input reaches the SQL string. Every
		// value is still passed through prepare(). A dynamic IN() list cannot be
		// expressed in a way the sniffs can follow, hence the annotations below.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total_result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($placeholders)",
				array_merge( array( $post_type ), $statuses )
			)
		);
		$total = (int) $total_result;
		$count_error = $wpdb->last_error;

		// Keyset pagination. OFFSET drifts if a post is added or removed mid-run.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_name, post_status, post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status IN ($placeholders) AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				array_merge( array( $post_type ), $statuses, array( $last_id, $batch ) )
			)
		);
		$rows_error = $wpdb->last_error;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $count_error || $rows_error || ! is_array( $rows ) ) {
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'WordPress could not read the selected content from the database. The run was paused without advancing; fix the database error, then resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		// Those rows came straight from $wpdb, so none of them are in the post
		// cache. get_permalink() and the _wp_old_slug lookups below would each
		// fetch their post one query at a time -- a hundred or more extra queries
		// per batch. Prime posts, terms and meta in three queries instead.
		if ( $rows ) {
			_prime_post_caches( wp_list_pluck( $rows, 'ID' ), true, true );
		}

		// Append even when last_id is zero. A request can die after writing some
		// slugs but before the batch checkpoint is saved; truncating here would
		// erase the only Undo record for those successful writes.
		$changes_handle  = fopen( $changes_path, 'a+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$redirect_handle = fopen( $redirect_path, 'a+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$journal_handle  = fopen( $journal_path, 'a+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $changes_handle || ! $redirect_handle || ! $journal_handle ) {
			if ( $changes_handle ) {
				fclose( $changes_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $redirect_handle ) {
				fclose( $redirect_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $journal_handle ) {
				fclose( $journal_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The report files could not be opened. Check that the uploads directory is writable, then resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$changes_stat = fstat( $changes_handle );
		$journal_stat = fstat( $journal_handle );
		$header       = array( 'id', 'post_type', 'status', 'title', 'old_slug', 'new_slug', 'old_url', 'new_url', 'note', 'post_parent', 'conflict_reason' );
		$headers_ok   = true;

		if ( empty( $changes_stat['size'] ) ) {
			// BOM so spreadsheet software reads accented characters correctly.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$headers_ok = false !== fwrite( $changes_handle, "\xEF\xBB\xBF" ) && self::write_csv_row( $changes_handle, $header );
		}

		if ( $headers_ok && empty( $journal_stat['size'] ) ) {
			$headers_ok = self::write_csv_row( $journal_handle, $header );
		}

		if ( ! $headers_ok ) {
			fclose( $changes_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $redirect_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $journal_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The report headers could not be written. The run was paused without changing anything; check available disk space and uploads permissions, then resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$reported_rows = self::report_rows_by_id( $changes_path );
		$journal_rows  = self::report_rows_by_id( $journal_path );

		if ( ! $apply ) {
			self::restore_claims( $run_id, $reported_rows );
		}

		$log            = array();
		$batch_findings = array();
		$next_last      = $last_id;
		$batch_changed  = 0;
		$batch_errors   = 0;
		$report_failed  = false;

		foreach ( $rows as $row ) {

			$next_last = (int) $row->ID;
			$done++;

			$signals = Slug_Sync_Signals::detect( $row->post_title );

			if ( $signals['code'] ) {
				$sig_code++;
			}
			if ( $signals['stopword'] ) {
				$sig_stopword++;
			}
			if ( $signals['non_latin'] ) {
				$sig_non_latin++;
			}

			$post_id = (int) $row->ID;

			// A committed changes row is the per-item checkpoint. If the request
			// died before the batch option was saved, count it again but never write
			// or report it twice.
			if ( isset( $reported_rows[ $post_id ] ) ) {
				$batch_changed++;
				$finding = self::finding_from_change_row( $reported_rows[ $post_id ] );
				if ( $finding ) {
					$batch_findings[] = $finding;
				}
				continue;
			}

			if ( isset( $journal_rows[ $post_id ] ) ) {
				$planned = $journal_rows[ $post_id ];

				if ( ! $apply ) {
					$parent = isset( $planned[9] ) ? absint( $planned[9] ) : absint( $row->post_parent );
					self::remember_claim( $run_id, $post_id, $planned[4], $planned[5], $planned[2], $planned[1], $parent );
				} elseif ( $row->post_name !== $planned[5] && $row->post_name !== $planned[4] ) {
					if ( ! $quiet ) {
						// A standard WordPress save may legitimately adjust the requested
						// slug through a hook. The write-ahead row proves this request had
						// started the item, so preserve the actual result for Undo.
						$planned[5] = $row->post_name;
						$planned[7] = get_permalink( $post_id );
						$planned[8] = $planned[8] ? $planned[8] . '; ' : '';
						$planned[8] .= __( 'slug adjusted during WordPress save', 'slugsync' );

						if ( empty( $planned[10] ) ) {
							$planned[10] = 'wordpress_adjustment';
						}

						if ( ! self::write_csv_row( $journal_handle, $planned ) ) {
							$report_failed = true;
							break;
						}
					} else {
						/* translators: 1: post ID, 2: current slug. */
						$log[] = sprintf( __( '#%1$d skipped after interruption because its slug is now "%2$s"', 'slugsync' ), $post_id, $row->post_name );
						$batch_errors++;
						continue;
					}
				} elseif ( $row->post_name === $planned[4] ) {
					// The journal was flushed but the database write did not happen.
					// Recalculate below against the current database before retrying it.
					$planned = null;
				}

				if ( $planned ) {
					if ( 'publish' === $planned[2] && ! self::write_csv_row( $redirect_handle, array( wp_make_link_relative( $planned[6] ), wp_make_link_relative( $planned[7] ) ) ) ) {
						$report_failed = true;
						break;
					}

					if ( ! self::write_csv_row( $changes_handle, $planned ) ) {
						$report_failed = true;
						break;
					}

					$reported_rows[ $post_id ] = $planned;
					$batch_changed++;
					$finding = self::finding_from_change_row( $planned );
					if ( $finding ) {
						$batch_findings[] = $finding;
					}
					continue;
				}
			}

			/**
			 * Filters the title a slug is generated from.
			 *
			 * Runs before sanitize_title(), so a filter may return characters in
			 * any script; the result is sanitised and length-capped afterwards
			 * either way. The run's optional Free transformations are applied after
			 * this filter. Returning an empty string makes the run skip the post.
			 *
			 * @since 1.0.0
			 *
			 * @param string $title     Post title as stored.
			 * @param object $row       Row with ID, post_title, post_name, post_status, post_parent.
			 * @param string $post_type Post type being processed.
			 */
			$source = apply_filters( 'slug_sync_source_title', $row->post_title, $row, $post_type );
			$source = is_string( $source ) ? $source : $row->post_title;
			$source = self::transform_source_title( $source, $row, $post_type, $transliterate, $remove_sku, $include_sku );
			$source_notes = $source['notes'];
			$source       = $source['source'];

			$target = self::cap_length( sanitize_title( $source ) );

			if ( '' === $target || $row->post_name === $target ) {
				continue;
			}

			if ( ! $suffixed && preg_match( '/^' . preg_quote( $target, '/' ) . '-\d+$/', $row->post_name ) ) {
				continue;
			}

			$old_slug = $row->post_name;
			$old_url  = get_permalink( $row->ID );

			$claim_result = self::claim_result( $target, $post_id, $row->post_status, $post_type, $row->post_parent, ! $apply, $run_id, $old_slug );
			$new_slug     = $claim_result['slug'];
			$conflict     = $claim_result['reason'];

			if ( $new_slug === $old_slug ) {
				continue;
			}

			if ( $conflict ) {
				$source_notes[] = self::conflict_note( $conflict );
			}

			$note = implode( '; ', $source_notes );

			$new_url    = self::preview_url( $old_url, $old_slug, $new_slug );
			$change_row = self::change_row( $row, $post_type, $old_slug, $new_slug, $old_url, $new_url, $note, $conflict );

			// The private journal is flushed before Apply mutates the database. If
			// the request dies in the following instructions, Resume can determine
			// whether this exact plan succeeded and restore its public report row.
			if ( ! self::write_csv_row( $journal_handle, $change_row ) ) {
				$report_failed = true;
				break;
			}
			$journal_rows[ $post_id ] = $change_row;

			if ( $apply ) {
				$result = self::write_slug( $post_id, $new_slug, $quiet, $old_slug, $row->post_status, $post_type );

				if ( true !== $result ) {
					/* translators: 1: post ID, 2: error message. */
					$log[] = sprintf( __( '#%1$d failed: %2$s', 'slugsync' ), $row->ID, $result );
					$batch_errors++;
					continue;
				}

				$actual_slug = (string) get_post_field( 'post_name', $post_id );

				if ( '' === $actual_slug || $actual_slug === $old_slug ) {
					/* translators: %d: post ID. */
					$log[] = sprintf( __( '#%d failed: WordPress did not retain the requested slug.', 'slugsync' ), $post_id );
					$batch_errors++;
					continue;
				}

				$new_url = get_permalink( $post_id );

				if ( $actual_slug !== $new_slug ) {
					$new_slug = $actual_slug;
					$note = $note ? $note . '; ' : '';
					$note .= __( 'slug adjusted during WordPress save', 'slugsync' );

					if ( ! $conflict ) {
						$conflict = 'wordpress_adjustment';
					}
				}

				$change_row = self::change_row( $row, $post_type, $old_slug, $new_slug, $old_url, $new_url, $note, $conflict );

				if ( ! self::write_csv_row( $journal_handle, $change_row ) ) {
					$report_failed = true;
					break;
				}
				$journal_rows[ $post_id ] = $change_row;
			}

			// Only published posts get a redirect. get_permalink() on a draft
			// returns a query-string preview URL, which is junk in a redirect table.
			if ( 'publish' === $row->post_status && ! self::write_csv_row( $redirect_handle, array( wp_make_link_relative( $old_url ), wp_make_link_relative( $new_url ) ) ) ) {
				$report_failed = true;
				break;
			}

			if ( ! self::write_csv_row( $changes_handle, $change_row ) ) {
				$report_failed = true;
				break;
			}

			$reported_rows[ $post_id ] = $change_row;
			$batch_changed++;

			$finding = self::finding_from_change_row( $change_row );
			if ( $finding ) {
				$batch_findings[] = $finding;
			}
		}

		fclose( $changes_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $redirect_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $journal_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $report_failed ) {
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'A report write failed. The run was paused without advancing its checkpoint; any slug already written is safely recorded in the recovery journal. Check disk space and uploads permissions, then resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$finished = empty( $rows ) || count( $rows ) < $batch;
		$paused   = ! $finished && $pause;

		if ( $finished && ! self::rebuild_redirect_report( $changes_path, $redirect_path ) ) {
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The final redirect report could not be verified. The run was paused without advancing its checkpoint; check disk space and uploads permissions, then resume.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$run['last_id']           = $next_last;
		$run['done']              = $done;
		$run['total']             = $total;
		$run['changed']           = $changed + $batch_changed;
		$run['errors']            = $errors + $batch_errors;
		$run['sig_code']          = $sig_code;
		$run['sig_stopword']      = $sig_stopword;
		$run['sig_non_latin']     = $sig_non_latin;
		$run['risk']              = self::risk_from_rows( $reported_rows, $post_type );
		$run['recent_findings']   = self::merge_recent_findings( isset( $run['recent_findings'] ) ? $run['recent_findings'] : array(), $batch_findings );
		$run['updated_at']        = time();
		$run['pause_after_batch'] = false;

		if ( $finished ) {
			$run['status']       = 'completed';
			$run['completed_at'] = time();
		} elseif ( $paused ) {
			$run['status'] = 'paused';
		} else {
			$run['status'] = 'running';
		}

		if ( ! self::save_run( $run ) ) {
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The batch reports are safe, but WordPress could not save the run checkpoint. The recovery journal was kept; reload the page and resume to reconcile this batch.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		if ( $finished ) {
			self::clear_active_run( $run_id );
			self::reset_claims( $run_id );
		}

		if ( is_file( $journal_path ) ) {
			// The batch checkpoint and public changes report are now durable, so its
			// write-ahead journal is no longer needed.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $journal_path );
		}

		self::release_lock( $lock_token );

		self::run_modal(
			array(
				'run'      => $run,
				'run_id'   => $run_id,
				'apply'    => $apply,
				'quiet'    => $quiet,
				'done'     => $done,
				'total'    => $total,
				'findings' => $batch_findings,
				'log'      => $log,
				'finished' => $finished,
				'paused'   => $paused,
			)
		);

		if ( ! $finished ) {
			return;
		}

		// Left on the page behind the overlay, so closing it lands on the
		// detail rather than on an empty screen.
		if ( ! $apply ) {
			self::render_preview_risk( $run );
		}

		self::upsell_card( $run );
	}

	/**
	 * The run itself, shown over the screen.
	 *
	 * Batches advance by reloading this page, so this is plain server-rendered
	 * markup that reappears with each batch rather than something a script
	 * opens once and keeps. It therefore needs no JavaScript to work: the
	 * backdrop and card are CSS, and every control is a real link or form.
	 *
	 * It exists because the reports used to be reachable only by scrolling to
	 * the foot of a long screen, which nobody did. Putting them in front of
	 * the run is the whole point of the overlay.
	 *
	 * role=dialog without aria-modal is deliberate. aria-modal asserts that
	 * everything behind the dialog is inert, and here it is not: the admin bar
	 * is left uncovered on purpose, as the way out of a run that is going
	 * wrong. Claiming inertness we do not enforce would tell a screen reader
	 * the page behind is unreachable while a keyboard user can still tab
	 * straight into it. Do not add aria-modal without also making the rest of
	 * the page genuinely inert.
	 *
	 * @param array<string,mixed> $args Batch state, as assembled by run().
	 */
	private static function run_modal( $args ) {
		$run       = $args['run'];
		$run_id    = sanitize_key( $args['run_id'] );
		$apply     = ! empty( $args['apply'] );
		$finished  = ! empty( $args['finished'] );
		$paused    = ! empty( $args['paused'] );
		$working   = ! $finished && ! $paused;
		$done      = (int) $args['done'];
		$total     = (int) $args['total'];
		$post_type = isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '';
		$state     = $finished ? 'finished' : ( $paused ? 'paused' : 'running' );
		$back_url  = self::page_url();

		if ( $finished && ! $apply ) {
			// The same handover the old Back button made: the finished preview's
			// choices are restored on the form so Apply repeats them exactly.
			$back_url = add_query_arg( 'slug_sync_preview', $run_id, $back_url );
			$back_url = wp_nonce_url( $back_url, 'slug_sync_preview' );
		}

		if ( $finished ) {
			$heading = $apply ? __( 'Your new URLs are live', 'slugsync' ) : __( 'Your preview is ready', 'slugsync' );
			$lead    = $apply
				? __( 'Every selected item now uses its new slug. Both reports are ready to download.', 'slugsync' )
				: __( 'Nothing has been changed. The two reports below describe exactly what Apply would do.', 'slugsync' );
		} elseif ( $paused ) {
			$heading = __( 'Paused after the first batch', 'slugsync' );
			$lead    = __( 'The partial reports are already written. Resume to carry on, or stop to keep only the completed work.', 'slugsync' );
		} else {
			$heading = $apply ? __( 'Rewriting your URLs', 'slugsync' ) : __( 'Building your new URLs', 'slugsync' );
			$lead    = $apply
				? __( 'Saving each new slug and recording the old URL, so visitors and search engines still arrive.', 'slugsync' )
				: __( 'Reading every title and working out the slug WordPress would give it. Nothing is being saved.', 'slugsync' );
		}
		?>
		<div class="slug-sync-modal" id="slug-sync-run-modal" data-state="<?php echo esc_attr( $state ); ?>" role="dialog" aria-labelledby="slug-sync-modal-title" aria-describedby="slug-sync-modal-lead">
			<div class="slug-sync-modal-backdrop"></div>
			<div class="slug-sync-card slug-sync-modal-card" id="slug-sync-modal-card" tabindex="-1">
				<?php if ( $working ) : ?>
					<span class="slug-sync-modal-badge"><span class="slug-sync-modal-pulse" aria-hidden="true"></span><?php echo esc_html( $apply ? __( 'Apply in progress', 'slugsync' ) : __( 'Preview in progress', 'slugsync' ) ); ?></span>
				<?php else : ?>
					<a class="slug-sync-modal-close" id="slug-sync-modal-close" href="<?php echo esc_url( $back_url ); ?>" aria-label="<?php esc_attr_e( 'Close this run summary', 'slugsync' ); ?>"><span aria-hidden="true">&times;</span></a>
				<?php endif; ?>

				<h2 id="slug-sync-modal-title"><?php echo esc_html( $heading ); ?></h2>
				<p class="slug-sync-modal-lead" id="slug-sync-modal-lead"><?php echo esc_html( $lead ); ?></p>

				<?php self::progress_bar( $done, $total, $working ); ?>

				<p class="slug-sync-modal-meta">
					<span><strong><?php esc_html_e( 'Slug building:', 'slugsync' ); ?></strong> <?php echo esc_html( self::transformation_label( $run ) ); ?></span>
					<?php if ( $apply ) : ?>
						<span><strong><?php esc_html_e( 'Saving method:', 'slugsync' ); ?></strong> <?php echo esc_html( empty( $args['quiet'] ) ? __( 'Standard WordPress update', 'slugsync' ) : __( 'Quiet update', 'slugsync' ) ); ?></span>
					<?php else : ?>
						<span><?php esc_html_e( 'Preview only. No slugs or URLs are being changed.', 'slugsync' ); ?></span>
					<?php endif; ?>
				</p>

				<?php self::render_findings( $run, $args['findings'], $args['log'], self::MODAL_FINDINGS ); ?>

				<?php if ( $finished || $paused ) : ?>
					<?php self::run_modal_downloads( $run_id, $finished, $post_type ); ?>
				<?php else : ?>
					<?php self::run_modal_reading( $done, $run_id ); ?>
				<?php endif; ?>

				<div class="slug-sync-modal-actions">
					<?php if ( $finished ) : ?>
						<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html( $apply ? __( 'Back to SlugSync', 'slugsync' ) : __( 'Back to the setup form', 'slugsync' ) ); ?></a>
						<span class="description"><?php esc_html_e( 'The reports stay downloadable from Previous runs.', 'slugsync' ); ?></span>
					<?php elseif ( $paused ) : ?>
						<?php self::run_controls( $run ); ?>
					<?php else : ?>
						<?php self::run_controls( $run, true ); ?>
						<span class="description"><?php esc_html_e( 'The next batch starts automatically. You can leave this page and resume later.', 'slugsync' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The two report files, named and explained where the run finishes.
	 *
	 * @param string $run_id    Run identifier.
	 * @param bool   $finished  Whether the run completed rather than paused.
	 * @param string $post_type Content type the run covered.
	 */
	private static function run_modal_downloads( $run_id, $finished, $post_type ) {
		$changes   = self::report_path( $run_id, 'changes' );
		$redirects = self::report_path( $run_id, 'redirects' );

		if ( ! is_file( $changes ) && ! is_file( $redirects ) ) {
			return;
		}
		?>
		<div class="slug-sync-modal-downloads">
			<h3><?php echo esc_html( $finished ? __( 'Your two reports', 'slugsync' ) : __( 'The partial reports so far', 'slugsync' ) ); ?></h3>
			<div class="slug-sync-modal-files">
				<?php if ( is_file( $changes ) ) : ?>
					<div class="slug-sync-modal-file">
						<strong><?php esc_html_e( 'Changes report', 'slugsync' ); ?></strong>
						<span><?php esc_html_e( 'Every item with its old URL, its new URL and a note explaining anything unusual. This is the one to review.', 'slugsync' ); ?></span>
						<a class="button slug-sync-report-button slug-sync-report-button--changes" href="<?php echo esc_url( self::report_download_url( 'changes', $run_id ) ); ?>"><?php esc_html_e( 'Download changes CSV', 'slugsync' ); ?></a>
					</div>
				<?php endif; ?>
				<?php if ( is_file( $redirects ) ) : ?>
					<div class="slug-sync-modal-file">
						<strong><?php esc_html_e( 'Redirect map', 'slugsync' ); ?></strong>
						<span><?php esc_html_e( 'Two columns, old URL and new URL, ready to import into Redirection or any other redirect plugin. This is the one to keep.', 'slugsync' ); ?></span>
						<a class="button button-primary slug-sync-report-button slug-sync-report-button--redirects" href="<?php echo esc_url( self::report_download_url( 'redirects', $run_id ) ); ?>"><?php esc_html_e( 'Download redirect CSV', 'slugsync' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $post_type && is_post_type_hierarchical( $post_type ) ) : ?>
				<p class="slug-sync-modal-warning"><?php esc_html_e( 'This content type nests inside a parent, so WordPress will not redirect its old URLs by itself. Import the redirect map into a redirect plugin. Anything sitting beneath an item whose slug changed also has a new URL, and those are not listed in either report.', 'slugsync' ); ?></p>
			<?php endif; ?>
			<p class="slug-sync-modal-note"><?php esc_html_e( 'Both files stay available from Previous runs at the foot of this screen.', 'slugsync' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Something to read while the batches run.
	 *
	 * Rotated by batch so a long run does not repeat the same three cards on
	 * every reload. Pro cards are dropped once Pro is installed; there is no
	 * point selling somebody what they already own.
	 *
	 * @param int $done Items scanned so far, used only to pick the rotation.
	 */
	private static function run_modal_reading( $done, $run_id = '' ) {
		$items = array(
			array( 'kind' => 'free', 'icon' => 'link',   'text' => __( 'Your old URLs are recorded as the run goes, not at the end', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'shield', 'text' => __( 'WordPress redirects old post and product links by itself', 'slugsync' ) ),
			array(
				'kind' => 'free',
				'icon' => 'undo',
				/* translators: %d: number of runs kept in the history. */
				'text' => sprintf( __( 'Any applied run can be undone, for the last %d runs', 'slugsync' ), self::MAX_RUNS ),
			),
			array( 'kind' => 'free', 'icon' => 'clock',  'text' => __( 'Closing this page pauses the run. It never loses it', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'eye',    'text' => __( 'Every change is shown old to new before anything is saved', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'layers', 'text' => __( 'Duplicate URLs are handled for you, so nothing collides', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'off',    'text' => __( 'Quiet update changes the URL without waking connected tools', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'code',   'text' => __( 'Cyrillic and Greek titles can become readable Latin slugs', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'box',    'text' => __( 'A product\'s exact SKU can be left out of its URL, or added to it', 'slugsync' ) ),
			array( 'kind' => 'free', 'icon' => 'lock',   'text' => __( 'Nothing is tracked, and nothing ever leaves your site', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'wrench', 'text' => __( 'Pro removes unassigned codes, filler words and over-long names', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'code',   'text' => __( 'Pro reads Chinese and more, not just Cyrillic and Greek', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'box',    'text' => __( 'Pro builds product URLs from colour, size, material or SKU', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'link',   'text' => __( 'Pro puts assigned categories and tags into product URLs', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'tag',    'text' => __( 'Pro adds categories and tags from words in product names', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'layers', 'text' => __( 'Pro maps name text to attribute values, leaving names alone', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'eye',    'text' => __( 'Pro renames term URLs, with redirects and a seven-day 404 watch', 'slugsync' ) ),
			array( 'kind' => 'pro',  'icon' => 'off',    'text' => __( 'Pro clears WP Rocket, LiteSpeed, W3TC and Cloudflare caches', 'slugsync' ) ),
		);

		if ( has_filter( 'slug_sync_source_title' ) ) {
			$owned = array();

			foreach ( $items as $item ) {
				if ( 'pro' !== $item['kind'] ) {
					$owned[] = $item;
				}
			}

			$items = $owned;
		}

		$count = count( $items );

		if ( ! $count ) {
			return;
		}

		/*
		 * One line per batch, not a carousel inside the batch. A batch page
		 * lives at least as long as the auto-continue delay, so this is the
		 * only way to guarantee each line is readable before it is replaced.
		 * The run ID seeds the start so two runs do not open on the same line.
		 */
		$batch = self::batch_size();
		$cycle = $batch > 0 ? (int) floor( max( 0, $done ) / $batch ) : 0;
		$seed  = $run_id ? abs( crc32( (string) $run_id ) ) : 0;
		$item  = $items[ ( $seed + $cycle ) % $count ];
		?>
		<div class="slug-sync-modal-reading">
			<p class="slug-sync-modal-read slug-sync-modal-read--<?php echo esc_attr( $item['kind'] ); ?>">
				<?php self::feature_icon( $item['icon'], 'slug-sync-modal-read-icon' ); ?>
				<strong><?php echo esc_html( $item['text'] ); ?></strong>
			</p>
		</div>
		<?php
	}

	/**
	 * Contextual note about what a rules add-on would change in this run.
	 *
	 * Rendered on the plugin's own screen after a completed product preview, or
	 * after another content preview found a title Pro could help with. Guideline 11
	 * permits an upsell here; it does not permit an admin notice, and there is
	 * deliberately none. The examples are read-only explanations, not disabled
	 * controls or paid code hidden inside Free.
	 *
	 * @param array $run Completed run record.
	 */
	private static function upsell_card( $run ) {
		if ( ! self::pro_preview_context( $run ) ) {
			return;
		}

		self::pro_card( $run );
	}

	/**
	 * The Pro card itself.
	 *
	 * Contextual when a finished preview is passed, general otherwise: it also
	 * stands in for the introduction once somebody has run this at least once
	 * and no longer needs the three steps explained.
	 *
	 * Guideline 11 permits an upsell on the plugin's own screen; it does not
	 * permit an admin notice, and there is deliberately none. The examples are
	 * read-only explanations, not disabled controls.
	 *
	 * @param array<string,mixed> $run Finished preview, or an empty array.
	 */
	private static function pro_card( $run = array() ) {
		if ( has_filter( 'slug_sync_source_title' ) ) {
			return; // A rules add-on is already installed.
		}

		$context = self::pro_preview_context( $run );
		$lines   = array();

		if ( $context && $context['code'] ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains a product code or SKU.', '%d titles contain a product code or SKU.', $context['code'], 'slugsync' ), $context['code'] );
		}
		if ( $context && $context['stopword'] ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains common filler words.', '%d titles contain common filler words.', $context['stopword'], 'slugsync' ), $context['stopword'] );
		}

		echo '<div class="slug-sync-card">';
		echo '<p class="slug-sync-pro-status"><strong>' . esc_html__( 'Built and in final release checks.', 'slugsync' ) . '</strong> ';
		printf(
			/* translators: %s: Pro price, for example $79.99. */
			esc_html__( '%s once at launch, with no yearly renewal.', 'slugsync' ),
			esc_html( self::PRO_PRICE )
		);
		echo '</p>';
		echo '<span class="slug-sync-eyebrow">' . esc_html__( 'Separate paid add-on', 'slugsync' ) . '</span>';
		echo '<h2>' . esc_html__( 'What SlugSync Pro adds', 'slugsync' ) . '</h2>';

		if ( $lines ) {
			echo '<p><strong>' . esc_html__( 'What this preview noticed', 'slugsync' ) . '</strong></p><ul>';

			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}

			echo '</ul>';
		}

		echo '<p>' . esc_html(
			$context
				? __( 'Free completed this URL preview. The Pro workflows below are the ones Free does not already cover; nothing included in Free is moved or limited.', 'slugsync' )
				: __( 'SlugSync Free is complete on its own and has no limits. The Pro workflows below are the ones Free does not cover; nothing included in Free is moved or limited.', 'slugsync' )
		) . '</p>';
		self::pro_feature_slider();

		/*
		 * The link lands on the site's own Pro section rather than a bare
		 * product page, and it says where it goes: this is the one control on
		 * the screen that leaves WordPress, so it should not be a surprise.
		 * Placed after the workflows, which is what it is a conclusion to.
		 */
		echo '<div class="slug-sync-pro-footer">';
		echo '<a class="button button-primary slug-sync-pro-cta" href="' . esc_url( 'https://slugsync.com/#gap' ) . '" target="_blank" rel="noopener noreferrer">' .
			esc_html__( 'See all Pro features', 'slugsync' ) .
			'</a>';
		echo '<span class="description">' .
			esc_html__( 'Opens slugsync.com, where each workflow is shown in full: the screens it is set up on, and the URLs it produces.', 'slugsync' ) .
			'</span>';
		echo '</div></div>';
	}

	/**
	 * Render one icon copied from slugsync.com's current inline SVG system.
	 *
	 * @param string $name Icon name.
	 */
	private static function feature_icon( $name, $class = 'slug-sync-pro-icon' ) {
		$icons = array(
			'wrench' => '<path d="M13.9 3.7a3.5 3.5 0 00-4.3 4.6l-5.5 5.5a1.7 1.7 0 002.4 2.4l5.5-5.5a3.5 3.5 0 004.6-4.3l-2.4 2.4-2.2-.5-.5-2.2z"/>',
			'code'   => '<path d="M7 6l-4 4 4 4M13 6l4 4-4 4"/>',
			'box'    => '<path d="M3.6 6.6L10 3.6l6.4 3v6.9L10 16.4l-6.4-2.9z"/><path d="M3.6 6.6L10 9.6l6.4-3"/><path d="M10 9.6v6.8"/>',
			'link'   => '<path d="M8.5 11.5a3.5 3.5 0 005 0l2.5-2.5a3.5 3.5 0 00-5-5l-1 1"/><path d="M11.5 8.5a3.5 3.5 0 00-5 0L4 11a3.5 3.5 0 005 5l1-1"/>',
			'tag'    => '<path d="M10.6 3.6H16v5.4l-6.9 6.9-5.4-5.4z"/><circle cx="13.2" cy="6.8" r="1.1"/>',
			'layers' => '<path d="M10 2.5L17.5 7 10 11.5 2.5 7z"/><path d="M2.5 12L10 16.5 17.5 12"/>',
			'off'    => '<circle cx="10" cy="10" r="7"/><path d="M5 15L15 5"/>',
			'eye'    => '<path d="M1.8 10S4.7 4.6 10 4.6 18.2 10 18.2 10 15.3 15.4 10 15.4 1.8 10 1.8 10z"/><circle cx="10" cy="10" r="2.4"/>',
			'undo'   => '<path d="M4 9.5h8a4 4 0 010 8H8"/><path d="M7 6L3.5 9.5 7 13"/>',
			'clock'  => '<circle cx="10" cy="10" r="7"/><path d="M10 6v4.3l2.7 1.6"/>',
			'shield' => '<path d="M10 2.8l5.5 2v4.7c0 3.3-2.2 6.2-5.5 7.2-3.3-1-5.5-3.9-5.5-7.2V4.8z"/>',
			'lock'   => '<rect x="4.2" y="9" width="11.6" height="7.2" rx="1.6"/><path d="M7 9V6.9a3 3 0 016 0V9"/>',
		);

		if ( ! isset( $icons[ $name ] ) ) {
			return;
		}

		echo '<span class="' . esc_attr( $class ) . '" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $icons[ $name ] . '</svg></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, locally defined SVG geometry.
	}

	/**
	 * The Pro capabilities that Free does not already cover.
	 *
	 * slugsync.com lists ten. Two of them -- removing an assigned product
	 * code, and transliterating a title -- are Free features now, and a
	 * slider that sells back what the screen behind it just did reads as
	 * either a mistake or a bait. They are left out until Pro ships.
	 */
	private static function pro_feature_slider() {
		$features = array(
			array(
				'icon'    => 'box',
				'title'   => __( 'Create URLs from product details', 'slugsync' ),
				'body'    => __( 'Choose details such as colour, size, material, weight or SKU, put them in any order and preview the finished address.', 'slugsync' ),
				'example' => '{title} {pa_colour} {pa_size}',
				'result'  => '/product/cotton-shirt-black-large/',
			),
			array(
				'icon'    => 'link',
				'title'   => __( 'Use categories and tags in product URLs', 'slugsync' ),
				'body'    => __( 'Add assigned product categories and tags to the same URL template as the name, attributes, SKU, weight or dimensions.', 'slugsync' ),
				'example' => '{title} {product_cat} {product_tag}',
				'result'  => '/product/wh-1000xm5-headphones-sony-wireless/',
			),
			array(
				'icon'    => 'tag',
				'title'   => __( 'Add categories and tags from product names', 'slugsync' ),
				'body'    => __( 'Use exact word or phrase rules to organise matching products without removing existing categories or tags.', 'slugsync' ),
				'example' => __( 'Sony WH-1000XM5 Headphones', 'slugsync' ),
				'result'  => __( 'Sony tag · Headphones category', 'slugsync' ),
			),
			array(
				'icon'    => 'layers',
				'title'   => __( 'Add attributes and map name text to values', 'slugsync' ),
				'body'    => __( 'Choose the text to find, where to save it and the value to add. Existing values and visible names stay unchanged.', 'slugsync' ),
				'example' => __( 'Sony 55-inch TV', 'slugsync' ),
				'result'  => __( 'Brand: Sony · Category: Televisions', 'slugsync' ),
			),
			array(
				'icon'    => 'layers',
				'title'   => __( 'Rename category, tag and attribute URLs safely', 'slugsync' ),
				'body'    => __( 'Preview and update term addresses in batches with saved progress, downloadable reports and Undo.', 'slugsync' ),
				'example' => '/category/winter-boots/',
				'result'  => '/category/boots/',
			),
			array(
				'icon'    => 'link',
				'title'   => __( 'Redirect direct old category and tag links', 'slugsync' ),
				'body'    => __( 'Remember retired term addresses and redirect direct old links that WordPress reports as missing.', 'slugsync' ),
				'example' => '/category/winter-boots/',
				'result'  => __( 'Permanent redirect', 'slugsync' ),
			),
			array(
				'icon'    => 'off',
				'title'   => __( 'Clear saved pages after term changes', 'slugsync' ),
				'body'    => __( 'Clear supported WordPress page caches and, when connected, only the changed Cloudflare addresses.', 'slugsync' ),
				'example' => __( 'WP Rocket · LiteSpeed · W3TC', 'slugsync' ),
				'result'  => __( 'Cloudflare: changed URLs only', 'slugsync' ),
			),
			array(
				'icon'    => 'eye',
				'title'   => __( 'Watch changed term links for 404 errors', 'slugsync' ),
				'body'    => __( 'For seven days, record a 404 when visitors reach one of up to 1,000 watched paths from the latest term update.', 'slugsync' ),
				'example' => '/category/winter-boots/',
				'result'  => __( 'Seven-day 404 watch', 'slugsync' ),
			),
		);
		$total = count( $features );
		?>
		<div class="slug-sync-pro-slider">
			<ul class="slug-sync-pro-grid" id="slug-sync-pro-slider" role="region" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'SlugSync Pro features', 'slugsync' ); ?>" tabindex="0">
				<?php foreach ( $features as $index => $feature ) : ?>
					<?php
					$feature_position = sprintf(
						/* translators: 1: current feature number, 2: total number of features. */
						__( '%1$d of %2$d', 'slugsync' ),
						$index + 1,
						$total
					);
					?>
					<li role="group" aria-label="<?php echo esc_attr( $feature_position ); ?>">
						<?php self::feature_icon( $feature['icon'] ); ?>
						<strong><?php echo esc_html( $feature['title'] ); ?></strong>
						<p><?php echo esc_html( $feature['body'] ); ?></p>
						<div class="slug-sync-pro-example"><code><?php echo esc_html( $feature['example'] ); ?></code><span class="arrow" aria-hidden="true">→</span><code><?php echo esc_html( $feature['result'] ); ?></code></div>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="slug-sync-pro-slider-controls" aria-label="<?php esc_attr_e( 'Pro feature slider controls', 'slugsync' ); ?>">
				<button class="button slug-sync-pro-slider-button slug-sync-pro-slider-prev" id="slug-sync-pro-prev" type="button" aria-label="<?php esc_attr_e( 'Previous Pro features', 'slugsync' ); ?>" aria-controls="slug-sync-pro-slider" disabled><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 10h13M11.5 5.5L17 10l-5.5 4.5"/></svg></button>
				<span class="slug-sync-pro-slider-count" aria-live="polite"><span id="slug-sync-pro-current">1–4</span> <?php esc_html_e( 'of', 'slugsync' ); ?> <span id="slug-sync-pro-total"><?php echo absint( $total ); ?></span></span>
				<button class="button slug-sync-pro-slider-button" id="slug-sync-pro-next" type="button" aria-label="<?php esc_attr_e( 'Next Pro features', 'slugsync' ); ?>" aria-controls="slug-sync-pro-slider"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 10h13M11.5 5.5L17 10l-5.5 4.5"/></svg></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Decide whether a completed run has a useful, contextual Pro explanation.
	 *
	 * Product previews always qualify because Pro's catalogue organiser and URL
	 * templates solve product problems the title signals cannot detect. A
	 * non-product preview qualifies only when its titles expose a relevant rule.
	 * Kept pure so the commercial surface cannot quietly expand without a test.
	 *
	 * @param array $run Completed run record.
	 * @return array<string,int|bool>|array{}
	 */
	private static function pro_preview_context( $run ) {
		$context = array(
			'preview'  => isset( $run['mode'] ) && 'dry' === $run['mode'],
			'product'  => isset( $run['post_type'] ) && 'product' === $run['post_type'],
			'code'     => isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0,
			'stopword' => isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0,
		);

		if ( ! $context['preview'] || ( ! $context['product'] && ! $context['code'] && ! $context['stopword'] ) ) {
			return array();
		}

		return $context;
	}

	/**
	 * Recover the completed preview named by the result screen's Back link.
	 *
	 * The run ID is carried in the URL rather than stored as global UI state. It
	 * restores the exact content type and slug-building choices for Apply, while
	 * an ordinary visit to the tool still starts from clean defaults.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function returned_preview() {
		if ( ! isset( $_GET['slug_sync_preview'], $_GET['_wpnonce'] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'slug_sync_preview' ) ) {
			return null;
		}

		$run_id = sanitize_key( wp_unslash( $_GET['slug_sync_preview'] ) );
		$run    = self::get_run( $run_id );

		if ( ! $run || ! isset( $run['status'], $run['mode'] ) || 'completed' !== $run['status'] || 'dry' !== $run['mode'] ) {
			return null;
		}

		return $run;
	}

	/**
	 * Commit recoverable journal rows before a crashed run is canceled.
	 *
	 * @param array<string,mixed> $run Run record, updated with the recovered count.
	 * @return bool
	 */
	private static function reconcile_journal( &$run ) {
		$run_id       = sanitize_key( $run['id'] );
		$journal_path = self::report_path( $run_id, 'journal' );

		if ( ! is_file( $journal_path ) ) {
			return true;
		}

		$journal = self::report_rows_by_id( $journal_path );

		if ( ! $journal ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $journal_path );
			return true;
		}

		$changes_path  = self::report_path( $run_id, 'changes' );
		$redirect_path = self::report_path( $run_id, 'redirects' );
		$reported      = self::report_rows_by_id( $changes_path );
		$changes       = fopen( $changes_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$redirects     = fopen( $redirect_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $changes || ! $redirects ) {
			if ( $changes ) {
				fclose( $changes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $redirects ) {
				fclose( $redirects ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return false;
		}

		$ok       = true;
		$is_apply = isset( $run['mode'] ) && 'apply' === $run['mode'];
		$quiet    = ! isset( $run['write'] ) || 'quiet' === $run['write'];

		foreach ( $journal as $post_id => $row ) {
			if ( isset( $reported[ $post_id ] ) ) {
				continue;
			}

			if ( $is_apply ) {
				$current_post = get_post( $post_id );

				if ( ! $current_post || (string) $current_post->post_name === $row[4] ) {
					continue; // The planned write never happened.
				}

				if ( (string) $current_post->post_name !== $row[5] ) {
					if ( $quiet ) {
						continue; // A later edit, not SlugSync's direct write.
					}

					$row[5] = (string) $current_post->post_name;
					$row[7] = get_permalink( $post_id );
					$row[8] = $row[8] ? $row[8] . '; ' : '';
					$row[8] .= __( 'slug adjusted during WordPress save', 'slugsync' );

					if ( empty( $row[10] ) ) {
						$row[10] = 'wordpress_adjustment';
					}
				}
			}

			if ( 'publish' === $row[2] ) {
				$ok = self::write_csv_row( $redirects, array( wp_make_link_relative( $row[6] ), wp_make_link_relative( $row[7] ) ) );
			}

			if ( $ok ) {
				$ok = self::write_csv_row( $changes, $row );
			}

			if ( ! $ok ) {
				break;
			}

			$reported[ $post_id ] = $row;
		}

		fclose( $changes );   // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $redirects ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $ok || ! self::rebuild_redirect_report( $changes_path, $redirect_path ) ) {
			return false;
		}

		$committed      = self::report_rows_by_id( $changes_path );
		$run['changed'] = max( isset( $run['changed'] ) ? absint( $run['changed'] ) : 0, count( $committed ) );
		$run['risk']    = self::risk_from_rows( $committed, isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $journal_path );

		return true;
	}

	/**
	 * Cancel an unfinished run while retaining its partial reports.
	 */
	private static function cancel_run() {
		// Nonce is verified by render() before this method runs.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run    = self::get_run( $run_id );

		if ( ! $run || self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run is not available to stop.', 'slugsync' ) . '</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( 'cancel-' . $run_id );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'A batch is still processing. Wait for it to finish before stopping the run.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$run = self::get_run( $run_id );

		if ( $run && ! self::run_is_finished( $run ) ) {
			if ( ! self::reconcile_journal( $run ) ) {
				self::release_lock( $lock_token );
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'The recovery journal could not be merged into the reports, so the run was not stopped. Check disk space and uploads permissions, then resume or try stopping again.', 'slugsync' ) .
					'</p></div>';
				return;
			}

			$run['status']       = 'canceled';
			$run['updated_at']   = time();
			$run['completed_at'] = time();

			if ( ! self::save_run( $run ) ) {
				self::release_lock( $lock_token );
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'The reports are safe, but WordPress could not save the stopped status. Reload the page and try stopping the run again.', 'slugsync' ) .
					'</p></div>';
				return;
			}

			self::clear_active_run( $run_id );
			self::reset_claims( $run_id );
		}

		self::release_lock( $lock_token );
		echo '<div class="notice notice-success"><p>' .
			esc_html__( 'Run stopped. Work already completed was kept, and the partial reports remain available under Previous runs.', 'slugsync' ) .
			'</p></div>';
	}

	/* ----------------------------------------------------------- rollback */

	/**
	 * Restore previous slugs from a selected run or a legacy report.
	 */
	private static function rollback() {
		global $wpdb;

		// Nonce is verified by render() before this method runs.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run    = $run_id ? self::get_run( $run_id ) : null;

		if ( $run_id && ( ! $run || ! isset( $run['mode'] ) || 'apply' !== $run['mode'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The selected apply run is not available.', 'slugsync' ) . '</p></div>';
			return;
		}

		if ( $run && ! self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Stop the active run before undoing its changes.', 'slugsync' ) . '</p></div>';
			return;
		}

		if ( $run && isset( $run['status'] ) && 'rolled_back' === $run['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run has already been undone.', 'slugsync' ) . '</p></div>';
			return;
		}

		if ( self::active_run() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Finish or stop the active run before undoing another run.', 'slugsync' ) . '</p></div>';
			return;
		}

		$file = self::report_path( $run_id, 'changes' );

		if ( ! file_exists( $file ) ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'No changes report was found for this undo.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( 'rollback-' . ( $run_id ? $run_id : 'legacy' ) );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another SlugSync operation is still running. Try again shortly.', 'slugsync' ) . '</p></div>';
			return;
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The report could not be read.', 'slugsync' ) .
				'</p></div>';
			return;
		}

		fgetcsv( $handle, 0, ',', '"', '' ); // Header row.

		$restored = 0;
		$skipped  = 0;
		$failed   = 0;
		$already  = 0;
		$log      = array();

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {

			if ( count( $row ) < 6 ) {
				continue;
			}

			$post_id  = absint( $row[0] );
			$old_slug = $row[4];
			$new_slug = $row[5];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_name, post_status, post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d",
					$post_id
				)
			);

			if ( null === $current ) {
				$skipped++;
				continue;
			}

			if ( $current->post_name === $old_slug ) {
				$already++;
				continue; // Restored by an earlier attempt that ended before checkpointing.
			}

			if ( $current->post_name !== $new_slug ) {
				$skipped++;
				/* translators: 1: post ID, 2: current slug, 3: expected slug. */
				$log[] = sprintf( __( '#%1$d skipped, slug is now "%2$s" rather than "%3$s"', 'slugsync' ), $post_id, $current->post_name, $new_slug );
				continue;
			}

			$safe_old_slug = wp_unique_post_slug(
				$old_slug,
				$post_id,
				$current->post_status,
				$current->post_type,
				(int) $current->post_parent
			);

			if ( $safe_old_slug !== $old_slug ) {
				$skipped++;
				/* translators: 1: post ID, 2: old slug, 3: slug WordPress would use instead. */
				$log[] = sprintf( __( '#%1$d skipped, the old slug "%2$s" is now in use (WordPress would choose "%3$s")', 'slugsync' ), $post_id, $old_slug, $safe_old_slug );
				continue;
			}

			// The slug being retired here is the one the run created, so record it.
			$result = self::write_slug( $post_id, $old_slug, true, $new_slug, $current->post_status, $current->post_type );

			if ( true === $result ) {
				$restored++;
			} else {
				$failed++;
				/* translators: 1: post ID, 2: error message. */
				$log[] = sprintf( __( '#%1$d failed: %2$s', 'slugsync' ), $post_id, $result );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $run ) {
			$previous_restored        = isset( $run['rollback_restored'] ) ? absint( $run['rollback_restored'] ) : 0;
			$run['updated_at']        = time();
			$run['rollback_restored'] = $previous_restored + $restored;
			$run['rollback_skipped']  = $skipped;
			$run['rollback_errors']   = $failed;

			if ( 0 === $failed ) {
				$run['status']         = 'rolled_back';
				$run['rolled_back_at'] = time();
			}

			if ( ! self::save_run( $run ) ) {
				$failed++;
				$log[] = __( 'The slugs were processed, but WordPress could not save the Undo checkpoint. Run Undo changes again to reconcile the status.', 'slugsync' );
			}
		}

		self::release_lock( $lock_token );

		echo '<div class="notice ' . esc_attr( $failed ? 'notice-error' : 'notice-success' ) . '"><p>';

		if ( $failed ) {
			printf(
				/* translators: 1: restored count, 2: skipped count, 3: failed count. */
				esc_html__( 'Undo is incomplete: restored %1$d slugs, skipped %2$d, and failed to restore %3$d. Fix the reported database error and use Undo changes again; successful rows will not be repeated.', 'slugsync' ),
				(int) $restored,
				(int) $skipped,
				(int) $failed
			);
		} else {
			printf(
				/* translators: 1: number restored, 2: number skipped. */
				esc_html__( 'Undo finished: restored %1$d slugs and skipped %2$d items that were missing, had a different current slug, or could no longer reclaim their old slug.', 'slugsync' ),
				(int) ( $restored + $already ),
				(int) $skipped
			);
		}
		echo '</p></div>';

		if ( $log ) {
			echo '<pre class="slug-sync-log">';
			echo esc_html( implode( "\n", $log ) );
			echo '</pre>';
		}
	}
}

Slug_Sync::init();
