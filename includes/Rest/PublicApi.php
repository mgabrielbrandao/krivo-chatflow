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
use Krivo\ChatFlow\Core\Submission\Tracking;
use Krivo\ChatFlow\Core\Whatsapp\ButtonLink;

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
	public const EVENTS         = array( 'view', 'start', 'click' );

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
		$row = FlowRepository::published( $flow_id );
		if ( null === $row || 'button' === $row['config']['mode'] ) {
			return self::not_found(); // A "button only" chat has no form, so there is nothing to submit.
		}
		StatsRepository::increment( $flow_id, StatsRepository::OPEN );
		return array(
			'token'       => Token::issue( $flow_id ),
			'min_wait_ms' => (int) Settings::get( 'antispam.min_seconds', 3 ) * 1000,
		);
	}

	/**
	 * Records funnel events reported by the browser. They are advisory (a robot can inflate them),
	 * which is why leads and WhatsApp clicks are counted by the server instead. A "click" is only
	 * accepted for a "button only" chat that opens a plain link (a WhatsApp button is counted by the
	 * server, in button()).
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
		$row = FlowRepository::published( $flow_id );
		if ( null === $row ) {
			return self::not_found();
		}
		foreach ( array_unique( array_slice( $events, 0, 5 ) ) as $event ) {
			if ( ! is_string( $event ) || ! in_array( $event, self::EVENTS, true ) ) {
				continue;
			}
			if ( 'view' === $event && ! Settings::get( 'analytics.count_views', true ) ) {
				continue;
			}
			if ( 'click' === $event ) {
				if ( 'button' === $row['config']['mode'] && 'link' === $row['config']['button']['action'] ) {
					StatsRepository::increment( $flow_id, StatsRepository::BUTTON_CLICK );
				}
				continue;
			}
			StatsRepository::increment( $flow_id, $event );
		}
		return true;
	}

	/**
	 * The visitor tapped the WhatsApp button of a "button only" chat: resolves the destination (routing
	 * rules included), builds the wa.me link, counts the click and hands the link to the browser. The
	 * destination number is therefore never printed in the page.
	 *
	 * @param int   $flow_id Flow id.
	 * @param array $context Page context reported by the browser (url, title, referrer, device, utm).
	 * @return array|\WP_Error { ok, url }
	 */
	public static function button( int $flow_id, array $context ) {
		$limited = Guard::light( 'button', $flow_id, 20 );
		if ( null !== $limited ) {
			return $limited;
		}
		$row = FlowRepository::published( $flow_id );
		if ( null === $row || 'button' !== $row['config']['mode'] || 'whatsapp' !== $row['config']['button']['action'] ) {
			return self::not_found();
		}

		$tracking = Tracking::sanitize( $context );
		$link     = ButtonLink::compose( $flow_id, $row['config'], $tracking );
		if ( '' === $link['url'] ) {
			return new \WP_Error( 'krivo_chatflow_unavailable', __( 'WhatsApp is not available right now. Please try again in a moment.', 'krivo-chatflow' ), array( 'status' => 503 ) );
		}

		StatsRepository::increment( $flow_id, StatsRepository::BUTTON_CLICK );
		StatsRepository::increment( $flow_id, StatsRepository::WHATSAPP_CLICK );
		/**
		 * Fires when a visitor taps the WhatsApp button of a "button only" chat (there is no lead).
		 *
		 * @param int    $flow_id  Flow id.
		 * @param string $url      The wa.me link handed to the browser.
		 * @param array  $tracking Page and campaign context.
		 */
		do_action( 'krivo_chatflow_button_clicked', $flow_id, $link['url'], $tracking );

		return array(
			'ok'  => true,
			'url' => $link['url'],
		);
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
