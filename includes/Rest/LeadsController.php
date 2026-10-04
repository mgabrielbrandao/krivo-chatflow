<?php
/**
 * Leads REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Automation\Queue\RunRepository;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Leads\LeadPresenter;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Leads\LeadService;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Leads: search and filter, open, change status, delete (single and bulk).
 */
final class LeadsController extends AdminController {

	public const MAX_BULK = 200;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/leads',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => self::can( Capabilities::VIEW_LEADS ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), LeadRepository::STATUSES ),
					),
					'flow'     => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'from'     => self::date_arg(),
					'to'       => self::date_arg(),
					'orderby'  => array(
						'type'    => 'string',
						'enum'    => array( 'created_at', 'name', 'email', 'status', 'id' ),
						'default' => 'created_at',
					),
					'order'    => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc', 'ASC', 'DESC' ),
						'default' => 'desc',
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
			'/leads/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => self::can( Capabilities::VIEW_LEADS ),
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => self::can( Capabilities::EDIT_LEADS ),
					'args'                => self::id_arg() + array(
						'status' => array(
							'type'     => 'string',
							'enum'     => LeadRepository::STATUSES,
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => self::can( Capabilities::DELETE_LEADS ),
					'args'                => self::id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bulk' ),
				'permission_callback' => self::can_any( array( Capabilities::EDIT_LEADS, Capabilities::DELETE_LEADS ) ),
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'status', 'delete' ),
						'required' => true,
					),
					'ids'    => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
						'maxItems' => self::MAX_BULK,
					),
					'status' => array(
						'type' => 'string',
						'enum' => LeadRepository::STATUSES,
					),
				),
			)
		);
	}

	/**
	 * GET /leads
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index( \WP_REST_Request $request ): \WP_REST_Response {
		$args = array(
			'search'   => (string) $request['search'],
			'status'   => (string) $request['status'],
			'flow_id'  => (int) $request['flow'],
			'from'     => (string) $request['from'],
			'to'       => (string) $request['to'],
			'orderby'  => (string) $request['orderby'],
			'order'    => (string) $request['order'],
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		);
		$result = LeadRepository::query( $args );
		$titles = self::flow_titles();
		return self::ok(
			array(
				'items'    => array_map(
					static function ( $lead ) use ( $titles ) {
						return LeadPresenter::summary( $lead, $titles );
					},
					$result['items']
				),
				'total'    => $result['total'],
				'page'     => $args['page'],
				'per_page' => $args['per_page'],
				'counts'   => LeadRepository::status_counts( (int) $args['flow_id'] ),
				'flows'    => self::flow_list( $titles ),
			)
		);
	}

	/**
	 * GET /leads/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function show( \WP_REST_Request $request ) {
		$lead = LeadRepository::get( (int) $request['id'] );
		if ( null === $lead ) {
			return self::not_found( __( 'Lead not found.', 'krivo-chatflow' ) );
		}
		$runs = current_user_can( Capabilities::VIEW_INTEGRATIONS ) ? RunRepository::query( array( 'lead_id' => $lead['id'], 'per_page' => 50 ) )['items'] : array();
		return self::ok( LeadPresenter::detail( $lead, self::flow_titles(), $runs ) );
	}

	/**
	 * PATCH /leads/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( \WP_REST_Request $request ) {
		$lead = LeadService::change_status( (int) $request['id'], (string) $request['status'] );
		return null === $lead ? self::not_found( __( 'Lead not found.', 'krivo-chatflow' ) ) : self::ok( LeadPresenter::summary( $lead, self::flow_titles() ) );
	}

	/**
	 * DELETE /leads/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete( \WP_REST_Request $request ) {
		return 1 === LeadRepository::delete( array( (int) $request['id'] ) ) ? self::ok( array( 'deleted' => 1 ) ) : self::not_found( __( 'Lead not found.', 'krivo-chatflow' ) );
	}

	/**
	 * POST /leads/bulk
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function bulk( \WP_REST_Request $request ) {
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $request['ids'] ) ) ) );
		$action = (string) $request['action'];
		if ( array() === $ids || count( $ids ) > self::MAX_BULK ) {
			return self::invalid( __( 'Select between 1 and 200 leads.', 'krivo-chatflow' ) );
		}

		if ( 'delete' === $action ) {
			if ( ! current_user_can( Capabilities::DELETE_LEADS ) ) {
				return self::forbidden();
			}
			return self::ok( array( 'affected' => LeadRepository::delete( $ids ) ) );
		}

		if ( ! current_user_can( Capabilities::EDIT_LEADS ) ) {
			return self::forbidden();
		}
		$status = (string) $request['status'];
		if ( ! in_array( $status, LeadRepository::STATUSES, true ) ) {
			return self::invalid( __( 'Choose a status.', 'krivo-chatflow' ) );
		}
		$affected = 0;
		foreach ( $ids as $id ) {
			if ( null !== LeadService::change_status( $id, $status ) ) {
				++$affected;
			}
		}
		return self::ok( array( 'affected' => $affected ) );
	}

	/**
	 * Flow id => title.
	 *
	 * @return array<int,string>
	 */
	private static function flow_titles(): array {
		$titles = array();
		foreach ( FlowRepository::all() as $flow ) {
			$titles[ $flow['id'] ] = $flow['title'];
		}
		return $titles;
	}

	/**
	 * Flow list for the filter dropdown.
	 *
	 * @param array<int,string> $titles Flow id => title.
	 * @return array[]
	 */
	private static function flow_list( array $titles ): array {
		$list = array();
		foreach ( $titles as $id => $title ) {
			$list[] = array(
				'id'    => (int) $id,
				'title' => (string) $title,
			);
		}
		return $list;
	}
}
