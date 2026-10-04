<?php
/**
 * Tests for field types: per-type sanitisation and validation.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Fields\Registry;
use Krivo\ChatFlow\Core\Fields\Types;
use Krivo\ChatFlow\Core\Whatsapp\Phone;

/**
 * @group fields
 */
class FieldsTest extends Krivo_TestCase {

	/**
	 * @param string $type  Field type.
	 * @param mixed  $raw   Raw value.
	 * @param array  $extra Extra field properties.
	 * @return array
	 */
	private function run_field( string $type, $raw, array $extra = array(), array $ctx = array() ): array {
		return Registry::normalize( array_merge( array( 'type' => $type, 'key' => 'k' ), $extra ), $raw, $ctx );
	}

	public function test_sixteen_types_are_registered_and_html_collects_nothing(): void {
		$types = array_keys( Registry::types() );
		sort( $types );
		$expected = array( 'checkbox', 'consent', 'date', 'email', 'hidden', 'html', 'name', 'number', 'phone', 'radio', 'select', 'text', 'textarea', 'time', 'url', 'whatsapp' );
		$this->assertSame( $expected, $types );
		$this->assertFalse( Registry::collects( 'html' ) );
		$this->assertTrue( $this->run_field( 'html', 'x' )['skip'] );
	}

	public function test_required_and_optional_blank_values(): void {
		$this->assertSame( 'required', $this->run_field( 'text', '   ', array( 'required' => true ) )['error'] );
		$this->assertSame( 'required', $this->run_field( 'text', null, array( 'required' => true ) )['error'] );
		$optional = $this->run_field( 'text', '' );
		$this->assertNull( $optional['error'] );
		$this->assertTrue( $optional['empty'] );
		$this->assertSame( array(), $this->run_field( 'checkbox', array(), array( 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ) )['value'] );
	}

	public function test_text_strips_tags_and_enforces_length(): void {
		$r = $this->run_field( 'text', '  <b>Hello</b> <script>alert(1)</script> world ' );
		$this->assertStringNotContainsString( '<', $r['value'] );
		$this->assertStringNotContainsString( 'alert(1)', $r['value'], 'sanitize_text_field removes script content' );
		$this->assertSame( 'too_long', $this->run_field( 'text', str_repeat( 'a', 256 ) )['error'] );
		$this->assertNull( $this->run_field( 'text', str_repeat( 'a', 256 ), array( 'maxlength' => 400 ) )['error'] );
		$this->assertSame( 'too_long', $this->run_field( 'name', str_repeat( 'a', 121 ), array( 'maxlength' => 400 ) )['error'], 'Name is capped at 120' );
		$this->assertSame( 'invalid', $this->run_field( 'text', array( 'x' ) )['error'], 'Arrays are not text' );
	}

	public function test_textarea_keeps_line_breaks_and_enforces_limit(): void {
		$r = $this->run_field( 'textarea', "Line one\n<b>Line</b> two" );
		$this->assertSame( "Line one\nLine two", $r['value'] );
		$this->assertSame( 'too_long', $this->run_field( 'textarea', str_repeat( 'a', 2001 ) )['error'] );
		$this->assertNull( $this->run_field( 'textarea', str_repeat( 'a', 2001 ), array( 'maxlength' => 5000 ) )['error'] );
		$this->assertSame( 'too_long', $this->run_field( 'textarea', str_repeat( 'a', 5001 ), array( 'maxlength' => 99999 ) )['error'], 'Hard cap at 5000' );
	}

	/**
	 * @dataProvider email_cases
	 */
	public function test_email( $input, ?string $expected ): void {
		$r = $this->run_field( 'email', $input );
		if ( null === $expected ) {
			$this->assertSame( 'invalid_email', $r['error'], (string) wp_json_encode( $input ) );
		} else {
			$this->assertNull( $r['error'] );
			$this->assertSame( $expected, $r['value'] );
		}
	}

	public function email_cases(): array {
		return array(
			'plain'          => array( 'ana@example.com', 'ana@example.com' ),
			'uppercase'      => array( '  Ana@Example.COM ', 'ana@example.com' ),
			'plus tag'       => array( 'ana+x@example.com', 'ana+x@example.com' ),
			'no domain'      => array( 'ana@', null ),
			'no at'          => array( 'ana.example.com', null ),
			'header inject'  => array( "ana@example.com\r\nBcc: x@y.com", null ),
			'html'           => array( '<ana@example.com>', null ),
			'array'          => array( array( 'a@b.co' ), null ),
			'too long'       => array( str_repeat( 'a', 185 ) . '@example.com', null ),
		);
	}

