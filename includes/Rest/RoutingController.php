<?php
/**
 * Routing REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Destinations and routing rules.
 */
final class RoutingController extends AdminController {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/routing',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => self::can( Capabilities::MANAGE_ROUTING ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => self::can( Capabilities::MANAGE_ROUTING ),
					'args'                => array(
						'destinations'        => array(
							'type'     => 'array',
							'required' => true,
						),
						'rules'               => array(
							'type'    => 'array',
							'default' => array(),
						),
						'default_destination' => array(
							'type'    => 'string',
							'default' => '',
						),
						'base_revision'       => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * GET /routing
	 *
	 * @return \WP_REST_Response
	 */
	public static function show(): \WP_REST_Response {
		$flows  = array();
		$fields = array();
		foreach ( FlowRepository::all() as $row ) {
			$flow = FlowRepository::get( $row['id'] );
			if ( null === $flow ) {
				continue;
			}
			$flows[] = array(
				'id'    => $row['id'],
				'title' => $row['title'],
			);
			foreach ( Builder::fields( $flow['draft'] ) as $field ) {
				if ( '' === (string) $field['key'] || ! \Krivo\ChatFlow\Core\Fields\Registry::collects( (string) $field['type'] ) ) {
					continue;
				}
				$fields[ $field['key'] ] = array(
					'key'     => $field['key'],
					'label'   => '' !== $field['label'] ? $field['label'] : $field['key'],
					'type'    => $field['type'],
					'options' => array_values( (array) ( $field['options'] ?? array() ) ),
				);
			}
		}
		return self::ok(
			array(
				'routing' => RoutingRepository::get(),
				'flows'   => $flows,
				'fields'  => array_values( $fields ),
				'context' => array(
					array(
						'key'   => 'page_path',
						'label' => __( 'Page path', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'page_url',
						'label' => __( 'Page address', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'referrer',
						'label' => __( 'Referrer', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'utm_source',
						'label' => __( 'Campaign source', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'utm_medium',
						'label' => __( 'Campaign medium', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'utm_campaign',
						'label' => __( 'Campaign name', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'device',
						'label' => __( 'Device (desktop, tablet, mobile)', 'krivo-chatflow' ),
					),
					array(
						'key'   => 'in_hours',
						'label' => __( 'Within business hours (yes/no)', 'krivo-chatflow' ),
					),
				),
			)
		);
	}

	/**
	 * PUT /routing
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( \WP_REST_Request $request ) {
		$saved = RoutingRepository::save(
			array(
				'destinations'        => (array) $request['destinations'],
				'rules'               => (array) $request['rules'],
				'default_destination' => (string) $request['default_destination'],
			),
			null === $request['base_revision'] ? null : (int) $request['base_revision']
		);
		return is_wp_error( $saved ) ? $saved : self::ok( array( 'routing' => $saved ) );
	}
}
