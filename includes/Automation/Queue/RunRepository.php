<?php
/**
 * Automation runs (queue and log).
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Queue;

use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * One row per (lead, trigger, automation). It is the queue (status pending/retrying/running) and the
 * log (success/failed/skipped) at the same time. No payload is stored: it is rebuilt from the lead
 * when the run executes, so deleting a lead also removes everything personal about it here.
 */
final class RunRepository {

	public const PENDING   = 'pending';
	public const RUNNING   = 'running';
	public const RETRYING  = 'retrying';
	public const SUCCESS   = 'success';
	public const FAILED    = 'failed';
	public const SKIPPED   = 'skipped';
	public const CANCELLED = 'cancelled';

	/**
	 * Creates a pending run.
	 *
	 * @param int    $flow_id     Flow id.
	 * @param int    $lead_id     Lead id.
	 * @param string $trigger     Trigger key.
	 * @param string $action_id   Automation id inside the flow.
	 * @param string $action_type Action type.
	 * @param array  $context     Non-personal trigger data (for example status changes).
	 * @return int Run id, 0 on failure.
	 */
	public static function create( int $flow_id, int $lead_id, string $trigger, string $action_id, string $action_type, array $context = array() ): int {
		global $wpdb;
		$now = Clock::now();
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Tables::runs(),
			array(
				'flow_id'         => $flow_id,
				'lead_id'         => $lead_id,
				'trigger_key'     => $trigger,
				'action_id'       => $action_id,
				'action_type'     => $action_type,
				'context'         => array() === $context ? null : Json::encode( $context ),
				'status'          => self::PENDING,
				'attempts'        => 0,
				'max_attempts'    => Backoff::max_attempts(),
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finds a run.
	 *
	 * @param int $id Run id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Tables::runs();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Ids of runs that are due now, oldest first.
	 *
	 * @param int $limit Maximum ids.
	 * @return int[]
	 */
	public static function due( int $limit = 10 ): array {
		global $wpdb;
		$table = Tables::runs();
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('pending','retrying') AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d", Clock::now(), $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Atomically claims a run for execution. Only one worker can win.
	 *
	 * @param int $id Run id.
	 * @return bool True when this caller now owns the run.
	 */
	public static function claim( int $id ): bool {
		global $wpdb;
		$table = Tables::runs();
		$now   = Clock::now();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', locked_at = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ('pending','retrying') AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$now,
				$id,
				$now
			)
		);
		return 1 === (int) $rows;
	}

	/**
	 * Records the final outcome of a run.
	 *
	 * @param int    $id          Run id.
	 * @param string $status      success, failed, skipped or cancelled.
	 * @param array  $fields      http_status, error_code, error_message, duration_ms.
	 * @return void
	 */
	public static function finish( int $id, string $status, array $fields = array() ): void {
		self::update(
			$id,
			array_merge(
				array(
					'status'          => $status,
					'locked_at'       => null,
					'next_attempt_at' => null,
					'finished_at'     => Clock::now(),
				),
				self::summary_fields( $fields, self::SUCCESS === $status )
			)
		);
	}

	/**
	 * Marks a run to be retried later.
	 *
	 * @param int   $id            Run id.
	 * @param int   $delay_seconds Delay before the next attempt.
	 * @param array $fields        Failure summary of the attempt that just failed.
	 * @return void
	 */
	public static function retry_later( int $id, int $delay_seconds, array $fields = array() ): void {
		self::update(
			$id,
			array_merge(
				array(
					'status'          => self::RETRYING,
					'locked_at'       => null,
					'next_attempt_at' => Clock::now( $delay_seconds ),
				),
				self::summary_fields( $fields, false )
			)
		);
	}

	/**
	 * Puts a failed/skipped run back in the queue with a fresh attempt budget ("Try again" in the admin).
	 *
	 * @param int $id Run id.
	 * @return bool
	 */
	public static function requeue( int $id ): bool {
		$run = self::get( $id );
		if ( null === $run || ! in_array( $run['status'], array( self::FAILED, self::SKIPPED, self::CANCELLED ), true ) ) {
			return false;
		}
		self::update(
			$id,
			array(
				'status'          => self::PENDING,
				'attempts'        => 0,
				'next_attempt_at' => Clock::now(),
				'locked_at'       => null,
				'finished_at'     => null,
				'error_code'      => '',
				'error_message'   => '',
				'http_status'     => null,
			)
		);
		return true;
	}

	/**
	 * Re-opens runs that stayed "running" for too long (a request died mid-way).
	 *
	 * @param int $older_than Seconds.
	 * @return int Number of runs re-opened.
	 */
	public static function reopen_stuck( int $older_than = 300 ): int {
		global $wpdb;
		$table = Tables::runs();
		$now   = Clock::now();
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "UPDATE {$table} SET status = 'retrying', locked_at = NULL, next_attempt_at = %s, updated_at = %s WHERE status = 'running' AND locked_at < %s", $now, $now, Clock::now( -$older_than ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Lists runs for the admin log.
	 *
	 * @param array $args status, flow_id, lead_id, page, per_page.
	 * @return array{items:array[],total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table   = Tables::runs();
		$clauses = array( '1=1' );
		$values  = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( self::PENDING, self::RUNNING, self::RETRYING, self::SUCCESS, self::FAILED, self::SKIPPED, self::CANCELLED ), true ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $args['status'];
		}
		if ( ! empty( $args['flow_id'] ) ) {
			$clauses[] = 'flow_id = %d';
			$values[]  = (int) $args['flow_id'];
		}
		if ( ! empty( $args['lead_id'] ) ) {
			$clauses[] = 'lead_id = %d';
			$values[]  = (int) $args['lead_id'];
		}
		$where    = implode( ' AND ', $clauses );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( array() === $values ? $count_sql : $wpdb->prepare( $count_sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts per status (dashboard health).
	 *
	 * @return array<string,int>
	 */
	public static function counts(): array {
		global $wpdb;
		$table  = Tables::runs();
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Age in seconds of the oldest run that is due but not yet executed (queue lag), or null.
	 *
	 * @return int|null
	 */
	public static function oldest_due_age(): ?int {
		global $wpdb;
		$table = Tables::runs();
		$when  = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_attempt_at) FROM {$table} WHERE status IN ('pending','retrying') AND next_attempt_at <= %s", Clock::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $when ? max( 0, Clock::time() - (int) strtotime( $when . ' UTC' ) ) : null;
	}

	/**
	 * Failed runs finished in the last N seconds.
	 *
	 * @param int $seconds Window.
	 * @return int
	 */
	public static function failed_since( int $seconds ): int {
		global $wpdb;
		$table = Tables::runs();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND finished_at >= %s", Clock::now( -$seconds ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes finished runs older than a number of days, in a bounded batch (log retention).
	 *
	 * @param int $days  Age in days.
	 * @param int $limit Maximum rows.
	 * @return int
	 */
	public static function purge_older_than( int $days, int $limit = 500 ): int {
		global $wpdb;
		$table = Tables::runs();
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('success','failed','skipped','cancelled') AND created_at < %s ORDER BY id ASC LIMIT %d", Clock::now( -$days * DAY_IN_SECONDS ), $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Generic updater.
	 *
	 * @param int   $id   Run id.
	 * @param array $data Columns.
	 * @return void
	 */
	private static function update( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = Clock::now();
		$formats            = array();
		foreach ( $data as $column => $value ) {
			$formats[] = in_array( $column, array( 'attempts', 'http_status', 'duration_ms' ), true ) ? '%d' : '%s';
		}
		$wpdb->update( Tables::runs(), $data, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Keeps only the safe summary columns of an outcome.
	 *
	 * @param array $fields     Fields from a Result.
	 * @param bool  $clear_error Whether to clear the error columns (success).
	 * @return array
	 */
	private static function summary_fields( array $fields, bool $clear_error ): array {
		$out = array(
			'http_status'   => isset( $fields['http_status'] ) ? (int) $fields['http_status'] : null,
			'duration_ms'   => isset( $fields['duration_ms'] ) ? (int) $fields['duration_ms'] : null,
			'error_code'    => $clear_error ? '' : substr( (string) ( $fields['error_code'] ?? '' ), 0, 40 ),
			'error_message' => $clear_error ? '' : substr( (string) ( $fields['error_message'] ?? '' ), 0, 255 ),
		);
		return $out;
	}

	/**
	 * Casts a row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'flow_id', 'lead_id', 'attempts', 'max_attempts' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['http_status'] = null === $row['http_status'] ? null : (int) $row['http_status'];
		$row['duration_ms'] = null === $row['duration_ms'] ? null : (int) $row['duration_ms'];
		$row['context']     = Json::decode( (string) ( $row['context'] ?? '' ) );
		return $row;
	}
}
