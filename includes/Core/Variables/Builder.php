<?php
/**
 * Template variable builder.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Variables;

use Krivo\ChatFlow\Core\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the variable map used by every template (WhatsApp message, e-mail, webhook payload, success
 * message) from a flow, the stored answers and the tracking context.
 */
final class Builder {

	/**
	 * Names that user-defined field keys may not use, because they would shadow a system variable.
	 *
	 * @return string[]
	 */
	public static function reserved(): array {
		return array(
			'all_fields',
			'respostas',
			'lead_id',
			'page_url',
			'pagina',
			'page_title',
			'pagina_titulo',
			'referrer',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_content',
			'utm_term',
			'site_name',
			'site_nome',
			'flow_name',
			'fluxo',
			'date',
			'data',
			'time',
			'hora',
			'destination',
			'destino',
		);
	}

	/**
	 * Builds the variables.
	 *
	 * @param array $flow    Flow config.
	 * @param array $answers Stored answers: key => {label,type,value,display}.
	 * @param array $meta    Optional: tracking (page_url, page_title, referrer, utm_*), lead_id, destination, timestamp.
	 * @return array<string,mixed>
	 */
	public static function build( array $flow, array $answers, array $meta = array() ): array {
		$tracking  = isset( $meta['tracking'] ) && is_array( $meta['tracking'] ) ? $meta['tracking'] : array();
		$timestamp = isset( $meta['timestamp'] ) ? (int) $meta['timestamp'] : time();

		$vars = array(
			'page_url'      => (string) ( $tracking['page_url'] ?? '' ),
			'page_title'    => (string) ( $tracking['page_title'] ?? '' ),
			'referrer'      => (string) ( $tracking['referrer'] ?? '' ),
			'utm_source'    => (string) ( $tracking['utm_source'] ?? '' ),
			'utm_medium'    => (string) ( $tracking['utm_medium'] ?? '' ),
			'utm_campaign'  => (string) ( $tracking['utm_campaign'] ?? '' ),
			'utm_content'   => (string) ( $tracking['utm_content'] ?? '' ),
			'utm_term'      => (string) ( $tracking['utm_term'] ?? '' ),
			'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'flow_name'     => (string) ( $flow['name'] ?? '' ),
			'date'          => wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp ),
			'time'          => wp_date( (string) get_option( 'time_format', 'H:i' ), $timestamp ),
			'lead_id'       => isset( $meta['lead_id'] ) ? (string) $meta['lead_id'] : '',
			'destination'   => (string) ( $meta['destination'] ?? '' ),
		);

		// Role variables: the first field of each kind, so templates work with any flow.
		$roles = array(
			'name'  => array( 'name' ),
			'email' => array( 'email' ),
			'phone' => array( 'phone', 'whatsapp' ),
		);
		foreach ( self::fields( $flow ) as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			foreach ( $roles as $role => $types ) {
				if ( ! isset( $vars[ $role ] ) && in_array( $field['type'] ?? '', $types, true ) && isset( $answers[ $key ] ) ) {
					$vars[ $role ] = self::as_string( $answers[ $key ] );
				}
			}
		}
		foreach ( array_keys( $roles ) as $role ) {
			if ( ! isset( $vars[ $role ] ) ) {
				$vars[ $role ] = '';
			}
		}

		// One variable per field key (display value: the option label for choices).
		$rows = array();
		foreach ( self::fields( $flow ) as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || ! Registry::collects( (string) ( $field['type'] ?? '' ) ) || ! isset( $answers[ $key ] ) ) {
				continue;
			}
			$display = self::as_string( $answers[ $key ] );
			if ( ! in_array( $key, self::reserved(), true ) ) {
				$vars[ $key ] = $display;
			}
			if ( 'hidden' !== ( $field['type'] ?? '' ) ) {
				$rows[] = array(
					'label' => (string) ( $answers[ $key ]['label'] ?? $field['label'] ?? $key ),
					'value' => $display,
				);
			}
		}
		$vars['all_fields'] = $rows;

		return $vars;
	}

	/**
	 * Flattens the flow's fields in order.
	 *
	 * @param array $flow Flow config.
	 * @return array[]
	 */
	public static function fields( array $flow ): array {
		$fields = array();
		foreach ( (array) ( $flow['steps'] ?? array() ) as $step ) {
			foreach ( (array) ( $step['fields'] ?? array() ) as $field ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	/**
	 * Human readable value of a stored answer.
	 *
	 * @param mixed $answer Stored answer ({display,value}) or raw value.
	 * @return string
	 */
	private static function as_string( $answer ): string {
		if ( is_array( $answer ) && array_key_exists( 'display', $answer ) ) {
			return (string) $answer['display'];
		}
		if ( is_array( $answer ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $answer, 'is_scalar' ) ) );
		}
		return is_scalar( $answer ) ? (string) $answer : '';
	}
}
