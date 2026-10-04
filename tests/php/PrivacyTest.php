<?php
/**
 * Tests for the privacy tools, retention and uninstall.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Leads\PrivacyTools;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Cron\Events;
use Krivo\ChatFlow\Infrastructure\Crypto\Secrets;
use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Db\Uninstaller;

/**
 * @group privacy
 */
class PrivacyTest extends Krivo_TestCase {

	private function lead( string $email, array $over = array() ): int {
		return LeadRepository::insert(
			array_merge(
				array(
					'flow_id'    => 1,
					'name'       => 'Ana Souza',
					'email'      => $email,
					'phone'      => '+5511999990000',
					'page_url'   => 'https://example.test/p',
					'utm_source' => 'google',
					'answers'    => array( 'message' => array( 'label' => 'Message', 'type' => 'textarea', 'value' => 'Hello', 'display' => 'Hello' ) ),
					'consent'    => array( array( 'key' => 'consent', 'accepted' => true, 'at' => '2026-01-02T03:04:05Z' ) ),
				),
				$over
			)
		);
	}

	public function test_tools_are_registered_with_wordpress(): void {
		PrivacyTools::register();
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'krivo-chatflow', $exporters );
		$this->assertArrayHasKey( 'krivo-chatflow', $erasers );
		$this->assertIsCallable( $exporters['krivo-chatflow']['callback'] );
		$this->assertIsCallable( $erasers['krivo-chatflow']['callback'] );
	}

	public function test_export_contains_everything_stored_about_the_person_and_nobody_else(): void {
		$this->lead( 'ana@example.test' );
		$this->lead( 'other@example.test', array( 'name' => 'Someone Else' ) );
		$result = PrivacyTools::export( 'ANA@example.test', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$item = $result['data'][0];
		$this->assertSame( 'krivo-chatflow-leads', $item['group_id'] );
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'Ana Souza', $values['Name'] );
		$this->assertSame( 'ana@example.test', $values['E-mail'] );
		$this->assertSame( '+5511999990000', $values['Phone'] );
		$this->assertSame( 'https://example.test/p', $values['Page'] );
		$this->assertSame( 'Hello', $values['Message'] );
		$this->assertSame( '2026-01-02T03:04:05Z', $values['Consent given'] );
		$this->assertStringNotContainsString( 'Someone Else', wp_json_encode( $result ) );
	}

	public function test_export_is_paged_so_large_histories_do_not_time_out(): void {
		for ( $i = 0; $i < 53; $i++ ) {
			$this->lead( 'many@example.test', array( 'name' => 'N' . $i ) );
		}
		$first  = PrivacyTools::export( 'many@example.test', 1 );
		$second = PrivacyTools::export( 'many@example.test', 2 );
		$this->assertCount( 50, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 3, $second['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertSame( array(), PrivacyTools::export( 'nobody@example.test' )['data'] );
	}

	public function test_erase_removes_the_leads_and_their_automation_log_only_for_that_address(): void {
		$mine  = $this->lead( 'ana@example.test' );
		$mine2 = $this->lead( 'ana@example.test', array( 'name' => 'Ana again' ) );
		$other = $this->lead( 'other@example.test' );
		RunRepository::create( 1, $mine, 'form.submitted', 'a_x', 'send_email' );
		RunRepository::create( 1, $other, 'form.submitted', 'a_x', 'send_email' );

		$result = PrivacyTools::erase( 'Ana@Example.test', 1 );
		$this->assertSame( 2, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( LeadRepository::get( $mine ) );
		$this->assertNull( LeadRepository::get( $mine2 ) );
		$this->assertNotNull( LeadRepository::get( $other ) );
		$this->assertSame( 0, RunRepository::query( array( 'lead_id' => $mine ) )['total'] );
		$this->assertSame( 1, RunRepository::query( array( 'lead_id' => $other ) )['total'] );
	}

	public function test_erase_keeps_going_until_everything_is_gone(): void {
		for ( $i = 0; $i < 120; $i++ ) {
			$this->lead( 'many@example.test' );
		}
		$total = 0;
		$calls = 0;
		do {
			$result = PrivacyTools::erase( 'many@example.test', 1 );
			$total += $result['items_removed'];
			++$calls;
		} while ( ! $result['done'] && $calls < 10 );
		$this->assertSame( 120, $total );
		$this->assertSame( array(), LeadRepository::by_email( 'many@example.test' ) );
	}

	public function test_suggested_policy_text_covers_collection_storage_whatsapp_storage_and_rights(): void {
		$text = PrivacyTools::policy_html();
		foreach ( array( 'What we collect', 'Where it is stored', 'WhatsApp', 'Cookies and browser storage', 'sets no cookies', 'Export Personal Data', 'webhook' ) as $needle ) {
			$this->assertStringContainsString( $needle, $text, $needle );
		}
	}

	public function test_policy_text_is_offered_in_the_privacy_guide(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		set_current_screen( 'dashboard' );
		remove_all_actions( 'admin_init' ); // Core's own admin_init work calls wordpress.org.
		PrivacyTools::register();
		do_action( 'admin_init' );
		$content = WP_Privacy_Policy_Content::get_suggested_policy_text();
		$names   = array_column( $content, 'plugin_name' );
		$this->assertContains( 'Krivo ChatFlow', $names );
		set_current_screen( 'front' );
	}

	// ------------------------------------------------------------------ uninstall

	public function test_uninstall_keeps_everything_by_default(): void {
		$lead = $this->lead( 'ana@example.test' );
		$this->assertFalse( Uninstaller::run_site() );
		$this->assertNotNull( LeadRepository::get( $lead ) );
		$this->assertNotFalse( get_option( Krivo\ChatFlow\Infrastructure\Db\Migrator::DB_VERSION_OPTION ) );
	}

	public function test_uninstall_removes_everything_when_the_administrator_opted_in(): void {
		global $wpdb;
		$this->lead( 'ana@example.test' );
		RoutingRepository::save( array( 'destinations' => array( array( 'id' => 'dst_one1', 'name' => 'One', 'dial_code' => '55', 'number' => '11999998888' ) ) ) );
		Secrets::set( 'smtp.password', 'secret' );
		update_option( 'krivo_chatflow_email_test', array( 'status' => 'ok' ) );
		set_transient( 'krivo_chatflow_dup_x', 'y', 600 );
		Settings::update( array( 'privacy' => array( 'delete_on_uninstall' => true ) ) );
		Capabilities::grant_default();

		// The test suite turns DROP TABLE into DROP TEMPORARY TABLE, so check the statements instead.
		$dropped = array();
		add_filter(
			'query',
			static function ( $sql ) use ( &$dropped ) {
				if ( preg_match( '/^DROP (TEMPORARY )?TABLE IF EXISTS `?([A-Za-z0-9_]+)`?/', $sql, $m ) ) {
					$dropped[] = $m[2];
				}
				return $sql;
			},
			100
		);
		$this->assertTrue( Uninstaller::run_site() );
		$this->assertEqualsCanonicalizing( Tables::all(), $dropped, 'All four tables are dropped' );

		foreach ( array( 'krivo_chatflow_settings', 'krivo_chatflow_routing', 'krivo_chatflow_secrets', 'krivo_chatflow_email_test', 'krivo_chatflow_index', 'krivo_chatflow_db_version' ) as $option ) {
			// get_option() would return the registered default, so ask the table.
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $option ) ), $option . ' removed' ); // phpcs:ignore WordPress.DB
		}
		// Read the table: the in-process object cache would still answer for the deleted row.
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%krivo\\_chatflow\\_%'" ), 'Transients removed' ); // phpcs:ignore WordPress.DB
		$this->assertFalse( wp_next_scheduled( Events::QUEUE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::VIEW_LEADS ), 'Capabilities removed' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'krivo\\_chatflow\\_%'" ), 'No option of the plugin is left' ); // phpcs:ignore WordPress.DB
		Capabilities::grant_default();
	}
}
