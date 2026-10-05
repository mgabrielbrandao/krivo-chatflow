<?php
/**
 * Dry run of a flow.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

use Krivo\ChatFlow\Automation\Actions\OpenWhatsapp;
use Krivo\ChatFlow\Automation\Actions\SendEmail;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Automation\Webhook\PayloadBuilder;
use Krivo\ChatFlow\Core\Routing\Resolver;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Core\Whatsapp\ButtonLink;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what a flow WOULD do with a sample lead: the WhatsApp message and link, every e-mail, every
 * webhook payload and the success message. It runs the same code as a real submission (the actions'
 * `compose()` and the payload builder) but sends nothing, stores nothing and fires no automation hook,
 * so "what you preview is what runs".
 */
final class DryRun {

	/**
	 * Previews a flow.
	 *
	 * @param int   $flow_id Flow id (0 for an unsaved flow).
	 * @param array $flow    Sanitised flow configuration.
	 * @return array
	 */
	public static function run( int $flow_id, array $flow ): array {
		if ( 'button' === $flow['mode'] ) {
			return self::run_button( $flow_id, $flow );
		}
		$lead   = SampleData::lead_for_flow( $flow );
		$values = array();
		foreach ( $lead['answers'] as $key => $answer ) {
			$values[ $key ] = $answer['value'];
		}
		$routing = Resolver::resolve(
			$flow_id,
			$flow,
			$values,
			array(
				'page_path' => '/sample-page/',
				'page_url'  => $lead['page_url'],
				'device'    => 'desktop',
			)
		);
		if ( null !== $routing['destination'] ) {
			$lead['destination_key']   = (string) $routing['destination']['id'];
			$lead['destination_label'] = (string) $routing['destination']['name'];
		} else {
			$lead['destination_label'] = '';
		}

		$vars = Builder::build(
			$flow,
			$lead['answers'],
			array(
				'tracking'    => array(
					'page_url'   => $lead['page_url'],
					'page_title' => $lead['page_title'],
					'utm_source' => $lead['utm_source'],
					'utm_medium' => $lead['utm_medium'],
				),
				'lead_id'     => '123',
				'destination' => $lead['destination_label'],
			)
		);

		$success = '' !== (string) $flow['finish']['success_message'] ? (string) $flow['finish']['success_message'] : __( 'Thank you! We received your message.', 'krivo-chatflow' );

		$report = array(
			'destination' => null === $routing['destination'] ? null : array(
				'name'     => $routing['destination']['name'],
				'reason'   => $routing['reason'],
				'whatsapp' => '' !== $routing['destination']['whatsapp'],
			),
			'whatsapp'    => null,
			'emails'      => array(),
			'webhooks'    => array(),
			'message'     => Renderer::render( $success, $vars, 'text' ),
			'variables'   => array(),
		);
		foreach ( Builder::catalog( $flow ) as $variable ) {
			if ( 'all_fields' === $variable['name'] ) {
				continue;
			}
			$report['variables'][ $variable['name'] ] = isset( $vars[ $variable['name'] ] ) && is_scalar( $vars[ $variable['name'] ] ) ? (string) $vars[ $variable['name'] ] : '';
		}

		foreach ( $flow['automations'] as $automation ) {
			if ( empty( $automation['enabled'] ) ) {
				continue;
			}
			$trigger = (string) $automation['trigger'];
			$ctx     = new RunContext( $flow_id, $flow, $lead, $trigger );

			if ( 'open_whatsapp' === $automation['type'] ) {
				$composed           = ( new OpenWhatsapp() )->compose( $ctx, (array) $automation['config'] );
				$report['whatsapp'] = array(
					'ok'      => null === $composed['skip'],
					'message' => $composed['message'],
					'url'     => $composed['url'],
					'reason'  => null === $composed['skip'] ? '' : $composed['skip']->error_message,
				);
			} elseif ( 'send_email' === $automation['type'] ) {
				$composed = ( new SendEmail() )->compose( $ctx, (array) $automation['config'] );
				$entry    = array(
					'automation_id' => $automation['id'],
					'trigger'       => $trigger,
					'ok'            => null === $composed['skip'],
					'reason'        => null === $composed['skip'] ? '' : $composed['skip']->error_message,
				);
				if ( null !== $composed['args'] ) {
					$entry['to']       = array_values( (array) $composed['args']['to'] );
					$entry['subject']  = (string) $composed['args']['subject'];
					$entry['text']     = (string) $composed['args']['text'];
					$entry['reply_to'] = (string) ( $composed['args']['options']['reply_to'] ?? '' );
				}
				$report['emails'][] = $entry;
			} elseif ( 'send_webhook' === $automation['type'] ) {
				$connection           = ConnectionRepository::find( (string) ( $automation['config']['connection_id'] ?? '' ) );
				$report['webhooks'][] = array(
					'automation_id' => $automation['id'],
					'trigger'       => $trigger,
					'connection'    => null === $connection ? '' : (string) $connection['name'],
					'enabled'       => null !== $connection && ! empty( $connection['enabled'] ),
					'payload'       => PayloadBuilder::build( $ctx, (array) $automation['config'], $trigger, 'rn_preview' ),
				);
			}
		}
		return $report;
	}

	/**
	 * Previews a "button only" flow: the link the button would open for a visitor on a sample page.
	 *
	 * @param int   $flow_id Flow id (0 for an unsaved flow).
	 * @param array $flow    Sanitised flow configuration.
	 * @return array Same shape as run().
	 */
	private static function run_button( int $flow_id, array $flow ): array {
		$tracking = array(
			'page_url'   => home_url( '/sample-page/' ),
			'page_path'  => '/sample-page/',
			'page_title' => __( 'Sample page', 'krivo-chatflow' ),
			'device'     => 'desktop',
		);
		$report   = array(
			'destination' => null,
			'whatsapp'    => null,
			'emails'      => array(),
			'webhooks'    => array(),
			'message'     => '',
			'variables'   => array(),
		);
		if ( 'whatsapp' !== $flow['button']['action'] ) {
			return $report;
		}
		$link = ButtonLink::compose( $flow_id, $flow, $tracking );
		if ( null !== $link['destination'] ) {
			$report['destination'] = array(
				'name'     => $link['destination']['name'],
				'reason'   => '',
				'whatsapp' => '' !== $link['destination']['whatsapp'],
			);
		}
		$report['whatsapp'] = array(
			'ok'      => '' !== $link['url'],
			'message' => $link['message'],
			'url'     => $link['url'],
			'reason'  => $link['reason'],
		);
		return $report;
	}
}
