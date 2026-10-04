<?php
/**
 * Webhook request signing.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Signs webhook bodies so the receiver can verify origin and reject replays.
 *
 * Header: X-KrivoChatFlow-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">
 * Receivers recompute the HMAC with the shared secret and compare in constant time; the timestamp
 * lets them refuse requests older than a few minutes.
 */
final class Signer {

	/**
	 * Builds the signature header value.
	 *
	 * @param string $secret    Shared secret.
	 * @param string $body      Exact request body.
	 * @param int    $timestamp Unix time.
	 * @return string
	 */
	public static function sign( string $secret, string $body, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verifies a signature header (used by tests and documented for receivers).
	 *
	 * @param string $secret    Shared secret.
	 * @param string $body      Body received.
	 * @param string $header    Header value.
	 * @param int    $tolerance Accepted age in seconds.
	 * @param int    $now       Current time.
	 * @return bool
	 */
	public static function verify( string $secret, string $body, string $header, int $tolerance = 300, ?int $now = null ): bool {
		if ( ! preg_match( '/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m ) ) {
			return false;
		}
		$now = null === $now ? time() : $now;
		if ( abs( $now - (int) $m[1] ) > $tolerance ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $m[1] . '.' . $body, $secret ), $m[2] );
	}
}
