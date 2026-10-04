<?php
/**
 * Retry policy.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * How many times and how soon a failed run is retried. Bounded on purpose: no endless processes.
 */
final class Backoff {

	public const DEFAULT_MAX_ATTEMPTS = 3;
	public const HARD_CAP             = 5;

	/**
	 * Maximum attempts per run (filterable, never above the hard cap).
	 *
	 * @return int
	 */
	public static function max_attempts(): int {
		/**
		 * Filters the maximum number of attempts of an automation run (1 to 5).
		 *
		 * @param int $attempts Attempts.
		 */
		$max = (int) apply_filters( 'krivo_chatflow_max_attempts', self::DEFAULT_MAX_ATTEMPTS );
		return max( 1, min( self::HARD_CAP, $max ) );
	}

	/**
	 * Seconds to wait before the next attempt, given how many attempts were already made.
	 * 60 s, then 5 min, then 30 min.
	 *
	 * @param int $attempts_made Attempts already made (1 after the first failure).
	 * @return int
	 */
	public static function delay( int $attempts_made ): int {
		$schedule = array( 60, 300, 1800, 3600, 7200 );
		$index    = max( 0, min( count( $schedule ) - 1, $attempts_made - 1 ) );
		/**
		 * Filters the delay before a retry.
		 *
		 * @param int $seconds       Delay in seconds.
		 * @param int $attempts_made Attempts already made.
		 */
		return max( 1, (int) apply_filters( 'krivo_chatflow_retry_backoff', $schedule[ $index ], $attempts_made ) );
	}
}
