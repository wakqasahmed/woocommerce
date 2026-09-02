<?php
/**
 * Handles reports CSV export.
 */

namespace Automattic\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Admin\Schedulers\SchedulerTraits;

/**
 * ReportExporter Class.
 */
class ReportExporter {
	/**
	 * Slug to identify the scheduler.
	 *
	 * @var string
	 */
	public static $name = 'report_exporter';

	/**
	 * Scheduler traits.
	 */
	use SchedulerTraits {
		init as scheduler_init;
	}

	/**
	 * Export status option name.
	 */
	const EXPORT_STATUS_OPTION = 'woocommerce_admin_report_export_status';

	/**
	 * Export file download action.
	 */
	const DOWNLOAD_EXPORT_ACTION = 'woocommerce_admin_download_report_csv';

	/**
	 * How long generated report exports remain available.
	 */
	private const EXPORT_RETENTION_PERIOD = 7 * DAY_IN_SECONDS;

	/**
	 * How often failed or missed cleanup attempts are retried.
	 */
	private const EXPORT_CLEANUP_RETRY_PERIOD = DAY_IN_SECONDS;

	/**
	 * Get all available scheduling actions.
	 * Used to determine action hook names and clear events.
	 *
	 * @return array
	 */
	public static function get_scheduler_actions() {
		return array(
			'export_report'              => 'woocommerce_admin_report_export',
			'email_report_download_link' => 'woocommerce_admin_email_report_download_link',
			'cleanup_export'             => 'woocommerce_admin_report_export_cleanup',
		);
	}

	/**
	 * Add action dependencies.
	 *
	 * @return array
	 */
	public static function get_dependencies() {
		return array(
			'email_report_download_link' => self::get_action( 'export_report' ),
		);
	}

	/**
	 * Hook in action methods.
	 */
	public static function init() {
		// Initialize scheduled action handlers.
		self::scheduler_init();

		// Report download handler.
		add_action( 'admin_init', array( __CLASS__, 'download_export_file' ) );
	}

	/**
	 * Queue up actions for a full report export.
	 *
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @param array  $report_args Report parameters, passed to data query.
	 * @param bool   $send_email Optional. Send an email when the export is complete.
	 * @return int Number of items to export.
	 */
	public static function queue_report_export( $export_id, $report_type, $report_args = array(), $send_email = false ) {
		$exporter = new ReportCSVExporter( $report_type, $report_args );
		$exporter->prepare_data_to_export();

		$total_rows  = $exporter->get_total_rows();
		$batch_size  = $exporter->get_limit();
		$num_batches = (int) ceil( $total_rows / $batch_size );

		// Create batches, like initial import.
		$report_batch_args = array( $export_id, $report_type, $report_args );

		if ( 0 < $num_batches ) {
			self::queue_batches( 1, $num_batches, 'export_report', $report_batch_args );

			if ( $send_email ) {
				$email_action_args = array( get_current_user_id(), $export_id, $report_type );
				self::schedule_action( 'email_report_download_link', $email_action_args );
			}
		}

		return $total_rows;
	}

	/**
	 * Process a report export action.
	 *
	 * @param int    $page_number Page number for this action.
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @param array  $report_args Report parameters, passed to data query.
	 * @return void
	 */
	public static function export_report( $page_number, $export_id, $report_type, $report_args ) {
		$report_args['page'] = $page_number;

		$filename = "wc-{$report_type}-report-export-{$export_id}";
		$exporter = new ReportCSVExporter( $report_type, $report_args );
		$exporter->set_filename( $filename );
		$exporter->generate_file();

		$percent_complete = $exporter->get_percent_complete();
		self::update_export_percentage_complete( $report_type, $export_id, $percent_complete );
		if ( 100 === $percent_complete ) {
			self::schedule_export_cleanup( $exporter->get_filename() );
		}
	}

	/**
	 * Generate a key to reference an export status.
	 *
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @return string Status key.
	 */
	protected static function get_status_key( $report_type, $export_id ) {
		return $report_type . ':' . $export_id;
	}

	/**
	 * Update the completion percentage of a report export.
	 *
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @param int    $percentage Completion percentage.
	 * @return void
	 */
	public static function update_export_percentage_complete( $report_type, $export_id, $percentage ) {
		$exports_status = get_option( self::EXPORT_STATUS_OPTION, array() );
		$status_key     = self::get_status_key( $report_type, $export_id );

		$exports_status[ $status_key ] = $percentage;

		update_option( self::EXPORT_STATUS_OPTION, $exports_status );
	}

