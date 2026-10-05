<?php
/**
 * Starter flow templates.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Automation\Actions\SendEmail;
use Krivo\ChatFlow\Core\Whatsapp\ButtonLink;

defined( 'ABSPATH' ) || exit;

/**
 * Ready-made flows for common businesses. Everything is translatable; field keys (the
 * {variables}) are generated from the translated labels, so a Portuguese site gets {nome} and an
 * English site gets {name}.
 */
final class Templates {

	/**
	 * Template catalogue for the admin.
	 *
	 * @return array[] id, name, description, fields (labels), whatsapp (default).
	 */
	public static function all(): array {
		$list = array();
		foreach ( self::definitions() as $id => $def ) {
			$list[] = array(
				'id'          => $id,
				'name'        => $def['name'],
				'description' => $def['description'],
				'fields'      => array_map(
					static function ( $field ) {
						return $field['label'];
					},
					$def['fields']
				),
				'whatsapp'    => $def['whatsapp'],
				'mode'        => $def['mode'] ?? 'form',
			);
		}
		return $list;
	}

	/**
	 * Builds a flow configuration from a template.
	 *
	 * @param string $id   Template id.
	 * @param array  $opts whatsapp (bool) and name (string) overrides.
	 * @return array|null Raw configuration (pass it through Sanitizer), null for an unknown template.
	 */
	public static function build( string $id, array $opts = array() ): ?array {
		$defs = self::definitions();
		if ( ! isset( $defs[ $id ] ) ) {
			return null;
		}
		$def      = $defs[ $id ];
		$whatsapp = array_key_exists( 'whatsapp', $opts ) ? (bool) $opts['whatsapp'] : $def['whatsapp'];
		$config   = Defaults::blank( isset( $opts['name'] ) && '' !== $opts['name'] ? (string) $opts['name'] : $def['name'] );
		if ( 'button' === ( $def['mode'] ?? '' ) ) {
			return self::build_button( $config, $def );
		}

		$config['steps'][0]['fields']        = $def['fields'];
		$config['whatsapp']['message']       = $def['message'];
		$config['finish']['success_message'] = __( 'Thank you, {name}! We received your message and will get back to you soon.', 'krivo-chatflow' );

		$email                 = ( new SendEmail() )->defaults();
		$config['automations'] = array(
			array(
				'id'      => 'a_notify',
				'trigger' => 'form.submitted',
				'type'    => 'send_email',
				'enabled' => true,
				'managed' => false,
				'config'  => $email,
			),
		);
		if ( $whatsapp ) {
			$config['automations'][] = array(
				'id'      => 'a_whatsapp',
				'trigger' => 'form.submitted',
				'type'    => 'open_whatsapp',
				'enabled' => true,
				'managed' => true,
				'config'  => array( 'message' => '' ),
			);
		}
		return $config;
	}

	/**
	 * A "button only" template: no fields, no e-mail; the welcome card does the talking.
	 *
	 * @param array $config Blank configuration.
	 * @param array $def    Template definition.
	 * @return array
	 */
	private static function build_button( array $config, array $def ): array {
		$config['mode']                       = 'button';
		$config['button']['action']           = 'whatsapp';
		$config['whatsapp']['message']        = ButtonLink::default_message();
		$config['bubble']['label']            = $def['label'];
		$config['bubble']['tooltip']['title'] = $def['title'];
		$config['bubble']['tooltip']['text']  = $def['text'];
		$config['bubble']['tooltip']['cta']   = $def['cta'];
		$config['bubble']['tooltip']['delay'] = 2;
		// The card stays until the visitor dismisses it or taps the button: it is the whole message.
		$config['bubble']['tooltip']['duration'] = 0;
		$config['bubble']['tooltip']['once']     = false;
		return $config;
	}

