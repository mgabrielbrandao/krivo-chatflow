<?php
/**
 * Base for the administrative REST controllers.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers: capability checks (a real `permission_callback` on every route, never
 * `__return_true`), argument schemas and consistent responses.
 *
 * Authentication is the WordPress cookie plus the `X-WP-Nonce` header: without a valid nonce core treats
 * the request as anonymous and the capability check fails. The nonce protects against CSRF, the
 * capability authorises. The checks always run on the server; hiding a button is not a control.
 */
abstract class AdminController {

	public const NAMESPACE = 'krivo-chatflow/v1';

	/**
	 * Permission callback requiring one capability.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	protected static function can( string $cap ): callable {
		return static function () use ( $cap ) {
			return current_user_can( $cap ) ? true : self::forbidden();
		};
	}

	/**
	 * Permission callback requiring any of several capabilities.
	 *
	 * @param string[] $caps Capabilities.
	 * @return callable
	 */
	protected static function can_any( array $caps ): callable {
		return static function () use ( $caps ) {
			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}
			return self::forbidden();
		};
	}

	/**
	 * The error returned when the user is not allowed.
	 *
	 * @return \WP_Error
	 */
	protected static function forbidden(): \WP_Error {
		return new \WP_Error(
			'krivo_chatflow_forbidden',
			__( 'You are not allowed to do this.', 'krivo-chatflow' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Standard `id` route argument.
	 *
	 * @return array
	 */
	protected static function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Wraps data in a no-store response (admin data is never cached).
	 *
	 * @param mixed $data   Data.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	protected static function ok( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * A not-found error.
	 *
	 * @param string $what Human description.
	 * @return \WP_Error
	 */
	protected static function not_found( string $what = '' ): \WP_Error {
		return new \WP_Error(
			'krivo_chatflow_not_found',
			'' !== $what ? $what : __( 'Not found.', 'krivo-chatflow' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Invalid-request error.
	 *
	 * @param string $message Message for the administrator.
	 * @param array  $data    Extra data.
	 * @return \WP_Error
	 */
	protected static function invalid( string $message, array $data = array() ): \WP_Error {
		return new \WP_Error( 'krivo_chatflow_invalid', $message, array_merge( array( 'status' => 400 ), $data ) );
	}

	/**
	 * Date argument (Y-m-d).
	 *
	 * @return array
	 */
	protected static function date_arg(): array {
		return array(
			'type'              => 'string',
			'validate_callback' => static function ( $value ) {
				return '' === $value || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? true : new \WP_Error( 'rest_invalid_param', __( 'Use the format YYYY-MM-DD.', 'krivo-chatflow' ) );
			},
		);
	}
}
