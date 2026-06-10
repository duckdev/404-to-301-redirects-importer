<?php
/**
 * Addon uninstall handler.
 *
 * Runs when the user clicks "Delete" on the addon in
 * `wp-admin/plugins.php`. Cleans up the CSV-upload transients the
 * importer parks in `wp_options` while a two-step CSV import is in
 * progress. Each transient self-expires after an hour, but we'd
 * rather not leave them around for the next admin to puzzle over.
 *
 * The importer doesn't own any keys in the parent's
 * `404_to_301_settings` option (it has no class-settings.php), so
 * there's nothing to strip there.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// CSV-upload transients (`d404_csv_<token>`) — the WP-options keys
// are prefixed with `_transient_` / `_transient_timeout_`. The prefix
// + token shape is owned by `class-api.php::CSV_TOKEN_PREFIX`; keep
// the LIKE pattern in sync if that constant ever changes.
//
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_d404_csv_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_d404_csv_' ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