	/**
	 * Template definitions.
	 *
	 * @return array<string,array>
	 */
	private static function definitions(): array {
		$f      = static function ( string $type, string $label, array $extra = array() ): array {
			return array_merge(
				array(
					'type'  => $type,
					'label' => $label,
				),
				$extra
			);
		};
		$choice = static function ( array $labels ): array {
			$options = array();
			foreach ( $labels as $label ) {
				$options[] = array( 'label' => $label );
			}
			return $options;
		};

		return array(
			'whatsapp_button' => array(
				'name'        => __( 'WhatsApp button', 'krivo-chatflow' ),
				'description' => __( 'No form: just a floating button with a welcome message that opens WhatsApp.', 'krivo-chatflow' ),
				'mode'        => 'button',
				'whatsapp'    => true,
				'message'     => '',
				'fields'      => array(),
				'label'       => __( 'Chat on WhatsApp', 'krivo-chatflow' ),
				'title'       => __( 'Hello!', 'krivo-chatflow' ),
				'text'        => __( 'How can we help? Talk to us on WhatsApp.', 'krivo-chatflow' ),
				'cta'         => __( 'Chat on WhatsApp', 'krivo-chatflow' ),
			),
			'contact'         => array(
				'name'        => __( 'Simple contact', 'krivo-chatflow' ),
				'description' => __( 'Name, WhatsApp and a message. The fastest way to start.', 'krivo-chatflow' ),
				'whatsapp'    => true,
				'message'     => __( "Hello! My name is {name}.\n\n{all_fields}\n\nPage: {page_url}", 'krivo-chatflow' ),
				'fields'      => array(
					$f(
						'name',
						__( 'Name', 'krivo-chatflow' ),
						array(
							'required'    => true,
							'placeholder' => __( 'Your name', 'krivo-chatflow' ),
						)
					),
					$f(
						'whatsapp',
						__( 'WhatsApp', 'krivo-chatflow' ),
						array(
							'required'    => true,
							'placeholder' => __( '(00) 00000-0000', 'krivo-chatflow' ),
						)
					),
					$f( 'textarea', __( 'Message', 'krivo-chatflow' ), array( 'placeholder' => __( 'How can we help?', 'krivo-chatflow' ) ) ),
				),
			),
			'quote'           => array(
				'name'        => __( 'Request a quote', 'krivo-chatflow' ),
				'description' => __( 'Qualify the project before the conversation: company, service and budget.', 'krivo-chatflow' ),
				'whatsapp'    => true,
				'message'     => __( "Hello! I'd like a quote. My name is {name}.\n\n{all_fields}", 'krivo-chatflow' ),
				'fields'      => array(
					$f( 'name', __( 'Name', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f( 'text', __( 'Company', 'krivo-chatflow' ) ),
					$f(
						'select',
						__( 'Service', 'krivo-chatflow' ),
						array(
							'required' => true,
							'options'  => $choice( array( __( 'Website', 'krivo-chatflow' ), __( 'Automation', 'krivo-chatflow' ), __( 'Support', 'krivo-chatflow' ), __( 'Other', 'krivo-chatflow' ) ) ),
						)
					),
					$f( 'number', __( 'Budget', 'krivo-chatflow' ), array( 'placeholder' => __( 'Estimated amount', 'krivo-chatflow' ) ) ),
					$f( 'textarea', __( 'Message', 'krivo-chatflow' ), array( 'placeholder' => __( 'Tell us a bit about the project', 'krivo-chatflow' ) ) ),
				),
			),
			'support'         => array(
				'name'        => __( 'Support', 'krivo-chatflow' ),
				'description' => __( 'Collect the product and the problem so the team can answer faster.', 'krivo-chatflow' ),
				'whatsapp'    => false,
				'message'     => __( "Hello! I need support. My name is {name}.\n\n{all_fields}", 'krivo-chatflow' ),
				'fields'      => array(
					$f( 'name', __( 'Name', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f( 'email', __( 'E-mail', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f( 'text', __( 'Product', 'krivo-chatflow' ) ),
					$f(
						'textarea',
						__( 'Problem', 'krivo-chatflow' ),
						array(
							'required'    => true,
							'placeholder' => __( 'Describe what is happening', 'krivo-chatflow' ),
						)
					),
				),
			),
			'clinic'          => array(
				'name'        => __( 'Clinic', 'krivo-chatflow' ),
				'description' => __( 'Appointment requests with the type of care.', 'krivo-chatflow' ),
				'whatsapp'    => true,
				'message'     => __( "Hello! I'd like to schedule an appointment. My name is {name}.\n\n{all_fields}", 'krivo-chatflow' ),
				'fields'      => array(
					$f( 'name', __( 'Name', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f( 'phone', __( 'Phone', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f(
						'select',
						__( 'Type of care', 'krivo-chatflow' ),
						array(
							'required' => true,
							'options'  => $choice( array( __( 'First appointment', 'krivo-chatflow' ), __( 'Return visit', 'krivo-chatflow' ), __( 'Exam', 'krivo-chatflow' ), __( 'Other', 'krivo-chatflow' ) ) ),
						)
					),
					$f( 'textarea', __( 'Message', 'krivo-chatflow' ) ),
				),
			),
			'real_estate'     => array(
				'name'        => __( 'Real estate', 'krivo-chatflow' ),
				'description' => __( 'Property type and price range, so the broker calls with the right options.', 'krivo-chatflow' ),
				'whatsapp'    => true,
				'message'     => __( "Hello! I'm looking for a property. My name is {name}.\n\n{all_fields}", 'krivo-chatflow' ),
				'fields'      => array(
					$f( 'name', __( 'Name', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f( 'phone', __( 'Phone', 'krivo-chatflow' ), array( 'required' => true ) ),
					$f(
						'select',
						__( 'Property type', 'krivo-chatflow' ),
						array(
							'required' => true,
							'options'  => $choice( array( __( 'Apartment', 'krivo-chatflow' ), __( 'House', 'krivo-chatflow' ), __( 'Commercial', 'krivo-chatflow' ), __( 'Land', 'krivo-chatflow' ) ) ),
						)
					),
					$f(
						'select',
						__( 'Price range', 'krivo-chatflow' ),
						array(
							'options' => $choice( array( __( 'Up to 300k', 'krivo-chatflow' ), __( '300k to 600k', 'krivo-chatflow' ), __( '600k to 1M', 'krivo-chatflow' ), __( 'Above 1M', 'krivo-chatflow' ) ) ),
						)
					),
				),
			),
		);
	}
}
