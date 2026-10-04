<?php
/**
 * Flows REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Automation\DryRun;
use Krivo\ChatFlow\Automation\Registry as AutomationRegistry;
use Krivo\ChatFlow\Core\Conditions\Evaluator;
use Krivo\ChatFlow\Core\Fields\Registry as FieldRegistry;
use Krivo\ChatFlow\Core\Flow\Defaults;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Core\Flow\Validator;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Flows: list, create (blank or from a template), edit the draft, publish, pause, duplicate, delete,
 * preview and dry run.
 *
 * Saving a draft checks the capability of every block that changed: `automations` need
 * `manage_integrations` in addition to `edit_flows`, because whoever can edit automations can send leads
 * to another service.
 */
final class FlowsController extends AdminController {

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/flows',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'index' ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => self::can( Capabilities::CREATE_FLOWS ),
					'args'                => array(
						'template' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'name'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'whatsapp' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/flows/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
					'args'                => self::id_arg() + array(
						'config'    => array(
							'type'     => 'object',
							'required' => true,
						),
						'base_hash' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => self::can( Capabilities::DELETE_FLOWS ),
					'args'                => self::id_arg(),
				),
			)
		);

		foreach ( array( 'publish', 'pause' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/flows/(?P<id>\d+)/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $action ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
					'args'                => self::id_arg(),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/flows/(?P<id>\d+)/duplicate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'duplicate' ),
				'permission_callback' => self::can( Capabilities::CREATE_FLOWS ),
				'args'                => self::id_arg(),
			)
		);

		foreach ( array( 'dry-run' => 'dry_run', 'preview' => 'preview', 'preview-link' => 'preview_link' ) as $path => $method ) {
			register_rest_route(
				self::NAMESPACE,
				'/flows/(?P<id>\d+)/' . $path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $method ),
					'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
					'args'                => self::id_arg() + array(
						'config' => array( 'type' => 'object' ),
					),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/templates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'templates' ),
				'permission_callback' => self::can( Capabilities::CREATE_FLOWS ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/meta',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'meta' ),
				'permission_callback' => self::can( Capabilities::EDIT_FLOWS ),
			)
		);
	}

	/**
	 * GET /flows
	 *
	 * @return \WP_REST_Response
	 */
	public static function index(): \WP_REST_Response {
		$leads = LeadRepository::count_by_flow();
		$items = array();
		foreach ( FlowRepository::all() as $row ) {
			$items[] = array(
				'id'           => $row['id'],
				'title'        => $row['title'],
				'status'       => $row['status'],
				'revision'     => $row['revision'],
				'leads'        => $leads[ $row['id'] ] ?? 0,
				'updated_at'   => Clock::iso( $row['updated_at'] ),
				'published_at' => '' === (string) $row['published_at'] ? '' : Clock::iso( $row['published_at'] ),
			);
		}
		return self::ok( array( 'items' => $items ) );
	}

	/**
	 * POST /flows
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( \WP_REST_Request $request ) {
		$template = (string) $request['template'];
		$name     = (string) $request['name'];
		$opts     = array();
		if ( '' !== $name ) {
			$opts['name'] = $name;
		}
		if ( null !== $request['whatsapp'] ) {
			$opts['whatsapp'] = (bool) $request['whatsapp'];
		}
		if ( '' !== $template ) {
			$config = Templates::build( $template, $opts );
			if ( null === $config ) {
				return self::invalid( __( 'Unknown template.', 'krivo-chatflow' ) );
			}
		} else {
			$config = Defaults::blank( $name );
		}
		$id = FlowRepository::create( $config, get_current_user_id() );
		if ( $id <= 0 ) {
			return new \WP_Error( 'krivo_chatflow_storage', __( 'The chat could not be created.', 'krivo-chatflow' ), array( 'status' => 500 ) );
		}
		return self::ok( self::present( (array) FlowRepository::get( $id ) ), 201 );
	}

	/**
	 * GET /flows/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function show( \WP_REST_Request $request ) {
		$flow = FlowRepository::get( (int) $request['id'] );
		return null === $flow ? self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) ) : self::ok( self::present( $flow ) );
	}

	/**
	 * PUT /flows/{id}: saves the draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$current = FlowRepository::get( $id );
		if ( null === $current ) {
			return self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) );
		}
		$incoming = Sanitizer::sanitize( (array) $request['config'] );

		// Per-section authorisation: automations send data to other services.
		if ( Json::encode( $incoming['automations'] ) !== Json::encode( $current['draft']['automations'] ) && ! current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			return new \WP_Error(
				'krivo_chatflow_forbidden_section',
				__( 'You are not allowed to change automations, e-mail recipients or webhooks.', 'krivo-chatflow' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$saved = FlowRepository::save_draft( $id, $incoming, '' !== (string) $request['base_hash'] ? (string) $request['base_hash'] : null );
		return is_wp_error( $saved ) ? $saved : self::ok( self::present( $saved ) );
	}

	/**
	 * DELETE /flows/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete( \WP_REST_Request $request ) {
		return FlowRepository::delete( (int) $request['id'] ) ? self::ok( array( 'deleted' => true ) ) : self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) );
	}

	/**
	 * POST /flows/{id}/publish
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function publish( \WP_REST_Request $request ) {
		$result = FlowRepository::publish( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( self::present( $result['flow'] ) );
	}

	/**
	 * POST /flows/{id}/pause
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pause( \WP_REST_Request $request ) {
		if ( ! FlowRepository::pause( (int) $request['id'] ) ) {
			return self::invalid( __( 'Only a published chat can be paused.', 'krivo-chatflow' ) );
		}
		return self::ok( self::present( (array) FlowRepository::get( (int) $request['id'] ) ) );
	}

	/**
	 * POST /flows/{id}/duplicate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function duplicate( \WP_REST_Request $request ) {
		$new = FlowRepository::duplicate( (int) $request['id'], get_current_user_id() );
		return $new > 0 ? self::ok( self::present( (array) FlowRepository::get( $new ) ), 201 ) : self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) );
	}

	/**
	 * POST /flows/{id}/dry-run
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dry_run( \WP_REST_Request $request ) {
		$config = self::config_for( $request );
		return is_wp_error( $config ) ? $config : self::ok( DryRun::run( (int) $request['id'], $config ) );
	}

	/**
	 * POST /flows/{id}/preview: the manifest of the draft, for the editor's live preview. Nothing is saved.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview( \WP_REST_Request $request ) {
		$config = self::config_for( $request );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$manifest = Manifest::build( (int) $request['id'], 0, $config );
		return self::ok( $manifest );
	}

	/**
	 * POST /flows/{id}/preview-link: a URL that shows the DRAFT on the site, to this user only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_link( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === FlowRepository::get( $id ) ) {
			return self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) );
		}
		$url = add_query_arg(
			array(
				'krivo_chatflow_preview' => $id,
				'_wpnonce'               => wp_create_nonce( 'krivo_chatflow_preview_' . $id ),
			),
			home_url( '/' )
		);
		return self::ok( array( 'url' => $url ) );
	}

	/**
	 * GET /templates
	 *
	 * @return \WP_REST_Response
	 */
	public static function templates(): \WP_REST_Response {
		return self::ok( array( 'items' => Templates::all() ) );
	}

	/**
	 * GET /meta: everything the editor needs to build its forms from the server's own definitions.
	 *
	 * @return \WP_REST_Response
	 */
	public static function meta(): \WP_REST_Response {
		return self::ok(
			array(
				'field_types' => FieldRegistry::describe(),
				'operators'   => Evaluator::OPERATORS,
				'automations' => AutomationRegistry::describe(),
				'statuses'    => LeadRepository::STATUSES,
				'defaults'    => Defaults::blank(),
				'options'     => array(
					'modes'      => Defaults::MODES,
					'shapes'     => Defaults::SHAPES,
					'positions'  => Defaults::POSITIONS,
					'animations' => Defaults::ANIMATIONS,
					'icons'      => Defaults::ICONS,
					'themes'     => Defaults::THEMES,
					'devices'    => Defaults::DEVICES,
				),
				'limits'      => array(
					'steps'       => Sanitizer::MAX_STEPS,
					'fields'      => Sanitizer::MAX_FIELDS,
					'options'     => Sanitizer::MAX_OPTIONS,
					'rules'       => Sanitizer::MAX_RULES,
					'conditions'  => Sanitizer::MAX_CONDITIONS,
					'automations' => Sanitizer::MAX_AUTOMATIONS,
				),
				'credit_url'  => KRIVO_CHATFLOW_CREDIT_URL,
			)
		);
	}

	/**
	 * Sanitised configuration to work on: the one sent by the editor (unsaved changes) or the stored draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private static function config_for( \WP_REST_Request $request ) {
		$flow = FlowRepository::get( (int) $request['id'] );
		if ( null === $flow ) {
			return self::not_found( __( 'Chat not found.', 'krivo-chatflow' ) );
		}
		$config = $request['config'];
		return is_array( $config ) && array() !== $config ? Sanitizer::sanitize( $config ) : $flow['draft'];
	}

	/**
	 * Admin representation of a flow.
	 *
	 * @param array $flow Flow from the repository.
	 * @return array
	 */
	private static function present( array $flow ): array {
		$draft = $flow['draft'];
		return array(
			'id'                      => $flow['id'],
			'title'                   => $flow['title'],
			'status'                  => $flow['status'],
			'revision'                => $flow['revision'],
			'config'                  => $draft,
			'draft_hash'              => $flow['draft_hash'],
			'report'                  => Validator::report( $draft ),
			'variables'               => Builder::catalog( $draft ),
			'has_unpublished_changes' => null === $flow['published'] || Json::encode( $flow['published'] ) !== Json::encode( $draft ),
			'created_at'              => Clock::iso( $flow['created_at'] ),
			'updated_at'              => Clock::iso( $flow['updated_at'] ),
			'published_at'            => '' === $flow['published_at'] ? '' : Clock::iso( $flow['published_at'] ),
		);
	}
}
