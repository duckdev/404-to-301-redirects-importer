<?php
/**
 * "301 Redirects" (WebFactory) source.
 *
 * Reads rules from WebFactory's "301 Redirects" plugin
 * ({@link https://wordpress.org/plugins/eps-301-redirects/}). The
 * plugin folder is `eps-301-redirects` for historical reasons —
 * Scott Nelle (Eric's Plugins Suite) wrote the original, WebFactory
 * acquired it and kept the slug.
 *
 * Storage detected, in priority order:
 *
 *   1. Custom table `{prefix}redirects`
 *      Columns:
 *        - id        mediumint(9)
 *        - url_from  varchar(1024)
 *        - url_to    varchar(1024)
 *        - status    varchar(12)   '301' | '302' | '307' | '404' | …
 *        - type      varchar(12)   'url' | 'post'
 *        - count     mediumint(9)
 *
 *      The table has no enabled/disabled flag — every row is
 *      considered active by the plugin's front-controller, so we
 *      import them with `is_active=1`.
 *
 *   2. Legacy option `eps_redirects` (pre-v2 storage)
 *      Associative array `[from => to]`. Old installs that never
 *      ran the v2 migration still keep their rules here; we map
 *      everything as exact, 301, `link` targets.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter\Sources
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter\Sources;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Class WebFactory_301_Source
 *
 * @since 1.0.0
 */
final class WebFactory_301_Source implements Source {

	/**
	 * Detected storage mode — `table`, `option`, or empty when absent.
	 *
	 * Cached so `is_available()` / `count()` / `read()` share one
	 * detection call per request.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private $mode = null;

	/**
	 * Cached rows when reading from the legacy option layout.
	 *
	 * The option holds the entire ruleset in memory anyway, so we pay
	 * the unserialise cost once and slice it for each `read()` batch.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,array{from:string,to:string}>|null
	 */
	private $option_rows = null;

	/**
	 * Accumulated per-row skip messages from the most recent `read()`.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int,array{row:int,message:string}>
	 */
	private $skips = array();

