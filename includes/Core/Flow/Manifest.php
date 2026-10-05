<?php
/**
 * Public manifest.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Core\Fields\Registry as FieldRegistry;
use Krivo\ChatFlow\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds what the visitor's browser is allowed to know about a flow.
 *
 * Two parts: "boot" (bubble, tooltip, behaviour, hours, design, signature: printed with the page and
 * cached in the autoloaded index so a page view needs no database query) and "flow" (steps, fields,
 * logic, texts: fetched on first interaction).
 *
 * Never included: recipient e-mails, routing rules, automations, webhook data, WhatsApp numbers (the
 * link arrives only in the response to a submission), secrets, draft content.
 */
final class Manifest {

	/**
	 * Full manifest.
	 *
	 * @param int   $id       Flow id.
	 * @param int   $revision Revision.
	 * @param array $config   Flow configuration.
	 * @return array{boot:array,flow:array}
	 */
	public static function build( int $id, int $revision, array $config ): array {
		$manifest = array(
			'boot' => self::boot( $id, $revision, $config ),
			'flow' => self::flow( $id, $revision, $config ),
		);
		/**
		 * Filters the public manifest of a flow (must not contain anything private).
		 *
		 * @param array $manifest { boot, flow }.
		 * @param int   $id       Flow id.
		 * @param array $config   Flow configuration.
		 */
		return apply_filters( 'krivo_chatflow_public_manifest', $manifest, $id, $config );
	}