	/**
	 * @dataProvider phone_cases
	 */
	public function test_phone( string $input, string $dial, ?string $expected ): void {
		$r = $this->run_field( 'phone', $input, array( 'default_dial_code' => $dial ) );
		if ( null === $expected ) {
			$this->assertSame( 'invalid_phone', $r['error'], $input );
		} else {
			$this->assertNull( $r['error'], $input );
			$this->assertSame( $expected, $r['value'], $input );
		}
	}

	public function phone_cases(): array {
		return array(
			'br mobile with mask'            => array( '(11) 99999-8888', '55', '+5511999998888' ),
			'br already has ddi'             => array( '5511999998888', '55', '+5511999998888' ),
			'br plus'                        => array( '+55 11 99999-8888', '55', '+5511999998888' ),
			'double zero prefix'             => array( '0055 11 99999-8888', '', '+5511999998888' ),
			'trunk zero is dropped'          => array( '011 99999-8888', '55', '+5511999998888' ),
			'us number with plus'            => array( '+1 (415) 555-2671', '55', '+14155552671' ),
			'no dial code keeps national'    => array( '11999998888', '', '11999998888' ),
			'too short'                      => array( '12345', '55', null ),
			'too long'                       => array( '+5511999998888123456', '55', null ),
			'letters only'                   => array( 'call me', '55', null ),
		);
	}

	public function test_phone_falls_back_to_the_site_default_dial_code(): void {
		\Krivo\ChatFlow\Core\Settings\Settings::update( array( 'general' => array( 'default_dial_code' => '351' ) ) );
		$r = $this->run_field( 'phone', '912 345 678' );
		$this->assertSame( '+351912345678', $r['value'] );
	}

	/**
	 * @dataProvider number_cases
	 */
	public function test_parse_decimal( string $input, ?string $expected ): void {
		$this->assertSame( $expected, Types::parse_decimal( $input ), $input );
	}

	public function number_cases(): array {
		return array(
			'integer'                 => array( '1500', '1500' ),
			'brazilian thousands'     => array( '10.000', '10000' ),
			'brazilian full'          => array( '10.000,50', '10000.5' ),
			'international full'      => array( '10,000.50', '10000.5' ),
			'comma decimal'           => array( '12,5', '12.5' ),
			'dot decimal'             => array( '12.5', '12.5' ),
			'currency prefix'         => array( 'R$ 10.000,00', '10000' ),
			'dollar'                  => array( '$1,250.75', '1250.75' ),
			'millions br'             => array( '1.234.567', '1234567' ),
			'negative'                => array( '-3,5', '-3.5' ),
			'leading zeros'           => array( '007', '7' ),
			'zero'                    => array( '0', '0' ),
			'decimal only'            => array( ',5', '0.5' ),
			'letters'                 => array( '12abc', null ),
			'two decimal separators'  => array( '1,2,3', null ),
			'empty'                   => array( '', null ),
			'scientific'              => array( '1e5', null ),
			'too long'                => array( str_repeat( '9', 30 ), null ),
		);
	}

	public function test_number_min_max(): void {
		$field = array( 'min' => 10, 'max' => 100 );
		$this->assertSame( 'min', $this->run_field( 'number', '9,99', $field )['error'] );
		$this->assertSame( 'max', $this->run_field( 'number', '100,01', $field )['error'] );
		$this->assertSame( '50', $this->run_field( 'number', '50', $field )['value'] );
		$this->assertSame( 'invalid_number', $this->run_field( 'number', 'abc' )['error'] );
	}

	public function test_choice_only_accepts_server_side_options(): void {
		$field = array(
			'options' => array(
				array( 'value' => 'dev', 'label' => 'Development' ),
				array( 'value' => 'ops', 'label' => 'Automation' ),
			),
		);
		$ok = $this->run_field( 'select', 'dev', $field );
		$this->assertSame( 'dev', $ok['value'] );
		$this->assertSame( 'Development', $ok['display'] );
		$this->assertSame( 'invalid_option', $this->run_field( 'select', 'hack', $field )['error'] );
		$this->assertSame( 'invalid_option', $this->run_field( 'radio', array( 'dev' ), $field )['error'], 'Arrays are not a single choice' );
	}

	public function test_multi_choice_validates_every_value_and_deduplicates(): void {
		$field = array(
			'options' => array(
				array( 'value' => 'a', 'label' => 'Alpha' ),
				array( 'value' => 'b', 'label' => 'Beta' ),
			),
		);
		$r = $this->run_field( 'checkbox', array( 'a', 'b', 'a' ), $field );
		$this->assertSame( array( 'a', 'b' ), $r['value'] );
		$this->assertSame( 'Alpha, Beta', $r['display'] );
		$this->assertSame( 'invalid_option', $this->run_field( 'checkbox', array( 'a', 'zzz' ), $field )['error'] );
		$this->assertSame( 'required', $this->run_field( 'checkbox', array(), $field + array( 'required' => true ) )['error'] );
	}

