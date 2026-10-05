<?php
/**
 * The admin-ajax fallback for the public endpoints.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Submission\AntiSpam\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Some hosts and security plugins block the REST API for visitors. The widget then falls back to
 * `admin-ajax.php`. The actions call the very same services as the REST routes (PublicApi), so every
 * guard applies equally; this is only another door to the same rooms.
 *
 * A WordPress nonce is deliberately not used (see PublicController): the protection is the Origin
 * check, the signed token, the honeypot and the rate limits.
 */
final class AjaxFallback {

	/**
	 * Registers the actions (for visitors and for logged-in users).
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( array( 'manifest', 'session', 'events', 'submit' ) as $action ) {
			add_action( 'wp_ajax_krivo_chatflow_' . $action, array( __CLASS__, $action ) );
			add_action( 'wp_ajax_nopriv_krivo_chatflow_' . $action, array( __CLASS__, $action ) );
		}
	}

	/**
	 * Manifest.
	 *
	 * @return void
	 */
	public static function manifest(): void {
		self::guard_origin();
		$data   = self::payload();
		$result = PublicApi::manifest( (int) ( $data['flow'] ?? 0 ) );
		self::respond( is_wp_error( $result ) ? $result : $result['flow'] );
	}

	/**
	 * Session.
	 *
	 * @return void
	 */
	public static function session(): void {
		self::guard_origin();
		$data = self::payload();
		self::respond( PublicApi::session( (int) ( $data['flow'] ?? 0 ) ) );
	}

	/**
	 * Events.
	 *
	 * @return void
	 */
	public static function events(): void {
		self::guard_origin();
		$data   = self::payload();
		$result = PublicApi::events( (int) ( $data['flow'] ?? 0 ), is_array( $data['events'] ?? null ) ? $data['events'] : array() );
		if ( is_wp_error( $result ) ) {
			self::respond( $result );
		}
		status_header( 204 );
		exit;
	}

	/**
	 * Submission.
	 *
	 * @return void
	 */
	public static function submit(): void {
		self::guard_origin();
		$data = self::payload();
		self::respond(
			PublicApi::submit(
				array(
					'flow'    => (int) ( $data['flow'] ?? 0 ),
					'token'   => is_string( $data['token'] ?? null ) ? $data['token'] : '',
					'answers' => is_array( $data['answers'] ?? null ) ? $data['answers'] : array(),
					'context' => is_array( $data['context'] ?? null ) ? $data['context'] : array(),
					'hp'      => $data['hp'] ?? '',
				)
			)
		);
	}

	/**
	 * Decodes the JSON payload sent in the "payload" form field. Every value is validated and
	 * sanitised by the field types and the tracking sanitiser before it is used or stored.
	 *
	 * @return array
	 */
	private static function payload(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public endpoint without a nonce by design (cached pages, logged-out visitors); the raw JSON is decoded here and each value is sanitised by Core\Fields\Registry / Tracking before use.
		$raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		// phpcs:enable
		if ( '' === $raw || strlen( $raw ) > PublicApi::MAX_BODY_BYTES ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Refuses a request from another origin.
	 *
	 * @return void
	 */
	private static function guard_origin(): void {
		if ( ! Guard::origin_ok() ) {
			self::respond( new \WP_Error( 'krivo_chatflow_origin', __( 'This request was not accepted.', 'krivo-chatflow' ), array( 'status' => 403 ) ) );
		}
	}

	/**
	 * Sends the JSON answer and stops.
	 *
	 * @param array|\WP_Error $result Result.
	 * @return void
	 */
	private static function respond( $result ): void {
		nocache_headers();
		if ( is_wp_error( $result ) ) {
			$mapped = PublicApi::error_response( $result );
			foreach ( $mapped['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
			wp_send_json( $mapped['body'], $mapped['status'] );
		}
		wp_send_json( $result, 200 );
	}
}
