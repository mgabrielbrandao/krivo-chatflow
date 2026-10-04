<?php
/**
 * Time helpers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises time so it can be controlled in tests and stays consistent (UTC in the database).
 */
final class Clock {

	/**
	 * Optional fixed timestamp used by tests.
	 *
	 * @var int|null
	 */
	private static $fixed = null;

	/**
	 * Freezes (or unfreezes with null) the clock. Intended for tests.
	 *
	 * @param int|null $timestamp Unix timestamp.
	 * @return void
	 */
	public static function freeze( ?int $timestamp ): void {
		self::$fixed = $timestamp;
	}

	/**
	 * Current Unix timestamp.
	 *
	 * @return int
	 */
	public static function time(): int {
		return null !== self::$fixed ? self::$fixed : time();
	}

	/**
	 * Current UTC datetime in MySQL format.
	 *
	 * @param int $offset_seconds Seconds to add.
	 * @return string
	 */
	public static function now( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', self::time() + $offset_seconds );
	}

	/**
	 * Today's date in the site's timezone (Y-m-d). Used as the stats bucket.
	 *
	 * @return string
	 */
	public static function site_day(): string {
		return wp_date( 'Y-m-d', self::time() );
	}

	/**
	 * Converts a UTC MySQL datetime to an ISO 8601 string (UTC).
	 *
	 * @param string $mysql_utc Datetime like "2026-10-04 18:31:00".
	 * @return string
	 */
	public static function iso( string $mysql_utc ): string {
		$ts = strtotime( $mysql_utc . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
	}
}
