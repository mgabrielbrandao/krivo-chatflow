<?php
/**
 * Sample data for previews and tests.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Obviously fake lead data, shaped like a real lead of a given flow. Used by the dry run (message and
 * e-mail previews), the payload preview and the "test webhook" button, so nothing real is ever sent
 * during a test and a receiver can recognise a test by the "test": true marker.
 */
final class SampleData {

	/**
	 * Builds a sample lead row for a flow.
	 *
	 * @param array $flow Flow configuration.
	 * @return array Lead row (id 0).
	 */
	public static function lead_for_flow( array $flow ): array {
		$answers = array();
		foreach ( Builder::fields( $flow ) as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$key  = (string) ( $field['key'] ?? '' );
			if ( '' === $key || ! \Krivo\ChatFlow\Core\Fields\Registry::collects( $type ) ) {
				continue;
			}
			list( $value, $display ) = self::sample_value( $field );
			$answers[ $key ]         = array(
				'label'   => (string) ( $field['label'] ?? $key ),
				'type'    => $type,
				'value'   => $value,
				'display' => $display,
			);
		}

		$name  = 'Maria Teste';
		$email = 'maria.teste@example.invalid';
		$phone = '+5511999990000';
		foreach ( $answers as $answer ) {
			if ( 'name' === $answer['type'] ) {
				$name = $answer['display'];
			} elseif ( 'email' === $answer['type'] ) {
				$email = $answer['display'];
			} elseif ( in_array( $answer['type'], array( 'phone', 'whatsapp' ), true ) ) {
				$phone = $answer['display'];
			}
		}

		return array(
			'id'                => 0,
			'public_id'         => '00000000-0000-4000-8000-000000000000',
			'flow_id'           => 0,
			'status'            => 'new',
			'name'              => $name,
			'email'             => $email,
			'phone'             => $phone,
			'destination_key'   => '',
			'destination_label' => __( 'Sample destination', 'krivo-chatflow' ),
			'channel'           => 'whatsapp',
			'in_hours'          => null,
			'device'            => 'desktop',
			'answers'           => $answers,
			'consent'           => array(),
			'page_url'          => home_url( '/sample-page/' ),
			'page_title'        => __( 'Sample page', 'krivo-chatflow' ),
			'referrer'          => '',
			'utm_source'        => 'newsletter',
			'utm_medium'        => 'email',
			'utm_campaign'      => 'sample',
			'utm_content'       => '',
			'utm_term'          => '',
			'created_at'        => Clock::now(),
		);
	}

	/**
	 * A sample value (and its display text) for a field.
	 *
	 * @param array $field Field definition.
	 * @return array{0:mixed,1:string}
	 */
	private static function sample_value( array $field ): array {
		switch ( $field['type'] ?? '' ) {
			case 'name':
				return array( 'Maria Teste', 'Maria Teste' );
			case 'email':
				return array( 'maria.teste@example.invalid', 'maria.teste@example.invalid' );
			case 'phone':
			case 'whatsapp':
				return array( '+5511999990000', '+5511999990000' );
			case 'textarea':
				$text = __( 'This is a sample message written to preview how your template looks.', 'krivo-chatflow' );
				return array( $text, $text );
			case 'number':
				return array( '1500', '1500' );
			case 'date':
				return array( '2026-01-15', '2026-01-15' );
			case 'time':
				return array( '14:30', '14:30' );
			case 'url':
				return array( 'https://example.invalid/', 'https://example.invalid/' );
			case 'consent':
				return array( true, __( 'Accepted', 'krivo-chatflow' ) );
			case 'select':
			case 'radio':
				$option = $field['options'][0] ?? array(
					'value' => 'option',
					'label' => 'Option',
				);
				return array( (string) $option['value'], (string) $option['label'] );
			case 'checkbox':
				$option = $field['options'][0] ?? array(
					'value' => 'option',
					'label' => 'Option',
				);
				return array( array( (string) $option['value'] ), (string) $option['label'] );
			default:
				return array( __( 'Sample', 'krivo-chatflow' ), __( 'Sample', 'krivo-chatflow' ) );
		}
	}
}