	/**
	 * Get the completion percentage of a report export.
	 *
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @return bool|int Completion percentage, or false if export not found.
	 */
	public static function get_export_percentage_complete( $report_type, $export_id ) {
		$exports_status = get_option( self::EXPORT_STATUS_OPTION, array() );
		$status_key     = self::get_status_key( $report_type, $export_id );

		if ( isset( $exports_status[ $status_key ] ) ) {
			return $exports_status[ $status_key ];
		}

		return false;
	}

	/**
	 * Schedule cleanup for a completed report export.
	 *
	 * @param string $filename Export filename after filters have been applied.
	 * @param int    $delay    Delay before the first cleanup attempt.
	 * @return void
	 */
	private static function schedule_export_cleanup( $filename, $delay = self::EXPORT_RETENTION_PERIOD ) {
		$action_hook = self::get_action( 'cleanup_export' );
		if ( ! is_string( $action_hook ) ) {
			return;
		}

		$action_args = array( $filename );
		if ( wp_next_scheduled( $action_hook, $action_args ) ) {
			return;
		}

		$timestamp = time() + $delay;
		$scheduled = wp_schedule_event( $timestamp, 'daily', $action_hook, $action_args, true );
		if ( true === $scheduled ) {
			return;
		}

		$action_id = WC()->queue()->schedule_single( $timestamp, $action_hook, $action_args, (string) self::$group );
		if ( ! $action_id ) {
			wc_get_logger()->error(
				sprintf( 'Unable to schedule cleanup for report export %s.', $filename ),
				array( 'source' => 'report-exporter' )
			);
		}
	}

	/**
	 * Delete an expired report export.
	 *
	 * @internal
	 *
	 * @since 11.2.0
	 *
	 * @param string $filename Export filename after filters have been applied.
	 * @return void
	 */
	public static function cleanup_export( $filename ) {
		if ( ! is_string( $filename ) ) {
			return;
		}

		$exporter = new ReportCSVExporter();
		if ( ! $exporter->delete_file( $filename ) ) {
			wc_get_logger()->warning(
				sprintf( 'Unable to delete expired report export %s.', $filename ),
				array( 'source' => 'report-exporter' )
			);
			self::schedule_export_cleanup( $filename, self::EXPORT_CLEANUP_RETRY_PERIOD );
			return;
		}

		$action_hook = self::get_action( 'cleanup_export' );
		if ( is_string( $action_hook ) ) {
			wp_clear_scheduled_hook( $action_hook, array( $filename ) );
		}
	}

	/**
	 * Serve the export file.
	 */
	public static function download_export_file() {
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? wp_unslash( $_GET['action'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The value is only compared verbatim with a fixed action name.
		if ( self::DOWNLOAD_EXPORT_ACTION !== $action ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== strtoupper( $request_method ) ) {
			if ( ! headers_sent() ) {
				header( 'Allow: GET' );
			}
			status_header( 405 );
			return;
		}

		if ( ! current_user_can( 'view_woocommerce_reports' ) || empty( $_GET['filename'] ) || ! is_string( $_GET['filename'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Report downloads are capability-gated.
			return;
		}

		$exporter = new ReportCSVExporter();
		$exporter->set_filename( wp_unslash( $_GET['filename'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- set_filename() sanitizes the filename and keeps it inside the reports directory.
		if ( ! $exporter->send_file() ) {
			status_header( 404 );
			return;
		}

		exit;
	}

	/**
	 * Process a report export email action.
	 *
	 * @param int    $user_id User ID that requested the email.
	 * @param string $export_id Unique ID for report (timestamp expected).
	 * @param string $report_type Report type. E.g. 'customers'.
	 * @return void
	 */
	public static function email_report_download_link( $user_id, $export_id, $report_type ) {
		$percent_complete = self::get_export_percentage_complete( $report_type, $export_id );

		if ( 100 === $percent_complete ) {
			$query_args   = array(
				'action'   => self::DOWNLOAD_EXPORT_ACTION,
				'filename' => "wc-{$report_type}-report-export-{$export_id}",
			);
			$download_url = add_query_arg( $query_args, admin_url() );

			\WC_Emails::instance();
			$email = new ReportCSVEmail();
			$email->trigger( $user_id, $report_type, $download_url );
		}
	}
}
