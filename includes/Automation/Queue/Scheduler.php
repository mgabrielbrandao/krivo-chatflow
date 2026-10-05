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
		// A wake-up that is a few seconds late is fine for a retry, but an immediate kick must be due now: spawn_cron()
		// starts nothing while the next event is still in the future, and on a quiet site no other request would come
		// along to run it.
		$tolerance = 0 === $delay ? 0 : 5;
		if ( false === $next || $next > $when + $tolerance ) {
			// WordPress refuses a second identical event within 10 minutes of an existing one, so a later wake-up
			// (a retry in 5 minutes) would silently swallow this earlier one. Replace it: the worker schedules the
			// next retry again from the database when it finishes a pass.
			if ( false !== $next ) {
				wp_unschedule_event( $next, Events::QUEUE );
			}
			wp_schedule_single_event( $when, Events::QUEUE );
		}
		if ( 0 === $delay && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}
}
