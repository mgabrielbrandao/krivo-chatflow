<?php
/**
 * Queue worker.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Queue;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Infrastructure\Logging\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Executes queued automation runs (e-mail, webhook) with bounded retries.
 *
 * A run is claimed with an atomic UPDATE so two overlapping cron requests never run it twice; every
 * action runs inside try/catch so one broken action cannot stop the queue; failures that are worth a
 * retry are rescheduled with a growing delay and, after the last attempt, marked failed.
 */
final class Worker {

	private const LOCK_OPTION  = 'krivo_chatflow_queue_lock';
	private const LOCK_SECONDS = 60;
	private const TIME_BUDGET  = 20;

	/**
	 * Cron entry point: processes everything that is due, within a time budget.
	 *
	 * @return int Number of runs executed.
	 */
	public static function process(): int {
		if ( ! self::lock() ) {
			return 0;
		}
		$executed = 0;
		$deadline = microtime( true ) + self::TIME_BUDGET;
		try {
			while ( microtime( true ) < $deadline ) {
				$ids = RunRepository::due( 10 );
				if ( array() === $ids ) {
					break;
				}
				foreach ( $ids as $id ) {
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}
					self::execute( $id );
					++$executed;
				}
			}
		} finally {
			self::unlock();
		}

		// Anything that became due while we were busy (a kick found the lock taken) must not wait for
		// the hourly maintenance: schedule another pass.
		if ( array() !== RunRepository::due( 1 ) ) {
			Scheduler::kick();
		} else {
			self::schedule_next_retry();
		}
		return $executed;
	}

	/**
	 * Executes one run.
	 *
	 * @param int $id Run id.
	 * @return void
	 */
	public static function execute( int $id ): void {
		if ( ! RunRepository::claim( $id ) ) {
			return;
		}
		$run = RunRepository::get( $id );
		if ( null === $run ) {
			return;
		}

		$flow_row = FlowRepository::get( $run['flow_id'] );
		$lead     = LeadRepository::get( $run['lead_id'] );
		if ( null === $flow_row || null === $lead ) {
			RunRepository::finish( $id, RunRepository::CANCELLED, array( 'error_code' => 'gone', 'error_message' => 'The flow or the lead no longer exists.' ) );
			return;
		}

		$flow       = $flow_row['published'] ?? $flow_row['draft'];
		$automation = null;
		foreach ( (array) $flow['automations'] as $candidate ) {
			if ( $candidate['id'] === $run['action_id'] ) {
				$automation = $candidate;
			}
		}
		$action = null === $automation ? null : Registry::action( (string) $automation['type'] );
		if ( null === $automation || null === $action || empty( $automation['enabled'] ) ) {
			RunRepository::finish( $id, RunRepository::CANCELLED, array( 'error_code' => 'removed', 'error_message' => 'The automation was removed or turned off.' ) );
			return;
		}

		$context         = new RunContext( $run['flow_id'], $flow, $lead, $run['trigger_key'], (array) $run['context'] );
		$context->run_id = $id;

		try {
			$result = $action->run( $context, (array) $automation['config'] );
		} catch ( \Throwable $e ) {
			$result = Result::failure( 'exception', Redactor::clean( get_class( $e ) . ': ' . $e->getMessage() ), false );
		}

		$fields = array(
			'http_status'   => $result->http_status,
			'duration_ms'   => $result->duration_ms,
			'error_code'    => $result->error_code,
			'error_message' => $result->error_message,
		);

		if ( Result::SUCCESS === $result->status ) {
			RunRepository::finish( $id, RunRepository::SUCCESS, $fields );
			return;
		}
		if ( Result::SKIPPED === $result->status ) {
			RunRepository::finish( $id, RunRepository::SKIPPED, $fields );
			return;
		}

		if ( $result->retry && $run['attempts'] < $run['max_attempts'] ) {
			RunRepository::retry_later( $id, Backoff::delay( $run['attempts'] ), $fields );
			return;
		}

		RunRepository::finish( $id, RunRepository::FAILED, $fields );
		/**
		 * Fires when a run used all its attempts (or failed permanently).
		 *
		 * @param array $run Run row.
		 */
		do_action( 'krivo_chatflow_run_failed', RunRepository::get( $id ) );
	}

	/**
	 * Takes the worker lock.
	 *
	 * @return bool
	 */
	private static function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION );
		if ( time() - $held >= self::LOCK_SECONDS ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Releases the worker lock.
	 *
	 * @return void
	 */
	private static function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Schedules a wake-up for the earliest pending retry.
	 *
	 * @return void
	 */
	private static function schedule_next_retry(): void {
		global $wpdb;
		$table = \Krivo\ChatFlow\Infrastructure\Db\Tables::runs();
		$next  = $wpdb->get_var( "SELECT MIN(next_attempt_at) FROM {$table} WHERE status IN ('pending','retrying')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $next ) {
			$delay = max( 1, (int) strtotime( $next . ' UTC' ) - time() );
			Scheduler::kick( $delay );
		}
	}
}
