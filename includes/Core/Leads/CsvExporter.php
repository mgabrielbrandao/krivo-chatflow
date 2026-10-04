<?php
/**
 * Lead CSV export.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Leads;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Streams leads as CSV in batches, so memory stays flat with thousands of leads.
 *
 * - UTF-8 with BOM, so Excel opens accents correctly; delimiter "," or ";" (Excel in many locales).
 * - Formula injection: a cell that a spreadsheet could execute (starts with = + - @ tab or CR) is
 *   prefixed with an apostrophe, unless it is a plain number.
 * - Requires the export capability and a nonce (it reads personal data).
 */
final class CsvExporter {

	public const ACTION = 'krivo_chatflow_export_leads';

	/**
	 * Hooks the admin-post handler (logged-in users only: there is no nopriv variant).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * URL (with nonce) the admin screen links to; filters are appended by the screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Handles the download.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::EXPORT_LEADS ) ) {
			wp_die( esc_html__( 'You are not allowed to export leads.', 'krivo-chatflow' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$filters   = self::filters_from_request();
		$delimiter = isset( $_GET['delimiter'] ) && ';' === sanitize_text_field( wp_unslash( $_GET['delimiter'] ) ) ? ';' : ','; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'krivo-chatflow-leads-' . wp_date( 'Ymd-His' ) . '.csv' ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the output, not the file system.
		$out = fopen( 'php://output', 'w' );
		self::write( $out, $filters, $delimiter );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Writes the whole CSV to a stream.
	 *
	 * @param resource $stream    Output stream.
	 * @param array    $filters   Lead filters (see LeadRepository::query).
	 * @param string   $delimiter "," or ";".
	 * @return int Rows written (without the header).
	 */
	public static function write( $stream, array $filters, string $delimiter = ',' ): int {
		fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the output.

		$flow_titles = array();
		foreach ( FlowRepository::all() as $flow ) {
			$flow_titles[ $flow['id'] ] = $flow['title'];
		}

		// With a single flow selected, every question becomes its own column.
		$columns = array();
		if ( ! empty( $filters['flow_id'] ) ) {
			LeadRepository::each(
				$filters,
				static function ( $lead ) use ( &$columns ) {
					foreach ( (array) $lead['answers'] as $key => $answer ) {
						if ( is_array( $answer ) && ! isset( $columns[ $key ] ) ) {
							$columns[ $key ] = (string) ( $answer['label'] ?? $key );
						}
					}
				}
			);
		}

		$header = array(
			__( 'ID', 'krivo-chatflow' ),
			__( 'Date', 'krivo-chatflow' ),
			__( 'Chat', 'krivo-chatflow' ),
			__( 'Name', 'krivo-chatflow' ),
			__( 'E-mail', 'krivo-chatflow' ),
			__( 'Phone', 'krivo-chatflow' ),
			__( 'Status', 'krivo-chatflow' ),
			__( 'Channel', 'krivo-chatflow' ),
			__( 'Destination', 'krivo-chatflow' ),
			__( 'Within business hours', 'krivo-chatflow' ),
			__( 'Device', 'krivo-chatflow' ),
			__( 'Page', 'krivo-chatflow' ),
			__( 'Page title', 'krivo-chatflow' ),
			__( 'Referrer', 'krivo-chatflow' ),
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			__( 'Consent', 'krivo-chatflow' ),
		);
		$header = array_merge( $header, array() === $columns ? array( __( 'Answers', 'krivo-chatflow' ) ) : array_values( $columns ) );
		self::row( $stream, $header, $delimiter );

		$count = 0;
		LeadRepository::each(
			$filters,
			static function ( $lead ) use ( $stream, $delimiter, $columns, $flow_titles, &$count ) {
				$cells = array(
					$lead['id'],
					wp_date( 'Y-m-d H:i:s', strtotime( $lead['created_at'] . ' UTC' ) ),
					$flow_titles[ $lead['flow_id'] ] ?? '',
					$lead['name'],
					$lead['email'],
					$lead['phone'],
					$lead['status'],
					$lead['channel'],
					$lead['destination_label'],
					null === $lead['in_hours'] ? '' : ( $lead['in_hours'] ? __( 'Yes', 'krivo-chatflow' ) : __( 'No', 'krivo-chatflow' ) ),
					$lead['device'],
					$lead['page_url'],
					$lead['page_title'],
					$lead['referrer'],
					$lead['utm_source'],
					$lead['utm_medium'],
					$lead['utm_campaign'],
					$lead['utm_content'],
					$lead['utm_term'],
					array() === (array) $lead['consent'] ? '' : __( 'Accepted', 'krivo-chatflow' ),
				);
				if ( array() === $columns ) {
					$parts = array();
					foreach ( (array) $lead['answers'] as $key => $answer ) {
						if ( is_array( $answer ) ) {
							$parts[] = ( $answer['label'] ?? $key ) . ': ' . ( $answer['display'] ?? '' );
						}
					}
					$cells[] = implode( ' | ', $parts );
				} else {
					foreach ( array_keys( $columns ) as $key ) {
						$cells[] = isset( $lead['answers'][ $key ]['display'] ) ? (string) $lead['answers'][ $key ]['display'] : '';
					}
				}
				self::row( $stream, $cells, $delimiter );
				++$count;
			}
		);
		return $count;
	}

	/**
	 * Neutralises a value that a spreadsheet could interpret as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $text || 1 === preg_match( '/^[+-]?\d+(\.\d+)?$/', $text ) ) {
			return $text;
		}
		return 1 === preg_match( '/^[=+\-@\t\r]/', $text ) ? "'" . $text : $text;
	}

	/**
	 * Writes one row.
	 *
	 * @param resource $stream    Stream.
	 * @param array    $cells     Cells.
	 * @param string   $delimiter Delimiter.
	 * @return void
	 */
	private static function row( $stream, array $cells, string $delimiter ): void {
		fputcsv( $stream, array_map( array( __CLASS__, 'cell' ), $cells ), $delimiter, '"', '\\' );
	}

	/**
	 * Lead filters from the query string (after the nonce was verified).
	 *
	 * @return array
	 */
	private static function filters_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() in handle().
		$get = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable
		return array_filter(
			array(
				'status'  => $get( 'status' ),
				'flow_id' => absint( $get( 'flow' ) ),
				'from'    => $get( 'from' ),
				'to'      => $get( 'to' ),
				'search'  => $get( 'search' ),
			)
		);
	}
}
