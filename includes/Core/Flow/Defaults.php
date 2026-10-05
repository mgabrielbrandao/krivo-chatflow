<?php
/**
 * Default flow configuration.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The shape of a flow configuration (schema 1) with its default values. Everything the sanitiser,
 * the manifest builder and the admin editor rely on starts here.
 */
final class Defaults {

	public const SCHEMA = 1;

	public const MODES      = array( 'form', 'steps', 'conversational' );
	public const SHAPES     = array( 'circle', 'squircle', 'pill', 'custom' );
	public const POSITIONS  = array( 'bottom-right', 'bottom-left' );
	public const ANIMATIONS = array( 'none', 'fade', 'pulse', 'bounce' );
	public const ICONS      = array( 'chat', 'message', 'headset', 'phone-chat', 'custom_svg', 'custom_image' );
	public const THEMES     = array( 'light', 'dark', 'auto' );
	public const DEVICES    = array( 'desktop', 'tablet', 'mobile' );

	/**
	 * A blank flow configuration with safe defaults.
	 *
	 * @param string $name Flow name.
	 * @return array<string,mixed>
	 */
	public static function blank( string $name = '' ): array {
		return array(
			'schema'      => self::SCHEMA,
			'name'        => '' !== $name ? $name : __( 'New ChatFlow', 'krivo-chatflow' ),
			'mode'        => 'form',
			'steps'       => array(
				array(
					'id'     => Text::random_id( 's_', 6 ),
					'title'  => '',
					'fields' => array(),
				),
			),
			'logic'       => array( 'rules' => array() ),
			'finish'      => array(
				'button_label'    => '',
				'success_message' => '',
			),
			'whatsapp'    => array(
				'message'             => '',
				'default_destination' => '',
			),
			'automations' => array(),
			'bubble'      => array(
				'icon'      => array(
					'type'          => 'chat',
					'svg'           => '',
					'attachment_id' => 0,
				),
				'label'     => '',
				'shape'     => 'circle',
				'radius'    => 18,
				'position'  => 'bottom-right',
				'offset_x'  => 24,
				'offset_y'  => 24,
				'size'      => 60,
				'colors'    => array(
					'bg'     => '#7B2FF7',
					'icon'   => '#FFFFFF',
					'border' => '',
				),
				'shadow'    => true,
				'animation' => 'none',
				'tooltip'   => array(
					'enabled'     => true,
					'text'        => '',
					'delay'       => 3,
					'duration'    => 8,
					'dismissible' => true,
					'once'        => true,
				),
				'badge'     => array(
					'enabled' => false,
					'text'    => '1',
					'delay'   => 5,
				),
			),
			'design'      => array(
				'title'                => '',
				'subtitle'             => '',
				'theme'                => 'light',
				'accent'               => '#7B2FF7',
				'avatar_attachment_id' => 0,
				'strings'              => array(
					'online'  => '',
					'offline' => '',
					'send'    => '',
					'next'    => '',
					'back'    => '',
				),
			),
			'hours'       => array(
				'enabled' => false,
				'days'    => array(
					'mon' => array( array( '09:00', '18:00' ) ),
					'tue' => array( array( '09:00', '18:00' ) ),
					'wed' => array( array( '09:00', '18:00' ) ),
					'thu' => array( array( '09:00', '18:00' ) ),
					'fri' => array( array( '09:00', '18:00' ) ),
					'sat' => array(),
					'sun' => array(),
				),
			),
			'behavior'    => array(
				'trigger'        => 'delay',
				'delay'          => 3,
				'scroll_percent' => 30,
				'auto_open'      => false,
				'auto_open_once' => true,
			),
			'display'     => array(
				'priority' => 10,
				'mode'     => 'all',
				'include'  => array(),
				'exclude'  => array(),
				'devices'  => self::DEVICES,
				'logged'   => 'any',
			),
		);
	}
}
