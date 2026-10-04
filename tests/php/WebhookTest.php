<?php
/**
 * Tests for webhooks: safe HTTP client, connections and their secrets, executor, signature, payload.
 *
 * They talk real HTTP to a local receiver (tests/php/stub/receiver.php).
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Actions\SendWebhook;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Automation\Webhook\Executor;
use Krivo\ChatFlow\Automation\Webhook\PayloadBuilder;
use Krivo\ChatFlow\Automation\Webhook\Signer;
use Krivo\ChatFlow\Infrastructure\Http\SafeClient;

/**
 * @group webhook
 */
class WebhookTest extends Krivo_TestCase {

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
	}

	/**
	 * Options that let the client reach the local receiver.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function local( array $over = array() ): array {
		return array_merge(
			array(
				'allow_http'    => true,
				'allow_private' => true,
				'timeout'       => 5,
			),
			$over
		);
	}

	private function post( string $path, array $headers = array(), array $options = null ): array {
		return SafeClient::request( 'POST', StubServer::url( $path ), '{"a":1}', $headers, $options ?? $this->local() );
	}

	/**
	 * Saves a connection pointing at the local receiver.
	 *
	 * @param string $path Receiver path.
	 * @param array  $over Overrides.
	 * @return array|WP_Error
	 */
	private function connection( string $path = '/ok', array $over = array() ) {
		return ConnectionRepository::save(
			array_merge(
				array(
					'name'                => 'Local receiver',
					'url'                 => StubServer::url( $path ),
					'allow_http'          => true,
					'allow_private_hosts' => true,
					'acknowledged'        => true,
					'enabled'             => true,
					'timeout'             => 5,
				),
				$over
			),
			1
		);
	}

	// ------------------------------------------------------------------ SafeClient

	public function test_successful_request_reports_status_and_sends_the_body(): void {
		$result = $this->post( '/created', array( 'X-Test' => 'yes', 'Content-Type' => 'application/json' ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 201, $result['status'] );
		$this->assertSame( 'ok', $result['category'] );
		$this->assertFalse( $result['retry'] );
		$this->assertGreaterThanOrEqual( 0, $result['duration_ms'] );

		$received = StubServer::requests();
		$this->assertCount( 1, $received );
		$this->assertSame( 'POST', $received[0]['method'] );
		$this->assertSame( '{"a":1}', $received[0]['body'] );
		$this->assertSame( 'yes', $received[0]['headers']['x-test'] );
	}

	public function test_private_and_loopback_addresses_are_refused_by_default_without_any_request(): void {
		$result = $this->post( '/ok', array(), array( 'allow_http' => true ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'blocked', $result['category'] );
		$this->assertFalse( $result['retry'], 'A blocked address is never retried' );
		$this->assertSame( array(), StubServer::requests(), 'Nothing reached the target' );

		foreach ( array( 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/hook', 'http://192.168.1.10/hook', 'http://[::1]/hook', 'http://localhost/hook', 'http://127.0.0.1:22/' ) as $url ) {
			$r = SafeClient::request( 'POST', $url, '{}', array(), array( 'allow_http' => true ) );
			$this->assertFalse( $r['ok'], $url );
			$this->assertSame( 'blocked', $r['category'], $url );
		}
	}

	public function test_plain_http_needs_explicit_permission(): void {
		$result = SafeClient::request( 'POST', StubServer::url(), '{}', array(), array( 'allow_private' => true ) );
		$this->assertSame( 'insecure', $result['category'] );
		$this->assertSame( array(), StubServer::requests() );
	}

	public function test_malformed_and_dangerous_urls_are_refused(): void {
		foreach ( array( '', 'not a url', 'ftp://example.test/x', 'file:///etc/passwd', 'gopher://127.0.0.1/', 'https://user:pass@example.test/hook', '//example.test/hook', 'https://' . str_repeat( 'a', 3000 ) . '.test/' ) as $url ) {
			$r = SafeClient::request( 'POST', $url, '{}', array(), $this->local() );
			$this->assertFalse( $r['ok'], $url );
			$this->assertContains( $r['category'], array( 'blocked', 'insecure' ), $url );
		}
		$this->assertSame( array(), StubServer::requests() );
	}

	public function test_only_write_methods_are_allowed(): void {
		foreach ( array( 'GET', 'DELETE', 'HEAD', 'TRACE', 'CONNECT' ) as $method ) {
			$this->assertSame( 'blocked', SafeClient::request( $method, StubServer::url(), '', array(), $this->local() )['category'], $method );
		}
		$this->assertSame( array(), StubServer::requests() );
	}

	public function test_redirects_are_not_followed(): void {
		$result = $this->post( '/redirect' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'redirect', $result['category'] );
		$this->assertSame( 302, $result['status'] );
		$this->assertFalse( $result['retry'] );
		$paths = array_column( StubServer::requests(), 'path' );
		$this->assertSame( array( '/redirect' ), $paths, 'The Location target was never requested' );
	}

	public function test_server_errors_and_rate_limits_are_retryable_client_errors_are_not(): void {
		$cases = array(
			'/500' => array( 500, 'http_5xx', true, 0 ),
			'/503' => array( 503, 'http_5xx', true, 120 ),
			'/429' => array( 429, 'http_4xx', true, 30 ),
			'/400' => array( 400, 'http_4xx', false, 0 ),
		);
		foreach ( $cases as $path => list( $status, $category, $retry, $after ) ) {
			$r = $this->post( $path );
			$this->assertFalse( $r['ok'], $path );
			$this->assertSame( $status, $r['status'], $path );
			$this->assertSame( $category, $r['category'], $path );
			$this->assertSame( $retry, $r['retry'], $path );
			$this->assertSame( $after, $r['retry_after'], $path );
		}
	}

	public function test_a_slow_receiver_times_out_and_is_retryable(): void {
		$result = $this->post( '/slow', array(), $this->local( array( 'timeout' => 1 ) ) );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'timeout', $result['category'] );
		$this->assertTrue( $result['retry'] );
		$this->assertLessThan( 2500, $result['duration_ms'] );
	}

	public function test_refused_connection_is_reported_as_connection_and_retryable(): void {
		$result = SafeClient::request( 'POST', 'http://127.0.0.1:1/hook', '{}', array(), $this->local() );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'connection', $result['category'] );
		$this->assertTrue( $result['retry'] );
	}

	public function test_result_never_carries_the_response_body_or_headers(): void {
		$result = $this->post( '/500' );
		$this->assertSame( array( 'ok', 'status', 'category', 'retry', 'retry_after', 'duration_ms' ), array_keys( $result ) );
		$this->assertStringNotContainsString( 'secret-token-in-body', wp_json_encode( $result ) );
	}

	public function test_private_host_permission_does_not_leak_to_other_requests(): void {
		global $wp_filter;
		$count = static function ( string $hook ): int {
			global $wp_filter;
			return isset( $wp_filter[ $hook ] ) ? count( $wp_filter[ $hook ]->callbacks, COUNT_RECURSIVE ) : 0;
		};
		$before = array( $count( 'http_request_host_is_external' ), $count( 'http_allowed_safe_ports' ) );
		$this->post( '/ok' );
		$after = SafeClient::request( 'POST', StubServer::url(), '{}', array(), array( 'allow_http' => true ) );
		$this->assertSame( 'blocked', $after['category'], 'The permission was only for the first request' );
		$this->assertSame( $before, array( $count( 'http_request_host_is_external' ), $count( 'http_allowed_safe_ports' ) ), 'The filters opened for one request are removed again' );
		unset( $wp_filter );
	}

	// ------------------------------------------------------------------ connections and secrets

	public function test_secrets_are_encrypted_at_rest_and_never_returned(): void {
		$view = $this->connection(
			'/ok?token=SUPER-SECRET-PATH-TOKEN',
			array(
				'auth'    => array( 'mode' => 'bearer', 'secret' => 'bearer-secret-123' ),
				'headers' => array(
					array( 'name' => 'X-Plain', 'value' => 'visible-value' ),
					array( 'name' => 'X-Api-Key', 'value' => 'header-secret-456', 'secret' => true ),
				),
				'signing' => array( 'enabled' => true, 'secret' => 'sign-secret-789' ),
			)
		);
		$this->assertIsArray( $view );

		$stored = wp_json_encode( array( get_option( ConnectionRepository::OPTION ), get_option( 'krivo_chatflow_secrets' ) ) );
		foreach ( array( 'SUPER-SECRET-PATH-TOKEN', 'bearer-secret-123', 'header-secret-456', 'sign-secret-789' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored, 'Never stored in clear' );
			$this->assertStringNotContainsString( $secret, wp_json_encode( $view ), 'Never sent to the admin' );
			$this->assertStringNotContainsString( $secret, wp_json_encode( ConnectionRepository::public_list() ) );
		}
		$this->assertStringContainsString( 'visible-value', wp_json_encode( $view ), 'A non-secret header is shown' );
		$this->assertSame( '127.0.0.1:' . StubServer::port(), $view['url_host'] );
		$this->assertTrue( $view['has_url'] );
		$this->assertTrue( $view['auth']['has_secret'] );
		$this->assertTrue( $view['signing']['has_secret'] );

		$runtime = ConnectionRepository::runtime( $view['id'] );
		$this->assertSame( StubServer::url( '/ok?token=SUPER-SECRET-PATH-TOKEN' ), $runtime['url'] );
		$this->assertSame( 'bearer-secret-123', $runtime['auth_secret'] );
		$this->assertSame( 'header-secret-456', $runtime['headers']['X-Api-Key'] );
	}

	public function test_omitting_a_secret_keeps_it_and_clear_removes_it(): void {
		$view = $this->connection( '/auth', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'test-token' ) ) );
		$id   = $view['id'];

		$again = ConnectionRepository::save( array( 'id' => $id, 'name' => 'Renamed' ), 1 );
		$this->assertSame( 'Renamed', $again['name'] );
		$this->assertTrue( $again['has_url'], 'URL kept' );
		$this->assertTrue( $again['auth']['has_secret'], 'Bearer token kept' );
		$this->assertSame( 'test-token', ConnectionRepository::runtime( $id )['auth_secret'] );

		$cleared = ConnectionRepository::save( array( 'id' => $id, 'auth' => array( 'mode' => 'bearer', 'clear' => true ) ), 1 );
		$this->assertFalse( $cleared['auth']['has_secret'] );
		$this->assertSame( '', ConnectionRepository::runtime( $id )['auth_secret'] );
	}

	public function test_secret_header_value_can_be_kept_while_editing_headers(): void {
		$view = $this->connection( '/ok', array( 'headers' => array( array( 'name' => 'X-Api-Key', 'value' => 'keep-me', 'secret' => true ) ) ) );
		$id   = $view['id'];
		ConnectionRepository::save( array( 'id' => $id, 'headers' => array( array( 'name' => 'X-Api-Key', 'secret' => true, 'keep' => true ), array( 'name' => 'X-New', 'value' => 'v' ) ) ), 1 );
		$headers = ConnectionRepository::runtime( $id )['headers'];
		$this->assertSame( 'keep-me', $headers['X-Api-Key'] );
		$this->assertSame( 'v', $headers['X-New'] );
	}

	public function test_enabling_a_connection_requires_the_data_sharing_acknowledgement(): void {
		$result = $this->connection( '/ok', array( 'acknowledged' => false, 'enabled' => true ) );
		$this->assertWPErrorCode( 'krivo_chatflow_ack', $result );

		$off = $this->connection( '/ok', array( 'acknowledged' => false, 'enabled' => false ) );
		$this->assertIsArray( $off );
		$this->assertFalse( $off['enabled'] );
		$this->assertSame( '', $off['acknowledged_at'] );

		$on = ConnectionRepository::save( array( 'id' => $off['id'], 'enabled' => true, 'acknowledged' => true ), 7 );
		$this->assertTrue( $on['enabled'] );
		$this->assertNotSame( '', $on['acknowledged_at'] );
		$this->assertSame( 7, $on['acknowledged_by'] );
	}

	public function test_invalid_connection_input_is_rejected(): void {
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => '', 'url' => 'https://example.test/h' ), 1 ), 'name' );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X' ), 1 ), 'url is required' );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X', 'url' => 'http://example.test/h' ), 1 ), 'http not allowed' );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X', 'url' => 'https://u:p@example.test/h' ), 1 ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X', 'url' => 'javascript:alert(1)' ), 1 ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X', 'url' => 'https://example.test/h', 'signing' => array( 'enabled' => true ) ), 1 ), 'signing needs a secret' );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', ConnectionRepository::save( array( 'name' => 'X', 'url' => 'https://example.test/h', 'auth' => array( 'mode' => 'header', 'header_name' => "X-A\r\nInjected: 1", 'secret' => 'v' ) ), 1 ) );
		$this->assertWPErrorCode( 'krivo_chatflow_not_found', ConnectionRepository::save( array( 'id' => 'con_nope1234', 'name' => 'X' ), 1 ) );
		$this->assertSame( array(), ConnectionRepository::all() );
	}

	public function test_headers_that_must_not_be_overridden_are_refused_and_newlines_are_stripped(): void {
		foreach ( array( 'Host', 'Content-Length', 'Content-Type', 'Transfer-Encoding', 'Connection', 'User-Agent', 'Proxy-Authorization' ) as $name ) {
			$r = $this->connection( '/ok', array( 'headers' => array( array( 'name' => $name, 'value' => 'x' ) ) ) );
			$this->assertWPErrorCode( 'krivo_chatflow_invalid', $r, $name );
		}
		$view = $this->connection( '/ok', array( 'headers' => array( array( 'name' => 'X-Safe', 'value' => "ok\r\nX-Injected: 1" ) ) ) );
		$this->assertStringNotContainsString( "\n", ConnectionRepository::runtime( $view['id'] )['headers']['X-Safe'] );
	}

	public function test_deleting_a_connection_deletes_its_secrets(): void {
		$view = $this->connection( '/ok', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'gone-soon' ), 'signing' => array( 'enabled' => true, 'secret' => 's' ) ) );
		$this->assertTrue( ConnectionRepository::delete( $view['id'] ) );
		$this->assertFalse( ConnectionRepository::delete( $view['id'] ) );
		$this->assertNull( ConnectionRepository::runtime( $view['id'] ) );
		$this->assertStringNotContainsString( $view['id'], wp_json_encode( get_option( 'krivo_chatflow_secrets' ) ) );
	}

	// ------------------------------------------------------------------ executor

	public function test_executor_sends_identification_headers_and_json_body(): void {
		$view    = $this->connection();
		$runtime = ConnectionRepository::runtime( $view['id'] );
		$result  = Executor::send( $runtime, array( 'hello' => 'world' ), 'form.submitted', 'rn_1_abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'The receiving service accepted the request.', $result['summary'] );
		$request = StubServer::requests()[0];
		$this->assertSame( 'application/json; charset=utf-8', $request['headers']['content-type'] );
		$this->assertSame( 'form.submitted', $request['headers']['x-krivochatflow-event'] );
		$this->assertSame( 'rn_1_abc', $request['headers']['x-krivochatflow-delivery'] );
		$this->assertSame( array( 'hello' => 'world' ), json_decode( $request['body'], true ) );
	}

	public function test_bearer_basic_and_custom_header_authentication(): void {
		$bearer = $this->connection( '/auth', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'test-token' ) ) );
		$this->assertTrue( Executor::send( ConnectionRepository::runtime( $bearer['id'] ), array(), 'x', 'd' )['ok'] );

		$wrong = $this->connection( '/auth', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'wrong' ) ) );
		$r     = Executor::send( ConnectionRepository::runtime( $wrong['id'] ), array(), 'x', 'd' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 401, $r['status'] );
		$this->assertFalse( $r['retry'], 'Bad credentials are not retried' );

		StubServer::clear();
		$basic = $this->connection( '/ok', array( 'auth' => array( 'mode' => 'basic', 'username' => 'user', 'secret' => 'pa:ss' ) ) );
		Executor::send( ConnectionRepository::runtime( $basic['id'] ), array(), 'x', 'd' );
		$this->assertSame( 'Basic ' . base64_encode( 'user:pa:ss' ), StubServer::requests()[0]['headers']['authorization'] );

		StubServer::clear();
		$header = $this->connection( '/ok', array( 'auth' => array( 'mode' => 'header', 'header_name' => 'X-Token', 'secret' => 'abc' ) ) );
		Executor::send( ConnectionRepository::runtime( $header['id'] ), array(), 'x', 'd' );
		$this->assertSame( 'abc', StubServer::requests()[0]['headers']['x-token'] );
	}

	public function test_signature_is_valid_for_the_exact_body_and_rejects_tampering(): void {
		$view = $this->connection( '/signed', array( 'signing' => array( 'enabled' => true, 'secret' => 'shh-secret' ) ) );
		$this->assertTrue( Executor::send( ConnectionRepository::runtime( $view['id'] ), array( 'a' => 1 ), 'x', 'd' )['ok'], 'The receiver verified the HMAC' );

		$wrong = $this->connection( '/signed', array( 'signing' => array( 'enabled' => true, 'secret' => 'another-secret' ) ) );
		$this->assertSame( 401, Executor::send( ConnectionRepository::runtime( $wrong['id'] ), array( 'a' => 1 ), 'x', 'd' )['status'] );

		$now    = 1700000000;
		$header = Signer::sign( 's', '{"a":1}', $now );
		$this->assertTrue( Signer::verify( 's', '{"a":1}', $header, 300, $now + 10 ) );
		$this->assertFalse( Signer::verify( 's', '{"a":2}', $header, 300, $now ), 'Body changed' );
		$this->assertFalse( Signer::verify( 'other', '{"a":1}', $header, 300, $now ), 'Wrong secret' );
		$this->assertFalse( Signer::verify( 's', '{"a":1}', $header, 300, $now + 1000 ), 'Replay outside the tolerance' );
		$this->assertFalse( Signer::verify( 's', '{"a":1}', 't=1,v1=zz', 300, $now ), 'Malformed' );
	}

	public function test_executor_with_a_missing_url_makes_no_request(): void {
		$runtime        = ConnectionRepository::runtime( $this->connection()['id'] );
		$runtime['url'] = '';
		$result         = Executor::send( $runtime, array(), 'x', 'd' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), StubServer::requests() );
	}

	public function test_request_arguments_can_be_filtered_but_secrets_stay_out_of_the_hooks(): void {
		$runtime = ConnectionRepository::runtime( $this->connection( '/ok?token=URL-SECRET', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'AUTH-SECRET' ) ) )['id'] );
		$seen    = array();
		add_action(
			'krivo_chatflow_before_webhook',
			static function ( $summary ) use ( &$seen ) {
				$seen[] = $summary;
			}
		);
		add_action(
			'krivo_chatflow_after_webhook',
			static function ( $result, $summary ) use ( &$seen ) {
				$seen[] = array( $result, $summary );
			},
			10,
			2
		);
		add_filter(
			'krivo_chatflow_webhook_request_args',
			static function ( $args ) {
				$args['headers']['X-Added'] = 'by-filter';
				return $args;
			}
		);
		Executor::send( $runtime, array(), 'form.submitted', 'rn_9' );

		$this->assertSame( 'by-filter', StubServer::requests()[0]['headers']['x-added'] );
		$this->assertCount( 2, $seen );
		$json = wp_json_encode( $seen );
		$this->assertStringNotContainsString( 'URL-SECRET', $json );
		$this->assertStringNotContainsString( 'AUTH-SECRET', $json );
		$this->assertSame( '127.0.0.1', $seen[0]['host'] );
	}

	// ------------------------------------------------------------------ payload

	private function context( array $lead_over = array(), array $flow_over = array() ): RunContext {
		$lead = array_merge(
			array(
				'id'                => 5,
				'public_id'         => '0b1c2d3e-aaaa-bbbb-cccc-111122223333',
				'status'            => 'new',
				'channel'           => 'whatsapp',
				'name'              => 'Maria <b>Silva</b>',
				'email'             => 'maria@example.test',
				'phone'             => '11999991234',
				'destination_key'   => 'dst_sales',
				'destination_label' => 'Sales',
				'in_hours'          => 1,
				'created_at'        => '2026-01-02 03:04:05',
				'consent'           => array( array( 'key' => 'consent', 'accepted' => true ) ),
				'answers'           => array(
					'name'  => array( 'label' => 'Name', 'type' => 'name', 'value' => 'Maria <b>Silva</b>', 'display' => 'Maria <b>Silva</b>' ),
					'email' => array( 'label' => 'E-mail', 'type' => 'email', 'value' => 'maria@example.test', 'display' => 'maria@example.test' ),
				),
				'page_url'          => 'https://example.test/p',
				'page_title'        => 'P',
				'utm_source'        => 'google',
			),
			$lead_over
		);
		$flow = Krivo\ChatFlow\Core\Flow\Sanitizer::sanitize(
			array_merge(
				array(
					'name'  => 'Contact',
					'steps' => array(
						array(
							'fields' => array(
								array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Name', 'key' => 'name' ),
								array( 'id' => 'f_mail', 'type' => 'email', 'label' => 'E-mail', 'key' => 'email' ),
								array( 'id' => 'f_msg', 'type' => 'textarea', 'label' => 'Message', 'key' => 'message' ),
							),
						),
					),
				),
				$flow_over
			)
		);
		return new RunContext( 3, $flow, $lead, 'form.submitted' );
	}

	public function test_default_payload_has_the_documented_shape(): void {
		$payload = PayloadBuilder::build( $this->context(), array(), 'form.submitted', 'rn_1_0b1c2d3e' );
		$this->assertSame( 'form.submitted', $payload['event'] );
		$this->assertSame( 'rn_1_0b1c2d3e', $payload['delivery_id'] );
		$this->assertSame( 3, $payload['flow']['id'] );
		$this->assertSame( 'maria@example.test', $payload['lead']['email'] );
		$this->assertSame( 'Sales', $payload['lead']['destination']['label'] );
		$this->assertSame( '2026-01-02T03:04:05Z', $payload['lead']['created_at'] );
		$this->assertSame( 'maria@example.test', $payload['answers']->email );
		$this->assertSame( 'E-mail', $payload['answers_labeled'][1]['label'] );
		$this->assertSame( 'google', $payload['tracking']['utm_source'] );
		$this->assertTrue( $payload['consent'][0]['accepted'] );
		$this->assertArrayHasKey( 'url', $payload['site'] );
	}

	public function test_custom_template_substitutes_only_inside_strings_and_keeps_the_structure(): void {
		$template = wp_json_encode(
			array(
				'text'   => 'New lead {name} <{email}> from {utm_source}',
				'nested' => array( 'flow' => '{flow_id}', 'event' => '{event}', 'count' => 3, 'ok' => true ),
				'{name}' => 'keys are not substituted',
			)
		);
		$payload = PayloadBuilder::build( $this->context( array( 'answers' => array( 'name' => array( 'label' => 'Name', 'type' => 'name', 'value' => "O'Brien & Sons", 'display' => "O'Brien & Sons" ), 'email' => array( 'label' => 'E-mail', 'type' => 'email', 'value' => 'maria@example.test', 'display' => 'maria@example.test' ) ) ) ), array( 'payload' => array( 'mode' => 'custom', 'template' => $template ) ), 'form.submitted', 'd' );
		$this->assertSame( "New lead O'Brien & Sons <maria@example.test> from google", $payload['text'], 'JSON carries data: nothing is HTML-escaped, so receivers get the exact text' );
		$this->assertSame( '3', $payload['nested']['flow'] );
		$this->assertSame( 'form.submitted', $payload['nested']['event'] );
		$this->assertSame( 3, $payload['nested']['count'] );
		$this->assertTrue( $payload['nested']['ok'] );
		$this->assertSame( 'keys are not substituted', $payload['{name}'] );
	}

	public function test_a_value_cannot_break_out_of_the_json_template(): void {
		$template = '{"text":"{message}"}';
		$ctx      = $this->context(
			array(
				'answers' => array( 'message' => array( 'label' => 'Message', 'type' => 'textarea', 'value' => '","admin":true,"x":"', 'display' => '","admin":true,"x":"' ) ),
			)
		);
		$payload = PayloadBuilder::build( $ctx, array( 'payload' => array( 'mode' => 'custom', 'template' => $template ) ), 'x', 'd' );
		$this->assertSame( array( 'text' ), array_keys( $payload ), 'A visitor cannot add keys to the payload' );
		$this->assertSame( '","admin":true,"x":"', $payload['text'] );
	}

	public function test_invalid_custom_template_falls_back_to_the_default_payload(): void {
		$payload = PayloadBuilder::build( $this->context(), array( 'payload' => array( 'mode' => 'custom', 'template' => '{not json' ) ), 'form.submitted', 'd' );
		$this->assertSame( 'form.submitted', $payload['event'] );
		$this->assertArrayHasKey( 'lead', $payload );
	}

	// ------------------------------------------------------------------ action

	public function test_action_sends_to_an_enabled_connection(): void {
		$view   = $this->connection( '/created' );
		$ctx    = $this->context();
		$result = ( new SendWebhook() )->run( $ctx, array( 'connection_id' => $view['id'] ) );

		$this->assertSame( Result::SUCCESS, $result->status );
		$this->assertSame( 201, $result->http_status );
		$request = StubServer::requests()[0];
		$this->assertSame( '/created', $request['path'] );
		$body = json_decode( $request['body'], true );
		$this->assertSame( 'maria@example.test', $body['lead']['email'] );
		$this->assertSame( 3, $body['flow']['id'] );
	}

	public function test_a_disabled_or_missing_connection_makes_zero_requests(): void {
		$view = $this->connection( '/ok', array( 'enabled' => false ) );
		$off  = ( new SendWebhook() )->run( $this->context(), array( 'connection_id' => $view['id'] ) );
		$this->assertSame( Result::SKIPPED, $off->status );
		$this->assertSame( 'connection_disabled', $off->error_code );

		$missing = ( new SendWebhook() )->run( $this->context(), array( 'connection_id' => 'con_missing01' ) );
		$this->assertSame( Result::SKIPPED, $missing->status );

		$none = ( new SendWebhook() )->run( $this->context(), array() );
		$this->assertSame( Result::SKIPPED, $none->status );
		$this->assertSame( array(), StubServer::requests() );
	}

	public function test_action_failure_is_retryable_for_5xx_and_final_for_4xx(): void {
		$five = $this->connection( '/500' );
		$r5   = ( new SendWebhook() )->run( $this->context(), array( 'connection_id' => $five['id'] ) );
		$this->assertSame( Result::FAILED, $r5->status );
		$this->assertTrue( $r5->retry );
		$this->assertSame( 'http_5xx', $r5->error_code );
		$this->assertStringNotContainsString( 'secret-token-in-body', $r5->error_message, 'The response body never reaches the log' );

		$four = $this->connection( '/400' );
		$r4   = ( new SendWebhook() )->run( $this->context(), array( 'connection_id' => $four['id'] ) );
		$this->assertFalse( $r4->retry );
	}

	public function test_error_messages_never_contain_the_url_or_the_credentials(): void {
		$view = $this->connection( '/500?token=LEAK-ME', array( 'auth' => array( 'mode' => 'bearer', 'secret' => 'LEAK-AUTH' ) ) );
		$r    = ( new SendWebhook() )->run( $this->context(), array( 'connection_id' => $view['id'] ) );
		$this->assertStringNotContainsString( 'LEAK-ME', $r->error_message );
		$this->assertStringNotContainsString( 'LEAK-AUTH', $r->error_message );
		$this->assertStringNotContainsString( '127.0.0.1', $r->error_message );
	}

	public function test_action_config_sanitiser_accepts_only_valid_connection_ids_and_modes(): void {
		$action = new SendWebhook();
		$clean  = $action->sanitize( array( 'connection_id' => '../../etc', 'payload' => array( 'mode' => 'evil', 'template' => "{\"a\":\"b\"}\x00" ) ) );
		$this->assertSame( '', $clean['connection_id'] );
		$this->assertSame( 'default', $clean['payload']['mode'] );
		$this->assertStringNotContainsString( "\x00", $clean['payload']['template'] );
		$this->assertSame( 'con_abc12345', $action->sanitize( array( 'connection_id' => 'con_abc12345' ) )['connection_id'] );
	}
}
