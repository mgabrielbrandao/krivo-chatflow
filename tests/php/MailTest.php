<?php
/**
 * Tests for e-mail: the send action, the scoped SMTP configuration and the settings/secret handling.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Actions\SendEmail;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Mail\Diagnostics;
use Krivo\ChatFlow\Mail\EmailSettings;
use Krivo\ChatFlow\Mail\Mailer;

/**
 * @group mail
 */
class MailTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		reset_phpmailer_instance();
	}

	/**
	 * Builds a run context from a real submission.
	 *
	 * @param array $answers Answers (defaults to valid ones).
	 * @param array $lead_over Fields to force on the stored lead row.
	 * @return RunContext
	 */
	private function context( array $answers = array(), array $lead_over = array() ): RunContext {
		$flow_id = $this->flow( array() );
		$result  = $this->submit( $flow_id, $this->valid_answers( $answers ) );
		$this->assertIsArray( $result );
		$lead = LeadRepository::get_by_public_id( $result['lead'] );
		$lead = array_merge( $lead, $lead_over );
		return new RunContext( $flow_id, FlowRepository::published( $flow_id )['config'], $lead, 'form.submitted' );
	}

	private function email( array $config = array() ): array {
		$action = new SendEmail();
		return $action->sanitize( array_merge( $action->defaults(), $config ) );
	}

	private function sent( int $index = 0 ) {
		return tests_retrieve_phpmailer_instance()->get_sent( $index );
	}

	// ------------------------------------------------------------------ the action

	public function test_internal_notification_goes_to_the_team_with_the_answers_and_a_reply_to(): void {
		$ctx    = $this->context( array( 'message' => 'I need a quote for 3 units.' ) );
		$result = ( new SendEmail() )->run( $ctx, $this->email() );

		$this->assertSame( Result::SUCCESS, $result->status );
		$mail = $this->sent();
		$this->assertSame( get_option( 'admin_email' ), $mail->to[0][0] );
		$this->assertSame( 'New lead — Maria Silva', $mail->subject );
		$this->assertStringContainsString( 'Content-Type: multipart/alternative', $mail->header );
		$this->assertStringContainsString( 'Reply-To: Maria Silva <maria@example.test>', $mail->header );
		$this->assertStringContainsString( 'I need a quote for 3 units.', $mail->body );
		$this->assertStringContainsString( 'text/plain', $mail->body, 'A plain-text alternative is always sent' );
		$this->assertStringContainsString( 'text/html', $mail->body );
	}

	public function test_html_in_lead_data_is_escaped_in_the_html_part(): void {
		$ctx = $this->context( array(), array( 'name' => '<script>alert(1)</script>Eve' ) );
		$ctx->lead['answers']['message']['display'] = '<img src=x onerror=alert(1)>';
		$ctx->lead['answers']['message']['value']   = '<img src=x onerror=alert(1)>';
		( new SendEmail() )->run( $ctx, $this->email( array( 'subject' => 'Lead', 'body' => "{all_fields}\n{message}" ) ) );

		$raw  = $this->sent()->body;
		$html = quoted_printable_decode( substr( $raw, (int) strpos( $raw, 'Content-Type: text/html' ) ) );
		$this->assertStringNotContainsString( '<img src=x', $html, 'Markup typed by a visitor is shown as text in the HTML part' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;img', $html );
	}

	public function test_a_line_break_in_the_subject_cannot_inject_headers(): void {
		$ctx = $this->context( array(), array( 'name' => "Eve\r\nBcc: attacker@example.test" ) );
		$ctx->vars();
		$ctx->lead['answers']['name']['display'] = "Eve\r\nBcc: attacker@example.test";
		$ctx->lead['answers']['name']['value']   = "Eve\r\nBcc: attacker@example.test";
		( new SendEmail() )->run( $ctx, $this->email( array( 'subject' => 'Lead {name}' ) ) );

		$mail = $this->sent();
		$this->assertStringNotContainsString( "\nBcc:", $mail->header );
		$this->assertSame( array(), $mail->bcc );
		$this->assertStringNotContainsString( "\n", $mail->subject );
	}

	public function test_destination_and_custom_recipients(): void {
		$flow_id = $this->flow( array() ); // Creates the routing with dst_sales (sales@example.test).
		$result  = $this->submit( $flow_id, $this->valid_answers() );
		$lead    = LeadRepository::get_by_public_id( $result['lead'] );
		$ctx     = new RunContext( $flow_id, FlowRepository::published( $flow_id )['config'], $lead, 'form.submitted' );

		( new SendEmail() )->run( $ctx, $this->email( array( 'to' => array( 'destination', 'custom:boss@example.test', 'custom:not-an-email', 'custom:boss@example.test' ) ) ) );
		$recipients = array_column( $this->sent()->to, 0 );
		sort( $recipients );
		$this->assertSame( array( 'boss@example.test', 'sales@example.test' ), $recipients, 'Invalid and duplicate addresses are dropped' );
	}

	public function test_with_no_valid_recipient_nothing_is_sent_and_the_run_is_skipped(): void {
		$ctx    = $this->context();
		$ctx->lead['destination_key'] = '';
		$result = ( new SendEmail() )->run( $ctx, $this->email( array( 'to' => array( 'destination' ) ) ) );
		$this->assertSame( Result::SKIPPED, $result->status );
		$this->assertSame( 'no_recipient', $result->error_code );
		$this->assertFalse( $this->sent() );
	}

	public function test_a_lead_held_as_spam_never_sends_mail(): void {
		$ctx = $this->context( array(), array( 'status' => 'spam' ) );
		$this->assertSame( Result::SKIPPED, ( new SendEmail() )->run( $ctx, $this->email() )->status );
		$this->assertFalse( $this->sent() );
	}

	public function test_confirmation_to_the_visitor_is_exclusive_and_limited_per_address(): void {
		$config = SendEmail::autoresponder_defaults();
		$clean  = ( new SendEmail() )->sanitize( array_merge( $config, array( 'to' => array( 'lead', 'site_admin', 'custom:team@example.test' ) ) ) );
		$this->assertSame( array( 'lead' ), $clean['to'], 'A confirmation is only ever addressed to the visitor, never mixed with the team' );

		$ctx   = $this->context();
		$first = ( new SendEmail() )->run( $ctx, $clean );
		$this->assertSame( Result::SUCCESS, $first->status );
		$mail = $this->sent();
		$this->assertSame( 'maria@example.test', $mail->to[0][0] );
		$this->assertStringNotContainsString( 'Reply-To:', $mail->header );
		$this->assertStringContainsString( 'Hello Maria Silva', quoted_printable_decode( $mail->body ) );

		$again = ( new SendEmail() )->run( $ctx, $clean );
		$this->assertSame( Result::SKIPPED, $again->status );
		$this->assertSame( 'rate_limited', $again->error_code, 'The form cannot be used to flood somebody with confirmations' );
		$this->assertFalse( $this->sent( 1 ) );

		$other = $this->context( array( 'email' => 'other@example.test', 'message' => 'Another request' ) );
		$this->assertSame( Result::SUCCESS, ( new SendEmail() )->run( $other, $clean )->status );
	}

	public function test_the_confirmation_never_echoes_free_text_typed_by_the_visitor(): void {
		$defaults = SendEmail::autoresponder_defaults();
		$this->assertStringNotContainsString( '{message}', $defaults['body'] );
		$this->assertStringNotContainsString( '{all_fields}', $defaults['body'] );
	}

	public function test_a_failed_send_is_reported_as_retryable(): void {
		$ctx = $this->context();
		add_filter(
			'wp_mail',
			static function ( $args ) {
				$args['to'] = '';
				return $args;
			}
		);
		$result = ( new SendEmail() )->run( $ctx, $this->email() );
		$this->assertSame( Result::FAILED, $result->status );
		$this->assertTrue( $result->retry );
		$this->assertSame( 'mail_failed', $result->error_code );
		$this->assertNotSame( '', $result->error_message );
	}

	public function test_email_arguments_can_be_filtered(): void {
		$ctx = $this->context();
		add_filter(
			'krivo_chatflow_email_args',
			static function ( $args ) {
				$args['subject'] = 'Filtered subject';
				$args['to']      = array( 'filtered@example.test' );
				return $args;
			}
		);
		( new SendEmail() )->run( $ctx, $this->email() );
		$this->assertSame( 'Filtered subject', $this->sent()->subject );
		$this->assertSame( 'filtered@example.test', $this->sent()->to[0][0] );
	}

	public function test_action_config_sanitiser(): void {
		$clean = ( new SendEmail() )->sanitize(
			array(
				'to'       => array( 'site_admin', 'bogus', 'custom:a@example.test', 'custom:' . "x@y.test\r\nBcc: z@y.test", 7, array( 'site_admin' ) ),
				'subject'  => '<b>Hi</b> {name}' . "\r\nBcc: x@y.test",
				'body'     => '',
				'reply_to' => 'attacker',
			)
		);
		$this->assertSame( array( 'site_admin', 'custom:a@example.test' ), $clean['to'] );
		$this->assertStringNotContainsString( "\n", $clean['subject'] );
		$this->assertStringNotContainsString( '<b>', $clean['subject'] );
		$this->assertNotSame( '', $clean['body'], 'An empty body falls back to the default' );
		$this->assertSame( 'lead', $clean['reply_to'] );
		$this->assertSame( array( 'site_admin' ), ( new SendEmail() )->sanitize( array( 'to' => array() ) )['to'], 'No recipient falls back to the site admin' );
		$many = array_map(
			static function ( $i ) {
				return "custom:p{$i}@example.test";
			},
			range( 1, 30 )
		);
		$this->assertCount( 10, ( new SendEmail() )->sanitize( array( 'to' => $many ) )['to'] );
	}

	// ------------------------------------------------------------------ the sender

	public function test_default_mode_uses_the_wordpress_mailer_untouched(): void {
		$result = Mailer::send( array( 'a@example.test' ), 'Subject', '<p>Hi</p>', 'Hi' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'wp-default', $result['transport'] );
		$this->assertSame( '', $result['host'] );
		$this->assertSame( 'Hi', tests_retrieve_phpmailer_instance()->AltBody );
	}

	public function test_the_site_default_sender_is_replaced_only_when_it_is_not_a_valid_address(): void {
		add_filter(
			'home_url',
			static function () {
				return 'https://shop.example.test';
			}
		);
		Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' );
		$this->assertStringContainsString( 'From: WordPress <wordpress@shop.example.test>', $this->sent()->header, 'A valid default sender is left alone' );

		reset_phpmailer_instance();
		remove_all_filters( 'home_url' );
		add_filter(
			'home_url',
			static function () {
				return 'http://localhost';
			}
		);
		Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' );
		$this->assertStringContainsString( 'From: WordPress <' . get_option( 'admin_email' ) . '>', $this->sent()->header, 'wordpress@localhost cannot be delivered, so the admin address is used' );
	}

	public function test_own_smtp_applies_only_to_chatflow_messages_and_never_leaks_to_the_rest_of_the_site(): void {
		EmailSettings::update(
			array(
				'mode'       => 'custom',
				'host'       => 'smtp.example.test',
				'port'       => 465,
				'encryption' => 'ssl',
				'username'   => 'mailer',
				'from_email' => 'noreply@example.test',
				'from_name'  => 'Krivo Test',
			),
			'smtp-secret'
		);
		$phpmailer_init = has_action( 'phpmailer_init' );
		$from           = has_filter( 'wp_mail_from' );

		$result = Mailer::send( array( 'a@example.test' ), 'Subject', '<p>Hi</p>', 'Hi' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'smtp-chatflow', $result['transport'] );
		$this->assertSame( 'smtp.example.test', $result['host'] );
		$this->assertSame( 465, $result['port'] );
		$phpmailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.example.test', $phpmailer->Host );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'mailer', $phpmailer->Username );
		$this->assertSame( 'smtp-secret', $phpmailer->Password );
		$this->assertSame( 'noreply@example.test', $phpmailer->From );
		$this->assertSame( 'Krivo Test', $phpmailer->FromName );

		// Nothing stays registered afterwards.
		$this->assertSame( $phpmailer_init, has_action( 'phpmailer_init' ) );
		$this->assertSame( $from, has_filter( 'wp_mail_from' ) );

		// A message from anywhere else on the site is not touched.
		reset_phpmailer_instance();
		wp_mail( 'x@example.test', 'Some other plugin', 'Body' );
		$other = tests_retrieve_phpmailer_instance();
		$this->assertNotSame( 'smtp', $other->Mailer, 'Other plugins keep their own transport' );
		$this->assertNotSame( 'smtp.example.test', $other->Host );
		$this->assertNotSame( 'noreply@example.test', $other->From );
		$this->assertSame( '', $other->Password );
	}

	public function test_hooks_are_removed_even_when_sending_throws(): void {
		$before = array( has_action( 'phpmailer_init' ), has_filter( 'wp_mail_from' ), has_action( 'wp_mail_failed' ) );
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new RuntimeException( 'transport exploded' );
			}
		);
		try {
			Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' );
			$this->fail( 'The exception should propagate to the worker, which contains it' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'transport exploded', $e->getMessage() );
		}
		$this->assertSame( $before, array( has_action( 'phpmailer_init' ), has_filter( 'wp_mail_from' ), has_action( 'wp_mail_failed' ) ) );
	}

	public function test_another_smtp_plugin_taking_over_is_detected(): void {
		EmailSettings::update( array( 'mode' => 'custom', 'host' => 'smtp.example.test' ) );
		// Another plugin hooks in while the message is being built, after the ChatFlow hooks exist,
		// with the same (last) priority: it runs after ours and replaces the server.
		add_filter(
			'wp_mail',
			static function ( $args ) {
				add_action(
					'phpmailer_init',
					static function ( $phpmailer ) {
						$phpmailer->isSMTP();
						$phpmailer->Host = 'relay.other-plugin.test';
					},
					PHP_INT_MAX
				);
				return $args;
			}
		);
		$result = Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' );
		$this->assertSame( 'overridden', $result['transport'] );
	}

	public function test_custom_mode_without_a_host_falls_back_to_wordpress(): void {
		EmailSettings::update( array( 'mode' => 'custom', 'host' => '' ) );
		$this->assertSame( 'wp-default', Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' )['transport'] );
	}

	public function test_configured_reply_to_is_used_when_the_message_has_none(): void {
		EmailSettings::update( array( 'reply_to' => 'support@example.test' ) );
		Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x' );
		$this->assertStringContainsString( 'Reply-To: support@example.test', $this->sent()->header );
		reset_phpmailer_instance();
		Mailer::send( array( 'a@example.test' ), 'S', '<p>x</p>', 'x', array( 'reply_to' => 'lead@example.test', 'reply_to_name' => 'Lead' ) );
		$this->assertStringContainsString( 'Reply-To: Lead <lead@example.test>', $this->sent()->header, 'The lead wins over the default' );
	}

	// ------------------------------------------------------------------ settings and secrets

	public function test_smtp_password_is_encrypted_and_never_returned(): void {
		EmailSettings::update( array( 'mode' => 'custom', 'host' => 'smtp.example.test', 'username' => 'u' ), 'Sup3r-Secret!' );
		$this->assertStringNotContainsString( 'Sup3r-Secret!', wp_json_encode( array( get_option( EmailSettings::OPTION ), get_option( 'krivo_chatflow_secrets' ) ) ) );
		$this->assertStringNotContainsString( 'Sup3r-Secret!', wp_json_encode( EmailSettings::get() ) );
		$this->assertSame( 'Sup3r-Secret!', EmailSettings::password() );
		$this->assertSame( array( 'is_set' => true, 'source' => 'database', 'readable' => true ), EmailSettings::password_status() );
	}

	public function test_omitting_the_password_keeps_it_and_an_empty_one_removes_it(): void {
		EmailSettings::update( array( 'mode' => 'custom', 'host' => 'smtp.example.test' ), 'keep-me' );
		EmailSettings::update( array( 'mode' => 'custom', 'host' => 'smtp2.example.test' ), null );
		$this->assertSame( 'keep-me', EmailSettings::password() );
		EmailSettings::update( array( 'mode' => 'custom', 'host' => 'smtp2.example.test' ), '' );
		$this->assertSame( '', EmailSettings::password() );
		$this->assertFalse( EmailSettings::password_status()['is_set'] );
	}

	public function test_settings_are_sanitised(): void {
		$clean = EmailSettings::sanitize(
			array(
				'mode'       => 'rocket',
				'host'       => 'smtp.example.test; rm -rf /',
				'port'       => 99999,
				'encryption' => 'plain',
				'from_email' => "x@y.test\r\nBcc: z@y.test",
				'reply_to'   => 'not an email',
				'from_name'  => '<b>Name</b>',
				'username'   => '<i>user</i>',
			)
		);
		$this->assertSame( 'wp', $clean['mode'] );
		$this->assertSame( '', $clean['host'] );
		$this->assertSame( 587, $clean['port'], 'An out-of-range port falls back to the default' );
		$this->assertSame( 'tls', $clean['encryption'] );
		$this->assertSame( '', $clean['from_email'] );
		$this->assertSame( '', $clean['reply_to'] );
		$this->assertSame( 'Name', $clean['from_name'] );
		$this->assertSame( 'user', $clean['username'] );

		$ok = EmailSettings::sanitize( array( 'mode' => 'custom', 'host' => 'SMTP.Example.Test', 'port' => '2525', 'encryption' => 'none', 'auth' => '0' ) );
		$this->assertSame( 'smtp.example.test', $ok['host'] );
		$this->assertSame( 2525, $ok['port'] );
		$this->assertFalse( $ok['auth'] );
	}

	// ------------------------------------------------------------------ diagnostics

	public function test_test_email_reports_success_and_the_transport_used(): void {
		$result = Diagnostics::test( 'owner@example.test' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( 'wp-default', $result['transport'] );
		$this->assertSame( 'owner@example.test', $this->sent()->to[0][0] );
		$this->assertStringContainsString( 'Krivo ChatFlow test e-mail', $this->sent()->subject );
	}

	public function test_test_email_failure_is_explained_in_plain_language(): void {
		add_filter(
			'wp_mail',
			static function ( $args ) {
				$args['to'] = '';
				return $args;
			}
		);
		$result = Diagnostics::test( 'owner@example.test' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'error', $result['status'] );
		$this->assertNotSame( '', $result['message'] );
		$this->assertStringNotContainsString( 'PHPMailer', $result['message'] );
	}
}
