<?php
/**
 * WCAG contrast.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * WCAG 2.x contrast ratio between two hex colours. Used to warn the administrator when a chosen
 * bubble or panel colour combination is hard to read (the admin shows the same calculation live).
 */
final class Contrast {

	/**
	 * Contrast ratio from 1 to 21.
	 *
	 * @param string $a Hex colour (#RGB or #RRGGBB).
	 * @param string $b Hex colour.
	 * @return float
	 */
	public static function ratio( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		$hi = max( $la, $lb );
		$lo = min( $la, $lb );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * Relative luminance of a hex colour.
	 *
	 * @param string $hex Colour.
	 * @return float
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return 0.0;
		}
		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
