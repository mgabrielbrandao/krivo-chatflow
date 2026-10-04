<?php
/**
 * Cron events.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Names and scheduling of the plugin's WP-Cron events.
 */
final class Events {

	public const QUEUE       = 'krivo_chatflow_process_queue';
	public const MAINTENANCE = 'krivo_chatflow_maintenance';

	/**
	 * Schedules the recurring maintenance event.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::MAINTENANCE ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::MAINTENANCE );
		}
	}

	/**
	 * Removes every scheduled event of the plugin (used on deactivation and uninstall).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::MAINTENANCE );
		wp_clear_scheduled_hook( self::QUEUE );
	}
}
