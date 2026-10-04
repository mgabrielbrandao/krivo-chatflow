<?php
/**
 * Tests for the condition engine, wildcard, decimals and the visitor journey.
 * The same JSON fixtures are executed by the JavaScript tests (tests/js), so PHP and JS cannot drift.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Conditions\Evaluator;
use Krivo\ChatFlow\Core\Conditions\Wildcard;
use Krivo\ChatFlow\Core\Fields\Types;
use Krivo\ChatFlow\Core\Flow\Journey;

/**
 * @group conditions
 */
class ConditionsTest extends Krivo_TestCase {

	/**
	 * Loads a fixture file.
	 *
	 * @param string $name File name.
	 * @return array
	 */
	private static function fixture( string $name ): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name ), true );
		return is_array( $data ) ? $data : array();
	}

	public function condition_cases(): array {
		$cases = array();
		foreach ( self::fixture( 'conditions.json' ) as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider condition_cases
	 */
	public function test_conditions_fixture( array $case ): void {
		$this->assertSame(
			$case['expect'],
			Evaluator::matches( $case['group'], $case['values'], $case['ctx'] ),
			$case['name']
		);
	}

	public function wildcard_cases(): array {
		$cases = array();
		foreach ( self::fixture( 'wildcard.json' ) as $i => $case ) {
			$cases[ sprintf( '#%d %s ~ %s', $i, $case['pattern'], substr( $case['subject'], 0, 20 ) ) ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider wildcard_cases
	 */
	public function test_wildcard_fixture( array $case ): void {
		$this->assertSame( $case['expect'], Wildcard::match( $case['pattern'], $case['subject'] ) );
	}

	public function test_wildcard_is_fast_on_hostile_input(): void {
		$start = microtime( true );
		Wildcard::match( '*a*a*a*a*a*a*a*a*b', str_repeat( 'a', 4000 ) );
		$this->assertLessThan( 1.0, microtime( true ) - $start, 'Pathological pattern must not blow up' );
		$this->assertFalse( Wildcard::match( str_repeat( '*a', 400 ) . 'b', 'a' ), 'Over-long patterns are refused' );
	}

	public function decimal_cases(): array {
		$cases = array();
		foreach ( self::fixture( 'decimals.json' ) as $case ) {
			$cases[ $case['input'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider decimal_cases
	 */
	public function test_decimals_fixture( array $case ): void {
		$this->assertSame( $case['expect'], Types::parse_decimal( $case['input'] ) );
	}

	public function journey_cases(): array {
		$cases = array();
		foreach ( self::fixture( 'journey.json' ) as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider journey_cases
	 */
	public function test_journey_fixture( array $case ): void {
		$result = Journey::compute( $case['flow'], $case['values'], $case['ctx'] );
		$this->assertSame( $case['expect']['visible'], $result['visible'], $case['name'] . ': visible' );
		$this->assertSame( $case['expect']['visited'], $result['visited'], $case['name'] . ': visited' );
		$this->assertSame( $case['expect']['finished'], $result['finished'], $case['name'] . ': finished' );
	}
}
