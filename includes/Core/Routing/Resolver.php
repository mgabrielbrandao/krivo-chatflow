<?php
/**
 * Routing resolver.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Routing;

use Krivo\ChatFlow\Core\Conditions\Evaluator;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which destination receives a lead.
 *
 * Order: routing rules (first match wins; a rule may be limited to some flows), then the flow's own
 * default destination, then the site default. Developers can alter the result with the
 * `krivo_chatflow_routing_result` filter (this is where a Pro add-on plugs in round-robin attendants).
 */
final class Resolver {

	/**
	 * Resolves the destination.
	 *
	 * @param int   $flow_id Flow id.
	 * @param array $flow    Flow configuration.
	 * @param array $values  Answers by field key.
	 * @param array $ctx     Context (page_path, page_url, utm_*, device, in_hours).
	 * @return array{destination:?array,rule_id:?string,reason:string}
	 */
	public static function resolve( int $flow_id, array $flow, array $values, array $ctx ): array {
		$routing = RoutingRepository::get();
		$result  = array(
			'destination' => null,
			'rule_id'     => null,
			'reason'      => 'none',
		);

		foreach ( $routing['rules'] as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( array() !== $rule['flows'] && ! in_array( $flow_id, array_map( 'intval', $rule['flows'] ), true ) ) {
				continue;
			}
			if ( ! Evaluator::matches( (array) $rule['when'], $values, $ctx ) ) {
				continue;
			}
			$destination = RoutingRepository::destination( (string) $rule['destination'] );
			if ( null !== $destination ) {
				$result = array(
					'destination' => $destination,
					'rule_id'     => (string) $rule['id'],
					'reason'      => 'rule',
				);
				break;
			}
		}

		if ( null === $result['destination'] ) {
			$flow_default = isset( $flow['whatsapp']['default_destination'] ) ? (string) $flow['whatsapp']['default_destination'] : '';
			$destination  = RoutingRepository::destination( $flow_default );
			if ( null !== $destination ) {
				$result = array(
					'destination' => $destination,
					'rule_id'     => null,
					'reason'      => 'flow_default',
				);
			} else {
				$destination = RoutingRepository::destination( $routing['default_destination'] );
				if ( null !== $destination ) {
					$result = array(
						'destination' => $destination,
						'rule_id'     => null,
						'reason'      => 'site_default',
					);
				}
			}
		}

		/**
		 * Filters the routing result.
		 *
		 * @param array $result  {destination, rule_id, reason}.
		 * @param int   $flow_id Flow id.
		 * @param array $values  Answers by field key.
		 * @param array $ctx     Context.
		 */
		$filtered = apply_filters( 'krivo_chatflow_routing_result', $result, $flow_id, $values, $ctx );
		return is_array( $filtered ) && array_key_exists( 'destination', $filtered ) ? $filtered : $result;
	}
}
