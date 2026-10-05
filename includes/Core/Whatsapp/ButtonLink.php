<?php
/**
 * WhatsApp link for the "button only" mode.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Whatsapp;

use Krivo\ChatFlow\Core\Flow\Hours;
use Krivo\ChatFlow\Core\Routing\Resolver;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Core\Variables\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the click-to-chat link for a flow that has no form. There is no lead and no answer: the
 * destination comes from the routing rules (which can still look at the page, the campaign, the
 * device and the business hours), and the message may use the page and campaign variables.
 *
 * Same rule as everywhere else: nothing is sent by the site. This only builds a https://wa.me/ link
 * that the visitor's own browser opens after an explicit click.
 */
final class ButtonLink {

	/**
	 * Message used when the flow does not define one.
	 *
	 * @return string
	 */
	public static function default_message(): string {
		return __( "Hello! I am visiting your website and would like to talk.\n\nPage: {page_url}", 'krivo-chatflow' );
	}

	/**
	 * Works out the destination, the text and the link.
	 *
	 * @param int   $flow_id  Flow id.
	 * @param array $flow     Flow configuration.
	 * @param array $tracking Sanitised tracking context (Tracking::sanitize).
	 * @return array{url:string,message:string,destination:?array,reason:string} "reason" is empty on success.
	 */
	public static function compose( int $flow_id, array $flow, array $tracking ): array {
		$in_hours = Hours::is_open( (array) ( $flow['hours'] ?? array() ) );
		$ctx      = array_merge( $tracking, array( 'in_hours' => null === $in_hours ? '' : ( $in_hours ? 'yes' : 'no' ) ) );

		$routing     = Resolver::resolve( $flow_id, $flow, array(), $ctx );
		$destination = $routing['destination'];
		if ( null === $destination || '' === (string) $destination['whatsapp'] ) {
			return array(
				'url'         => '',
				'message'     => '',
				'destination' => $destination,
				'reason'      => __( 'The destination chosen for this chat has no WhatsApp number.', 'krivo-chatflow' ),
			);
		}

		$template = trim( (string) ( $flow['whatsapp']['message'] ?? '' ) );
		if ( '' === $template ) {
			$template = self::default_message();
		}
		$vars    = Builder::build(
			$flow,
			array(),
			array(
				'tracking'    => $tracking,
				'destination' => (string) $destination['name'],
			)
		);
		$message = Renderer::render( $template, $vars, 'text' );

		/** This filter is documented in includes/Automation/Actions/OpenWhatsapp.php */
		$message = (string) apply_filters( 'krivo_chatflow_whatsapp_message', $message, array(), $flow );

		$url = UrlBuilder::build( (string) $destination['whatsapp'], $message );
		/** This filter is documented in includes/Automation/Actions/OpenWhatsapp.php */
		$url = (string) apply_filters( 'krivo_chatflow_whatsapp_url', $url, array(), $flow );

		if ( 0 !== strpos( $url, UrlBuilder::HOST ) ) {
			return array(
				'url'         => '',
				'message'     => $message,
				'destination' => $destination,
				'reason'      => __( 'The WhatsApp link was not generated.', 'krivo-chatflow' ),
			);
		}
		return array(
			'url'         => $url,
			'message'     => $message,
			'destination' => $destination,
			'reason'      => '',
		);
	}
}
