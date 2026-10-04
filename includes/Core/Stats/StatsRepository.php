<?php
/**
 * Funnel counters.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Stats;

use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Daily aggregated counters per flow. No visitor identifier is stored: a view is one row increment,
 * not one event row, so the table stays tiny and carries no personal data.
 *
 * Client-reported metrics (view, open, start) are advisory and can be inflated by bots; "lead" and
 * "whatsapp_click" are counted by the server and are authoritative.
 */
final class StatsRepository {

	public const VIEW           = 'view';
	public const OPEN           = 'open';
	public const START          = 'start';
	public const LEAD           = 'lead';
	public const WHATSAPP_CLICK = 'whatsapp_click';

	/**
	 * All metrics in funnel order.
	 *
	 * @return string[]
	 */
	public static function metrics(): array {
		return array( self::VIEW, self::OPEN, self::START, self::LEAD, self::WHATSAPP_CLICK );
	}

	/**
	 * Increments a counter for today (site timezone).
	 *
	 * @param int    $flow_id Flow id.
	 * @param string $metric  One of metrics().
	 * @return bool
	 */
	public static function increment( int $flow_id, string $metric ): bool {
		global $wpdb;
		if ( $flow_id <= 0 || ! in_array( $metric, self::metrics(), true ) ) {
			return false;
		}
		$table = Tables::stats();
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (flow_id, day, metric, total) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE total = total + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Tables.
			$flow_id,
			Clock::site_day(),
			$metric
		);
		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Totals per metric for a period.
	 *
	 * @param string $from    First day (Y-m-d, site timezone).
	 * @param string $to      Last day (Y-m-d).
	 * @param int    $flow_id Flow id, 0 for all flows.
	 * @return array<string,int>
	 */
	public static function totals( string $from, string $to, int $flow_id = 0 ): array {
		global $wpdb;
		$table = Tables::stats();
		$where = 'day BETWEEN %s AND %s';
		$args  = array( $from, $to );
		if ( $flow_id > 0 ) {
			$where .= ' AND flow_id = %d';
			$args[] = $flow_id;
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT metric, SUM(total) AS total FROM {$table} WHERE {$where} GROUP BY metric", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$totals = array_fill_keys( self::metrics(), 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $totals[ $row['metric'] ] ) ) {
				$totals[ $row['metric'] ] = (int) $row['total'];
			}
		}
		return $totals;
	}

	/**
	 * Per-day series of every metric for a period.
	 *
	 * @param string $from    First day.
	 * @param string $to      Last day.
	 * @param int    $flow_id Flow id, 0 for all.
	 * @return array<string,array<string,int>> day => metric => total
	 */
	public static function series( string $from, string $to, int $flow_id = 0 ): array {
		global $wpdb;
		$table = Tables::stats();
		$where = 'day BETWEEN %s AND %s';
		$args  = array( $from, $to );
		if ( $flow_id > 0 ) {
			$where .= ' AND flow_id = %d';
			$args[] = $flow_id;
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT day, metric, SUM(total) AS total FROM {$table} WHERE {$where} GROUP BY day, metric ORDER BY day ASC", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$series = array();
		foreach ( (array) $rows as $row ) {
			$series[ $row['day'] ][ $row['metric'] ] = (int) $row['total'];
		}
		return $series;
	}

	/**
	 * Funnel rates derived from totals. A rate is null when its denominator is zero.
	 *
	 * @param array $totals Totals from totals().
	 * @return array<string,float|null>
	 */
	public static function rates( array $totals ): array {
		$rate = static function ( int $part, int $whole ): ?float {
			return $whole > 0 ? round( $part / $whole * 100, 1 ) : null;
		};
		return array(
			'open_rate'       => $rate( (int) $totals[ self::OPEN ], (int) $totals[ self::VIEW ] ),
			'completion_rate' => $rate( (int) $totals[ self::LEAD ], (int) $totals[ self::START ] ),
			'conversion_rate' => $rate( (int) $totals[ self::LEAD ], (int) $totals[ self::VIEW ] ),
			'whatsapp_rate'   => $rate( (int) $totals[ self::WHATSAPP_CLICK ], (int) $totals[ self::LEAD ] ),
		);
	}

	/**
	 * Removes the counters of a flow.
	 *
	 * @param int $flow_id Flow id.
	 * @return void
	 */
	public static function delete_for_flow( int $flow_id ): void {
		global $wpdb;
		$wpdb->delete( Tables::stats(), array( 'flow_id' => $flow_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
