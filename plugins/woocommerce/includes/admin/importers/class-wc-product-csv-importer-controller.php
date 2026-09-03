<?php
/**
 * Class WC_Product_CSV_Importer_Controller file.
 *
 * @package WooCommerce\Admin\Importers
 */

use Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController;
use Automattic\WooCommerce\Internal\Utilities\FilesystemUtil;
use Automattic\WooCommerce\Internal\Utilities\URL;
use Automattic\WooCommerce\Utilities\I18nUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Importer' ) ) {
	return;
}

/**
 * Product importer controller - handles file upload and forms in admin.
 *
 * @package     WooCommerce\Admin\Importers
 * @version     3.1.0
 */
class WC_Product_CSV_Importer_Controller {

	/**
	 * Maximum number of importer placeholders deleted in one request.
	 */
	private const IMPORT_CLEANUP_BATCH_SIZE = 30;

	/**
	 * AJAX position prefix used while importer placeholders are being removed.
	 */
	private const IMPORT_CLEANUP_POSITION_PREFIX = 'cleanup:';

	/**
	 * Meta key used to associate temporary products with an import run.
	 */
	private const IMPORT_RUN_META_KEY = '_wc_product_csv_import_run_id';

	/**
	 * Meta key that records when temporary import ownership expires.
	 */
	private const IMPORT_RUN_EXPIRATION_META_KEY = '_wc_product_csv_import_run_expires_at';

	/**
	 * Prefix for server-side import token state.
	 */
	private const IMPORT_TOKEN_OPTION_PREFIX = 'wc_product_csv_import_';

	/**
	 * Lifetime of an import token and its temporary product ownership.
	 */
	private const IMPORT_TOKEN_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Keep expired state reserved long enough for an admitted request to finish.
	 */
	private const IMPORT_REQUEST_GRACE_PERIOD = HOUR_IN_SECONDS;

	/**
	 * The path to the current file.
	 *
	 * @var string
	 */
	protected $file = '';

	/**
	 * The current import step.
	 *
	 * @var string
	 */
	protected $step = '';

	/**
	 * Progress steps.
	 *
	 * @var array
	 */
	protected $steps = array();

	/**
	 * Errors.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * The current delimiter for the file being read.
	 *
	 * @var string
	 */
	protected $delimiter = ',';

	/**
	 * Whether to use previous mapping selections.
	 *
	 * @var bool
	 */
	protected $map_preferences = false;

	/**
	 * Whether to skip existing products.
	 *
	 * @var bool
	 */
	protected $update_existing = false;

	/**
	 * The character encoding to use to interpret the input file, or empty string for autodetect.
	 *
	 * @var string
	 */
	protected $character_encoding = 'UTF-8';

	/**
	 * Get importer instance.
	 *
	 * @param  string $file File to import.
	 * @param  array  $args Importer arguments.
	 * @return WC_Product_CSV_Importer
	 */
	public static function get_importer( $file, $args = array() ) {
		$reserved_args  = array_intersect_key( $args, array_flip( array( 'import_run_id', 'import_run_expires_at' ) ) );
		$importer_class = apply_filters( 'woocommerce_product_csv_importer_class', 'WC_Product_CSV_Importer' );

		/**
		 * Filters the arguments used by the product CSV importer.
		 *
		 * @since 3.1.0
		 *
		 * @param array  $args Importer arguments.
		 * @param string $importer_class Importer class name.
		 */
		$filtered_args = apply_filters( 'woocommerce_product_csv_importer_args', $args, $importer_class );
		$args          = is_array( $filtered_args ) ? array_merge( $filtered_args, $reserved_args ) : $args;
		return new $importer_class( $file, $args );
	}

	/**
	 * Check whether a file is a valid CSV file.
	 *
	 * @param string $file File path.
	 * @param bool   $check_path Whether to also check the file is located in a valid location (Default: true).
	 * @return bool
	 */
	public static function is_file_valid_csv( $file, $check_path = true ) {
		return wc_is_file_valid_csv( $file, $check_path );
	}

	/**
	 * Runs before controller actions to check that the file used during the import is valid.
	 *
	 * @since 9.3.0
	 *
	 * @param string $path Path to test.
	 *
	 * @throws \Exception When file validation fails.
	 */
	protected static function validate_file_path( string $path ): void {
		try {
			FilesystemUtil::validate_upload_file_path( $path );
		} catch ( \Exception $e ) {
			throw new \Exception( esc_html__( 'File path provided for import is invalid.', 'woocommerce' ) );
		}

		if ( ! self::is_file_valid_csv( $path ) ) {
			throw new \Exception( esc_html__( 'Invalid file type. The importer supports CSV and TXT file formats.', 'woocommerce' ) );
		}
	}

