<?php
/**
 * Log and message redaction.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Logging;

use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Strips credentials and sensitive URL parts from any text before it is stored in a log or shown
 * in the admin (items 50 to 52 of the product brief).
 */
final class Redactor {

	/**
	 * Cleans a text.
	 *
	 * @param string   $text    Text that may contain secrets.
	 * @param string[] $secrets Known secret values to remove literally.
	 * @param int      $max     Maximum length of the result.
	 * @return string
	 */
	public static function clean( string $text, array $secrets = array(), int $max = 240 ): string {
		foreach ( $secrets as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) >= 4 ) {
				$text = str_replace( $secret, '[redacted]', $text );
			}
		}

		// Credentials inside URLs: keep scheme and host only (webhook URLs carry tokens in the path).
		$text = (string) preg_replace_callback(
			'#\bhttps?://[^\s"\'<>]+#i',
			static function ( array $m ): string {
				return self::host_only( $m[0] );
			},
			$text
		);

		$patterns = array(
			'/\bBearer\s+[A-Za-z0-9._~+\/=-]{6,}/i' => 'Bearer [redacted]',
			'/\bBasic\s+[A-Za-z0-9+\/=]{6,}/i'      => 'Basic [redacted]',
			'/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|signature)\b(\s*[=:]\s*)[^\s,;&"\']+/i' => '$1$2[redacted]',
			'/\b[A-Za-z0-9+\/]{40,}={0,2}\b/'       => '[redacted]',
		);
		foreach ( $patterns as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}

		return Text::limit( Text::single_line( $text ), $max, '…' );
	}

	/**
	 * Reduces a URL to "scheme://host" (with port when present).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host_only( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '[url]';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $scheme . '://' . $parts['host'] . $port . '/…';
	}
}
