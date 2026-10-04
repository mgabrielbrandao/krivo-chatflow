<?php
/**
 * Public endpoint guard.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission\AntiSpam;

use Krivo\ChatFlow\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Anti-abuse for the public endpoints, designed separately from nonces (a nonce is CSRF protection,
 * not spam protection) and without relying on a CAPTCHA:
 *
 *  1. Origin check (when the browser sends one).
 *  2. Honeypot field: a robot filling it gets a fake "success" and nothing is stored.
 *  3. Signed token with a minimum age (a form filled in a couple of seconds is a bot).
 *  4. Per-IP, per-flow rate limit.
 *  5. Per-flow circuit breaker: a flood makes new leads land in the spam folder instead of
 *     triggering e-mails and webhooks.
 */
final class Guard {

	/**
	 * Checks a submission before anything is processed.
	 *
	 * @param int    $flow_id  Flow id.
	 * @param string $token    Token from the browser.
	 * @param string $honeypot Value of the hidden honeypot field.
	 * @return array{status:string,error:?\WP_Error,breaker:bool} status: "ok", "silent" (pretend success) or "error".
	 */
	public static function submission( int $flow_id, string $token, string $honeypot ): array {
		$result = array(
			'status'  => 'ok',
			'error'   => null,
			'breaker' => false,
		);

		if ( ! self::origin_ok() ) {
			return self::fail( $result, new \WP_Error( 'krivo_chatflow_origin', __( 'This request was not accepted.', 'krivo-chatflow' ), array( 'status' => 403 ) ) );
		}

		if ( '' !== trim( $honeypot ) ) {
			$result['status'] = 'silent';
			return $result;
		}

		$verdict = Token::verify( $token, $flow_id, (int) Settings::get( 'antispam.min_seconds', 3 ) );
		if ( Token::OK !== $verdict ) {
			$message = Token::TOO_FAST === $verdict
				? __( 'Please take a moment to review your answers and try again.', 'krivo-chatflow' )
				: __( 'Your session expired. Reload the page and try again.', 'krivo-chatflow' );
			return self::fail( $result, new \WP_Error( 'krivo_chatflow_token_' . $verdict, $message, array( 'status' => 403 ) ) );
		}

		$max    = (int) Settings::get( 'antispam.rate_limit_max', 8 );
		$window = (int) Settings::get( 'antispam.rate_limit_window', 900 );
		/**
		 * Filters the submission rate limit: array( max, window_seconds ).
		 *
		 * @param array $limits Limits.
		 * @param int   $flow_id Flow id.
		 */
		$limits = apply_filters( 'krivo_chatflow_rate_limits', array( $max, $window ), $flow_id );
		if ( ! RateLimiter::hit( 'submit:' . $flow_id . ':' . ClientIp::get(), (int) $limits[0], (int) $limits[1] ) ) {
			return self::fail(
				$result,
				new \WP_Error(
					'krivo_chatflow_rate_limited',
					__( 'Too many attempts. Please wait a few minutes and try again.', 'krivo-chatflow' ),
					array(
						'status'      => 429,
						'retry_after' => (int) $limits[1],
					)
				)
			);
		}

		if ( ! RateLimiter::hit( 'breaker:' . $flow_id, (int) Settings::get( 'antispam.breaker_per_minute', 30 ), 60 ) ) {
			$result['breaker'] = true;
		}
		return $result;
	}

	/**
	 * Rate limit for the lightweight endpoints (session, events).
	 *
	 * @param string $scope Scope name.
	 * @param int    $flow_id Flow id.
	 * @param int    $max   Hits per minute.
	 * @return \WP_Error|null Error when blocked.
	 */
	public static function light( string $scope, int $flow_id, int $max = 60 ): ?\WP_Error {
		if ( ! self::origin_ok() ) {
			return new \WP_Error( 'krivo_chatflow_origin', __( 'This request was not accepted.', 'krivo-chatflow' ), array( 'status' => 403 ) );
		}
		if ( ! RateLimiter::hit( $scope . ':' . $flow_id . ':' . ClientIp::get(), $max, 60 ) ) {
			return new \WP_Error( 'krivo_chatflow_rate_limited', __( 'Too many requests.', 'krivo-chatflow' ), array( 'status' => 429 ) );
		}
		return null;
	}

	/**
	 * When the browser sends an Origin header, its host must be this site.
	 *
	 * @return bool
	 */
	public static function origin_ok(): bool {
		if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			return true; // Same-origin GET/POST from some browsers and non-browser clients send none.
		}
		$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		$host   = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$port   = wp_parse_url( $origin, PHP_URL_PORT );
		if ( '' === $host ) {
			return false;
		}
		$allowed = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$allowed[] = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}
		/**
		 * Filters the hosts accepted as the Origin of public requests.
		 *
		 * @param string[] $allowed Allowed hosts.
		 */
		$allowed = (array) apply_filters( 'krivo_chatflow_allowed_origins', array_unique( $allowed ) );
		unset( $port );
		return in_array( $host, $allowed, true );
	}

	/**
	 * Marks the result as an error.
	 *
	 * @param array     $result Result.
	 * @param \WP_Error $error  Error.
	 * @return array
	 */
	private static function fail( array $result, \WP_Error $error ): array {
		$result['status'] = 'error';
		$result['error']  = $error;
		return $result;
	}
}
