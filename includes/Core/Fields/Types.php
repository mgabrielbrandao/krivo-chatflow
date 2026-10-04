<?php
/**
 * Built-in field type normalisers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Fields;

use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Whatsapp\Phone;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * One method per field type. Each receives the raw submitted value, the field definition taken from
 * the SERVER-side flow (never from the browser) and the sanitised tracking context, and returns
 * array{value:mixed,display:string,error:?string}.
 *
 * Sanitisation is per type on purpose: a generic "sanitise everything the same way" function would
 * either strip legitimate content or let the wrong thing through.
 */
final class Types {

	/**
	 * Full name.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function name( $raw, array $field ): array {
		return self::plain_text( $raw, min( 120, self::max_length( $field, 120 ) ) );
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function text( $raw, array $field ): array {
		return self::plain_text( $raw, self::max_length( $field, 255 ) );
	}

	/**
	 * Multi-line text.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function textarea( $raw, array $field ): array {
		if ( ! is_scalar( $raw ) ) {
			return self::invalid( 'invalid' );
		}
		$value = trim( sanitize_textarea_field( (string) $raw ) );
		$max   = self::max_length( $field, 2000, 5000 );
		if ( mb_strlen( $value ) > $max ) {
			return self::invalid( 'too_long', array( 'max' => $max ) );
		}
		return self::ok( $value, $value );
	}

	/**
	 * E-mail address.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	public static function email( $raw ): array {
		$value = \Krivo\ChatFlow\Core\Flow\Sanitizer::email( $raw );
		if ( '' === $value ) {
			return self::invalid( 'invalid_email' );
		}
		return self::ok( $value, $value );
	}

	/**
	 * Phone and WhatsApp numbers.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function phone( $raw, array $field ): array {
		if ( ! is_scalar( $raw ) || mb_strlen( (string) $raw ) > 40 ) {
			return self::invalid( 'invalid_phone' );
		}
		$dial   = isset( $field['default_dial_code'] ) && '' !== $field['default_dial_code']
			? (string) $field['default_dial_code']
			: (string) Settings::get( 'general.default_dial_code', '' );
		$result = Phone::normalize( (string) $raw, $dial );
		if ( ! $result['valid'] ) {
			return self::invalid( 'invalid_phone' );
		}
		return self::ok( $result['value'], $result['value'] );
	}

	/**
	 * Number (accepts "10.000,50", "10,000.50", "1500").
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function number( $raw, array $field ): array {
		if ( ! is_scalar( $raw ) ) {
			return self::invalid( 'invalid_number' );
		}
		$value = self::parse_decimal( (string) $raw );
		if ( null === $value ) {
			return self::invalid( 'invalid_number' );
		}
		$float = (float) $value;
		if ( isset( $field['min'] ) && '' !== $field['min'] && null !== $field['min'] && $float < (float) $field['min'] ) {
			return self::invalid( 'min', array( 'min' => $field['min'] ) );
		}
		if ( isset( $field['max'] ) && '' !== $field['max'] && null !== $field['max'] && $float > (float) $field['max'] ) {
			return self::invalid( 'max', array( 'max' => $field['max'] ) );
		}
		return self::ok( $value, $value );
	}

	/**
	 * Single choice (select and radio). The value must be one of the server-side options.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function choice( $raw, array $field ): array {
		if ( ! is_scalar( $raw ) ) {
			return self::invalid( 'invalid_option' );
		}
		$labels = self::option_labels( $field );
		$value  = (string) $raw;
		if ( ! array_key_exists( $value, $labels ) ) {
			return self::invalid( 'invalid_option' );
		}
		return self::ok( $value, $labels[ $value ] );
	}

	/**
	 * Multiple choice (checkbox group). Every value must be one of the server-side options.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function multi_choice( $raw, array $field ): array {
		$list   = is_array( $raw ) ? $raw : array( $raw );
		$labels = self::option_labels( $field );
		$values = array();
		foreach ( $list as $item ) {
			if ( ! is_scalar( $item ) || ! array_key_exists( (string) $item, $labels ) ) {
				return self::invalid( 'invalid_option' );
			}
			$values[ (string) $item ] = (string) $item;
		}
		$values  = array_values( $values );
		$display = implode( ', ', array_map( static fn( $v ) => $labels[ $v ], $values ) );
		return self::ok( $values, $display );
	}

	/**
	 * Date in Y-m-d.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function date( $raw, array $field ): array {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return self::invalid( 'invalid_date' );
		}
		if ( ! empty( $field['min'] ) && $value < $field['min'] ) {
			return self::invalid( 'min', array( 'min' => $field['min'] ) );
		}
		if ( ! empty( $field['max'] ) && $value > $field['max'] ) {
			return self::invalid( 'max', array( 'max' => $field['max'] ) );
		}
		return self::ok( $value, $value );
	}

	/**
	 * Time in H:i.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition.
	 * @return array
	 */
	public static function time( $raw, array $field ): array {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return self::invalid( 'invalid_time' );
		}
		if ( ! empty( $field['min'] ) && $value < $field['min'] ) {
			return self::invalid( 'min', array( 'min' => $field['min'] ) );
		}
		if ( ! empty( $field['max'] ) && $value > $field['max'] ) {
			return self::invalid( 'max', array( 'max' => $field['max'] ) );
		}
		return self::ok( $value, $value );
	}

	/**
	 * Web address (http and https only).
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	public static function url( $raw ): array {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' !== $value && ! preg_match( '#^https?://#i', $value ) && false === strpos( $value, ' ' ) && false !== strpos( $value, '.' ) ) {
			$value = 'https://' . $value; // Friendly: "krivo.works" becomes a URL.
		}
		$clean = esc_url_raw( $value, array( 'http', 'https' ) );
		$parts = wp_parse_url( $clean );
		if ( '' === $clean || strlen( $clean ) > 2048 || empty( $parts['host'] ) || false === strpos( $parts['host'], '.' ) ) {
			return self::invalid( 'invalid_url' );
		}
		return self::ok( $clean, $clean );
	}

	/**
	 * Consent checkbox. Stores a boolean.
	 *
	 * @param mixed $raw Raw value.
	 * @return array
	 */
	public static function consent( $raw ): array {
		$accepted = true === $raw || in_array( $raw, array( 1, '1', 'true', 'on', 'yes' ), true );
		return self::ok( $accepted, $accepted ? __( 'Accepted', 'krivo-chatflow' ) : __( 'Not accepted', 'krivo-chatflow' ) );
	}

	/**
	 * Hidden field. The value comes from the server (static) or the sanitised tracking context.
	 * Only "query_param" uses a browser-provided value.
	 *
	 * @param mixed $raw   Raw value (used only by the "query_param" source).
	 * @param array $field Field definition.
	 * @param array $ctx   Sanitised tracking context.
	 * @return array
	 */
	public static function hidden( $raw, array $field, array $ctx ): array {
		$source = isset( $field['source'] ) ? (string) $field['source'] : 'static';
		switch ( $source ) {
			case 'page_url':
			case 'page_title':
			case 'referrer':
			case 'utm_source':
			case 'utm_medium':
			case 'utm_campaign':
			case 'utm_content':
			case 'utm_term':
				$value = isset( $ctx[ $source ] ) ? (string) $ctx[ $source ] : '';
				break;
			case 'query_param':
				$value = is_scalar( $raw ) ? Text::limit( sanitize_text_field( (string) $raw ), 255 ) : '';
				break;
			case 'static':
			default:
				$value = isset( $field['value'] ) ? (string) $field['value'] : '';
		}
		return self::ok( $value, $value );
	}

	/**
	 * Parses a decimal typed in Brazilian or international notation into a canonical string.
	 *
	 * @param string $raw Typed value.
	 * @return string|null Canonical decimal ("10000.5") or null when it is not a number.
	 */
	public static function parse_decimal( string $raw ): ?string {
		$value = preg_replace( '/[\s\x{00A0}]/u', '', trim( $raw ) );
		$value = preg_replace( '/^(R\$|\$|€|£)/u', '', (string) $value );
		if ( '' === $value || strlen( $value ) > 24 || ! preg_match( '/^-?[\d.,]+$/', $value ) ) {
			return null;
		}
		$negative = 0 === strpos( $value, '-' );
		$value    = ltrim( $value, '-' );

		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			$decimal_sep = $last_dot > $last_comma ? '.' : ',';
		} elseif ( false !== $last_comma ) {
			$decimal_sep = ',';
		} elseif ( false !== $last_dot ) {
			// "10.000" is thousands in Brazilian notation; "10.5" and "10.50" are decimals.
			$decimal_sep = preg_match( '/^\d{1,3}(\.\d{3})+$/', $value ) ? '' : '.';
		} else {
			$decimal_sep = '';
		}

		if ( '' === $decimal_sep ) {
			$normalized = str_replace( array( '.', ',' ), '', $value );
		} else {
			$other      = '.' === $decimal_sep ? ',' : '.';
			$normalized = str_replace( $other, '', $value );
			if ( substr_count( $normalized, $decimal_sep ) > 1 ) {
				return null;
			}
			$normalized = str_replace( $decimal_sep, '.', $normalized );
		}

		if ( ! preg_match( '/^(\d+(\.\d+)?|\.\d+)$/', $normalized ) ) {
			return null;
		}
		if ( false !== strpos( $normalized, '.' ) ) {
			$normalized = rtrim( rtrim( $normalized, '0' ), '.' );
		}
		$normalized = ltrim( $normalized, '0' );
		if ( '' === $normalized || '.' === $normalized[0] ) {
			$normalized = '0' . $normalized;
		}
		return ( $negative && '0' !== $normalized ? '-' : '' ) . $normalized;
	}

	/**
	 * Returns the allowed option values mapped to their labels.
	 *
	 * @param array $field Field definition.
	 * @return array<string,string>
	 */
	public static function option_labels( array $field ): array {
		$labels = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( isset( $option['value'] ) ) {
				$labels[ (string) $option['value'] ] = isset( $option['label'] ) && '' !== $option['label'] ? (string) $option['label'] : (string) $option['value'];
			}
		}
		return $labels;
	}

	/**
	 * Shared single-line text handling.
	 *
	 * @param mixed $raw Raw value.
	 * @param int   $max Maximum characters.
	 * @return array
	 */
	private static function plain_text( $raw, int $max ): array {
		if ( ! is_scalar( $raw ) ) {
			return self::invalid( 'invalid' );
		}
		$value = trim( sanitize_text_field( (string) $raw ) );
		if ( mb_strlen( $value ) > $max ) {
			return self::invalid( 'too_long', array( 'max' => $max ) );
		}
		return self::ok( $value, $value );
	}

	/**
	 * Reads the field's own maximum length, bounded by a hard cap.
	 *
	 * @param array $field    Field definition.
	 * @param int   $fallback Default length.
	 * @param int   $cap      Hard cap.
	 * @return int
	 */
	private static function max_length( array $field, int $fallback, int $cap = 500 ): int {
		$configured = isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 0;
		return $configured > 0 ? min( $configured, $cap ) : $fallback;
	}

	/**
	 * Success result.
	 *
	 * @param mixed  $value   Stored value.
	 * @param string $display Human readable value.
	 * @return array
	 */
	private static function ok( $value, string $display ): array {
		return array(
			'value'   => $value,
			'display' => $display,
			'error'   => null,
		);
	}

	/**
	 * Failure result.
	 *
	 * @param string $code Error code.
	 * @param array  $args Message arguments.
	 * @return array
	 */
	private static function invalid( string $code, array $args = array() ): array {
		return array(
			'value'   => '',
			'display' => '',
			'error'   => $code,
			'args'    => $args,
		);
	}
}