	/**
	 * Get all the valid filetypes for a CSV file.
	 *
	 * @return array
	 */
	protected static function get_valid_csv_filetypes() {
		return apply_filters(
			'woocommerce_csv_product_import_valid_filetypes',
			array(
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			)
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$default_steps = array(
			'upload'  => array(
				'name'    => __( 'Upload CSV file', 'woocommerce' ),
				'view'    => array( $this, 'upload_form' ),
				'handler' => array( $this, 'upload_form_handler' ),
			),
			'mapping' => array(
				'name'    => __( 'Column mapping', 'woocommerce' ),
				'view'    => array( $this, 'mapping_form' ),
				'handler' => '',
			),
			'import'  => array(
				'name'    => __( 'Import', 'woocommerce' ),
				'view'    => array( $this, 'import' ),
				'handler' => '',
			),
			'done'    => array(
				'name'    => __( 'Done!', 'woocommerce' ),
				'view'    => array( $this, 'done' ),
				'handler' => '',
			),
		);

		$this->steps = apply_filters( 'woocommerce_product_csv_importer_steps', $default_steps );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$this->step               = isset( $_REQUEST['step'] ) ? sanitize_key( $_REQUEST['step'] ) : current( array_keys( $this->steps ) );
		$this->file               = isset( $_REQUEST['file'] ) ? wc_clean( wp_unslash( $_REQUEST['file'] ) ) : '';
		$this->update_existing    = isset( $_REQUEST['update_existing'] ) ? (bool) $_REQUEST['update_existing'] : false;
		$this->delimiter          = ! empty( $_REQUEST['delimiter'] ) ? wc_clean( wp_unslash( $_REQUEST['delimiter'] ) ) : ',';
		$this->map_preferences    = isset( $_REQUEST['map_preferences'] ) ? (bool) $_REQUEST['map_preferences'] : false;
		$this->character_encoding = isset( $_REQUEST['character_encoding'] ) ? wc_clean( wp_unslash( $_REQUEST['character_encoding'] ) ) : 'UTF-8';
		// phpcs:enable

		// Import mappings for CSV data.
		include_once __DIR__ . '/mappings/mappings.php';

		if ( $this->map_preferences ) {
			add_filter( 'woocommerce_csv_product_import_mapped_columns', array( $this, 'auto_map_user_preferences' ), 9999 );
		}
	}

	/**
	 * Get the URL for the next step's screen.
	 *
	 * @param string $step  slug (default: current step).
	 * @return string       URL for next step if a next step exists.
	 *                      Admin URL if it's the last step.
	 *                      Empty string on failure.
	 */
	public function get_next_step_link( $step = '' ) {
		if ( ! $step ) {
			$step = $this->step;
		}

		$keys = array_keys( $this->steps );

		if ( end( $keys ) === $step ) {
			return admin_url();
		}

		$step_index = array_search( $step, $keys, true );

		if ( false === $step_index ) {
			return '';
		}

		// add_query_arg() does not encode values, so characters like '+' in request-derived strings would be decoded as a space on the next request.
		$params = array(
			'step'               => $keys[ $step_index + 1 ],
			'file'               => rawurlencode( str_replace( DIRECTORY_SEPARATOR, '/', $this->file ) ),
			'delimiter'          => rawurlencode( $this->delimiter ),
			'update_existing'    => $this->update_existing,
			'map_preferences'    => $this->map_preferences,
			'character_encoding' => rawurlencode( $this->character_encoding ),
			'_wpnonce'           => wp_create_nonce( 'woocommerce-csv-importer' ), // wp_nonce_url() escapes & to &amp; breaking redirects.
		);

		return add_query_arg( $params );
	}

	/**
	 * Output header view.
	 */
	protected function output_header() {
		include __DIR__ . '/views/html-csv-import-header.php';
	}

	/**
	 * Output steps view.
	 */
	protected function output_steps() {
		include __DIR__ . '/views/html-csv-import-steps.php';
	}

	/**
	 * Output footer view.
	 */
	protected function output_footer() {
		include __DIR__ . '/views/html-csv-import-footer.php';
	}

	/**
	 * Add error message.
	 *
	 * @param string $message Error message.
	 * @param array  $actions List of actions with 'url' and 'label'.
	 */
	protected function add_error( $message, $actions = array() ) {
		$this->errors[] = array(
			'message' => $message,
			'actions' => $actions,
		);
	}

	/**
	 * Add error message.
	 */
	protected function output_errors() {
		if ( ! $this->errors ) {
			return;
		}

		foreach ( $this->errors as $error ) {
			echo '<div class="error inline">';
			echo '<p>' . esc_html( $error['message'] ) . '</p>';

			if ( ! empty( $error['actions'] ) ) {
				echo '<p>';
				foreach ( $error['actions'] as $action ) {
					echo '<a class="button button-primary" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a> ';
				}
				echo '</p>';
			}
			echo '</div>';
		}
	}

	/**
	 * Dispatch current step and show correct view.
	 */
	public function dispatch() {
		$output = '';

		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['save_step'] ) && ! empty( $this->steps[ $this->step ]['handler'] ) ) {
				if ( is_callable( $this->steps[ $this->step ]['handler'] ) ) {
					call_user_func( $this->steps[ $this->step ]['handler'], $this );
				}
			}

			ob_start();

			if ( is_callable( $this->steps[ $this->step ]['view'] ) ) {
				call_user_func( $this->steps[ $this->step ]['view'], $this );
			}

