<?php
/**
 * Automation engine.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Automation\Queue\Scheduler;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Infrastructure\Logging\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a trigger into work: finds the flow's enabled automations for that trigger, runs the
 * synchronous ones now (they only build instructions for the browser or touch the database) and queues
 * the asynchronous ones (e-mail, webhook) for WP-Cron.
 */
final class Engine {

	/**
	 * How deeply nested automation-originated dispatches are (guards against loops).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Hooks the engine to events raised elsewhere in the plugin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'krivo_chatflow_lead_updated', array( __CLASS__, 'on_lead_updated' ), 10, 2 );
	}

	/**
	 * Runs the automations of a trigger.
	 *
	 * @param string $trigger Trigger key.
	 * @param int    $flow_id Flow id.
	 * @param array  $flow    Flow configuration.
	 * @param array  $lead    Lead row.
	 * @param array  $data    Trigger data (for lead.updated: "changes").
	 * @param bool   $sync_only Run only synchronous actions (used when a retried submission is recognised as a duplicate: it must get its WhatsApp link again but must not queue e-mails twice).
	 * @return array[] Directives for the visitor's browser (for example open_whatsapp).
	 */
	public static function dispatch( string $trigger, int $flow_id, array $flow, array $lead, array $data = array(), bool $sync_only = false ): array {
		// An update caused by an automation must not trigger more "lead updated" automations.
		if ( Registry::LEAD_UPDATED === $trigger && self::$depth > 0 ) {
			return array();
		}

		$directives = array();
		$queued     = false;

		foreach ( (array) ( $flow['automations'] ?? array() ) as $automation ) {
			if ( empty( $automation['enabled'] ) || ( $automation['trigger'] ?? '' ) !== $trigger ) {
				continue;
			}
			$action = Registry::action( (string) $automation['type'] );
			if ( null === $action ) {
				continue;
			}

			if ( 'async' === $action->mode() ) {
				if ( $sync_only ) {
					continue;
				}
				$context = isset( $data['changes'] ) ? array( 'changes' => $data['changes'] ) : array();
				if ( RunRepository::create( $flow_id, (int) ( $lead['id'] ?? 0 ), $trigger, (string) $automation['id'], (string) $automation['type'], $context ) > 0 ) {
					$queued = true;
				}
				continue;
			}

			$context = new RunContext( $flow_id, $flow, $lead, $trigger, $data );
			++self::$depth;
			try {
				$result = $action->run( $context, (array) $automation['config'] );
				if ( null !== $result->directive ) {
					$directives[] = $result->directive;
				}
				// Actions may change the lead (status, destination): later actions see the update.
				$lead = $context->lead;
			} catch ( \Throwable $e ) {
				do_action( 'krivo_chatflow_action_failed', (string) $automation['type'], Redactor::clean( get_class( $e ) . ': ' . $e->getMessage() ) );
			} finally {
				--self::$depth;
			}
		}

		if ( $queued ) {
			Scheduler::kick();
		}
		return $directives;
	}

	/**
	 * Source of the lead.updated trigger.
	 *
	 * @param array $lead    Updated lead.
	 * @param array $changes Changes.
	 * @return void
	 */
	public static function on_lead_updated( array $lead, array $changes ): void {
		$row = FlowRepository::get( (int) $lead['flow_id'] );
		if ( null === $row ) {
			return;
		}
		$flow = $row['published'] ?? $row['draft'];
		self::dispatch( Registry::LEAD_UPDATED, (int) $lead['flow_id'], $flow, $lead, array( 'changes' => $changes ) );
	}
}
