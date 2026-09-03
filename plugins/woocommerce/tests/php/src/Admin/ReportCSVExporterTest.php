<?php
/**
 * Tests for the report CSV exporter.
 *
 * @package WooCommerce\Tests\Admin
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin;

use Automattic\WooCommerce\Admin\ReportCSVExporter;
use Automattic\WooCommerce\Admin\ReportExporter;
use WC_Unit_Test_Case;

/**
 * Tests for the report CSV exporter.
 */
class ReportCSVExporterTest extends WC_Unit_Test_Case {
	/**
	 * Export filename without the extension.
	 *
	 * @var string
	 */
	private $filename;

	/**
	 * Full path to the export body.
	 *
	 * @var string
	 */
	private $file_path;

	/**
	 * Full path to the export header row.
	 *
	 * @var string
	 */
	private $headers_path;

	/**
	 * Set up the export paths.
	 */
	public function setUp(): void {
		parent::setUp();

		ReportCSVExporter::maybe_create_directory();
		$this->filename     = 'wc-orders-report-export-' . wp_generate_uuid4();
		$this->file_path    = ReportCSVExporter::get_reports_directory() . sanitize_file_name( $this->filename . '.csv' );
		$this->headers_path = $this->file_path . '.headers';
	}

	/**
	 * Remove files that are not covered by the database transaction.
	 */
	public function tearDown(): void {
		try {
			wp_delete_file( $this->file_path );
			wp_delete_file( $this->headers_path );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should stream a complete export repeatedly without deleting it.
	 */
	public function test_send_file_is_repeatable(): void {
		file_put_contents( $this->headers_path, "column\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $this->file_path, "value\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		$exporter = $this->create_exporter();

		ob_start();
		$first_result = $this->send_file( $exporter );
		$first_output = ob_get_clean();

		ob_start();
		$second_result = $this->send_file( $exporter );
		$second_output = ob_get_clean();

		$this->assertTrue( $first_result, 'The first download should succeed.' );
		$this->assertTrue( $second_result, 'The repeated download should succeed.' );
		$this->assertSame( "column\nvalue\n", $first_output, 'The first download should contain the complete CSV.' );
		$this->assertSame( $first_output, $second_output, 'A repeated download should return identical bytes.' );
		$this->assertFileExists( $this->file_path, 'The export body should be retained.' );
		$this->assertFileExists( $this->headers_path, 'The export headers should be retained.' );
	}

	/**
	 * @testdox Should reject an incomplete export without creating or deleting files.
	 *
	 * @testWith ["body"]
	 *           ["headers"]
	 *
	 * @param string $existing_part The part of the export that exists.
	 */
	public function test_send_file_rejects_incomplete_export( string $existing_part ): void {
		$existing_path = 'body' === $existing_part ? $this->file_path : $this->headers_path;
		$missing_path  = 'body' === $existing_part ? $this->headers_path : $this->file_path;
		file_put_contents( $existing_path, 'contents' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an incomplete export fixture.
		$exporter = $this->create_exporter();

		ob_start();
		$result = $this->send_file( $exporter );
		$output = ob_get_clean();

		$this->assertFalse( $result, 'An incomplete export should not be sent.' );
		$this->assertSame( '', $output, 'An incomplete export should not emit partial content.' );
		$this->assertFileExists( $existing_path, 'The existing part should not be consumed.' );
		$this->assertFileDoesNotExist( $missing_path, 'The missing part should not be created as an empty file.' );
	}

	/**
	 * @testdox Should remove stale headers when restarting an export.
	 */
	public function test_first_batch_removes_stale_headers(): void {
		file_put_contents( $this->headers_path, "old-column\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating a stale export fixture.
		file_put_contents( $this->file_path, "old-value\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating a stale export fixture.

		$exporter = new class() extends ReportCSVExporter {
			/**
			 * Prepare a partial first batch.
			 */
			public function prepare_data_to_export() {
				$this->total_rows = 2;
				$this->row_data   = array( array( 'value' => 'new-value' ) );
			}
		};
		$exporter->set_filename( $this->filename );
		$exporter->set_column_names( array( 'value' => 'value' ) );
		$exporter->set_limit( 1 );

		$exporter->generate_file();

		$this->assertStringContainsString( 'new-value', (string) file_get_contents( $this->file_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the generated export fixture.
		$this->assertFileDoesNotExist( $this->headers_path, 'A partial replacement export should not retain headers from the previous export.' );

		ob_start();
		$result = $this->send_file( $exporter );
		$output = ob_get_clean();

		$this->assertFalse( $result, 'A partial replacement export should not be downloadable.' );
		$this->assertSame( '', $output, 'A partial replacement export should not emit stale content.' );
	}

	/**
	 * @testdox Should delete only the export body and headers and remain safe to repeat.
	 */
	public function test_delete_file_is_exact_and_idempotent(): void {
		$unrelated_path = ReportCSVExporter::get_reports_directory() . 'unrelated-' . wp_generate_uuid4() . '.csv';
		file_put_contents( $this->headers_path, 'headers' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $this->file_path, 'body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $unrelated_path, 'unrelated' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an unrelated cleanup fixture.
		try {
			$this->assertTrue( $this->delete_file(), 'The complete export should be deleted.' );
			$this->assertTrue( $this->delete_file(), 'Deleting an already missing export should succeed.' );
			$this->assertFileDoesNotExist( $this->file_path, 'The export body should be removed.' );
			$this->assertFileDoesNotExist( $this->headers_path, 'The export headers should be removed.' );
			$this->assertFileExists( $unrelated_path, 'Cleanup should not remove unrelated files.' );
		} finally {
			wp_delete_file( $unrelated_path );
		}
	}

	/**
	 * Create an exporter that does not send HTTP headers during tests.
	 *
	 * @return ReportCSVExporter
	 */
	private function create_exporter(): ReportCSVExporter {
		$exporter = new class() extends ReportCSVExporter {
			/**
			 * Suppress HTTP headers in unit tests.
			 */
			public function send_headers() {
			}
		};
		$exporter->set_filename( $this->filename );

		return $exporter;
	}

	/**
	 * Invoke the report handler's private streaming helper.
	 *
	 * @param ReportCSVExporter $exporter Exporter used to send download headers.
	 * @return bool Whether the response started.
	 */
	private function send_file( ReportCSVExporter $exporter ): bool {
		$method = new \ReflectionMethod( ReportExporter::class, 'send_export_file' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $exporter, basename( $this->file_path ) );
	}

	/**
	 * Invoke the report handler's private deletion helper.
	 *
	 * @return bool Whether both export files are absent.
	 */
	private function delete_file(): bool {
		$method = new \ReflectionMethod( ReportExporter::class, 'delete_export_file' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, basename( $this->file_path ) );
	}
}
