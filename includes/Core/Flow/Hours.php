<?php
/**
 * Business hours.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Business hours evaluation in the site's timezone. The browser evaluates the same schedule with
 * Intl (the page may be cached, so the server cannot decide for the visitor); the server records the
 * authoritative answer on the lead.
 */
final class Hours {

	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Whether the schedule is open at a moment.
	 *
	 * @param array $hours     { enabled, days: { mon: [ [ "09:00", "18:00" ] ], ... } }.
	 * @param int   $timestamp Unix timestamp (defaults to now).
	 * @return bool|null Null when no schedule is configured (always available).
	 */
	public static function is_open( array $hours, ?int $timestamp = null ): ?bool {
		if ( empty( $hours['enabled'] ) ) {
			return null;
		}
		$timestamp = null === $timestamp ? Clock::time() : $timestamp;
		$moment    = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
		$day       = strtolower( $moment->format( 'D' ) );
		$clock     = $moment->format( 'H:i' );

		foreach ( (array) ( $hours['days'][ $day ] ?? array() ) as $range ) {
			if ( is_array( $range ) && 2 === count( $range ) && $clock >= $range[0] && $clock < $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Timezone descriptor for the browser: an IANA name when the site has one, otherwise a fixed offset.
	 *
	 * @return array{type:string,name?:string,minutes?:int}
	 */
	public static function client_timezone(): array {
		$name = wp_timezone_string();
		if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $name ) ) {
			$sign    = '-' === $name[0] ? -1 : 1;
			$minutes = $sign * ( (int) substr( $name, 1, 2 ) * 60 + (int) substr( $name, 4, 2 ) );
			return array(
				'type'    => 'offset',
				'minutes' => $minutes,
			);
		}
		return array(
			'type' => 'iana',
			'name' => $name,
		);
	}
}
