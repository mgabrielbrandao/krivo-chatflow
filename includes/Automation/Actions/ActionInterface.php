<?php
/**
 * Automation action contract.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Automation\Result;

defined( 'ABSPATH' ) || exit;

/**
 * An action is one unit of work an automation can run after a trigger.
 *
 * "sync" actions run inside the visitor's request, do no external I/O and may return a directive for
 * the browser (for example "open WhatsApp"). "async" actions are queued and run later by WP-Cron.
 */
interface ActionInterface {

	/**
	 * Unique type slug, for example "send_email".
	 *
	 * @return string
	 */
	public function type(): string;

	/**
	 * Human label (translated).
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Execution mode: "sync" or "async".
	 *
	 * @return string
	 */
	public function mode(): string;

	/**
	 * Trigger keys this action can be attached to.
	 *
	 * @return string[]
	 */
	public function triggers(): array;

	/**
	 * Default configuration for a new instance.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults(): array;

	/**
	 * Sanitises a configuration coming from the admin.
	 *
	 * @param array $config Raw configuration.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $config ): array;

	/**
	 * Runs the action.
	 *
	 * @param RunContext $context Run context (flow, lead, trigger).
	 * @param array      $config  Sanitised configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result;
}
