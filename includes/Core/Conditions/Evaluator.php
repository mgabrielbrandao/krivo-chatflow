<?php
/**
 * Condition evaluator.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Conditions;

use Krivo\ChatFlow\Core\Fields\Types;

defined( 'ABSPATH' ) || exit;

/**
 * The single condition engine used for field visibility, jumps, routing and (server side) display
 * rules. A JavaScript twin (src/shared/conditions.js) is checked against the same fixtures
 * (tests/fixtures/conditions.json), so both sides always agree.
 *
 * A group is { match: "all"|"any", conditions: [ { left, op, right } | <group> ] }.
 * Operands: "field:<key>" or "ctx:<name>". An empty group matches (explicit "always").
 */
final class Evaluator {

	public const OPERATORS = array(
		'eq',
		'neq',
		'contains',
		'not_contains',
		'starts_with',
		'ends_with',
		'gt',
		'gte',
		'lt',
		'lte',
		'empty',
		'not_empty',
		'in',
		'matches',
	);

	/**
	 * Whether a group of conditions holds.
	 *
	 * @param array $group  Condition group.
	 * @param array $values Answers by field key (already normalised).
	 * @param array $ctx    Context values (page_path, device, utm_*, in_hours...).
	 * @return bool
	 */
	public static function matches( array $group, array $values, array $ctx = array() ): bool {
		$conditions = isset( $group['conditions'] ) && is_array( $group['conditions'] ) ? $group['conditions'] : array();
		if ( array() === $conditions ) {
			return true;
		}
		$any = isset( $group['match'] ) && 'any' === $group['match'];

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				$result = false;
			} elseif ( isset( $condition['conditions'] ) ) {
				$result = self::matches( $condition, $values, $ctx );
			} else {
				$result = self::condition( $condition, $values, $ctx );
			}

