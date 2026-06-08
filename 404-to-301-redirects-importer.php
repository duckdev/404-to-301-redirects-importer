<?php
/**
 * Plugin Name:       404 to 301 - Redirects Importer
 * Description:       Bulk-import custom redirects from a CSV file. Adds an import panel to the Tools tab of the 404 to 301 settings page.
 * Version:           1.0.0
 * Author:            Joel James
 * Author URI:        https://duckdev.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       404-to-301-redirects-importer
 * Requires Plugins:  404-to-301
 * Requires PHP:      7.4
 * Requires at least: 6.4
 *
 * Light-weight addon for the {@see https://wordpress.org/plugins/404-to-301/ 404 to 301}
 * plugin: lets the user bulk-load a CSV of redirects through the Tools
 * tab. The CSV is parsed server-side and rows are written through the
 * parent's Redirects model so hashing, audit events and dedupe rules
 * stay consistent with single-row creates.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter
 */

declare( strict_types = 1 );

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/*
 * Plugin constants.
 *
 * Defined once here so every class can read the addon's version + paths
 * without re-deriving them.
 */

// Plugin version (kept in sync with the `Version:` header above).
const D404_REDIRECTS_IMPORTER_VERSION = '1.0.0';

// Absolute path to this bootstrap file.
define( 'D404_REDIRECTS_IMPORTER_FILE', __FILE__ );

// Absolute plugin directory path (with a trailing slash).
define( 'D404_REDIRECTS_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL (with a trailing slash).
define( 'D404_REDIRECTS_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

/*
 * Class loader.
 *
 * The addon is small enough that wiring up Composer would be heavier
 * than the code it autoloads — a hand-written loader for our handful
 * of `class-*.php` files keeps the addon dependency-free while still
 * following the parent plugin's file-naming convention.
 */
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/sources/interface-source.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/sources/class-source-csv.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/sources/class-source-redirection.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/sources/class-source-webfactory-301.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/sources/class-registry.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/class-plugin.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/class-assets.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/class-importer.php';
require_once D404_REDIRECTS_IMPORTER_DIR . 'includes/class-api.php';

/*
 * Boot on `404_to_301_init` so the parent's models, helpers and REST
 * namespace are guaranteed to be loaded before we wire our own hooks
 * up against them.
 */
add_action(
	'404_to_301_init',
	array( \DuckDev\FourNotFour\RedirectsImporter\Plugin::class, 'boot' )
);
