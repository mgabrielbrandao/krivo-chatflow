<?php
/**
 * Tests for variables, WhatsApp URLs and business hours.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Flow\Hours;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Core\Whatsapp\UrlBuilder;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

/**
 * @group messaging
 */
class MessagingTest extends Krivo_TestCase {

	public function variable_cases(): array {
		$cases = array();
		foreach ( json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/variables.json' ), true ) as $case ) {
			$cases[ $case['name'] ] = array( $case );
		}
		return $cases;
	}

	/**
	 * @dataProvider variable_cases
	 */
	public function test_variables_fixture( array $case ): void {
		$this->assertSame( $case['expect'], Renderer::render( $case['template'], $case['vars'], 'text' ) );
	}

	public function test_html_mode_escapes_values_and_keeps_line_breaks(): void {
		$out = Renderer::render( '<p>{mensagem}</p>', array( 'mensagem' => "<script>alert(1)</script>\nsecond" ), 'html' );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
		$this->assertStringContainsString( '<br>', $out );
	}

	public function test_subject_mode_blocks_header_injection(): void {
		$out = Renderer::render( 'New lead - {name}', array( 'name' => "Ana\r\nBcc: evil@x.com" ), 'subject' );
		$this->assertStringNotContainsString( "\n", $out );
		$this->assertStringNotContainsString( "\r", $out );
	}

	public function test_all_fields_summary_in_each_mode(): void {
		$vars = array(
			'all_fields' => array(
				array( 'label' => 'Name', 'value' => 'Ana' ),
				array( 'label' => 'Message', 'value' => 'Hi <b>there</b>' ),
				array( 'label' => 'Empty', 'value' => '' ),
			),
		);
		$text = Renderer::render( '{all_fields}', $vars, 'text' );
		$this->assertSame( "Name: Ana\nMessage: Hi <b>there</b>", $text, 'Empty answers are omitted' );
		$this->assertSame( $text, Renderer::render( '{respostas}', $vars, 'text' ), 'Portuguese alias' );

		$html = Renderer::render( '{all_fields}', $vars, 'html' );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringNotContainsString( '<b>there</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;there&lt;/b&gt;', $html );
	}

	public function test_builder_creates_role_field_and_system_variables(): void {
		$flow    = array(
			'name'  => 'Contact',
			'steps' => array(
				array(
					'id'     => 's1',
					'fields' => array(
						array( 'id' => 'f1', 'type' => 'name', 'key' => 'nome', 'label' => 'Nome' ),
						array( 'id' => 'f2', 'type' => 'whatsapp', 'key' => 'zap', 'label' => 'Zap' ),
						array( 'id' => 'f3', 'type' => 'select', 'key' => 'servico', 'label' => 'Serviço' ),
						array( 'id' => 'f4', 'type' => 'hidden', 'key' => 'src', 'label' => 'src' ),
					),
				),
			),
		);
		$answers = array(
			'nome'    => array( 'label' => 'Nome', 'type' => 'name', 'value' => 'Gabriel', 'display' => 'Gabriel' ),
			'zap'     => array( 'label' => 'Zap', 'type' => 'whatsapp', 'value' => '+5511999998888', 'display' => '+5511999998888' ),
			'servico' => array( 'label' => 'Serviço', 'type' => 'select', 'value' => 'dev', 'display' => 'Desenvolvimento' ),
			'src'     => array( 'label' => 'src', 'type' => 'hidden', 'value' => 'x', 'display' => 'x' ),
		);
		$vars    = Builder::build( $flow, $answers, array( 'tracking' => array( 'page_url' => 'https://s.test/p', 'utm_source' => 'google' ), 'lead_id' => 42, 'destination' => 'Sales' ) );

		$this->assertSame( 'Gabriel', $vars['name'] );
		$this->assertSame( '+5511999998888', $vars['phone'], 'phone role falls back to the whatsapp field' );
		$this->assertSame( 'Desenvolvimento', $vars['servico'], 'choices use the option label' );
		$this->assertSame( 'google', $vars['utm_source'] );
		$this->assertSame( '42', $vars['lead_id'] );
		$this->assertSame( 'Sales', $vars['destination'] );
		$this->assertCount( 3, $vars['all_fields'], 'Hidden fields are not listed in the summary' );
		$this->assertSame( 'Perfeito, Gabriel. (Desenvolvimento)', Renderer::render( 'Perfeito, {nome}. ({servico})', $vars ) );
	}

	public function test_a_field_key_cannot_shadow_a_system_variable(): void {
		$flow    = array( 'steps' => array( array( 'id' => 's', 'fields' => array( array( 'id' => 'f', 'type' => 'text', 'key' => 'page_url', 'label' => 'x' ) ) ) ) );
		$answers = array( 'page_url' => array( 'label' => 'x', 'type' => 'text', 'value' => 'https://evil.test', 'display' => 'https://evil.test' ) );
		$vars    = Builder::build( $flow, $answers, array( 'tracking' => array( 'page_url' => 'https://real.test/' ) ) );
		$this->assertSame( 'https://real.test/', $vars['page_url'] );
	}

