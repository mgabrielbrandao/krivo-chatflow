<?php
/**
 * Action: route lead.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Re-evaluates the routing rules for an existing lead and updates its destination. Useful after the
 * rules were edited or when a lead is changed.
 */
final class RouteLead implements ActionInterface {

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'route_lead';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Route the lead again', 'krivo-chatflow' );
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
	 * Triggers.
	 *
	 * @return string[]
	 */
	public function triggers(): array {
		return array( Registry::LEAD_UPDATED );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array();
	}

	/**
	 * Sanitises the configuration (it has none).
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	public function sanitize( array $config ): array {
		return array();
	}

	/**
	 * Re-routes the lead.
	 *
	 * @param RunContext $context Context.
	 * @param array      $config  Configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result {
		$lead_id = (int) ( $context->lead['id'] ?? 0 );
		if ( $lead_id <= 0 ) {
			return Result::skipped( 'no_lead' );
		}
		$values = array();
		foreach ( $context->answers() as $key => $answer ) {
			$values[ $key ] = is_array( $answer ) && array_key_exists( 'value', $answer ) ? $answer['value'] : $answer;
		}
		$tracking = $context->tracking();
		$result   = Resolver::resolve(
			$context->flow_id,
			$context->flow,
			$values,
			array_merge(
				$tracking,
				array(
					'page_path' => (string) wp_parse_url( $tracking['page_url'], PHP_URL_PATH ),
				)
			)
		);

		$destination = $result['destination'];
		if ( null === $destination ) {
			return Result::skipped( 'no_destination' );
		}
		LeadRepository::update_destination( $lead_id, (string) $destination['id'], (string) $destination['name'] );
		$context->lead = (array) LeadRepository::get( $lead_id );
		return Result::success();
	}
}
