<?php
/**
 * Action: change lead status.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Leads\LeadService;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the lead's status (for example "In contact" as soon as the visitor continues on WhatsApp).
 */
final class ChangeLeadStatus implements ActionInterface {

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'change_lead_status';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Change lead status', 'krivo-chatflow' );
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
	 * Triggers. Not "lead.updated": an action that changes the lead must not react to its own change.
	 *
	 * @return string[]
	 */
	public function triggers(): array {
		return array( Registry::FORM_SUBMITTED, Registry::LEAD_CREATED, Registry::ROUTING_MATCHED, Registry::WHATSAPP_CLICKED, Registry::FLOW_COMPLETED );
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array( 'status' => 'contacted' );
	}

	/**
	 * Sanitises the configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	public function sanitize( array $config ): array {
		return array( 'status' => Sanitizer::enum( $config['status'] ?? '', LeadRepository::STATUSES, 'contacted' ) );
	}

	/**
	 * Applies the status.
	 *
	 * @param RunContext $context Context.
	 * @param array      $config  Configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result {
		$lead_id = (int) ( $context->lead['id'] ?? 0 );
		if ( $lead_id <= 0 || 'spam' === ( $context->lead['status'] ?? '' ) ) {
			return Result::skipped( 'no_lead' );
		}
		$updated = LeadService::change_status( $lead_id, (string) ( $config['status'] ?? 'contacted' ) );
		if ( null === $updated ) {
			return Result::skipped( 'not_found' );
		}
		$context->lead = $updated;
		return Result::success();
	}
}
