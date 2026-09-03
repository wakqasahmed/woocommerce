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

		$this->assert_download_request_terminates();

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

		$this->assert_download_request_terminates();

		$missing_part = 'body' === $existing_part ? 'headers' : 'body';
		$this->assertSame( 404, $status(), 'An incomplete export should return not found.' );
		$this->assertFileExists( $paths[ $existing_part ], 'The existing export part should not be consumed.' );
		$this->assertFileDoesNotExist( $paths[ $missing_part ], 'The missing export part should not be created.' );
	}

	/**
	 * @testdox Should clean up the resolved filename without running export filters again.
	 */
	public function test_cleanup_export_uses_resolved_filename_without_rerunning_filters(): void {
		$filter_called   = false;
		$filename_filter = static function ( $filename ) use ( &$filter_called ) {
			$filter_called = true;
			return 'changed-' . $filename;
		};
		add_filter( 'woocommerce__export_get_filename', $filename_filter );
		$filename      = 'filtered-wc-orders-report-export-' . wp_generate_uuid4() . '.csv';
		$body          = ReportCSVExporter::get_reports_directory() . $filename;
		$headers       = $body . '.headers';
		$this->files[] = $body;
		$this->files[] = $headers;

		try {
			file_put_contents( $body, 'body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
			file_put_contents( $headers, 'headers' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
			touch( $body, time() - WEEK_IN_SECONDS - 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
			touch( $headers, time() - WEEK_IN_SECONDS - 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch

			ReportExporter::cleanup_export( ...$this->get_cleanup_action_args( $filename ) );

			$this->assertFalse( $filter_called, 'Cleanup should not resolve the filename through live filters.' );
			$this->assertFileDoesNotExist( $body, 'Cleanup should remove the resolved export body.' );
			$this->assertFileDoesNotExist( $headers, 'Cleanup should remove the resolved export headers.' );
		} finally {
			remove_filter( 'woocommerce__export_get_filename', $filename_filter );
		}
	}

	/**
	 * @testdox Should retry cleanup when deleting an export fails.
	 */
	public function test_cleanup_export_retries_after_delete_failure(): void {
		$paths           = $this->create_export_files();
		$filename        = basename( $paths['body'] );
		$cleanup_hook    = ReportExporter::get_action( 'cleanup_export' );
		$cleanup_args    = $this->get_cleanup_action_args( $filename );
		$redirect_delete = static function ( $path ) {
			return $path . '.blocked';
		};
		$this->assertIsString( $cleanup_hook );
		touch( $paths['body'], time() - WEEK_IN_SECONDS - 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $paths['headers'], time() - WEEK_IN_SECONDS - 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		add_filter( 'wp_delete_file', $redirect_delete );

		try {
			ReportExporter::cleanup_export( ...$cleanup_args );

			$this->assertFileExists( $paths['body'], 'A failed cleanup should retain the export body.' );
			$this->assertFileExists( $paths['headers'], 'A failed cleanup should retain the export headers.' );
			$cleanup_event = wp_get_scheduled_event( $cleanup_hook, $cleanup_args );
			$this->assertNotFalse( $cleanup_event, 'A failed cleanup should schedule another attempt.' );
			$this->assertSame( 'daily', $cleanup_event->schedule, 'Cleanup should keep retrying until deletion succeeds.' );
			$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 5, $cleanup_event->timestamp );
			$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS + 5, $cleanup_event->timestamp );
			$cleanup_actions = as_get_scheduled_actions(
				array(
					'hook'     => $cleanup_hook,
					'args'     => $cleanup_args,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				)
			);
			$this->assertCount( 1, $cleanup_actions, 'A failed cleanup should also queue another attempt.' );
			$cleanup_action = reset( $cleanup_actions );
			$scheduled_date = $cleanup_action->get_schedule()->get_date();
			$this->assertNotNull( $scheduled_date );
			$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 5, $scheduled_date->getTimestamp() );
			$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS + 5, $scheduled_date->getTimestamp() );
		} finally {
			remove_filter( 'wp_delete_file', $redirect_delete );
			ReportExporter::cleanup_export( ...$cleanup_args );
		}
	}

	/**
	 * @testdox Should not delete a replacement export until it has been retained for seven days.
	 */
	public function test_cleanup_export_defers_recent_replacement(): void {
		$paths        = $this->create_export_files();
		$filename     = basename( $paths['body'] );
		$cleanup_hook = ReportExporter::get_action( 'cleanup_export' );
		$cleanup_args = $this->get_cleanup_action_args( $filename );
		$this->assertIsString( $cleanup_hook );

		try {
			ReportExporter::cleanup_export( ...$cleanup_args );

			$this->assertFileExists( $paths['body'], 'A recent replacement body should be retained.' );
			$this->assertFileExists( $paths['headers'], 'Recent replacement headers should be retained.' );
			$cleanup_event = wp_get_scheduled_event( $cleanup_hook, $cleanup_args );
			$this->assertNotFalse( $cleanup_event, 'Cleanup should remain scheduled for the replacement.' );
			$this->assertGreaterThanOrEqual( time() + WEEK_IN_SECONDS - 5, $cleanup_event->timestamp );
			$this->assertLessThanOrEqual( time() + WEEK_IN_SECONDS + 5, $cleanup_event->timestamp );
		} finally {
			wp_clear_scheduled_hook( $cleanup_hook, $cleanup_args );
			as_unschedule_all_actions( $cleanup_hook, $cleanup_args );
		}
	}

	/**
	 * @testdox Should cancel an invalid recurring cleanup job.
	 */
	public function test_cleanup_export_cancels_invalid_job(): void {
		$cleanup_hook = ReportExporter::get_action( 'cleanup_export' );
		$cleanup_args = array( '../invalid.csv' );
		$this->assertIsString( $cleanup_hook );
		$this->assertTrue( wp_schedule_event( time() + WEEK_IN_SECONDS, 'daily', $cleanup_hook, $cleanup_args ) );
		as_schedule_single_action( time() + WEEK_IN_SECONDS, $cleanup_hook, $cleanup_args, 'wc-admin-report-cleanup' );

		ReportExporter::cleanup_export( ...$cleanup_args );

		$this->assertFalse( wp_next_scheduled( $cleanup_hook, $cleanup_args ), 'The invalid WP-Cron job should be removed.' );
		$cleanup_actions = as_get_scheduled_actions(
			array(
				'hook'     => $cleanup_hook,
				'args'     => $cleanup_args,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			)
		);
		$this->assertCount( 0, $cleanup_actions, 'The invalid Action Scheduler job should be removed.' );
	}

	/**
	 * @testdox Should use only WP-Cron when Analytics Action Scheduler jobs are disabled.
	 */
	public function test_cleanup_schedule_respects_action_scheduler_filter(): void {
		$paths        = $this->create_export_files();
		$filename     = basename( $paths['body'] );
		$cleanup_hook = ReportExporter::get_action( 'cleanup_export' );
		$cleanup_args = $this->get_cleanup_action_args( $filename );
		$this->assertIsString( $cleanup_hook );
		add_filter( 'woocommerce_analytics_disable_action_scheduling', '__return_true' );

		try {
			$this->assertTrue( $this->schedule_export_cleanup( $filename ), 'WP-Cron should keep cleanup available.' );
			$this->assertNotFalse( wp_get_scheduled_event( $cleanup_hook, $cleanup_args ), 'A WP-Cron cleanup should be scheduled.' );
			$cleanup_actions = as_get_scheduled_actions(
				array(
					'hook'     => $cleanup_hook,
					'args'     => $cleanup_args,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				)
			);
			$this->assertCount( 0, $cleanup_actions, 'The filter should prevent the Action Scheduler fallback.' );
		} finally {
			remove_filter( 'woocommerce_analytics_disable_action_scheduling', '__return_true' );
			wp_clear_scheduled_hook( $cleanup_hook, $cleanup_args );
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
	 * Build the arguments used by an export cleanup job.
	 *
	 * @param string $filename Export filename.
	 * @return array{string} Cleanup arguments.
	 */
	private function get_cleanup_action_args( string $filename ): array {
		return array( $filename );
	}

	/**
	 * Schedule cleanup through the private production helper.
	 *
	 * @param string $filename Export filename.
	 * @return bool Whether a cleanup job exists.
	 */
	private function schedule_export_cleanup( string $filename ): bool {
		$method = new \ReflectionMethod( ReportExporter::class, 'schedule_export_cleanup' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $filename );
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
	 * Assert that the download handler terminates the request.
	 *
	 * @return void
	 */
	private function assert_download_request_terminates(): void {
		$request_terminated = false;

		try {
			ReportExporter::download_export_file();
		} catch ( \WPDieException $exception ) {
			$request_terminated = true;
		}

		$this->assertTrue( $request_terminated, 'A matched download error should terminate the request.' );
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
