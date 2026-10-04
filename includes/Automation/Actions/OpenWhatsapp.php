<?php
/**
 * Action: open WhatsApp.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Core\Whatsapp\UrlBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the visitor's browser to open WhatsApp with the message already written.
 *
 * Runs inside the submit request and does no external I/O: it only builds a wa.me link. The browser
 * opens it after the visitor's own click; the server never contacts WhatsApp.
 */
final class OpenWhatsapp implements ActionInterface {

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'open_whatsapp';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Open WhatsApp', 'krivo-chatflow' );
	}

	/**
	 * Mode.
	 *
	 * @return string
	 */
	public function mode(): string {
		return 'sync';
	}

	/**
	 * Triggers (only those that fire during the visitor's request).
	 *
	 * @return string[]
	 */
	public function triggers(): array {
		return array( Registry::FORM_SUBMITTED, Registry::LEAD_CREATED, Registry::ROUTING_MATCHED, Registry::FLOW_COMPLETED );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array( 'message' => '' );
	}

	/**
	 * Sanitises the configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	public function sanitize( array $config ): array {
		return array( 'message' => Sanitizer::textarea( $config['message'] ?? '', 1000 ) );
	}

	/**
	 * Builds the WhatsApp directive.
	 *
	 * @param RunContext $context Context.
	 * @param array      $config  Configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result {
		$destination = RoutingRepository::destination( (string) ( $context->lead['destination_key'] ?? '' ) );
		$digits      = null !== $destination ? (string) $destination['whatsapp'] : '';
		if ( '' === $digits ) {
			return Result::skipped( 'no_whatsapp', __( 'The routed destination has no WhatsApp number.', 'krivo-chatflow' ) );
		}

		$template = '' !== (string) ( $config['message'] ?? '' ) ? (string) $config['message'] : (string) ( $context->flow['whatsapp']['message'] ?? '' );
		if ( '' === trim( $template ) ) {
			$template = self::default_message();
		}
		$message = Renderer::render( $template, $context->vars(), 'text' );

		/**
		 * Filters the WhatsApp message text.
		 *
		 * @param string $message Rendered message.
		 * @param array  $lead    Lead.
		 * @param array  $flow    Flow configuration.
		 */
		$message = (string) apply_filters( 'krivo_chatflow_whatsapp_message', $message, $context->lead, $context->flow );

		$url = UrlBuilder::build( $digits, $message );
		/**
		 * Filters the WhatsApp URL (must stay on https://wa.me/ to be opened by the widget).
		 *
		 * @param string $url     URL.
		 * @param array  $lead    Lead.
		 * @param array  $flow    Flow configuration.
		 */
		$url = (string) apply_filters( 'krivo_chatflow_whatsapp_url', $url, $context->lead, $context->flow );

		if ( 0 !== strpos( $url, UrlBuilder::HOST ) ) {
			return Result::skipped( 'bad_url', __( 'The WhatsApp link was not generated.', 'krivo-chatflow' ) );
		}

		/**
		 * Fires right before the WhatsApp redirect is handed to the visitor's browser.
		 *
		 * @param string $url  URL.
		 * @param array  $lead Lead.
		 */
		do_action( 'krivo_chatflow_before_whatsapp_redirect', $url, $context->lead );

		return Result::success(
			array(
				'directive' => array(
					'type' => 'open_whatsapp',
					'url'  => $url,
				),
			)
		);
	}

	/**
	 * Message used when the flow does not define one.
	 *
	 * @return string
	 */
	public static function default_message(): string {
		return __( "Hello! My name is {name}.\n\n{all_fields}\n\nPage: {page_url}", 'krivo-chatflow' );
	}
}
