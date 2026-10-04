<?php
/**
 * WhatsApp click-to-chat URL.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Whatsapp;

use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Builds https://wa.me/<number>?text=<message>.
 *
 * The URL is opened by the VISITOR'S browser after an explicit click. The site's server never
 * contacts WhatsApp, and no paid API is involved.
 */
final class UrlBuilder {

	public const HOST       = 'https://wa.me/';
	public const MAX_LENGTH = 4000;

	/**
	 * Builds the URL.
	 *
	 * @param string $number  Destination number (any format; only digits are kept).
	 * @param string $message Message text (line breaks allowed).
	 * @return string Empty when the number is unusable.
	 */
	public static function build( string $number, string $message ): string {
		$digits = (string) preg_replace( '/\D+/', '', $number );
		if ( strlen( $digits ) < Phone::MIN_DIGITS || strlen( $digits ) > Phone::MAX_DIGITS ) {
			return '';
		}

		$base    = self::HOST . $digits;
		$message = trim( str_replace( array( "\r\n", "\r" ), "\n", $message ) );
		if ( '' === $message ) {
			return $base;
		}

		// Browsers and some in-app webviews have URL length limits: shorten the TEXT until the encoded
		// URL fits. The full message always stays in the lead record.
		$text = $message;
		for ( $i = 0; $i < 40; $i++ ) {
			$url = $base . '?text=' . rawurlencode( $text );
			if ( strlen( $url ) <= self::MAX_LENGTH ) {
				return $url;
			}
			$text = Text::limit( $text, max( 1, (int) floor( mb_strlen( $text ) * 0.9 ) ), '…' );
		}
		return $base;
	}
}
