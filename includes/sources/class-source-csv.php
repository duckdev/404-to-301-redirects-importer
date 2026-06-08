<?php
/**
 * CSV upload source.
 *
 * The user uploads a file via `POST /imports/upload`, which stashes it
 * under a token and stores the path in a per-user transient. This
 * source then opens the stashed file and yields shaped rows the same
 * way the original CSV importer did — keeping the existing column
 * vocabulary and aliases.
 *
 * The token model means we get preview + chunked-run for free without
 * the user re-uploading the file between the two phases.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter\Sources
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter\Sources;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Class CSV_Source
 *
 * @since 1.0.0
 */
final class CSV_Source implements Source {

	/**
	 * Hard ceiling on rows we'll process from a single CSV.
	 *
	 * Mirrors the cap the original Importer used — prevents a runaway
	 * upload from holding a PHP worker hostage.
	 *
	 * @since 1.0.0
	 */
	const MAX_ROWS = 100000;

	/**
	 * Recognised header → canonical column key map.
	 *
	 * Looked up case-insensitively so `Source` / `SOURCE` / `source`
	 * all resolve to the same field. Unknown headers are ignored — the
	 * importer is intentionally permissive about extra columns so a
	 * round-tripped export from another tool doesn't have to be trimmed
	 * before re-import.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string,string>
	 */
	private const HEADERS = array(
		'source'         => 'source',
		'target'         => 'target_url',
		'target_url'     => 'target_url',
		'destination'    => 'target_url',
		'match_type'     => 'match_type',
		'target_type'    => 'target_type',
		'target_page_id' => 'target_page_id',
		'page_id'        => 'target_page_id',
		'redirect_type'  => 'redirect_type',
		'status_code'    => 'redirect_type',
		'is_active'      => 'is_active',
		'active'         => 'is_active',
		'enabled'        => 'is_active',
		'query_handling' => 'query_handling',
		'notes'          => 'notes',
		'note'           => 'notes',
	);

	/**
	 * Path to the CSV file backing this instance.
	 *
	 * `null` until {@see self::bind()} is called. The Registry exposes
	 * a singleton instance for source-listing purposes; the REST layer
	 * clones it and calls `bind()` for per-request reads.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private $path = null;

	/**
	 * Accumulated per-row skip messages.
	 *
	 * Populated as {@see self::read()} iterates the file. The REST
	 * layer reads this at the end via {@see self::skip_summary()} and
	 * folds it into the per-row error list shown in the UI.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,array{row:int,message:string}>
	 */
	private $skips = array();

	/**
	 * Bind this source to a specific uploaded file.
	 *
	 * Returns `$this` for fluent chaining at the REST layer.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path to the stashed CSV.
	 *
	 * @return self
	 */
	public function bind( string $path ): self {
		$this->path  = $path;
		$this->skips = array();

		return $this;
	}

