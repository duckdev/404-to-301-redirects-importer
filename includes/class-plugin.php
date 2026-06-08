<?php
/**
 * Addon bootstrap.
 *
 * Wires the Assets and Api subsystems together once the parent
 * plugin's Core has finished booting.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Guard against double-booting.
	 *
	 * `404_to_301_init` only fires once in a normal request, but the
	 * parent does emit it again from CLI bootstraps and integration
	 * tests, and double-registering hooks here would attach the
	 * REST handler twice.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Wire each subsystem's hooks.
	 *
	 * Each class owns its own `register()` so the boot sequence here
	 * stays a flat, readable list of subsystems.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		Assets::instance()->register();
		Api::instance()->register();

		self::$booted = true;
	}
}
