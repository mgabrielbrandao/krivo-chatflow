<?php
/**
 * Tests for the foundation: schema, capabilities, settings, secrets, redaction.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Crypto\Secrets;
use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Db\Uninstaller;
use Krivo\ChatFlow\Infrastructure\Logging\Redactor;

/**
 * @group foundation
 */
class FoundationTest extends Krivo_TestCase {

	public function test_tables_exist_with_expected_indexes(): void {
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing table {$table}" ); // phpcs:ignore WordPress.DB
		}
		$indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . Tables::leads(), 2 ); // phpcs:ignore WordPress.DB
		foreach ( array( 'PRIMARY', 'public_id', 'flow_created', 'status_created', 'email', 'created_at' ) as $name ) {
			$this->assertContains( $name, $indexes );
		}
		$queue = $wpdb->get_col( 'SHOW INDEX FROM ' . Tables::runs(), 2 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'queue', $queue );
	}

	public function test_administrator_receives_all_capabilities(): void {
		$role = get_role( 'administrator' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( $role->has_cap( $cap ), $cap );
		}
		$this->assertCount( 13, Capabilities::all() );
	}

	public function test_editor_has_no_plugin_capabilities_by_default(): void {
		$role = get_role( 'editor' );
		foreach ( Capabilities::all() as $cap ) {
			$this->assertFalse( $role->has_cap( $cap ), $cap );
		}
	}

	public function test_settings_defaults_are_conservative(): void {
		$all = Settings::all();
		$this->assertFalse( $all['branding']['signature_enabled'], 'Signature must be opt-in' );
		$this->assertFalse( $all['branding']['signature_link'] );
		$this->assertFalse( $all['privacy']['delete_on_uninstall'] );
		$this->assertFalse( $all['events']['datalayer'] );
		$this->assertSame( 'none', $all['antispam']['proxy_header'] );
		$this->assertSame( 0, $all['privacy']['retention_leads_days'] );
		$this->assertSame( 30, $all['privacy']['retention_logs_days'] );
	}

	public function test_settings_sanitisation_clamps_and_drops_unknown_keys(): void {
		$stored = Settings::update(
			array(
				'privacy'  => array( 'retention_logs_days' => 99999, 'retention_leads_days' => -5 ),
				'antispam' => array( 'proxy_header' => '<script>', 'min_seconds' => '4' ),
				'evil'     => array( 'key' => 'x' ),
			)
		);
		$this->assertSame( 365, $stored['privacy']['retention_logs_days'] );
		$this->assertSame( 0, $stored['privacy']['retention_leads_days'] );
		$this->assertSame( 'none', $stored['antispam']['proxy_header'], 'Invalid enum keeps the previous value' );
		$this->assertSame( 4, $stored['antispam']['min_seconds'] );
		$this->assertArrayNotHasKey( 'evil', $stored );
	}

	public function test_signature_link_requires_signature_and_authorisation_is_stamped_by_the_server(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$stored = Settings::update( array( 'branding' => array( 'signature_link' => true ) ) );
		$this->assertFalse( $stored['branding']['signature_link'], 'Link without signature is impossible' );

		$stored = Settings::update(
			array(
				'branding' => array(
					'signature_enabled' => true,
					'signature_link'    => true,
					'authorized_by'     => 999,
					'authorized_at'     => '2000-01-01 00:00:00',
				),
			)
		);
		$this->assertTrue( $stored['branding']['signature_enabled'] );
		$this->assertTrue( $stored['branding']['signature_link'] );
		$this->assertSame( $admin, $stored['branding']['authorized_by'], 'Caller cannot forge who authorised' );
		$this->assertNotSame( '2000-01-01 00:00:00', $stored['branding']['authorized_at'] );

		$stored = Settings::update( array( 'branding' => array( 'signature_enabled' => false ) ) );
		$this->assertFalse( $stored['branding']['signature_link'], 'Disabling clears the link consent too' );
		$this->assertSame( 0, $stored['branding']['authorized_by'] );
	}

	public function test_secrets_round_trip_and_are_not_stored_in_clear(): void {
		$this->assertTrue( Secrets::set( 'smtp.password', 'S3cr3t-Pass!' ) );
		$this->assertSame( 'S3cr3t-Pass!', Secrets::get( 'smtp.password' ) );
		$this->assertTrue( Secrets::has( 'smtp.password' ) );

		$raw = (string) wp_json_encode( get_option( Secrets::OPTION ) );
		$this->assertStringNotContainsString( 'S3cr3t-Pass!', $raw );
		$this->assertStringContainsString( 'k1:', $raw );

		Secrets::delete( 'smtp.password' );
		$this->assertFalse( Secrets::has( 'smtp.password' ) );
		$this->assertSame( '', Secrets::get( 'smtp.password' ) );
	}

	public function test_secrets_reject_invalid_references_and_tampered_values(): void {
		$this->assertFalse( Secrets::set( 'Bad Ref!', 'x' ) );

		Secrets::set( 'conn.a.url', 'https://hooks.example.test/abc' );
		$all                = get_option( Secrets::OPTION );
		$all['conn.a.url']  = 'k1:' . base64_encode( str_repeat( 'x', 60 ) );
		update_option( Secrets::OPTION, $all );
		$this->assertSame( '', Secrets::get( 'conn.a.url' ), 'Tampered ciphertext must not decrypt' );
		$this->assertTrue( Secrets::has( 'conn.a.url' ) );
		$this->assertFalse( Secrets::is_readable( 'conn.a.url' ), 'UI can ask the user to re-enter it' );
	}

	public function test_secrets_delete_prefix(): void {
		Secrets::set( 'conn.a.url', 'one' );
		Secrets::set( 'conn.a.auth', 'two' );
		Secrets::set( 'conn.b.url', 'three' );
		Secrets::delete_prefix( 'conn.a.' );
		$this->assertFalse( Secrets::has( 'conn.a.url' ) );
		$this->assertFalse( Secrets::has( 'conn.a.auth' ) );
		$this->assertTrue( Secrets::has( 'conn.b.url' ) );
	}

	public function test_secret_mask_never_reveals_the_whole_value(): void {
		$this->assertSame( '••••', Secrets::mask( 'abc' ) );
		$this->assertSame( '••••••yz', Secrets::mask( 'abcdefghijklmnopqrstuvwxyz' ) );
	}

	public function test_redactor_removes_credentials_and_url_paths(): void {
		$text = 'POST https://hooks.example.test/webhook/AbCdEf123456?token=zzz failed. Authorization: Bearer abcdef123456 password=hunter22';
		$out  = Redactor::clean( $text );
		$this->assertStringNotContainsString( 'AbCdEf123456', $out );
		$this->assertStringNotContainsString( 'zzz', $out );
		$this->assertStringNotContainsString( 'abcdef123456', $out );
		$this->assertStringNotContainsString( 'hunter22', $out );
		$this->assertStringContainsString( 'https://hooks.example.test/…', $out );

		$this->assertStringNotContainsString( 'my-known-secret', Redactor::clean( 'value my-known-secret here', array( 'my-known-secret' ) ) );
		$this->assertLessThanOrEqual( 240, mb_strlen( Redactor::clean( str_repeat( 'a b ', 500 ) ) ) );
	}

	public function test_uninstall_keeps_data_unless_the_admin_opted_in(): void {
		global $wpdb;
		Settings::update( array( 'privacy' => array( 'delete_on_uninstall' => false ) ) );
		$this->assertFalse( Uninstaller::run_site() );
		$this->assertSame( Tables::flows(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Tables::flows() ) ) ); // phpcs:ignore WordPress.DB
	}
}