			$output = ob_get_clean();
		} catch ( \Exception $e ) {
			$this->add_error( $e->getMessage() );
		}

		$this->output_header();
		$this->output_steps();
		$this->output_errors();
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is HTML we've generated ourselves.
		$this->output_footer();
	}

	/**
	 * Hash the immutable request values that identify an import.
	 *
	 * @param string $file Import file path.
	 * @param array  $mapping Column mapping.
	 * @param string $delimiter Field delimiter.
	 * @param bool   $update_existing Whether existing products may be updated.
	 * @param string $character_encoding Source character encoding.
	 * @return string Import context hash.
	 */
	private static function get_import_context_hash( string $file, array $mapping, string $delimiter, bool $update_existing, string $character_encoding ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'file'               => wp_normalize_path( $file ),
					'mapping'            => $mapping,
					'delimiter'          => $delimiter,
					'update_existing'    => $update_existing,
					'character_encoding' => $character_encoding,
				)
			)
		);
	}

	/**
	 * Persist import state under a new random token.
	 *
	 * @param array  $state Import state.
	 * @param string $preserve_token Token being atomically replaced, if any.
	 * @throws RuntimeException When state cannot be persisted.
	 * @return string Import token.
	 */
	private static function create_import_token( array $state, string $preserve_token = '' ): string {
		self::delete_expired_import_tokens( $preserve_token );

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$token = wc_rand_hash();
			if ( add_option( self::get_import_token_option_name( $token ), $state, '', false ) ) {
				return $token;
			}
		}

		throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
	}

	/**
	 * Delete a bounded set of abandoned, expired token records.
	 *
	 * @param string $preserve_token Token that is currently being replaced.
	 * @return void
	 */
	private static function delete_expired_import_tokens( string $preserve_token = '' ): void {
		global $wpdb;
		$preserved_option_name = $preserve_token ? self::get_import_token_option_name( $preserve_token ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Import tokens are private non-autoloaded options and are pruned in a bounded batch.
		$token_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$wpdb->esc_like( self::IMPORT_TOKEN_OPTION_PREFIX ) . '%',
				self::IMPORT_CLEANUP_BATCH_SIZE
			),
			ARRAY_A
		);

		foreach ( $token_options as $token_option ) {
			if ( $preserved_option_name === $token_option['option_name'] ) {
				continue;
			}

			$state = maybe_unserialize( $token_option['option_value'] );
			if ( ! is_array( $state ) || ! isset( $state['expires_at'] ) || absint( $state['expires_at'] ) <= time() - self::IMPORT_REQUEST_GRACE_PERIOD ) {
				delete_option( $token_option['option_name'] );
			}
		}
	}

	/**
	 * Read and validate server-side import state.
	 *
	 * @param string $token Import token.
	 * @param string $context_hash Import context hash.
	 * @param string $phase Expected import phase.
	 * @throws RuntimeException When the token is invalid, expired, or mismatched.
	 * @return array Import state.
	 */
	private static function get_import_state( string $token, string $context_hash, string $phase ): array {
		if ( 40 !== strlen( $token ) || ! ctype_xdigit( $token ) ) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}

		$option_name = self::get_import_token_option_name( $token );
		$state       = get_option( $option_name );
		if (
			! is_array( $state ) ||
			! isset( $state['user_id'], $state['context_hash'], $state['run_id'], $state['phase'], $state['expires_at'] ) ||
			get_current_user_id() !== absint( $state['user_id'] ) ||
			! is_string( $state['context_hash'] ) ||
			! hash_equals( $state['context_hash'], $context_hash ) ||
			! is_string( $state['run_id'] ) ||
			40 !== strlen( $state['run_id'] ) ||
			! ctype_xdigit( $state['run_id'] ) ||
			$phase !== $state['phase'] ||
			absint( $state['expires_at'] ) <= time()
		) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}

		return $state;
	}

	/**
	 * Atomically consume an import token.
	 *
	 * @param string $token Import token.
	 * @param array  $state State returned by get_import_state().
	 * @throws RuntimeException When the token was already consumed.
	 * @return void
	 */
	private static function consume_import_token( string $token, array $state ): void {
		global $wpdb;

		$option_name = self::get_import_token_option_name( $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-delete makes the token single-use across concurrent requests.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option_name, maybe_serialize( $state ) ) );
		wp_cache_delete( $option_name, 'options' );

		if ( 1 !== $deleted ) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}
	}

	/**
	 * Atomically replace the state stored under an unexposed recovery token.
	 *
	 * @param string $token Import token.
	 * @param array  $expected_state Current token state.
	 * @param array  $next_state Replacement token state.
	 * @throws RuntimeException When token state cannot be advanced.
	 * @return void
	 */
	private static function replace_import_token_state( string $token, array $expected_state, array $next_state ): void {
		global $wpdb;

		$option_name = self::get_import_token_option_name( $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-swap advances only the unexposed recovery token created for this request.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $next_state ),
				$option_name,
				maybe_serialize( $expected_state )
			)
		);
		wp_cache_delete( $option_name, 'options' );

		if ( 1 !== $updated ) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}
	}

	/**
	 * Get the option name for an opaque import token.
	 *
	 * @param string $token Import token.
	 * @return string Option name.
	 */
	private static function get_import_token_option_name( string $token ): string {
		return self::IMPORT_TOKEN_OPTION_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Get a post ID cutoff that freezes the cleanup candidate set.
	 *
	 * @throws RuntimeException When the cutoff cannot be read.
	 * @return int Highest post ID present when cleanup starts.
	 */
	private static function get_import_cleanup_post_id_limit(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The primary-key maximum must reflect posts created by the import that just finished.
		$post_id_limit = $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}

		return absint( $post_id_limit );
	}

	/**
	 * Extend ownership before an import batch can cross its expiration time.
	 *
	 * @param string $import_run_id Import run identifier.
	 * @param int    $expires_at New ownership expiration.
	 * @throws RuntimeException When ownership cannot be renewed.
	 * @return void
	 */
	private static function renew_import_run_ownership( string $import_run_id, int $expires_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One atomic update keeps a near-expiry run from losing placeholders while its next batch executes.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} expiration INNER JOIN {$wpdb->postmeta} import_run ON import_run.post_id = expiration.post_id SET expiration.meta_value = GREATEST(CAST(expiration.meta_value AS UNSIGNED), %d) WHERE expiration.meta_key = %s AND import_run.meta_key = %s AND import_run.meta_value = %s",
				$expires_at,
				self::IMPORT_RUN_EXPIRATION_META_KEY,
				self::IMPORT_RUN_META_KEY,
				$import_run_id
			)
		);

		if ( false === $updated ) {
			throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
		}
	}

	/**
	 * Atomically lease a post before cleanup mutates or deletes it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $import_run_id Import run identifier.
	 * @param int    $expires_at Lease expiration.
	 * @throws RuntimeException When the ownership lease cannot be persisted.
	 * @return bool Whether this run still owns the post.
	 */
	private static function claim_import_post( int $post_id, string $import_run_id, int $expires_at ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The unique owner check and lease extension must be atomic with expired-run adoption.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} expiration INNER JOIN {$wpdb->postmeta} import_run ON import_run.post_id = expiration.post_id LEFT JOIN {$wpdb->postmeta} other_import_run ON other_import_run.post_id = import_run.post_id AND other_import_run.meta_key = import_run.meta_key AND other_import_run.meta_id <> import_run.meta_id LEFT JOIN {$wpdb->postmeta} other_expiration ON other_expiration.post_id = expiration.post_id AND other_expiration.meta_key = expiration.meta_key AND other_expiration.meta_id <> expiration.meta_id SET expiration.meta_value = GREATEST(CAST(expiration.meta_value AS UNSIGNED), %d) WHERE expiration.post_id = %d AND expiration.meta_key = %s AND import_run.meta_key = %s AND import_run.meta_value = %s AND other_import_run.meta_id IS NULL AND other_expiration.meta_id IS NULL",
				$expires_at,
				$post_id,
				self::IMPORT_RUN_EXPIRATION_META_KEY,
				self::IMPORT_RUN_META_KEY,
				$import_run_id
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );

		if ( false === $updated ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
			throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
		}

		$current_owners = get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, false );
		if ( empty( $current_owners ) ) {
			return false;
		}
		if ( 1 !== count( $current_owners ) || ! is_string( $current_owners[0] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
			throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
		}
		if ( ! hash_equals( $import_run_id, $current_owners[0] ) ) {
			return false;
		}

		$current_expirations = get_post_meta( $post_id, self::IMPORT_RUN_EXPIRATION_META_KEY, false );
		if ( 1 !== count( $current_expirations ) || $expires_at > absint( $current_expirations[0] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
			throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
		}

		return true;
	}

	/**
	 * Remove one batch of temporary products and mapping data left by the importer.
	 *
	 * @param string $import_run_id Import run that owns the temporary data.
	 * @param int    $post_id_limit Highest post ID eligible for this cleanup run.
	 * @param int    $lease_expires_at Expiration for posts claimed by this cleanup request.
	 * @throws RuntimeException When a placeholder cannot be deleted.
	 * @return bool Whether all importer placeholders have been removed.
	 */
	private static function cleanup_after_import( string $import_run_id, int $post_id_limit, int $lease_expires_at ): bool {
		global $wpdb;

		$remaining_batch_size = self::IMPORT_CLEANUP_BATCH_SIZE;

		// Delete variations first so removing a parent cannot cascade into another import's variation.
		foreach ( array( 'product_variation', 'product' ) as $post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Cleanup must select only placeholders owned by this import.
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT posts.ID FROM {$wpdb->posts} posts INNER JOIN {$wpdb->postmeta} import_run ON import_run.post_id = posts.ID WHERE posts.post_type = %s AND posts.post_status = %s AND posts.ID <= %d AND import_run.meta_key = %s AND import_run.meta_value = %s ORDER BY posts.ID ASC LIMIT %d",
					$post_type,
					'importing',
					$post_id_limit,
					self::IMPORT_RUN_META_KEY,
					$import_run_id,
					$remaining_batch_size + 1
				)
			);
			if ( $wpdb->last_error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
				throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
			}
			$has_more = count( $post_ids ) > $remaining_batch_size;
			$post_ids = array_slice( $post_ids, 0, $remaining_batch_size );

			if ( ! empty( $post_ids ) ) {
				// Prime caches to reduce future queries.
				_prime_post_caches( $post_ids );
			}

			foreach ( $post_ids as $post_id ) {
				if ( ! self::claim_import_post( absint( $post_id ), $import_run_id, $lease_expires_at ) ) {
					continue;
				}

				$post          = get_post( $post_id );
				$current_owner = get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, true );

				if ( ! $post || $post_type !== $post->post_type || 'importing' !== $post->post_status || ! is_string( $current_owner ) || ! hash_equals( $import_run_id, $current_owner ) ) {
					continue;
				}

				if ( 'product' === $post_type ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Avoid cascading deletion into another import's variation.
					$child_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' LIMIT 1", $post_id ) );
					// @phpstan-ignore-next-line if.alwaysFalse (Runtime database drivers can still report a query error.)
					if ( $wpdb->last_error ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
						throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
					}
					if ( $child_id ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
						throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
					}
				}

				wp_delete_post( absint( $post_id ), true );

				if ( get_post( $post_id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
					throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
				}
			}

			$remaining_batch_size -= count( $post_ids );

			if ( $has_more || 0 === $remaining_batch_size ) {
				return false;
			}
		}

		// Remove this run's mapping and ownership markers only after its placeholders are gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Cleanup must select only markers owned by this import.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <= %d ORDER BY post_id ASC LIMIT %d",
				self::IMPORT_RUN_META_KEY,
				$import_run_id,
				$post_id_limit,
				$remaining_batch_size + 1
			)
		);
		// @phpstan-ignore-next-line if.alwaysFalse (Runtime database drivers can still report a query error.)
		if ( $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
			throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
		}
		$has_more = count( $post_ids ) > $remaining_batch_size;
		$post_ids = array_slice( $post_ids, 0, $remaining_batch_size );

		foreach ( $post_ids as $post_id ) {
			if ( ! self::claim_import_post( absint( $post_id ), $import_run_id, $lease_expires_at ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Delete this uniquely owned run's related marker rows atomically after claiming the post.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE markers FROM {$wpdb->postmeta} markers INNER JOIN {$wpdb->postmeta} import_run ON import_run.post_id = markers.post_id AND import_run.meta_key = %s AND import_run.meta_value = %s INNER JOIN {$wpdb->postmeta} expiration ON expiration.post_id = import_run.post_id AND expiration.meta_key = %s LEFT JOIN {$wpdb->postmeta} other_import_run ON other_import_run.post_id = import_run.post_id AND other_import_run.meta_key = import_run.meta_key AND other_import_run.meta_id <> import_run.meta_id LEFT JOIN {$wpdb->postmeta} other_expiration ON other_expiration.post_id = expiration.post_id AND other_expiration.meta_key = expiration.meta_key AND other_expiration.meta_id <> expiration.meta_id WHERE markers.post_id = %d AND other_import_run.meta_id IS NULL AND other_expiration.meta_id IS NULL AND ( markers.meta_key IN ( %s, %s ) OR ( markers.meta_key = %s AND markers.meta_value = %s ) )",
					self::IMPORT_RUN_META_KEY,
					$import_run_id,
					self::IMPORT_RUN_EXPIRATION_META_KEY,
					$post_id,
					'_original_id',
					self::IMPORT_RUN_EXPIRATION_META_KEY,
					self::IMPORT_RUN_META_KEY,
					$import_run_id
				)
			);
			wp_cache_delete( $post_id, 'post_meta' );

			if ( false === $deleted || metadata_exists( 'post', $post_id, '_original_id' ) || metadata_exists( 'post', $post_id, self::IMPORT_RUN_EXPIRATION_META_KEY ) || in_array( $import_run_id, get_post_meta( $post_id, self::IMPORT_RUN_META_KEY, false ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The JSON response is rendered with jQuery .text().
				throw new RuntimeException( __( 'Import cleanup could not be completed.', 'woocommerce' ) );
			}
		}

		return ! $has_more;
	}

	/**
	 * Processes AJAX requests related to a product CSV import.
	 *
	 * @since 9.3.0
	 * @throws RuntimeException When request state is invalid.
	 * @throws \Throwable When an import token cannot be replaced.
	 */
	public static function dispatch_ajax() {
		check_ajax_referer( 'wc-product-import', 'security' );
		$response               = array();
		$error                  = null;
		$cleanup_recovery_token = '';
		$import_successor_token = '';
		$import_successor_state = array();

		try {
			// PHPCS: input var ok.
			$request_position     = isset( $_POST['position'] ) ? wc_clean( wp_unslash( $_POST['position'] ) ) : 0;
			$file                 = wc_clean( wp_unslash( $_POST['file'] ?? '' ) );
			$request_import_token = wc_clean( wp_unslash( $_POST['import_token'] ?? '' ) );
			$delimiter            = ! empty( $_POST['delimiter'] ) ? wc_clean( wp_unslash( $_POST['delimiter'] ) ) : ',';
			$mapping              = isset( $_POST['mapping'] ) ? (array) wc_clean( wp_unslash( $_POST['mapping'] ) ) : array();
			$raw_update_existing  = isset( $_POST['update_existing'] ) ? wc_clean( wp_unslash( $_POST['update_existing'] ) ) : false;
			$character_encoding   = isset( $_POST['character_encoding'] ) ? wc_clean( wp_unslash( $_POST['character_encoding'] ) ) : '';
			$cleanup_pattern      = '/\A' . preg_quote( self::IMPORT_CLEANUP_POSITION_PREFIX, '/' ) . '([a-f0-9]{40})\z/';
			$cleanup_token        = is_string( $request_position ) && preg_match( $cleanup_pattern, $request_position, $cleanup_matches ) ? $cleanup_matches[1] : '';
			$is_import_position   = ( is_int( $request_position ) && 0 <= $request_position ) || ( is_string( $request_position ) && ctype_digit( $request_position ) );

			if (
				! is_string( $file ) ||
				! is_string( $request_import_token ) ||
				! is_string( $delimiter ) ||
				! is_string( $character_encoding ) ||
				( ! is_string( $raw_update_existing ) && ! is_bool( $raw_update_existing ) ) ||
				( is_string( $request_position ) && 0 === strpos( $request_position, self::IMPORT_CLEANUP_POSITION_PREFIX ) && ! $cleanup_token ) ||
				( ! $cleanup_token && ! $is_import_position )
			) {
				throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
			}

			$update_existing = wc_string_to_bool( $raw_update_existing );
			$context_hash    = self::get_import_context_hash( $file, $mapping, $delimiter, $update_existing, $character_encoding );

			if ( $cleanup_token ) {
				if ( ! hash_equals( $request_import_token, $cleanup_token ) ) {
					throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
				}

				$state = self::get_import_state( $cleanup_token, $context_hash, 'cleanup' );
				if ( ! isset( $state['post_id_limit'] ) ) {
					throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
				}

				$next_state               = $state;
				$next_state['expires_at'] = time() + self::IMPORT_TOKEN_EXPIRATION;
				$next_token               = self::create_import_token( $next_state, $cleanup_token );

				try {
					self::consume_import_token( $cleanup_token, $state );
				} catch ( \Throwable $throwable ) {
					delete_option( self::get_import_token_option_name( $next_token ) );
					throw $throwable;
				}

				$cleanup_recovery_token = $next_token;

				$cleanup_complete = self::cleanup_after_import( $state['run_id'], absint( $state['post_id_limit'] ), $next_state['expires_at'] );

				$response = array(
					'position'            => 'done',
					'percentage'          => 100,
					'imported'            => 0,
					'imported_variations' => 0,
					'failed'              => 0,
					'updated'             => 0,
					'skipped'             => 0,
				);

				if ( $cleanup_complete ) {
					delete_option( self::get_import_token_option_name( $next_token ) );
					$cleanup_recovery_token = '';
					if ( isset( $state['failure_message'] ) && is_string( $state['failure_message'] ) && $state['failure_message'] ) {
						throw new RuntimeException( $state['failure_message'] );
					}
					$response['url'] = add_query_arg( array( '_wpnonce' => wp_create_nonce( 'woocommerce-csv-importer' ) ), admin_url( 'edit.php?post_type=product&page=product_importer&step=done' ) );
				} else {
					$response['position']     = self::IMPORT_CLEANUP_POSITION_PREFIX . $next_token;
					$response['import_token'] = $next_token;
				}
			} else {
				$state    = self::get_import_state( $request_import_token, $context_hash, 'import' );
				$position = absint( $request_position );
				if ( ! isset( $state['position'] ) || ! is_int( $state['position'] ) || $position !== $state['position'] ) {
					throw new RuntimeException( esc_html__( 'Import could not be completed. Please start again.', 'woocommerce' ) );
				}

				self::validate_file_path( $file );
				$next_expiration = absint( $state['expires_at'] );
				if ( $next_expiration - time() < self::IMPORT_REQUEST_GRACE_PERIOD ) {
					$next_expiration = time() + self::IMPORT_TOKEN_EXPIRATION;
					self::renew_import_run_ownership( $state['run_id'], $next_expiration );
				}
				$recovery_state               = $state;
				$recovery_state['expires_at'] = $next_expiration;
				$recovery_token               = self::create_import_token( $recovery_state, $request_import_token );

				try {
					self::consume_import_token( $request_import_token, $state );
				} catch ( \Throwable $throwable ) {
					delete_option( self::get_import_token_option_name( $recovery_token ) );
					throw $throwable;
				}

				$import_successor_token = $recovery_token;
				$import_successor_state = $recovery_state;
				$state                  = $recovery_state;

				$params = array(
					'delimiter'             => $delimiter,
					'start_pos'             => $position,
					'mapping'               => $mapping,
					'update_existing'       => $update_existing,
					'character_encoding'    => $character_encoding,
					'import_run_id'         => $state['run_id'],
					'import_run_expires_at' => $state['expires_at'],

					/**
					 * Batch size for the product import process.
					 *
					 * @param int $size Batch size.
					 *
					 * @since 3.1.0
					 */
					'lines'                 => apply_filters( 'woocommerce_product_import_batch_size', 30 ),
					'parse'                 => true,
				);

				// Log failures.
				if ( 0 !== $params['start_pos'] ) {
					$error_log = array_filter( (array) get_user_option( 'product_import_error_log' ) );
				} else {
					$error_log = array();
				}

				include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';

				$importer         = self::get_importer( $file, $params );
				$results          = $importer->import();
				$percent_complete = $importer->get_percent_complete();
				$error_log        = array_merge( $error_log, $results['failed'], $results['skipped'] );

				update_user_option( get_current_user_id(), 'product_import_error_log', $error_log );

				$response = array(
					'position'            => $importer->get_file_position(),
					'percentage'          => $percent_complete,
					'imported'            => is_countable( $results['imported'] ) ? count( $results['imported'] ) : 0,
					'imported_variations' => is_countable( $results['imported_variations'] ) ? count( $results['imported_variations'] ) : 0,
					'failed'              => is_countable( $results['failed'] ) ? count( $results['failed'] ) : 0,
					'updated'             => is_countable( $results['updated'] ) ? count( $results['updated'] ) : 0,
					'skipped'             => is_countable( $results['skipped'] ) ? count( $results['skipped'] ) : 0,
				);

				$next_state = $state;
				if ( 100 === $percent_complete ) {
					$next_state['phase']         = 'cleanup';
					$next_state['post_id_limit'] = self::get_import_cleanup_post_id_limit();
					unset( $next_state['position'] );
				} else {
					$next_state['position'] = $response['position'];
				}

				self::replace_import_token_state( $recovery_token, $recovery_state, $next_state );
				$response['import_token'] = $recovery_token;
				if ( 100 === $percent_complete ) {
					$response['position'] = self::IMPORT_CLEANUP_POSITION_PREFIX . $recovery_token;
				}
				$import_successor_token = '';
				$import_successor_state = array();
			}
		} catch ( \Throwable $e ) {
			$error = array( 'message' => $e->getMessage() );
			if ( $import_successor_token ) {
				try {
					$cleanup_state                    = $import_successor_state;
					$cleanup_state['phase']           = 'cleanup';
					$cleanup_state['expires_at']      = time() + self::IMPORT_TOKEN_EXPIRATION;
					$cleanup_state['post_id_limit']   = self::get_import_cleanup_post_id_limit();
					$cleanup_state['failure_message'] = $e->getMessage();
					unset( $cleanup_state['position'] );
					self::replace_import_token_state( $import_successor_token, $import_successor_state, $cleanup_state );
					$cleanup_recovery_token = $import_successor_token;
				} catch ( \Throwable $cleanup_error ) {
					delete_option( self::get_import_token_option_name( $import_successor_token ) );
				}
			}
			if ( $cleanup_recovery_token ) {
				$error['position']     = self::IMPORT_CLEANUP_POSITION_PREFIX . $cleanup_recovery_token;
				$error['import_token'] = $cleanup_recovery_token;
			}
		}

		if ( null !== $error ) {
			wp_send_json_error( $error );
		}

		wp_send_json_success( $response );
	}

	/**
	 * Output information about the uploading process.
	 */
	protected function upload_form() {
		$bytes      = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size       = size_format( $bytes );
		$upload_dir = wp_upload_dir();

		include __DIR__ . '/views/html-product-csv-import-form.php';
	}

	/**
	 * Handle the upload form and store options.
	 */
	public function upload_form_handler() {
		check_admin_referer( 'woocommerce-csv-importer' );

		$file = $this->handle_upload();

		if ( is_wp_error( $file ) ) {
			$this->add_error( $file->get_error_message() );
			return;
		} else {
			$this->file = $file;
		}

		wp_redirect( esc_url_raw( $this->get_next_step_link() ) );
		exit;
	}

	/**
	 * Handles the CSV upload and initial parsing of the file to prepare for
	 * displaying author import options.
	 *
	 * @return string|WP_Error
	 */
	public function handle_upload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce already verified in WC_Product_CSV_Importer_Controller::upload_form_handler()
		$file_url = isset( $_POST['file_url'] ) ? wc_clean( wp_unslash( $_POST['file_url'] ) ) : '';

		try {
			if ( ! empty( $file_url ) ) {
				$path = ABSPATH . $file_url;
				self::validate_file_path( $path );
			} else {
				$csv_import_util = wc_get_container()->get( Automattic\WooCommerce\Internal\Admin\ImportExport\CSVUploadHelper::class );
				$upload          = $csv_import_util->handle_csv_upload( 'product', 'import', self::get_valid_csv_filetypes() );
				$path            = $upload['file'];
			}

			return $path;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'woocommerce_product_csv_importer_upload_invalid_file', $e->getMessage() );
		}
	}

	/**
	 * Mapping step.
	 */
	protected function mapping_form() {
		check_admin_referer( 'woocommerce-csv-importer' );
		self::validate_file_path( $this->file );

		$args = array(
			'lines'              => 1,
			'delimiter'          => $this->delimiter,
			'character_encoding' => $this->character_encoding,
		);

		$importer     = self::get_importer( $this->file, $args );
		$headers      = $importer->get_raw_keys();
		$mapped_items = $this->auto_map_columns( $headers );
		$sample       = current( $importer->get_raw_data() );

		if ( empty( $sample ) ) {
			$this->add_error(
				__( 'The file is empty or using a different encoding than UTF-8, please try again with a new file.', 'woocommerce' ),
				array(
					array(
						'url'   => admin_url( 'edit.php?post_type=product&page=product_importer' ),
						'label' => __( 'Upload a new file', 'woocommerce' ),
					),
				)
			);

			// Force output the errors in the same page.
			$this->output_errors();
			return;
		}

		include_once __DIR__ . '/views/html-csv-import-mapping.php';
	}

	/**
	 * Import the file if it exists and is valid.
	 */
	public function import() {
		// Displaying this page triggers Ajax action to run the import with a valid nonce,
		// therefore this page needs to be nonce protected as well.
		check_admin_referer( 'woocommerce-csv-importer' );
		self::validate_file_path( $this->file );

		if ( ! empty( $_POST['map_from'] ) && ! empty( $_POST['map_to'] ) ) {
			$mapping_from = wc_clean( wp_unslash( $_POST['map_from'] ) );
			$mapping_to   = wc_clean( wp_unslash( $_POST['map_to'] ) );

			// Save mapping preferences for future imports.
			update_user_option( get_current_user_id(), 'woocommerce_product_import_mapping', $mapping_to );
		} else {
			wp_redirect( esc_url_raw( $this->get_next_step_link( 'upload' ) ) );
			exit;
		}

		$mapping = array(
			'from' => (array) $mapping_from,
			'to'   => (array) $mapping_to,
		);
		$state   = array(
			'user_id'      => get_current_user_id(),
			'context_hash' => self::get_import_context_hash( $this->file, $mapping, $this->delimiter, $this->update_existing, $this->character_encoding ),
			'run_id'       => wc_rand_hash(),
			'phase'        => 'import',
			'expires_at'   => time() + self::IMPORT_TOKEN_EXPIRATION,
			'position'     => 0,
		);

		wp_localize_script(
			'wc-product-import',
			'wc_product_import_params',
			array(
				'import_nonce'       => wp_create_nonce( 'wc-product-import' ),
				'import_token'       => self::create_import_token( $state ),
				'mapping'            => $mapping,
				'file'               => $this->file,
				'update_existing'    => $this->update_existing,
				'delimiter'          => $this->delimiter,
				'character_encoding' => $this->character_encoding,
				'import_error'       => __( 'Import could not be completed.', 'woocommerce' ),
			)
		);
		wp_enqueue_script( 'wc-product-import' );

		include_once __DIR__ . '/views/html-csv-import-progress.php';
	}

	/**
	 * Done step.
	 */
	protected function done() {
		check_admin_referer( 'woocommerce-csv-importer' );
		$imported            = isset( $_GET['products-imported'] ) ? absint( $_GET['products-imported'] ) : 0;
		$imported_variations = isset( $_GET['products-imported-variations'] ) ? absint( $_GET['products-imported-variations'] ) : 0;
		$updated             = isset( $_GET['products-updated'] ) ? absint( $_GET['products-updated'] ) : 0;
		$failed              = isset( $_GET['products-failed'] ) ? absint( $_GET['products-failed'] ) : 0;
		$skipped             = isset( $_GET['products-skipped'] ) ? absint( $_GET['products-skipped'] ) : 0;
		$file_name           = isset( $_GET['file-name'] ) ? sanitize_text_field( wp_unslash( $_GET['file-name'] ) ) : '';
		$errors              = array_filter( (array) get_user_option( 'product_import_error_log' ) );

		include_once __DIR__ . '/views/html-csv-import-done.php';
	}

	/**
	 * Columns to normalize.
	 *
	 * @param  array $columns List of columns names and keys.
	 * @return array
	 */
	protected function normalize_columns_names( $columns ) {
		$normalized = array();

		foreach ( $columns as $key => $value ) {
			$normalized[ strtolower( $key ) ] = $value;
		}

		return $normalized;
	}

	/**
	 * Auto map column names.
	 *
	 * @param  array $raw_headers Raw header columns.
	 * @param  bool  $num_indexes If should use numbers or raw header columns as indexes.
	 * @return array
	 */
	protected function auto_map_columns( $raw_headers, $num_indexes = true ) {
		$weight_unit_label    = I18nUtil::get_weight_unit_label( get_option( 'woocommerce_weight_unit', 'kg' ) );
		$dimension_unit_label = I18nUtil::get_dimensions_unit_label( get_option( 'woocommerce_dimension_unit', 'cm' ) );

		$default_columns = array(
			__( 'ID', 'woocommerce' )                      => 'id',
			__( 'Type', 'woocommerce' )                    => 'type',
			__( 'SKU', 'woocommerce' )                     => 'sku',
			__( 'Name', 'woocommerce' )                    => 'name',
			__( 'Published', 'woocommerce' )               => 'published',
			__( 'Is featured?', 'woocommerce' )            => 'featured',
			__( 'Visibility in catalog', 'woocommerce' )   => 'catalog_visibility',
			__( 'Short description', 'woocommerce' )       => 'short_description',
			__( 'Description', 'woocommerce' )             => 'description',
			__( 'Date sale price starts', 'woocommerce' )  => 'date_on_sale_from',
			__( 'Date sale price ends', 'woocommerce' )    => 'date_on_sale_to',
			__( 'Tax status', 'woocommerce' )              => 'tax_status',
			__( 'Tax class', 'woocommerce' )               => 'tax_class',
			__( 'In stock?', 'woocommerce' )               => 'stock_status',
			__( 'Stock', 'woocommerce' )                   => 'stock_quantity',
			__( 'Backorders allowed?', 'woocommerce' )     => 'backorders',
			__( 'Low stock amount', 'woocommerce' )        => 'low_stock_amount',
			__( 'Sold individually?', 'woocommerce' )      => 'sold_individually',
			/* translators: %s: Weight unit */
			sprintf( __( 'Weight (%s)', 'woocommerce' ), $weight_unit_label ) => 'weight',
			/* translators: %s: Length unit */
			sprintf( __( 'Length (%s)', 'woocommerce' ), $dimension_unit_label ) => 'length',
			/* translators: %s: Width unit */
			sprintf( __( 'Width (%s)', 'woocommerce' ), $dimension_unit_label ) => 'width',
			/* translators: %s: Height unit */
			sprintf( __( 'Height (%s)', 'woocommerce' ), $dimension_unit_label ) => 'height',
			__( 'Allow customer reviews?', 'woocommerce' ) => 'reviews_allowed',
			__( 'Purchase note', 'woocommerce' )           => 'purchase_note',
			__( 'Sale price', 'woocommerce' )              => 'sale_price',
			__( 'Regular price', 'woocommerce' )           => 'regular_price',
			__( 'Categories', 'woocommerce' )              => 'category_ids',
			__( 'Tags', 'woocommerce' )                    => 'tag_ids',
			__( 'Shipping class', 'woocommerce' )          => 'shipping_class_id',
			__( 'Images', 'woocommerce' )                  => 'images',
			__( 'Download limit', 'woocommerce' )          => 'download_limit',
			__( 'Download expiry days', 'woocommerce' )    => 'download_expiry',
			__( 'Parent', 'woocommerce' )                  => 'parent_id',
			__( 'Upsells', 'woocommerce' )                 => 'upsell_ids',
			__( 'Cross-sells', 'woocommerce' )             => 'cross_sell_ids',
			__( 'Grouped products', 'woocommerce' )        => 'grouped_products',
			__( 'External URL', 'woocommerce' )            => 'product_url',
			__( 'Button text', 'woocommerce' )             => 'button_text',
			__( 'Position', 'woocommerce' )                => 'menu_order',
		);

		if ( wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled() ) {
			$default_columns[ __( 'Cost of goods', 'woocommerce' ) ] = 'cogs_value';
		}

		/*
		 * @hooked wc_importer_generic_mappings - 10
		 * @hooked wc_importer_wordpress_mappings - 10
		 * @hooked wc_importer_default_english_mappings - 100
		 */
		$default_columns = $this->normalize_columns_names(
			apply_filters(
				'woocommerce_csv_product_import_mapping_default_columns',
				$default_columns,
				$raw_headers
			)
		);

		$special_columns = $this->get_special_columns(
			$this->normalize_columns_names(
				apply_filters(
					'woocommerce_csv_product_import_mapping_special_columns',
					array(
						/* translators: %d: Attribute number */
						__( 'Attribute %d name', 'woocommerce' ) => 'attributes:name',
						/* translators: %d: Attribute number */
						__( 'Attribute %d value(s)', 'woocommerce' ) => 'attributes:value',
						/* translators: %d: Attribute number */
						__( 'Attribute %d visible', 'woocommerce' ) => 'attributes:visible',
						/* translators: %d: Attribute number */
						__( 'Attribute %d global', 'woocommerce' ) => 'attributes:taxonomy',
						/* translators: %d: Attribute number */
						__( 'Attribute %d default', 'woocommerce' ) => 'attributes:default',
						/* translators: %d: Download number */
						__( 'Download %d ID', 'woocommerce' ) => 'downloads:id',
						/* translators: %d: Download number */
						__( 'Download %d name', 'woocommerce' ) => 'downloads:name',
						/* translators: %d: Download number */
						__( 'Download %d URL', 'woocommerce' ) => 'downloads:url',
						/* translators: %d: Meta number */
						__( 'Meta: %s', 'woocommerce' ) => 'meta:',
					),
					$raw_headers
				)
			)
		);

		$headers = array();
		foreach ( $raw_headers as $key => $field ) {
			$normalized_field  = strtolower( $field );
			$index             = $num_indexes ? $key : $field;
			$headers[ $index ] = $normalized_field;

			if ( isset( $default_columns[ $normalized_field ] ) ) {
				$headers[ $index ] = $default_columns[ $normalized_field ];
			} else {
				foreach ( $special_columns as $regex => $special_key ) {
					// Don't use the normalized field in the regex since meta might be case-sensitive.
					if ( preg_match( $regex, $field, $matches ) ) {
						$headers[ $index ] = $special_key . $matches[1];
						break;
					}
				}
			}
		}

		return apply_filters( 'woocommerce_csv_product_import_mapped_columns', $headers, $raw_headers );
	}

	/**
	 * Map columns using the user's latest import mappings.
	 *
	 * @param  array $headers Header columns.
	 * @return array
	 */
	public function auto_map_user_preferences( $headers ) {
		$mapping_preferences = get_user_option( 'woocommerce_product_import_mapping' );

		if ( ! empty( $mapping_preferences ) && is_array( $mapping_preferences ) ) {
			return $mapping_preferences;
		}

		return $headers;
	}

	/**
	 * Sanitize special column name regex.
	 *
	 * @param  string $value Raw special column name.
	 * @return string
	 */
	protected function sanitize_special_column_name_regex( $value ) {
		return '/' . str_replace( array( '%d', '%s' ), '(.*)', trim( quotemeta( $value ) ) ) . '/i';
	}

	/**
	 * Get special columns.
	 *
	 * @param  array $columns Raw special columns.
	 * @return array
	 */
	protected function get_special_columns( $columns ) {
		$formatted = array();

		foreach ( $columns as $key => $value ) {
			$regex = $this->sanitize_special_column_name_regex( $key );

			$formatted[ $regex ] = $value;
		}

		return $formatted;
	}

	/**
	 * Get mapping options.
	 *
	 * @param  string $item Item name.
	 * @return array
	 */
	protected function get_mapping_options( $item = '' ) {
		// Get index for special column names.
		$index = $item;

		if ( preg_match( '/\d+/', $item, $matches ) ) {
			$index = $matches[0];
		}

		// Properly format for meta field.
		$meta = str_replace( 'meta:', '', $item );

		// Available options.
		$weight_unit_label    = I18nUtil::get_weight_unit_label( get_option( 'woocommerce_weight_unit', 'kg' ) );
		$dimension_unit_label = I18nUtil::get_dimensions_unit_label( get_option( 'woocommerce_dimension_unit', 'cm' ) );
		$options              = array(
			'id'                 => __( 'ID', 'woocommerce' ),
			'type'               => __( 'Type', 'woocommerce' ),
			'sku'                => __( 'SKU', 'woocommerce' ),
			'global_unique_id'   => __( 'GTIN, UPC, EAN, or ISBN', 'woocommerce' ),
			'name'               => __( 'Name', 'woocommerce' ),
			'published'          => __( 'Published', 'woocommerce' ),
			'featured'           => __( 'Is featured?', 'woocommerce' ),
			'catalog_visibility' => __( 'Visibility in catalog', 'woocommerce' ),
			'short_description'  => __( 'Short description', 'woocommerce' ),
			'description'        => __( 'Description', 'woocommerce' ),
			'price'              => array(
				'name'    => __( 'Price', 'woocommerce' ),
				'options' => array(
					'regular_price'     => __( 'Regular price', 'woocommerce' ),
					'sale_price'        => __( 'Sale price', 'woocommerce' ),
					'date_on_sale_from' => __( 'Date sale price starts', 'woocommerce' ),
					'date_on_sale_to'   => __( 'Date sale price ends', 'woocommerce' ),
				),
			),
			'tax_status'         => __( 'Tax status', 'woocommerce' ),
			'tax_class'          => __( 'Tax class', 'woocommerce' ),
			'stock_status'       => __( 'In stock?', 'woocommerce' ),
			'stock_quantity'     => _x( 'Stock', 'Quantity in stock', 'woocommerce' ),
			'backorders'         => __( 'Backorders allowed?', 'woocommerce' ),
			'low_stock_amount'   => __( 'Low stock amount', 'woocommerce' ),
			'sold_individually'  => __( 'Sold individually?', 'woocommerce' ),
			/* translators: %s: weight unit */
			'weight'             => sprintf( __( 'Weight (%s)', 'woocommerce' ), $weight_unit_label ),
			'dimensions'         => array(
				'name'    => __( 'Dimensions', 'woocommerce' ),
				'options' => array(
					/* translators: %s: dimension unit */
					'length' => sprintf( __( 'Length (%s)', 'woocommerce' ), $dimension_unit_label ),
					/* translators: %s: dimension unit */
					'width'  => sprintf( __( 'Width (%s)', 'woocommerce' ), $dimension_unit_label ),
					/* translators: %s: dimension unit */
					'height' => sprintf( __( 'Height (%s)', 'woocommerce' ), $dimension_unit_label ),
				),
			),
			'category_ids'       => __( 'Categories', 'woocommerce' ),
			'tag_ids'            => __( 'Tags (comma separated)', 'woocommerce' ),
			'tag_ids_spaces'     => __( 'Tags (space separated)', 'woocommerce' ),
			'shipping_class_id'  => __( 'Shipping class', 'woocommerce' ),
			'images'             => __( 'Images', 'woocommerce' ),
			'parent_id'          => __( 'Parent', 'woocommerce' ),
			'upsell_ids'         => __( 'Upsells', 'woocommerce' ),
			'cross_sell_ids'     => __( 'Cross-sells', 'woocommerce' ),
			'grouped_products'   => __( 'Grouped products', 'woocommerce' ),
			'external'           => array(
				'name'    => __( 'External product', 'woocommerce' ),
				'options' => array(
					'product_url' => __( 'External URL', 'woocommerce' ),
					'button_text' => __( 'Button text', 'woocommerce' ),
				),
			),
			'downloads'          => array(
				'name'    => __( 'Downloads', 'woocommerce' ),
				'options' => array(
					'downloads:id' . $index   => __( 'Download ID', 'woocommerce' ),
					'downloads:name' . $index => __( 'Download name', 'woocommerce' ),
					'downloads:url' . $index  => __( 'Download URL', 'woocommerce' ),
					'download_limit'          => __( 'Download limit', 'woocommerce' ),
					'download_expiry'         => __( 'Download expiry days', 'woocommerce' ),
				),
			),
			'attributes'         => array(
				'name'    => __( 'Attributes', 'woocommerce' ),
				'options' => array(
					'attributes:name' . $index     => __( 'Attribute name', 'woocommerce' ),
					'attributes:value' . $index    => __( 'Attribute value(s)', 'woocommerce' ),
					'attributes:taxonomy' . $index => __( 'Is a global attribute?', 'woocommerce' ),
					'attributes:visible' . $index  => __( 'Attribute visibility', 'woocommerce' ),
					'attributes:default' . $index  => __( 'Default attribute', 'woocommerce' ),
				),
			),
			'reviews_allowed'    => __( 'Allow customer reviews?', 'woocommerce' ),
			'purchase_note'      => __( 'Purchase note', 'woocommerce' ),
			'meta:' . $meta      => __( 'Import as meta data', 'woocommerce' ),
			'menu_order'         => __( 'Position', 'woocommerce' ),
		);

		if ( wc_get_container()->get( CostOfGoodsSoldController::class )->feature_is_enabled() ) {
			$options['cogs_value'] = __( 'Cost of goods', 'woocommerce' );
		}

		return apply_filters( 'woocommerce_csv_product_import_mapping_options', $options, $item );
	}
}
