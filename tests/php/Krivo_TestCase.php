<?php
/**
 * Base test case.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Infrastructure\Db\Migrator;
use Krivo\ChatFlow\Infrastructure\Db\Tables;

/**
 * Common helpers for the plugin's tests.
 */
abstract class Krivo_TestCase extends WP_UnitTestCase {

	/**
	 * Makes sure the plugin tables exist (they are created lazily on plugins_loaded).
	 * dbDelta uses CREATE TABLE, which commits implicitly, so tables are emptied by hand
	 * instead of relying on the transaction rollback of WP_UnitTestCase.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Migrator::DB_VERSION_OPTION );
		Migrator::upgrade();
		$this->truncate();
		delete_option( 'krivo_chatflow_settings' );
		delete_option( 'krivo_chatflow_routing' );
		delete_option( 'krivo_chatflow_integrations' );
		delete_option( 'krivo_chatflow_secrets' );
		delete_option( 'krivo_chatflow_email' );
		delete_option( 'krivo_chatflow_index' );
		\Krivo\ChatFlow\Infrastructure\Support\Clock::freeze( null );
	}

	/**
	 * Empties every plugin table.
	 *
	 * @return void
	 */
	protected function truncate(): void {
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Asserts that a value is a WP_Error with the given code.
	 *
	 * @param string $code  Expected error code.
	 * @param mixed  $value Value to inspect.
	 * @return void
	 */
	protected function assertWPErrorCode( string $code, $value ): void {
		$this->assertInstanceOf( WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}
}
