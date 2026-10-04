<?php
/**
 * Tests for the public submission pipeline and its anti-abuse layer.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Core\Submission\AntiSpam\RateLimiter;
use Krivo\ChatFlow\Core\Submission\AntiSpam\Token;
use Krivo\ChatFlow\Core\Submission\Pipeline;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

/**
 * @group submission
 */
class SubmissionTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		unset( $_SERVER['HTTP_ORIGIN'] );
		// Keep the cron queue out of the way: these tests look at what was queued.
		add_filter( 'pre_schedule_event', '__return_true' );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['REMOTE_ADDR'] );
		remove_filter( 'pre_schedule_event', '__return_true' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------ happy path

	public function test_valid_submission_saves_the_lead_with_everything_the_admin_needs(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['duplicate'] );

		$lead = LeadRepository::get_by_public_id( $result['lead'] );
		$this->assertNotNull( $lead );
		$this->assertSame( $id, $lead['flow_id'] );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertSame( 'Maria Silva', $lead['name'] );
		$this->assertSame( 'maria@example.test', $lead['email'] );
		$this->assertSame( 'whatsapp', $lead['channel'] );
		$this->assertSame( 'https://example.test/pricing?x=1', $lead['page_url'] );
		$this->assertSame( 'Pricing', $lead['page_title'] );
		$this->assertSame( 'google', $lead['utm_source'] );
		$this->assertSame( 'cpc', $lead['utm_medium'] );
		$this->assertSame( 'mobile', $lead['device'] );
		$this->assertSame( 'Low', $lead['answers']['budget']['display'] );
		$this->assertSame( 'I would like a quote.', $lead['answers']['message']['value'] );
		$this->assertSame( 'consent', $lead['consent'][0]['key'] );
		$this->assertTrue( $lead['consent'][0]['accepted'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', self::T0 + 10 ), $lead['consent'][0]['at'] );

		$stats = StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id );
		$this->assertSame( 1, $stats['lead'] );
		$this->assertSame( 1, $stats['whatsapp_click'] );
	}

	public function test_response_reaches_the_browser_with_a_whatsapp_link_and_nothing_private(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'budget' => 'high' ) ) );

		$this->assertCount( 1, $result['directives'] );
		$directive = $result['directives'][0];
		$this->assertSame( 'open_whatsapp', $directive['type'] );
		$this->assertStringStartsWith( 'https://wa.me/5521988887777?text=', $directive['url'], 'The routing rule sent a high budget to the VIP number' );
		$this->assertStringContainsString( rawurlencode( 'Hi, I am Maria Silva' ), $directive['url'] );

		$json = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'vip@example.test', $json );
		$this->assertStringNotContainsString( 'sales@example.test', $json );
		$this->assertStringNotContainsString( 'dst_vip01', $json );
		$this->assertStringNotContainsString( 'rl_vip', $json );
	}

	public function test_routing_rule_picks_the_destination_and_default_applies_otherwise(): void {
		$id   = $this->flow();
		$vip  = $this->submit( $id, $this->valid_answers( array( 'budget' => 'high', 'email' => 'a@example.test' ) ) );
		$low  = $this->submit( $id, $this->valid_answers( array( 'budget' => 'low', 'email' => 'b@example.test' ) ) );

		$this->assertSame( 'dst_vip01', LeadRepository::get_by_public_id( $vip['lead'] )['destination_key'] );
		$this->assertSame( 'VIP', LeadRepository::get_by_public_id( $vip['lead'] )['destination_label'] );
		$this->assertSame( 'dst_sales', LeadRepository::get_by_public_id( $low['lead'] )['destination_key'] );
	}

	public function test_email_automation_is_queued_not_sent_during_the_visitors_request(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers() );
		$lead   = LeadRepository::get_by_public_id( $result['lead'] );

		$runs = RunRepository::query( array( 'lead_id' => $lead['id'] ) );
		$this->assertSame( 1, $runs['total'] );
		$this->assertSame( 'send_email', $runs['items'][0]['action_type'] );
		$this->assertSame( 'pending', $runs['items'][0]['status'] );
		$this->assertSame( 0, $runs['items'][0]['attempts'] );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent ?? array(), 'No e-mail was sent inline' );
	}

	public function test_success_message_uses_the_visitors_name(): void {
		$id = $this->flow( null, array( 'finish' => array( 'success_message' => 'Thanks {name}!' ) ) );
		$this->assertSame( 'Thanks Maria Silva!', $this->submit( $id, $this->valid_answers() )['message'] );
	}

	// ------------------------------------------------------------------ validation

	public function test_invalid_input_is_rejected_with_field_errors_and_stores_nothing(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'email' => 'not-an-email', 'name' => '', 'consent' => false ) ) );

		$this->assertWPErrorCode( 'krivo_chatflow_validation', $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$errors = $result->get_error_data()['errors'];
		$this->assertArrayHasKey( 'email', $errors );
		$this->assertArrayHasKey( 'name', $errors );
		$this->assertArrayHasKey( 'consent', $errors );
		$this->assertSame( 0, LeadRepository::query()['total'] );
		$this->assertSame( 0, RunRepository::query()['total'] );
	}

	public function test_unknown_answer_keys_are_dropped(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'admin' => 'yes', '<script>' => 'x' ) ) );
		$lead   = LeadRepository::get_by_public_id( $result['lead'] );
		$this->assertArrayNotHasKey( 'admin', $lead['answers'] );
		$this->assertArrayNotHasKey( '<script>', $lead['answers'] );
	}

	public function test_a_value_outside_the_options_is_rejected(): void {
		$id = $this->flow();
		$this->assertWPErrorCode( 'krivo_chatflow_validation', $this->submit( $id, $this->valid_answers( array( 'budget' => 'free' ) ) ) );
	}

	public function test_oversized_text_is_rejected_or_truncated_never_stored_whole(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'message' => str_repeat( 'x', 50000 ) ) ) );
		if ( is_wp_error( $result ) ) {
			$this->assertWPErrorCode( 'krivo_chatflow_validation', $result );
			return;
		}
		$lead = LeadRepository::get_by_public_id( $result['lead'] );
		$this->assertLessThanOrEqual( 5000, strlen( $lead['answers']['message']['value'] ) );
	}

	public function test_html_in_answers_is_stored_as_text_only(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'name' => 'Ana <script>alert(1)</script>', 'message' => '<img src=x onerror=alert(1)> hello' ) ) );
		$lead   = LeadRepository::get_by_public_id( $result['lead'] );
		$this->assertStringNotContainsString( '<script', $lead['name'] );
		$this->assertStringNotContainsString( '<img', $lead['answers']['message']['value'] );
	}

	public function test_unknown_and_unpublished_flows_do_not_exist_for_visitors(): void {
		$this->assertWPErrorCode( 'krivo_chatflow_not_found', Pipeline::handle( array( 'flow' => 99999 ) ) );

		$draft = FlowRepository::create( array( 'name' => 'Draft only' ), 1 );
		$this->assertWPErrorCode( 'krivo_chatflow_not_found', Pipeline::handle( array( 'flow' => $draft ) ) );

		$id = $this->flow();
		FlowRepository::pause( $id );
		$this->assertWPErrorCode( 'krivo_chatflow_not_found', Pipeline::handle( array( 'flow' => $id ) ) );
	}

	public function test_conditional_field_is_not_required_when_hidden_and_not_stored(): void {
		$id = $this->flow(
			null,
			array(
				'logic' => array(
					'rules' => array(
						array(
							'id'   => 'r1',
							'when' => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:budget', 'op' => 'eq', 'right' => 'high' ) ) ),
							'then' => array(
								array( 'do' => 'show_field', 'target' => 'f_msg' ),
							),
						),
					),
				),
			)
		);
		$config = FlowRepository::get( $id )['published'];
		// Make the message required to prove a hidden required field never blocks the visitor.
		$draft = FlowRepository::get( $id )['draft'];
		foreach ( $draft['steps'][0]['fields'] as $i => $field ) {
			if ( 'message' === $field['key'] ) {
				$draft['steps'][0]['fields'][ $i ]['required'] = true;
			}
		}
		$this->assertIsArray( FlowRepository::save_draft( $id, $draft ) );
		$this->assertIsArray( FlowRepository::publish( $id ) );
		unset( $config );

		$hidden = $this->submit( $id, $this->valid_answers( array( 'budget' => 'low', 'message' => 'sneaky' ) ) );
		$this->assertIsArray( $hidden, is_wp_error( $hidden ) ? wp_json_encode( $hidden->get_error_data() ) : '' );
		$this->assertArrayNotHasKey( 'message', LeadRepository::get_by_public_id( $hidden['lead'] )['answers'], 'A field the visitor never saw is not stored, even if the browser sent it' );

		$shown = $this->submit( $id, $this->valid_answers( array( 'budget' => 'high', 'message' => '', 'email' => 'c@example.test' ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_validation', $shown );
		$this->assertArrayHasKey( 'message', $shown->get_error_data()['errors'] );
	}

	// ------------------------------------------------------------------ anti-abuse

	public function test_missing_malformed_and_forged_tokens_are_refused(): void {
		$id = $this->flow();
		Clock::freeze( self::T0 + 10 );

		foreach ( array( '', 'garbage', str_repeat( 'x', 500 ), "a.b\n", 'a.b.c' ) as $token ) {
			$result = Pipeline::handle( array( 'flow' => $id, 'token' => $token, 'answers' => $this->valid_answers(), 'hp' => '' ) );
			$this->assertWPErrorCode( 'krivo_chatflow_token_malformed', $result );
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
		$unsigned = Pipeline::handle( array( 'flow' => $id, 'token' => 'a.b', 'answers' => $this->valid_answers(), 'hp' => '' ) );
		$this->assertWPErrorCode( 'krivo_chatflow_token_signature', $unsigned );

		Clock::freeze( self::T0 );
		$good = Token::issue( $id );
		list( $payload, $sig ) = explode( '.', $good );
		Clock::freeze( self::T0 + 10 );
		$forged = Pipeline::handle( array( 'flow' => $id, 'token' => $payload . '.' . strrev( $sig ), 'answers' => $this->valid_answers(), 'hp' => '' ) );
		$this->assertWPErrorCode( 'krivo_chatflow_token_signature', $forged );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_token_is_bound_to_its_flow(): void {
		$a = $this->flow();
		Clock::freeze( self::T0 );
		$token_for_a = Token::issue( $a );
		$this->routing();
		$b = FlowRepository::create( array( 'name' => 'B', 'steps' => array( array( 'fields' => array( array( 'type' => 'name', 'label' => 'Name', 'key' => 'name' ) ) ) ), 'automations' => array() ), 1 );
		FlowRepository::publish( $b );
		Clock::freeze( self::T0 + 10 );
		$this->assertWPErrorCode( 'krivo_chatflow_token_flow', Pipeline::handle( array( 'flow' => $b, 'token' => $token_for_a, 'answers' => array( 'name' => 'X' ), 'hp' => '' ) ) );
	}

	public function test_a_form_filled_too_fast_is_refused_and_a_stale_session_expires(): void {
		$id = $this->flow();
		Clock::freeze( self::T0 );
		$token = Token::issue( $id );

		Clock::freeze( self::T0 + 1 );
		$fast = Pipeline::handle( array( 'flow' => $id, 'token' => $token, 'answers' => $this->valid_answers(), 'hp' => '' ) );
		$this->assertWPErrorCode( 'krivo_chatflow_token_too_fast', $fast );

		Clock::freeze( self::T0 + Token::MAX_AGE + 5 );
		$old = Pipeline::handle( array( 'flow' => $id, 'token' => $token, 'answers' => $this->valid_answers(), 'hp' => '' ) );
		$this->assertWPErrorCode( 'krivo_chatflow_token_expired', $old );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_honeypot_looks_like_success_but_stores_and_queues_nothing(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers(), array( 'hp' => 'http://spam.example' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['lead'] );
		$this->assertSame( array(), $result['directives'], 'A robot never receives a WhatsApp link' );
		$this->assertSame( 0, LeadRepository::query()['total'] );
		$this->assertSame( 0, RunRepository::query()['total'] );
	}

	public function test_honeypot_with_a_non_string_value_is_treated_as_a_robot(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers(), array( 'hp' => array( 'x' ) ) );
		$this->assertSame( '', $result['lead'] );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_rate_limit_per_ip_and_flow_blocks_with_429(): void {
		$id = $this->flow();
		Settings::update( array( 'antispam' => array( 'rate_limit_max' => 2, 'rate_limit_window' => 600 ) ) );

		$this->assertIsArray( $this->submit( $id, $this->valid_answers( array( 'email' => 'a@example.test' ) ) ) );
		$this->assertIsArray( $this->submit( $id, $this->valid_answers( array( 'email' => 'b@example.test' ) ) ) );
		$third = $this->submit( $id, $this->valid_answers( array( 'email' => 'c@example.test' ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_rate_limited', $third );
		$this->assertSame( 429, $third->get_error_data()['status'] );
		$this->assertSame( 2, LeadRepository::query()['total'] );

		// Another visitor is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertIsArray( $this->submit( $id, $this->valid_answers( array( 'email' => 'd@example.test' ) ) ) );
	}

	public function test_rate_limit_window_expires(): void {
		$id = $this->flow();
		Settings::update( array( 'antispam' => array( 'rate_limit_max' => 1, 'rate_limit_window' => 60 ) ) );
		$this->assertIsArray( $this->submit( $id, $this->valid_answers( array( 'email' => 'a@example.test' ) ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_rate_limited', $this->submit( $id, $this->valid_answers( array( 'email' => 'b@example.test' ) ) ) );

		// RateLimiter stores the expiry with the frozen clock: moving past the window opens it again.
		Clock::freeze( self::T0 + 200 );
		$token_time = self::T0 + 150;
		Clock::freeze( $token_time );
		$token = Token::issue( $id );
		Clock::freeze( $token_time + 60 );
		$this->assertIsArray( Pipeline::handle( array( 'flow' => $id, 'token' => $token, 'answers' => $this->valid_answers( array( 'email' => 'c@example.test' ) ), 'hp' => '' ) ) );
	}

	public function test_rate_limiter_never_stores_the_ip_in_clear(): void {
		RateLimiter::hit( 'submit:1:203.0.113.9', 5, 60 );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%krivo_chatflow_rl_%'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString( '203.0.113.9', $row['option_name'] . $row['option_value'] );
		}
	}

	public function test_a_foreign_origin_is_refused(): void {
		$id                    = $this->flow();
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$result                = $this->submit( $id, $this->valid_answers() );
		$this->assertWPErrorCode( 'krivo_chatflow_origin', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		$_SERVER['HTTP_ORIGIN'] = home_url();
		$this->assertIsArray( $this->submit( $id, $this->valid_answers() ) );
	}

	public function test_circuit_breaker_sends_a_flood_to_spam_without_running_automations(): void {
		$id = $this->flow();
		Settings::update( array( 'antispam' => array( 'breaker_per_minute' => 2, 'rate_limit_max' => 100 ) ) );

		foreach ( array( 'a', 'b' ) as $n ) {
			$ok = $this->submit( $id, $this->valid_answers( array( 'email' => $n . '@example.test' ) ) );
			$this->assertSame( 'new', LeadRepository::get_by_public_id( $ok['lead'] )['status'] );
		}
		$flood = $this->submit( $id, $this->valid_answers( array( 'email' => 'c@example.test' ) ) );
		$lead  = LeadRepository::get_by_public_id( $flood['lead'] );
		$this->assertSame( 'spam', $lead['status'] );
		$this->assertSame( array(), $flood['directives'], 'No WhatsApp link for a held lead' );
		$this->assertSame( 0, RunRepository::query( array( 'lead_id' => $lead['id'] ) )['total'], 'No e-mail or webhook for a held lead' );
	}

	public function test_many_links_in_the_answers_hold_the_lead_as_spam(): void {
		$id     = $this->flow();
		$result = $this->submit( $id, $this->valid_answers( array( 'message' => 'http://a.test http://b.test http://c.test http://d.test' ) ) );
		$this->assertSame( 'spam', LeadRepository::get_by_public_id( $result['lead'] )['status'] );
	}

	public function test_spam_verdict_can_be_changed_with_a_filter(): void {
		$id = $this->flow();
		add_filter( 'krivo_chatflow_is_spam', '__return_true' );
		$result = $this->submit( $id, $this->valid_answers() );
		remove_filter( 'krivo_chatflow_is_spam', '__return_true' );
		$this->assertSame( 'spam', LeadRepository::get_by_public_id( $result['lead'] )['status'] );
	}

	// ------------------------------------------------------------------ duplicates

	public function test_a_double_click_does_not_create_a_second_lead_or_second_email(): void {
		$id    = $this->flow();
		$first = $this->submit( $id, $this->valid_answers() );
		$again = $this->submit( $id, $this->valid_answers() );

		$this->assertFalse( $first['duplicate'] );
		$this->assertTrue( $again['duplicate'] );
		$this->assertSame( $first['lead'], $again['lead'] );
		$this->assertSame( 1, LeadRepository::query()['total'] );
		$this->assertSame( 1, RunRepository::query()['total'], 'The e-mail is queued once' );
		$this->assertSame( $first['directives'], $again['directives'], 'The retry still gets its WhatsApp link' );
		$this->assertSame( 1, StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id )['lead'] );
	}

	public function test_same_person_with_a_different_message_is_a_new_lead(): void {
		$id = $this->flow();
		$this->submit( $id, $this->valid_answers() );
		$this->submit( $id, $this->valid_answers( array( 'message' => 'A different request.' ) ) );
		$this->assertSame( 2, LeadRepository::query()['total'] );
	}

	// ------------------------------------------------------------------ hooks

	public function test_developer_hooks_fire_in_order_with_the_lead(): void {
		$id    = $this->flow();
		$order = array();
		add_action(
			'krivo_chatflow_before_submit',
			static function () use ( &$order ) {
				$order[] = 'before';
			}
		);
		add_action(
			'krivo_chatflow_lead_created',
			static function ( $lead ) use ( &$order ) {
				$order[] = 'created:' . $lead['email'];
			}
		);
		add_action(
			'krivo_chatflow_after_submit',
			static function ( $lead, $response ) use ( &$order ) {
				$order[] = 'after:' . ( $response['ok'] ? 'ok' : 'no' );
			},
			10,
			2
		);
		$this->submit( $id, $this->valid_answers() );
		$this->assertSame( array( 'before', 'created:maria@example.test', 'after:ok' ), $order );
	}

	public function test_a_failing_sync_action_never_breaks_the_submission(): void {
		$id = $this->flow(
			array(
				array( 'id' => 'a_wa', 'trigger' => 'form.submitted', 'type' => 'open_whatsapp', 'config' => array() ),
			)
		);
		add_filter(
			'krivo_chatflow_whatsapp_message',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$failed = array();
		add_action(
			'krivo_chatflow_action_failed',
			static function ( $type, $message ) use ( &$failed ) {
				$failed[] = array( $type, $message );
			},
			10,
			2
		);
		$result = $this->submit( $id, $this->valid_answers() );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['directives'] );
		$this->assertSame( 'open_whatsapp', $failed[0][0] );
		$this->assertSame( 1, LeadRepository::query()['total'], 'The lead is safe even when an automation fails' );
	}

	public function test_change_status_automation_updates_the_lead_and_fires_lead_updated_once(): void {
		$id = $this->flow(
			array(
				array( 'id' => 'a_st', 'trigger' => 'lead.created', 'type' => 'change_lead_status', 'config' => array( 'status' => 'contacted' ) ),
			)
		);
		$updates = 0;
		add_action(
			'krivo_chatflow_lead_updated',
			static function () use ( &$updates ) {
				++$updates;
			}
		);
		$result = $this->submit( $id, $this->valid_answers() );
		$this->assertSame( 'contacted', LeadRepository::get_by_public_id( $result['lead'] )['status'] );
		$this->assertSame( 1, $updates );
	}

	public function test_spam_leads_are_not_changed_by_automations(): void {
		$id = $this->flow(
			array(
				array( 'id' => 'a_st', 'trigger' => 'lead.created', 'type' => 'change_lead_status', 'config' => array( 'status' => 'won' ) ),
			)
		);
		add_filter( 'krivo_chatflow_is_spam', '__return_true' );
		$result = $this->submit( $id, $this->valid_answers() );
		remove_filter( 'krivo_chatflow_is_spam', '__return_true' );
		$this->assertSame( 'spam', LeadRepository::get_by_public_id( $result['lead'] )['status'] );
	}

	public function test_privacy_settings_can_turn_off_utm_and_referrer_capture(): void {
		Settings::update( array( 'tracking' => array( 'capture_utm' => false, 'capture_referrer' => false ) ) );
		$id   = $this->flow();
		$lead = LeadRepository::get_by_public_id( $this->submit( $id, $this->valid_answers() )['lead'] );
		$this->assertSame( '', $lead['utm_source'] );
		$this->assertSame( '', $lead['referrer'] );
		$this->assertSame( 'https://example.test/pricing?x=1', $lead['page_url'] );
	}

	public function test_non_http_page_urls_in_the_context_are_dropped(): void {
		$id   = $this->flow();
		$lead = LeadRepository::get_by_public_id(
			$this->submit( $id, $this->valid_answers(), array( 'context' => array( 'url' => 'javascript:alert(1)', 'referrer' => 'data:text/html,x' ) ) )['lead']
		);
		$this->assertSame( '', $lead['page_url'] );
		$this->assertSame( '', $lead['referrer'] );
	}
}