	/**
	 * Source id used on the wire and as the React state key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'csv';
	}

	/**
	 * Human-readable name shown in the picker.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'CSV upload', '404-to-301-redirects-importer' );
	}

	/**
	 * CSV is always available — the user uploads the file inline.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Count data rows in the bound file.
	 *
	 * Cheap line-count via `fgets()` — we don't parse the CSV here,
	 * just count newlines for the UI's progress meter.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		if ( null === $this->path || ! is_readable( $this->path ) ) {
			return 0;
		}

		// `WP_Filesystem` is the wrong tool here — counting rows in a
		// multi-megabyte CSV through a Filesystem abstraction would
		// load the file into memory first. We need to stream.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( $this->path, 'r' );
		if ( false === $handle ) {
			return 0;
		}

		$lines = 0;
		while ( ! feof( $handle ) ) {
			$buffer = fgets( $handle, 1024 * 64 );
			if ( false === $buffer ) {
				break;
			}
			$lines += substr_count( $buffer, "\n" );
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Subtract 1 for the header row, clamp to 0+ for files that
		// don't end on a newline (single-row files would otherwise
		// return -1).
		return max( 0, $lines - 1 );
	}

	/**
	 * Yield one batch of mapped rows from the stashed CSV.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Row offset within the data rows.
	 * @param int $limit  Maximum rows in this batch.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function read( int $offset, int $limit ): iterable {
		if ( null === $this->path || ! is_readable( $this->path ) ) {
			return;
		}

		// Streaming read — see `count()` for why `WP_Filesystem` would
		// be the wrong tool here.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( $this->path, 'r' );
		if ( false === $handle ) {
			return;
		}

		// Strip an optional UTF-8 BOM so the first header doesn't end
		// up as `﻿source` — Excel adds one on Save-As-CSV.
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		$headers = $this->read_headers( $handle );
		if ( empty( $headers ) || ! in_array( 'source', $headers, true ) ) {
			fclose( $handle );
			return;
		}

		$data_row = 0;
		$emitted  = 0;
		// Header row was already consumed; data rows are 1-indexed
		// inside this loop and presented to the user as "row N+1" to
		// match what they'd see in Excel (where the header is row 1).
		$csv_row = 1;

		// `$values = fgetcsv(...)` is the idiomatic CSV read loop; the
		// assignment-in-condition warning is a false positive here.
		while ( ( $values = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$csv_row;

			// Skip blank lines — fgetcsv returns `[ null ]` for them.
			if ( 1 === count( $values ) && ( null === $values[0] || '' === $values[0] ) ) {
				continue;
			}

			if ( $data_row >= self::MAX_ROWS ) {
				$this->skips[] = array(
					'row'     => $csv_row,
					/* translators: %d: maximum row count. */
					'message' => sprintf( __( 'Stopped after %d rows. Split the file and re-import.', '404-to-301-redirects-importer' ), self::MAX_ROWS ),
				);
				break;
			}

			if ( $data_row < $offset ) {
				++$data_row;
				continue;
			}

			++$data_row;
			$row             = $this->shape_row( $headers, $values );
			$row['_csv_row'] = $csv_row;
			yield $row;

			++$emitted;
			if ( $emitted >= $limit ) {
				break;
			}
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Per-row skip reasons accumulated by the most recent read() pass.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{row:int,message:string}>
	 */
	public function skip_summary(): array {
		return $this->skips;
	}

	/**
	 * Read the header row and translate each column to its canonical key.
	 *
	 * Unknown columns map to an empty string so {@see self::shape_row()}
	 * can skip them by index without a second lookup.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open file handle positioned at row 0.
	 *
	 * @return array<int,string>
	 */
	private function read_headers( $handle ): array {
		$raw = fgetcsv( $handle );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$headers = array();
		foreach ( $raw as $col ) {
			$key       = strtolower( trim( (string) $col ) );
			$headers[] = self::HEADERS[ $key ] ?? '';
		}

		return $headers;
	}

	/**
	 * Turn one CSV row into a column => value map the model can accept.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,string> $headers Canonical column key per CSV index.
	 * @param array<int,string> $values  Raw cell values for this row.
	 *
	 * @return array<string,mixed>
	 */
	private function shape_row( array $headers, array $values ): array {
		$row = array();

		foreach ( $headers as $i => $key ) {
			if ( '' === $key ) {
				continue;
			}

			$value = isset( $values[ $i ] ) ? trim( (string) $values[ $i ] ) : '';
			if ( '' === $value ) {
				continue;
			}

			switch ( $key ) {
				case 'source':
				case 'target_url':
				case 'notes':
					$row[ $key ] = sanitize_text_field( $value );
					break;

				case 'match_type':
					$lower       = strtolower( $value );
					$row[ $key ] = in_array( $lower, array( 'exact', 'prefix', 'regex' ), true ) ? $lower : 'exact';
					break;

				case 'target_type':
					$lower       = strtolower( $value );
					$row[ $key ] = in_array( $lower, array( 'link', 'page', 'none' ), true ) ? $lower : 'link';
					break;

				case 'query_handling':
					$lower       = strtolower( $value );
					$row[ $key ] = in_array( $lower, array( 'ignore', 'preserve', 'require' ), true ) ? $lower : 'ignore';
					break;

				case 'target_page_id':
					$row[ $key ] = (int) $value;
					break;

				case 'redirect_type':
					$code        = (int) $value;
					$row[ $key ] = in_array( $code, array( 301, 302, 303, 307, 308, 410, 451 ), true ) ? $code : 301;
					break;

				case 'is_active':
					$row[ $key ] = $this->parse_bool( $value ) ? 1 : 0;
					break;
			}
		}

		// Apply the same create-time defaults the REST endpoint uses so
		// imports and single-row creates produce identical rows.
		$row['match_type']    = $row['match_type'] ?? 'exact';
		$row['target_type']   = $row['target_type'] ?? 'link';
		$row['redirect_type'] = $row['redirect_type'] ?? 301;
		$row['is_active']     = $row['is_active'] ?? 1;

		// Terminal status codes don't need a target — flip the type so
		// the Importer's validator stops asking for one.
		if ( in_array( (int) $row['redirect_type'], array( 410, 451 ), true ) ) {
			$row['target_type'] = 'none';
		}

		return $row;
	}

	/**
	 * Parse a permissive boolean from a CSV cell.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw cell.
	 *
	 * @return bool
	 */
	private function parse_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'y', 'on' ), true );
	}
}
