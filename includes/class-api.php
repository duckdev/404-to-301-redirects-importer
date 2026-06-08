<?php
/**
 * Import REST endpoints.
 *
 * Five routes drive the UI's two-phase, chunked import flow:
 *
 *   GET    /imports/sources              — list available sources +
 *                                          their row counts.
 *
 *   POST   /imports/upload               — stash an uploaded CSV under
 *                                          a token so subsequent
 *                                          preview/run calls can find
 *                                          it without re-uploading.
 *
 *   POST   /imports/preview              — dry-run the source (or the
 *                                          CSV referenced by a token)
 *                                          and return counts + a
 *                                          handful of sample rows.
 *
 *   POST   /imports/run                  — process one offset/limit
 *                                          batch. Client loops until
 *                                          `processed < limit`.
 *
 *   POST   /imports/cleanup              — drop a CSV token's stashed
 *                                          tmp file (best-effort tidy
 *                                          on close / cancel).
 *
 * All routes live under the parent's `/404-to-301/v1` namespace so the
 * existing `wpApiSettings` nonce works without any client-side plumbing.
 *
 * @package DuckDev\FourNotFour\RedirectsImporter
 */

declare( strict_types = 1 );

namespace DuckDev\FourNotFour\RedirectsImporter;

use DuckDev\FourNotFour\RedirectsImporter\Sources\CSV_Source;
use DuckDev\FourNotFour\RedirectsImporter\Sources\Registry;
use DuckDev\FourNotFour\RedirectsImporter\Sources\Source;
use DuckDev\FourNotFour\Utils\Permission;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// If this file is called directly, abort.
defined( 'ABSPATH' ) || exit;

/**
 * Class Api
 *
 * @since 1.0.0
 */
final class Api {

	/**
	 * REST namespace shared with the parent plugin.
	 *
	 * Hard-coded rather than read off the parent's `Endpoint::NAMESPACE`
	 * constant so a missing parent class doesn't fatal the addon — the
	 * permission callback handles the "parent gone" case gracefully.
	 *
	 * @since 1.0.0
	 */
	const NAMESPACE = '404-to-301/v1';

	/**
	 * Transient key prefix for stashed CSV uploads.
	 *
	 * One transient per upload; the value is the absolute path of the
	 * tmp file. `cleanup()` deletes both the transient and the file.
	 *
	 * @since 1.0.0
	 */
	const CSV_TOKEN_PREFIX = 'd404_csv_';

	/**
	 * How long a stashed CSV stays on disk before it gets garbage
	 * collected.
	 *
	 * The preview→run flow usually finishes in a few minutes; an hour
	 * is comfortable head-room for slow networks or a user who walks
	 * away mid-import without explicitly cancelling.
	 *
	 * @since 1.0.0
	 */
	const CSV_TOKEN_TTL = HOUR_IN_SECONDS;

