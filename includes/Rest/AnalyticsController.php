<?php
/**
 * Analytics REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The funnel: views, opens, starts, leads, WhatsApp clicks and the rates between them.
 *
 * Views, opens and starts are reported by the visitor's browser (a robot can inflate them); leads and
 * WhatsApp clicks are counted by the server. The response says which is which.
 */
final class AnalyticsController extends AdminController {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/analytics/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'summary' ),
				'permission_callback' => self::can( Capabilities::VIEW_ANALYTICS ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 365,
					),
					'flow' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /analytics/summary
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function summary( \WP_REST_Request $request ): \WP_REST_Response {
		$days    = max( 1, min( 365, (int) $request['days'] ) );
		$flow_id = (int) $request['flow'];
		$to      = Clock::site_day();
		$from    = wp_date( 'Y-m-d', Clock::time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$totals = StatsRepository::totals( $from, $to, $flow_id );
		$series = StatsRepository::series( $from, $to, $flow_id );

		// One entry per day, zeros included, so the chart has no gaps.
		$daily = array();
		for ( $i = $days - 1; $i >= 0; --$i ) {
			$day     = wp_date( 'Y-m-d', Clock::time() - $i * DAY_IN_SECONDS );
			$daily[] = array_merge(
				array( 'day' => $day ),
				array_merge( array_fill_keys( StatsRepository::metrics(), 0 ), $series[ $day ] ?? array() )
			);
		}

		$flows = array();
		foreach ( FlowRepository::all() as $row ) {
			$flows[] = array(
				'id'    => $row['id'],
				'title' => $row['title'],
			);
		}

		return self::ok(
			array(
				'from'   => $from,
				'to'     => $to,
				'days'   => $days,
				'totals' => $totals,
				'rates'  => StatsRepository::rates( $totals ),
				'daily'  => $daily,
				'flows'  => $flows,
				'source' => array(
					'view'           => 'browser',
					'open'           => 'server',
					'start'          => 'browser',
					'button_click'   => 'server',
					'lead'           => 'server',
					'whatsapp_click' => 'server',
				),
			)
		);
	}
}
