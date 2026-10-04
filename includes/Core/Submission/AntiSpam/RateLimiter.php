<?php
/**
 * Rate limiter.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission\AntiSpam;

use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters. Keys are hashed with a site secret (an IP address is never stored in clear),
 * and counters live in the object cache when a persistent one exists, otherwise in transients, so they
 * expire by themselves and carry no personal data after the window.
 */
final class RateLimiter {

	private const GROUP = 'krivo_chatflow_rl';

	/**
	 * Counts one hit and tells whether it is still within the limit.
	 *
	 * @param string $key    Logical key, for example "submit:12:203.0.113.5".
	 * @param int    $max    Maximum hits per window.
	 * @param int    $window Window in seconds.
	 * @return bool True when allowed, false when the limit was exceeded.
	 */
	public static function hit( string $key, int $max, int $window ): bool {
		$name = self::name( $key );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $name, 0, self::GROUP, $window );
			$count = wp_cache_incr( $name, 1, self::GROUP );
			return false !== $count && (int) $count <= $max;
		}

		$now   = Clock::time();
		$state = get_transient( $name );
		if ( ! is_array( $state ) || ( $state['e'] ?? 0 ) <= $now ) {
			$state = array(
				'c' => 0,
				'e' => $now + $window,
			);
		}
		++$state['c'];
		set_transient( $name, $state, max( 1, (int) $state['e'] - $now ) );
		return $state['c'] <= $max;
	}

	/**
	 * Current count without incrementing.
	 *
	 * @param string $key Logical key.
	 * @return int
	 */
	public static function count( string $key ): int {
		$name = self::name( $key );
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $name, self::GROUP );
		}
		$state = get_transient( $name );
		return is_array( $state ) && ( $state['e'] ?? 0 ) > Clock::time() ? (int) $state['c'] : 0;
	}

	/**
	 * Clears a counter (tests, admin tools).
	 *
	 * @param string $key Logical key.
	 * @return void
	 */
	public static function reset( string $key ): void {
		$name = self::name( $key );
		wp_cache_delete( $name, self::GROUP );
		delete_transient( $name );
	}

	/**
	 * Storage name derived from the logical key.
	 *
	 * @param string $key Logical key.
	 * @return string
	 */
	private static function name( string $key ): string {
		return 'krivo_chatflow_rl_' . substr( hash_hmac( 'sha256', $key, wp_salt( 'nonce' ) ), 0, 24 );
	}
}
