<?php
/**
 * Source-agnostic import pipeline.
 *
 * The Importer no longer knows how data was sourced — it just consumes
 * shaped rows from a {@see Sources\Source} instance and writes them
 * through the parent's Redirects model so hashing, audit events and
 * dedupe rules stay consistent with single-row creates.
 *
 * Two entry points:
 *
 *   `preview()`  — read a sample of mapped rows + total counts without
 *                   touching the database. Used by the preview modal.
 *
 *   `run_batch()` — process one offset/limit slice and report what
 *                   happened. The REST layer drives the loop; the
 *                   client polls between batches so the UI can render
 *                   a progress bar.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter;

use DuckDev\FourNotFour\Database\Queries\Redirect as RedirectQuery;
use DuckDev\FourNotFour\Database\Rows\Redirect as RedirectRow;
use DuckDev\FourNotFour\Models\Redirects as RedirectsModel;
use DuckDev\FourNotFour\RedirectsImporter\Sources\Source;
use DuckDev\FourNotFour\Utils\Helpers;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Class Importer
 *
 * @since 1.0.0
 */
final class Importer {

	/**
	 * Number of sample rows returned in a preview response.
	 *
	 * Small enough that the modal stays scannable and the response
	 * payload stays under a few KB even with a long `notes` column.
	 *
	 * @since 1.0.0
	 */
	const PREVIEW_SAMPLE_SIZE = 5;

	/**
	 * Dry-run summary used by the preview modal.
	 *
	 * Walks the source once over the first N rows, classifies each as
	 * "importable" or "skipped" (without writing anything), and
	 * returns the resulting counts + sample for display.
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source Source to inspect.
	 *
	 * @return array{
	 *   total:int,
	 *   importable:int,
	 *   skipped:int,
	 *   sample:array<int,array<string,mixed>>,
	 *   errors:array<int,array{row:int,message:string}>
	 * }
	 */
	public function preview( Source $source ): array {
		$total      = $source->count();
		$importable = 0;
		$skipped    = 0;
		$sample     = array();
		$errors     = array();

		// Cap the dry-run at a manageable slice. On a 100k-row source
		// counting every importable vs. skipped row up-front is more
		// work than the user needs to make a decision — they care
		// about "does this look right?" plus the totals.
		$cap = (int) min( $total > 0 ? $total : 1000, 1000 );

		foreach ( $source->read( 0, $cap ) as $row ) {
			$error = $this->validate( $row );

			if ( null !== $error ) {
				++$skipped;
				$errors[] = array(
					'row'     => (int) ( $row['_csv_row'] ?? 0 ),
					'message' => $error,
				);
				continue;
			}

			++$importable;
			if ( count( $sample ) < self::PREVIEW_SAMPLE_SIZE ) {
				$sample[] = $this->shape_sample( $row );
			}
		}

		// Merge in source-side skip reasons (eg. Redirection's
		// conditional matches) so the UI's "needs attention" list is
		// the complete story, not just our validator's contribution.
		foreach ( $source->skip_summary() as $skip ) {
			++$skipped;
			$errors[] = $skip;
		}

		return array(
			'total'      => $total,
			'importable' => $importable,
			'skipped'    => $skipped,
			'sample'     => $sample,
			'errors'     => $errors,
		);
	}

	/**
	 * Process one offset/limit batch.
	 *
	 * Returns a per-batch summary; the REST layer accumulates these
	 * client-side as the loop progresses so the UI can render a
	 * progress bar.
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source          Bound source.
	 * @param int    $offset          Row offset.
	 * @param int    $limit           Max rows in this batch.
	 * @param bool   $update_existing Overwrite an existing row when
	 *                                its `source_hash` collides.
	 *
	 * @return array{
	 *   created:int,
	 *   updated:int,
	 *   skipped:int,
	 *   processed:int,
	 *   errors:array<int,array{row:int,message:string}>
	 * }
	 */
	public function run_batch( Source $source, int $offset, int $limit, bool $update_existing ): array {
		$summary = array(
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'processed' => 0,
			'errors'    => array(),
		);

		if ( ! class_exists( RedirectsModel::class ) ) {
			$summary['errors'][] = array(
				'row'     => 0,
				'message' => __( 'Parent plugin is not active.', '404-to-301-redirects-importer' ),
			);

			return $summary;
		}

		$model = RedirectsModel::instance();

		foreach ( $source->read( $offset, $limit ) as $row ) {
			++$summary['processed'];

			$error = $this->validate( $row );
			if ( null !== $error ) {
				++$summary['skipped'];
				$summary['errors'][] = array(
					'row'     => (int) ( $row['_csv_row'] ?? 0 ),
					'message' => $error,
				);
				continue;
			}

			// Internal hints we use for validation/dedupe but the model
			// doesn't accept — strip them before the write so they
			// don't end up in `wpdb->insert()`'s column list.
			$row_id = (int) ( $row['_csv_row'] ?? 0 );
			unset( $row['_csv_row'] );

			$existing = $this->find_existing(
				(string) $row['source'],
				(string) ( $row['query_handling'] ?? 'ignore' )
			);

			if ( $existing instanceof RedirectRow ) {
				if ( ! $update_existing ) {
					++$summary['skipped'];
					continue;
				}

				if ( $model->update( (int) $existing->id, $row ) ) {
					++$summary['updated'];
				} else {
					++$summary['skipped'];
					$summary['errors'][] = array(
						'row'     => $row_id,
						'message' => __( 'Could not update the existing redirect.', '404-to-301-redirects-importer' ),
					);
				}
				continue;
			}

			global $wpdb;
			// Clear any pre-existing error so we can attribute the next
			// one (if any) to this insert specifically.
			$wpdb->last_error = '';

			$id = $model->create( $row );
			if ( $id > 0 ) {
				++$summary['created'];
			} else {
				++$summary['skipped'];

				// Surface the real reason when one is available — a
				// bare "could not create" leaves the user guessing
				// between "duplicate source", "too long", "regex
				// invalid" and so on.
				$db_error = (string) $wpdb->last_error;
				$summary['errors'][] = array(
					'row'     => $row_id,
					'message' => '' !== $db_error
						? sprintf(
							/* translators: %s: database error message. */
							__( 'Could not create the redirect: %s', '404-to-301-redirects-importer' ),
							$db_error
						)
						: __( 'Could not create the redirect (no DB error reported — likely a duplicate source).', '404-to-301-redirects-importer' ),
				);
			}
		}

		// Source-side skip reasons (Redirection's conditional matches,
		// empty targets, etc.) — fold them into this batch's error
		// list so the UI's progress feed reflects them as they arrive.
		foreach ( $source->skip_summary() as $skip ) {
			$summary['skipped'] += 1;
			$summary['errors'][]  = $skip;
		}

		return $summary;
	}

