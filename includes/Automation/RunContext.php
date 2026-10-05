<?php
/**
 * Run context.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an action needs to run: the flow it belongs to, the lead, the trigger and (lazily) the
 * template variables. Payloads are always rebuilt from the lead here; nothing personal is copied into
 * the queue table.
 */
final class RunContext {

	/**
	 * Flow id.
	 *
	 * @var int
	 */
	public $flow_id = 0;

	/**
	 * Flow configuration (the published one).
	 *
	 * @var array
	 */
	public $flow = array();

	/**
	 * Lead row as returned by LeadRepository.
	 *
	 * @var array
	 */
	public $lead = array();

	/**
	 * Trigger key.
	 *
	 * @var string
	 */
	public $trigger = '';

	/**
	 * Trigger specific data (for example "changes" for lead.updated).
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Queue run id (0 for synchronous actions).
	 *
	 * @var int
	 */
	public $run_id = 0;

	/**
	 * Cached variables.
	 *
	 * @var array|null
	 */
	private $vars = null;

	/**
	 * Constructor.
	 *
	 * @param int    $flow_id Flow id.
	 * @param array  $flow    Flow config.
	 * @param array  $lead    Lead row.
	 * @param string $trigger Trigger key.
	 * @param array  $data    Trigger data.
	 */
	public function __construct( int $flow_id, array $flow, array $lead, string $trigger, array $data = array() ) {
		$this->flow_id = $flow_id;
		$this->flow    = $flow;
		$this->lead    = $lead;
		$this->trigger = $trigger;
		$this->data    = $data;
	}

	/**
	 * Template variables for the lead.
	 *
	 * @return array
	 */
	public function vars(): array {
		if ( null === $this->vars ) {
			$created    = strtotime( ( $this->lead['created_at'] ?? 'now' ) . ' UTC' );
			$this->vars = Builder::build(
				$this->flow,
				$this->answers(),
				array(
					'tracking'    => $this->tracking(),
					'lead_id'     => $this->lead['id'] ?? '',
					'destination' => $this->lead['destination_label'] ?? '',
					'timestamp'   => $created ? $created : time(),
				)
			);
		}
		return $this->vars;
	}

	/**
	 * Stored answers of the lead.
	 *
	 * @return array
	 */
	public function answers(): array {
		$answers = $this->lead['answers'] ?? array();
		return is_array( $answers ) ? $answers : Json::decode( (string) $answers );
	}

	/**
	 * Tracking data of the lead.
	 *
	 * @return array<string,string>
	 */
	public function tracking(): array {
		$keys = array( 'page_url', 'page_title', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'device' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = (string) ( $this->lead[ $key ] ?? '' );
		}
		return $out;
	}
}
