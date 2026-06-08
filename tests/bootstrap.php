<?php
/**
 * PHPUnit bootstrap for the addon.
 *
 * Intentionally minimal — at present the test suite contains a single
 * smoke test that doesn't load any plugin code, so the only thing we
 * need here is Composer's autoloader for PHPUnit itself.
 *
 * When real integration tests are added, swap this for a WP-aware
 * bootstrap (eg. one that pulls in `wp-phpunit/wp-phpunit` and calls
 * `tests_add_filter()` to load the plugin's main file under WordPress).
 *
 * @package DuckDev\FourNotFour
 */

declare( strict_types = 1 );

$autoload = __DIR__ . '/../vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "Composer autoloader missing — run `composer install` first.\n" );
	exit( 1 );
}

require_once $autoload;
