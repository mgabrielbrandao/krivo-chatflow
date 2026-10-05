<?php
/**
 * Public REST routes.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Submission\AntiSpam\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * `krivo-chatflow/v1/public/*`: the only endpoints a logged-out visitor can reach.
 *
 * A WordPress nonce is NOT the defence here (it is identical for every visitor, expires and goes
 * stale in cached pages, and is not anti-spam). The defence is the layered guard: Origin check,
 * signed short-lived token with a minimum age, honeypot, rate limit and a per-flow circuit breaker.
 * The permission callbacks are real methods, never `__return_true`.
 */
final class PublicController {

	public const NAMESPACE = 'krivo-chatflow/v1';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/flows/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => array( __CLASS__, 'permit_origin' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'v'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'lang' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/session',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'session' ),
				'permission_callback' => array( __CLASS__, 'permit_origin' ),
				'args'                => array(
					'flow' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'events' ),
				'permission_callback' => array( __CLASS__, 'permit_origin' ),
				'args'                => array(
					'flow'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'events' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
						'maxItems' => 5,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/submissions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => array( __CLASS__, 'permit_submission' ),
				'args'                => array(
					'flow'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'token'   => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 400,
					),
					'answers' => array(
						'type'     => 'object',
						'required' => true,
					),
					'context' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'hp'      => array(
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission for the light endpoints: the Origin header, when sent, must be this site.
	 *
	 * @return true|\WP_Error
	 */
	public static function permit_origin() {
		if ( ! Guard::origin_ok() ) {
			return new \WP_Error( 'krivo_chatflow_origin', __( 'This request was not accepted.', 'krivo-chatflow' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission for submissions: Origin plus a size limit before anything is parsed further.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permit_submission( \WP_REST_Request $request ) {
		$origin = self::permit_origin();
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}
		if ( strlen( (string) $request->get_body() ) > PublicApi::MAX_BODY_BYTES ) {
			return new \WP_Error( 'krivo_chatflow_too_large', __( 'The message is too large.', 'krivo-chatflow' ), array( 'status' => 413 ) );
		}
		return true;
	}

	/**
	 * GET manifest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function manifest( \WP_REST_Request $request ) {
		$result = PublicApi::manifest( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		$response = new \WP_REST_Response( $result['flow'], 200 );
		$response->header( 'ETag', '"kcf-' . (int) $request['id'] . '-' . (int) $result['rev'] . '"' );
		// Marks the response as cacheable; the Cache-Control header itself is set in rest_post_dispatch().
		$response->header( 'X-Krivo-Chatflow-Cacheable', '1' );
		return $response;
	}

	/**
	 * POST session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function session( \WP_REST_Request $request ) {
		$result = PublicApi::session( (int) $request['flow'] );
		return is_wp_error( $result ) ? self::error( $result ) : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function events( \WP_REST_Request $request ) {
		$result = PublicApi::events( (int) $request['flow'], (array) $request['events'] );
		return is_wp_error( $result ) ? self::error( $result ) : new \WP_REST_Response( null, 204 );
	}

	/**
	 * POST submissions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( \WP_REST_Request $request ) {
		$result = PublicApi::submit(
			array(
				'flow'    => (int) $request['flow'],
				'token'   => (string) $request['token'],
				'answers' => (array) $request['answers'],
				'context' => (array) $request['context'],
				'hp'      => $request['hp'],
			)
		);
		return is_wp_error( $result ) ? self::error( $result ) : new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Cache headers for the manifest. The REST server sends "no-cache" only to logged-in users; for
	 * visitors we make the cacheable manifest explicit, and we never let anything else be cached.
	 *
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Server   $server  Server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_HTTP_Response
	 */
	public static function cache_headers( $result, $server, $request ) {
		if ( ! $result instanceof \WP_HTTP_Response || 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE . '/public/' ) ) {
			return $result;
		}
		$headers = $result->get_headers();
		if ( isset( $headers['X-Krivo-Chatflow-Cacheable'] ) && 200 === $result->get_status() && ! is_user_logged_in() ) {
			$result->header( 'Cache-Control', 'public, max-age=300, stale-while-revalidate=60' );
			$result->header( 'Vary', 'Accept-Encoding' );
		} elseif ( ! isset( $headers['Cache-Control'] ) || is_user_logged_in() ) {
			$result->header( 'Cache-Control', 'no-store' );
		}
		unset( $result->headers['X-Krivo-Chatflow-Cacheable'] );
		return $result;
	}

	/**
	 * Converts a domain error to a REST error carrying the public status, message and field errors.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private static function error( \WP_Error $error ): \WP_REST_Response {
		$mapped   = PublicApi::error_response( $error );
		$response = new \WP_REST_Response( $mapped['body'], $mapped['status'] );
		foreach ( $mapped['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