	/**
	 * Source id used on the wire and as the React state key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'webfactory-301';
	}

	/**
	 * Human-readable name shown in the picker.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function label(): string {
		return __( '301 Redirects – Redirect Manager (WebFactory)', '404-to-301-redirects-importer' );
	}

	/**
	 * Whether either supported storage layout is present on the site.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->detect_mode();
	}

	/**
	 * Total number of redirects in whichever storage layout is in use.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		$mode = $this->detect_mode();
		if ( '' === $mode ) {
			return 0;
		}

		if ( 'table' === $mode ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}redirects`" );
		}

		return count( $this->load_option_rows() );
	}

	/**
	 * Yield one batch of mapped rows from the detected storage.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Row offset.
	 * @param int $limit  Maximum rows per batch.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	public function read( int $offset, int $limit ): iterable {
		$mode = $this->detect_mode();
		if ( '' === $mode ) {
			return;
		}

		$this->skips = array();

		if ( 'table' === $mode ) {
			yield from $this->read_table( $offset, $limit );
			return;
		}

		yield from $this->read_option( $offset, $limit );
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
	 * Probe the two known storage layouts.
	 *
	 * Result is cached on the instance — `Registry::all()` keeps each
	 * source as a singleton so this only runs once per request.
	 *
	 * The plugin folder may be `eps-301-redirects/` (legacy slug, what
	 * the wp.org listing installs as) — but the data layout we care
	 * about is identified by table / option presence, not by the
	 * folder name, so plugin-folder probing isn't needed.
	 *
	 * @since 1.0.0
	 *
	 * @return string `table` | `option` | `''` (none).
	 */
	private function detect_mode(): string {
		if ( null !== $this->mode ) {
			return $this->mode;
		}

		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'redirects' );
		// Detection runs once per request and the schema doesn't change
		// often enough to warrant an object-cache round-trip — the cost
		// of a cache miss + warm is higher than the bare `SHOW TABLES`.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $found === $table ) {
			// Be a little paranoid — the `{prefix}redirects` table
			// name is generic and could in theory be created by some
			// other plugin. Confirm by checking the column shape
			// before claiming the source.
			// `prepare()` doesn't support table-name placeholders, so the
			// interpolation is intentional and the sniff is silenced.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$columns = $wpdb->get_col( "DESC `{$table}`", 0 );
			if ( is_array( $columns ) && in_array( 'url_from', $columns, true ) && in_array( 'url_to', $columns, true ) ) {
				$this->mode = 'table';
				return $this->mode;
			}
		}

		// `get_option` returns `false` when missing — a truthy array
		// means the legacy option layout is in play.
		$option = get_option( 'eps_redirects', false );
		if ( is_array( $option ) && ! empty( $option ) ) {
			$this->mode = 'option';
			return $this->mode;
		}

		$this->mode = '';
		return $this->mode;
	}

	/**
	 * Read a batch from the modern, table-based layout.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Row offset.
	 * @param int $limit  Max rows.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	private function read_table( int $offset, int $limit ): iterable {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url_from, url_to, status, type
				 FROM {$wpdb->prefix}redirects
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$mapped = $this->map_table_row( $row );
			if ( null === $mapped ) {
				continue;
			}
			yield $mapped;
		}
	}

	/**
	 * Read a slice from the legacy option-based layout.
	 *
	 * The option stores every rule in one array, so we materialise the
	 * full list once and slice from it — no per-batch DB hit.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Row offset.
	 * @param int $limit  Max rows.
	 *
	 * @return iterable<int,array<string,mixed>>
	 */
	private function read_option( int $offset, int $limit ): iterable {
		$rows  = $this->load_option_rows();
		$slice = array_slice( $rows, $offset, $limit );

		foreach ( $slice as $i => $row ) {
			$source = trim( (string) $row['from'] );
			$target = trim( (string) $row['to'] );

			if ( '' === $source ) {
				$this->skip( $offset + $i, __( 'Skipped: source path is empty.', '404-to-301-redirects-importer' ) );
				continue;
			}

			if ( '' === $target ) {
				$this->skip( $offset + $i, __( 'Skipped: target URL is empty.', '404-to-301-redirects-importer' ) );
				continue;
			}

			$out             = array(
				'source'        => sanitize_text_field( $source ),
				'target_url'    => sanitize_text_field( $target ),
				'target_type'   => 'link',
				'match_type'    => 'exact',
				// Legacy storage doesn't record a code — the plugin
				// always treated these as 301 in its front-controller.
				'redirect_type' => 301,
				'is_active'     => 1,
				'notes'         => $this->build_note(),
			);
			$out['_csv_row'] = $offset + $i;
			yield $out;
		}
	}

	/**
	 * Materialise + cache the option-based ruleset.
	 *
	 * Normalises the raw `[from => to]` map into a positional list so
	 * `read_option()` can slice it by offset without surprises around
	 * numeric-looking string keys (PHP would re-key them silently).
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{from:string,to:string}>
	 */
	private function load_option_rows(): array {
		if ( null !== $this->option_rows ) {
			return $this->option_rows;
		}

		$raw = get_option( 'eps_redirects', array() );

		$list = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $from => $to ) {
				$list[] = array(
					'from' => (string) $from,
					'to'   => (string) $to,
				);
			}
		}

		$this->option_rows = $list;

		return $this->option_rows;
	}

	/**
	 * Translate one table row into our column shape.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $row Raw row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function map_table_row( array $row ): ?array {
		$source_id = (int) ( $row['id'] ?? 0 );
		$source    = trim( (string) ( $row['url_from'] ?? '' ) );
		$target    = trim( (string) ( $row['url_to'] ?? '' ) );
		$status    = (string) ( $row['status'] ?? '301' );
		$type      = strtolower( (string) ( $row['type'] ?? 'url' ) );

		if ( '' === $source ) {
			$this->skip( $source_id, __( 'Skipped: source path is empty.', '404-to-301-redirects-importer' ) );
			return null;
		}

		// `status='404'` means "respond 404 to this URL" — semantically
		// the same as "URL is gone", which our model expresses as 410.
		// Map onto 410 so crawlers get the cleaner signal.
		if ( '404' === $status ) {
			$out             = array(
				'source'        => sanitize_text_field( $source ),
				'target_type'   => 'none',
				'match_type'    => 'exact',
				'redirect_type' => 410,
				'is_active'     => 1,
				'notes'         => $this->build_note(),
			);
			$out['_csv_row'] = $source_id;
			return $out;
		}

		if ( '' === $target ) {
			$this->skip( $source_id, __( 'Skipped: target URL is empty.', '404-to-301-redirects-importer' ) );
			return null;
		}

		// `type='post'` rows store the post ID in `url_to`. Resolve
		// the permalink up-front so the imported row works even if
		// the source plugin is later deactivated. If the post is
		// missing we skip the row rather than create a redirect to
		// nowhere.
		$target_type = 'link';
		if ( 'post' === $type && is_numeric( $target ) ) {
			$permalink = get_permalink( (int) $target );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				$this->skip(
					$source_id,
					__( 'Skipped: linked post is missing.', '404-to-301-redirects-importer' )
				);
				return null;
			}
			$target = $permalink;
		}

		$code = (int) $status;

		$out             = array(
			'source'        => sanitize_text_field( $source ),
			'target_url'    => sanitize_text_field( $target ),
			'target_type'   => $target_type,
			'match_type'    => 'exact',
			'redirect_type' => in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ? $code : 301,
			'is_active'     => 1,
			'notes'         => $this->build_note(),
		);
		$out['_csv_row'] = $source_id;

		return $out;
	}

	/**
	 * Standard `notes` tag so the redirect's origin stays traceable.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function build_note(): string {
		return '[Imported from 301 Redirects]';
	}

	/**
	 * Record a per-row skip reason for the final UI summary.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $row     Source row id.
	 * @param string $message Human-readable reason.
	 *
	 * @return void
	 */
	private function skip( int $row, string $message ): void {
		$this->skips[] = array(
			'row'     => $row,
			'message' => $message,
		);
	}
}
