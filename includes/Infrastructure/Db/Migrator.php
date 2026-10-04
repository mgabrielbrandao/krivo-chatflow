<?php
/**
 * Schema installation and upgrades.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Db;

use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Cron\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the schema lazily and per site, so network activation and sites created
 * after activation are covered without iterating blogs at activation time.
 */
final class Migrator {

	public const DB_VERSION_OPTION = 'krivo_chatflow_db_version';
	private const LOCK_OPTION      = 'krivo_chatflow_migrating';
	private const LOCK_SECONDS     = 60;

	/**
	 * Hooks the version check.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Runs the upgrade when the stored schema version differs from the code's. One autoloaded
	 * option read on every request when up to date.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === KRIVO_CHATFLOW_DB_VERSION ) {
			return;
		}
		self::upgrade();
	}

	/**
	 * Creates or updates the tables, grants capabilities and schedules maintenance.
	 * Guarded by a short-lived lock so concurrent requests do not run it twice.
	 *
	 * @return void
	 */
	public static function upgrade(): void {
		if ( ! add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			$started = (int) get_option( self::LOCK_OPTION );
			if ( time() - $started < self::LOCK_SECONDS ) {
				return;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::sql() );

		Capabilities::grant_default();
		Events::schedule();

		update_option( self::DB_VERSION_OPTION, KRIVO_CHATFLOW_DB_VERSION, true );
		delete_option( self::LOCK_OPTION );
	}
}
