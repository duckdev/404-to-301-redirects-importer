<?php
/**
 * Import-source contract.
 *
 * Each "source" knows how to detect whether its underlying data store
 * is present (an installed plugin's table / option / CPT, or an
 * uploaded CSV stashed under a token) and yields shaped rows that the
 * Importer can write through the parent's Redirects model.
 *
 * Adding a new source is a 1-class affair: implement this interface
 * and register the class with {@see Registry::add()} (or hook the
 * `404_to_301_redirects_importer_sources` filter from a third-party plugin).
 *
 * @package DuckDev\FourNotFour\RedirectsImporter\Sources
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter\Sources;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Interface Source
 *
 * @since 1.0.0
 */
interface Source {

	/**
	 * Stable identifier used on the wire (REST payload, JS state).
	 *
	 * Should be a short, ASCII slug. Stays stable across versions so
	 * stored UI preferences / saved jobs keep working.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable name for the picker (translated).
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the underlying data store is present on this site.
	 *
	 * Detection should be fast (one option read, one `SHOW TABLES LIKE`,
	 * one `post_type_exists()` call) — `Registry::available()` calls
	 * this on every preview request and we don't want to slow the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Total number of rows the source can offer.
	 *
	 * Used by the UI to show "We found N redirects to import". Cached
	 * results are fine — exact accuracy isn't important here.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int;

	/**
	 * Yield a batch of mapped rows ready for the Importer.
	 *
	 * Each yielded row should already be shaped in our column space —
	 * keys matching what `RedirectsModel::create()` accepts (`source`,
	 * `target_url`, `match_type`, `target_type`, `redirect_type`,
	 * `is_active`, `query_handling`, `notes`, `target_page_id`).
	 *
	 * Rows that the source can't map (conditional matches, pass-through
	 * actions, etc.) should be skipped here and counted via
	 * {@see Source::skip_summary()} so the UI can surface the reason
	 * to the user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Row offset within the source's natural order.
	 * @param int $limit  Maximum rows to return in this batch.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function read( int $offset, int $limit ): iterable;

	/**
	 * Optional: summary of rows the source declined to map.
	 *
	 * Returned as a flat array of human-readable reason strings, one
	 * per skipped row encountered so far. The Importer concatenates
	 * this with its own per-row errors in the final summary so the
	 * user sees both "could not write row 12 (invalid regex)" and
	 * "skipped 3 rows: conditional cookie match not supported".
	 *
	 * Implementations are free to return an empty array — the default
	 * Importer fallback is a no-op count.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{row:int,message:string}>
	 */
	public function skip_summary(): array;
}
