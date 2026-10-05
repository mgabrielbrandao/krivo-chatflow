<?php
/**
 * Submission pipeline.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission;

use Krivo\ChatFlow\Automation\Engine;
use Krivo\ChatFlow\Automation\Registry as AutomationRegistry;
use Krivo\ChatFlow\Core\Fields\Registry as FieldRegistry;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\Resolver;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Core\Submission\AntiSpam\Guard;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Handles a visitor's submission from start to finish.
 *
 *  1 input and size       2 flow            3 guard (token, honeypot, rate limit, origin)
 *  4 validation           5 tracking        6 duplicate suppression
 *  7 routing              8 spam            9 save the lead
 * 10 triggers             11 actions        12 response
 *
 * Nothing external happens here: e-mail and webhooks are queued, WhatsApp is only a link.
 */
final class Pipeline {

	/**
	 * Processes a submission.
	 *
	 * @param array $payload { flow, token, answers, context, hp }.
	 * @return array|\WP_Error Response for the browser or an error.
	 */
	public static function handle( array $payload ) {
		$flow_id = (int) ( $payload['flow'] ?? 0 );
		$row     = FlowRepository::published( $flow_id );
		if ( null === $row ) {
			return new \WP_Error( 'krivo_chatflow_not_found', __( 'This chat is not available.', 'krivo-chatflow' ), array( 'status' => 404 ) );
		}
		$flow = $row['config'];

		/**
		 * Fires before a submission is processed.
		 *
		 * @param array $payload Raw submission.
		 * @param array $flow    Flow configuration.
		 */
		do_action( 'krivo_chatflow_before_submit', $payload, $flow );

		$guard = Guard::submission( $flow_id, (string) ( $payload['token'] ?? '' ), is_scalar( $payload['hp'] ?? '' ) ? (string) ( $payload['hp'] ?? '' ) : 'x' );
		if ( 'error' === $guard['status'] ) {
			return $guard['error'];
		}
		if ( 'silent' === $guard['status'] ) {
			// A robot filled the honeypot: answer like a success and store nothing.
			return self::response( $flow, array(), array(), array(), null );
		}

		$tracking  = Tracking::sanitize( is_array( $payload['context'] ?? null ) ? $payload['context'] : array() );
		$validated = SubmissionValidator::validate( $flow, is_array( $payload['answers'] ?? null ) ? $payload['answers'] : array(), $tracking );
		if ( array() !== $validated['errors'] ) {
			return new \WP_Error(
				'krivo_chatflow_validation',
				__( 'Please check the highlighted fields.', 'krivo-chatflow' ),
				array(
					'status' => 400,
					'errors' => $validated['errors'],
				)
			);
		}

		/**
		 * Filters the validated submission before it is saved.
		 *
		 * @param array $validated { values, answers, errors, finished, in_hours, consent, ctx }.
		 * @param array $flow      Flow configuration.
		 * @param array $tracking  Tracking context.
		 */
		$validated = apply_filters( 'krivo_chatflow_submission_data', $validated, $flow, $tracking );

		// Identity columns: the first field of each kind.
		$identity = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
		);
		foreach ( Builder::fields( $flow ) as $field ) {
			$key = $field['key'];
			if ( ! isset( $validated['answers'][ $key ] ) ) {
				continue;
			}
			$display = (string) $validated['answers'][ $key ]['display'];
			if ( 'name' === $field['type'] && '' === $identity['name'] ) {
				$identity['name'] = $display;
			} elseif ( 'email' === $field['type'] && '' === $identity['email'] ) {
				$identity['email'] = $display;
			} elseif ( in_array( $field['type'], array( 'phone', 'whatsapp' ), true ) && '' === $identity['phone'] ) {
				$identity['phone'] = $display;
			}
		}

		// Duplicate suppression: a retry of the same submission is not a new lead.
		$fingerprint = md5( $flow_id . '|' . $identity['email'] . '|' . $identity['phone'] . '|' . md5( wp_json_encode( $validated['values'] ) ) );
		$existing    = get_transient( 'krivo_chatflow_dup_' . $fingerprint );
		if ( is_string( $existing ) && '' !== $existing ) {
			$lead = LeadRepository::get_by_public_id( $existing );
			if ( null !== $lead ) {
				$directives = Engine::dispatch( AutomationRegistry::FORM_SUBMITTED, $flow_id, $flow, $lead, array(), true );
				return self::response( $flow, $lead, $validated, $directives, $lead );
			}
		}

		$routing = Resolver::resolve( $flow_id, $flow, $validated['values'], $validated['ctx'] );
		$dest    = $routing['destination'];

		$is_spam = $guard['breaker'] || self::looks_like_spam( $validated['values'] );
		/**
		 * Filters whether a submission is held as spam.
		 *
		 * @param bool  $is_spam   Verdict so far.
		 * @param array $validated Validated submission.
		 * @param array $tracking  Tracking context.
		 * @param array $flow      Flow configuration.
		 */
		$is_spam = (bool) apply_filters( 'krivo_chatflow_is_spam', $is_spam, $validated, $tracking, $flow );

		$uses_whatsapp = false;
		foreach ( $flow['automations'] as $automation ) {
			$uses_whatsapp = $uses_whatsapp || ( 'open_whatsapp' === $automation['type'] && $automation['enabled'] );
		}