	/**
	 * Validate a shaped row before handing it to the model.
	 *
	 * Returns null on success or a human-readable error string for the
	 * per-row report.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $data Shaped row data.
	 *
	 * @return string|null
	 */
	private function validate( array $data ): ?string {
		if ( empty( $data['source'] ) ) {
			return __( 'Missing `source` value.', '404-to-301-redirects-importer' );
		}

		$target_type = (string) ( $data['target_type'] ?? 'link' );
		$status      = (int) ( $data['redirect_type'] ?? 301 );

		// 410/451 are terminal — they don't need a destination.
		$is_terminal = in_array( $status, array( 410, 451 ), true );

		if ( ! $is_terminal && 'link' === $target_type && empty( $data['target_url'] ) ) {
			return __( '`target_url` is required when target_type is "link".', '404-to-301-redirects-importer' );
		}

		if ( ! $is_terminal && 'page' === $target_type && empty( $data['target_page_id'] ) ) {
			return __( '`target_page_id` is required when target_type is "page".', '404-to-301-redirects-importer' );
		}

		// Regex must be a syntactically valid pattern — otherwise the row
		// would silently never match anything at request time.
		if ( 'regex' === (string) ( $data['match_type'] ?? '' ) ) {
			$pattern = (string) $data['source'];
			$wrapped = ( '' !== $pattern && ( '/' === $pattern[0] || '#' === $pattern[0] ) )
				? $pattern
				: '#' . $pattern . '#';
			// @ — the function emits a warning on bad patterns; we want
			// the boolean return, not the noise.
			if ( false === @preg_match( $wrapped, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return __( '`source` is not a valid regex pattern.', '404-to-301-redirects-importer' );
			}
		}

		return null;
	}

	/**
	 * Look up an existing row with the same `source_hash`.
	 *
	 * Matches the hashing rule used by the model's `create()` — `require`
	 * rows hash with the query string, every other mode hashes path-only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Source URL / pattern.
	 * @param string $mode   `query_handling` value.
	 *
	 * @return RedirectRow|null
	 */
	private function find_existing( string $source, string $mode ): ?RedirectRow {
		if ( ! class_exists( Helpers::class ) || ! class_exists( RedirectQuery::class ) ) {
			return null;
		}

		$hash = 'require' === $mode
			? Helpers::url_hash_with_query( $source )
			: Helpers::url_hash( $source );

		$query = new RedirectQuery(
			array(
				'source_hash' => $hash,
				'number'      => 1,
			)
		);

		$items = (array) $query->items;

		return ! empty( $items ) && $items[0] instanceof RedirectRow ? $items[0] : null;
	}

	/**
	 * Shape a sample row for the preview modal — strips internal hints
	 * and keeps only what's worth showing to the user.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $row Shaped row.
	 *
	 * @return array<string,mixed>
	 */
	private function shape_sample( array $row ): array {
		unset( $row['_csv_row'] );

		return array(
			'source'        => (string) ( $row['source'] ?? '' ),
			'target_url'    => (string) ( $row['target_url'] ?? '' ),
			'match_type'    => (string) ( $row['match_type'] ?? 'exact' ),
			'redirect_type' => (int) ( $row['redirect_type'] ?? 301 ),
			'is_active'     => (int) ( $row['is_active'] ?? 1 ),
		);
	}
}
