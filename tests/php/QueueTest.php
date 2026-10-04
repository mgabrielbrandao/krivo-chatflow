<?php
/**
 * Tests for the automation queue: runs, claiming, retries with backoff, cancellation, the worker lock
 * and the scheduler. Webhooks are delivered to a real local receiver.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Queue\Backoff;
use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Automation\Queue\Scheduler;
use Krivo\ChatFlow\Automation\Queue\Worker;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Infrastructure\Cron\Events;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

/**
 * @group queue
 */
class QueueTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		StubServer::start();
	}

	public static function tear_down_after_class(): void {
		StubServer::stop();
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		StubServer::clear();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		unset( $_SERVER['HTTP_ORIGIN'] );
		delete_option( 'krivo_chatflow_queue_lock' );
		wp_clear_scheduled_hook( Events::QUEUE );
		reset_phpmailer_instance();
	}

	/**
	 * A webhook connection to the receiver.
	 *
	 * @param string $path Receiver path.
	 * @return string Connection id.
	 */
	private function hook( string $path ): string {
		$view = ConnectionRepository::save(
			array(
				'name'                => 'Receiver',
				'url'                 => StubServer::url( $path ),
				'allow_http'          => true,
				'allow_private_hosts' => true,
				'acknowledged'        => true,
				'enabled'             => true,
				'timeout'             => 5,
			),
			1
		);
		$this->assertIsArray( $view );
		return $view['id'];
	}

	/**
	 * Publishes a flow with one webhook automation and submits it once.
	 *
	 * @param string $path Receiver path.
	 * @return array{flow:int,lead:array,run:array}
	 */
	private function webhook_run( string $path ): array {
		$connection = $this->hook( $path );
		$flow       = $this->flow(
			array(
				array( 'id' => 'a_hook', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => $connection ) ),
			)
		);
		$result = $this->submit( $flow, $this->valid_answers() );
		$this->assertIsArray( $result );
		$lead = LeadRepository::get_by_public_id( $result['lead'] );
		$runs = RunRepository::query( array( 'lead_id' => $lead['id'] ) );
		$this->assertSame( 1, $runs['total'] );
		return array(
			'flow' => $flow,
			'lead' => $lead,
			'run'  => $runs['items'][0],
		);
	}

	// ------------------------------------------------------------------ backoff

	public function test_backoff_grows_and_is_capped(): void {
		$this->assertSame( 3, Backoff::max_attempts() );
		$this->assertSame( 60, Backoff::delay( 1 ) );
		$this->assertSame( 300, Backoff::delay( 2 ) );
		$this->assertSame( 1800, Backoff::delay( 3 ) );
		$this->assertSame( 7200, Backoff::delay( 99 ), 'Never beyond the last step' );
		$this->assertSame( 60, Backoff::delay( 0 ) );
	}

	public function test_attempts_are_clamped_between_one_and_five(): void {
		add_filter( 'krivo_chatflow_max_attempts', static fn() => 50 );
		$this->assertSame( 5, Backoff::max_attempts() );
		remove_all_filters( 'krivo_chatflow_max_attempts' );
		add_filter( 'krivo_chatflow_max_attempts', static fn() => 0 );
		$this->assertSame( 1, Backoff::max_attempts() );
	}

	// ------------------------------------------------------------------ runs

	public function test_a_run_is_claimed_by_exactly_one_worker(): void {
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		$this->assertGreaterThan( 0, $id );
		$this->assertTrue( RunRepository::claim( $id ) );
		$this->assertFalse( RunRepository::claim( $id ), 'A second worker cannot take it' );
		$run = RunRepository::get( $id );
		$this->assertSame( 'running', $run['status'] );
		$this->assertSame( 1, $run['attempts'] );
		$this->assertSame( array(), RunRepository::due(), 'A running run is not due' );
	}

	public function test_a_retrying_run_is_only_due_after_its_delay(): void {
		Clock::freeze( 1700000000 );
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $id );
		RunRepository::retry_later( $id, 60, array( 'error_code' => 'http_5xx', 'error_message' => 'boom' ) );

		$this->assertSame( array(), RunRepository::due() );
		$this->assertFalse( RunRepository::claim( $id ), 'Not claimable before its time' );
		Clock::freeze( 1700000061 );
		$this->assertSame( array( $id ), RunRepository::due() );
		$this->assertTrue( RunRepository::claim( $id ) );
		$this->assertSame( 2, RunRepository::get( $id )['attempts'] );
	}

	public function test_stuck_runs_are_reopened_after_a_crash(): void {
		Clock::freeze( 1700000000 );
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $id );
		Clock::freeze( 1700000100 );
		$this->assertSame( 0, RunRepository::reopen_stuck( 300 ), 'Recently claimed runs are left alone' );
		Clock::freeze( 1700000400 );
		$this->assertSame( 1, RunRepository::reopen_stuck( 300 ) );
		$this->assertSame( 'retrying', RunRepository::get( $id )['status'] );
		$this->assertSame( array( $id ), RunRepository::due() );
	}

	public function test_run_log_keeps_only_a_safe_summary(): void {
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_webhook' );
		RunRepository::claim( $id );
		RunRepository::finish( $id, RunRepository::FAILED, array( 'http_status' => 500, 'error_code' => str_repeat( 'x', 100 ), 'error_message' => str_repeat( 'y', 1000 ), 'payload' => array( 'email' => 'leak@example.test' ), 'duration_ms' => 12 ) );
		$run = RunRepository::get( $id );
		$this->assertSame( 500, $run['http_status'] );
		$this->assertSame( 40, strlen( $run['error_code'] ) );
		$this->assertSame( 255, strlen( $run['error_message'] ) );
		$this->assertStringNotContainsString( 'leak@example.test', wp_json_encode( $run ), 'No payload is ever stored in the log' );
	}

	public function test_success_clears_previous_error_and_requeue_resets_the_budget(): void {
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $id );
		RunRepository::retry_later( $id, 1, array( 'error_code' => 'x', 'error_message' => 'temporary' ) );
		RunRepository::finish( $id, RunRepository::SUCCESS, array( 'http_status' => 200 ) );
		$run = RunRepository::get( $id );
		$this->assertSame( '', $run['error_message'] );
		$this->assertNotNull( $run['finished_at'] );
		$this->assertFalse( RunRepository::requeue( $id ), 'A successful run cannot be re-run' );

		RunRepository::finish( $id, RunRepository::FAILED, array( 'error_code' => 'x' ) );
		$this->assertTrue( RunRepository::requeue( $id ) );
		$run = RunRepository::get( $id );
		$this->assertSame( 'pending', $run['status'] );
		$this->assertSame( 0, $run['attempts'] );
		$this->assertSame( '', $run['error_code'] );
	}

	public function test_query_filters_and_counts(): void {
		$a = RunRepository::create( 1, 10, 'form.submitted', 'a_x', 'send_email' );
		$b = RunRepository::create( 2, 11, 'form.submitted', 'a_y', 'send_webhook' );
		RunRepository::claim( $b );
		RunRepository::finish( $b, RunRepository::FAILED, array( 'error_code' => 'x' ) );

		$this->assertSame( 2, RunRepository::query()['total'] );
		$this->assertSame( 1, RunRepository::query( array( 'status' => 'failed' ) )['total'] );
		$this->assertSame( 1, RunRepository::query( array( 'flow_id' => 1 ) )['total'] );
		$this->assertSame( 1, RunRepository::query( array( 'lead_id' => 11 ) )['total'] );
		$this->assertSame( 2, RunRepository::query( array( 'status' => "failed' OR 1=1 --" ) )['total'], 'An unknown status is ignored (no filter), never injected' );
		$counts = RunRepository::counts();
		ksort( $counts );
		$this->assertSame( array( 'failed' => 1, 'pending' => 1 ), $counts );
		unset( $a );
	}

	public function test_old_finished_runs_are_purged_but_pending_ones_stay(): void {
		Clock::freeze( 1700000000 );
		$old = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $old );
		RunRepository::finish( $old, RunRepository::SUCCESS );
		$pending = RunRepository::create( 1, 2, 'form.submitted', 'a_x', 'send_email' );
		Clock::freeze( 1700000000 + 40 * DAY_IN_SECONDS );
		$this->assertSame( 1, RunRepository::purge_older_than( 30 ) );
		$this->assertNull( RunRepository::get( $old ) );
		$this->assertNotNull( RunRepository::get( $pending ), 'Work that has not run yet is never purged' );
	}

	// ------------------------------------------------------------------ worker: success

	public function test_worker_delivers_a_webhook_and_logs_the_outcome(): void {
		$case = $this->webhook_run( '/created' );
		$this->assertSame( 1, Worker::process() );

		$run = RunRepository::get( $case['run']['id'] );
		$this->assertSame( 'success', $run['status'] );
		$this->assertSame( 201, $run['http_status'] );
		$this->assertSame( 1, $run['attempts'] );
		$this->assertSame( '', $run['error_message'] );

		$requests = StubServer::requests();
		$this->assertCount( 1, $requests );
		$body = json_decode( $requests[0]['body'], true );
		$this->assertSame( 'form.submitted', $body['event'] );
		$this->assertSame( 'maria@example.test', $body['lead']['email'] );
		$this->assertSame( $case['lead']['public_id'], $body['lead']['public_id'] );
		$this->assertSame( 'Low', $body['answers_labeled'][3]['display'] );
		$this->assertSame( 'https://example.test/pricing?x=1', $body['tracking']['page_url'] );
		$this->assertSame( $body['delivery_id'], $requests[0]['headers']['x-krivochatflow-delivery'] );
	}

	public function test_worker_sends_the_notification_email_for_a_real_submission(): void {
		$flow   = $this->flow();
		$result = $this->submit( $flow, $this->valid_answers() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );

		$this->assertSame( 1, Worker::process() );
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $sent );
		$this->assertSame( get_option( 'admin_email' ), $sent->to[0][0] );
		$this->assertSame( 'Lead Maria Silva', $sent->subject );
		$this->assertStringContainsString( 'maria@example.test', $sent->body );
		$this->assertStringContainsString( 'Reply-To: Maria Silva <maria@example.test>', $sent->header );
		$this->assertSame( 'success', RunRepository::query()['items'][0]['status'] );
		unset( $result );
	}

	public function test_nothing_is_processed_when_the_queue_is_empty(): void {
		$this->assertSame( 0, Worker::process() );
		$this->assertFalse( get_option( 'krivo_chatflow_queue_lock' ), 'The lock is released' );
	}

	// ------------------------------------------------------------------ worker: failure paths

	public function test_server_error_is_retried_with_backoff_then_fails_after_three_attempts(): void {
		$case = $this->webhook_run( '/500' );
		$id   = $case['run']['id'];
		$failed = array();
		add_action(
			'krivo_chatflow_run_failed',
			static function ( $run ) use ( &$failed ) {
				$failed[] = $run;
			}
		);

		Worker::process();
		$run = RunRepository::get( $id );
		$this->assertSame( 'retrying', $run['status'] );
		$this->assertSame( 1, $run['attempts'] );
		$this->assertSame( 500, $run['http_status'] );
		$this->assertSame( 'http_5xx', $run['error_code'] );
		$this->assertSame( Clock::now( 60 ), $run['next_attempt_at'] );
		$this->assertSame( 0, Worker::process(), 'Not yet due' );

		Clock::freeze( Clock::time() + 61 );
		Worker::process();
		$run = RunRepository::get( $id );
		$this->assertSame( 'retrying', $run['status'] );
		$this->assertSame( 2, $run['attempts'] );
		$this->assertSame( Clock::now( 300 ), $run['next_attempt_at'] );

		Clock::freeze( Clock::time() + 301 );
		Worker::process();
		$run = RunRepository::get( $id );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 3, $run['attempts'] );
		$this->assertNull( $run['next_attempt_at'] );
		$this->assertCount( 1, $failed, 'The failure hook fires once, after the last attempt' );

		$deliveries = array_unique( array_column( array_column( StubServer::requests(), 'headers' ), 'x-krivochatflow-delivery' ) );
		$this->assertCount( 3, StubServer::requests() );
		$this->assertCount( 1, $deliveries, 'Every retry carries the same delivery id, so receivers can de-duplicate' );

		Clock::freeze( Clock::time() + 99999 );
		$this->assertSame( 0, Worker::process(), 'A failed run is not tried again by itself' );
		$this->assertCount( 3, StubServer::requests() );
	}

	public function test_client_error_fails_immediately_without_retries(): void {
		$case = $this->webhook_run( '/400' );
		Worker::process();
		$run = RunRepository::get( $case['run']['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 1, $run['attempts'] );
		$this->assertSame( 400, $run['http_status'] );
		$this->assertCount( 1, StubServer::requests() );
	}

	public function test_a_manual_retry_succeeds_after_the_receiver_is_fixed(): void {
		$case = $this->webhook_run( '/400' );
		Worker::process();
		$id = $case['run']['id'];
		$this->assertSame( 'failed', RunRepository::get( $id )['status'] );

		// The administrator fixes the address and presses "Try again".
		$connection = ConnectionRepository::all()[0]['id'];
		ConnectionRepository::save( array( 'id' => $connection, 'url' => StubServer::url( '/ok' ) ), 1 );
		$this->assertTrue( RunRepository::requeue( $id ) );
		Worker::process();
		$run = RunRepository::get( $id );
		$this->assertSame( 'success', $run['status'] );
		$this->assertSame( 1, $run['attempts'], 'A new attempt budget' );
	}

	public function test_an_action_that_throws_is_contained_and_logged_without_leaking(): void {
		$case = $this->webhook_run( '/ok' );
		add_filter(
			'krivo_chatflow_webhook_payload',
			static function () {
				throw new RuntimeException( 'database password is hunter2' );
			}
		);
		$ran = Worker::process();
		$this->assertSame( 1, $ran, 'The queue keeps going' );
		$run = RunRepository::get( $case['run']['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'exception', $run['error_code'] );
		$this->assertStringContainsString( 'RuntimeException', $run['error_message'] );
		$this->assertCount( 0, StubServer::requests() );
	}

	public function test_one_failing_run_does_not_block_the_next_one(): void {
		$bad  = $this->hook( '/500' );
		$good = $this->hook( '/ok' );
		$flow = $this->flow(
			array(
				array( 'id' => 'a_bad', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => $bad ) ),
				array( 'id' => 'a_good', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => $good ) ),
			)
		);
		$this->submit( $flow, $this->valid_answers() );
		$this->assertSame( 2, Worker::process() );
		$by_action = array();
		foreach ( RunRepository::query()['items'] as $run ) {
			$by_action[ $run['action_id'] ] = $run['status'];
		}
		ksort( $by_action );
		$this->assertSame( array( 'a_bad' => 'retrying', 'a_good' => 'success' ), $by_action );
	}

	// ------------------------------------------------------------------ worker: cancellation

	public function test_run_is_cancelled_when_the_automation_was_removed_or_turned_off(): void {
		$case = $this->webhook_run( '/ok' );
		$draft = FlowRepository::get( $case['flow'] )['draft'];
		$draft['automations'][0]['enabled'] = false;
		$this->assertIsArray( FlowRepository::save_draft( $case['flow'], $draft ) );
		$this->assertIsArray( FlowRepository::publish( $case['flow'] ) );

		Worker::process();
		$run = RunRepository::get( $case['run']['id'] );
		$this->assertSame( 'cancelled', $run['status'] );
		$this->assertSame( 'removed', $run['error_code'] );
		$this->assertCount( 0, StubServer::requests(), 'A turned-off automation sends nothing, even for work queued earlier' );
	}

	public function test_run_is_cancelled_when_the_lead_was_deleted(): void {
		$case = $this->webhook_run( '/ok' );
		LeadRepository::delete( array( $case['lead']['id'] ) );
		$this->assertSame( 0, RunRepository::query( array( 'lead_id' => $case['lead']['id'] ) )['total'], 'Deleting a lead also removes its run log' );
		$this->assertSame( 0, Worker::process() );
		$this->assertCount( 0, StubServer::requests() );
	}

	public function test_run_is_cancelled_when_the_flow_was_deleted(): void {
		$case = $this->webhook_run( '/ok' );
		FlowRepository::delete( $case['flow'] );
		Worker::process();
		$run = RunRepository::query()['items'][0] ?? null;
		if ( null !== $run ) {
			$this->assertSame( 'cancelled', $run['status'] );
		}
		$this->assertCount( 0, StubServer::requests() );
	}

	public function test_a_disabled_connection_skips_the_run_with_zero_requests(): void {
		$case = $this->webhook_run( '/ok' );
		ConnectionRepository::save( array( 'id' => ConnectionRepository::all()[0]['id'], 'enabled' => false ), 1 );
		Worker::process();
		$run = RunRepository::get( $case['run']['id'] );
		$this->assertSame( 'skipped', $run['status'] );
		$this->assertSame( 'connection_disabled', $run['error_code'] );
		$this->assertCount( 0, StubServer::requests() );
	}

	// ------------------------------------------------------------------ worker lock and scheduler

	public function test_a_second_worker_is_refused_while_the_first_holds_the_lock(): void {
		$this->webhook_run( '/ok' );
		update_option( 'krivo_chatflow_queue_lock', time(), false );
		$this->assertSame( 0, Worker::process() );
		$this->assertCount( 0, StubServer::requests() );
	}

	public function test_a_stale_lock_from_a_crashed_worker_is_taken_over(): void {
		$this->webhook_run( '/ok' );
		update_option( 'krivo_chatflow_queue_lock', time() - 600, false );
		$this->assertSame( 1, Worker::process() );
	}

	public function test_kick_schedules_exactly_one_event(): void {
		Scheduler::kick();
		$next = wp_next_scheduled( Events::QUEUE );
		$this->assertNotFalse( $next );
		Scheduler::kick();
		Scheduler::kick();
		$this->assertSame( $next, wp_next_scheduled( Events::QUEUE ) );
		$crons = _get_cron_array();
		$this->assertCount( 1, $crons[ $next ][ Events::QUEUE ], 'No duplicate events' );
		// The WordPress test suite defines DISABLE_WP_CRON: the scheduler respects it and never loops back
		// to wp-cron.php (spawning is verified against the real site in the end-to-end checks).
		$this->assertSame( array(), $this->blocked_http );
	}

	public function test_delayed_kick_schedules_in_the_future_without_spawning(): void {
		Scheduler::kick( 600 );
		$next = wp_next_scheduled( Events::QUEUE );
		$this->assertGreaterThanOrEqual( time() + 595, $next );
		$this->assertSame( array(), $this->blocked_http, 'A retry later does not hit wp-cron now' );
	}

	public function test_visitor_request_never_waits_for_delivery(): void {
		$connection = $this->hook( '/slow' );
		$flow       = $this->flow(
			array(
				array( 'id' => 'a_hook', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => $connection ) ),
			)
		);
		$start  = microtime( true );
		$result = $this->submit( $flow, $this->valid_answers() );
		$this->assertIsArray( $result );
		$this->assertLessThan( 1.5, microtime( true ) - $start, 'The slow receiver is only contacted from the queue' );
		$this->assertCount( 0, StubServer::requests() );
	}
}
