<?php
/**
 * Text helpers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Multibyte-safe text helpers.
 */
final class Text {

	/**
	 * Truncates to a maximum number of characters (not bytes).
	 *
	 * @param string $text   Text.
	 * @param int    $max    Maximum characters.
	 * @param string $suffix Appended when truncated.
	 * @return string
	 */
	public static function limit( string $text, int $max, string $suffix = '' ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$keep = max( 0, $max - mb_strlen( $suffix ) );
		return rtrim( mb_substr( $text, 0, $keep ) ) . $suffix;
	}

	/**
	 * Builds a machine key (lowercase letters, digits, underscore) from a label.
	 * "Qual serviço você procura?" becomes "qual_servico_voce_procura".
	 *
	 * @param string $label    Human label.
	 * @param string $fallback Used when the label yields nothing.
	 * @return string
	 */
	public static function key_from_label( string $label, string $fallback = 'field' ): string {
		$ascii = remove_accents( $label );
		$key   = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '_', $ascii ) );
		$key   = trim( (string) $key, '_' );
		if ( '' === $key ) {
			return $fallback;
		}
		if ( preg_match( '/^[0-9]/', $key ) ) {
			$key = 'f_' . $key;
		}
		return self::limit( $key, 40 );
	}

	/**
	 * Removes control characters (including CR/LF) and collapses whitespace to single spaces.
	 * Used for e-mail headers, subjects and anything that must stay on one line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function single_line( string $text ): string {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Generates a short random identifier made of lowercase letters and digits.
	 *
	 * @param string $prefix Prefix such as "f_".
	 * @param int    $length Random part length.
	 * @return string
	 */
	public static function random_id( string $prefix = '', int $length = 8 ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id       = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$id .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $prefix . $id;
	}
}
