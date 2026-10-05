<?php
/**
 * Visitor journey calculation.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Core\Conditions\Evaluator;
use Krivo\ChatFlow\Core\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Computes which fields the visitor sees and which steps they walk through, given their answers.
 *
 * The server recomputes this on every submission (a field hidden by logic is neither required nor
 * stored). The widget runs the same algorithm in JavaScript (src/shared/journey.js); both are
 * verified against tests/fixtures/journey.json.
 *
 * Semantics:
 *  - A flow is a list of "units": one unit for the whole form, one per step, or one per field
 *    (conversational mode).
 *  - Fields are evaluated in order. A rule can only depend on answers of fields shown BEFORE it:
 *    answers of hidden or skipped fields do not exist for later conditions.
 *  - show_field: the field is hidden by default and shown when a rule matches.
 *    hide_field: the field is shown by default and hidden when a rule matches. Hide wins.
 *  - goto / finish rules are checked when leaving the unit named in "after". The first matching
 *    rule wins. goto only moves forward (no loops).
 */
final class Journey {

	/**
	 * Builds the journey units of a flow.
	 *
	 * @param array $flow Flow config (mode, steps).
	 * @return array<int,array{id:string,fields:array}>
	 */
	public static function units( array $flow ): array {
		$mode  = isset( $flow['mode'] ) ? (string) $flow['mode'] : 'form';
		$steps = isset( $flow['steps'] ) && is_array( $flow['steps'] ) ? $flow['steps'] : array();

		if ( 'steps' === $mode ) {
			$units = array();
			foreach ( $steps as $step ) {
				$units[] = array(
					'id'     => (string) ( $step['id'] ?? '' ),
					'fields' => isset( $step['fields'] ) && is_array( $step['fields'] ) ? $step['fields'] : array(),
				);
			}
			return $units;
		}

		$all = array();
		foreach ( $steps as $step ) {
			foreach ( ( isset( $step['fields'] ) && is_array( $step['fields'] ) ? $step['fields'] : array() ) as $field ) {
				$all[] = $field;
			}
		}

		if ( 'conversational' === $mode ) {
			return array_map(
				static function ( $field ) {
					return array(
						'id'     => (string) ( $field['id'] ?? '' ),
						'fields' => array( $field ),
					);
				},
				$all
			);
		}

		return array(
			array(
				'id'     => 'all',
				'fields' => $all,
			),
		);
	}

	/**
	 * Computes the journey.
	 *
	 * @param array $flow   Flow config.
	 * @param array $values Normalised answers by field key.
	 * @param array $ctx    Context for conditions.
	 * @return array{visible:array<string,bool>,visited:string[],finished:bool}
	 */
	public static function compute( array $flow, array $values, array $ctx = array() ): array {
		$units = self::units( $flow );
		$rules = isset( $flow['logic']['rules'] ) && is_array( $flow['logic']['rules'] ) ? $flow['logic']['rules'] : array();

		$visible  = array();
		$visited  = array();
		$seen     = array();
		$finished = false;

		// Every field starts invisible; walking a unit decides.
		foreach ( $units as $unit ) {
			foreach ( $unit['fields'] as $field ) {
				$visible[ (string) ( $field['id'] ?? '' ) ] = false;
			}
		}

		$count = count( $units );
		$i     = 0;
		while ( $i < $count ) {
			$unit      = $units[ $i ];
			$visited[] = $unit['id'];

			foreach ( $unit['fields'] as $field ) {
				$id             = (string) ( $field['id'] ?? '' );
				$is_visible     = self::field_visible( $field, $rules, $seen, $ctx );
				$visible[ $id ] = $is_visible;
				if ( $is_visible && Registry::collects( (string) ( $field['type'] ?? '' ) ) ) {
					$key          = (string) ( $field['key'] ?? '' );
					$seen[ $key ] = array_key_exists( $key, $values ) ? $values[ $key ] : '';
				}
			}

			$next = $i + 1;
			foreach ( $rules as $rule ) {
				foreach ( (array) ( $rule['then'] ?? array() ) as $action ) {
					$do = isset( $action['do'] ) ? $action['do'] : '';
					if ( ! in_array( $do, array( 'goto', 'finish' ), true ) || ( $rule['after'] ?? '' ) !== $unit['id'] ) {
						continue;
					}
					if ( ! Evaluator::matches( (array) ( $rule['when'] ?? array() ), $seen, $ctx ) ) {
						continue;
					}
					if ( 'finish' === $do ) {
						$finished = true;
						break 3;
					}
					$target = self::unit_index( $units, (string) ( $action['target'] ?? '' ) );
					if ( null !== $target && $target > $i ) {
						$next = $target;
						break 2;
					}
				}
			}
			$i = $next;
		}

		return array(
			'visible'  => $visible,
			'visited'  => $visited,
			'finished' => $finished,
		);
	}

	/**
	 * Visibility of one field given the answers seen so far.
	 *
	 * @param array $field Field definition.
	 * @param array $rules Logic rules.
	 * @param array $seen  Answers of earlier visible fields.
	 * @param array $ctx   Context.
	 * @return bool
	 */
	private static function field_visible( array $field, array $rules, array $seen, array $ctx ): bool {
		$id       = (string) ( $field['id'] ?? '' );
		$has_show = false;
		$show_hit = false;
		$hide_hit = false;

		foreach ( $rules as $rule ) {
			foreach ( (array) ( $rule['then'] ?? array() ) as $action ) {
				$do = isset( $action['do'] ) ? $action['do'] : '';
				if ( ( $action['target'] ?? '' ) !== $id || ! in_array( $do, array( 'show_field', 'hide_field' ), true ) ) {
					continue;
				}
				$hit = Evaluator::matches( (array) ( $rule['when'] ?? array() ), $seen, $ctx );
				if ( 'show_field' === $do ) {
					$has_show = true;
					$show_hit = $show_hit || $hit;
				} else {
					$hide_hit = $hide_hit || $hit;
				}
			}
		}

		if ( $hide_hit ) {
			return false;
		}
		return $has_show ? $show_hit : true;
	}

	/**
	 * Index of a unit by id.
	 *
	 * @param array  $units Units.
	 * @param string $id    Unit id (step id or field id).
	 * @return int|null
	 */
	private static function unit_index( array $units, string $id ): ?int {
		foreach ( $units as $index => $unit ) {
			if ( $unit['id'] === $id ) {
				return $index;
			}
		}
		return null;
	}
}
