<?php
/**
 * Tests for the report exporter.
 *
 * @package WooCommerce\Tests\Admin
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin;

use Automattic\WooCommerce\Admin\ReportCSVExporter;
use Automattic\WooCommerce\Admin\ReportExporter;
use WC_Unit_Test_Case;

/**
 * Tests for the report exporter.
 */
class ReportExporterTest extends WC_Unit_Test_Case {
	/**
	 * Query parameters from before the test.
	 *
	 * @var array
	 */
	private $original_get;

	/**
	 * Request method from before the test.
	 *
	 * @var mixed
	 */
	private $original_request_method;

	/**
	 * Whether REQUEST_METHOD existed before the test.
	 *
	 * @var bool
	 */
	private $request_method_was_set;

	/**
	 * Files created by a test.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Status header filters registered by a test.
	 *
	 * @var callable[]
	 */
	private $status_filters = array();

	/**
	 * Preserve request globals used by the tests.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_get            = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserving the test-process query parameters for restoration.
		$this->request_method_was_set  = array_key_exists( 'REQUEST_METHOD', $_SERVER );
		$this->original_request_method = $this->request_method_was_set ? $_SERVER['REQUEST_METHOD'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserving the exact test-process value for restoration.
	}

	/**
	 * Reset request globals and remove files outside the database transaction.
	 */
	public function tearDown(): void {
		try {
			foreach ( $this->status_filters as $status_filter ) {
				remove_filter( 'status_header', $status_filter );
			}

			$_GET = $this->original_get;
			if ( $this->request_method_was_set ) {
				$_SERVER['REQUEST_METHOD'] = $this->original_request_method;
			} else {
				unset( $_SERVER['REQUEST_METHOD'] );
			}

			foreach ( $this->files as $file ) {
				wp_delete_file( $file );
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should reject non-GET requests without consuming the export.
	 *
	 * @testWith ["HEAD"]
	 *           ["POST"]
	 *
	 * @param string $request_method Request method to test.
	 */
	public function test_download_handler_rejects_non_get_requests( string $request_method ): void {
		$paths = $this->create_export_files();
		$this->set_download_request( $request_method );
		$status = $this->capture_status();

		ReportExporter::download_export_file();

		$this->assertSame( 405, $status(), 'A non-GET download should return method not allowed.' );
		$this->assertFileExists( $paths['body'], 'A rejected request should retain the export body.' );
		$this->assertFileExists( $paths['headers'], 'A rejected request should retain the export headers.' );
	}

	/**
	 * @testdox Should reject an incomplete export without creating blank files.
	 *
	 * @testWith ["body"]
	 *           ["headers"]
	 *
	 * @param string $existing_part The part of the export that exists.
	 */
	public function test_download_handler_rejects_incomplete_export( string $existing_part ): void {
		$paths = $this->get_export_paths();
		file_put_contents( $paths[ $existing_part ], 'contents' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an incomplete export fixture.
		$this->set_download_request();
		$status = $this->capture_status();

		ReportExporter::download_export_file();

		$missing_part = 'body' === $existing_part ? 'headers' : 'body';
		$this->assertSame( 404, $status(), 'An incomplete export should return not found.' );
		$this->assertFileExists( $paths[ $existing_part ], 'The existing export part should not be consumed.' );
		$this->assertFileDoesNotExist( $paths[ $missing_part ], 'The missing export part should not be created.' );
	}

	/**
	 * @testdox Should clean up the exact export using its report-specific filename filter.
	 */
	public function test_cleanup_export_uses_report_filename_filter(): void {
		$filename_filter = static function ( $filename ) {
			return 'filtered-' . $filename;
		};
		add_filter( 'woocommerce_admin_orders_report_export_get_filename', $filename_filter );
		$filename = 'wc-orders-report-export-' . wp_generate_uuid4();

		try {
			$exporter = new ReportCSVExporter( 'orders' );
			$exporter->set_filename( $filename );
			$body          = ReportCSVExporter::get_reports_directory() . $exporter->get_filename();
			$headers       = $body . '.headers';
			$this->files[] = $body;
			$this->files[] = $headers;
			file_put_contents( $body, 'body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
			file_put_contents( $headers, 'headers' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.

			ReportExporter::cleanup_export( 'orders', $filename );

			$this->assertFileDoesNotExist( $body, 'Cleanup should remove the filtered export body.' );
			$this->assertFileDoesNotExist( $headers, 'Cleanup should remove the filtered export headers.' );
		} finally {
			remove_filter( 'woocommerce_admin_orders_report_export_get_filename', $filename_filter );
		}
	}

	/**
	 * Create both parts of an export fixture.
	 *
	 * @return array{body: string, headers: string} File paths.
	 */
	private function create_export_files(): array {
		$paths = $this->get_export_paths();
		file_put_contents( $paths['body'], "value\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $paths['headers'], "column\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.

		return $paths;
	}

	/**
	 * Get and track the paths for an export fixture.
	 *
	 * @return array{body: string, headers: string} File paths.
	 */
	private function get_export_paths(): array {
		new ReportCSVExporter();
		$filename      = 'wc-orders-report-export-' . wp_generate_uuid4() . '.csv';
		$body          = ReportCSVExporter::get_reports_directory() . sanitize_file_name( $filename );
		$headers       = $body . '.headers';
		$this->files[] = $body;
		$this->files[] = $headers;

		$_GET['filename'] = $filename;

		return array(
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Populate globals for a report download request.
	 *
	 * @param string $request_method Request method.
	 * @return void
	 */
	private function set_download_request( string $request_method = 'GET' ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['action']            = ReportExporter::DOWNLOAD_EXPORT_ACTION;
		$_SERVER['REQUEST_METHOD'] = $request_method;
	}

	/**
	 * Capture the latest HTTP status code.
	 *
	 * @return callable(): int|null Status reader.
	 */
	private function capture_status(): callable {
		$status        = null;
		$status_filter = static function ( $status_header, $status_code ) use ( &$status ) {
			$status = $status_code;
			return $status_header;
		};
		add_filter( 'status_header', $status_filter, 10, 2 );
		$this->status_filters[] = $status_filter;

		return static function () use ( &$status ) {
			return $status;
		};
	}
}