	/**
	 * @dataProvider date_cases
	 */
	public function test_date_and_time( string $type, $input, bool $valid ): void {
		$r = $this->run_field( $type, $input );
		$this->assertSame( $valid, null === $r['error'], wp_json_encode( $input ) );
	}

	public function date_cases(): array {
		return array(
			'valid date'         => array( 'date', '2026-10-04', true ),
			'leap day'           => array( 'date', '2028-02-29', true ),
			'not a leap year'    => array( 'date', '2027-02-29', false ),
			'month 13'           => array( 'date', '2026-13-01', false ),
			'wrong format'       => array( 'date', '04/10/2026', false ),
			'valid time'         => array( 'time', '18:30', true ),
			'midnight'           => array( 'time', '00:00', true ),
			'hour 24'            => array( 'time', '24:00', false ),
			'minute 60'          => array( 'time', '12:60', false ),
			'time with seconds'  => array( 'time', '12:30:15', false ),
		);
	}

	/**
	 * @dataProvider url_cases
	 */
	public function test_url( string $input, ?string $expected ): void {
		$r = $this->run_field( 'url', $input );
		if ( null === $expected ) {
			$this->assertSame( 'invalid_url', $r['error'], $input );
		} else {
			$this->assertSame( $expected, $r['value'], $input );
		}
	}

	public function url_cases(): array {
		return array(
			'https'             => array( 'https://krivo.works/path?x=1', 'https://krivo.works/path?x=1' ),
			'bare domain'       => array( 'krivo.works', 'https://krivo.works' ),
			'javascript scheme' => array( 'javascript:alert(1)', null ),
			'data scheme'       => array( 'data:text/html,<script>', null ),
			'ftp'               => array( 'ftp://example.com/file', null ),
			'spaces'            => array( 'not a url', null ),
			'no tld'            => array( 'http://localhost', null ),
		);
	}

	public function test_consent_is_a_boolean_and_can_be_required(): void {
		$field = array( 'required' => true );
		$this->assertSame( 'consent_required', $this->run_field( 'consent', null, $field )['error'] );
		$this->assertSame( 'consent_required', $this->run_field( 'consent', 'no', $field )['error'] );
		$this->assertTrue( $this->run_field( 'consent', true, $field )['value'] );
		$this->assertTrue( $this->run_field( 'consent', 'on', $field )['value'] );
		$this->assertNull( $this->run_field( 'consent', null )['error'], 'Optional consent may stay unchecked' );
	}

	public function test_hidden_fields_never_trust_the_browser_except_query_param(): void {
		$ctx = array( 'page_url' => 'https://site.test/p', 'utm_source' => 'google', 'referrer' => 'https://ref.test/' );

		$static = $this->run_field( 'hidden', 'ATTACKER', array( 'source' => 'static', 'value' => 'campaign-x' ), $ctx );
		$this->assertSame( 'campaign-x', $static['value'] );

		$utm = $this->run_field( 'hidden', 'ATTACKER', array( 'source' => 'utm_source' ), $ctx );
		$this->assertSame( 'google', $utm['value'] );

		$page = $this->run_field( 'hidden', 'ATTACKER', array( 'source' => 'page_url' ), $ctx );
		$this->assertSame( 'https://site.test/p', $page['value'] );

		$param = $this->run_field( 'hidden', '<b>promo</b>', array( 'source' => 'query_param', 'param' => 'promo' ), $ctx );
		$this->assertSame( 'promo', $param['value'], 'query_param is browser provided but sanitised' );
	}

	public function test_unknown_type_is_skipped_not_trusted(): void {
		$this->assertTrue( Registry::normalize( array( 'type' => 'evil', 'key' => 'x' ), 'v' )['skip'] );
	}

	public function test_phone_normalizer_unit(): void {
		$this->assertSame( '5511999998888', Phone::destination_digits( '+55', '(11) 99999-8888' ) );
		$this->assertSame( '', Phone::destination_digits( '55', '123' ) );
	}

	public function test_messages_are_translatable_and_interpolated(): void {
		$this->assertStringContainsString( '255', Registry::message( 'too_long', array( 'max' => 255 ) ) );
		$this->assertSame( Registry::message( 'invalid' ), Registry::message( 'does_not_exist' ) );
	}
}
