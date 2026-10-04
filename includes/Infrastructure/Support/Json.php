<?php
/**
 * JSON helpers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Support;

defined( 'ABSPATH' ) || exit;

/**
 * JSON encode/decode with the flags and fallbacks the plugin needs.
 */
final class Json {

	/**
	 * Encodes for storage or transport (unicode kept readable).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function encode( $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Encodes so the result is safe to print inside an HTML script data block
	 * (cannot close the tag, cannot break out of attributes).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function encode_for_html( $value ): string {
		$json = wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Decodes to an array, returning the fallback on any failure or non-array result.
	 *
	 * @param mixed $json     JSON string.
	 * @param array $fallback Returned on failure.
	 * @return array
	 */
	public static function decode( $json, array $fallback = array() ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return $fallback;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : $fallback;
	}
}