	/**
	 * The part printed with the page.
	 *
	 * @param int   $id       Flow id.
	 * @param int   $revision Revision.
	 * @param array $config   Flow configuration.
	 * @return array
	 */
	public static function boot( int $id, int $revision, array $config ): array {
		$bubble  = $config['bubble'];
		$design  = $config['design'];
		$icon    = $bubble['icon'];
		$strings = self::strings( $design['strings'] );

		$image_url = '';
		if ( 'custom_image' === $icon['type'] && $icon['attachment_id'] > 0 ) {
			$image_url = (string) wp_get_attachment_image_url( (int) $icon['attachment_id'], 'thumbnail' );
		}
		$avatar_url = $design['avatar_attachment_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $design['avatar_attachment_id'], 'thumbnail' ) : '';

		$button   = 'button' === $config['mode'] ? self::button( $config['button'] ) : null;
		$tooltip  = $bubble['tooltip'];
		$fallback = null === $button ? array(
			'label' => __( 'Open chat', 'krivo-chatflow' ),
			'text'  => __( 'Need help?', 'krivo-chatflow' ),
			'cta'   => '',
		) : self::button_texts( $button['action'] );

		$i18n = array(
			'close'   => __( 'Close chat', 'krivo-chatflow' ),
			'loading' => __( 'Loading…', 'krivo-chatflow' ),
			'error'   => __( 'We could not load the chat. Please try again.', 'krivo-chatflow' ),
			'retry'   => __( 'Try again', 'krivo-chatflow' ),
		);
		if ( null !== $button ) {
			$i18n['button_error']   = __( 'We could not open WhatsApp. Please try again in a moment.', 'krivo-chatflow' );
			$i18n['preview_button'] = 'whatsapp' === $button['action'] ? __( 'Preview: on your site this button opens WhatsApp.', 'krivo-chatflow' ) : __( 'Preview: on your site this button opens your link.', 'krivo-chatflow' );
		}

		return array(
			'id'        => $id,
			'rev'       => $revision,
			'mode'      => $config['mode'],
			'bubble'    => array(
				'icon'      => array(
					'type'      => $icon['type'],
					'svg'       => 'custom_svg' === $icon['type'] ? $icon['svg'] : '',
					'image_url' => $image_url,
				),
				'label'     => '' !== $bubble['label'] ? $bubble['label'] : $fallback['label'],
				'shape'     => $bubble['shape'],
				'radius'    => $bubble['radius'],
				'position'  => $bubble['position'],
				'offset_x'  => $bubble['offset_x'],
				'offset_y'  => $bubble['offset_y'],
				'size'      => $bubble['size'],
				'colors'    => $bubble['colors'],
				'shadow'    => $bubble['shadow'],
				'animation' => $bubble['animation'],
				'tooltip'   => array(
					'enabled'     => $tooltip['enabled'],
					'title'       => $tooltip['title'],
					'text'        => '' !== $tooltip['text'] ? $tooltip['text'] : $fallback['text'],
					'cta'         => '' !== $tooltip['cta'] ? $tooltip['cta'] : $fallback['cta'],
					'delay'       => $bubble['tooltip']['delay'],
					'duration'    => $bubble['tooltip']['duration'],
					'dismissible' => $bubble['tooltip']['dismissible'],
					'once'        => $bubble['tooltip']['once'],
				),
				'badge'     => $bubble['badge'],
			),
			'button'    => $button,
			'behavior'  => $config['behavior'],
			'devices'   => $config['display']['devices'],
			'hours'     => array(
				'enabled' => $config['hours']['enabled'],
				'days'    => $config['hours']['days'],
				'tz'      => Hours::client_timezone(),
			),
			'design'    => array(
				'title'      => '' !== $design['title'] ? $design['title'] : __( 'Hello', 'krivo-chatflow' ),
				'subtitle'   => '' !== $design['subtitle'] ? $design['subtitle'] : __( 'How can we help you?', 'krivo-chatflow' ),
				'theme'      => $design['theme'],
				'accent'     => $design['accent'],
				'avatar_url' => $avatar_url,
				'strings'    => $strings,
			),
			// Only what is needed before the manifest has been downloaded.
			'i18n'      => $i18n,
			'signature' => self::signature(),
		);
	}

	/**
	 * What the browser needs to know about the button of a "button only" chat. A WhatsApp number never
	 * appears here: the browser asks the server when the visitor taps.
	 *
	 * @param array $button Sanitised button settings.
	 * @return array{action:string,url:string,new_tab:bool}
	 */
	private static function button( array $button ): array {
		$url = (string) $button['url'];
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		return array(
			'action'  => $button['action'],
			'url'     => 'link' === $button['action'] ? $url : '',
			'new_tab' => ! empty( $button['new_tab'] ) && 'link' === $button['action'] && 1 === preg_match( '#^https?://#i', $url ),
		);
	}

	/**
	 * Default texts of a "button only" chat.
	 *
	 * @param string $action whatsapp or link.
	 * @return array{label:string,text:string,cta:string}
	 */
	private static function button_texts( string $action ): array {
		if ( 'whatsapp' === $action ) {
			return array(
				'label' => __( 'Chat on WhatsApp', 'krivo-chatflow' ),
				'text'  => __( 'Hi! How can we help? Talk to us on WhatsApp.', 'krivo-chatflow' ),
				'cta'   => __( 'Chat on WhatsApp', 'krivo-chatflow' ),
			);
		}
		return array(
			'label' => __( 'Contact us', 'krivo-chatflow' ),
			'text'  => __( 'Hi! How can we help?', 'krivo-chatflow' ),
			'cta'   => __( 'Contact us', 'krivo-chatflow' ),
		);
	}

	/**
	 * The part fetched on first interaction.
	 *
	 * @param int   $id       Flow id.
	 * @param int   $revision Revision.
	 * @param array $config   Flow configuration.
	 * @return array
	 */
	public static function flow( int $id, int $revision, array $config ): array {
		$steps = array();
		foreach ( $config['steps'] as $step ) {
			$fields = array();
			foreach ( $step['fields'] as $field ) {
				$fields[] = self::field( $field );
			}
			$steps[] = array(
				'id'     => $step['id'],
				'title'  => $step['title'],
				'fields' => $fields,
			);
		}

		$whatsapp = false;
		foreach ( $config['automations'] as $automation ) {
			if ( 'open_whatsapp' === $automation['type'] && $automation['enabled'] ) {
				$whatsapp = true;
			}
		}

		$strings = self::strings( $config['design']['strings'] );
		return array(
			'id'          => $id,
			'rev'         => $revision,
			'mode'        => $config['mode'],
			'steps'       => $steps,
			'logic'       => $config['logic'],
			'finish'      => array(
				'button_label'    => '' !== $config['finish']['button_label'] ? $config['finish']['button_label'] : ( $whatsapp ? __( 'Continue on WhatsApp', 'krivo-chatflow' ) : $strings['send'] ),
				'success_message' => '' !== $config['finish']['success_message'] ? $config['finish']['success_message'] : __( 'Thank you! We received your message.', 'krivo-chatflow' ),
				'whatsapp'        => $whatsapp,
			),
			'privacy_url' => (string) get_privacy_policy_url(),
			'i18n'        => self::i18n( $strings ),
			'messages'    => FieldRegistry::messages(),
		);
	}

	/**
	 * Public representation of a field (no server-only data such as static hidden values).
	 *
	 * @param array $field Field definition.
	 * @return array
	 */
	private static function field( array $field ): array {
		$out = array(
			'id'          => $field['id'],
			'type'        => $field['type'],
			'key'         => $field['key'],
			'label'       => $field['label'],
			'prompt'      => $field['prompt'],
			'description' => $field['description'],
			'placeholder' => $field['placeholder'],
			'required'    => $field['required'],
			'default'     => $field['default'],
		);
		foreach ( array( 'options', 'min', 'max', 'maxlength', 'default_dial_code', 'text', 'privacy_link', 'html' ) as $property ) {
			if ( array_key_exists( $property, $field ) ) {
				$out[ $property ] = $field[ $property ];
			}
		}
		if ( 'hidden' === $field['type'] ) {
			$out['source'] = $field['source'];
			$out['param']  = $field['param'];
		}
		if ( in_array( $field['type'], array( 'phone', 'whatsapp' ), true ) && empty( $out['default_dial_code'] ) ) {
			$out['default_dial_code'] = (string) Settings::get( 'general.default_dial_code', '' );
		}
		return $out;
	}

	/**
	 * Credit signature, only when the administrator authorised it. Opt-in, off by default.
	 *
	 * @return array|null
	 */
	private static function signature(): ?array {
		if ( ! Settings::get( 'branding.signature_enabled', false ) ) {
			return null;
		}
		return array(
			'prefix' => __( 'Powered by', 'krivo-chatflow' ),
			'name'   => 'Krivo Works',
			'link'   => Settings::get( 'branding.signature_link', false ) ? KRIVO_CHATFLOW_CREDIT_URL : '',
		);
	}

	/**
	 * Resolves overridable interface strings against their defaults.
	 *
	 * @param array $custom Strings customised by the administrator.
	 * @return array<string,string>
	 */
	private static function strings( array $custom ): array {
		$defaults = array(
			'online'  => __( 'We are online', 'krivo-chatflow' ),
			'offline' => __( 'We are offline right now, but leave your message.', 'krivo-chatflow' ),
			'send'    => __( 'Send message', 'krivo-chatflow' ),
			'next'    => __( 'Continue', 'krivo-chatflow' ),
			'back'    => __( 'Back', 'krivo-chatflow' ),
		);
		$out      = array();
		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = isset( $custom[ $key ] ) && '' !== $custom[ $key ] ? $custom[ $key ] : $default;
		}
		return $out;
	}

