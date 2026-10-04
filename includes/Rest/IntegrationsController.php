<?php
/**
 * Integrations REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Automation\Queue\Scheduler;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Automation\SampleData;
use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Automation\Webhook\Executor;
use Krivo\ChatFlow\Automation\Webhook\PayloadBuilder;
use Krivo\ChatFlow\Core\Flow\Defaults;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook connections, the test button, payload preview and the automation run log with retries.
 * Credentials are written but never returned.
 */
final class IntegrationsController extends AdminController {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/integrations/connections',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => self::can( Capabilities::VIEW_INTEGRATIONS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
					'args'                => array(
						'connection' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/integrations/connections/(?P<cid>con_[a-z0-9]{4,16})',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
					'args'                => array(
						'connection' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/integrations/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
				'args'                => array(
					'connection_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^con_[a-z0-9]{4,16}$',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/automations/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
				'args'                => array(
					'flow_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'automation' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/automations/runs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'runs' ),
				'permission_callback' => self::can( Capabilities::VIEW_INTEGRATIONS ),
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array( '', 'pending', 'running', 'retrying', 'success', 'failed', 'skipped', 'cancelled' ),
					),
					'flow'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/automations/runs/(?P<id>\d+)/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'retry' ),
				'permission_callback' => self::can( Capabilities::MANAGE_INTEGRATIONS ),
				'args'                => self::id_arg(),
			)
		);
	}

	/**
	 * GET /integrations/connections
	 *
	 * @return \WP_REST_Response
	 */
	public static function index(): \WP_REST_Response {
		return self::ok(
			array(
				'items'   => ConnectionRepository::public_list(),
				'presets' => ConnectionRepository::PRESETS,
				'notice'  => self::data_notice(),
			)
		);
	}

	/**
	 * POST/PUT connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		$raw = (array) $request['connection'];
		if ( null !== $request['cid'] ) {
			$raw['id'] = (string) $request['cid'];
		} else {
			unset( $raw['id'] );
		}
		$saved = ConnectionRepository::save( $raw, get_current_user_id() );
		return is_wp_error( $saved ) ? $saved : self::ok( $saved, null !== $request['cid'] ? 200 : 201 );
	}

	/**
	 * DELETE connection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete( \WP_REST_Request $request ) {
		return ConnectionRepository::delete( (string) $request['cid'] ) ? self::ok( array( 'deleted' => true ) ) : self::not_found( __( 'Connection not found.', 'krivo-chatflow' ) );
	}

	/**
	 * POST /integrations/test: sends a SAMPLE payload (obviously fake data, marked "test": true).
	 * The answer is a summary: status, category, a plain-language sentence and the time taken.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test( \WP_REST_Request $request ) {
		$runtime = ConnectionRepository::runtime( (string) $request['connection_id'] );
		if ( null === $runtime ) {
			return self::not_found( __( 'Connection not found.', 'krivo-chatflow' ) );
		}
		$limit = (int) get_transient( 'krivo_chatflow_hook_test_limit' );
		if ( $limit >= 10 ) {
			return new \WP_Error( 'krivo_chatflow_rate_limited', __( 'Please wait a minute before sending another test.', 'krivo-chatflow' ), array( 'status' => 429 ) );
		}
		set_transient( 'krivo_chatflow_hook_test_limit', $limit + 1, MINUTE_IN_SECONDS );

		$flow    = Sanitizer::sanitize( Templates::build( 'contact' ) );
		$ctx     = new RunContext( 0, $flow, SampleData::lead_for_flow( $flow ), 'form.submitted' );
		$payload = PayloadBuilder::default_payload( $ctx, 'form.submitted', 'rn_test_' . substr( wp_generate_uuid4(), 0, 8 ) );
		$payload = array( 'test' => true ) + $payload;

		$result = Executor::send( $runtime, $payload, 'form.submitted', (string) $payload['delivery_id'] );
		return self::ok(
			array(
				'ok'          => $result['ok'],
				'status'      => $result['status'],
				'category'    => $result['category'],
				'summary'     => $result['summary'],
				'duration_ms' => $result['duration_ms'],
				'host'        => (string) wp_parse_url( $runtime['url'], PHP_URL_HOST ),
				'enabled'     => $runtime['enabled'],
			)
		);
	}

	/**
	 * POST /automations/preview: the payload an automation would send, for a sample lead.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$flow_id = (int) $request['flow_id'];
		$row     = $flow_id > 0 ? FlowRepository::get( $flow_id ) : null;
		$flow    = null !== $row ? $row['draft'] : Sanitizer::sanitize( Defaults::blank() );

		$item = \Krivo\ChatFlow\Automation\Registry::sanitize_item( (array) $request['automation'] );
		if ( null === $item || 'send_webhook' !== $item['type'] ) {
			return self::invalid( __( 'Choose a valid webhook automation.', 'krivo-chatflow' ) );
		}
		$ctx = new RunContext( $flow_id, $flow, SampleData::lead_for_flow( $flow ), $item['trigger'] );
		return self::ok( array( 'payload' => PayloadBuilder::build( $ctx, $item['config'], $item['trigger'], 'rn_preview' ) ) );
	}

	/**
	 * GET /automations/runs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function runs( \WP_REST_Request $request ): \WP_REST_Response {
		$result = RunRepository::query(
			array(
				'status'   => (string) $request['status'],
				'flow_id'  => (int) $request['flow'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		$titles = array();
		foreach ( FlowRepository::all() as $flow ) {
			$titles[ $flow['id'] ] = $flow['title'];
		}
		$items = array();
		foreach ( $result['items'] as $run ) {
			$items[] = array(
				'id'            => $run['id'],
				'flow_id'       => $run['flow_id'],
				'flow'          => $titles[ $run['flow_id'] ] ?? '',
				'lead_id'       => $run['lead_id'],
				'trigger'       => $run['trigger_key'],
				'action'        => $run['action_type'],
				'status'        => $run['status'],
				'attempts'      => $run['attempts'],
				'max_attempts'  => $run['max_attempts'],
				'http_status'   => $run['http_status'],
				'duration_ms'   => $run['duration_ms'],
				'error_code'    => $run['error_code'],
				'error_message' => $run['error_message'],
				'next_attempt'  => $run['next_attempt_at'] ? Clock::iso( $run['next_attempt_at'] ) : '',
				'created_at'    => Clock::iso( $run['created_at'] ),
				'finished_at'   => $run['finished_at'] ? Clock::iso( $run['finished_at'] ) : '',
			);
		}
		return self::ok(
			array(
				'items'  => $items,
				'total'  => $result['total'],
				'counts' => RunRepository::counts(),
			)
		);
	}

	/**
	 * POST /automations/runs/{id}/retry
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function retry( \WP_REST_Request $request ) {
		if ( ! RunRepository::requeue( (int) $request['id'] ) ) {
			return self::invalid( __( 'Only a failed, skipped or cancelled run can be tried again.', 'krivo-chatflow' ) );
		}
		Scheduler::kick();
		return self::ok( array( 'queued' => true ) );
	}

	/**
	 * What the administrator must understand before turning a connection on.
	 *
	 * @return string
	 */
	private static function data_notice(): string {
		return __( 'When this connection is on, the details of each new lead (name, e-mail, phone, answers, page and campaign information) are sent to the address you configure. Only turn it on for a service you trust and that you may share this data with.', 'krivo-chatflow' );
	}
}