			if ( $any && $result ) {
				return true;
			}
			if ( ! $any && ! $result ) {
				return false;
			}
		}
		return ! $any;
	}

	/**
	 * Evaluates one condition.
	 *
	 * @param array $condition { left, op, right }.
	 * @param array $values    Answers by key.
	 * @param array $ctx       Context.
	 * @return bool
	 */
	public static function condition( array $condition, array $values, array $ctx ): bool {
		$op   = isset( $condition['op'] ) ? (string) $condition['op'] : '';
		$left = self::operand( isset( $condition['left'] ) ? (string) $condition['left'] : '', $values, $ctx );

		if ( null === $left || ! in_array( $op, self::OPERATORS, true ) ) {
			return false;
		}

		$right = isset( $condition['right'] ) ? $condition['right'] : '';
		$items = is_array( $left ) ? self::items( $left ) : array( self::norm( $left ) );
		$one   = is_array( $left ) ? ( 1 === count( $items ) ? $items[0] : null ) : $items[0];
		$r     = is_array( $right ) ? '' : self::norm( $right );

		switch ( $op ) {
			case 'empty':
				return self::is_empty( $items );
			case 'not_empty':
				return ! self::is_empty( $items );
			case 'eq':
				return null !== $one && self::equal( $one, $r );
			case 'neq':
				return ! ( null !== $one && self::equal( $one, $r ) );
			case 'contains':
				return self::contains( $left, $items, $r );
			case 'not_contains':
				return ! self::contains( $left, $items, $r );
			case 'starts_with':
				return ! is_array( $left ) && '' !== $r && 0 === mb_strpos( (string) $one, $r );
			case 'ends_with':
				return ! is_array( $left ) && '' !== $r && mb_substr( (string) $one, -mb_strlen( $r ) ) === $r;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				return ! is_array( $left ) && self::compare_numbers( $op, (string) $one, $r );
			case 'in':
				return self::in_list( $items, $right );
			case 'matches':
				foreach ( $items as $item ) {
					if ( '' !== $r && Wildcard::match( $r, $item ) ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}

	/**
	 * Resolves a left operand to a scalar, a list or null (unknown operand).
	 *
	 * @param string $left   Operand such as "field:servico" or "ctx:device".
	 * @param array  $values Answers.
	 * @param array  $ctx    Context.
	 * @return mixed
	 */
	private static function operand( string $left, array $values, array $ctx ) {
		if ( 0 === strpos( $left, 'field:' ) ) {
			$key = substr( $left, 6 );
			return array_key_exists( $key, $values ) ? $values[ $key ] : '';
		}
		if ( 0 === strpos( $left, 'ctx:' ) ) {
			$key = substr( $left, 4 );
			return array_key_exists( $key, $ctx ) ? $ctx[ $key ] : '';
		}
		return null;
	}

	/**
	 * Normalises a scalar for comparison: trimmed, lowercase. "true" answers read as "yes".
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function norm( $value ): string {
		if ( true === $value ) {
			return 'yes';
		}
		if ( false === $value || null === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_strtolower( trim( (string) $value ) );
	}

	/**
	 * Normalised items of a list value.
	 *
	 * @param array $list List.
	 * @return string[]
	 */
	private static function items( array $list ): array {
		$items = array();
		foreach ( $list as $item ) {
			if ( is_scalar( $item ) || is_bool( $item ) ) {
				$items[] = self::norm( $item );
			}
		}
		return array_values( $items );
	}

	/**
	 * Whether every item is empty.
	 *
	 * @param string[] $items Normalised items.
	 * @return bool
	 */
	private static function is_empty( array $items ): bool {
		foreach ( $items as $item ) {
			if ( '' !== $item ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Equality: exact after normalisation, or numerically equal ("10.000" equals "10000").
	 *
	 * @param string $a Normalised left.
	 * @param string $b Normalised right.
	 * @return bool
	 */
	private static function equal( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$na = Types::parse_decimal( $a );
		$nb = Types::parse_decimal( $b );
		return null !== $na && null !== $nb && $na === $nb;
	}

	/**
	 * "contains": substring for text, exact membership for lists. An empty needle never matches.
	 *
	 * @param mixed    $left  Raw left operand.
	 * @param string[] $items Normalised items.
	 * @param string   $r     Normalised right.
	 * @return bool
	 */
	private static function contains( $left, array $items, string $r ): bool {
		if ( '' === $r ) {
			return false;
		}
		if ( is_array( $left ) ) {
			return in_array( $r, $items, true );
		}
		return false !== mb_strpos( $items[0], $r );
	}

	/**
	 * Numeric comparison; false when either side is not a number.
	 *
	 * @param string $op    gt, gte, lt or lte.
	 * @param string $left  Normalised left.
	 * @param string $right Normalised right.
	 * @return bool
	 */
	private static function compare_numbers( string $op, string $left, string $right ): bool {
		$a = Types::parse_decimal( $left );
		$b = Types::parse_decimal( $right );
		if ( null === $a || null === $b ) {
			return false;
		}
		$a = (float) $a;
		$b = (float) $b;
		switch ( $op ) {
			case 'gt':
				return $a > $b;
			case 'gte':
				return $a >= $b;
			case 'lt':
				return $a < $b;
			default:
				return $a <= $b;
		}
	}

	/**
	 * "in" a list given as an array or as comma/newline separated text.
	 *
	 * @param string[] $items Normalised left items.
	 * @param mixed    $right List.
	 * @return bool
	 */
	private static function in_list( array $items, $right ): bool {
		$list = is_array( $right ) ? $right : preg_split( '/[\r\n,]+/', (string) $right );
		$list = array_filter(
			array_map( array( __CLASS__, 'norm' ), (array) $list ),
			static function ( $entry ) {
				return '' !== $entry;
			}
		);
		foreach ( $items as $item ) {
			foreach ( $list as $entry ) {
				if ( '' !== $item && self::equal( $item, $entry ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
