<?php
/**
 * Lead presentation for the admin.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Leads;

use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored lead into the shape the admin screens use. Dates are ISO 8601 in UTC (the browser
 * formats them in the administrator's timezone); answers become an ordered list with labels.
 */
final class LeadPresenter {

	/**
	 * Compact form for tables.
	 *
	 * @param array                $lead        Stored lead.
	 * @param array<int,string>    $flow_titles Flow id => title.
	 * @return array
	 */
	public static function summary( array $lead, array $flow_titles = array() ): array {
		return array(
			'id'          => (int) $lead['id'],
			'public_id'   => (string) $lead['public_id'],
			'flow_id'     => (int) $lead['flow_id'],
			'flow'        => $flow_titles[ (int) $lead['flow_id'] ] ?? '',
			'status'      => (string) $lead['status'],
			'name'        => (string) $lead['name'],
			'email'       => (string) $lead['email'],
			'phone'       => (string) $lead['phone'],
			'channel'     => (string) $lead['channel'],
			'destination' => (string) $lead['destination_label'],
			'in_hours'    => $lead['in_hours'],
			'utm_source'  => (string) $lead['utm_source'],
			'page_url'    => (string) $lead['page_url'],
			'created_at'  => Clock::iso( (string) $lead['created_at'] ),
		);
	}

	/**
	 * Full form for the detail drawer.
	 *
	 * @param array             $lead        Stored lead.
	 * @param array<int,string> $flow_titles Flow id => title.
	 * @param array[]           $runs        Automation runs of the lead.
	 * @return array
	 */
	public static function detail( array $lead, array $flow_titles = array(), array $runs = array() ): array {
		$answers = array();
		foreach ( (array) $lead['answers'] as $key => $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$answers[] = array(
				'key'     => (string) $key,
				'label'   => (string) ( $answer['label'] ?? $key ),
				'type'    => (string) ( $answer['type'] ?? '' ),
				'display' => (string) ( $answer['display'] ?? '' ),
			);
		}
		$consent = array();
		foreach ( (array) $lead['consent'] as $item ) {
			if ( is_array( $item ) ) {
				$consent[] = array(
					'key'      => (string) ( $item['key'] ?? '' ),
					'accepted' => ! empty( $item['accepted'] ),
					'at'       => (string) ( $item['at'] ?? '' ),
				);
			}
		}
		return array_merge(
			self::summary( $lead, $flow_titles ),
			array(
				'source'        => (string) $lead['source'],
				'device'        => (string) $lead['device'],
				'flow_revision' => (int) $lead['flow_revision'],
				'answers'       => $answers,
				'consent'       => $consent,
				'page_title'    => (string) $lead['page_title'],
				'referrer'      => (string) $lead['referrer'],
				'utm'           => array(
					'source'   => (string) $lead['utm_source'],
					'medium'   => (string) $lead['utm_medium'],
					'campaign' => (string) $lead['utm_campaign'],
					'content'  => (string) $lead['utm_content'],
					'term'     => (string) $lead['utm_term'],
				),
				'updated_at'    => Clock::iso( (string) $lead['updated_at'] ),
				'runs'          => array_map(
					static function ( $run ) {
						return array(
							'id'            => (int) $run['id'],
							'trigger'       => (string) $run['trigger_key'],
							'action'        => (string) $run['action_type'],
							'status'        => (string) $run['status'],
							'attempts'      => (int) $run['attempts'],
							'http_status'   => $run['http_status'],
							'error_message' => (string) $run['error_message'],
							'created_at'    => Clock::iso( (string) $run['created_at'] ),
						);
					},
					$runs
				),
			)
		);
	}
}