	/**
	 * Hard cap on rows per `run` batch — protects against a misbehaving
	 * client that asks for a million-row slice in one shot.
	 *
	 * @since 1.0.0
	 */
	const MAX_BATCH = 500;

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the shared instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the WordPress hooks owned by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Declare the REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/imports/sources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sources' ),
				'permission_callback' => array( $this, 'require_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'require_access' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'require_access' ),
				'args'                => array(
					'source_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'csv_token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'require_access' ),
				'args'                => array(
					'source_id'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'csv_token'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'offset'          => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'limit'           => array(
						'type'    => 'integer',
						'default' => 100,
					),
					'update_existing' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/imports/cleanup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cleanup' ),
				'permission_callback' => array( $this, 'require_access' ),
				'args'                => array(
					'csv_token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission gate — defers to the parent's `Permission::has_access()`.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function require_access(): bool {
		if ( class_exists( Permission::class ) ) {
			return Permission::has_access();
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /imports/sources — list of available sources for the picker.
	 *
	 * Always includes CSV; plugin-backed sources are only included when
	 * their underlying storage is actually present on the site.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function sources(): WP_REST_Response {
		$items = array();

		foreach ( Registry::available() as $source ) {
			// CSV count is meaningless before the upload exists, so we
			// report it as 0 and the UI suppresses the "found N rows"
			// hint for that source.
			$count = $source instanceof CSV_Source ? 0 : $source->count();

			$items[] = array(
				'id'    => $source->id(),
				'label' => $source->label(),
				'count' => (int) $count,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * POST /imports/upload — stash an uploaded CSV under a token.
	 *
	 * The handler validates the upload, moves the tmp file into a
	 * private location under the WP uploads dir, and writes a
	 * transient mapping `csv_token → file path`. The token is returned
	 * to the client and used in subsequent /preview and /run calls.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'rest_import_missing_file',
				__( 'No CSV file was uploaded.', '404-to-301-redirects-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'rest_import_upload_error',
				$this->upload_error_message( (int) $file['error'] ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'rest_import_invalid_upload',
				__( 'Uploaded file failed verification.', '404-to-301-redirects-importer' ),
				array( 'status' => 400 )
			);
		}

		$name = (string) ( $file['name'] ?? '' );
		if ( '' !== $name && ! preg_match( '/\.(csv|txt)$/i', $name ) ) {
			return new WP_Error(
				'rest_import_bad_extension',
				__( 'Only .csv files are supported.', '404-to-301-redirects-importer' ),
				array( 'status' => 400 )
			);
		}

		$stash = $this->stash_directory();
		if ( null === $stash ) {
			return new WP_Error(
				'rest_import_stash_failed',
				__( 'Could not create a temporary upload area.', '404-to-301-redirects-importer' ),
				array( 'status' => 500 )
			);
		}

		// `wp_unique_filename()` keeps repeated uploads from clobbering
		// each other in the unlikely event two tokens hit the dir at
		// the same second.
		$dest_name = wp_unique_filename( $stash, 'import-' . wp_generate_password( 8, false ) . '.csv' );
		$dest      = trailingslashit( $stash ) . $dest_name;

		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'rest_import_stash_failed',
				__( 'Could not stash the uploaded file.', '404-to-301-redirects-importer' ),
				array( 'status' => 500 )
			);
		}

		$token = wp_generate_password( 24, false, false );
		set_transient( self::CSV_TOKEN_PREFIX . $token, $dest, self::CSV_TOKEN_TTL );

		return new WP_REST_Response(
			array(
				'csv_token' => $token,
				'filename'  => $name,
				'count'     => ( new CSV_Source() )->bind( $dest )->count(),
			),
			201
		);
	}

	/**
	 * POST /imports/preview — dry-run summary for the modal.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$source = $this->resolve_source( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$summary = ( new Importer() )->preview( $source );

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * POST /imports/run — process one offset/limit batch.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$source = $this->resolve_source( $request );
		if ( $source instanceof WP_Error ) {
			return $source;
		}

		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$limit  = max( 1, min( self::MAX_BATCH, (int) $request->get_param( 'limit' ) ) );

		$summary = ( new Importer() )->run_batch(
			$source,
			$offset,
			$limit,
			(bool) $request->get_param( 'update_existing' )
		);

		// Tell the client where it is in the source — saves it from
		// having to track offset arithmetic locally and lets us hint
		// the next batch from a single place.
		$summary['next_offset'] = $offset + $summary['processed'];
		$summary['done']        = $summary['processed'] < $limit;

		return new WP_REST_Response( $summary, 200 );
	}

	/**
	 * POST /imports/cleanup — drop a CSV token + its tmp file.
	 *
	 * Idempotent: an unknown token is a no-op success, since the most
	 * common reason for missing it is "the client already cleaned up".
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response
	 */
	public function cleanup( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'csv_token' );

		if ( '' !== $token ) {
			$path = get_transient( self::CSV_TOKEN_PREFIX . $token );
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			delete_transient( self::CSV_TOKEN_PREFIX . $token );
		}

		return new WP_REST_Response( array( 'cleaned' => true ), 200 );
	}

	/**
	 * Resolve the requested source — either a registered plugin source
	 * or the CSV source bound to a stashed upload.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return Source|WP_Error
	 */
	private function resolve_source( WP_REST_Request $request ) {
		$source_id = (string) $request->get_param( 'source_id' );

		$source = Registry::get( $source_id );
		if ( ! $source instanceof Source ) {
			return new WP_Error(
				'rest_import_unknown_source',
				__( 'Unknown import source.', '404-to-301-redirects-importer' ),
				array( 'status' => 400 )
			);
		}

		if ( $source instanceof CSV_Source ) {
			$token = (string) $request->get_param( 'csv_token' );
			$path  = '' === $token ? '' : (string) get_transient( self::CSV_TOKEN_PREFIX . $token );

			if ( '' === $path || ! is_readable( $path ) ) {
				return new WP_Error(
					'rest_import_missing_csv',
					__( 'CSV upload not found. Re-upload the file.', '404-to-301-redirects-importer' ),
					array( 'status' => 400 )
				);
			}

			$source->bind( $path );
		} elseif ( ! $source->is_available() ) {
			// Plugin sources are listed off `is_available()`. Calling
			// the route with an unavailable source is a client bug —
			// surface the 400 so it gets caught in QA rather than
			// silently returning zero rows.
			return new WP_Error(
				'rest_import_source_unavailable',
				__( "This source isn't available on the site.", '404-to-301-redirects-importer' ),
				array( 'status' => 400 )
			);
		}

		return $source;
	}

	/**
	 * Resolve a private stash directory under uploads/.
	 *
	 * Created lazily on first upload, with a `.htaccess` deny rule so
	 * the stashed file isn't directly fetchable over the web — defence
	 * in depth, the file name is already unguessable.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null Absolute path, or null when uploads are
	 *                     misconfigured (returns the WP error).
	 */
	private function stash_directory(): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . '404-to-301-imports';

		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}

			// `Deny from all` blocks Apache; the empty index.php
			// blocks dir listing on nginx/lighttpd; together they
			// cover the common shared-host setups.
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		return $dir;
	}

	/**
	 * Translate a PHP upload error code into an admin-facing message.
	 *
	 * @since 1.0.0
	 *
	 * @param int $code One of the `UPLOAD_ERR_*` constants.
	 *
	 * @return string
	 */
	private function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The file is larger than the server upload limit.', '404-to-301-redirects-importer' );

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Try again.', '404-to-301-redirects-importer' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was received.', '404-to-301-redirects-importer' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not write the uploaded file to disk.', '404-to-301-redirects-importer' );

			default:
				return __( 'Upload failed.', '404-to-301-redirects-importer' );
		}
	}
}
