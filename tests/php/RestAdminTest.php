<?php
/**
 * Tests for the administrative REST API: permissions on every route, then each module.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Core\Leads\CsvExporter;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Mail\EmailSettings;

/**
 * @group rest
 */
class RestAdminTest extends Krivo_TestCase {

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
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		Capabilities::grant_default();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		unset( $_SERVER['HTTP_ORIGIN'] );
		add_filter( 'pre_schedule_event', '__return_true' );
		reset_phpmailer_instance();
		$this->as_admin();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function as_admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Logs in a user that has exactly the given plugin capabilities.
	 *
	 * @param string[] $caps Capabilities.
	 * @return int
	 */
	private function as_user_with( array $caps ): int {
		$id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new WP_User( $id );
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $id );
		return $id;
	}

	private function call( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/krivo-chatflow/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
	}

	// ------------------------------------------------------------------ permissions

	/**
	 * Every administrative route with a concrete URL for each method.
	 *
	 * @return array<int,array{0:string,1:string}> [method, route]
	 */
	private function admin_routes(): array {
		global $wp_rest_server;
		$out = array();
		foreach ( $wp_rest_server->get_routes( 'krivo-chatflow/v1' ) as $route => $handlers ) {
			if ( false !== strpos( $route, '/public/' ) || '/krivo-chatflow/v1' === $route ) {
				continue;
			}
			$concrete = preg_replace( '/\(\?P<cid>[^)]*\)\)?/', 'con_abcd1234', $route );
			$concrete = preg_replace( '/\(\?P<id>[^)]*\)/', '1', (string) $concrete );
			$concrete = substr( (string) $concrete, strlen( '/krivo-chatflow/v1' ) );
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					$out[] = array( $method, $concrete, $this->sample_args( (array) ( $handler['args'] ?? array() ) ) );
				}
			}
		}
		return $out;
	}

	/**
	 * A valid value for every required argument. WordPress validates arguments BEFORE it calls the
	 * permission callback, so without them a protected route would answer 400 instead of 401/403.
	 *
	 * @param array $args Route args schema.
	 * @return array
	 */
	private function sample_args( array $args ): array {
		$out = array();
		foreach ( $args as $name => $schema ) {
			if ( empty( $schema['required'] ) ) {
				continue;
			}
			if ( ! empty( $schema['enum'] ) ) {
				$out[ $name ] = $schema['enum'][0];
			} elseif ( ! empty( $schema['pattern'] ) && false !== strpos( $schema['pattern'], 'con_' ) ) {
				$out[ $name ] = 'con_abcd1234';
			} else {
				$type         = is_array( $schema['type'] ?? 'string' ) ? 'string' : ( $schema['type'] ?? 'string' );
				$out[ $name ] = 'integer' === $type ? 1 : ( 'string' === $type ? 'x' : array() );
			}
		}
		return $out;
	}

	public function test_there_are_admin_routes_to_protect(): void {
		$routes = array_map(
			static function ( $route ) {
				return array( $route[0], $route[1] );
			},
			$this->admin_routes()
		);
		$this->assertGreaterThan( 30, count( $routes ) );
		$this->assertContains( array( 'DELETE', '/leads/1' ), $routes );
		$this->assertContains( array( 'PUT', '/flows/1' ), $routes );
		$this->assertContains( array( 'POST', '/integrations/test' ), $routes );
		$this->assertContains( array( 'PUT', '/integrations/connections/con_abcd1234' ), $routes );
	}

	public function test_every_admin_route_refuses_anonymous_visitors_and_ordinary_users(): void {
		foreach ( $this->admin_routes() as list( $method, $route, $args ) ) {
			wp_set_current_user( 0 );
			$anonymous = $this->call( $method, $route, $args );
			$this->assertSame( 401, $anonymous->get_status(), "$method $route (anonymous)" );

			$this->as_user_with( array() );
			$subscriber = $this->call( $method, $route, $args );
			$this->assertSame( 403, $subscriber->get_status(), "$method $route (no plugin capability)" );
		}
	}

	public function test_every_admin_route_has_a_real_permission_callback(): void {
		global $wp_rest_server;
		foreach ( $wp_rest_server->get_routes( 'krivo-chatflow/v1' ) as $route => $handlers ) {
			if ( '/krivo-chatflow/v1' === $route ) {
				continue; // The namespace index is registered by WordPress itself.
			}
			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
			}
		}
	}

	public function test_each_capability_opens_only_its_own_area(): void {
		$matrix = array(
			Capabilities::EDIT_FLOWS          => array( array( 'GET', '/flows' ), array( 'GET', '/meta' ) ),
			Capabilities::VIEW_LEADS          => array( array( 'GET', '/leads' ) ),
			Capabilities::MANAGE_ROUTING      => array( array( 'GET', '/routing' ) ),
			Capabilities::MANAGE_SETTINGS     => array( array( 'GET', '/settings' ) ),
			Capabilities::MANAGE_EMAIL        => array( array( 'GET', '/email' ) ),
			Capabilities::VIEW_INTEGRATIONS   => array( array( 'GET', '/integrations/connections' ), array( 'GET', '/automations/runs' ) ),
			Capabilities::VIEW_ANALYTICS      => array( array( 'GET', '/analytics/summary' ) ),
			Capabilities::CREATE_FLOWS        => array( array( 'GET', '/templates' ) ),
		);
		$all = array_merge( ...array_values( $matrix ) );
		foreach ( $matrix as $cap => $allowed ) {
			$this->as_user_with( array( $cap ) );
			foreach ( $all as list( $method, $route ) ) {
				$status = $this->call( $method, $route )->get_status();
				if ( in_array( array( $method, $route ), $allowed, true ) ) {
					$this->assertSame( 200, $status, "$cap should open $method $route" );
				} else {
					$this->assertSame( 403, $status, "$cap must not open $method $route" );
				}
			}
		}
	}

	public function test_a_leads_only_user_cannot_change_or_delete_or_export_anything(): void {
		$lead = LeadRepository::insert( array( 'flow_id' => 1, 'name' => 'A', 'email' => 'a@example.test' ) );
		$this->as_user_with( array( Capabilities::VIEW_LEADS ) );
		$this->assertSame( 200, $this->call( 'GET', '/leads/' . $lead )->get_status() );
		$this->assertSame( 403, $this->call( 'PATCH', '/leads/' . $lead, array( 'status' => 'converted' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/leads/' . $lead )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => array( $lead ) ) )->get_status() );
		$this->assertSame( 'new', LeadRepository::get( $lead )['status'] );
		$this->assertNotNull( LeadRepository::get( $lead ) );
	}

	public function test_bulk_checks_the_capability_of_the_action_it_performs(): void {
		$a = LeadRepository::insert( array( 'flow_id' => 1, 'name' => 'A', 'email' => 'a@example.test' ) );
		$this->as_user_with( array( Capabilities::EDIT_LEADS ) );
		$this->assertSame( 403, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => array( $a ) ) )->get_status(), 'Editing is not deleting' );
		$this->assertSame( 200, $this->call( 'POST', '/leads/bulk', array( 'action' => 'status', 'ids' => array( $a ), 'status' => 'contacted' ) )->get_status() );

		$this->as_user_with( array( Capabilities::DELETE_LEADS ) );
		$this->assertSame( 403, $this->call( 'POST', '/leads/bulk', array( 'action' => 'status', 'ids' => array( $a ), 'status' => 'lost' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => array( $a ) ) )->get_status() );
	}

	// ------------------------------------------------------------------ flows

	private function create_flow( array $body = array() ): array {
		$response = $this->call( 'POST', '/flows', $body );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	public function test_create_from_a_template_returns_a_draft_with_report_and_variables(): void {
		$flow = $this->create_flow( array( 'template' => 'quote', 'name' => 'Quote form' ) );
		$this->assertSame( 'draft', $flow['status'] );
		$this->assertSame( 'Quote form', $flow['title'] );
		$this->assertSame( 'Quote form', $flow['config']['name'] );
		$this->assertNotEmpty( $flow['config']['steps'][0]['fields'] );
		$this->assertArrayHasKey( 'errors', $flow['report'] );
		$this->assertContains( 'name', array_column( $flow['variables'], 'name' ) );
		$this->assertContains( 'all_fields', array_column( $flow['variables'], 'name' ) );
		$this->assertTrue( $flow['has_unpublished_changes'] );
		$this->assertNotEmpty( $flow['draft_hash'] );
	}

	public function test_create_blank_and_reject_unknown_template(): void {
		$blank = $this->create_flow( array( 'name' => 'Blank' ) );
		$this->assertSame( array(), $blank['config']['steps'][0]['fields'] );
		$this->assertSame( 400, $this->call( 'POST', '/flows', array( 'template' => 'nope' ) )->get_status() );
	}

	public function test_template_without_whatsapp(): void {
		$flow = $this->create_flow( array( 'template' => 'contact', 'whatsapp' => false ) );
		$this->assertNotContains( 'open_whatsapp', array_column( $flow['config']['automations'], 'type' ) );
	}

	public function test_listing_shows_lead_counts_without_spam(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		LeadRepository::insert( array( 'flow_id' => $flow['id'], 'name' => 'A' ) );
		LeadRepository::insert( array( 'flow_id' => $flow['id'], 'name' => 'B', 'status' => 'spam' ) );
		$items = $this->call( 'GET', '/flows' )->get_data()['items'];
		$this->assertSame( 1, $items[0]['leads'] );
		$this->assertSame( 'draft', $items[0]['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $items[0]['updated_at'] );
	}

	public function test_saving_a_draft_returns_the_new_hash_and_detects_conflicts(): void {
		$flow   = $this->create_flow( array( 'template' => 'contact' ) );
		$config = $flow['config'];
		$config['name'] = 'Renamed';
		$saved = $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $config, 'base_hash' => $flow['draft_hash'] ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Renamed', $saved->get_data()['title'] );
		$this->assertNotSame( $flow['draft_hash'], $saved->get_data()['draft_hash'] );

		$stale = $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $config, 'base_hash' => $flow['draft_hash'] ) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'krivo_chatflow_conflict', $stale->get_data()['code'] );
	}

	public function test_saving_sanitises_what_the_editor_sends(): void {
		$flow   = $this->create_flow( array( 'template' => 'contact' ) );
		$config = $flow['config'];
		$config['name']                     = '<script>alert(1)</script>Safe';
		$config['bubble']['colors']['bg']   = 'url(javascript:alert(1))';
		$config['evil']                     = 'x';
		$saved = $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $config ) )->get_data();
		$this->assertSame( 'Safe', $saved['config']['name'] );
		$this->assertMatchesRegularExpression( '/^#[0-9A-Fa-f]{6}$/', $saved['config']['bubble']['colors']['bg'] );
		$this->assertArrayNotHasKey( 'evil', $saved['config'] );
	}

	public function test_changing_automations_needs_the_integrations_capability_too(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$this->as_user_with( array( Capabilities::EDIT_FLOWS ) );

		$edit = $flow['config'];
		$edit['name'] = 'Only the name';
		$this->assertSame( 200, $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $edit ) )->get_status(), 'Other blocks need only edit_flows' );

		$exfiltrate = $edit;
		foreach ( $exfiltrate['automations'] as $i => $automation ) {
			if ( 'send_email' === $automation['type'] ) {
				$exfiltrate['automations'][ $i ]['config']['to'] = array( 'custom:attacker@example.test' );
			}
		}
		$denied = $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $exfiltrate ) );
		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( 'krivo_chatflow_forbidden_section', $denied->get_data()['code'] );
		$stored = FlowRepository::get( $flow['id'] )['draft']['automations'];
		$this->assertStringNotContainsString( 'attacker@example.test', wp_json_encode( $stored ) );

		$removal = $edit;
		$removal['automations'] = array();
		$this->assertSame( 403, $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $removal ) )->get_status(), 'Removing automations is also a change' );

		$this->as_user_with( array( Capabilities::EDIT_FLOWS, Capabilities::MANAGE_INTEGRATIONS ) );
		$this->assertSame( 200, $this->call( 'PUT', '/flows/' . $flow['id'], array( 'config' => $exfiltrate ) )->get_status() );
	}

	public function test_publish_reports_problems_and_then_goes_live(): void {
		$flow = $this->create_flow( array( 'name' => 'Empty' ) );
		$bad  = $this->call( 'POST', '/flows/' . $flow['id'] . '/publish' );
		$this->assertSame( 422, $bad->get_status() );
		$this->assertSame( 'krivo_chatflow_validation', $bad->get_data()['code'] );
		$this->assertContains( 'no_fields', array_column( $bad->get_data()['data']['report']['errors'], 'code' ) );
		$this->assertNull( FlowRepository::published( $flow['id'] ) );

		RoutingRepository::save( array( 'destinations' => array( array( 'id' => 'dst_main1', 'name' => 'Main', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'a@example.test' ) ) ), 'default_destination' => 'dst_main1' ) );
		$good = $this->create_flow( array( 'template' => 'contact' ) );
		$ok   = $this->call( 'POST', '/flows/' . $good['id'] . '/publish' );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'published', $ok->get_data()['status'] );
		$this->assertSame( 1, $ok->get_data()['revision'] );
		$this->assertFalse( $ok->get_data()['has_unpublished_changes'] );
		$this->assertNotNull( Index::get( $good['id'] ), 'The published flow is in the page index' );
	}

	public function test_pause_duplicate_and_delete(): void {
		RoutingRepository::save( array( 'destinations' => array( array( 'id' => 'dst_main1', 'name' => 'Main', 'dial_code' => '55', 'number' => '11999998888' ) ), 'default_destination' => 'dst_main1' ) );
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$id   = $flow['id'];

		$this->assertSame( 400, $this->call( 'POST', "/flows/$id/pause" )->get_status(), 'A draft cannot be paused' );
		$this->call( 'POST', "/flows/$id/publish" );
		$this->assertSame( 'paused', $this->call( 'POST', "/flows/$id/pause" )->get_data()['status'] );
		$this->assertNull( Index::get( $id ) );

		$copy = $this->call( 'POST', "/flows/$id/duplicate" );
		$this->assertSame( 201, $copy->get_status() );
		$this->assertStringContainsString( '(copy)', $copy->get_data()['title'] );
		$this->assertSame( 'draft', $copy->get_data()['status'] );
		$this->assertNotSame( $id, $copy->get_data()['id'] );

		$this->assertSame( 200, $this->call( 'DELETE', "/flows/$id" )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', "/flows/$id" )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', "/flows/$id" )->get_status() );
	}

	public function test_delete_needs_its_own_capability(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$this->as_user_with( array( Capabilities::EDIT_FLOWS, Capabilities::CREATE_FLOWS ) );
		$this->assertSame( 403, $this->call( 'DELETE', '/flows/' . $flow['id'] )->get_status() );
		$this->assertNotNull( FlowRepository::get( $flow['id'] ) );
	}

	public function test_preview_link_is_a_nonce_url_for_the_draft(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$url  = $this->call( 'POST', '/flows/' . $flow['id'] . '/preview-link' )->get_data()['url'];
		$parts = wp_parse_url( $url );
		parse_str( $parts['query'], $query );
		$this->assertSame( (string) $flow['id'], $query['krivo_chatflow_preview'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'krivo_chatflow_preview_' . $flow['id'] ) );
		$this->assertSame( 404, $this->call( 'POST', '/flows/999/preview-link' )->get_status() );
	}

	public function test_dry_run_shows_the_real_messages_without_sending_or_saving_anything(): void {
		RoutingRepository::save( array( 'destinations' => array( array( 'id' => 'dst_main1', 'name' => 'Main', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'team@example.test' ) ) ), 'default_destination' => 'dst_main1' ) );
		$flow = $this->create_flow( array( 'template' => 'quote' ) );
		$run  = $this->call( 'POST', '/flows/' . $flow['id'] . '/dry-run' )->get_data();

		$this->assertSame( 'Main', $run['destination']['name'] );
		$this->assertTrue( $run['whatsapp']['ok'] );
		$this->assertStringStartsWith( 'https://wa.me/5511999998888?text=', $run['whatsapp']['url'] );
		$this->assertStringContainsString( 'Maria Teste', $run['whatsapp']['message'] );
		$this->assertCount( 1, $run['emails'] );
		$this->assertSame( array( get_option( 'admin_email' ) ), $run['emails'][0]['to'] );
		$this->assertStringContainsString( 'Maria Teste', $run['emails'][0]['subject'] );
		$this->assertStringContainsString( 'Company: Sample', $run['emails'][0]['text'] );
		$this->assertArrayHasKey( 'name', $run['variables'] );
		$this->assertStringContainsString( 'Maria Teste', $run['message'] );

		$this->assertSame( 0, LeadRepository::query()['total'], 'Nothing was stored' );
		$this->assertSame( 0, RunRepository::query()['total'], 'Nothing was queued' );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'Nothing was sent' );
	}

	public function test_dry_run_uses_unsaved_changes_when_the_editor_sends_them(): void {
		RoutingRepository::save( array( 'destinations' => array( array( 'id' => 'dst_main1', 'name' => 'Main', 'dial_code' => '55', 'number' => '11999998888' ) ), 'default_destination' => 'dst_main1' ) );
		$flow   = $this->create_flow( array( 'template' => 'contact' ) );
		$config = $flow['config'];
		$config['whatsapp']['message'] = 'Custom text for {name}';
		foreach ( $config['automations'] as $i => $automation ) {
			if ( 'open_whatsapp' === $automation['type'] ) {
				$config['automations'][ $i ]['config']['message'] = '';
			}
		}
		$run = $this->call( 'POST', '/flows/' . $flow['id'] . '/dry-run', array( 'config' => $config ) )->get_data();
		$this->assertSame( 'Custom text for Maria Teste', $run['whatsapp']['message'] );
		$this->assertStringNotContainsString( 'Custom text', FlowRepository::get( $flow['id'] )['draft']['whatsapp']['message'], 'The preview did not save' );
	}

	public function test_dry_run_explains_a_missing_whatsapp_destination(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$run  = $this->call( 'POST', '/flows/' . $flow['id'] . '/dry-run' )->get_data();
		$this->assertFalse( $run['whatsapp']['ok'] );
		$this->assertNotSame( '', $run['whatsapp']['reason'] );
		$this->assertNull( $run['destination'] );
	}

	public function test_live_preview_returns_the_manifest_of_the_draft(): void {
		$flow   = $this->create_flow( array( 'template' => 'contact' ) );
		$config = $flow['config'];
		$config['bubble']['colors']['bg'] = '#112233';
		$config['design']['title']        = 'Unsaved title';
		$manifest = $this->call( 'POST', '/flows/' . $flow['id'] . '/preview', array( 'config' => $config ) )->get_data();
		$this->assertSame( '#112233', $manifest['boot']['bubble']['colors']['bg'] );
		$this->assertSame( 'Unsaved title', $manifest['boot']['design']['title'] );
		$this->assertArrayHasKey( 'steps', $manifest['flow'] );
		$this->assertNotSame( '#112233', FlowRepository::get( $flow['id'] )['draft']['bubble']['colors']['bg'] );
		$this->assertStringNotContainsString( 'automations', wp_json_encode( $manifest ) );
	}

	public function test_preview_of_an_unsaved_configuration_needs_no_stored_chat_and_escapes_what_it_renders(): void {
		$config          = \Krivo\ChatFlow\Core\Flow\Defaults::blank( 'Wizard' );
		$config['bubble']['label']        = '<script>alert(1)</script>';
		$config['bubble']['colors']['bg'] = '#0B6B3A';
		$config['design']['title']        = 'Hello wizard';
		$response = $this->call( 'POST', '/preview', array( 'config' => $config ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Hello wizard', $data['boot']['design']['title'] );
		$this->assertSame( '#0B6B3A', $data['boot']['bubble']['colors']['bg'] );
		$this->assertStringContainsString( 'krivo-chatflow-root', $data['bubble_html'] );
		$this->assertStringNotContainsString( '<script', $data['bubble_html'], 'the bubble markup is escaped' );
		$this->assertSame( array(), FlowRepository::all(), 'nothing was stored' );
		$this->assertStringNotContainsString( 'automations', wp_json_encode( $data ) );
	}

	public function test_preview_returns_markup_for_a_stored_chat_too(): void {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$data = $this->call( 'POST', '/flows/' . $flow['id'] . '/preview', array( 'config' => $flow['config'] ) )->get_data();
		$this->assertStringContainsString( 'class="kcf-bubble ', $data['bubble_html'] );
	}

	public function test_meta_gives_the_editor_destinations_without_numbers_and_connections_only_to_people_who_may_see_them(): void {
		RoutingRepository::save(
			array(
				'default_destination' => 'dst_sales',
				'destinations'        => array( array( 'id' => 'dst_sales', 'name' => 'Sales', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'private@example.test' ) ) ),
			)
		);
		ConnectionRepository::save( array( 'name' => 'Hook', 'url' => StubServer::url( '/ok' ), 'allow_http' => true, 'allow_private_hosts' => true ), get_current_user_id() );

		$meta = $this->call( 'GET', '/meta' )->get_data();
		$this->assertSame( array( array( 'id' => 'dst_sales', 'name' => 'Sales', 'whatsapp' => true, 'emails' => 1 ) ), $meta['destinations'] );
		$this->assertStringNotContainsString( '11999998888', wp_json_encode( $meta ) );
		$this->assertStringNotContainsString( 'private@example.test', wp_json_encode( $meta ) );
		$this->assertSame( array( 'Hook' ), array_column( $meta['connections'], 'name' ) );
		$this->assertArrayNotHasKey( 'url', $meta['connections'][0] );
		$this->assertArrayHasKey( 'chat', $meta['icons'] );
		$this->assertStringStartsWith( '<svg', $meta['icons']['chat'] );
		$this->assertSame( get_option( 'admin_email' ), $meta['admin_email'] );

		$this->as_user_with( array( Capabilities::EDIT_FLOWS ) );
		$this->assertSame( array(), $this->call( 'GET', '/meta' )->get_data()['connections'], 'editing chats does not reveal which services are connected' );
	}

	public function test_templates_and_meta_describe_what_the_editor_needs(): void {
		$templates = $this->call( 'GET', '/templates' )->get_data()['items'];
		$this->assertCount( count( Templates::all() ), $templates );

		$meta = $this->call( 'GET', '/meta' )->get_data();
		$this->assertContains( 'whatsapp', array_column( $meta['field_types'], 'type' ) );
		$this->assertContains( 'eq', $meta['operators'] );
		$this->assertContains( 'send_webhook', array_column( $meta['automations']['actions'], 'type' ) );
		$this->assertContains( 'form.submitted', array_column( $meta['automations']['triggers'], 'key' ) );
		$this->assertSame( LeadRepository::STATUSES, $meta['statuses'] );
		$this->assertSame( 'form', $meta['defaults']['mode'] );
		$this->assertSame( KRIVO_CHATFLOW_CREDIT_URL, $meta['credit_url'] );
		$this->assertSame( 10, $meta['limits']['steps'] );
	}

	// ------------------------------------------------------------------ leads

	private function seed_leads(): array {
		$flow = $this->create_flow( array( 'template' => 'contact' ) );
		$ids  = array();
		foreach ( array( 'Ana Souza', 'Bruno Lima', 'Carla Dias' ) as $i => $name ) {
			$ids[] = LeadRepository::insert(
				array(
					'flow_id'    => $flow['id'],
					'name'       => $name,
					'email'      => strtolower( strtok( $name, ' ' ) ) . '@example.test',
					'phone'      => '1199999000' . $i,
					'utm_source' => 'google',
					'answers'    => array( 'name' => array( 'label' => 'Name', 'type' => 'name', 'value' => $name, 'display' => $name ) ),
				)
			);
		}
		return array( $flow['id'], $ids );
	}

	public function test_lead_list_filters_sorts_and_pages(): void {
		list( $flow, $ids ) = $this->seed_leads();
		LeadService_status( $ids[0], 'contacted' );
		$all = $this->call( 'GET', '/leads' )->get_data();
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( 'contacted', LeadRepository::get( $ids[0] )['status'] );
		$this->assertSame( 1, $all['counts']['contacted'] );
		$this->assertSame( $flow, $all['flows'][0]['id'] );
		$this->assertSame( 'google', $all['items'][0]['utm_source'] );

		$this->assertSame( 1, $this->call( 'GET', '/leads', array( 'search' => 'bruno' ) )->get_data()['total'] );
		$this->assertSame( 1, $this->call( 'GET', '/leads', array( 'status' => 'contacted' ) )->get_data()['total'] );
		$this->assertSame( 3, $this->call( 'GET', '/leads', array( 'flow' => $flow ) )->get_data()['total'] );
		$this->assertSame( 0, $this->call( 'GET', '/leads', array( 'flow' => $flow + 1 ) )->get_data()['total'] );
		$sorted = $this->call( 'GET', '/leads', array( 'orderby' => 'name', 'order' => 'asc' ) )->get_data()['items'];
		$this->assertSame( array( 'Ana Souza', 'Bruno Lima', 'Carla Dias' ), array_column( $sorted, 'name' ) );
		$page = $this->call( 'GET', '/leads', array( 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'asc' ) )->get_data();
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 3, $page['total'] );
	}

	public function test_lead_list_rejects_bad_arguments(): void {
		$this->assertSame( 400, $this->call( 'GET', '/leads', array( 'status' => 'hacked' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/leads', array( 'orderby' => 'id; DROP TABLE x' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/leads', array( 'per_page' => 1000 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/leads', array( 'from' => '01/02/2026' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/leads', array( 'page' => 0 ) )->get_status() );
	}

	public function test_lead_detail_has_answers_tracking_consent_and_the_automation_log(): void {
		list( $flow, $ids ) = $this->seed_leads();
		$run = RunRepository::create( $flow, $ids[0], 'form.submitted', 'a_notify', 'send_email' );
		RunRepository::claim( $run );
		RunRepository::finish( $run, RunRepository::SUCCESS );

		$detail = $this->call( 'GET', '/leads/' . $ids[0] )->get_data();
		$this->assertSame( 'Ana Souza', $detail['name'] );
		$this->assertSame( 'Name', $detail['answers'][0]['label'] );
		$this->assertSame( 'google', $detail['utm']['source'] );
		$this->assertSame( 'send_email', $detail['runs'][0]['action'] );
		$this->assertSame( 'success', $detail['runs'][0]['status'] );
		$this->assertSame( 404, $this->call( 'GET', '/leads/99999' )->get_status() );

		$this->as_user_with( array( Capabilities::VIEW_LEADS ) );
		$this->assertSame( array(), $this->call( 'GET', '/leads/' . $ids[0] )->get_data()['runs'], 'Automation logs need their own capability' );
	}

	public function test_status_change_delete_and_bulk(): void {
		list( , $ids ) = $this->seed_leads();
		$this->assertSame( 'converted', $this->call( 'PATCH', '/leads/' . $ids[0], array( 'status' => 'converted' ) )->get_data()['status'] );
		$this->assertSame( 400, $this->call( 'PATCH', '/leads/' . $ids[0], array( 'status' => 'bogus' ) )->get_status() );
		$this->assertSame( 404, $this->call( 'PATCH', '/leads/99999', array( 'status' => 'lost' ) )->get_status() );

		$this->assertSame( 2, $this->call( 'POST', '/leads/bulk', array( 'action' => 'status', 'ids' => array( $ids[1], $ids[2], 99999 ), 'status' => 'lost' ) )->get_data()['affected'] );
		$this->assertSame( 400, $this->call( 'POST', '/leads/bulk', array( 'action' => 'status', 'ids' => array( $ids[1] ) ) )->get_status(), 'A status is required' );
		$this->assertSame( 400, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => array() ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => range( 1, 201 ) ) )->get_status() );

		$this->assertSame( 200, $this->call( 'DELETE', '/leads/' . $ids[0] )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/leads/' . $ids[0] )->get_status() );
		$this->assertSame( 2, $this->call( 'POST', '/leads/bulk', array( 'action' => 'delete', 'ids' => array( $ids[1], $ids[2] ) ) )->get_data()['affected'] );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	// ------------------------------------------------------------------ CSV export

	private function csv( array $filters = array(), string $delimiter = ',' ): array {
		$stream = fopen( 'php://memory', 'w+' );
		CsvExporter::write( $stream, $filters, $delimiter );
		rewind( $stream );
		$raw = (string) stream_get_contents( $stream );
		fclose( $stream );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $raw, 'UTF-8 BOM for Excel' );
		$rows = array();
		$h    = fopen( 'php://memory', 'w+' );
		fwrite( $h, substr( $raw, 3 ) );
		rewind( $h );
		while ( false !== ( $row = fgetcsv( $h, 0, $delimiter, '"', '\\' ) ) ) {
			$rows[] = $row;
		}
		fclose( $h );
		return $rows;
	}

	public function test_csv_export_has_a_header_one_row_per_lead_and_the_answers(): void {
		list( $flow ) = $this->seed_leads();
		$rows = $this->csv();
		$this->assertCount( 4, $rows );
		$this->assertSame( 'ID', $rows[0][0] );
		$this->assertSame( 'Answers', end( $rows[0] ) );
		$this->assertSame( 'Carla Dias', $rows[1][3], 'Newest first' );
		$this->assertStringContainsString( 'Name: Carla Dias', end( $rows[1] ) );

		$per_flow = $this->csv( array( 'flow_id' => $flow ) );
		$this->assertSame( 'Name', end( $per_flow[0] ), 'With one flow selected each question is its own column' );
		$this->assertSame( 'Carla Dias', end( $per_flow[1] ) );
	}

	public function test_csv_export_neutralises_spreadsheet_formulas(): void {
		foreach ( array( '=HYPERLINK("http://evil","x")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tTAB", 'Normal' ) as $i => $name ) {
			LeadRepository::insert( array( 'flow_id' => 1, 'name' => $name, 'email' => "x{$i}@example.test", 'phone' => '+5511999990000' ) );
		}
		$names = array_column( array_slice( $this->csv(), 1 ), 3 );
		sort( $names );
		foreach ( $names as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/^[=+\-@\t\r]/', $name, 'A cell must not start with a formula character' );
		}
		$this->assertContains( "'=HYPERLINK(\"http://evil\",\"x\")", $names );
		$this->assertContains( 'Normal', $names );
		$phones = array_column( array_slice( $this->csv(), 1 ), 5 );
		$this->assertSame( '+5511999990000', $phones[0], 'A plain phone number is left as it is' );
		$this->assertSame( "'=1+1", CsvExporter::cell( '=1+1' ) );
		$this->assertSame( '-12.5', CsvExporter::cell( '-12.5' ) );
		$this->assertSame( "'-1+2", CsvExporter::cell( '-1+2' ) );
		$this->assertSame( '', CsvExporter::cell( array( 'x' ) ) );
	}

	public function test_csv_export_supports_semicolons_filters_and_streams_in_batches(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			LeadRepository::insert( array( 'flow_id' => 1, 'name' => "N{$i}, with comma", 'email' => "n{$i}@example.test", 'status' => 0 === $i % 2 ? 'new' : 'lost' ) );
		}
		$rows = $this->csv( array( 'status' => 'lost' ), ';' );
		$this->assertCount( 4, $rows, 'Header + the 3 lost leads' );
		$this->assertStringContainsString( 'with comma', $rows[1][3], 'A comma inside a value stays inside it' );
		$this->assertCount( 8, $this->csv() );
	}

	public function test_csv_download_needs_the_capability_and_a_valid_nonce(): void {
		$this->as_user_with( array( Capabilities::VIEW_LEADS ) );
		try {
			CsvExporter::handle();
			$this->fail( 'Expected the request to be refused' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}

		$this->as_user_with( array( Capabilities::EXPORT_LEADS ) );
		unset( $_REQUEST['_wpnonce'] );
		try {
			CsvExporter::handle();
			$this->fail( 'Expected the missing nonce to be refused' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
		$this->assertStringContainsString( '_wpnonce=', CsvExporter::url() );
		$this->assertStringContainsString( 'action=krivo_chatflow_export_leads', CsvExporter::url() );
	}

	// ------------------------------------------------------------------ routing

	public function test_routing_screen_data_and_save(): void {
		$this->seed_leads();
		$show = $this->call( 'GET', '/routing' )->get_data();
		$this->assertSame( 0, $show['routing']['revision'] );
		$this->assertContains( 'name', array_column( $show['fields'], 'key' ) );
		$this->assertContains( 'utm_source', array_column( $show['context'], 'key' ) );
		$this->assertNotEmpty( $show['flows'] );

		$body = array(
			'destinations'        => array( array( 'id' => 'dst_sales', 'name' => 'Sales', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'sales@example.test' ) ) ),
			'default_destination' => 'dst_sales',
			'rules'               => array(),
			'base_revision'       => 0,
		);
		$saved = $this->call( 'PUT', '/routing', $body );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 1, $saved->get_data()['routing']['revision'] );
		$this->assertSame( 409, $this->call( 'PUT', '/routing', $body )->get_status(), 'The same stale revision again' );

		$body['destinations'][0]['number'] = '12';
		$body['base_revision']             = 1;
		$invalid = $this->call( 'PUT', '/routing', $body );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertStringContainsString( 'Sales', $invalid->get_data()['message'] );
	}

	// ------------------------------------------------------------------ settings and e-mail

	public function test_settings_update_and_the_signature_stays_opt_in(): void {
		$show = $this->call( 'GET', '/settings' )->get_data();
		$this->assertFalse( $show['settings']['branding']['signature_enabled'], 'Off by default' );
		$this->assertSame( KRIVO_CHATFLOW_CREDIT_URL, $show['credit']['url'] );

		$updated = $this->call( 'PUT', '/settings', array( 'settings' => array( 'privacy' => array( 'retention_leads_days' => 90 ), 'events' => array( 'datalayer' => true ) ) ) )->get_data();
		$this->assertSame( 90, $updated['settings']['privacy']['retention_leads_days'] );
		$this->assertTrue( $updated['settings']['events']['datalayer'] );
		$this->assertFalse( $updated['settings']['branding']['signature_enabled'], 'Untouched settings keep their value' );

		$forged = $this->call( 'PUT', '/settings', array( 'settings' => array( 'branding' => array( 'signature_enabled' => true, 'authorized_by' => 999, 'authorized_at' => '2000-01-01' ) ) ) )->get_data();
		$this->assertTrue( $forged['settings']['branding']['signature_enabled'] );
		$this->assertSame( get_current_user_id(), $forged['settings']['branding']['authorized_by'], 'The consent record cannot be written by the client' );
		$this->assertNotSame( '2000-01-01', $forged['settings']['branding']['authorized_at'] );
		$this->assertFalse( $forged['settings']['branding']['signature_link'], 'The link is a second, separate consent' );
	}

	public function test_changing_the_signature_updates_the_published_pages_at_once(): void {
		$this->flow();
		$id = array_key_first( Index::all() );
		$this->assertNull( Index::get( $id )['boot']['signature'] );
		$this->call( 'PUT', '/settings', array( 'settings' => array( 'branding' => array( 'signature_enabled' => true, 'signature_link' => true ) ) ) );
		$signature = Index::get( $id )['boot']['signature'];
		$this->assertSame( 'Krivo Works', $signature['name'] );
		$this->assertSame( KRIVO_CHATFLOW_CREDIT_URL, $signature['link'] );
		$this->call( 'PUT', '/settings', array( 'settings' => array( 'branding' => array( 'signature_enabled' => false ) ) ) );
		$this->assertNull( Index::get( $id )['boot']['signature'] );
	}

	public function test_settings_values_are_clamped_to_their_schema(): void {
		$s = $this->call( 'PUT', '/settings', array( 'settings' => array( 'antispam' => array( 'rate_limit_max' => 99999, 'min_seconds' => -5, 'proxy_header' => 'evil' ), 'privacy' => array( 'retention_logs_days' => 0 ) ) ) )->get_data()['settings'];
		$this->assertSame( 1000, $s['antispam']['rate_limit_max'] );
		$this->assertSame( 0, $s['antispam']['min_seconds'] );
		$this->assertSame( 'none', $s['antispam']['proxy_header'] );
		$this->assertSame( 1, $s['privacy']['retention_logs_days'] );
	}

	public function test_email_settings_never_return_the_password(): void {
		$saved = $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'custom', 'host' => 'smtp.example.test', 'port' => 587, 'username' => 'u', 'from_email' => 'noreply@example.test' ), 'password' => 'Sup3r-Secret!' ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertStringNotContainsString( 'Sup3r-Secret!', wp_json_encode( $saved->get_data() ) );
		$this->assertTrue( $saved->get_data()['password']['is_set'] );
		$this->assertStringNotContainsString( 'Sup3r-Secret!', wp_json_encode( $this->call( 'GET', '/email' )->get_data() ) );

		$kept = $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'custom', 'host' => 'smtp2.example.test' ) ) );
		$this->assertTrue( $kept->get_data()['password']['is_set'], 'Omitting the password keeps it' );
		$this->assertSame( 'Sup3r-Secret!', EmailSettings::password() );

		$removed = $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'custom', 'host' => 'smtp2.example.test' ), 'password' => '' ) );
		$this->assertFalse( $removed->get_data()['password']['is_set'] );
	}

	public function test_email_settings_reject_unusable_values(): void {
		$this->assertSame( 400, $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'custom', 'host' => '' ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'wp', 'from_email' => "x@y.test\r\nBcc: z@y.test" ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'wp', 'reply_to' => 'nope' ) ) )->get_status() );
	}

	public function test_test_email_is_sent_recorded_and_rate_limited(): void {
		$result = $this->call( 'POST', '/email/test', array( 'to' => 'owner@example.test' ) );
		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['ok'] );
		$this->assertSame( 'owner@example.test', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
		$this->assertSame( 'ok', $this->call( 'GET', '/email' )->get_data()['last_test']['status'] );

		$this->assertSame( 400, $this->call( 'POST', '/email/test', array( 'to' => 'not-an-email' ) )->get_status() );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->call( 'POST', '/email/test', array( 'to' => 'owner@example.test' ) );
		}
		$this->assertSame( 429, $this->call( 'POST', '/email/test', array( 'to' => 'owner@example.test' ) )->get_status() );
	}

	public function test_changing_the_email_settings_clears_the_previous_test_result(): void {
		$this->call( 'POST', '/email/test', array( 'to' => 'owner@example.test' ) );
		$this->assertNotNull( $this->call( 'GET', '/email' )->get_data()['last_test'] );
		$this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'wp', 'from_name' => 'New name' ) ) );
		$this->assertNull( $this->call( 'GET', '/email' )->get_data()['last_test'] );
	}

	// ------------------------------------------------------------------ integrations

	private function connection_body( array $over = array() ): array {
		return array(
			'connection' => array_merge(
				array(
					'name'                => 'Local receiver',
					'url'                 => StubServer::url( '/created?token=LEAK-ME' ),
					'allow_http'          => true,
					'allow_private_hosts' => true,
					'acknowledged'        => true,
					'enabled'             => true,
					'auth'                => array( 'mode' => 'bearer', 'secret' => 'bearer-secret' ),
				),
				$over
			),
		);
	}

	public function test_connections_are_saved_without_ever_returning_secrets(): void {
		$created = $this->call( 'POST', '/integrations/connections', $this->connection_body() );
		$this->assertSame( 201, $created->get_status() );
		$json = wp_json_encode( $created->get_data() );
		$this->assertStringNotContainsString( 'LEAK-ME', $json );
		$this->assertStringNotContainsString( 'bearer-secret', $json );
		$this->assertTrue( $created->get_data()['has_url'] );

		$list = $this->call( 'GET', '/integrations/connections' )->get_data();
		$this->assertCount( 1, $list['items'] );
		$this->assertStringNotContainsString( 'LEAK-ME', wp_json_encode( $list ) );
		$this->assertStringContainsString( 'sent to the address you configure', $list['notice'] );

		$id      = $created->get_data()['id'];
		$updated = $this->call( 'PUT', '/integrations/connections/' . $id, array( 'connection' => array( 'name' => 'Renamed' ) ) );
		$this->assertSame( 'Renamed', $updated->get_data()['name'] );
		$this->assertTrue( $updated->get_data()['auth']['has_secret'], 'The token was kept' );
		$this->assertSame( 'bearer-secret', ConnectionRepository::runtime( $id )['auth_secret'] );

		$this->assertSame( 200, $this->call( 'DELETE', '/integrations/connections/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/integrations/connections/' . $id )->get_status() );
	}

	public function test_a_connection_cannot_be_enabled_without_the_acknowledgement(): void {
		$response = $this->call( 'POST', '/integrations/connections', $this->connection_body( array( 'acknowledged' => false ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'krivo_chatflow_ack', $response->get_data()['code'] );
		$this->assertSame( array(), ConnectionRepository::all() );
	}

	public function test_a_client_cannot_choose_the_id_of_a_new_connection(): void {
		$body = $this->connection_body();
		$body['connection']['id'] = 'con_chosen12';
		$view = $this->call( 'POST', '/integrations/connections', $body )->get_data();
		$this->assertNotSame( 'con_chosen12', $view['id'] );
	}

	public function test_view_only_users_can_list_connections_but_not_change_them(): void {
		$id = $this->call( 'POST', '/integrations/connections', $this->connection_body() )->get_data()['id'];
		$this->as_user_with( array( Capabilities::VIEW_INTEGRATIONS ) );
		$this->assertSame( 200, $this->call( 'GET', '/integrations/connections' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/integrations/connections', $this->connection_body() )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/integrations/connections/' . $id, array( 'connection' => array( 'url' => 'https://attacker.example/hook' ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/integrations/connections/' . $id )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/integrations/test', array( 'connection_id' => $id ) )->get_status() );
		$this->assertStringContainsString( '127.0.0.1', ConnectionRepository::runtime( $id )['url'], 'The address was not replaced' );
	}

	public function test_test_button_sends_a_marked_sample_payload_and_returns_a_safe_summary(): void {
		StubServer::clear();
		$id     = $this->call( 'POST', '/integrations/connections', $this->connection_body() )->get_data()['id'];
		$result = $this->call( 'POST', '/integrations/test', array( 'connection_id' => $id ) );
		$this->assertSame( 200, $result->get_status() );
		$data = $result->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 201, $data['status'] );
		$this->assertSame( 'ok', $data['category'] );
		$this->assertSame( '127.0.0.1', $data['host'] );
		$this->assertStringNotContainsString( 'LEAK-ME', wp_json_encode( $data ) );

		$request = StubServer::requests()[0];
		$this->assertSame( 'Bearer bearer-secret', $request['headers']['authorization'] );
		$body = json_decode( $request['body'], true );
		$this->assertTrue( $body['test'] );
		$this->assertStringContainsString( 'invalid', $body['lead']['email'], 'Obviously fake data, never a real lead' );
		$this->assertSame( 0, LeadRepository::query()['total'] );
	}

	public function test_test_button_reports_a_failing_receiver_in_plain_language(): void {
		$id = $this->call( 'POST', '/integrations/connections', $this->connection_body( array( 'url' => StubServer::url( '/500' ), 'auth' => array( 'mode' => 'none' ) ) ) )->get_data()['id'];
		$data = $this->call( 'POST', '/integrations/test', array( 'connection_id' => $id ) )->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'http_5xx', $data['category'] );
		$this->assertSame( 500, $data['status'] );
		$this->assertSame( 'The service had an internal error.', $data['summary'] );

		$this->assertSame( 404, $this->call( 'POST', '/integrations/test', array( 'connection_id' => 'con_missing01' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/integrations/test', array( 'connection_id' => '../../etc' ) )->get_status() );
	}

	public function test_test_button_is_rate_limited(): void {
		$id = $this->call( 'POST', '/integrations/connections', $this->connection_body() )->get_data()['id'];
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( 200, $this->call( 'POST', '/integrations/test', array( 'connection_id' => $id ) )->get_status() );
		}
		$this->assertSame( 429, $this->call( 'POST', '/integrations/test', array( 'connection_id' => $id ) )->get_status() );
	}

	public function test_payload_preview_for_an_automation(): void {
		$flow = $this->create_flow( array( 'template' => 'support' ) );
		$automation = array( 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'config' => array( 'connection_id' => '', 'payload' => array( 'mode' => 'custom', 'template' => '{"who":"{name}","mail":"{email}"}' ) ) );
		$preview = $this->call( 'POST', '/automations/preview', array( 'flow_id' => $flow['id'], 'automation' => $automation ) );
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( array( 'who' => 'Maria Teste', 'mail' => 'maria.teste@example.invalid' ), $preview->get_data()['payload'] );

		$default = $automation;
		$default['config']['payload']['mode'] = 'default';
		$payload = $this->call( 'POST', '/automations/preview', array( 'flow_id' => $flow['id'], 'automation' => $default ) )->get_data()['payload'];
		$this->assertSame( 'form.submitted', $payload['event'] );

		$this->assertSame( 400, $this->call( 'POST', '/automations/preview', array( 'flow_id' => $flow['id'], 'automation' => array( 'type' => 'send_email' ) ) )->get_status() );
		$this->assertSame( 0, RunRepository::query()['total'] );
	}

	public function test_run_log_lists_filters_and_retries_failed_runs(): void {
		$run = RunRepository::create( 1, 2, 'form.submitted', 'a_hook', 'send_webhook' );
		RunRepository::claim( $run );
		RunRepository::finish( $run, RunRepository::FAILED, array( 'http_status' => 500, 'error_code' => 'http_5xx', 'error_message' => 'The service had an internal error.' ) );
		RunRepository::create( 1, 3, 'form.submitted', 'a_mail', 'send_email' );

		$log = $this->call( 'GET', '/automations/runs' )->get_data();
		$this->assertSame( 2, $log['total'] );
		$this->assertSame( 1, $log['counts']['failed'] );
		$failed = $this->call( 'GET', '/automations/runs', array( 'status' => 'failed' ) )->get_data();
		$this->assertSame( 1, $failed['total'] );
		$this->assertSame( 500, $failed['items'][0]['http_status'] );
		$this->assertSame( 'The service had an internal error.', $failed['items'][0]['error_message'] );
		$this->assertSame( 400, $this->call( 'GET', '/automations/runs', array( 'status' => 'bogus' ) )->get_status() );

		$this->assertSame( 200, $this->call( 'POST', '/automations/runs/' . $run . '/retry' )->get_status() );
		$this->assertSame( 'pending', RunRepository::get( $run )['status'] );
		$this->assertSame( 400, $this->call( 'POST', '/automations/runs/' . $run . '/retry' )->get_status(), 'A pending run is not retried again' );
	}

	// ------------------------------------------------------------------ analytics, health, dashboard, onboarding

	public function test_analytics_summary_has_a_gapless_series_and_the_funnel_rates(): void {
		StatsRepository::increment( 1, 'view' );
		StatsRepository::increment( 1, 'view' );
		StatsRepository::increment( 1, 'open' );
		StatsRepository::increment( 1, 'start' );
		StatsRepository::increment( 1, 'lead' );
		$data = $this->call( 'GET', '/analytics/summary', array( 'days' => 7 ) )->get_data();
		$this->assertCount( 7, $data['daily'] );
		$this->assertSame( Clock::site_day(), end( $data['daily'] )['day'] );
		$this->assertSame( 2, $data['totals']['view'] );
		$this->assertSame( 50.0, $data['rates']['open_rate'] );
		$this->assertSame( 100.0, $data['rates']['completion_rate'] );
		$this->assertSame( 50.0, $data['rates']['conversion_rate'] );
		$this->assertSame( 0.0, $data['rates']['whatsapp_rate'] );
		$this->assertSame( 'browser', $data['source']['view'] );
		$this->assertSame( 'server', $data['source']['lead'] );

		$this->assertSame( 2, $this->call( 'GET', '/analytics/summary', array( 'flow' => 1 ) )->get_data()['totals']['view'] );
		$this->assertSame( 0, $this->call( 'GET', '/analytics/summary', array( 'flow' => 2 ) )->get_data()['totals']['view'] );
		$this->assertSame( 400, $this->call( 'GET', '/analytics/summary', array( 'days' => 9999 ) )->get_status() );
		$empty = $this->call( 'GET', '/analytics/summary', array( 'flow' => 77 ) )->get_data()['rates'];
		$this->assertSame( array( 'open_rate' => null, 'completion_rate' => null, 'conversion_rate' => null, 'click_rate' => null, 'whatsapp_rate' => null ), $empty, 'No division by zero' );
	}

	public function test_health_reports_what_needs_attention(): void {
		$health = $this->call( 'GET', '/health' )->get_data();
		$codes  = array_column( $health['issues'], 'code' );
		$this->assertContains( 'cron_disabled', $codes, 'The test suite runs with WP-Cron disabled' );

		$run = RunRepository::create( 1, 1, 'form.submitted', 'a_hook', 'send_webhook' );
		RunRepository::claim( $run );
		RunRepository::finish( $run, RunRepository::FAILED, array( 'error_code' => 'x' ) );
		$this->assertContains( 'runs_failed', array_column( $this->call( 'GET', '/health' )->get_data()['issues'], 'code' ) );

		$this->call( 'PUT', '/email', array( 'settings' => array( 'mode' => 'custom', 'host' => 'smtp.example.test' ) ) );
		$this->assertContains( 'smtp_untested', array_column( $this->call( 'GET', '/health' )->get_data()['issues'], 'code' ) );

		RunRepository::create( 1, 1, 'form.submitted', 'a_mail', 'send_email' );
		Clock::freeze( time() + 1200 );
		$late = $this->call( 'GET', '/health' )->get_data();
		$this->assertContains( 'queue_lag', array_column( $late['issues'], 'code' ) );
		$this->assertGreaterThan( 300, $late['queue']['lag'] );
	}

	public function test_dashboard_shows_only_what_the_user_may_see(): void {
		list( $flow ) = $this->seed_leads();
		StatsRepository::increment( $flow, 'view' );
		$full = $this->call( 'GET', '/dashboard' )->get_data();
		$this->assertSame( 3, $full['leads']['total'] );
		$this->assertCount( 3, $full['leads']['latest'] );
		$this->assertSame( 1, $full['funnel']['totals']['view'] );
		$checklist = array_column( $full['checklist'], 'done', 'key' );
		$this->assertTrue( $checklist['create'] );
		$this->assertTrue( $checklist['first_lead'] );
		$this->assertFalse( $checklist['publish'] );
		$this->assertFalse( $checklist['destination'] );

		$this->as_user_with( array( Capabilities::VIEW_LEADS ) );
		$limited = $this->call( 'GET', '/dashboard' )->get_data();
		$this->assertArrayHasKey( 'leads', $limited );
		$this->assertArrayNotHasKey( 'funnel', $limited );

		$this->as_user_with( array( Capabilities::VIEW_ANALYTICS ) );
		$analytics = $this->call( 'GET', '/dashboard' )->get_data();
		$this->assertArrayHasKey( 'funnel', $analytics );
		$this->assertArrayNotHasKey( 'leads', $analytics );
	}

	public function test_onboarding_state_is_stored_and_sanitised(): void {
		$this->assertSame( array( 'completed' => false, 'dismissed' => false, 'goal' => '' ), $this->call( 'GET', '/onboarding' )->get_data() );
		$state = $this->call( 'PUT', '/onboarding', array( 'goal' => 'Whatsapp<script>', 'dismissed' => true ) )->get_data();
		$this->assertTrue( $state['dismissed'] );
		$this->assertFalse( $state['completed'] );
		$this->assertSame( 'whatsappscript', $state['goal'] );
		$this->assertTrue( $this->call( 'PUT', '/onboarding', array( 'completed' => true ) )->get_data()['completed'] );
	}
}

/**
 * Changes a lead status like the admin does (keeps the test body short).
 *
 * @param int    $id     Lead id.
 * @param string $status Status.
 * @return void
 */
function LeadService_status( int $id, string $status ): void {
	\Krivo\ChatFlow\Core\Leads\LeadService::change_status( $id, $status );
}
