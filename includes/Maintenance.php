<?php
/**
 * Scheduled maintenance.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow;

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Automation\Queue\Scheduler;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly housekeeping: re-opens stuck runs, applies the log and lead retention policies in small
 * batches (no long database locks) and makes sure due work is not forgotten.
 */
final class Maintenance {

	/**
	 * Runs every task once.
	 *
	 * @return array<string,int> What was done (counts), mostly for tests and WP-CLI.
	 */
	public static function run(): array {
		$report = array();

		$report['reopened'] = RunRepository::reopen_stuck( 300 );

		$logs_days             = (int) Settings::get( 'privacy.retention_logs_days', 30 );
		$report['runs_purged'] = RunRepository::purge_older_than( max( 1, $logs_days ), 500 );

		$leads_days             = (int) Settings::get( 'privacy.retention_leads_days', 0 );
		$report['leads_purged'] = $leads_days > 0 ? LeadRepository::purge_older_than( $leads_days, 500 ) : 0;

		if ( array() !== RunRepository::due( 1 ) ) {
			Scheduler::kick();
		}
		return $report;
	}
}