		$lead_id = LeadRepository::insert(
			array(
				'flow_id'           => $flow_id,
				'flow_revision'     => $row['revision'],
				'status'            => $is_spam ? 'spam' : 'new',
				'name'              => Text::limit( $identity['name'], 190 ),
				'email'             => Text::limit( $identity['email'], 190 ),
				'phone'             => Text::limit( $identity['phone'], 32 ),
				'destination_key'   => null !== $dest ? (string) $dest['id'] : '',
				'destination_label' => null !== $dest ? (string) $dest['name'] : '',
				'channel'           => $uses_whatsapp ? 'whatsapp' : 'message',
				'source'            => 'widget',
				'in_hours'          => $validated['in_hours'],
				'device'            => $tracking['device'],
				'answers'           => $validated['answers'],
				'consent'           => $validated['consent'],
				'page_url'          => $tracking['page_url'],
				'page_title'        => $tracking['page_title'],
				'referrer'          => $tracking['referrer'],
				'utm_source'        => $tracking['utm_source'],
				'utm_medium'        => $tracking['utm_medium'],
				'utm_campaign'      => $tracking['utm_campaign'],
				'utm_content'       => $tracking['utm_content'],
				'utm_term'          => $tracking['utm_term'],
			)
		);
		if ( $lead_id <= 0 ) {
			return new \WP_Error( 'krivo_chatflow_storage', __( 'We could not save your message. Please try again.', 'krivo-chatflow' ), array( 'status' => 503 ) );
		}

		$lead = (array) LeadRepository::get( $lead_id );
		set_transient( 'krivo_chatflow_dup_' . $fingerprint, $lead['public_id'], 10 * MINUTE_IN_SECONDS );
		StatsRepository::increment( $flow_id, StatsRepository::LEAD );

		$directives = array();
		if ( ! $is_spam ) {
			$directives = array_merge( $directives, Engine::dispatch( AutomationRegistry::LEAD_CREATED, $flow_id, $flow, $lead ) );
			if ( null !== $routing['rule_id'] ) {
				$directives = array_merge( $directives, Engine::dispatch( AutomationRegistry::ROUTING_MATCHED, $flow_id, $flow, $lead, array( 'rule_id' => $routing['rule_id'] ) ) );
			}
			$directives = array_merge( $directives, Engine::dispatch( AutomationRegistry::FORM_SUBMITTED, $flow_id, $flow, $lead ) );

			$whatsapp = false;
			foreach ( $directives as $directive ) {
				$whatsapp = $whatsapp || 'open_whatsapp' === ( $directive['type'] ?? '' );
			}
			if ( $whatsapp ) {
				StatsRepository::increment( $flow_id, StatsRepository::WHATSAPP_CLICK );
				$directives = array_merge( $directives, Engine::dispatch( AutomationRegistry::WHATSAPP_CLICKED, $flow_id, $flow, $lead ) );
			}
			$directives = array_merge( $directives, Engine::dispatch( AutomationRegistry::FLOW_COMPLETED, $flow_id, $flow, $lead ) );
		}

		/**
		 * Fires when a lead was created (also for leads held as spam; check the status).
		 *
		 * @param array $lead Lead.
		 */
		do_action( 'krivo_chatflow_lead_created', $lead );

		$response = self::response( $flow, $lead, $validated, $directives, null );

		/**
		 * Fires after a submission was fully processed.
		 *
		 * @param array $lead     Lead.
		 * @param array $response Response sent to the browser.
		 */
		do_action( 'krivo_chatflow_after_submit', $lead, $response );

		return $response;
	}

	/**
	 * Builds the response for the browser. Contains nothing private: no e-mails, no routing details.
	 *
	 * @param array      $flow       Flow configuration.
	 * @param array      $lead       Lead (empty for a honeypot hit).
	 * @param array      $validated  Validated submission.
	 * @param array      $directives Directives from sync actions.
	 * @param array|null $duplicate  Existing lead when this was a retry.
	 * @return array
	 */
	private static function response( array $flow, array $lead, array $validated, array $directives, ?array $duplicate ): array {
		$message = '' !== $flow['finish']['success_message'] ? $flow['finish']['success_message'] : __( 'Thank you! We received your message.', 'krivo-chatflow' );
		if ( array() !== $lead ) {
			$vars    = Builder::build(
				$flow,
				(array) $lead['answers'],
				array(
					'tracking'    => array(
						'page_url'   => $lead['page_url'],
						'page_title' => $lead['page_title'],
					),
					'lead_id'     => $lead['id'],
					'destination' => $lead['destination_label'],
				)
			);
			$message = Renderer::render( $message, $vars, 'text' );
		} else {
			$message = Renderer::render( $message, array(), 'text' );
		}

		return array(
			'ok'         => true,
			'lead'       => array() !== $lead ? (string) $lead['public_id'] : '',
			'message'    => $message,
			'directives' => array_values( $directives ),
			'duplicate'  => null !== $duplicate,
		);
	}

	/**
	 * Cheap content heuristics: many links in the answers is the classic spam signature.
	 *
	 * @param array $values Normalised values.
	 * @return bool
	 */
	private static function looks_like_spam( array $values ): bool {
		$links = 0;
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$links += (int) preg_match_all( '#https?://|www\.#i', $value );
			}
		}
		return $links > 3;
	}
}
