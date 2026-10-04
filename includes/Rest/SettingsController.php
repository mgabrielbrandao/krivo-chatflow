<?php
/**
 * Settings and e-mail REST controller.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Mail\Diagnostics;
use Krivo\ChatFlow\Mail\EmailSettings;

defined( 'ABSPATH' ) || exit;

/**
 * General settings (privacy, retention, branding, anti-spam, events) and e-mail delivery settings.
 */
final class SettingsController extends AdminController {

	public const EMAIL_TEST_OPTION = 'krivo_chatflow_email_test';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'show' ),
					'permission_callback' => self::can( Capabilities::MANAGE_SETTINGS ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => self::can( Capabilities::MANAGE_SETTINGS ),
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/email',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'email' ),
					'permission_callback' => self::can( Capabilities::MANAGE_EMAIL ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_email' ),
					'permission_callback' => self::can( Capabilities::MANAGE_EMAIL ),
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
						// Omitted keeps the current password; an empty string removes it.
						'password' => array( 'type' => array( 'string', 'null' ) ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/email/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'test_email' ),
				'permission_callback' => self::can( Capabilities::MANAGE_EMAIL ),
				'args'                => array(
					'to' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /settings
	 *
	 * @return \WP_REST_Response
	 */
	public static function show(): \WP_REST_Response {
		return self::ok( self::settings_payload() );
	}

	/**
	 * PUT /settings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function update( \WP_REST_Request $request ): \WP_REST_Response {
		Settings::update( (array) $request['settings'] );
		// The signature is part of what the page prints for each flow.
		Index::rebuild();
		return self::ok( self::settings_payload() );
	}

	/**
	 * GET /email
	 *
	 * @return \WP_REST_Response
	 */
	public static function email(): \WP_REST_Response {
		return self::ok( self::email_payload() );
	}

	/**
	 * PUT /email
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_email( \WP_REST_Request $request ) {
		$raw   = (array) $request['settings'];
		$clean = EmailSettings::sanitize( $raw );
		foreach ( array( 'from_email', 'reply_to' ) as $key ) {
			if ( '' !== trim( (string) ( $raw[ $key ] ?? '' ) ) && '' === $clean[ $key ] ) {
				return self::invalid(
					'from_email' === $key
						? __( 'The sender address is not a valid e-mail address.', 'krivo-chatflow' )
						: __( 'The reply-to address is not a valid e-mail address.', 'krivo-chatflow' )
				);
			}
		}
		if ( 'custom' === $clean['mode'] && '' === $clean['host'] ) {
			return self::invalid( __( 'Enter the SMTP server address, or choose the WordPress mailer.', 'krivo-chatflow' ) );
		}
		$password = $request->has_param( 'password' ) ? $request['password'] : null;
		EmailSettings::update( $raw, is_string( $password ) ? $password : null );
		// A changed configuration invalidates the previous test result.
		delete_option( self::EMAIL_TEST_OPTION );
		return self::ok( self::email_payload() );
	}

	/**
	 * POST /email/test
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test_email( \WP_REST_Request $request ) {
		$to = Sanitizer::email( $request['to'] );
		if ( '' === $to ) {
			return self::invalid( __( 'Enter a valid e-mail address to receive the test.', 'krivo-chatflow' ) );
		}
		$limit = (int) get_transient( 'krivo_chatflow_email_test_limit' );
		if ( $limit >= 5 ) {
			return new \WP_Error( 'krivo_chatflow_rate_limited', __( 'Please wait a minute before sending another test.', 'krivo-chatflow' ), array( 'status' => 429 ) );
		}
		set_transient( 'krivo_chatflow_email_test_limit', $limit + 1, MINUTE_IN_SECONDS );

		$result = Diagnostics::test( $to );
		update_option(
			self::EMAIL_TEST_OPTION,
			array(
				'status'    => $result['status'],
				'transport' => $result['transport'],
				'at'        => Clock::now(),
			),
			false
		);
		return self::ok( $result );
	}

	/**
	 * Settings with the data the screen needs.
	 *
	 * @return array
	 */
	private static function settings_payload(): array {
		return array(
			'settings' => Settings::all(),
			'defaults' => Settings::defaults(),
			'credit'   => array(
				'url'  => KRIVO_CHATFLOW_CREDIT_URL,
				'name' => 'Krivo Works',
			),
		);
	}

	/**
	 * E-mail settings (the password is never included, only whether it is set).
	 *
	 * @return array
	 */
	private static function email_payload(): array {
		$last = get_option( self::EMAIL_TEST_OPTION, array() );
		return array(
			'settings'    => EmailSettings::get(),
			'password'    => EmailSettings::password_status(),
			'admin_email' => (string) get_option( 'admin_email' ),
			'last_test'   => is_array( $last ) && isset( $last['status'] ) ? $last : null,
		);
	}
}
