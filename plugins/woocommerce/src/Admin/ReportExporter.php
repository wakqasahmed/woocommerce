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
	 * Action Scheduler group for export cleanup jobs.
	 */
	private const EXPORT_CLEANUP_GROUP = 'wc-admin-report-cleanup';

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

		self::init_retained_exports();
	}

	/**
	 * Hook in handlers needed for retained exports when Analytics is disabled.
	 *
	 * @internal
	 * @since 11.2.0
	 * @return void
	 */
	public static function init_retained_exports() {
		$cleanup_hook = self::get_action( 'cleanup_export' );
		if ( is_string( $cleanup_hook ) ) {
			add_action( $cleanup_hook, array( __CLASS__, 'do_action_or_reschedule' ), 10, 3 );
		}

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
	 * @throws \RuntimeException When a completed export can neither be retained safely nor deleted.
	 */
	public static function export_report( $page_number, $export_id, $report_type, $report_args ) {
		$report_args['page'] = $page_number;

		$filename = "wc-{$report_type}-report-export-{$export_id}";
		$exporter = new ReportCSVExporter( $report_type, $report_args );
		$exporter->set_filename( $filename );
		$filename         = $exporter->get_filename();
		$is_initial_batch = 1 === (int) $page_number;
		if ( $is_initial_batch ) {
			self::cancel_export_cleanup( $filename );
		}
		$exporter->generate_file();

		$percent_complete  = $exporter->get_percent_complete();
		$cleanup_scheduled = true;
		if ( $is_initial_batch || 100 === $percent_complete ) {
			$cleanup_scheduled = self::schedule_export_cleanup( $filename );
		}
		if ( 100 === $percent_complete && ! $cleanup_scheduled ) {
			if ( ! self::delete_export_file( $filename ) ) {
				wc_get_logger()->error(
					sprintf( 'Unable to retain or delete report export %s.', $filename ),
					array( 'source' => 'report-exporter' )
				);
				throw new \RuntimeException( 'A completed report export could not be scheduled for cleanup or removed.' );
			}
			return;
		}

		self::update_export_percentage_complete( $report_type, $export_id, $percent_complete );
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
	 * @param string      $filename  Export filename after filters have been applied.
	 * @param int         $delay     Delay before the first cleanup attempt.
	 * @param string|null $directory Reports directory used when the cleanup is scheduled.
	 * @return bool True when at least one cleanup job exists.
	 */
	private static function schedule_export_cleanup( $filename, $delay = self::EXPORT_RETENTION_PERIOD, $directory = null ) {
		$action_hook = self::get_action( 'cleanup_export' );
		$action_args = self::get_export_cleanup_args( $filename, $directory );
		if ( ! is_string( $action_hook ) || false === $action_args ) {
			return false;
		}

		$timestamp = time() + $delay;

		$cron_scheduled = (bool) wp_next_scheduled( $action_hook, $action_args );
		if ( ! $cron_scheduled ) {
			$cron_scheduled = true === wp_schedule_event( $timestamp, 'daily', $action_hook, $action_args, true );
		}

		$queue_scheduled = false;
		/**
		 * Whether to disable Action Scheduler for Analytics jobs.
		 *
		 * @since 4.0.0
		 *
		 * @param bool $disable_action_scheduling Whether scheduling is disabled.
		 */
		$action_scheduling_disabled = apply_filters( 'woocommerce_analytics_disable_action_scheduling', false );
		if ( get_option( 'schema-ActionScheduler_StoreSchema' ) && ! $action_scheduling_disabled ) {
			$queue_scheduled = (bool) self::queue()->search(
				array(
					'hook'     => $action_hook,
					'args'     => $action_args,
					'group'    => self::EXPORT_CLEANUP_GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
			if ( ! $queue_scheduled ) {
				$queue_scheduled = (bool) self::queue()->schedule_single( $timestamp, $action_hook, $action_args, self::EXPORT_CLEANUP_GROUP );
			}
		}

		if ( ! $cron_scheduled && ! $queue_scheduled ) {
			wc_get_logger()->error(
				sprintf( 'Unable to schedule cleanup for report export %s.', $filename ),
				array( 'source' => 'report-exporter' )
			);
		}

		return $cron_scheduled || $queue_scheduled;
	}

	/**
	 * Cancel cleanup jobs for one resolved export filename.
	 *
	 * @param string      $filename  Export filename after filters have been applied.
	 * @param string|null $directory Reports directory used when the cleanup was scheduled.
	 * @return void
	 */
	private static function cancel_export_cleanup( $filename, $directory = null ) {
		$action_hook = self::get_action( 'cleanup_export' );
		$action_args = self::get_export_cleanup_args( $filename, $directory );
		if ( ! is_string( $action_hook ) || false === $action_args ) {
			return;
		}

		wp_clear_scheduled_hook( $action_hook, $action_args );
		if ( get_option( 'schema-ActionScheduler_StoreSchema' ) ) {
			self::queue()->cancel_all( $action_hook, $action_args, self::EXPORT_CLEANUP_GROUP );
		}
	}

	/**
	 * Build arguments that bind a cleanup job to its original reports directory.
	 *
	 * @param mixed $filename  Export filename after filters have been applied.
	 * @param mixed $directory Reports directory used when the cleanup was scheduled.
	 * @return array{string, string, string}|false Cleanup arguments, or false when invalid.
	 */
	private static function get_export_cleanup_args( $filename, $directory = null ) {
		$directory = null === $directory ? ReportCSVExporter::get_reports_directory() : $directory;
		$paths     = self::get_export_file_paths( $filename, $directory );
		if ( false === $paths ) {
			return false;
		}

		$directory = trailingslashit( wp_normalize_path( $directory ) );
		$checksum  = hash( 'sha256', $filename . "\n" . $directory );

		return array( $filename, $directory, $checksum );
	}

	/**
	 * Get the exact paths for a resolved export filename.
	 *
	 * @param mixed $filename  Export filename after filters have been applied.
	 * @param mixed $directory Reports directory containing the export.
	 * @return array{body: string, headers: string}|false Export paths, or false for an invalid filename.
	 */
	private static function get_export_file_paths( $filename, $directory = null ) {
		if (
			! is_string( $filename )
			|| '' === $filename
			|| '.' === $filename
			|| '..' === $filename
			|| false !== strpos( $filename, '/' )
			|| false !== strpos( $filename, '\\' )
			|| false !== strpos( $filename, "\0" )
		) {
			return false;
		}

		$directory = null === $directory ? ReportCSVExporter::get_reports_directory() : $directory;
		if ( ! is_string( $directory ) || '' === $directory || false !== strpos( $directory, "\0" ) ) {
			return false;
		}

		$directory = trailingslashit( wp_normalize_path( $directory ) );
		$suffix    = 'woocommerce_uploads/reports/';
		if ( substr( $directory, -strlen( $suffix ) ) !== $suffix ) {
			return false;
		}

		$body = $directory . $filename;
		return array(
			'body'    => $body,
			'headers' => $body . '.headers',
		);
	}

	/**
	 * Get the time after which both parts of an export may be removed.
	 *
	 * @param array{body: string, headers: string} $paths Export paths.
	 * @return int|false Expiration timestamp, or false when both files are absent.
	 */
	private static function get_export_expiration( $paths ) {
		$latest_modified = false;
		foreach ( $paths as $path ) {
			clearstatcache( true, $path );
			if ( ! file_exists( $path ) && ! is_link( $path ) ) {
				continue;
			}

			$modified = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file can be deleted between the existence and timestamp checks.
			if ( false !== $modified ) {
				$latest_modified = false === $latest_modified ? $modified : max( $latest_modified, $modified );
			}
		}

		return false === $latest_modified ? false : $latest_modified + self::EXPORT_RETENTION_PERIOD;
	}

	/**
	 * Stream a complete export without deleting it.
	 *
	 * @param ReportCSVExporter $exporter Exporter used to send download headers.
	 * @param string            $filename Resolved export filename.
	 * @return bool True when the response started, false when either export part is unavailable.
	 */
	private static function send_export_file( $exporter, $filename ) {
		$paths = self::get_export_file_paths( $filename );
		if ( false === $paths ) {
			return false;
		}

		$headers_handle = @fopen( $paths['headers'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- The export can expire or be deleted between validation and opening it.
		if ( false === $headers_handle ) {
			return false;
		}

		$file_handle = @fopen( $paths['body'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- The export can expire or be deleted between validation and opening it.
		if ( false === $file_handle ) {
			fclose( $headers_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		$exporter->send_headers();

		try {
			$headers_sent = false !== fpassthru( $headers_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru, WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming a generated CSV download.
			$file_sent    = false !== fpassthru( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru, WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming a generated CSV download.
		} finally {
			fclose( $headers_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $file_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( ! $headers_sent || ! $file_sent ) {
			wc_get_logger()->error(
				'Unable to finish streaming a report export.',
				array( 'source' => 'report-exporter' )
			);
		}

		// Headers and possibly part of the body have already been sent, so the request must terminate.
		return true;
	}

	/**
	 * Delete both parts of one resolved export.
	 *
	 * @param string      $filename  Export filename after filters have been applied.
	 * @param string|null $directory Reports directory containing the export.
	 * @return bool True when both files are absent after cleanup.
	 */
	private static function delete_export_file( $filename, $directory = null ) {
		$paths = self::get_export_file_paths( $filename, $directory );
		if ( false === $paths ) {
			return false;
		}

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				wp_delete_file( $path );
			}
		}

		return ! file_exists( $paths['body'] ) && ! is_link( $paths['body'] ) && ! file_exists( $paths['headers'] ) && ! is_link( $paths['headers'] );
	}

	/**
	 * Delete an expired report export.
	 *
	 * @internal
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $filename  Export filename after filters have been applied.
	 * @param mixed $directory Reports directory used when cleanup was scheduled.
	 * @param mixed $checksum  Checksum binding the filename to the directory.
	 * @return void
	 */
	public static function cleanup_export( $filename, $directory, $checksum ) {
		$action_args = self::get_export_cleanup_args( $filename, $directory );
		if ( false === $action_args || ! is_string( $checksum ) || ! hash_equals( $action_args[2], $checksum ) ) {
			return;
		}

		$paths      = self::get_export_file_paths( $filename, $directory );
		$expires_at = false === $paths ? false : self::get_export_expiration( $paths );
		if ( false !== $expires_at && time() < $expires_at ) {
			self::schedule_export_cleanup( $filename, max( 1, $expires_at - time() ), $directory );
			return;
		}

		if ( ! self::delete_export_file( $filename, $directory ) ) {
			wc_get_logger()->warning(
				sprintf( 'Unable to delete expired report export %s.', $filename ),
				array( 'source' => 'report-exporter' )
			);
			self::schedule_export_cleanup( $filename, self::EXPORT_CLEANUP_RETRY_PERIOD, $directory );
			return;
		}

		self::cancel_export_cleanup( $filename, $directory );
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
		$filename = $exporter->get_filename();
		if ( ! self::send_export_file( $exporter, $filename ) ) {
			status_header( 404 );
			return;
		}

		if ( ! self::schedule_export_cleanup( $filename ) && ! self::delete_export_file( $filename ) ) {
			wc_get_logger()->error(
				sprintf( 'Unable to retain or delete report export %s after download.', $filename ),
				array( 'source' => 'report-exporter' )
			);
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