	public function test_whatsapp_url_encoding_and_digits(): void {
		$url = UrlBuilder::build( '+55 (11) 99999-8888', "Olá!\nMeu nome é José & cia" );
		$this->assertStringStartsWith( 'https://wa.me/5511999998888?text=', $url );
		$this->assertStringContainsString( '%0A', $url, 'newline encoded' );
		$this->assertStringContainsString( '%26', $url, 'ampersand encoded' );
		$this->assertStringContainsString( '%C3%A1', $url, 'accents encoded as UTF-8' );
		$this->assertSame( 'https://wa.me/5511999998888', UrlBuilder::build( '5511999998888', '   ' ) );
		$this->assertSame( '', UrlBuilder::build( '123', 'x' ), 'Unusable number gives no URL' );
		$this->assertSame( '', UrlBuilder::build( '', 'x' ) );
	}

	public function test_whatsapp_url_is_shortened_to_fit_and_still_valid(): void {
		$long = str_repeat( 'Olá, preciso de um orçamento. ', 400 );
		$url  = UrlBuilder::build( '5511999998888', $long );
		$this->assertLessThanOrEqual( UrlBuilder::MAX_LENGTH, strlen( $url ) );
		$this->assertStringContainsString( '%E2%80%A6', $url, 'Truncation marker (…) present' );
		$this->assertSame( 1, preg_match( '#^https://wa\.me/\d+\?text=[A-Za-z0-9%._~-]+$#', $url ), 'Only URL-safe characters' );
	}

	public function test_whatsapp_text_cannot_inject_extra_query_parameters(): void {
		$url = UrlBuilder::build( '5511999998888', 'x&phone=999&text=evil#frag' );
		$this->assertSame( 1, substr_count( $url, '?' ) );
		$this->assertStringNotContainsString( '&', $url );
		$this->assertStringNotContainsString( '#', $url );
	}

	public function test_business_hours(): void {
		update_option( 'timezone_string', 'America/Sao_Paulo' );
		$hours = array(
			'enabled' => true,
			'days'    => array(
				'mon' => array( array( '09:00', '18:00' ) ),
				'tue' => array( array( '09:00', '12:00' ), array( '14:00', '18:00' ) ),
			),
		);
		// 2026-10-05 is a Monday. São Paulo is UTC-3 (no DST in 2026).
		$monday_10h = gmmktime( 13, 0, 0, 10, 5, 2026 );
		$monday_18h = gmmktime( 21, 0, 0, 10, 5, 2026 );
		$monday_08h = gmmktime( 11, 0, 0, 10, 5, 2026 );
		$tuesday_13 = gmmktime( 16, 0, 0, 10, 6, 2026 );
		$tuesday_15 = gmmktime( 18, 0, 0, 10, 6, 2026 );
		$sunday     = gmmktime( 15, 0, 0, 10, 4, 2026 );

		$this->assertTrue( Hours::is_open( $hours, $monday_10h ) );
		$this->assertFalse( Hours::is_open( $hours, $monday_18h ), 'End of range is exclusive' );
		$this->assertFalse( Hours::is_open( $hours, $monday_08h ) );
		$this->assertFalse( Hours::is_open( $hours, $tuesday_13 ), 'Lunch break' );
		$this->assertTrue( Hours::is_open( $hours, $tuesday_15 ) );
		$this->assertFalse( Hours::is_open( $hours, $sunday ), 'No ranges on sunday' );
		$this->assertNull( Hours::is_open( array( 'enabled' => false ), $monday_10h ), 'Disabled means always available' );

		Clock::freeze( $monday_10h );
		$this->assertTrue( Hours::is_open( $hours ) );
		Clock::freeze( null );
	}

	public function test_business_hours_follow_the_site_timezone(): void {
		$hours = array( 'enabled' => true, 'days' => array( 'mon' => array( array( '09:00', '18:00' ) ) ) );
		$ts    = gmmktime( 13, 0, 0, 10, 5, 2026 ); // 13:00 UTC.

		update_option( 'timezone_string', 'America/Sao_Paulo' ); // 10:00 local.
		$this->assertTrue( Hours::is_open( $hours, $ts ) );

		update_option( 'timezone_string', 'Asia/Tokyo' ); // 22:00 local.
		$this->assertFalse( Hours::is_open( $hours, $ts ) );
	}

	public function test_client_timezone_descriptor(): void {
		update_option( 'timezone_string', 'Europe/Lisbon' );
		$this->assertSame( array( 'type' => 'iana', 'name' => 'Europe/Lisbon' ), Hours::client_timezone() );

		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -3 );
		$this->assertSame( array( 'type' => 'offset', 'minutes' => -180 ), Hours::client_timezone() );
	}
}
