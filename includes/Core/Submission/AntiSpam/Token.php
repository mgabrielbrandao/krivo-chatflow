<?php
/**
 * Stateless submission token.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission\AntiSpam;

use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * A signed, short-lived proof that the visitor really opened the chat of a given flow, and when.
 *
 * Why not a WordPress nonce? Visitor nonces are identical for every logged-out user, expire after
 * 12 to 24 hours and get stale inside cached pages: they would reject genuine leads and they are not
 * anti-spam anyway. This token is issued when the panel opens (never baked into cached HTML),
 * is bound to the flow, carries its issue time (a form filled in under a few seconds is a bot) and
 * is verified with an HMAC derived from the site's secret keys. It needs no storage.
 *
 * Format: base64url(json{f,t,r,v}) "." base64url(hmac_sha256).
 */
final class Token {

	public const MAX_AGE = 21600; // 6 hours.

	public const OK        = 'ok';
	public const MALFORMED = 'malformed';
	public const SIGNATURE = 'signature';
	public const FLOW      = 'flow';
	public const TOO_FAST  = 'too_fast';
	public const EXPIRED   = 'expired';

	/**
	 * Issues a token for a flow.
	 *
	 * @param int $flow_id Flow id.
	 * @return string
	 */
	public static function issue( int $flow_id ): string {
		$payload = self::b64( (string) wp_json_encode(
			array(
				'f' => $flow_id,
				't' => Clock::time(),
				'r' => bin2hex( random_bytes( 6 ) ),
				'v' => 1,
			)
		) );
		return $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, self::key(), true ) );
	}

	/**
	 * Verifies a token.
	 *
	 * @param string $token    Token received from the browser.
	 * @param int    $flow_id  Flow the submission is for.
	 * @param int    $min_age  Minimum seconds since issue.
	 * @return string One of the class constants; OK means valid.
	 */
	public static function verify( string $token, int $flow_id, int $min_age = 0 ): string {
		if ( strlen( $token ) > 400 || 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token ) ) {
			return self::MALFORMED;
		}
		list( $payload, $signature ) = explode( '.', $token, 2 );
		$expected                    = self::b64( hash_hmac( 'sha256', $payload, self::key(), true ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::SIGNATURE;
		}
		$data = json_decode( (string) self::unb64( $payload ), true );
		if ( ! is_array( $data ) || ! isset( $data['f'], $data['t'] ) || 1 !== ( $data['v'] ?? 0 ) ) {
			return self::MALFORMED;
		}
		if ( (int) $data['f'] !== $flow_id ) {
			return self::FLOW;
		}
		$age = Clock::time() - (int) $data['t'];
		if ( $age < $min_age ) {
			return self::TOO_FAST;
		}
		if ( $age > self::MAX_AGE ) {
			return self::EXPIRED;
		}
		return self::OK;
	}

	/**
	 * HMAC key derived from the site's salts.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, 'krivo-chatflow-token-v1' );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding, not obfuscation.
	}

	/**
	 * Decodes URL-safe base64.
	 *
	 * @param string $encoded Encoded text.
	 * @return string|false
	 */
	private static function unb64( string $encoded ) {
		return base64_decode( strtr( $encoded, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding.
	}
}
