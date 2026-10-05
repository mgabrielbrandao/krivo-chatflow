<?php
/**
 * Public API services.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Core\Submission\AntiSpam\Guard;
use Krivo\ChatFlow\Core\Submission\AntiSpam\Token;
use Krivo\ChatFlow\Core\Submission\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * What the visitor's browser can ask for. These services are shared by the REST routes and by the
 * admin-ajax fallback (used when a security plugin or host blocks the REST API), so both entry points
 * apply exactly the same checks.
 *
 * Nothing here returns a secret, an internal e-mail address, a routing rule or another visitor's data.
 */
final class PublicApi {

	public const MAX_BODY_BYTES = 65536;
	public const EVENTS         = array( 'view', 'start' );

	/**
	 * The public manifest of a published flow.
	 *
	 * @param int    $flow_id Flow id.
	 * @param string $locale  Language the page is in (optional).
	 * @return array|\WP_Error { rev, flow }
	 */
	public static function manifest( int $flow_id, string $locale = '' ) {
		$limited = Guard::light( 'manifest', $flow_id, 120 );
		if ( null !== $limited ) {
			return $limited;
		}
		$row = FlowRepository::published( $flow_id );
		if ( null === $row ) {
			return self::not_found();
		}
		// The page tells which language it is in, so a cached copy of the manifest never mixes languages.
		$switched = '' !== $locale
			&& 1 === preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})*$/', $locale )
			&& determine_locale() !== $locale
			&& ( 'en_US' === $locale || in_array( $locale, get_available_languages(), true ) )
			&& switch_to_locale( $locale );
		$flow     = Manifest::flow( $row['id'], $row['revision'], $row['config'] );
		if ( $switched ) {
			restore_previous_locale();
		}
		return array(
			'rev'  => $row['revision'],
			'flow' => $flow,
		);
	}

	/**
	 * Opens a session: issues the submission token and counts the "open" metric.
	 *
	 * @param int $flow_id Flow id.
	 * @return array|\WP_Error { token, min_wait_ms }
	 */
	public static function session( int $flow_id ) {
		$limited = Guard::light( 'session', $flow_id, 30 );
		if ( null !== $limited ) {
			return $limited;
		}
		if ( null === FlowRepository::published( $flow_id ) ) {
			return self::not_found();
		}
		StatsRepository::increment( $flow_id, StatsRepository::OPEN );
		return array(
			'token'       => Token::issue( $flow_id ),
			'min_wait_ms' => (int) Settings::get( 'antispam.min_seconds', 3 ) * 1000,
		);
	}

	/**
	 * Records funnel events reported by the browser. They are advisory (a robot can inflate them),
	 * which is why leads and WhatsApp clicks are counted by the server instead.
	 *
	 * @param int   $flow_id Flow id.
	 * @param array $events  Event names.
	 * @return true|\WP_Error
	 */
	public static function events( int $flow_id, array $events ) {
		$limited = Guard::light( 'events', $flow_id, 60 );
		if ( null !== $limited ) {
			return $limited;
		}
		if ( null === FlowRepository::published( $flow_id ) ) {
			return self::not_found();
		}
		foreach ( array_unique( array_slice( $events, 0, 5 ) ) as $event ) {
			if ( ! is_string( $event ) || ! in_array( $event, self::EVENTS, true ) ) {
				continue;
			}
			if ( 'view' === $event && ! Settings::get( 'analytics.count_views', true ) ) {
				continue;
			}
			StatsRepository::increment( $flow_id, $event );
		}
		return true;
	}

	/**
	 * Processes a submission.
	 *
	 * @param array $payload { flow, token, answers, context, hp }.
	 * @return array|\WP_Error
	 */
	public static function submit( array $payload ) {
		return Pipeline::handle( $payload );
	}

	/**
	 * Converts an error to the HTTP status and body that the browser receives. Internal detail
	 * never leaves: only the public code, a translated message and, for validation, the field errors.
	 *
	 * @param \WP_Error $error Error.
	 * @return array{status:int,body:array,headers:array}
	 */
	public static function error_response( \WP_Error $error ): array {
		$data    = (array) $error->get_error_data();
		$status  = isset( $data['status'] ) ? (int) $data['status'] : 400;
		$body    = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
		$headers = array();
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$body['errors'] = $data['errors'];
		}
		if ( ! empty( $data['retry_after'] ) ) {
			$headers['Retry-After'] = (string) (int) $data['retry_after'];
		}
		return array(
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Standard 404 for a flow that is missing, a draft or paused.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'krivo_chatflow_not_found', __( 'This chat is not available.', 'krivo-chatflow' ), array( 'status' => 404 ) );
	}
}
