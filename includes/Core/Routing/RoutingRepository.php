<?php
/**
 * Routing storage.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Routing;

use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Whatsapp\Phone;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Destinations (who receives a lead: WhatsApp number and e-mails) and routing rules (which condition
 * sends a lead to which destination). Stored in one non-autoloaded option with an optimistic
 * revision so two administrators cannot silently overwrite each other.
 */
final class RoutingRepository {

	public const OPTION = 'krivo_chatflow_routing';

	public const MAX_DESTINATIONS = 50;
	public const MAX_RULES        = 100;
	public const MAX_EMAILS       = 10;

	/**
	 * Current routing configuration.
	 *
	 * @return array{revision:int,default_destination:string,destinations:array,rules:array}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'revision'            => (int) ( $stored['revision'] ?? 0 ),
			'default_destination' => (string) ( $stored['default_destination'] ?? '' ),
			'destinations'        => array_values( (array) ( $stored['destinations'] ?? array() ) ),
			'rules'               => array_values( (array) ( $stored['rules'] ?? array() ) ),
		);
	}

	/**
	 * Finds a destination by id.
	 *
	 * @param string $id Destination id.
	 * @return array|null
	 */
	public static function destination( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		foreach ( self::get()['destinations'] as $destination ) {
			if ( ( $destination['id'] ?? '' ) === $id ) {
				return $destination;
			}
		}
		return null;
	}

	/**
	 * Sanitises raw routing input.
	 *
	 * @param array $raw Raw {default_destination, destinations[], rules[]}.
	 * @return array
	 */
	public static function sanitize( array $raw ): array {
		$destinations = array();
		$used         = array();
		$renamed      = array(); // Id typed by the client => id actually stored, so references keep working.
		foreach ( is_array( $raw['destinations'] ?? null ) ? array_values( $raw['destinations'] ) : array() as $item ) {
			if ( count( $destinations ) >= self::MAX_DESTINATIONS || ! is_array( $item ) ) {
				continue;
			}
			$typed_id = isset( $item['id'] ) ? (string) $item['id'] : '';
			$id       = $typed_id;
			if ( ! preg_match( '/^dst_[a-z0-9]{4,16}$/', $id ) || in_array( $id, $used, true ) ) {
				do {
					$id = Text::random_id( 'dst_', 6 );
				} while ( in_array( $id, $used, true ) );
			}
			$used[] = $id;
			if ( '' !== $typed_id && ! isset( $renamed[ $typed_id ] ) ) {
				$renamed[ $typed_id ] = $id;
			}

			$dial     = substr( (string) preg_replace( '/\D+/', '', (string) ( $item['dial_code'] ?? '' ) ), 0, 4 );
			$national = substr( (string) preg_replace( '/\D+/', '', (string) ( $item['number'] ?? '' ) ), 0, 15 );
			$name     = Sanitizer::str( $item['name'] ?? '', 80 );

			$emails = array();
			foreach ( is_array( $item['emails'] ?? null ) ? $item['emails'] : array() as $email ) {
				$clean = Sanitizer::email( $email );
				if ( '' !== $clean && ! in_array( $clean, $emails, true ) && count( $emails ) < self::MAX_EMAILS ) {
					$emails[] = $clean;
				}
			}

			$destinations[] = array(
				'id'        => $id,
				'name'      => '' !== $name ? $name : __( 'Destination', 'krivo-chatflow' ),
				'dial_code' => $dial,
				'number'    => $national,
				'whatsapp'  => '' === $national ? '' : Phone::destination_digits( $dial, $national ),
				'emails'    => $emails,
				'note'      => Sanitizer::str( $item['note'] ?? '', 200 ),
			);
		}

		$rules = array();
		$ruled = array();
		foreach ( is_array( $raw['rules'] ?? null ) ? array_values( $raw['rules'] ) : array() as $item ) {
			if ( count( $rules ) >= self::MAX_RULES || ! is_array( $item ) ) {
				continue;
			}
			$target = isset( $item['destination'] ) ? (string) $item['destination'] : '';
			$target = $renamed[ $target ] ?? $target;
			if ( ! in_array( $target, $used, true ) ) {
				continue; // A rule must point at an existing destination (validate() reports it).
			}
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( ! preg_match( '/^[a-z0-9_]{1,24}$/', $id ) || in_array( $id, $ruled, true ) ) {
				do {
					$id = Text::random_id( 'rl_', 6 );
				} while ( in_array( $id, $ruled, true ) );
			}
			$ruled[] = $id;
			$flows   = array();
			foreach ( is_array( $item['flows'] ?? null ) ? $item['flows'] : array() as $flow_id ) {
				$flow_id = absint( $flow_id );
				if ( $flow_id > 0 && ! in_array( $flow_id, $flows, true ) ) {
					$flows[] = $flow_id;
				}
			}
			$rules[] = array(
				'id'          => $id,
				'name'        => Sanitizer::str( $item['name'] ?? '', 80 ),
				'enabled'     => ! isset( $item['enabled'] ) || Sanitizer::bool( $item['enabled'] ),
				'flows'       => $flows,
				'when'        => Sanitizer::group( $item['when'] ?? array() ),
				'destination' => $target,
			);
		}

		$default = isset( $raw['default_destination'] ) ? (string) $raw['default_destination'] : '';
		$default = $renamed[ $default ] ?? $default;
		return array(
			'default_destination' => in_array( $default, $used, true ) ? $default : '',
			'destinations'        => $destinations,
			'rules'               => $rules,
		);
	}

