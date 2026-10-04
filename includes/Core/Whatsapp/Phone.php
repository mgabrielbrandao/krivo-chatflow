<?php
/**
 * Phone number normalisation.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Whatsapp;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises phone numbers entered by visitors or administrators.
 *
 * No guessing beyond one explicit rule: a default dial code is only applied when the field or the
 * site setting defines one, and a number that already starts with that code is not prefixed twice.
 */
final class Phone {

	public const MIN_DIGITS = 8;
	public const MAX_DIGITS = 15; // E.164.

	/**
	 * Normalises a phone number.
	 *
	 * @param string $raw          What the visitor typed.
	 * @param string $default_dial Default dial code without "+" (for example "55"), or empty.
	 * @return array{value:string,digits:string,international:bool,valid:bool}
	 */
	public static function normalize( string $raw, string $default_dial = '' ): array {
		$raw          = trim( $raw );
		$default_dial = preg_replace( '/\D+/', '', $default_dial );
		$has_plus     = 0 === strpos( $raw, '+' );
		$digits       = (string) preg_replace( '/\D+/', '', $raw );

		// "00" is the international prefix in most countries.
		$international = $has_plus;
		if ( ! $has_plus && 0 === strpos( $digits, '00' ) ) {
			$digits        = ltrim( substr( $digits, 2 ), '0' );
			$international = true;
		}

		if ( ! $international && '' !== $default_dial && '' !== $digits ) {
			$already = 0 === strpos( $digits, $default_dial ) && ( strlen( $digits ) - strlen( $default_dial ) ) >= self::MIN_DIGITS;
			if ( ! $already ) {
				$digits = $default_dial . ltrim( $digits, '0' );
			}
			$international = true;
		}

		$length = strlen( $digits );
		$valid  = $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS;

		return array(
			'value'         => $international ? '+' . $digits : $digits,
			'digits'        => $digits,
			'international' => $international,
			'valid'         => $valid,
		);
	}

	/**
	 * Builds the stored E.164 digits of an administrator-provided destination number.
	 *
	 * @param string $dial_code Dial code (for example "55" or "+55").
	 * @param string $national  National number as typed.
	 * @return string Digits only, empty when unusable.
	 */
	public static function destination_digits( string $dial_code, string $national ): string {
		$result = self::normalize( $national, $dial_code );
		return $result['valid'] ? $result['digits'] : '';
	}
}
