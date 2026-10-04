<?php
/**
 * Queue scheduling.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Queue;

use Krivo\ChatFlow\Infrastructure\Cron\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Wakes the queue worker through WP-Cron. Nothing is processed inside the visitor's request:
 * spawn_cron() fires a non-blocking request to wp-cron.php and returns immediately.
 */
final class Scheduler {

	/**
	 * Hooks the cron callbacks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( Events::QUEUE, array( Worker::class, 'process' ) );
		add_action( Events::MAINTENANCE, array( '\Krivo\ChatFlow\Maintenance', 'run' ) );
	}

	/**
	 * Makes sure the worker runs soon.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function kick( int $delay = 0 ): void {
		$when = time() + max( 0, $delay );
		$next = wp_next_scheduled( Events::QUEUE );
		if ( false === $next || $next > $when + 5 ) {
			wp_schedule_single_event( $when, Events::QUEUE );
		}
		if ( 0 === $delay && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}
}