	/**
	 * Validates a sanitised configuration and returns human errors (empty when valid).
	 *
	 * @param array $raw   What the user typed.
	 * @param array $clean Sanitised result.
	 * @return string[]
	 */
	public static function validate( array $raw, array $clean ): array {
		$errors = array();
		foreach ( array_values( (array) ( $raw['destinations'] ?? array() ) ) as $i => $item ) {
			$typed = preg_replace( '/\D+/', '', (string) ( $item['number'] ?? '' ) );
			$dest  = $clean['destinations'][ $i ] ?? null;
			if ( '' !== $typed && $dest && '' === $dest['whatsapp'] ) {
				/* translators: %s: destination name. */
				$errors[] = sprintf( __( 'The WhatsApp number of "%s" is not valid. Use the country code and the number with area code.', 'krivo-chatflow' ), $dest['name'] );
			}
			foreach ( (array) ( $item['emails'] ?? array() ) as $email ) {
				if ( is_string( $email ) && '' !== trim( $email ) && '' === Sanitizer::email( $email ) ) {
					/* translators: %s: e-mail typed by the user. */
					$errors[] = sprintf( __( '"%s" is not a valid e-mail address.', 'krivo-chatflow' ), Sanitizer::str( $email, 80 ) );
				}
			}
		}

		// A rule that cannot be kept must say so: silently dropping it would change who receives leads.
		// In particular a condition dropped as invalid would leave an empty group, and an empty group
		// matches everybody, so the rule would send every lead to its destination.
		$known = array_column( $clean['destinations'], 'id' );
		foreach ( array_values( (array) ( $raw['destinations'] ?? array() ) ) as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$known[] = (string) $item['id'];
			}
		}
		foreach ( array_values( (array) ( $raw['rules'] ?? array() ) ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rule_name = Sanitizer::str( $item['name'] ?? '', 80 );
			$label     = '' !== $rule_name ? $rule_name : __( 'Untitled rule', 'krivo-chatflow' );
			if ( ! in_array( (string) ( $item['destination'] ?? '' ), $known, true ) ) {
				/* translators: %s: rule name. */
				$errors[] = sprintf( __( 'The rule "%s" sends leads to a destination that does not exist.', 'krivo-chatflow' ), $label );
				continue;
			}
			$typed = is_array( $item['when']['conditions'] ?? null ) ? count( $item['when']['conditions'] ) : 0;
			$kept  = count( Sanitizer::group( $item['when'] ?? array() )['conditions'] );
			if ( 0 === $typed ) {
				/* translators: %s: rule name. */
				$errors[] = sprintf( __( 'The rule "%s" has no condition. Add at least one, or remove the rule.', 'krivo-chatflow' ), $label );
			} elseif ( $kept < $typed ) {
				/* translators: %s: rule name. */
				$errors[] = sprintf( __( 'The rule "%s" has a condition that is not valid. Check the field, the operator and the value.', 'krivo-chatflow' ), $label );
			}
		}
		return $errors;
	}

	/**
	 * Saves the routing configuration.
	 *
	 * @param array    $raw           Raw input.
	 * @param int|null $base_revision Revision the editor started from (null skips the check).
	 * @return array|\WP_Error The stored configuration, or an error (conflict / validation).
	 */
	public static function save( array $raw, ?int $base_revision = null ) {
		$current = self::get();
		if ( null !== $base_revision && $base_revision !== $current['revision'] ) {
			return new \WP_Error( 'krivo_chatflow_conflict', __( 'Someone else changed the routing. Reload the page and try again.', 'krivo-chatflow' ), array( 'status' => 409 ) );
		}
		$clean  = self::sanitize( $raw );
		$errors = self::validate( $raw, $clean );
		if ( array() !== $errors ) {
			return new \WP_Error( 'krivo_chatflow_invalid', implode( ' ', $errors ), array( 'status' => 400 ) );
		}
		$clean['revision'] = $current['revision'] + 1;
		update_option( self::OPTION, $clean, false );
		return self::get();
	}
}
