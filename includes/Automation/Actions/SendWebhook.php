<?php
/**
 * Action: send webhook.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Automation\Webhook\Executor;
use Krivo\ChatFlow\Automation\Webhook\PayloadBuilder;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Posts the lead as JSON to a configured connection (n8n, Make, Zapier or any HTTP endpoint).
 * Asynchronous: the visitor never waits for, or sees a failure of, an external service.
 */
final class SendWebhook implements ActionInterface {

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'send_webhook';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Send webhook', 'krivo-chatflow' );
	}

	/**
	 * Mode.
	 *
	 * @return string
	 */
	public function mode(): string {
		return 'async';
	}

	/**
	 * Triggers.
	 *
	 * @return string[]
	 */
	public function triggers(): array {
		return array_keys( Registry::triggers() );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'connection_id' => '',
			'payload'       => array(
				'mode'     => 'default',
				'template' => '',
			),
		);
	}

	/**
	 * Sanitises the configuration. The custom template is kept as typed (it is only ever decoded as
	 * JSON, never printed as HTML); an invalid one falls back to the default payload at run time and is
	 * reported as an error before publishing.
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	public function sanitize( array $config ): array {
		$id       = isset( $config['connection_id'] ) ? (string) $config['connection_id'] : '';
		$payload  = is_array( $config['payload'] ?? null ) ? $config['payload'] : array();
		$template = isset( $payload['template'] ) && is_string( $payload['template'] ) ? $payload['template'] : '';
		$template = Text::limit( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $template ), 6000 );

		return array(
			'connection_id' => preg_match( '/^con_[a-z0-9]{4,16}$/', $id ) ? $id : '',
			'payload'       => array(
				'mode'     => Sanitizer::enum( $payload['mode'] ?? '', array( 'default', 'custom' ), 'default' ),
				'template' => $template,
			),
		);
	}

	/**
	 * Sends the webhook.
	 *
	 * @param RunContext $context Context.
	 * @param array      $config  Configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result {
		$connection = ConnectionRepository::runtime( (string) ( $config['connection_id'] ?? '' ) );
		if ( null === $connection || ! $connection['enabled'] ) {
			// Disabled connection: ZERO outbound requests.
			return Result::skipped( 'connection_disabled', __( 'The connection is turned off or was removed.', 'krivo-chatflow' ) );
		}

		$delivery = 'rn_' . $context->run_id . '_' . substr( (string) ( $context->lead['public_id'] ?? '' ), 0, 8 );
		$payload  = PayloadBuilder::build( $context, $config, $context->trigger, $delivery );
		$response = Executor::send( $connection, $payload, $context->trigger, $delivery );

		$extra = array(
			'http_status' => $response['status'],
			'duration_ms' => $response['duration_ms'],
		);
		if ( $response['ok'] ) {
			return Result::success( $extra );
		}
		return Result::failure(
			$response['category'],
			ConnectionRepository::redact( $response['summary'], $connection ),
			$response['retry'],
			$extra
		);
	}
}
