<?php
/**
 * Array helpers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small, dependency-free array helpers.
 */
final class Arr {

	/**
	 * Reads a nested value using dot notation.
	 *
	 * @param array  $array    Source array.
	 * @param string $path     Dot path, e.g. "privacy.retention_leads_days".
	 * @param mixed  $fallback Value returned when the path does not exist.
	 * @return mixed
	 */
	public static function get( array $array, string $path, $fallback = null ) {
		$current = $array;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return $fallback;
			}
			$current = $current[ $segment ];
		}
		return $current;
	}

	/**
	 * Writes a nested value using dot notation.
	 *
	 * @param array  $array Target array (modified).
	 * @param string $path  Dot path.
	 * @param mixed  $value Value to set.
	 * @return void
	 */
	public static function set( array &$array, string $path, $value ): void {
		$segments = explode( '.', $path );
		$current  = &$array;
		foreach ( $segments as $segment ) {
			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				$current[ $segment ] = array();
			}
			$current = &$current[ $segment ];
		}
		$current = $value;
	}

	/**
	 * Whether an array is a list (sequential integer keys starting at 0).
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value;
	}
}
