<?php
/**
 * Tests for the "button only" mode: a floating button with a welcome message and no form.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\DryRun;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Core\Flow\Validator;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Core\Submission\Pipeline;
use Krivo\ChatFlow\Frontend\Assets;
use Krivo\ChatFlow\Frontend\Gate;
use Krivo\ChatFlow\Frontend\Renderer;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Rest\AjaxFallback;
use Krivo\ChatFlow\Rest\PublicApi;

/**
 * @group button
 */
class ButtonModeTest extends Krivo_TestCase {

	use Krivo_FlowFactory;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( 0 );
		Gate::reset();
		Assets::reset();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		unset( $_SERVER['HTTP_ORIGIN'] );
		add_filter( 'pre_schedule_event', '__return_true' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_ORIGIN'], $_POST['payload'] );
		Gate::reset();
		Assets::reset();
		parent::tear_down();
	}

	/**
	 * Creates and publishes a "button only" chat.
	 *
	 * @param array $over    Config overrides.
	 * @param bool  $routing Whether to create the routing (destinations) first.
	 * @return int
	 */
	private function button_flow( array $over = array(), bool $routing = true ): int {
		if ( $routing ) {
			$this->routing();
		}
		$config = array_replace_recursive(
			array(
				'name'   => 'Button',
				'mode'   => 'button',
				'button' => array( 'action' => 'whatsapp' ),
				'bubble' => array(
					'tooltip' => array(
						'enabled' => true,
						'title'   => 'Hello!',
						'text'    => 'How can we help?',
						'cta'     => 'Talk to us',
					),
				),
			),
			$over
		);
		$id     = FlowRepository::create( $config, 1 );
		$this->assertGreaterThan( 0, $id );
		$published = FlowRepository::publish( $id );
		$this->assertIsArray( $published, is_wp_error( $published ) ? $published->get_error_message() : '' );
		return $id;
	}

	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/krivo-chatflow/v1' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	private function context( string $path = '/pricing/' ): array {
		return array(
			'url'      => home_url( $path ) . '?x=1',
			'title'    => 'Pricing',
			'referrer' => '',
			'device'   => 'desktop',
			'utm'      => array( 'source' => 'google', 'campaign' => 'spring' ),
		);
	}

	// ------------------------------------------------------------------ the configuration

	public function test_the_blank_flow_has_safe_button_defaults_and_unknown_modes_fall_back(): void {
		$clean = Sanitizer::sanitize( array() );
		$this->assertSame( 'form', $clean['mode'] );
		$this->assertSame( array( 'action' => 'whatsapp', 'url' => '', 'new_tab' => true ), $clean['button'] );
		$this->assertSame( '', $clean['bubble']['tooltip']['title'] );
		$this->assertSame( '', $clean['bubble']['tooltip']['cta'] );

		$this->assertSame( 'button', Sanitizer::sanitize( array( 'mode' => 'button' ) )['mode'] );
		$this->assertSame( 'form', Sanitizer::sanitize( array( 'mode' => 'telepathy' ) )['mode'] );
		$this->assertSame( 'whatsapp', Sanitizer::sanitize( array( 'button' => array( 'action' => 'drop-table' ) ) )['button']['action'] );
	}

	/**
	 * @dataProvider urls
	 */
	public function test_the_address_of_the_button_only_accepts_safe_schemes( $raw, string $expected ): void {
		$this->assertSame( $expected, Sanitizer::button_url( $raw ) );
		$this->assertSame( $expected, Sanitizer::sanitize( array( 'button' => array( 'action' => 'link', 'url' => $raw ) ) )['button']['url'] );
	}

	public function urls(): array {
		return array(
			'https page'            => array( 'https://example.com/contact?a=1', 'https://example.com/contact?a=1' ),
			'http page'             => array( 'http://example.com/', 'http://example.com/' ),
			'page of this site'     => array( '/contact/', '/contact/' ),
			'phone, tidied'         => array( 'tel:+55 (11) 99999-8888', 'tel:+5511999998888' ),
			'phone without plus'    => array( 'TEL:1199998888', 'tel:1199998888' ),
			'e-mail'                => array( 'mailto:oi@example.com', 'mailto:oi@example.com' ),
			'e-mail with subject'   => array( 'mailto:oi@example.com?subject=Hi', 'mailto:oi@example.com' ),
			'javascript'            => array( 'javascript:alert(1)', '' ),
			'javascript in caps'    => array( 'JaVaScRiPt:alert(1)', '' ),
			'data url'              => array( 'data:text/html;base64,PHNjcmlwdD4=', '' ),
			'vbscript'              => array( 'vbscript:msgbox(1)', '' ),
			'file'                  => array( 'file:///etc/passwd', '' ),
			'bad phone'             => array( 'tel:abc', '' ),
			'bad e-mail'            => array( 'mailto:not-an-email', '' ),
			'empty'                 => array( '', '' ),
			'array'                 => array( array( 'https://example.com' ), '' ),
			'too long'              => array( 'https://example.com/' . str_repeat( 'a', 600 ), '' ),
		);
	}

	public function test_the_welcome_card_texts_are_bounded(): void {
		$clean = Sanitizer::sanitize(
			array(
				'bubble' => array(
					'tooltip' => array(
						'title' => str_repeat( 't', 200 ),
						'text'  => str_repeat( 'x', 900 ),
						'cta'   => '<b>' . str_repeat( 'c', 200 ) . '</b>',
					),
				),
			)
		);
		$this->assertSame( 60, mb_strlen( $clean['bubble']['tooltip']['title'] ) );
		$this->assertSame( 240, mb_strlen( $clean['bubble']['tooltip']['text'] ) );
		$this->assertSame( 40, mb_strlen( $clean['bubble']['tooltip']['cta'] ) );
		$this->assertStringNotContainsString( '<', $clean['bubble']['tooltip']['cta'] );
	}

	// ------------------------------------------------------------------ publishing

	public function test_a_button_flow_needs_somewhere_to_send_the_visitor(): void {
		$config = Sanitizer::sanitize( array( 'mode' => 'button' ) );

		$report = Validator::report( $config );
		$this->assertContains( 'no_whatsapp_destination', array_column( $report['errors'], 'code' ), 'No destination at all' );
		$this->assertNotContains( 'no_fields', array_column( $report['errors'], 'code' ), 'There is no form to fill in' );

		$this->routing();
		$this->assertSame( array(), Validator::report( $config )['errors'] );
	}

	public function test_a_destination_without_a_number_is_a_warning_not_a_blocker(): void {
		RoutingRepository::save(
			array(
				'default_destination' => 'dst_sales',
				'destinations'        => array(
					array( 'id' => 'dst_sales', 'name' => 'Sales', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array() ),
					array( 'id' => 'dst_mail1', 'name' => 'Mail only', 'dial_code' => '55', 'number' => '', 'emails' => array( 'a@example.test' ) ),
				),
				'rules'               => array(),
			)
		);
		$config = Sanitizer::sanitize( array( 'mode' => 'button', 'whatsapp' => array( 'default_destination' => 'dst_mail1' ) ) );
		$report = Validator::report( $config );
		$this->assertSame( array(), $report['errors'] );
		$this->assertContains( 'button_destination_no_number', array_column( $report['warnings'], 'code' ) );
	}

	public function test_a_link_button_needs_an_address(): void {
		$empty = Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => 'javascript:alert(1)' ) ) );
		$this->assertContains( 'button_no_url', array_column( Validator::report( $empty )['errors'], 'code' ) );

		$ok = Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => 'https://example.com/' ) ) );
		$this->assertSame( array(), Validator::report( $ok )['errors'], 'A link needs no WhatsApp destination' );
	}

	public function test_stale_automations_and_unknown_variables_do_not_break_a_button_flow(): void {
		$this->routing();
		$config = Sanitizer::sanitize(
			array(
				'mode'        => 'button',
				'whatsapp'    => array( 'message' => 'Hi {name}, from {page_url}' ),
				'automations' => array( array( 'id' => 'a_hook', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => 'cn_gone' ) ) ),
			)
		);
		$report = Validator::report( $config );
		$this->assertSame( array(), $report['errors'], 'Automations belong to the form; they are ignored here' );
		$messages = implode( ' ', array_column( $report['warnings'], 'message' ) );
		$this->assertStringContainsString( '{name}', $messages, 'There is no name without a form' );
		$this->assertStringNotContainsString( '{page_url}', $messages );
	}

	public function test_the_variables_offered_to_a_button_flow_have_no_form_answers(): void {
		$names = array_column( \Krivo\ChatFlow\Core\Variables\Builder::catalog( array( 'mode' => 'button' ) ), 'name' );
		$this->assertContains( 'page_url', $names );
		$this->assertNotContains( 'name', $names );
		$this->assertNotContains( 'all_fields', $names );
	}

	// ------------------------------------------------------------------ what the page learns

	public function test_the_boot_data_describes_the_button_and_never_contains_a_number(): void {
		$id    = $this->button_flow();
		$entry = Index::get( $id );
		$boot  = $entry['boot'];
		$this->assertSame( 'button', $boot['mode'] );
		$this->assertSame( array( 'action' => 'whatsapp', 'url' => '', 'new_tab' => false ), $boot['button'] );
		$this->assertSame( 'Hello!', $boot['bubble']['tooltip']['title'] );
		$this->assertSame( 'Talk to us', $boot['bubble']['tooltip']['cta'] );
		$this->assertArrayHasKey( 'button_error', $boot['i18n'] );

		$json = wp_json_encode( Manifest::build( $id, 1, FlowRepository::get( $id )['published'] ) );
		foreach ( array( '11999998888', '21988887777', '5511999998888' ) as $number ) {
			$this->assertStringNotContainsString( $number, $json, 'The WhatsApp number never reaches the page' );
		}
	}

	public function test_default_texts_follow_the_action_and_forms_have_no_button(): void {
		$wa   = Manifest::boot( 1, 1, Sanitizer::sanitize( array( 'mode' => 'button' ) ) );
		$link = Manifest::boot( 1, 1, Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => '/contact/' ) ) ) );
		$form = Manifest::boot( 1, 1, Sanitizer::sanitize( array( 'mode' => 'form' ) ) );

		$this->assertSame( 'Chat on WhatsApp', $wa['bubble']['label'] );
		$this->assertSame( 'Chat on WhatsApp', $wa['bubble']['tooltip']['cta'] );
		$this->assertSame( 'Contact us', $link['bubble']['label'] );
		$this->assertSame( 'Open chat', $form['bubble']['label'] );
		$this->assertSame( 'Need help?', $form['bubble']['tooltip']['text'] );
		$this->assertSame( '', $form['bubble']['tooltip']['cta'], 'A form chat has no button in its greeting unless asked' );
		$this->assertNull( $form['button'] );
		$this->assertArrayNotHasKey( 'button_error', $form['i18n'] );
	}

	public function test_a_path_on_this_site_becomes_a_full_address_and_only_web_pages_open_in_a_new_tab(): void {
		$page = Manifest::boot( 1, 1, Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => '/contact/', 'new_tab' => true ) ) ) );
		$this->assertSame( home_url( '/contact/' ), $page['button']['url'] );
		$this->assertTrue( $page['button']['new_tab'] );

		$tel = Manifest::boot( 1, 1, Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => 'tel:+5511999998888', 'new_tab' => true ) ) ) );
		$this->assertSame( 'tel:+5511999998888', $tel['button']['url'] );
		$this->assertFalse( $tel['button']['new_tab'], 'A phone call has no tab' );
	}

	// ------------------------------------------------------------------ the WhatsApp click

	public function test_tapping_the_button_returns_the_wa_me_link_and_counts_the_click_on_the_server(): void {
		$id       = $this->button_flow();
		$response = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context() ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertStringStartsWith( 'https://wa.me/5511999998888?text=', $data['url'] );
		$text = urldecode( substr( $data['url'], strlen( 'https://wa.me/5511999998888?text=' ) ) );
		$this->assertStringContainsString( home_url( '/pricing/' ), $text, 'The default message tells where the visitor was' );

		$totals = StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id );
		$this->assertSame( 1, $totals['button_click'] );
		$this->assertSame( 1, $totals['whatsapp_click'] );
		$this->assertSame( 0, $totals['lead'], 'There is no lead: nothing was asked' );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \Krivo\ChatFlow\Infrastructure\Db\Tables::leads() ) );
	}

	public function test_the_message_is_the_one_the_admin_wrote_with_page_and_campaign_variables(): void {
		$id   = $this->button_flow( array( 'whatsapp' => array( 'message' => 'Hi! Page: {page_title} / {utm_campaign} / {site_name}' ) ) );
		$data = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context() ) )->get_data();
		$text = urldecode( substr( $data['url'], strpos( $data['url'], '?text=' ) + 6 ) );
		$this->assertSame( 'Hi! Page: Pricing / spring / ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $text );
	}

	public function test_routing_rules_can_send_visitors_of_some_pages_to_another_person(): void {
		RoutingRepository::save(
			array(
				'default_destination' => 'dst_sales',
				'destinations'        => array(
					array( 'id' => 'dst_sales', 'name' => 'Sales', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array() ),
					array( 'id' => 'dst_shop1', 'name' => 'Shop', 'dial_code' => '55', 'number' => '21988887777', 'emails' => array() ),
				),
				'rules'               => array(
					array(
						'id'          => 'rl_shop',
						'name'        => 'Shop pages',
						'flows'       => array(),
						'when'        => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'ctx:page_path', 'op' => 'contains', 'right' => '/shop/' ) ) ),
						'destination' => 'dst_shop1',
					),
				),
			)
		);
		$id = $this->button_flow( array(), false );

		$shop = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context( '/shop/shoes/' ) ) )->get_data();
		$this->assertStringStartsWith( 'https://wa.me/5521988887777', $shop['url'] );

		$home = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context( '/about/' ) ) )->get_data();
		$this->assertStringStartsWith( 'https://wa.me/5511999998888', $home['url'] );
	}

	public function test_the_flow_s_own_destination_wins_over_the_site_default(): void {
		$id   = $this->button_flow( array( 'whatsapp' => array( 'default_destination' => 'dst_vip01' ) ) );
		$data = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context() ) )->get_data();
		$this->assertStringStartsWith( 'https://wa.me/5521988887777', $data['url'] );
	}

	public function test_nothing_is_counted_or_returned_when_there_is_no_number(): void {
		RoutingRepository::save(
			array(
				'default_destination' => 'dst_mail1',
				'destinations'        => array( array( 'id' => 'dst_mail1', 'name' => 'Mail only', 'dial_code' => '55', 'number' => '', 'emails' => array( 'a@example.test' ) ) ),
				'rules'               => array(),
			)
		);
		$id = FlowRepository::create( array( 'name' => 'Button', 'mode' => 'button' ), 1 );
		$this->assertGreaterThan( 0, $id );
		$flow = FlowRepository::get( $id );
		// It cannot be published (the report blocks it), so publish the stored config the way an older version of the routing would have allowed.
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->update( \Krivo\ChatFlow\Infrastructure\Db\Tables::flows(), array( 'status' => 'published', 'config_published' => wp_json_encode( $flow['draft'] ) ), array( 'id' => $id ) );
		Index::rebuild();

		$response = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context() ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'krivo_chatflow_unavailable', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'url', $response->get_data() );
		$this->assertSame( 0, StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id )['whatsapp_click'] );
	}

	public function test_the_button_endpoint_answers_only_for_published_whatsapp_buttons(): void {
		$form  = $this->flow();
		$link  = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => 'https://example.com/' ) ), false );
		$draft = FlowRepository::create( array( 'name' => 'Draft', 'mode' => 'button' ), 1 );

		foreach ( array( $form, $link, $draft, 99999 ) as $flow ) {
			$this->assertSame( 404, $this->call( 'POST', '/public/button', array( 'flow' => $flow, 'context' => array() ) )->get_status(), 'flow ' . $flow );
		}
	}

	public function test_the_button_endpoint_has_the_public_guards(): void {
		$id = $this->button_flow();

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->assertSame( 403, $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => array() ) )->get_status(), 'Another origin' );
		unset( $_SERVER['HTTP_ORIGIN'] );

		$this->assertSame( 400, $this->call( 'POST', '/public/button', array( 'context' => array() ) )->get_status(), 'The flow is required' );

		$last = 0;
		for ( $i = 0; $i < 25; $i++ ) {
			$last = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => array() ) )->get_status();
		}
		$this->assertSame( 429, $last, 'A robot cannot hammer the endpoint' );
	}

	public function test_garbage_context_is_sanitised_not_trusted(): void {
		$id   = $this->button_flow( array( 'whatsapp' => array( 'message' => '{page_url}|{page_title}|{utm_source}' ) ) );
		$data = $this->call(
			'POST',
			'/public/button',
			array(
				'flow'    => $id,
				'context' => array(
					'url'   => 'javascript:alert(1)',
					'title' => '<script>alert(1)</script>Title',
					'utm'   => array( 'source' => '<img src=x onerror=1>' ),
				),
			)
		)->get_data();
		$text = urldecode( substr( $data['url'], strpos( $data['url'], '?text=' ) + 6 ) );
		$this->assertStringNotContainsString( 'javascript', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function test_the_click_hook_fires_with_the_link_and_the_page_context(): void {
		$id    = $this->button_flow();
		$calls = array();
		add_action(
			'krivo_chatflow_button_clicked',
			static function ( $flow_id, $url, $tracking ) use ( &$calls ) {
				$calls[] = array( $flow_id, $url, $tracking['page_path'] );
			},
			10,
			3
		);
		$this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => $this->context( '/pricing/' ) ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( $id, $calls[0][0] );
		$this->assertStringStartsWith( 'https://wa.me/', $calls[0][1] );
		$this->assertSame( '/pricing/', $calls[0][2] );
	}

	public function test_developers_can_filter_the_message_and_the_link(): void {
		$id = $this->button_flow();
		add_filter(
			'krivo_chatflow_whatsapp_message',
			static function ( $message, $lead ) {
				return array() === $lead ? 'filtered message' : $message;
			},
			10,
			2
		);
		$data = $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => array() ) )->get_data();
		$this->assertSame( 'https://wa.me/5511999998888?text=filtered%20message', $data['url'] );

		add_filter( 'krivo_chatflow_whatsapp_url', '__return_empty_string' );
		$this->assertSame( 503, $this->call( 'POST', '/public/button', array( 'flow' => $id, 'context' => array() ) )->get_status(), 'A link that is not wa.me is refused' );
	}

	public function test_the_ajax_fallback_serves_the_button_too(): void {
		$id = $this->button_flow();
		$_POST['payload'] = wp_slash( wp_json_encode( array( 'flow' => $id, 'context' => $this->context() ) ) );
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
			AjaxFallback::button();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// wp_send_json() ends the request.
		}
		$out = json_decode( (string) ob_get_clean(), true );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertStringStartsWith( 'https://wa.me/5511999998888', $out['url'] );
	}

	// ------------------------------------------------------------------ plain links and the counters

	public function test_clicks_on_a_link_button_are_counted_but_a_whatsapp_button_is_counted_by_the_server_only(): void {
		$link = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => 'https://example.com/' ) ) );
		$wa   = $this->button_flow( array(), false );
		$form = $this->flow();

		foreach ( array( $link, $wa, $form ) as $flow ) {
			$this->assertSame( 204, $this->call( 'POST', '/public/events', array( 'flow' => $flow, 'events' => array( 'click' ) ) )->get_status() );
		}
		$day = Clock::site_day();
		$this->assertSame( 1, StatsRepository::totals( $day, $day, $link )['button_click'] );
		$this->assertSame( 0, StatsRepository::totals( $day, $day, $wa )['button_click'], 'The server counts WhatsApp taps; the browser cannot inflate them' );
		$this->assertSame( 0, StatsRepository::totals( $day, $day, $form )['button_click'] );
	}

	// ------------------------------------------------------------------ nothing to submit

	public function test_a_button_flow_accepts_no_submission_and_no_session(): void {
		$id = $this->button_flow();
		$this->assertSame( 404, $this->call( 'POST', '/public/session', array( 'flow' => $id ) )->get_status() );

		Clock::freeze( self::T0 );
		$result = Pipeline::handle( array( 'flow' => $id, 'token' => 'x', 'answers' => array( 'name' => 'Robot' ), 'hp' => '', 'context' => array() ) );
		$this->assertWPError( $result );
		$this->assertSame( 'krivo_chatflow_not_found', $result->get_error_code() );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \Krivo\ChatFlow\Infrastructure\Db\Tables::leads() ) );
	}

	// ------------------------------------------------------------------ the markup

	private function bubble_html( int $id ): string {
		return Renderer::bubble( Index::get( $id )['boot'] );
	}

	public function test_the_whatsapp_button_is_a_button_that_does_not_claim_to_open_a_dialog(): void {
		$html = $this->bubble_html( $this->button_flow() );
		$this->assertStringContainsString( 'kcf-mode-button', $html );
		$this->assertStringContainsString( '<strong class="kcf-tooltip-title">Hello!</strong>', $html );
		$this->assertStringContainsString( '<span class="kcf-tooltip-text">How can we help?</span>', $html );
		$this->assertStringContainsString( '<button type="button" class="kcf-tooltip-cta">Talk to us</button>', $html );
		$this->assertStringNotContainsString( 'aria-expanded', $html, 'Nothing expands' );
		$this->assertStringNotContainsString( 'aria-controls', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html, 'Screen readers are told what happens' );
		$this->assertStringNotContainsString( '5511999998888', $html );
		$this->assertStringNotContainsString( 'wa.me', $html );
	}

	public function test_a_link_button_is_a_real_link(): void {
		$id   = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => 'https://example.com/contact', 'new_tab' => true ) ) );
		$html = $this->bubble_html( $id );
		$this->assertMatchesRegularExpression( '#<a class="kcf-bubble[^"]*" href="https://example.com/contact" target="_blank" rel="noopener noreferrer" aria-label="[^"]*\(opens in a new tab\)">#', $html );
		$this->assertStringContainsString( '<a class="kcf-tooltip-cta" href="https://example.com/contact" target="_blank" rel="noopener noreferrer">Talk to us</a>', $html );
		$this->assertStringEndsWith( '</a></div>', $html );

		$tel = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => 'tel:+5511999998888' ) ), false );
		$this->assertStringContainsString( 'href="tel:+5511999998888"', $this->bubble_html( $tel ) );
		$this->assertStringNotContainsString( 'target=', $this->bubble_html( $tel ) );
	}

	public function test_the_welcome_text_is_escaped(): void {
		$id   = $this->button_flow( array( 'bubble' => array( 'tooltip' => array( 'title' => '"><img src=x onerror=1>', 'text' => '<script>alert(1)</script>', 'cta' => '<i>go</i>' ) ) ) );
		$html = $this->bubble_html( $id );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<i>', $html );
	}

	public function test_without_a_welcome_message_the_card_still_exists_to_carry_errors(): void {
		$id   = $this->button_flow( array( 'bubble' => array( 'tooltip' => array( 'enabled' => false ) ) ) );
		$html = $this->bubble_html( $id );
		$this->assertStringContainsString( 'class="kcf-tooltip"', $html );
		$this->assertStringContainsString( '<span class="kcf-tooltip-text"></span>', $html );
		$this->assertStringNotContainsString( 'kcf-tooltip-cta', $html );
		$this->assertStringContainsString( 'kcf-tooltip-close', $html, 'The visitor can always dismiss a message' );
	}

	public function test_a_form_chat_can_have_a_greeting_button_that_opens_the_chat(): void {
		$id   = $this->flow( null, array( 'bubble' => array( 'tooltip' => array( 'enabled' => true, 'title' => 'Hi', 'cta' => 'Open the form' ) ) ) );
		$html = $this->bubble_html( $id );
		$this->assertStringContainsString( '<button type="button" class="kcf-tooltip-cta">Open the form</button>', $html );
		$this->assertStringContainsString( 'aria-expanded="false" aria-controls="krivo-chatflow-panel"', $html );
		$this->assertStringNotContainsString( 'kcf-mode-button', $html );
	}

	// ------------------------------------------------------------------ page and shortcode

	public function test_only_a_button_chat_downloads_the_button_script(): void {
		$wa = $this->button_flow();
		Gate::reset();
		Assets::reset();
		$this->go_to( home_url( '/' ) );
		ob_start();
		Assets::print_footer();
		$footer = (string) ob_get_clean();
		$this->assertStringContainsString( 'assets\/frontend\/button.js', $footer );
		$this->assertStringContainsString( 'kcf-mode-button', $footer );

		FlowRepository::delete( $wa );
		Index::rebuild();
		$this->flow();
		Gate::reset();
		Assets::reset();
		$this->go_to( home_url( '/' ) );
		ob_start();
		Assets::print_footer();
		$this->assertStringNotContainsString( 'button.js', (string) ob_get_clean(), 'A form chat never pays for the button script' );
	}

	public function test_the_shortcode_prints_the_button_of_a_button_chat_and_the_panel_host_of_a_form_chat(): void {
		$wa = $this->button_flow();
		$this->go_to( home_url( '/' ) );
		$out = do_shortcode( '[krivo_chatflow id="' . $wa . '"]' );
		$this->assertStringContainsString( 'data-kcf-inline-button="' . $wa . '"', $out );
		$this->assertStringContainsString( '<button type="button" class="kcf-inline-cta">Talk to us</button>', $out );
		$this->assertStringNotContainsString( 'data-kcf-inline="', $out );

		$link = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => '/contact/', 'new_tab' => false ) ), false );
		$out  = do_shortcode( '[krivo_chatflow id="' . $link . '"]' );
		$this->assertStringContainsString( '<a class="kcf-inline-cta" href="' . esc_url( home_url( '/contact/' ) ) . '">Talk to us</a>', $out );

		$tab = $this->button_flow( array( 'button' => array( 'action' => 'link', 'url' => 'https://example.com/', 'new_tab' => true ) ), false );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer">Talk to us</a>', do_shortcode( '[krivo_chatflow id="' . $tab . '"]' ) );

		$form = $this->flow();
		$this->assertStringContainsString( 'data-kcf-inline="' . $form . '"', do_shortcode( '[krivo_chatflow id="' . $form . '"]' ) );
	}

	// ------------------------------------------------------------------ the editor

	public function test_the_dry_run_shows_the_exact_link_for_a_visitor_on_a_sample_page(): void {
		$this->routing();
		$config = Sanitizer::sanitize( array( 'mode' => 'button', 'whatsapp' => array( 'message' => 'Hello from {page_title}' ) ) );
		$report = DryRun::run( 0, $config );
		$this->assertTrue( $report['whatsapp']['ok'] );
		$this->assertSame( 'Hello from Sample page', $report['whatsapp']['message'] );
		$this->assertStringStartsWith( 'https://wa.me/5511999998888?text=', $report['whatsapp']['url'] );
		$this->assertSame( 'Sales', $report['destination']['name'] );
		$this->assertSame( array(), $report['emails'] );

		$link = DryRun::run( 0, Sanitizer::sanitize( array( 'mode' => 'button', 'button' => array( 'action' => 'link', 'url' => 'https://example.com/' ) ) ) );
		$this->assertNull( $link['whatsapp'] );
	}

	public function test_the_whatsapp_button_template_is_ready_to_publish(): void {
		$this->routing();
		$config = Sanitizer::sanitize( Templates::build( 'whatsapp_button' ) );
		$this->assertSame( 'button', $config['mode'] );
		$this->assertSame( 'whatsapp', $config['button']['action'] );
		$this->assertSame( 0, $config['bubble']['tooltip']['duration'], 'The welcome card stays until it is dismissed' );
		$this->assertFalse( $config['bubble']['tooltip']['once'] );
		$this->assertSame( array(), $config['automations'], 'No e-mail: there are no leads' );
		$this->assertSame( array(), Validator::report( $config )['errors'] );

		$catalogue = array_column( Templates::all(), 'mode', 'id' );
		$this->assertSame( 'button', $catalogue['whatsapp_button'] );
		$this->assertSame( 'form', $catalogue['contact'] );
	}

	public function test_a_published_button_flow_stays_out_of_the_lead_tables_and_the_events_validate(): void {
		$id = $this->button_flow();
		$this->assertSame( 204, $this->call( 'POST', '/public/events', array( 'flow' => $id, 'events' => array( 'view' ) ) )->get_status() );
		$this->assertTrue( PublicApi::events( $id, array( 'view', 'rm -rf' ) ) );
		$this->assertSame( 2, StatsRepository::totals( Clock::site_day(), Clock::site_day(), $id )['view'] );
	}
}
