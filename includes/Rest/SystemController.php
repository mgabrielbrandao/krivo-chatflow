<?php
/**
 * System REST controller: health and onboarding.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Stats\StatsRepository;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Cron\Events;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Mail\EmailSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Health (what needs attention) and the state of the onboarding wizard.
 */
final class SystemController extends AdminController {

	public const ONBOARDING_OPTION = 'krivo_chatflow_onboarding';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => self::can_any( Capabilities::all() ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/onboarding',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'onboarding' ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_onboarding' ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
					'args'                => array(
						'completed' => array( 'type' => 'boolean' ),
						'dismissed' => array( 'type' => 'boolean' ),
						'goal'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard' ),
				'permission_callback' => self::can_any( Capabilities::all() ),
			)
		);
	}

	/**
	 * GET /health
	 *
	 * @return \WP_REST_Response
	 */
	public static function health(): \WP_REST_Response {
		return self::ok( self::health_report() );
	}

	/**
	 * GET /onboarding
	 *
	 * @return \WP_REST_Response
	 */
	public static function onboarding(): \WP_REST_Response {
		return self::ok( self::onboarding_state() );
	}

	/**
	 * PUT /onboarding
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function update_onboarding( \WP_REST_Request $request ): \WP_REST_Response {
		$state = self::onboarding_state();
		foreach ( array( 'completed', 'dismissed' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$state[ $key ] = (bool) $request[ $key ];
			}
		}
		if ( null !== $request['goal'] ) {
			$state['goal'] = substr( (string) $request['goal'], 0, 30 );
		}
		update_option( self::ONBOARDING_OPTION, $state, false );
		return self::ok( self::onboarding_state() );
	}

	/**
	 * GET /dashboard: last 7 days, latest leads, health and the first-steps checklist. Each part is
	 * included only when the user may see it.
	 *
	 * @return \WP_REST_Response
	 */
	public static function dashboard(): \WP_REST_Response {
		$out = array(
			'checklist' => self::checklist(),
			'health'    => self::health_report(),
		);
		if ( current_user_can( Capabilities::VIEW_ANALYTICS ) ) {
			$to           = Clock::site_day();
			$from         = wp_date( 'Y-m-d', Clock::time() - 6 * DAY_IN_SECONDS );
			$totals       = StatsRepository::totals( $from, $to );
			$out['funnel'] = array(
				'from'   => $from,
				'to'     => $to,
				'totals' => $totals,
				'rates'  => StatsRepository::rates( $totals ),
			);
		}
		if ( current_user_can( Capabilities::VIEW_LEADS ) ) {
			$titles = array();
			foreach ( FlowRepository::all() as $flow ) {
				$titles[ $flow['id'] ] = $flow['title'];
			}
			$latest        = LeadRepository::query( array( 'per_page' => 5, 'status_not' => 'spam' ) );
			$out['leads']  = array(
				'total'  => $latest['total'],
				'counts' => LeadRepository::status_counts(),
				'latest' => array_map(
					static function ( $lead ) use ( $titles ) {
						return \Krivo\ChatFlow\Core\Leads\LeadPresenter::summary( $lead, $titles );
					},
					$latest['items']
				),
			);
		}
		return self::ok( $out );
	}

	/**
	 * Things that need the administrator's attention, in plain language.
	 *
	 * @return array
	 */
	private static function health_report(): array {
		$counts = RunRepository::counts();
		$lag    = RunRepository::oldest_due_age();
		$failed = RunRepository::failed_since( DAY_IN_SECONDS );
		$email  = EmailSettings::get();
		$last   = get_option( SettingsController::EMAIL_TEST_OPTION, array() );
		$last   = is_array( $last ) && isset( $last['status'] ) ? $last : null;
		$cron   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next   = wp_next_scheduled( Events::QUEUE );

		$issues = array();
		if ( $cron ) {
			$issues[] = array(
				'code'    => 'cron_disabled',
				'level'   => 'warning',
				'message' => __( 'WP-Cron is turned off on this site. E-mails and webhooks are only sent when a real server cron job calls wp-cron.php. If you have not set one up, they will be delayed.', 'krivo-chatflow' ),
			);
		}
		if ( null !== $lag && $lag > 300 ) {
			$issues[] = array(
				'code'    => 'queue_lag',
				'level'   => 'warning',
				/* translators: %d: minutes. */
				'message' => sprintf( __( 'Automations are running about %d minutes late. Check that WP-Cron works on this site.', 'krivo-chatflow' ), (int) ceil( $lag / 60 ) ),
			);
		}
		if ( $failed > 0 ) {
			$issues[] = array(
				'code'    => 'runs_failed',
				'level'   => 'warning',
				/* translators: %d: number of failed automations. */
				'message' => sprintf( _n( '%d automation failed in the last 24 hours. Open Integrations to see why and try again.', '%d automations failed in the last 24 hours. Open Integrations to see why and try again.', $failed, 'krivo-chatflow' ), $failed ),
			);
		}
		if ( 'custom' === $email['mode'] && ( null === $last || 'ok' !== $last['status'] ) ) {
			$issues[] = array(
				'code'    => 'smtp_untested',
				'level'   => 'warning',
				'message' => __( 'Your own SMTP server is configured but has not been tested successfully. Send a test e-mail in Settings.', 'krivo-chatflow' ),
			);
		}

		return array(
			'issues' => $issues,
			'cron'   => array(
				'disabled'   => $cron,
				'next_queue' => false === $next ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) $next ),
			),
			'queue'  => array(
				'counts'     => $counts,
				'lag'        => $lag,
				'failed_24h' => $failed,
			),
			'email'  => array(
				'mode'      => $email['mode'],
				'last_test' => $last,
			),
		);
	}

	/**
	 * First steps with their state.
	 *
	 * @return array[]
	 */
	private static function checklist(): array {
		$flows      = FlowRepository::all();
		$published  = array_filter(
			$flows,
			static function ( $flow ) {
				return 'published' === $flow['status'];
			}
		);
		$routing    = RoutingRepository::get();
		$has_dest   = array() !== $routing['destinations'];
		$leads      = LeadRepository::query( array( 'per_page' => 1, 'status_not' => 'spam' ) )['total'];
		$last       = get_option( SettingsController::EMAIL_TEST_OPTION, array() );
		$mail_ok    = is_array( $last ) && 'ok' === ( $last['status'] ?? '' );

		return array(
			array(
				'key'  => 'create',
				'done' => array() !== $flows,
			),
			array(
				'key'  => 'destination',
				'done' => $has_dest,
			),
			array(
				'key'  => 'publish',
				'done' => array() !== $published,
			),
			array(
				'key'  => 'email_test',
				'done' => $mail_ok,
			),
			array(
				'key'  => 'first_lead',
				'done' => $leads > 0,
			),
		);
	}

	/**
	 * Onboarding state.
	 *
	 * @return array{completed:bool,dismissed:bool,goal:string}
	 */
	private static function onboarding_state(): array {
		$stored = get_option( self::ONBOARDING_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'completed' => ! empty( $stored['completed'] ),
			'dismissed' => ! empty( $stored['dismissed'] ),
			'goal'      => isset( $stored['goal'] ) ? (string) $stored['goal'] : '',
		);
	}
}
