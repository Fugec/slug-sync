<?php
/**
 * Removes everything Slug Sync stored.
 *
 * @package Slug_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$slug_sync_token = get_option( 'slug_sync_token' );
$slug_sync_runs  = get_option( 'slug_sync_runs', array() );

if ( is_array( $slug_sync_runs ) ) {
	foreach ( array_keys( $slug_sync_runs ) as $slug_sync_run_id ) {
		$slug_sync_run_id = sanitize_key( $slug_sync_run_id );

		if ( $slug_sync_run_id ) {
			delete_transient( 'slug_sync_claimed_' . $slug_sync_run_id );
		}
	}
}

if ( $slug_sync_token ) {
	$slug_sync_uploads = wp_upload_dir();
	$slug_sync_dir     = trailingslashit( $slug_sync_uploads['basedir'] ) . 'slug-sync-' . $slug_sync_token;

	if ( is_dir( $slug_sync_dir ) ) {
		/*
		 * Match the reports on disk rather than rebuilding their names from the
		 * run records. A run record that was pruned, or a report left by a run
		 * whose record never saved, would otherwise strand its CSV and leave the
		 * directory behind for good.
		 */
		$slug_sync_files = glob( trailingslashit( $slug_sync_dir ) . 'slug-*.csv' );
		$slug_sync_files = is_array( $slug_sync_files ) ? $slug_sync_files : array();

		$slug_sync_files[] = trailingslashit( $slug_sync_dir ) . 'index.html';
		$slug_sync_files[] = trailingslashit( $slug_sync_dir ) . '.htaccess';

		foreach ( $slug_sync_files as $slug_sync_path ) {
			if ( is_file( $slug_sync_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $slug_sync_path );
			}
		}

		/*
		 * Only remove the directory once it is genuinely empty. rmdir() warns
		 * otherwise, and silencing that warning with @ hides a real leftover
		 * instead of reporting it.
		 */
		$slug_sync_left = scandir( $slug_sync_dir );

		if ( is_array( $slug_sync_left ) && ! array_diff( $slug_sync_left, array( '.', '..' ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $slug_sync_dir );
		}
	}
}

delete_option( 'slug_sync_token' );
delete_option( 'slug_sync_runs' );
delete_option( 'slug_sync_active_run' );
delete_option( 'slug_sync_lock' );
delete_transient( 'slug_sync_claimed' );
