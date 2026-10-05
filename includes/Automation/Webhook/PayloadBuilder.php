<?php
/**
 * Webhook payloads.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Webhook;

use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON sent to the receiving service.
 *
 *  - default: a stable, versioned structure with everything about the lead.
 *  - custom:  a JSON template written by the administrator, with {variables}. The template is decoded
 *             as JSON first and variables are substituted only inside string VALUES, so what a visitor
 *             types can never break the structure, and literal numbers/booleans/null stay as typed.
 *             Mapping "telefone" to "phone_number" is just writing "phone_number": "{telefone}".
 */
final class PayloadBuilder {

	/**
	 * Builds the payload for a lead.
	 *
	 * @param RunContext $ctx         Context.
	 * @param array      $config      Action configuration (payload.mode, payload.template).
	 * @param string     $event       Event name (form.submitted...).
	 * @param string     $delivery_id Delivery id (stable across retries).
	 * @return array
	 */
	public static function build( RunContext $ctx, array $config, string $event, string $delivery_id ): array {
		$mode    = (string) ( $config['payload']['mode'] ?? 'default' );
		$payload = null;
		if ( 'custom' === $mode ) {
			$payload = self::custom( (string) ( $config['payload']['template'] ?? '' ), $ctx, $event );
		}
		if ( null === $payload ) {
			$payload = self::default_payload( $ctx, $event, $delivery_id );
		}

		/**
		 * Filters the webhook payload.
		 *
		 * @param array      $payload Payload.
		 * @param RunContext $ctx     Context.
		 * @param string     $event   Event name.
		 */
		return (array) apply_filters( 'krivo_chatflow_webhook_payload', $payload, $ctx, $event );
	}

	/**
	 * The default payload.
	 *
	 * @param RunContext $ctx         Context.
	 * @param string     $event       Event name.
	 * @param string     $delivery_id Delivery id.
	 * @return array
	 */
	public static function default_payload( RunContext $ctx, string $event, string $delivery_id ): array {
		$lead    = $ctx->lead;
		$answers = $ctx->answers();
		$values  = array();
		$labeled = array();
		foreach ( $answers as $key => $answer ) {
			$value          = is_array( $answer ) && array_key_exists( 'value', $answer ) ? $answer['value'] : $answer;
			$values[ $key ] = $value;
			$labeled[]      = array(
				'key'     => (string) $key,
				'label'   => (string) ( $answer['label'] ?? $key ),
				'value'   => $value,
				'display' => (string) ( $answer['display'] ?? ( is_scalar( $value ) ? $value : '' ) ),
			);
		}
		$vars = $ctx->vars();

		$payload = array(
			'event'           => $event,
			'delivery_id'     => $delivery_id,
			'occurred_at'     => gmdate( 'Y-m-d\TH:i:s\Z', Clock::time() ),
			'site'            => array(
				'url'  => home_url(),
				'name' => (string) $vars['site_name'],
			),
			'flow'            => array(
				'id'   => $ctx->flow_id,
				'name' => (string) ( $ctx->flow['name'] ?? '' ),
			),
			'lead'            => array(
				'id'          => (int) ( $lead['id'] ?? 0 ),
				'public_id'   => (string) ( $lead['public_id'] ?? '' ),
				'status'      => (string) ( $lead['status'] ?? '' ),
				'channel'     => (string) ( $lead['channel'] ?? '' ),
				'name'        => (string) ( $lead['name'] ?? '' ),
				'email'       => (string) ( $lead['email'] ?? '' ),
				'phone'       => (string) ( $lead['phone'] ?? '' ),
				'destination' => array(
					'key'   => (string) ( $lead['destination_key'] ?? '' ),
					'label' => (string) ( $lead['destination_label'] ?? '' ),
				),
				'in_hours'    => $lead['in_hours'] ?? null,
				'created_at'  => Clock::iso( (string) ( $lead['created_at'] ?? '' ) ),
			),
			'answers'         => (object) $values,
			'answers_labeled' => $labeled,
			'tracking'        => $ctx->tracking(),
			'consent'         => array_values( (array) ( $lead['consent'] ?? array() ) ),
		);

		if ( isset( $ctx->data['changes'] ) ) {
			$payload['changes'] = $ctx->data['changes'];
		}
		return $payload;
	}

	/**
	 * Renders a custom JSON template, or returns null when it is not valid JSON.
	 *
	 * @param string     $template JSON template.
	 * @param RunContext $ctx      Context.
	 * @param string     $event    Event name.
	 * @return array|null
	 */
	public static function custom( string $template, RunContext $ctx, string $event ): ?array {
		$decoded = json_decode( $template, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$vars              = $ctx->vars();
		$vars['event']     = $event;
		$vars['flow_id']   = (string) $ctx->flow_id;
		$vars['lead_uuid'] = (string) ( $ctx->lead['public_id'] ?? '' );
		return self::walk( $decoded, $vars );
	}

	/**
	 * Substitutes variables in every string value of a decoded structure.
	 *
	 * @param mixed $node Node.
	 * @param array $vars Variables.
	 * @return mixed
	 */
	private static function walk( $node, array $vars ) {
		if ( is_array( $node ) ) {
			$out = array();
			foreach ( $node as $key => $value ) {
				$out[ $key ] = self::walk( $value, $vars );
			}
			return $out;
		}
		if ( is_string( $node ) ) {
			return Renderer::render( $node, $vars, 'text' );
		}
		return $node;
	}
}
