<?php
/**
 * Tests for the public REST endpoints and the admin-ajax fallback.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Rest\AjaxFallback;
use Krivo\ChatFlow\Rest\PublicApi;

/**
 * @group rest
 */
class RestPublicTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		unset( $_SERVER['HTTP_ORIGIN'] );
		add_filter( 'pre_schedule_event', '__return_true' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_ORIGIN'], $_POST['payload'] );
		parent::tear_down();
	}

	/**
	 * Dispatches a REST request with a JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below krivo-chatflow/v1.
	 * @param array  $body   Body (or query for GET).
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/krivo-chatflow/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		// rest_do_request() skips the filter that the real server applies when it serves the request.
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	private function open_session( int $flow ): string {
		Clock::freeze( self::T0 );
		$response = $this->call( 'POST', '/public/session', array( 'flow' => $flow ) );
		Clock::freeze( self::T0 + 10 );
		return (string) $response->get_data()['token'];
	}

	// ------------------------------------------------------------------ manifest

	public function test_manifest_of_a_published_flow_is_public_cacheable_and_has_nothing_private(): void {
		$id       = $this->flow();
		$response = $this->call( 'GET', '/public/flows/' . $id );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'form', $data['mode'] );
		$this->assertSame( array( 'name', 'email', 'phone', 'budget', 'message', 'consent' ), array_column( $data['steps'][0]['fields'], 'key' ) );
		$this->assertArrayHasKey( 'i18n', $data );
		$this->assertTrue( $data['finish']['whatsapp'] );

		$json = wp_json_encode( $data );
		foreach ( array( 'sales@example.test', 'vip@example.test', '5511999998888', '5521988887777', 'dst_sales', 'dst_vip01', 'automations', 'a_mail', 'destination', 'send_email', 'admin_email' ) as $private ) {
			$this->assertStringNotContainsString( $private, $json, $private . ' must never reach a visitor' );
		}

		$headers = $response->get_headers();
		$this->assertSame( 'public, max-age=300, stale-while-revalidate=60', $headers['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^"kcf-\d+-1"$/', $headers['ETag'] );
		$this->assertArrayNotHasKey( 'X-Krivo-Chatflow-Cacheable', $headers, 'The internal marker is removed' );
	}

	public function test_manifest_follows_the_language_the_page_asks_for(): void {
		$id = $this->flow();
		$available = static function ( $languages ) {
			return array_merge( (array) $languages, array( 'pt_BR' ) );
		};
		add_filter( 'get_available_languages', $available );
		// The locale switcher reads the available languages once, when it is created.
		$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();
		try {
			unload_textdomain( 'krivo-chatflow', true ); // Reloadable: a plain unload stops WordPress from loading it again in this process.
			load_plugin_textdomain( 'krivo-chatflow', false, 'krivo-chatflow/languages' );
			$english    = $this->call( 'GET', '/public/flows/' . $id )->get_data();
			$portuguese = $this->call( 'GET', '/public/flows/' . $id, array( 'lang' => 'pt_BR' ) )->get_data();
			$this->assertSame( 'Continue', $english['i18n']['next'] );
			$this->assertSame( 'Continuar', $portuguese['i18n']['next'] );
			$this->assertSame( 'Continue', $this->call( 'GET', '/public/flows/' . $id )->get_data()['i18n']['next'], 'the language is restored afterwards' );
		} finally {
			remove_filter( 'get_available_languages', $available );
			$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
			$GLOBALS['wp_locale_switcher']->init();
		}
	}

	public function test_manifest_ignores_a_language_it_does_not_have_and_malformed_values(): void {
		$id = $this->flow();
		foreach ( array( 'xx_YY', '../../etc/passwd', 'pt_BR<script>', str_repeat( 'a', 300 ) ) as $lang ) {
			$response = $this->call( 'GET', '/public/flows/' . $id, array( 'lang' => $lang ) );
			$this->assertSame( 200, $response->get_status(), $lang );
			$this->assertSame( 'Continue', $response->get_data()['i18n']['next'], $lang );
		}
	}

	public function test_manifest_is_not_cached_for_logged_in_users(): void {
		$id = $this->flow();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'no-store', $this->call( 'GET', '/public/flows/' . $id )->get_headers()['Cache-Control'] );
	}

	public function test_drafts_paused_and_unknown_flows_are_not_found(): void {
		$draft = FlowRepository::create( array( 'name' => 'Draft' ), 1 );
		$this->assertSame( 404, $this->call( 'GET', '/public/flows/' . $draft )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/public/flows/999999' )->get_status() );

		$id = $this->flow();
		FlowRepository::pause( $id );
		$response = $this->call( 'GET', '/public/flows/' . $id );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'krivo_chatflow_not_found', $response->get_data()['code'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	// ------------------------------------------------------------------ session and events

	public function test_session_issues_a_token_and_counts_the_open(): void {
		$id       = $this->flow();
		$response = $this->call( 'POST', '/public/session', array( 'flow' => $id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $response->get_data()['token'] );
		$this->assertSame( 3000, $response->get_data()['min_wait_ms'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 1, StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id )['open'] );
	}

	public function test_session_for_an_unpublished_flow_is_refused(): void {
		$draft = FlowRepository::create( array( 'name' => 'Draft' ), 1 );
		$this->assertSame( 404, $this->call( 'POST', '/public/session', array( 'flow' => $draft ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/public/session', array() )->get_status(), 'The flow is required' );
	}

	public function test_session_is_rate_limited(): void {
		$id = $this->flow();
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 200, $this->call( 'POST', '/public/session', array( 'flow' => $id ) )->get_status() );
		}
		$this->assertSame( 429, $this->call( 'POST', '/public/session', array( 'flow' => $id ) )->get_status() );
	}

	public function test_events_count_only_known_metrics_and_answer_204(): void {
		$id       = $this->flow();
		$response = $this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array( 'view', 'start', 'lead', 'whatsapp_click', 'drop table' ) ) );
		$this->assertSame( 204, $response->get_status() );
		$this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array( 'view', 'view' ) ) );
		$totals = StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id );
		$this->assertSame( 2, $totals['view'], 'One request counts an event once, however many times it is repeated' );
		$this->assertSame( 1, $totals['start'] );
		$this->assertSame( 0, $totals['lead'], 'A browser can never report a lead: leads are counted by the server' );
		$this->assertSame( 0, $totals['whatsapp_click'] );
	}

	public function test_view_counting_can_be_turned_off(): void {
		$id = $this->flow();
		Settings::update( array( 'analytics' => array( 'count_views' => false ) ) );
		$this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array( 'view', 'start' ) ) );
		$totals = StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id );
		$this->assertSame( 0, $totals['view'] );
		$this->assertSame( 1, $totals['start'] );
	}

	public function test_events_validate_their_arguments(): void {
		$id = $this->flow();
		$this->assertSame( 400, $this->call( 'POST', '/public/events', array( 'flow' => $id ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array_fill( 0, 6, 'view' ) ) )->get_status() );
	}

	// ------------------------------------------------------------------ submissions

	public function test_full_visitor_journey_over_rest(): void {
		$id    = $this->flow();
		$token = $this->open_session( $id );
		$response = $this->call(
			'POST',
			'/public/submissions',
			array(
				'flow'    => $id,
				'token'   => $token,
				'answers' => $this->valid_answers( array( 'budget' => 'high' ) ),
				'context' => array( 'url' => 'https://example.test/p', 'title' => 'P', 'device' => 'mobile', 'utm' => array( 'source' => 'google' ) ),
				'hp'      => '',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'open_whatsapp', $data['directives'][0]['type'] );
		$this->assertStringStartsWith( 'https://wa.me/5521988887777', $data['directives'][0]['url'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		$lead = LeadRepository::get_by_public_id( $data['lead'] );
		$this->assertSame( 'Maria Silva', $lead['name'] );
		$this->assertSame( 'google', $lead['utm_source'] );
		$this->assertTrue( $lead['consent'][0]['accepted'], 'JSON true is accepted for the consent checkbox' );
	}

	public function test_validation_errors_come_back_per_field_with_status_400(): void {
		$id       = $this->flow();
		$response = $this->call(
			'POST',
			'/public/submissions',
			array(
				'flow'    => $id,
				'token'   => $this->open_session( $id ),
				'answers' => $this->valid_answers( array( 'email' => 'nope', 'consent' => false ) ),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'krivo_chatflow_validation', $data['code'] );
		$this->assertArrayHasKey( 'email', $data['errors'] );
		$this->assertArrayHasKey( 'consent', $data['errors'] );
		$this->assertSame( array( 'code', 'message', 'errors' ), array_keys( $data ), 'No internal detail is exposed' );
	}

	public function test_token_failures_are_403_and_rate_limit_is_429_with_retry_after(): void {
		$id = $this->flow();
		$this->assertSame( 403, $this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => 'bad.token', 'answers' => $this->valid_answers() ) )->get_status() );

		Settings::update( array( 'antispam' => array( 'rate_limit_max' => 1, 'rate_limit_window' => 600 ) ) );
		$this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => $this->open_session( $id ), 'answers' => $this->valid_answers() ) );
		$limited = $this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => $this->open_session( $id ), 'answers' => $this->valid_answers( array( 'email' => 'b@example.test' ) ) ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( '600', $limited->get_headers()['Retry-After'] );
	}

	public function test_a_foreign_origin_is_refused_on_every_public_route(): void {
		$id                     = $this->flow();
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->assertSame( 403, $this->call( 'GET', '/public/flows/' . $id )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/public/session', array( 'flow' => $id ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array( 'view' ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => 'x', 'answers' => array() ) )->get_status() );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_an_oversized_body_is_refused_before_it_is_processed(): void {
		$id       = $this->flow();
		$response = $this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => $this->open_session( $id ), 'answers' => array( 'message' => str_repeat( 'x', PublicApi::MAX_BODY_BYTES ) ) ) );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_the_honeypot_over_rest_looks_like_success(): void {
		$id       = $this->flow();
		$response = $this->call( 'POST', '/public/submissions', array( 'flow' => $id, 'token' => $this->open_session( $id ), 'answers' => $this->valid_answers(), 'hp' => 'bot' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['lead'] );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_public_routes_have_real_permission_callbacks_and_declare_their_args(): void {
		global $wp_rest_server;
		$found = 0;
		foreach ( $wp_rest_server->get_routes( 'krivo-chatflow/v1' ) as $route => $handlers ) {
			if ( false === strpos( $route, '/public/' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				++$found;
				$this->assertArrayHasKey( 'permission_callback', $handler, $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				$this->assertIsCallable( $handler['permission_callback'], $route );
				$this->assertNotEmpty( $handler['args'], $route . ' declares and validates its arguments' );
			}
		}
		$this->assertSame( 5, $found );
	}

	// ------------------------------------------------------------------ admin-ajax fallback

	/**
	 * Calls an admin-ajax action the way the browser would and returns the decoded answer.
	 *
	 * @param string $action  Method of AjaxFallback.
	 * @param array  $payload Payload (JSON encoded into the "payload" field).
	 * @return array|null
	 */
	private function ajax( string $action, array $payload ): ?array {
		$_POST['payload'] = wp_slash( wp_json_encode( $payload ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'ajax done' );
				};
			}
		);
		ob_start();
		try {
			call_user_func( array( AjaxFallback::class, $action ) );
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// wp_send_json() ends the request.
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		return json_decode( $out, true );
	}

	public function test_ajax_fallback_serves_the_same_services(): void {
		$id = $this->flow();
		$manifest = $this->ajax( 'manifest', array( 'flow' => $id ) );
		$this->assertSame( $id, $manifest['id'] );

		Clock::freeze( self::T0 );
		$session = $this->ajax( 'session', array( 'flow' => $id ) );
		$this->assertNotEmpty( $session['token'] );
		Clock::freeze( self::T0 + 10 );

		$result = $this->ajax( 'submit', array( 'flow' => $id, 'token' => $session['token'], 'answers' => $this->valid_answers(), 'context' => array(), 'hp' => '' ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Maria Silva', LeadRepository::get_by_public_id( $result['lead'] )['name'] );
	}

	public function test_ajax_fallback_applies_the_same_guards(): void {
		$id = $this->flow();
		$bad = $this->ajax( 'submit', array( 'flow' => $id, 'token' => 'forged.token', 'answers' => $this->valid_answers() ) );
		$this->assertSame( 'krivo_chatflow_token_signature', $bad['code'] );

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$origin = $this->ajax( 'session', array( 'flow' => $id ) );
		$this->assertSame( 'krivo_chatflow_origin', $origin['code'] );
		unset( $_SERVER['HTTP_ORIGIN'] );

		$missing = $this->ajax( 'manifest', array( 'flow' => 999999 ) );
		$this->assertSame( 'krivo_chatflow_not_found', $missing['code'] );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_ajax_fallback_ignores_garbage_payloads(): void {
		$_POST['payload'] = '{not json';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'done' );
				};
			}
		);
		ob_start();
		try {
			AjaxFallback::session();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// Expected.
		}
		$out = json_decode( (string) ob_get_clean(), true );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( 'krivo_chatflow_not_found', $out['code'] );
	}
}