	/**
	 * Interface strings used by the panel (the widget ships no translation files).
	 *
	 * @param array $strings Resolved overridable strings.
	 * @return array<string,string>
	 */
	private static function i18n( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'close'              => __( 'Close chat', 'krivo-chatflow' ),
				'sending'            => __( 'Sending…', 'krivo-chatflow' ),
				'retry'              => __( 'Try again', 'krivo-chatflow' ),
				'error_generic'      => __( 'We could not send your message. Please try again.', 'krivo-chatflow' ),
				'error_network'      => __( 'Connection problem. Your answers were kept; please try again.', 'krivo-chatflow' ),
				'error_rate'         => __( 'Too many attempts. Please wait a few minutes and try again.', 'krivo-chatflow' ),
				'open_whatsapp'      => __( 'Open WhatsApp', 'krivo-chatflow' ),
				'whatsapp_notice'    => __( 'You will be redirected to WhatsApp with your message ready.', 'krivo-chatflow' ),
				'select_placeholder' => __( 'Select an option', 'krivo-chatflow' ),
				'privacy_policy'     => __( 'privacy policy', 'krivo-chatflow' ),
				'preview_notice'     => __( 'Preview mode: nothing is sent or saved.', 'krivo-chatflow' ),
				'step_of'            => __( 'Step {current} of {total}', 'krivo-chatflow' ),
				'your_answer'        => __( 'Your answer', 'krivo-chatflow' ),
				'type_answer'        => __( 'Type your answer…', 'krivo-chatflow' ),
				'optional'           => __( '(optional)', 'krivo-chatflow' ),
				'done'               => __( 'Done', 'krivo-chatflow' ),
				'accept'             => __( 'I agree', 'krivo-chatflow' ),
				'skip'               => __( 'Skip', 'krivo-chatflow' ),
			)
		);
	}
}
