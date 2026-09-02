<?php
/**
 * Tests for the report CSV exporter.
 *
 * @package WooCommerce\Tests\Admin
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Admin;

use Automattic\WooCommerce\Admin\ReportCSVExporter;
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
		$first_result = $exporter->send_file();
		$first_output = ob_get_clean();

		ob_start();
		$second_result = $exporter->send_file();
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
		$result = $exporter->send_file();
		$output = ob_get_clean();

		$this->assertFalse( $result, 'An incomplete export should not be sent.' );
		$this->assertSame( '', $output, 'An incomplete export should not emit partial content.' );
		$this->assertFileExists( $existing_path, 'The existing part should not be consumed.' );
		$this->assertFileDoesNotExist( $missing_path, 'The missing part should not be created as an empty file.' );
	}

	/**
	 * @testdox Should delete only the export body and headers and remain safe to repeat.
	 */
	public function test_delete_file_is_exact_and_idempotent(): void {
		$unrelated_path = ReportCSVExporter::get_reports_directory() . 'unrelated-' . wp_generate_uuid4() . '.csv';
		file_put_contents( $this->headers_path, 'headers' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $this->file_path, 'body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an export fixture.
		file_put_contents( $unrelated_path, 'unrelated' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Creating an unrelated cleanup fixture.
		$exporter = $this->create_exporter();

		try {
			$this->assertTrue( $exporter->delete_file(), 'The complete export should be deleted.' );
			$this->assertTrue( $exporter->delete_file(), 'Deleting an already missing export should succeed.' );
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
}
