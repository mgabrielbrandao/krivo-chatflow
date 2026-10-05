<?php
/**
 * Tests for routing, leads, statistics and retention.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Leads\LeadService;
use Krivo\ChatFlow\Core\Routing\Resolver;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Maintenance;

/**
 * @group data
 */
class DataTest extends Krivo_TestCase {

	// ------------------------------------------------------------------ routing

	private function dest( string $id, string $name, string $number = '11999998888', array $emails = array() ): array {
		return array( 'id' => $id, 'name' => $name, 'dial_code' => '55', 'number' => $number, 'emails' => $emails );
	}

	private function cond( string $left, string $op, string $right ): array {
		return array( 'left' => $left, 'op' => $op, 'right' => $right );
	}

	private function rule( string $id, string $destination, array $conditions, array $extra = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'name'        => 'Rule ' . $id,
				'when'        => array( 'match' => 'all', 'conditions' => $conditions ),
				'destination' => $destination,
			),
			$extra
		);
	}

	public function test_routing_round_trips_and_bumps_the_revision(): void {
		$this->assertSame( 0, RoutingRepository::get()['revision'] );
		$saved = RoutingRepository::save( array( 'destinations' => array( $this->dest( 'dst_one1', 'One', '11999998888', array( 'a@example.test', 'A@example.test', 'b@example.test' ) ) ) ) );
		$this->assertSame( 1, $saved['revision'] );
		$this->assertSame( '5511999998888', $saved['destinations'][0]['whatsapp'] );
		$this->assertSame( array( 'a@example.test', 'b@example.test' ), $saved['destinations'][0]['emails'], 'Duplicates (any case) are merged' );
		$this->assertSame( 2, RoutingRepository::save( array( 'destinations' => array() ) )['revision'] );
	}

	public function test_two_administrators_cannot_overwrite_each_other_silently(): void {
		RoutingRepository::save( array( 'destinations' => array( $this->dest( 'dst_one1', 'One' ) ) ) );
		$stale = RoutingRepository::save( array( 'destinations' => array() ), 0 );
		$this->assertWPErrorCode( 'krivo_chatflow_conflict', $stale );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
		$this->assertCount( 1, RoutingRepository::get()['destinations'], 'The rejected save changed nothing' );
		$this->assertIsArray( RoutingRepository::save( array( 'destinations' => array() ), 1 ) );
	}

	public function test_invalid_numbers_and_addresses_are_reported_not_silently_dropped(): void {
		$bad_number = RoutingRepository::save( array( 'destinations' => array( $this->dest( 'dst_one1', 'Sales', '123' ) ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', $bad_number );
		$this->assertStringContainsString( 'Sales', $bad_number->get_error_message() );

		foreach ( array( 'not-an-email', "a@example.test\r\nBcc: x@y.test", 'a@example.test, b@example.test', '<script>@x.test' ) as $email ) {
			$this->assertWPErrorCode( 'krivo_chatflow_invalid', RoutingRepository::save( array( 'destinations' => array( $this->dest( 'dst_one1', 'Sales', '11999998888', array( $email ) ) ) ) ), $email );
		}
		$this->assertSame( 0, RoutingRepository::get()['revision'], 'Nothing was stored' );
	}

	public function test_a_destination_may_have_only_email_or_only_whatsapp(): void {
		$saved = RoutingRepository::save(
			array(
				'destinations' => array(
					$this->dest( 'dst_mail1', 'Mail only', '', array( 'a@example.test' ) ),
					$this->dest( 'dst_wa001', 'WhatsApp only' ),
				),
			)
		);
		$this->assertSame( '', $saved['destinations'][0]['whatsapp'] );
		$this->assertSame( array(), $saved['destinations'][1]['emails'] );
	}

	public function test_limits_and_text_cleaning(): void {
		$many = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$many[] = $this->dest( 'dst_' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ), 'D' . $i );
		}
		$this->assertCount( RoutingRepository::MAX_DESTINATIONS, RoutingRepository::save( array( 'destinations' => $many ) )['destinations'] );

		$item = $this->dest( 'dst_xss01', '<b>Sales</b><script>alert(1)</script>' );
		$item['note'] = '<img src=x onerror=alert(1)>Note';
		$saved = RoutingRepository::save( array( 'destinations' => array( $item ) ) );
		$this->assertSame( 'Sales', $saved['destinations'][0]['name'] );
		$this->assertSame( 'Note', $saved['destinations'][0]['note'] );
	}

	public function test_ids_typed_by_a_client_keep_working_when_they_have_to_be_renumbered(): void {
		$saved = RoutingRepository::save(
			array(
				'default_destination' => 'vip',
				'destinations'        => array( $this->dest( 'vip', 'VIP' ), $this->dest( 'vip', 'Duplicate id' ) ),
				'rules'               => array( $this->rule( 'r1', 'vip', array( $this->cond( 'field:budget', 'eq', 'high' ) ) ) ),
			)
		);
		$this->assertIsArray( $saved );
		$this->assertCount( 2, $saved['destinations'] );
		$this->assertNotSame( $saved['destinations'][0]['id'], $saved['destinations'][1]['id'] );
		$this->assertSame( $saved['destinations'][0]['id'], $saved['default_destination'] );
		$this->assertSame( $saved['destinations'][0]['id'], $saved['rules'][0]['destination'] );
	}

	public function test_a_rule_that_cannot_be_kept_is_an_error_never_a_silent_catch_all(): void {
		$dest = array( $this->dest( 'dst_one1', 'One' ) );

		$none = RoutingRepository::save( array( 'destinations' => $dest, 'rules' => array( $this->rule( 'r1', 'dst_one1', array() ) ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', $none );

		$broken = RoutingRepository::save( array( 'destinations' => $dest, 'rules' => array( $this->rule( 'r1', 'dst_one1', array( $this->cond( 'field:budget', 'explode', 'x' ), $this->cond( 'field:budget', 'eq', 'high' ) ) ) ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', $broken );

		$orphan = RoutingRepository::save( array( 'destinations' => $dest, 'rules' => array( $this->rule( 'r1', 'dst_gone1', array( $this->cond( 'field:budget', 'eq', 'high' ) ) ) ) ) );
		$this->assertWPErrorCode( 'krivo_chatflow_invalid', $orphan );

		$this->assertSame( 0, RoutingRepository::get()['revision'] );
	}

	private function configure_routing(): void {
		$saved = RoutingRepository::save(
			array(
				'default_destination' => 'dst_site1',
				'destinations'        => array(
					$this->dest( 'dst_site1', 'Site default', '11911110000' ),
					$this->dest( 'dst_flow1', 'Flow default', '11922220000' ),
					$this->dest( 'dst_vip01', 'VIP', '11933330000' ),
					$this->dest( 'dst_goog1', 'Google ads', '11944440000' ),
				),
				'rules'               => array(
					$this->rule( 'r_off', 'dst_vip01', array( $this->cond( 'field:budget', 'eq', 'high' ) ), array( 'enabled' => false ) ),
					$this->rule( 'r_flow7', 'dst_flow1', array( $this->cond( 'field:budget', 'eq', 'high' ) ), array( 'flows' => array( 7 ) ) ),
					$this->rule( 'r_vip', 'dst_vip01', array( $this->cond( 'field:budget', 'eq', 'high' ) ) ),
					$this->rule( 'r_goog', 'dst_goog1', array( $this->cond( 'ctx:utm_source', 'eq', 'google' ) ) ),
				),
			)
		);
		$this->assertIsArray( $saved );
	}

	public function test_resolver_order_rules_then_flow_default_then_site_default(): void {
		$this->configure_routing();
		$flow = array( 'whatsapp' => array( 'default_destination' => 'dst_flow1' ) );

		$vip = Resolver::resolve( 1, array(), array( 'budget' => 'high' ), array() );
		$this->assertSame( 'dst_vip01', $vip['destination']['id'], 'A disabled rule and a rule for another flow are skipped' );
		$this->assertSame( 'r_vip', $vip['rule_id'] );
		$this->assertSame( 'rule', $vip['reason'] );

		$scoped = Resolver::resolve( 7, array(), array( 'budget' => 'high' ), array() );
		$this->assertSame( 'dst_flow1', $scoped['destination']['id'], 'Rule limited to flow 7' );

		$ctx = Resolver::resolve( 1, array(), array( 'budget' => 'low' ), array( 'utm_source' => 'google' ) );
		$this->assertSame( 'dst_goog1', $ctx['destination']['id'], 'Conditions can use page context' );

		$flow_default = Resolver::resolve( 1, $flow, array( 'budget' => 'low' ), array() );
		$this->assertSame( 'dst_flow1', $flow_default['destination']['id'] );
		$this->assertSame( 'flow_default', $flow_default['reason'] );
		$this->assertNull( $flow_default['rule_id'] );

		$site = Resolver::resolve( 1, array(), array( 'budget' => 'low' ), array() );
		$this->assertSame( 'dst_site1', $site['destination']['id'] );
		$this->assertSame( 'site_default', $site['reason'] );
	}

	public function test_resolver_without_any_routing_returns_no_destination(): void {
		$r = Resolver::resolve( 1, array(), array(), array() );
		$this->assertNull( $r['destination'] );
		$this->assertSame( 'none', $r['reason'] );
	}

	public function test_a_flow_default_pointing_at_a_deleted_destination_falls_back_to_the_site_default(): void {
		$this->configure_routing();
		$r = Resolver::resolve( 1, array( 'whatsapp' => array( 'default_destination' => 'dst_deleted' ) ), array( 'budget' => 'low' ), array() );
		$this->assertSame( 'dst_site1', $r['destination']['id'] );
	}

	public function test_developers_can_override_the_routing_result(): void {
		$this->configure_routing();
		add_filter(
			'krivo_chatflow_routing_result',
			static function ( $result ) {
				$result['reason'] = 'round_robin';
				return $result;
			}
		);
		$this->assertSame( 'round_robin', Resolver::resolve( 1, array(), array( 'budget' => 'high' ), array() )['reason'] );
	}

	// ------------------------------------------------------------------ leads

	private function lead( array $over = array() ): int {
		$id = LeadRepository::insert(
			array_merge(
				array(
					'flow_id' => 1,
					'name'    => 'Ana Souza',
					'email'   => 'ana@example.test',
					'phone'   => '11988887777',
					'answers' => array( 'name' => array( 'label' => 'Name', 'value' => 'Ana Souza', 'display' => 'Ana Souza' ) ),
				),
				$over
			)
		);
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	public function test_a_lead_round_trips_with_typed_fields(): void {
		$id   = $this->lead( array( 'in_hours' => false, 'consent' => array( array( 'key' => 'c', 'accepted' => true ) ), 'utm_source' => 'google' ) );
		$lead = LeadRepository::get( $id );
		$this->assertSame( $id, $lead['id'] );
		$this->assertSame( 1, $lead['flow_id'] );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertFalse( $lead['in_hours'] );
		$this->assertSame( 'Ana Souza', $lead['answers']['name']['display'] );
		$this->assertTrue( $lead['consent'][0]['accepted'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $lead['public_id'] );
		$this->assertSame( $lead, LeadRepository::get_by_public_id( $lead['public_id'] ) );

		$unknown = LeadRepository::get( $this->lead( array( 'in_hours' => null ) ) );
		$this->assertNull( $unknown['in_hours'] );
		$this->assertSame( array(), $unknown['consent'] );
		$this->assertNull( LeadRepository::get( 999999 ) );
		$this->assertNull( LeadRepository::get_by_public_id( 'nope' ) );
	}

	public function test_invalid_status_source_and_channel_fall_back_to_safe_values(): void {
		$lead = LeadRepository::get( $this->lead( array( 'status' => 'hacked', 'source' => 'import', 'channel' => 'pigeon' ) ) );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertSame( 'widget', $lead['source'] );
		$this->assertSame( 'message', $lead['channel'] );
	}

	public function test_search_filters_and_pagination(): void {
		$this->lead( array( 'name' => 'Ana Souza', 'email' => 'ana@example.test' ) );
		$this->lead( array( 'name' => 'Bruno Lima', 'email' => 'bruno@corp.test', 'phone' => '21955554444', 'flow_id' => 2 ) );
		$this->lead( array( 'name' => '100% Real', 'email' => 'real@example.test', 'status' => 'converted' ) );

		$this->assertSame( 3, LeadRepository::query()['total'] );
		$this->assertSame( 1, LeadRepository::query( array( 'search' => 'bruno' ) )['total'] );
		$this->assertSame( 1, LeadRepository::query( array( 'search' => 'corp.test' ) )['total'] );
		$this->assertSame( 1, LeadRepository::query( array( 'search' => '2195555' ) )['total'], 'Phone' );
		$this->assertSame( 1, LeadRepository::query( array( 'search' => '%' ) )['total'], 'A literal % in the search is not a wildcard' );
		$this->assertSame( 0, LeadRepository::query( array( 'search' => "x' OR '1'='1" ) )['total'], 'Search is bound as data' );
		$this->assertSame( 1, LeadRepository::query( array( 'status' => 'converted' ) )['total'] );
		$this->assertSame( 2, LeadRepository::query( array( 'status_not' => 'converted' ) )['total'] );
		$this->assertSame( 1, LeadRepository::query( array( 'flow_id' => 2 ) )['total'] );
		$this->assertSame( 3, LeadRepository::query( array( 'status' => 'invented' ) )['total'], 'Unknown status = no filter' );

		$page = LeadRepository::query( array( 'per_page' => 2, 'page' => 2, 'orderby' => 'id', 'order' => 'ASC' ) );
		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( '100% Real', $page['items'][0]['name'] );
		$this->assertCount( 3, LeadRepository::query( array( 'per_page' => 5000 ) )['items'], 'per_page is clamped, not trusted' );
	}

	public function test_ordering_only_accepts_known_columns(): void {
		$this->lead( array( 'name' => 'B' ) );
		$this->lead( array( 'name' => 'A' ) );
		$asc = LeadRepository::query( array( 'orderby' => 'name', 'order' => 'ASC' ) )['items'];
		$this->assertSame( array( 'A', 'B' ), array_column( $asc, 'name' ) );
		$bad = LeadRepository::query( array( 'orderby' => 'id; DROP TABLE x', 'order' => 'DESC; --' ) );
		$this->assertSame( 2, $bad['total'] );
		$this->assertTrue( (bool) $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE '" . \Krivo\ChatFlow\Infrastructure\Db\Tables::leads() . "'" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_date_filters_use_the_site_timezone_day(): void {
		update_option( 'timezone_string', 'America/Sao_Paulo' );
		Clock::freeze( strtotime( '2026-03-10 02:30:00 UTC' ) ); // 23:30 on March 9 in São Paulo.
		$this->lead( array( 'name' => 'Late night' ) );
		Clock::freeze( null );

		$this->assertSame( 1, LeadRepository::query( array( 'from' => '2026-03-09', 'to' => '2026-03-09' ) )['total'] );
		$this->assertSame( 0, LeadRepository::query( array( 'from' => '2026-03-10', 'to' => '2026-03-10' ) )['total'] );
		$this->assertSame( 2 - 1, LeadRepository::query( array( 'from' => 'garbage', 'to' => '2026-03-10' ) )['total'], 'A malformed date is ignored' );
		delete_option( 'timezone_string' );
	}

	public function test_status_change_announces_itself_only_when_something_changed(): void {
		$id      = $this->lead();
		$changes = array();
		add_action(
			'krivo_chatflow_lead_updated',
			static function ( $lead, $change ) use ( &$changes ) {
				$changes[] = array( $lead['status'], $change );
			},
			10,
			2
		);

		$lead = LeadService::change_status( $id, 'contacted' );
		$this->assertSame( 'contacted', $lead['status'] );
		$this->assertSame( array( array( 'contacted', array( 'status' => array( 'from' => 'new', 'to' => 'contacted' ) ) ) ), $changes );

		LeadService::change_status( $id, 'contacted' );
		$this->assertCount( 1, $changes, 'Setting the same status again is not a change' );

		$this->assertNull( LeadService::change_status( $id, 'hacked' ) );
		$this->assertNull( LeadService::change_status( 999999, 'converted' ) );
		$this->assertSame( 'contacted', LeadRepository::get( $id )['status'] );
	}

	public function test_status_counts_always_list_every_status(): void {
		$this->lead();
		$this->lead( array( 'status' => 'converted' ) );
		$this->lead( array( 'status' => 'converted', 'flow_id' => 2 ) );
		$all = LeadRepository::status_counts();
		$this->assertSame( LeadRepository::STATUSES, array_keys( $all ) );
		$this->assertSame( 2, $all['converted'] );
		$this->assertSame( 0, $all['lost'] );
		$this->assertSame( 1, LeadRepository::status_counts( 2 )['converted'] );
	}

	public function test_delete_removes_leads_and_their_run_log(): void {
		$a = $this->lead();
		$b = $this->lead( array( 'email' => 'b@example.test' ) );
		RunRepository::create( 1, $a, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::create( 1, $b, 'form.submitted', 'a_x', 'send_email' );

		$this->assertSame( 0, LeadRepository::delete( array() ) );
		$this->assertSame( 0, LeadRepository::delete( array( 0, -5, 'abc' ) ) );
		$this->assertSame( 1, LeadRepository::delete( array( $a, "1; DROP TABLE x" ) ) === 1 ? 1 : 0 );
		$this->assertNull( LeadRepository::get( $a ) );
		$this->assertNotNull( LeadRepository::get( $b ) );
		$this->assertSame( 0, RunRepository::query( array( 'lead_id' => $a ) )['total'] );
		$this->assertSame( 1, RunRepository::query( array( 'lead_id' => $b ) )['total'] );
	}

	public function test_lookup_by_email_for_the_privacy_tools(): void {
		$this->lead( array( 'email' => 'ana@example.test' ) );
		$this->lead( array( 'email' => 'ana@example.test', 'name' => 'Ana 2' ) );
		$this->lead( array( 'email' => 'other@example.test' ) );
		$this->assertCount( 2, LeadRepository::by_email( 'ana@example.test' ) );
		$this->assertCount( 2, LeadRepository::by_email( 'ANA@Example.Test' ) );
		$this->assertCount( 1, LeadRepository::by_email( 'ana@example.test', 1, 1 ), 'Paged' );
		$this->assertSame( array(), LeadRepository::by_email( 'nobody@example.test' ) );
	}

	public function test_streaming_visits_every_lead_exactly_once_in_small_batches(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->lead( array( 'name' => 'L' . $i, 'email' => "l{$i}@example.test" ) );
		}
		$seen = array();
		LeadRepository::each(
			array(),
			static function ( $lead ) use ( &$seen ) {
				$seen[] = $lead['name'];
			},
			3
		);
		$this->assertCount( 7, $seen );
		$this->assertCount( 7, array_unique( $seen ) );
		$this->assertSame( 'L6', $seen[0], 'Newest first' );

		$won = array();
		$this->lead( array( 'status' => 'converted' ) );
		LeadRepository::each( array( 'status' => 'converted' ), static function ( $lead ) use ( &$won ) {
			$won[] = $lead['id'];
		} );
		$this->assertCount( 1, $won );
	}

	// ------------------------------------------------------------------ statistics

	public function test_counters_accumulate_per_day_and_flow(): void {
		$day = Clock::site_day();
		$this->assertTrue( StatsRepository::increment( 1, StatsRepository::VIEW ) );
		StatsRepository::increment( 1, StatsRepository::VIEW );
		StatsRepository::increment( 2, StatsRepository::VIEW );
		StatsRepository::increment( 1, StatsRepository::LEAD );

		$this->assertSame( 2, StatsRepository::totals( $day, $day, 1 )['view'] );
		$this->assertSame( 3, StatsRepository::totals( $day, $day )['view'], 'All flows' );
		$this->assertSame( 1, StatsRepository::totals( $day, $day, 1 )['lead'] );
		$this->assertSame( array( 'view', 'open', 'start', 'button_click', 'lead', 'whatsapp_click' ), array_keys( StatsRepository::totals( $day, $day ) ) );
		$this->assertSame( 0, StatsRepository::totals( '2000-01-01', '2000-01-02' )['view'] );
		$series = StatsRepository::series( $day, $day, 1 );
		ksort( $series[ $day ] );
		$this->assertSame( array( $day => array( 'lead' => 1, 'view' => 2 ) ), $series );
	}

	public function test_invalid_counters_are_ignored(): void {
		$this->assertFalse( StatsRepository::increment( 0, 'view' ) );
		$this->assertFalse( StatsRepository::increment( -1, 'view' ) );
		$this->assertFalse( StatsRepository::increment( 1, 'rm -rf' ) );
		$this->assertFalse( StatsRepository::increment( 1, "view'; DROP TABLE x; --" ) );
		$this->assertSame( 0, array_sum( StatsRepository::totals( '2000-01-01', '2999-12-31' ) ) );
	}

	public function test_funnel_rates_are_null_instead_of_dividing_by_zero(): void {
		$this->assertSame(
			array( 'open_rate' => null, 'completion_rate' => null, 'conversion_rate' => null, 'click_rate' => null, 'whatsapp_rate' => null ),
			StatsRepository::rates( array( 'view' => 0, 'open' => 0, 'start' => 0, 'button_click' => 0, 'lead' => 0, 'whatsapp_click' => 0 ) )
		);
		$this->assertSame(
			array( 'open_rate' => 20.0, 'completion_rate' => 50.0, 'conversion_rate' => 1.0, 'click_rate' => 4.0, 'whatsapp_rate' => 50.0 ),
			StatsRepository::rates( array( 'view' => 1000, 'open' => 200, 'start' => 20, 'button_click' => 40, 'lead' => 10, 'whatsapp_click' => 5 ) )
		);
	}

	public function test_deleting_a_flow_deletes_its_counters(): void {
		StatsRepository::increment( 1, 'view' );
		StatsRepository::increment( 2, 'view' );
		StatsRepository::delete_for_flow( 1 );
		$day = Clock::site_day();
		$this->assertSame( 0, StatsRepository::totals( $day, $day, 1 )['view'] );
		$this->assertSame( 1, StatsRepository::totals( $day, $day, 2 )['view'] );
	}

	// ------------------------------------------------------------------ maintenance

	public function test_maintenance_applies_the_retention_policies_in_batches(): void {
		Clock::freeze( 1700000000 );
		$old_lead = $this->lead( array( 'email' => 'old@example.test' ) );
		$old_run  = RunRepository::create( 1, $old_lead, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $old_run );
		RunRepository::finish( $old_run, RunRepository::SUCCESS );
		Clock::freeze( 1700000000 + 100 * DAY_IN_SECONDS );
		$new_lead = $this->lead( array( 'email' => 'new@example.test' ) );

		// Defaults: logs 30 days, leads kept forever.
		$report = Maintenance::run();
		$this->assertSame( 1, $report['runs_purged'] );
		$this->assertSame( 0, $report['leads_purged'] );
		$this->assertNotNull( LeadRepository::get( $old_lead ), 'Leads are never deleted unless the administrator sets a retention' );

		Settings::update( array( 'privacy' => array( 'retention_leads_days' => 90 ) ) );
		$report = Maintenance::run();
		$this->assertSame( 1, $report['leads_purged'] );
		$this->assertNull( LeadRepository::get( $old_lead ) );
		$this->assertNotNull( LeadRepository::get( $new_lead ) );
	}

	public function test_maintenance_reopens_stuck_runs_and_asks_for_the_queue_to_run(): void {
		Clock::freeze( 1700000000 );
		$id = RunRepository::create( 1, 1, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::claim( $id );
		Clock::freeze( 1700001000 );
		$report = Maintenance::run();
		$this->assertSame( 1, $report['reopened'] );
		$this->assertSame( 'retrying', RunRepository::get( $id )['status'] );
		$this->assertNotFalse( wp_next_scheduled( \Krivo\ChatFlow\Infrastructure\Cron\Events::QUEUE ), 'Due work wakes the queue' );
	}
}
