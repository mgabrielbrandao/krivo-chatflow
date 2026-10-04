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
	 * Outbound HTTP requests attempted during the test (URL => count), except to loopback addresses (the local test receiver).
	 *
	 * @var array<string,int>
	 */
	protected $blocked_http = array();

	/**
	 * Makes sure the plugin tables exist (they are created lazily on plugins_loaded).
	 * dbDelta uses CREATE TABLE, which commits implicitly, so tables are emptied by hand
	 * instead of relying on the transaction rollback of WP_UnitTestCase.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		// dbDelta commits the test transaction, so nothing is rolled back for us: forget every option and
		// transient the plugin (and WP-Cron) may have left behind by hand.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'krivo\_chatflow\_%' OR option_name LIKE '\_transient\_%' OR option_name LIKE '\_transient\_timeout\_%' OR option_name = 'doing_cron'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		Migrator::upgrade();
		$this->truncate();
		\Krivo\ChatFlow\Infrastructure\Support\Clock::freeze( null );
		$this->blocked_http = array();
		// A test never reaches the Internet (or the site's own wp-cron.php): anything that is not the local
		// receiver is refused and recorded.
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( 0 === strpos( $url, 'http://127.0.0.1:' ) ) {
					return $pre;
				}
				$this->blocked_http[ $url ] = ( $this->blocked_http[ $url ] ?? 0 ) + 1;
				return new WP_Error( 'http_request_failed', 'Blocked in tests.' );
			},
			10,
			3
		);
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
