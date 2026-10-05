<?php
/**
 * Flow validation report.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Automation\Webhook\ConnectionRepository;
use Krivo\ChatFlow\Core\Fields\Registry as FieldRegistry;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Core\Variables\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a sanitised flow and explains what is wrong in plain language.
 *
 * "error" blocks publishing (the flow would not work). "warning" is shown but does not block.
 */
final class Validator {

	/**
	 * Builds the report.
	 *
	 * @param array $config Sanitised flow configuration.
	 * @return array{errors:array[],warnings:array[]}
	 */
	public static function report( array $config ): array {
		if ( 'button' === $config['mode'] ) {
			return self::button_report( $config );
		}

		$errors   = array();
		$warnings = array();

		$fields   = Builder::fields( $config );
		$keys     = array();
		$ids      = array();
		$collects = 0;
		foreach ( $fields as $field ) {
			$ids[ $field['id'] ] = $field;
			if ( FieldRegistry::collects( $field['type'] ) ) {
				++$collects;
				$keys[ $field['key'] ] = $field['id'];
			}
			if ( '' === $field['label'] && ! in_array( $field['type'], array( 'html', 'hidden' ), true ) ) {
				$warnings[] = self::item( 'field_no_label', __( 'A field has no label.', 'krivo-chatflow' ), $field['id'] );
			}
			if ( ! empty( FieldRegistry::types()[ $field['type'] ]['options'] ) && array() === ( $field['options'] ?? array() ) ) {
				/* translators: %s: field label. */
				$errors[] = self::item( 'field_no_options', sprintf( __( 'The field "%s" needs at least one option.', 'krivo-chatflow' ), $field['label'] ), $field['id'] );
			}
		}
		if ( 0 === $collects ) {
			$errors[] = self::item( 'no_fields', __( 'Add at least one field to the flow.', 'krivo-chatflow' ), '' );
		}
		if ( 'steps' === $config['mode'] ) {
			foreach ( $config['steps'] as $step ) {
				if ( array() === $step['fields'] ) {
					$warnings[] = self::item( 'empty_step', __( 'A step has no fields and will be empty.', 'krivo-chatflow' ), $step['id'] );
				}
			}
		}

		// Logic that points at fields that do not exist, or that come later than the field it controls
		// (a rule can only depend on answers given before).
		$position = array();
		foreach ( array_values( $fields ) as $index => $field ) {
			$position[ $field['id'] ] = $index;
		}
		foreach ( $config['logic']['rules'] as $rule ) {
			if ( array() === $rule['when']['conditions'] ) {
				$warnings[] = self::item( 'rule_no_condition', __( 'A logic rule has no valid condition, so it always applies. Edit or remove it.', 'krivo-chatflow' ), $rule['id'] );
			}
			foreach ( $rule['when']['conditions'] as $condition ) {
				if ( ! isset( $condition['left'] ) || 0 !== strpos( $condition['left'], 'field:' ) ) {
					continue;
				}
				$key = substr( $condition['left'], 6 );
				if ( ! isset( $keys[ $key ] ) ) {
					/* translators: %s: field key. */
					$warnings[] = self::item( 'rule_unknown_field', sprintf( __( 'A logic rule refers to the field "%s", which does not exist.', 'krivo-chatflow' ), $key ), $rule['id'] );
					continue;
				}
				foreach ( $rule['then'] as $action ) {
					$target = $action['target'] ?? '';
					if ( in_array( $action['do'], array( 'show_field', 'hide_field' ), true ) && isset( $position[ $target ] ) && $position[ $keys[ $key ] ] >= $position[ $target ] ) {
						$warnings[] = self::item( 'rule_forward_reference', __( 'A logic rule depends on a field that comes after the field it shows or hides, so it will not work. Move the field up.', 'krivo-chatflow' ), $rule['id'] );
					}
				}
			}
		}

		// WhatsApp needs somewhere to send the visitor.
		$uses_whatsapp         = false;
		$uses_destination_mail = false;
		foreach ( $config['automations'] as $automation ) {
			if ( ! $automation['enabled'] ) {
				continue;
			}
			if ( 'open_whatsapp' === $automation['type'] ) {
				$uses_whatsapp = true;
			}
			if ( 'send_email' === $automation['type'] && in_array( 'destination', $automation['config']['to'], true ) ) {
				$uses_destination_mail = true;
			}
			if ( 'send_webhook' === $automation['type'] ) {
				$connection = ConnectionRepository::find( (string) $automation['config']['connection_id'] );
				if ( null === $connection ) {
					$errors[] = self::item( 'webhook_no_connection', __( 'A "Send webhook" automation has no connection selected.', 'krivo-chatflow' ), $automation['id'] );
				} elseif ( empty( $connection['enabled'] ) ) {
					/* translators: %s: connection name. */
					$warnings[] = self::item( 'webhook_connection_off', sprintf( __( 'The connection "%s" is turned off, so no data will be sent to it.', 'krivo-chatflow' ), $connection['name'] ), $automation['id'] );
				}
				if ( 'custom' === $automation['config']['payload']['mode'] && null === json_decode( $automation['config']['payload']['template'], true ) ) {
					$errors[] = self::item( 'webhook_bad_json', __( 'The custom webhook payload is not valid JSON.', 'krivo-chatflow' ), $automation['id'] );
				}
			}
		}
		if ( $uses_whatsapp && ! self::has_whatsapp_destination() ) {
			$errors[] = self::item( 'no_whatsapp_destination', __( 'WhatsApp is turned on, but there is no destination with a WhatsApp number. Add one in Routing.', 'krivo-chatflow' ), '' );
		}
		if ( $uses_destination_mail && ! self::has_destination_email() ) {
			$warnings[] = self::item( 'no_destination_email', __( 'An e-mail is set to go to the destination, but no destination has an e-mail address.', 'krivo-chatflow' ), '' );
		}
		if ( ! $uses_whatsapp && array() === $config['automations'] ) {
			$warnings[] = self::item( 'no_automations', __( 'Nothing happens after the visitor sends the form except saving the lead. Add an e-mail notification or WhatsApp.', 'krivo-chatflow' ), '' );
		}

		// Variables used in templates that do not exist.
		$known = array_merge( Builder::reserved(), array_keys( Renderer::ALIASES ), array( 'name', 'email', 'phone', 'event', 'flow_id' ), array_keys( $keys ) );
		$texts = array(
			'whatsapp.message'       => $config['whatsapp']['message'],
			'finish.success_message' => $config['finish']['success_message'],
		);
		foreach ( $config['automations'] as $automation ) {
			if ( 'send_email' === $automation['type'] ) {
				$texts[ 'email.' . $automation['id'] . '.subject' ] = $automation['config']['subject'];
				$texts[ 'email.' . $automation['id'] . '.body' ]    = $automation['config']['body'];
			}
		}
		foreach ( $fields as $field ) {
			$texts[ 'prompt.' . $field['id'] ] = $field['prompt'];
		}
		foreach ( $texts as $where => $text ) {
			foreach ( Renderer::used( (string) $text ) as $name ) {
				if ( ! in_array( $name, $known, true ) ) {
					/* translators: %s: variable name. */
					$warnings[] = self::item( 'unknown_variable', sprintf( __( 'The variable {%s} does not exist and will be empty.', 'krivo-chatflow' ), $name ), $where );
				}
			}
		}

		// Where the widget shows.
		if ( 'only' === $config['display']['mode'] && array() === $config['display']['include'] ) {
			$warnings[] = self::item( 'display_nowhere', __( 'The widget is set to show only on specific pages, but no page was added, so it will not appear anywhere.', 'krivo-chatflow' ), '' );
		}

		// Readability.
		if ( Contrast::ratio( $config['bubble']['colors']['bg'], $config['bubble']['colors']['icon'] ) < 3.0 ) {
			$warnings[] = self::item( 'bubble_contrast', __( 'The bubble icon is hard to see against the bubble colour. Pick colours with more contrast.', 'krivo-chatflow' ), 'bubble' );
		}
		if ( Contrast::ratio( $config['design']['accent'], '#FFFFFF' ) < 4.5 ) {
			$warnings[] = self::item( 'accent_contrast', __( 'White text on the panel accent colour is hard to read. Pick a darker accent colour.', 'krivo-chatflow' ), 'design' );
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * The report for a "button only" flow: there is no form, no logic and no automation, so only the
	 * action, the texts and where the button appears are checked.
	 *
	 * @param array $config Sanitised flow configuration.
	 * @return array{errors:array[],warnings:array[]}
	 */
	private static function button_report( array $config ): array {
		$errors   = array();
		$warnings = array();

		if ( 'link' === $config['button']['action'] ) {
			if ( '' === $config['button']['url'] ) {
				$errors[] = self::item( 'button_no_url', __( 'Enter the address the button opens: a web page, a phone number (tel:+5511999999999) or an e-mail (mailto:name@example.com).', 'krivo-chatflow' ), 'button' );
			}
		} else {
			if ( ! self::has_whatsapp_destination() ) {
				$errors[] = self::item( 'no_whatsapp_destination', __( 'WhatsApp is turned on, but there is no destination with a WhatsApp number. Add one in Routing.', 'krivo-chatflow' ), '' );
			}
			$chosen = RoutingRepository::destination( (string) $config['whatsapp']['default_destination'] );
			if ( null !== $chosen && '' === (string) $chosen['whatsapp'] ) {
				/* translators: %s: destination name. */
				$warnings[] = self::item( 'button_destination_no_number', sprintf( __( 'The destination "%s" has no WhatsApp number, so the button falls back to routing and the site default.', 'krivo-chatflow' ), $chosen['name'] ), 'whatsapp' );
			}
			$known = array_merge( array_column( Builder::button_catalog(), 'name' ), array_keys( Renderer::ALIASES ), array( 'referrer', 'utm_content', 'utm_term' ) );
			foreach ( Renderer::used( (string) $config['whatsapp']['message'] ) as $name ) {
				if ( ! in_array( $name, $known, true ) ) {
					/* translators: %s: variable name. */
					$warnings[] = self::item( 'unknown_variable', sprintf( __( 'The variable {%s} does not exist and will be empty.', 'krivo-chatflow' ), $name ), 'whatsapp.message' );
				}
			}
		}

		if ( 'only' === $config['display']['mode'] && array() === $config['display']['include'] ) {
			$warnings[] = self::item( 'display_nowhere', __( 'The widget is set to show only on specific pages, but no page was added, so it will not appear anywhere.', 'krivo-chatflow' ), '' );
		}
		if ( Contrast::ratio( $config['bubble']['colors']['bg'], $config['bubble']['colors']['icon'] ) < 3.0 ) {
			$warnings[] = self::item( 'bubble_contrast', __( 'The bubble icon is hard to see against the bubble colour. Pick colours with more contrast.', 'krivo-chatflow' ), 'bubble' );
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether any destination that could receive this flow has a WhatsApp number.
	 *
	 * @return bool
	 */
	private static function has_whatsapp_destination(): bool {
		$routing = RoutingRepository::get();
		foreach ( $routing['destinations'] as $destination ) {
			if ( '' !== (string) $destination['whatsapp'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any destination has an e-mail address.
	 *
	 * @return bool
	 */
	private static function has_destination_email(): bool {
		foreach ( RoutingRepository::get()['destinations'] as $destination ) {
			if ( array() !== (array) $destination['emails'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A report entry.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Plain-language message.
	 * @param string $ref     Reference (field, step, rule or automation id).
	 * @return array{code:string,message:string,ref:string}
	 */
	private static function item( string $code, string $message, string $ref ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'ref'     => $ref,
		);
	}
}
