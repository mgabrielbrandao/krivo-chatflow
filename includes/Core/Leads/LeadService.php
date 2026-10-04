<?php
/**
 * Lead operations that fire hooks.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Operations on leads that other parts of the plugin (and add-ons) must be told about.
 */
final class LeadService {

	/**
	 * Changes a lead's status and announces it.
	 *
	 * @param int    $id     Lead id.
	 * @param string $status New status.
	 * @return array|null The updated lead, or null when not found / invalid status.
	 */
	public static function change_status( int $id, string $status ): ?array {
		$changes = LeadRepository::update_status( $id, $status );
		if ( null === $changes ) {
			return null;
		}
		$lead = LeadRepository::get( $id );
		if ( $changes['from'] !== $changes['to'] && null !== $lead ) {
			/**
			 * Fires after a lead's status changed (also the source of the "lead.updated" trigger).
			 *
			 * @param array $lead    Updated lead.
			 * @param array $changes Changes: { status: { from, to } }.
			 */
			do_action( 'krivo_chatflow_lead_updated', $lead, array( 'status' => $changes ) );
		}
		return $lead;
	}
}
