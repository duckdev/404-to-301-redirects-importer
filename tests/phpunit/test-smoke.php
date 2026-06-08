<?php
/**
 * Smoke test — keeps the CI phpunit job green until real tests land.
 *
 * Add real unit/integration tests alongside this one. When the suite
 * grows to a non-trivial size, this placeholder can be deleted.
 *
 * @package DuckDev\FourNotFour
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Class SmokeTest
 */
final class SmokeTest extends TestCase {

	/**
	 * Sanity check that phpunit + the addon's composer install are working.
	 */
	public function test_phpunit_is_alive(): void {
		$this->assertTrue( true );
	}
}
