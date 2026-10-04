<?php
/**
 * Client IP detection.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission\AntiSpam;

use Krivo\ChatFlow\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the visitor's IP address for rate limiting ONLY. It is never stored: callers hash it
 * together with a site secret and use it as a short-lived counter key.
 *
 * Proxy headers are trusted only if the administrator chose so in Settings: otherwise any client
 * could forge "X-Forwarded-For" and dodge the rate limit.
 */
final class ClientIp {

	/**
	 * The client's IP address, or "0.0.0.0" when unknown.
	 *
	 * @return string
	 */
	public static function get(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;

		switch ( (string) Settings::get( 'antispam.proxy_header', 'none' ) ) {
			case 'cloudflare':
				$ip = self::header( 'HTTP_CF_CONNECTING_IP' ) ?: $remote;
				break;
			case 'x-forwarded-for':
				$forwarded = self::header( 'HTTP_X_FORWARDED_FOR' );
				if ( '' !== $forwarded ) {
					$first = trim( explode( ',', $forwarded )[0] );
					$ip    = $first;
				}
				break;
		}

		$ip = (string) apply_filters( 'krivo_chatflow_client_ip', $ip, $remote );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : ( false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0' );
	}

	/**
	 * Reads one request header from $_SERVER.
	 *
	 * @param string $name Key such as HTTP_CF_CONNECTING_IP.
	 * @return string
	 */
	private static function header( string $name ): string {
		return isset( $_SERVER[ $name ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) : '';
	}
}
