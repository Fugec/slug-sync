<?php
/**
 * Plugin Name:       Slug Sync
 * Description:       Rewrites post, page, product and custom post type slugs to match their titles. Previews every change first, keeps the old URLs redirecting, exports a redirect map, and can roll the whole run back.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Armin Kapetanovic
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slug-sync
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

	/** Advertised Pro price, kept out of the translatable sentence around it. */
	const PRO_PRICE = '$79.99';

	/**
	 * In-request slug claims for a preview run, keyed by run ID then by slug.
	 *
	 * @var array<string,array<string,int>>
	 */
	private static $claims = array();

	/**
	 * Run IDs whose claims changed and still need writing back.
	 *
	 * @var array<string,bool>
	 */
	private static $claims_dirty = array();

	/**
	 * Boot.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_to_tools_page' ) );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slug-sync' ) );
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
			__( 'Slug Sync', 'slug-sync' ),
			__( 'Slug Sync', 'slug-sync' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);

		add_management_page(
			__( 'Slug Sync', 'slug-sync' ),
			__( 'Slug Sync', 'slug-sync' ),
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
	 */
	private static function save_run( $run ) {
		if ( empty( $run['id'] ) ) {
			return;
		}

		$run_id = sanitize_key( $run['id'] );
		$runs   = self::runs();

		$run['id']       = $run_id;
		$runs[ $run_id ] = $run;
		update_option( self::RUNS_OPT, $runs, false );
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

			foreach ( array( 'changes', 'redirects' ) as $report ) {
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

		$runs = self::runs();

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
			'post_type'         => self::requested_type(),
			'mode'              => isset( $_POST['mode'] ) && 'apply' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'apply' : 'dry', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'write'             => ! isset( $_POST['write'] ) || 'quiet' === sanitize_key( wp_unslash( $_POST['write'] ) ) ? 'quiet' : 'hooks', // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
		);

		if ( ! self::add_option_once( self::ACTIVE_OPT, $run_id ) ) {
			return null;
		}

		self::save_run( $run );
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
	 * Transient key used for collision claims in one preview run.
	 *
	 * @param string $run_id Run ID.
	 * @return string
	 */
	private static function claim_key( $run_id ) {
		return self::CLAIM_KEY . '_' . sanitize_key( $run_id );
	}

	/**
	 * Load a run's claims into the request, reading the transient at most once.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function load_claims( $run_id ) {
		$key = sanitize_key( $run_id );

		if ( ! isset( self::$claims[ $key ] ) ) {
			$claimed              = get_transient( self::claim_key( $run_id ) );
			self::$claims[ $key ] = is_array( $claimed ) ? $claimed : array();
		}
	}

	/**
	 * Write a run's claims back once, at the end of a batch.
	 *
	 * The claim map grows by one entry per changed post and ends up the size of
	 * the whole run. Storing it from inside claim() meant re-serializing and
	 * rewriting the entire map once per post, so a catalogue of n posts cost
	 * O(n^2) bytes of option writes, and a single late batch could rewrite
	 * megabytes a hundred times over. One write per batch is enough, because
	 * nothing outside this request reads the map until the next batch starts.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function flush_claims( $run_id ) {
		$key = sanitize_key( $run_id );

		if ( empty( self::$claims_dirty[ $key ] ) ) {
			return;
		}

		set_transient( self::claim_key( $run_id ), self::$claims[ $key ], 7 * DAY_IN_SECONDS );
		unset( self::$claims_dirty[ $key ] );
	}

	/**
	 * Drop a run's claims from both the request and storage.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function reset_claims( $run_id ) {
		$key = sanitize_key( $run_id );

		unset( self::$claims[ $key ], self::$claims_dirty[ $key ] );
		delete_transient( self::claim_key( $run_id ) );
	}

	/**
	 * Rebuild preview collision claims from the durable changes report when a
	 * transient expired or an external object cache evicted it before a resume.
	 *
	 * @param string $run_id Run ID.
	 * @param string $file   Changes report path.
	 */
	private static function restore_claims( $run_id, $file ) {
		$key       = sanitize_key( $run_id );
		$claim_key = self::claim_key( $run_id );

		if ( isset( self::$claims[ $key ] ) || false !== get_transient( $claim_key ) || ! is_readable( $file ) ) {
			return;
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return;
		}

		$claimed = array();
		fgetcsv( $handle, 0, ',', '"', '' ); // Header row.

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
			if ( count( $row ) >= 6 ) {
				$claimed[ $row[5] ] = absint( $row[0] );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::$claims[ $key ] = $claimed;
		set_transient( $claim_key, $claimed, 7 * DAY_IN_SECONDS );
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
			wp_die( esc_html__( 'You do not have permission to download this report.', 'slug-sync' ) );
		}

		// Nonce is checked below after the report and run keys are validated.
		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id = isset( $_GET['run_id'] ) ? sanitize_key( wp_unslash( $_GET['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$files  = array( 'changes', 'redirects' );

		if ( ! in_array( $report, $files, true ) || ( $run_id && ! self::get_run( $run_id ) ) ) {
			wp_die( esc_html__( 'Invalid report.', 'slug-sync' ) );
		}

		check_admin_referer( 'slug_sync_download_' . $report . '_' . ( $run_id ? $run_id : 'legacy' ) );

		$filename = self::report_filename( $run_id, $report );
		$path     = self::report_path( $run_id, $report );

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The report file is not available.', 'slug-sync' ) );
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
	 * A preview writes nothing, so wp_unique_post_slug() cannot see slugs claimed
	 * earlier in the same run. Two posts sharing a title would both preview the
	 * same slug and the redirect map would be wrong. Claims are tracked in a
	 * transient so the preview matches what applying will actually produce.
	 *
	 * @param string $slug      Desired slug.
	 * @param int    $post_id   Post ID.
	 * @param string $status    Post status.
	 * @param string $post_type Post type.
	 * @param int    $parent    Parent ID.
	 * @param bool   $simulate  True while previewing.
	 * @param string $run_id    Run ID used to isolate preview claims.
	 * @return string
	 */
	private static function claim( $slug, $post_id, $status, $post_type, $parent, $simulate, $run_id ) {
		$unique = wp_unique_post_slug( $slug, $post_id, $status, $post_type, $parent );

		if ( ! $simulate ) {
			return $unique;
		}

		self::load_claims( $run_id );

		$key     = sanitize_key( $run_id );
		$claimed = &self::$claims[ $key ];

		$try = $unique;
		$n   = 1;

		while ( isset( $claimed[ $try ] ) && $claimed[ $try ] !== $post_id ) {
			$n++;
			$try = wp_unique_post_slug( $unique . '-' . $n, $post_id, $status, $post_type, $parent );
		}

		// Held in memory and written back once per batch by flush_claims().
		$claimed[ $try ]            = $post_id;
		self::$claims_dirty[ $key ] = true;

		return $try;
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
			return $wpdb->last_error ? $wpdb->last_error : __( 'Database write failed.', 'slug-sync' );
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
	 * Small, dependency-free styles for the plugin's admin screen.
	 */
	private static function render_styles() {
		?>
		<style>
			/* slugsync.com's tokens, copied from the site's own stylesheet so the
			   plugin and the site are one product rather than two that share a
			   logo. wp-admin's greys are deliberately not used: #dcdcde and
			   #50575e are WordPress's, and mixing them with the brand palette is
			   what made the screen read as a different piece of software. */
			.slug-sync-admin {
				--ss-navy: #0b0f43;
				--ss-accent: #f53e02;
				--ss-accent-ink: #dc3802;
				--ss-accent-soft: rgba(245, 62, 2, .09);
				--ss-bg-2: rgba(11, 15, 67, .035);
				--ss-muted: rgba(11, 15, 67, .66);
				--ss-dim: rgba(11, 15, 67, .48);
				--ss-line: rgba(11, 15, 67, .10);
				--ss-line-2: rgba(11, 15, 67, .20);
				--ss-r: 14px;
				--ss-r-sm: 10px;
				--ss-ease: cubic-bezier(.22, 1, .36, 1);
			}
			.slug-sync-admin { max-width: none; }
			.slug-sync-brand {
				align-items: center;
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				margin: 0 0 4px;
				padding: 9px 0 4px;
			}
			.slug-sync-brand img { height: 34px; width: auto; }
			.slug-sync-intro,
			.slug-sync-card,
			.slug-sync-active {
				background: #fff;
				border: 1px solid var(--ss-line);
				border-radius: var(--ss-r);
				box-sizing: border-box;
				margin: 18px 0;
				padding: 24px;
				transition: border-color .25s, box-shadow .25s var(--ss-ease);
			}
			/* The site lifts its cards on hover. These hold forms rather than
			   links, so they take the border and shadow and not the movement --
			   a control that shifts under the pointer is worse than a static
			   one, however much it matches. */
			.slug-sync-card:hover { border-color: var(--ss-line-2); box-shadow: 0 14px 32px -20px rgba(11, 15, 67, .3); }
			.slug-sync-intro { border-left: 4px solid var(--ss-accent); }
			.slug-sync-intro > p:first-child { font-size: 15px; margin-top: 0; }
			.slug-sync-steps {
				display: grid;
				gap: 12px;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				margin: 18px 0 0;
			}
			.slug-sync-step {
				background: #fff;
				border: 1px solid var(--ss-line);
				border-radius: var(--ss-r);
				padding: 22px 20px;
				transition: border-color .25s, box-shadow .25s var(--ss-ease);
			}
			.slug-sync-step:hover { border-color: var(--ss-line-2); box-shadow: 0 14px 32px -20px rgba(11, 15, 67, .3); }
			.slug-sync-step strong { display: block; margin-bottom: 4px; }
			.slug-sync-card > h2,
			.slug-sync-active > h2 {
				color: var(--ss-navy);
				font-size: 1.05rem;
				font-weight: 700;
				letter-spacing: -.01em;
				line-height: 1.2;
				margin: 0 0 8px;
				padding: 0;
			}
			/* The site's section label: small, orange, widely tracked. */
			.slug-sync-eyebrow {
				color: var(--ss-accent-ink);
				display: block;
				font-size: .75rem;
				font-weight: 700;
				letter-spacing: .14em;
				margin: 0 0 10px;
				text-transform: uppercase;
			}
			.slug-sync-card > p { color: var(--ss-muted); margin-top: 0; }
			.slug-sync-progress { margin: 14px 0 4px; }
			.slug-sync-progress-track {
				background: var(--ss-bg-2);
				border-radius: 9px;
				box-shadow: inset 0 0 0 1px var(--ss-line);
				height: 18px;
				overflow: hidden;
				width: 100%;
			}
			.slug-sync-progress-fill {
				background: var(--ss-accent);
				border-radius: 9px;
				height: 100%;
				transition: width .3s ease;
			}
			.slug-sync-progress-fill.is-working {
				animation: slug-sync-stripes 1s linear infinite;
				background-image: linear-gradient(45deg, rgba(255,255,255,.22) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.22) 50%, rgba(255,255,255,.22) 75%, transparent 75%);
				background-size: 22px 22px;
			}
			@keyframes slug-sync-stripes {
				from { background-position: 0 0; }
				to { background-position: 22px 0; }
			}
			@media (prefers-reduced-motion: reduce) {
				.slug-sync-progress-fill { transition: none; }
				.slug-sync-progress-fill.is-working { animation: none; }
			}
			.slug-sync-progress-meta {
				color: var(--ss-muted);
				display: flex;
				font-size: 13px;
				gap: 12px;
				justify-content: space-between;
				margin-top: 6px;
			}
			.slug-sync-progress-meta strong { color: var(--ss-navy); }
			.slug-sync-number {
				align-items: center;
				background: var(--ss-accent);
				border-radius: 50%;
				color: #fff;
				display: inline-flex;
				font-size: 13px;
				height: 26px;
				justify-content: center;
				margin-right: 8px;
				vertical-align: 2px;
				width: 26px;
			}
			.slug-sync-select { font-size: 14px; min-width: 280px; }
			.slug-sync-choices { display: grid; gap: 10px; margin-top: 14px; }
			.slug-sync-choice {
				align-items: flex-start;
				border: 1px solid var(--ss-line-2);
				border-radius: var(--ss-r-sm);
				cursor: pointer;
				display: grid;
				gap: 10px;
				grid-template-columns: auto 1fr;
				padding: 14px;
			}
			.slug-sync-choice:hover,
			.slug-sync-choice:focus-within { border-color: var(--ss-navy); box-shadow: 0 0 0 1px var(--ss-navy); }
			.slug-sync-choice:has(input:checked) { background: var(--ss-accent-soft); border-color: var(--ss-accent); box-shadow: inset 3px 0 0 var(--ss-accent); }
			.slug-sync-choice input { margin-top: 3px; }
			.slug-sync-choice-title { display: block; font-size: 14px; margin-bottom: 4px; }
			.slug-sync-choice-help { color: var(--ss-muted); display: block; line-height: 1.5; }
			.slug-sync-badge {
				background: #e7f5ea;
				border-radius: 999px;
				color: #116329;
				display: inline-block;
				font-size: 11px;
				font-weight: 600;
				margin-left: 6px;
				padding: 2px 8px;
				vertical-align: 1px;
			}
			.slug-sync-example { background: var(--ss-bg-2); border-radius: var(--ss-r-sm); display: inline-block; margin-top: 5px; padding: 3px 7px; }
			.slug-sync-apply-note { background: #fcf0f1; border-left: 4px solid #d63638; border-radius: var(--ss-r-sm); padding: 12px 14px; }
			.slug-sync-hierarchy-note { background: #fcf9e8; border-left: 4px solid #dba617; border-radius: var(--ss-r-sm); padding: 12px 14px; }
			.slug-sync-hierarchy-note p { margin: 6px 0 0; }
			.slug-sync-safety { background: var(--ss-bg-2); border: 1px solid var(--ss-line); border-left: 4px solid var(--ss-navy); border-radius: var(--ss-r-sm); padding: 16px 18px; }
			.slug-sync-safety h3 { margin: 0 0 6px; }
			.slug-sync-safety ul { margin-bottom: 0; }
			.slug-sync-actions { align-items: center; display: flex; gap: 12px; margin: 18px 0 24px; }
			.slug-sync-actions .button-primary { min-height: 36px; padding: 4px 18px; }
			.slug-sync-admin .button-primary {
				background: var(--ss-accent);
				border-color: var(--ss-accent);
				border-radius: 8px;
				box-shadow: 0 8px 22px -10px rgba(245, 62, 2, .75);
				color: #fff;
				text-shadow: none;
			}
			.slug-sync-admin .button-primary:hover {
				background: var(--ss-accent);
				border-color: var(--ss-accent);
				box-shadow: 0 12px 30px -10px rgba(245, 62, 2, .85);
				color: #fff;
				filter: brightness(.92);
			}
			.slug-sync-admin .button-primary:focus {
				background: var(--ss-accent);
				border-color: var(--ss-accent);
				box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--ss-navy);
				color: #fff;
			}
			.slug-sync-admin .button { border-radius: 8px; }
			.slug-sync-controls { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
			.slug-sync-controls form,
			.slug-sync-history form { margin: 0; }
			.slug-sync-history td { vertical-align: top; }
			.slug-sync-history .button { margin: 0 4px 4px 0; }
			.slug-sync-table-wrap { overflow-x: auto; }
			.slug-sync-advanced { margin: 0 0 18px; }
			.slug-sync-advanced > summary {
				align-items: center;
				background: #fff;
				border: 1px solid var(--ss-line);
				border-radius: var(--ss-r);
				color: var(--ss-navy);
				cursor: pointer;
				display: flex;
				flex-wrap: wrap;
				font-size: 15px;
				font-weight: 700;
				gap: 12px;
				list-style: none;
				padding: 20px 24px;
				transition: border-color .25s, box-shadow .25s var(--ss-ease);
			}
			.slug-sync-advanced > summary::-webkit-details-marker { display: none; }
			.slug-sync-advanced > summary::before {
				align-items: center;
				background: var(--ss-accent-soft);
				border-radius: 50%;
				color: var(--ss-accent-ink);
				content: "+";
				display: inline-flex;
				flex: none;
				font-size: 16px;
				font-weight: 700;
				height: 26px;
				justify-content: center;
				line-height: 1;
				width: 26px;
			}
			.slug-sync-advanced[open] > summary::before { content: "\2212"; }
			.slug-sync-advanced > summary:hover { border-color: var(--ss-line-2); box-shadow: 0 14px 32px -20px rgba(11, 15, 67, .3); }
			.slug-sync-advanced-hint {
				color: var(--ss-muted);
				font-size: 13px;
				font-weight: 400;
				margin-left: auto;
			}
			.slug-sync-advanced[open] > summary { margin-bottom: 0; }
			.slug-sync-advanced > .slug-sync-card { margin-top: 10px; }

			/* ---- Vertical rhythm ----------------------------------------
			   Every block on the screen was setting its own margins, so the gap
			   above a note and the gap below it were rarely the same number.
			   One scale, applied once: 8px inside a pair, 16px between blocks,
			   24px before a new heading, and nothing hanging off the bottom of
			   a card. */
			.slug-sync-card > :first-child,
			.slug-sync-intro > :first-child,
			.slug-sync-sub > :first-child { margin-top: 0; }
			.slug-sync-card > :last-child,
			.slug-sync-intro > :last-child,
			.slug-sync-sub > :last-child { margin-bottom: 0; }

			.slug-sync-card > h2,
			.slug-sync-sub > h2 { margin: 0 0 8px; }
			.slug-sync-card > h3 { margin: 24px 0 8px; }
			.slug-sync-card > p,
			.slug-sync-sub > p { margin: 0 0 16px; }

			.slug-sync-card > .slug-sync-safety,
			.slug-sync-card > .slug-sync-apply-note,
			.slug-sync-card > .slug-sync-hierarchy-note,
			.slug-sync-card > .slug-sync-taxonomy-note,
			.slug-sync-card > .slug-sync-taxonomy-scope,
			.slug-sync-card > .slug-sync-eg,
			.slug-sync-card > .slug-sync-examples,
			.slug-sync-card > .slug-sync-choices,
			.slug-sync-card > .form-table,
			.slug-sync-card > .slug-sync-field { margin: 16px 0; }

			.slug-sync-card > .form-table:first-of-type { margin-top: 8px; }
			.slug-sync-admin .form-table > tbody > tr:first-child > th,
			.slug-sync-admin .form-table > tbody > tr:first-child > td { padding-top: 0; }
			.slug-sync-admin .form-table > tbody > tr:last-child > th,
			.slug-sync-admin .form-table > tbody > tr:last-child > td { padding-bottom: 0; }

			/* One size for explanatory text everywhere on the screen. wp-admin
			   ships several and they were all showing up at once. */
			.slug-sync-admin .description,
			.slug-sync-admin .slug-sync-choice-help,
			.slug-sync-admin p { font-size: 13px; }
			.slug-sync-admin .slug-sync-card > p,
			.slug-sync-admin .slug-sync-intro > p { font-size: 14px; }
			.slug-sync-admin .form-table th { font-size: 14px; padding-top: 18px; width: 180px; }
			.slug-sync-admin .form-table td { padding-top: 14px; padding-bottom: 14px; }
			.slug-sync-admin .form-table td .description { margin: 6px 0 0; }

			/* Labelled field inside a cell, so a control and its instructions
			   stay together instead of the help drifting to the next field. */
			.slug-sync-field { margin: 14px 0 0; }
			.slug-sync-field label { display: block; margin-bottom: 5px; }

			/* A save button is a button. It was rendering as flat text next to
			   controls that all look like buttons. */
			.slug-sync-admin .button-primary.slug-sync-save {
				background: var(--ss-navy);
				border-color: var(--ss-navy);
				box-shadow: none;
			}
			.slug-sync-admin .button-primary.slug-sync-save:hover {
				background: var(--ss-navy);
				border-color: var(--ss-navy);
				filter: brightness(1.35);
			}

			/* Worked example under a heading. */
			/* A block inside a disclosure panel. Not a card -- a card inside a
			   card reads as a second layer of boxes rather than a section. */
			.slug-sync-sub { padding: 4px 0 18px; }
			.slug-sync-sub + .slug-sync-sub { border-top: 1px solid var(--ss-line); padding-top: 18px; }
			.slug-sync-sub > h2 {
				color: var(--ss-navy);
				font-size: 15px;
				font-weight: 700;
				margin: 0 0 4px;
				padding: 0;
			}
			.slug-sync-sub > h2 + p { margin-top: 0; }

			.slug-sync-eg {
				background: var(--ss-bg-2);
				border-radius: var(--ss-r-sm);
				font-size: 13px;
				padding: 12px 14px;
			}
			.slug-sync-eg code { background: #fff; }
			.slug-sync-technical { margin-top: 6px; }
			.slug-sync-technical summary { color: var(--ss-dim); cursor: pointer; font-size: 12px; }
			.slug-sync-batch-log summary { cursor: pointer; font-weight: 600; }
			.slug-sync-batch-log pre { background: var(--ss-bg-2); border: 1px solid var(--ss-line); max-height: 340px; overflow: auto; padding: 12px; }
			@media (max-width: 782px) {
				.slug-sync-brand img { height: 28px; }
				.slug-sync-steps { grid-template-columns: 1fr; }
				.slug-sync-select { max-width: 100%; min-width: 0; width: 100%; }
				.slug-sync-card { padding: 16px; }
			}
		</style>
		<?php
	}

	/**
	 * Route the Tools screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slug-sync' ) );
		}

		echo '<div class="wrap slug-sync-admin">';

		printf(
			'<h1 class="slug-sync-brand"><img src="%s" alt="%s" width="400" height="130"></h1>',
			esc_url( plugins_url( 'assets/logo.png', __FILE__ ) ),
			esc_attr__( 'Slug Sync', 'slug-sync' )
		);
		self::render_styles();

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
			'running'     => __( 'In progress', 'slug-sync' ),
			'paused'      => __( 'Paused', 'slug-sync' ),
			'completed'   => __( 'Completed', 'slug-sync' ),
			'canceled'    => __( 'Stopped', 'slug-sync' ),
			'rolled_back' => __( 'Undone', 'slug-sync' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'slug-sync' );
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
			<div class="slug-sync-progress-track" role="progressbar" aria-valuenow="<?php echo esc_attr( $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Run progress', 'slug-sync' ); ?>">
				<div class="<?php echo esc_attr( $fill_class ); ?>" style="width:<?php echo esc_attr( $percent ); ?>%"></div>
			</div>
			<div class="slug-sync-progress-meta" aria-live="polite">
				<strong><?php echo esc_html( $percent ); ?>%</strong>
				<span>
					<?php
					printf(
						/* translators: 1: items scanned, 2: total items. */
						esc_html__( '%1$s of %2$s scanned', 'slug-sync' ),
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
				<button class="button button-primary"><?php echo esc_html( $auto ? __( 'Continue', 'slug-sync' ) : __( 'Resume run', 'slug-sync' ) ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Stop this run? Work already completed will not be reversed, and the partial reports will be kept.', 'slug-sync' ) ); ?>');">
				<?php wp_nonce_field( 'slug_sync' ); ?>
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>">
				<button class="button" name="slug_sync_cancel" value="1"><?php esc_html_e( 'Stop run', 'slug-sync' ); ?></button>
			</form>
		</div>
		<?php if ( $auto ) : ?>
			<script>setTimeout(function(){document.getElementById('slug-sync-next').submit();},700);</script>
		<?php endif; ?>
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

		echo '<hr><h2>' . esc_html__( 'Previous runs', 'slug-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each row is one preview or apply run. Reports can be downloaded at any time; only applied changes can be undone.', 'slug-sync' ) . '</p>';
		echo '<div class="slug-sync-table-wrap"><table class="widefat striped slug-sync-history"><thead><tr>';
		echo '<th>' . esc_html__( 'Started', 'slug-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'What ran', 'slug-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'slug-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'slug-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Reports', 'slug-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'slug-sync' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $visible as $run ) {
			if ( empty( $run['id'] ) ) {
				continue;
			}

			$run_id      = sanitize_key( $run['id'] );
			$post_type   = isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '';
			$type_label  = isset( $types[ $post_type ] ) ? $types[ $post_type ]->labels->singular_name : $post_type;
			$created_at  = isset( $run['created_at'] ) ? (int) $run['created_at'] : 0;
			$status      = isset( $run['status'] ) ? sanitize_key( $run['status'] ) : '';
			$done        = isset( $run['done'] ) ? (int) $run['done'] : 0;
			$total       = isset( $run['total'] ) ? (int) $run['total'] : 0;
			$changed     = isset( $run['changed'] ) ? (int) $run['changed'] : 0;
			$errors      = isset( $run['errors'] ) ? (int) $run['errors'] : 0;
			$changes     = self::report_path( $run_id, 'changes' );
			$redirects   = self::report_path( $run_id, 'redirects' );
			$is_active   = ! self::run_is_finished( $run );
			$is_apply    = isset( $run['mode'] ) && 'apply' === $run['mode'];
			$write_label = isset( $run['write'] ) && 'hooks' === $run['write'] ? __( 'standard WordPress update', 'slug-sync' ) : __( 'quiet update', 'slug-sync' );
			?>
			<tr>
				<td><?php echo esc_html( $created_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created_at ) : '—' ); ?></td>
				<td>
					<strong><?php echo esc_html( $type_label ); ?></strong><br>
					<span class="description"><?php echo esc_html( $is_apply ? __( 'Apply', 'slug-sync' ) . ' / ' . $write_label : __( 'Preview', 'slug-sync' ) ); ?></span>
					<details class="slug-sync-technical"><summary><?php esc_html_e( 'Technical ID', 'slug-sync' ); ?></summary><code><?php echo esc_html( $run_id ); ?></code></details>
				</td>
				<td><strong><?php echo esc_html( self::status_label( $status ) ); ?></strong></td>
				<td>
					<?php
					printf(
						/* translators: 1: scanned posts, 2: total posts. */
						esc_html__( '%1$d / %2$d scanned', 'slug-sync' ),
						absint( $done ),
						absint( $total )
					);
					?><br>
					<span class="description">
						<?php
						printf(
							/* translators: 1: changed posts, 2: errors. */
							esc_html__( '%1$d changes, %2$d errors', 'slug-sync' ),
							absint( $changed ),
							absint( $errors )
						);
						?>
					</span>
				</td>
				<td>
					<?php if ( is_file( $changes ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( self::report_download_url( 'changes', $run_id ) ); ?>"><?php esc_html_e( 'Download changes', 'slug-sync' ); ?></a>
					<?php endif; ?>
					<?php if ( is_file( $redirects ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( self::report_download_url( 'redirects', $run_id ) ); ?>"><?php esc_html_e( 'Download redirects', 'slug-sync' ); ?></a>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $is_active ) : ?>
						<?php self::run_controls( $run ); ?>
					<?php elseif ( $is_apply && $changed > 0 && 'rolled_back' !== $status && is_file( $changes ) ) : ?>
						<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Undo the slug changes from this run? Items edited since the run will be left unchanged.', 'slug-sync' ) ); ?>');">
							<?php wp_nonce_field( 'slug_sync' ); ?>
							<input type="hidden" name="run_id" value="<?php echo esc_attr( $run_id ); ?>">
							<button class="button button-small" name="slug_sync_rollback" value="1"><?php esc_html_e( 'Undo changes', 'slug-sync' ); ?></button>
						</form>
					<?php else : ?>
						<span aria-hidden="true">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		echo '</tbody></table></div>';
		echo '<p class="description"><strong>' . esc_html__( 'Changes report:', 'slug-sync' ) . '</strong> ' .
			esc_html__( 'shows each old and proposed/new URL and supports Undo.', 'slug-sync' ) . ' <strong>' .
			esc_html__( 'Redirect report:', 'slug-sync' ) . '</strong> ' .
			esc_html__( 'contains source and destination pairs for published items, ready for a redirect tool.', 'slug-sync' ) . '</p>';

		if ( count( $runs ) > 20 ) {
			if ( $show_all ) {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url( self::page_url() ),
					esc_html__( 'Show the latest 20 runs', 'slug-sync' )
				);
			} else {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url( add_query_arg( 'slug_sync_history', 'all', self::page_url() ) ),
					esc_html__( 'Show all run history', 'slug-sync' )
				);
			}
		}
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

		echo '<hr><h2>' . esc_html__( 'Reports from an earlier version', 'slug-sync' ) . '</h2><p>';

		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( self::report_download_url( 'changes' ) ),
			esc_html__( 'Download changes', 'slug-sync' )
		);

		if ( is_file( $redirects ) ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( self::report_download_url( 'redirects' ) ),
				esc_html__( 'Download redirects', 'slug-sync' )
			);
		}

		echo '</p><p class="description">' .
			esc_html__( 'These files were created before the Previous runs screen was introduced.', 'slug-sync' ) .
			'</p>';

		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Undo the slug changes recorded in this earlier report?', 'slug-sync' ) ); ?>');">
			<?php wp_nonce_field( 'slug_sync' ); ?>
			<p><button class="button" name="slug_sync_rollback" value="1"><?php esc_html_e( 'Undo changes from this report', 'slug-sync' ); ?></button></p>
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
				<h2><?php esc_html_e( 'Finish your current run', 'slug-sync' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: run type, 2: content type, 3: scanned count, 4: total count. */
						esc_html__( 'An unfinished %1$s for %2$s has scanned %3$d of %4$d items. Resume continues from the last completed batch; it does not start over.', 'slug-sync' ),
						$is_apply ? esc_html__( 'Apply run', 'slug-sync' ) : esc_html__( 'Preview', 'slug-sync' ),
						esc_html( $type_name ),
						absint( $done ),
						absint( $total )
					);
					?>
				</p>
				<?php self::progress_bar( $done, $total ); ?>
				<?php if ( $is_apply ) : ?>
					<p class="description"><?php esc_html_e( 'Stopping keeps changes already made and preserves the partial reports. It does not undo completed batches.', 'slug-sync' ); ?></p>
				<?php endif; ?>
				<?php self::run_controls( $active ); ?>
			</div>
			<?php
			return;
		}

		$types          = self::post_types();
		$default        = self::default_type();
		$batch_size     = self::batch_size();
		$interface_text = array(
			'preview_button' => __( 'Create preview', 'slug-sync' ),
			'apply_button'   => __( 'Apply slug changes', 'slug-sync' ),
			'preview_write'  => __( 'This choice has no effect during a preview because nothing is saved.', 'slug-sync' ),
			'apply_write'    => __( 'This choice controls how each slug is saved during Apply.', 'slug-sync' ),
			'confirm_apply'  => __( 'Apply will begin changing slugs immediately. Have you reviewed a preview and taken a database backup?', 'slug-sync' ),
		);

		// Drives the note below, and is read again by the script at the end.
		$hierarchical = array();

		foreach ( array_keys( $types ) as $type_name ) {
			$hierarchical[ $type_name ] = is_post_type_hierarchical( $type_name );
		}
		?>
		<div class="slug-sync-intro">
			<p><strong><?php esc_html_e( 'Make URL slugs match content titles—safely and in batches.', 'slug-sync' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'A slug is the last part of a URL. For example, a title of “Blue Cotton Shirt” normally uses the slug “blue-cotton-shirt”. Slug Sync finds items that do not follow that pattern.', 'slug-sync' ); ?>
			</p>
			<div class="slug-sync-steps">
				<div class="slug-sync-step"><strong><?php esc_html_e( '1. Preview', 'slug-sync' ); ?></strong><?php esc_html_e( 'See every proposed old and new URL without saving changes.', 'slug-sync' ); ?></div>
				<div class="slug-sync-step"><strong><?php esc_html_e( '2. Review', 'slug-sync' ); ?></strong><?php esc_html_e( 'Download the changes report and check duplicate-title notes.', 'slug-sync' ); ?></div>
				<div class="slug-sync-step"><strong><?php esc_html_e( '3. Apply', 'slug-sync' ); ?></strong><?php esc_html_e( 'Run again with Apply selected when the preview looks correct.', 'slug-sync' ); ?></div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" id="slug-sync-start-form">
			<?php wp_nonce_field( 'slug_sync' ); ?>

			<section class="slug-sync-card" aria-labelledby="slug-sync-content-heading">
				<h2 id="slug-sync-content-heading"><span class="slug-sync-number">1</span><?php esc_html_e( 'What should I tidy up?', 'slug-sync' ); ?></h2>
				<p><?php esc_html_e( 'Only the selected content type is processed. Attachments and product variations are never included.', 'slug-sync' ); ?></p>
				<label for="slug-sync-post-type"><strong><?php esc_html_e( 'Content type', 'slug-sync' ); ?></strong></label><br>
				<select name="post_type" id="slug-sync-post-type" class="slug-sync-select">
					<?php foreach ( $types as $name => $object ) : ?>
						<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $name, $default ); ?>>
							<?php echo esc_html( $object->labels->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<div class="slug-sync-hierarchy-note" id="slug-sync-hierarchy-note" <?php echo is_post_type_hierarchical( $default ) ? '' : 'hidden'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string. ?>>
					<strong><?php esc_html_e( 'This content type nests inside a parent, so plan redirects yourself.', 'slug-sync' ); ?></strong>
					<p><?php esc_html_e( 'WordPress only redirects an old URL by itself for content that does not nest, such as posts and products. It does not do so for pages, whatever tool changes the slug, so import the redirect report into a redirect plugin after applying.', 'slug-sync' ); ?></p>
					<p><?php esc_html_e( 'A nested URL also contains its parents\' slugs. Renaming a parent therefore changes the URL of everything beneath it, and those child URLs are not in the reports, which list only items whose own slug changed. Check what sits under anything you rename.', 'slug-sync' ); ?></p>
				</div>
			</section>

			<section class="slug-sync-card" aria-labelledby="slug-sync-action-heading">
				<h2 id="slug-sync-action-heading"><span class="slug-sync-number">2</span><?php esc_html_e( 'Preview first, or apply now?', 'slug-sync' ); ?></h2>
				<p><?php esc_html_e( 'Start with a preview. Apply uses the same matching rules but saves the proposed slugs.', 'slug-sync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="radio" name="mode" value="dry" checked>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Create a preview', 'slug-sync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Recommended first', 'slug-sync' ); ?></span></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Reads the selected content and creates reports. No slugs, URLs, posts, or products are changed.', 'slug-sync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="radio" name="mode" value="apply">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Apply the slug changes', 'slug-sync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Changes matching slugs in batches and records reports for downloads and undo. Use this after reviewing a preview.', 'slug-sync' ); ?></span>
						</span>
					</label>
				</div>
				<div class="slug-sync-apply-note" id="slug-sync-apply-note" hidden>
					<strong><?php esc_html_e( 'Apply will change live URLs.', 'slug-sync' ); ?></strong>
					<?php esc_html_e( 'Take a database backup first. Existing indexed URLs are recorded for redirection, and a redirect CSV is created as a second layer.', 'slug-sync' ); ?>
				</div>
			</section>

			<details class="slug-sync-advanced">
			<summary><?php esc_html_e( 'How it runs', 'slug-sync' ); ?><span class="slug-sync-advanced-hint"><?php esc_html_e( 'Sensible defaults already chosen — most people never need this', 'slug-sync' ); ?></span></summary>
			<div class="slug-sync-card">

			<section class="slug-sync-sub" aria-labelledby="slug-sync-write-heading">
				<h2 id="slug-sync-write-heading"><?php esc_html_e( 'How each change is saved', 'slug-sync' ); ?></h2>
				<p id="slug-sync-write-help"><?php echo esc_html( $interface_text['preview_write'] ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="radio" name="write" value="quiet" checked>
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Quiet update', 'slug-sync' ); ?><span class="slug-sync-badge"><?php esc_html_e( 'Recommended', 'slug-sync' ); ?></span></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Best for most sites and large stores. Changes only the URL slug, keeps the existing modified date, and avoids triggering save automations, webhooks, or integration syncs for every item. Redirect protections and reports are still created.', 'slug-sync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="radio" name="write" value="hooks">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Standard WordPress update', 'slug-sync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Saves every item through WordPress normally. This updates its modified date and runs plugins, webhooks, and automations connected to saving. Choose this only when another integration must react to each slug change; it can be much slower.', 'slug-sync' ); ?></span>
						</span>
					</label>
				</div>
			</section>

			<section class="slug-sync-sub" aria-labelledby="slug-sync-scope-heading">
				<h2 id="slug-sync-scope-heading"><?php esc_html_e( 'What is included', 'slug-sync' ); ?></h2>
				<p><?php esc_html_e( 'By default, only published items whose slug clearly differs from the title are included. Leave these unchecked unless you need the broader scope described.', 'slug-sync' ); ?></p>
				<div class="slug-sync-choices">
					<label class="slug-sync-choice">
						<input type="checkbox" name="drafts" value="1">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Also include unpublished items', 'slug-sync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Includes drafts, pending-review items, and private items. Leave off if you only want to change URLs that visitors can currently access.', 'slug-sync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="checkbox" name="suffixed" value="1">
						<span>
							<strong class="slug-sync-choice-title"><?php esc_html_e( 'Recheck numbered slugs', 'slug-sync' ); ?></strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Includes slugs that already match the title except for a number. These are skipped by default because the number often marks a duplicate title. WordPress may add a number again when it is needed to keep the URL unique.', 'slug-sync' ); ?></span>
							<span class="slug-sync-example"><?php esc_html_e( 'Example: blue-cotton-shirt-2', 'slug-sync' ); ?></span>
						</span>
					</label>
					<label class="slug-sync-choice">
						<input type="checkbox" name="testonly" value="1">
						<span>
							<strong class="slug-sync-choice-title">
								<?php
								/* translators: %d: number of items in one batch. */
								printf( esc_html__( 'Pause after the first %d items', 'slug-sync' ), (int) $batch_size );
								?>
								<span class="slug-sync-badge"><?php esc_html_e( 'Useful for first Apply', 'slug-sync' ); ?></span>
							</strong>
							<span class="slug-sync-choice-help"><?php esc_html_e( 'Processes one batch, then pauses so you can inspect the partial report. Resume continues with the next item. Stopping does not undo the first batch.', 'slug-sync' ); ?></span>
						</span>
					</label>
				</div>
			</section>

			</div>
			</details>

			<div class="slug-sync-safety" id="slug-sync-safety" hidden>
				<h3><?php esc_html_e( 'Before you apply', 'slug-sync' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Take a current database backup.', 'slug-sync' ); ?></li>
					<li><?php esc_html_e( 'Run and review a complete preview first.', 'slug-sync' ); ?></li>
					<li><?php esc_html_e( 'After Apply finishes, purge page/CDN caches and test several old URLs.', 'slug-sync' ); ?></li>
				</ul>
			</div>

			<div class="slug-sync-actions">
				<button class="button button-primary" id="slug-sync-start-button" name="slug_sync_run" value="1"><?php echo esc_html( $interface_text['preview_button'] ); ?></button>
				<span class="description"><?php esc_html_e( 'Large sites continue automatically in small batches.', 'slug-sync' ); ?></span>
			</div>
		</form>
		<script>
		(function(){
			var form = document.getElementById('slug-sync-start-form');
			if (!form) { return; }
			var text = <?php echo wp_json_encode( $interface_text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded for JavaScript. ?>;
			var button = document.getElementById('slug-sync-start-button');
			var writeHelp = document.getElementById('slug-sync-write-help');
			var applyNote = document.getElementById('slug-sync-apply-note');
			var safety = document.getElementById('slug-sync-safety');
			var hierarchical = <?php echo wp_json_encode( $hierarchical, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded for JavaScript. ?>;
			var typeSelect = document.getElementById('slug-sync-post-type');
			var hierarchyNote = document.getElementById('slug-sync-hierarchy-note');
			function applying(){
				var selected = form.querySelector('input[name="mode"]:checked');
				return selected && selected.value === 'apply';
			}
			function update(){
				var isApply = applying();
				button.textContent = isApply ? text.apply_button : text.preview_button;
				writeHelp.textContent = isApply ? text.apply_write : text.preview_write;
				applyNote.hidden = !isApply;
				if (safety) { safety.hidden = !isApply; }
				if (typeSelect && hierarchyNote) {
					hierarchyNote.hidden = !hierarchical[typeSelect.value];
				}
			}
			form.addEventListener('change', function(event){
				if (event.target.name === 'mode' || event.target.name === 'post_type') { update(); }
			});
			form.addEventListener('submit', function(event){
				if (applying() && !window.confirm(text.confirm_apply)) { event.preventDefault(); }
			});
			update();
		})();
		</script>
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
				esc_html__( 'A run could not be started. Resume or stop the active run before trying again.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		$run_id = sanitize_key( $run['id'] );

		if ( self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run has already finished.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$active = self::active_run();

		if ( ! $active ) {
			if ( ! self::add_option_once( self::ACTIVE_OPT, $run_id ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Another run became active. Reload this page and try again.', 'slug-sync' ) . '</p></div>';
				return;
			}
		} elseif ( sanitize_key( $active['id'] ) !== $run_id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Another run is active. Finish or stop it first.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( $run_id );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Another batch is still processing. Wait a moment, then use Resume.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		// Reload after acquiring the lock so a cancel or completed request cannot be overwritten.
		$run = self::get_run( $run_id );

		if ( ! $run || self::run_is_finished( $run ) ) {
			self::release_lock( $lock_token );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run is no longer available to resume.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$last_id       = isset( $run['last_id'] ) ? absint( $run['last_id'] ) : 0;
		$done          = isset( $run['done'] ) ? absint( $run['done'] ) : 0;
		$changed       = isset( $run['changed'] ) ? absint( $run['changed'] ) : 0;
		$errors        = isset( $run['errors'] ) ? absint( $run['errors'] ) : 0;
		$sig_code      = isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0;
		$sig_stopword  = isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0;
		$sig_non_latin = isset( $run['sig_non_latin'] ) ? absint( $run['sig_non_latin'] ) : 0;
		$apply         = isset( $run['mode'] ) && 'apply' === $run['mode'];
		$quiet         = ! isset( $run['write'] ) || 'quiet' === $run['write'];
		$drafts        = ! empty( $run['drafts'] );
		$suffixed      = ! empty( $run['suffixed'] );
		$pause         = ! empty( $run['pause_after_batch'] );
		$post_type     = isset( $run['post_type'] ) ? sanitize_key( $run['post_type'] ) : '';
		$statuses      = $drafts
			? array( 'publish', 'draft', 'pending', 'private' )
			: array( 'publish' );

		$batch    = self::batch_size();
		$is_first = ( 0 === $last_id );
		$changes_path = self::report_path( $run_id, 'changes' );
		$redirect_path = self::report_path( $run_id, 'redirects' );

		if ( $is_first ) {
			delete_transient( self::CLAIM_KEY ); // Clean up the older global claim transient.
			self::reset_claims( $run_id );
		} elseif ( ! is_file( $changes_path ) || ! is_file( $redirect_path ) ) {
			$run['status']     = 'paused';
			$run['updated_at'] = time();
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'This run cannot resume because one of its report files is missing. Stop it before starting another run.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		if ( ! $apply && ! $is_first ) {
			self::restore_claims( $run_id, $changes_path );
		}

		// $statuses is a hardcoded literal array (see above), so $placeholders is only
		// ever a list of "%s" tokens -- no caller input reaches the SQL string. Every
		// value is still passed through prepare(). A dynamic IN() list cannot be
		// expressed in a way the sniffs can follow, hence the annotations below.
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ($placeholders)",
				array_merge( array( $post_type ), $statuses )
			)
		);

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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Those rows came straight from $wpdb, so none of them are in the post
		// cache. get_permalink() and the _wp_old_slug lookups below would each
		// fetch their post one query at a time -- a hundred or more extra queries
		// per batch. Prime posts, terms and meta in three queries instead.
		if ( $rows ) {
			_prime_post_caches( wp_list_pluck( $rows, 'ID' ), true, true );
		}

		$mode    = $is_first ? 'w' : 'a';
		$changes_handle = fopen( $changes_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$redirect_handle = fopen( $redirect_path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $changes_handle || ! $redirect_handle ) {
			if ( $changes_handle ) {
				fclose( $changes_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( $redirect_handle ) {
				fclose( $redirect_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			$run['status']     = 'paused';
			$run['updated_at'] = time();
			$run['errors']     = $errors + 1;
			self::save_run( $run );
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The report files could not be opened. Check that the uploads directory is writable, then resume.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		if ( $is_first ) {
			// BOM so spreadsheet software reads accented characters correctly.
			fwrite( $changes_handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fputcsv( $changes_handle, array( 'id', 'post_type', 'status', 'title', 'old_slug', 'new_slug', 'old_url', 'new_url', 'note' ), ',', '"', '' );
			// No BOM and no header on the redirect file: some redirect plugins
			// import a header row as a live redirect.
		}

		$log           = array();
		$next_last     = $last_id;
		$batch_changed = 0;
		$batch_errors  = 0;

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

			/**
			 * Filters the title a slug is generated from.
			 *
			 * Runs before sanitize_title(), so a filter may return characters in
			 * any script; the result is sanitised and length-capped afterwards
			 * either way. Returning an empty string makes the run skip the post.
			 *
			 * @since 1.0.0
			 *
			 * @param string $title     Post title as stored.
			 * @param object $row       Row with ID, post_title, post_name, post_status, post_parent.
			 * @param string $post_type Post type being processed.
			 */
			$source = apply_filters( 'slug_sync_source_title', $row->post_title, $row, $post_type );
			$source = is_string( $source ) ? $source : $row->post_title;

			$target = self::cap_length( sanitize_title( $source ) );

			if ( '' === $target || $row->post_name === $target ) {
				continue;
			}

			if ( ! $suffixed && preg_match( '/^' . preg_quote( $target, '/' ) . '-\d+$/', $row->post_name ) ) {
				continue;
			}

			$old_slug = $row->post_name;
			$old_url  = get_permalink( $row->ID );

			$new_slug = self::claim( $target, (int) $row->ID, $row->post_status, $post_type, $row->post_parent, ! $apply, $run_id );

			if ( $new_slug === $old_slug ) {
				continue;
			}

			$note = ( $new_slug !== $target ) ? __( 'duplicate title, suffixed', 'slug-sync' ) : '';

			if ( $apply ) {
				$result = self::write_slug( (int) $row->ID, $new_slug, $quiet, $old_slug, $row->post_status, $post_type );

				if ( true !== $result ) {
					/* translators: 1: post ID, 2: error message. */
					$log[] = sprintf( __( '#%1$d failed: %2$s', 'slug-sync' ), $row->ID, $result );
					$batch_errors++;
					continue;
				}

				$new_url = get_permalink( $row->ID );
			} else {
				$new_url = self::preview_url( $old_url, $old_slug, $new_slug );
			}

			fputcsv(
				$changes_handle,
				array( $row->ID, $post_type, $row->post_status, self::csv_text( $row->post_title ), $old_slug, $new_slug, $old_url, $new_url, $note ),
				',',
				'"',
				''
			);
			$batch_changed++;

			// Only published posts get a redirect. get_permalink() on a draft
			// returns a query-string preview URL, which is junk in a redirect table.
			if ( 'publish' === $row->post_status ) {
				fputcsv( $redirect_handle, array( wp_make_link_relative( $old_url ), wp_make_link_relative( $new_url ) ), ',', '"', '' );
			}

			$log[] = sprintf( '#%d  %s  ->  %s%s', $row->ID, $old_slug, $new_slug, $note ? '   [' . $note . ']' : '' );
		}

		fclose( $changes_handle );  // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $redirect_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$finished = empty( $rows ) || count( $rows ) < $batch;
		$paused   = ! $finished && $pause;

		$run['last_id']           = $next_last;
		$run['done']              = $done;
		$run['total']             = $total;
		$run['changed']           = $changed + $batch_changed;
		$run['errors']            = $errors + $batch_errors;
		$run['sig_code']          = $sig_code;
		$run['sig_stopword']      = $sig_stopword;
		$run['sig_non_latin']     = $sig_non_latin;
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

		self::save_run( $run );

		if ( $finished ) {
			self::clear_active_run( $run_id );
			self::reset_claims( $run_id );
		} else {
			// One write per batch rather than one per post; see flush_claims().
			self::flush_claims( $run_id );
		}

		self::release_lock( $lock_token );

		echo '<div class="slug-sync-card"><h2>';
		echo esc_html( $apply ? __( 'Applying slug changes', 'slug-sync' ) : __( 'Building the preview', 'slug-sync' ) );
		echo '</h2>';
		self::progress_bar( $done, $total, ! $finished && ! $paused );

		if ( $apply ) {
			echo '<p class="description"><strong>' . esc_html__( 'Saving method:', 'slug-sync' ) . '</strong> ' .
				esc_html( $quiet ? __( 'Quiet update', 'slug-sync' ) : __( 'Standard WordPress update', 'slug-sync' ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'This is a preview. No slugs or URLs are being changed.', 'slug-sync' ) . '</p>';
		}

		if ( $log ) {
			echo '<details class="slug-sync-batch-log"><summary>' . esc_html__( 'View items found in this batch', 'slug-sync' ) . '</summary><pre>';
			echo esc_html( implode( "\n", $log ) );
			echo '</pre></details>';
		}

		echo '</div>';

		if ( $finished ) {
			if ( $apply ) {
				echo '<div class="notice notice-success"><p>' .
					esc_html__( 'All selected items are finished. Download the reports below, purge any page/CDN cache, and test several old URLs.', 'slug-sync' ) .
					'</p></div>';
			} else {
				echo '<div class="notice notice-info"><p>' .
					esc_html__( 'Preview complete. Download the changes report below and review the old URL, new URL, and note columns. When it looks correct, go back and choose Apply.', 'slug-sync' ) .
					'</p></div>';
			}

			if ( is_post_type_hierarchical( $post_type ) ) {
				echo '<div class="notice notice-warning"><p>' .
					esc_html__( 'This content type nests inside a parent. WordPress does not redirect its old URLs on its own, so import the redirect report into a redirect plugin. Anything sitting beneath an item whose slug changed also has a new URL, and those are not listed in the reports.', 'slug-sync' ) .
					'</p></div>';
			}

			self::upsell_card( $run );

			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( self::page_url() ),
				esc_html__( 'Back', 'slug-sync' )
			);

			return;
		}

		if ( $paused ) {
			echo '<div class="notice notice-info"><p>' .
				esc_html__( 'The first batch is complete and the run is paused. Download the partial report below, then resume to continue or stop to keep only the completed work.', 'slug-sync' ) .
				'</p></div>';
			self::run_controls( $run );
			return;
		}

		echo '<p class="description">' . esc_html__( 'The next batch will start automatically.', 'slug-sync' ) . '</p>';
		self::run_controls( $run, true );
	}

	/**
	 * Contextual note about what a rules add-on would change in this run.
	 *
	 * Rendered on the plugin's own screen, after a completed run, and only when
	 * the run actually found something. Guideline 11 permits an upsell here; it
	 * does not permit an admin notice, and there is deliberately none.
	 *
	 * @param array $run Completed run record.
	 */
	private static function upsell_card( $run ) {
		$code      = isset( $run['sig_code'] ) ? absint( $run['sig_code'] ) : 0;
		$stopword  = isset( $run['sig_stopword'] ) ? absint( $run['sig_stopword'] ) : 0;
		$non_latin = isset( $run['sig_non_latin'] ) ? absint( $run['sig_non_latin'] ) : 0;

		if ( ! $code && ! $stopword && ! $non_latin ) {
			return;
		}

		if ( has_filter( 'slug_sync_source_title' ) ) {
			return; // A rules add-on is already installed.
		}

		$lines = array();

		if ( $code ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains a product code or SKU.', '%d titles contain a product code or SKU.', $code, 'slug-sync' ), $code );
		}
		if ( $non_latin ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title is written in a non-Latin script.', '%d titles are written in a non-Latin script.', $non_latin, 'slug-sync' ), $non_latin );
		}
		if ( $stopword ) {
			/* translators: %d: number of titles. */
			$lines[] = sprintf( _n( '%d title contains common filler words.', '%d titles contain common filler words.', $stopword, 'slug-sync' ), $stopword );
		}

		echo '<div class="slug-sync-card"><h2>' . esc_html__( 'About these titles', 'slug-sync' ) . '</h2><ul>';

		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}

		echo '</ul><p class="description">' .
			esc_html__( 'Slug Sync builds each slug from the title exactly as WordPress would. Slug Sync Pro, a separate add-on still in development, adds rules that rewrite the title first, so codes and filler words never reach the URL and non-Latin titles are transliterated rather than percent-encoded. It also syncs category and tag slugs, which this plugin leaves alone.', 'slug-sync' ) .
			'</p><p><a class="button" href="' . esc_url( 'https://slugsync.com/#pricing' ) . '" target="_blank" rel="noopener noreferrer">' .
			esc_html(
				sprintf(
					/* translators: %s: Pro price, for example $79.99. */
					__( 'Slug Sync Pro — coming soon, %s at launch', 'slug-sync' ),
					self::PRO_PRICE
				)
			) .
			'</a></p></div>';
	}

	/**
	 * Cancel an unfinished run while retaining its partial reports.
	 */
	private static function cancel_run() {
		// Nonce is verified by render() before this method runs.
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$run    = self::get_run( $run_id );

		if ( ! $run || self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run is not available to stop.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( 'cancel-' . $run_id );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'A batch is still processing. Wait for it to finish before stopping the run.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		$run = self::get_run( $run_id );

		if ( $run && ! self::run_is_finished( $run ) ) {
			$run['status']       = 'canceled';
			$run['updated_at']   = time();
			$run['completed_at'] = time();
			self::save_run( $run );
			self::clear_active_run( $run_id );
			self::reset_claims( $run_id );
		}

		self::release_lock( $lock_token );
		echo '<div class="notice notice-success"><p>' .
			esc_html__( 'Run stopped. Work already completed was kept, and the partial reports remain available under Previous runs.', 'slug-sync' ) .
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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The selected apply run is not available.', 'slug-sync' ) . '</p></div>';
			return;
		}

		if ( $run && ! self::run_is_finished( $run ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Stop the active run before undoing its changes.', 'slug-sync' ) . '</p></div>';
			return;
		}

		if ( $run && isset( $run['status'] ) && 'rolled_back' === $run['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This run has already been undone.', 'slug-sync' ) . '</p></div>';
			return;
		}

		if ( self::active_run() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Finish or stop the active run before undoing another run.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$file = self::report_path( $run_id, 'changes' );

		if ( ! file_exists( $file ) ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'No changes report was found for this undo.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		$lock_token = self::acquire_lock( 'rollback-' . ( $run_id ? $run_id : 'legacy' ) );

		if ( ! $lock_token ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another Slug Sync operation is still running. Try again shortly.', 'slug-sync' ) . '</p></div>';
			return;
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			self::release_lock( $lock_token );
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'The report could not be read.', 'slug-sync' ) .
				'</p></div>';
			return;
		}

		fgetcsv( $handle, 0, ',', '"', '' ); // Header row.

		$restored = 0;
		$skipped  = 0;
		$log      = array();

		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {

			if ( count( $row ) < 6 ) {
				continue;
			}

			$post_id   = absint( $row[0] );
			$post_type = sanitize_key( $row[1] );
			$status    = sanitize_key( $row[2] );
			$old_slug  = $row[4];
			$new_slug  = $row[5];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );

			if ( null === $current ) {
				$skipped++;
				continue;
			}

			if ( $current !== $new_slug ) {
				$skipped++;
				/* translators: 1: post ID, 2: current slug, 3: expected slug. */
				$log[] = sprintf( __( '#%1$d skipped, slug is now "%2$s" rather than "%3$s"', 'slug-sync' ), $post_id, $current, $new_slug );
				continue;
			}

			// The slug being retired here is the one the run created, so record it.
			$result = self::write_slug( $post_id, $old_slug, true, $new_slug, $status, $post_type );

			if ( true === $result ) {
				$restored++;
			} else {
				/* translators: 1: post ID, 2: error message. */
				$log[] = sprintf( __( '#%1$d failed: %2$s', 'slug-sync' ), $post_id, $result );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $run ) {
			$run['status']            = 'rolled_back';
			$run['updated_at']        = time();
			$run['rolled_back_at']    = time();
			$run['rollback_restored'] = $restored;
			$run['rollback_skipped']  = $skipped;
			self::save_run( $run );
		}

		self::release_lock( $lock_token );

		echo '<div class="notice notice-success"><p>';
		printf(
			/* translators: 1: number restored, 2: number skipped. */
			esc_html__( 'Undo finished: restored %1$d slugs and skipped %2$d items that were missing or had changed since the run.', 'slug-sync' ),
			(int) $restored,
			(int) $skipped
		);
		echo '</p></div>';

		if ( $log ) {
			echo '<pre style="max-height:300px;overflow:auto;background:#fff;padding:12px;border:1px solid #ccd0d4;">';
			echo esc_html( implode( "\n", $log ) );
			echo '</pre>';
		}
	}
}

Slug_Sync::init();
