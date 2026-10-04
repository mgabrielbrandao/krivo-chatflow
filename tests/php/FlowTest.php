<?php
/**
 * Tests for flows: sanitiser, templates, repository, manifest, validation report.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Flow\Defaults;
use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Flow\SvgSanitizer;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Core\Flow\Validator;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;

/**
 * @group flow
 */
class FlowTest extends Krivo_TestCase {

	/**
	 * A minimal valid raw flow.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function raw_flow( array $over = array() ): array {
		return array_replace_recursive(
			array(
				'name'  => 'Test flow',
				'mode'  => 'form',
				'steps' => array(
					array(
						'id'     => 's_one',
						'fields' => array(
							array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Name', 'required' => true ),
							array( 'id' => 'f_mail', 'type' => 'email', 'label' => 'E-mail' ),
						),
					),
				),
			),
			$over
		);
	}

	private function add_destination( string $whatsapp = '5511999998888', array $emails = array( 'sales@example.test' ) ): string {
		$saved = RoutingRepository::save(
			array(
				'default_destination' => '',
				'destinations'        => array( array( 'id' => 'dst_sales1', 'name' => 'Sales', 'dial_code' => '55', 'number' => substr( $whatsapp, 2 ), 'emails' => $emails ) ),
				'rules'               => array(),
			)
		);
		$this->assertIsArray( $saved );
		return 'dst_sales1';
	}

	// ---------------------------------------------------------------- sanitiser

	public function test_blank_flow_is_a_valid_shape(): void {
		$clean = Sanitizer::sanitize( array() );
		$this->assertSame( 1, $clean['schema'] );
		$this->assertSame( 'form', $clean['mode'] );
		$this->assertCount( 1, $clean['steps'] );
		$this->assertSame( array(), $clean['steps'][0]['fields'] );
		$this->assertSame( array( 'rules' => array() ), $clean['logic'] );
	}

	public function test_sanitiser_drops_unknown_keys_and_types_and_is_idempotent(): void {
		$raw = $this->raw_flow(
			array(
				'evil'  => '<script>alert(1)</script>',
				'steps' => array( array( 'fields' => array( array( 'type' => 'exec', 'label' => 'x' ) ) ) ),
			)
		);
		$raw['steps'][0]['fields'][] = array( 'type' => 'text', 'label' => 'Ok' );
		$clean = Sanitizer::sanitize( $raw );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$types = array_column( $clean['steps'][0]['fields'], 'type' );
		$this->assertNotContains( 'exec', $types );
		$this->assertSame( $clean, Sanitizer::sanitize( $clean ), 'Sanitising twice changes nothing' );
	}

	public function test_text_properties_are_sanitised_and_bounded(): void {
		$clean = Sanitizer::sanitize(
			$this->raw_flow(
				array(
					'name'   => '<b>Hello</b><script>x</script>' . str_repeat( 'a', 300 ),
					'finish' => array( 'success_message' => "Thanks <img src=x onerror=alert(1)>\nbye" ),
				)
			)
		);
		$this->assertStringNotContainsString( '<', $clean['name'] );
		$this->assertLessThanOrEqual( 120, mb_strlen( $clean['name'] ) );
		$this->assertStringNotContainsString( 'onerror', $clean['finish']['success_message'] );
		$this->assertStringNotContainsString( '<img', $clean['finish']['success_message'] );
	}

	public function test_ids_and_keys_are_made_unique_and_reserved_keys_are_renamed(): void {
		$clean  = Sanitizer::sanitize(
			array(
				'steps' => array(
					array(
						'id'     => 's_a',
						'fields' => array(
							array( 'id' => 'f_same', 'type' => 'text', 'label' => 'Name', 'key' => 'nome' ),
							array( 'id' => 'f_same', 'type' => 'text', 'label' => 'Name again', 'key' => 'nome' ),
							array( 'type' => 'text', 'label' => 'Page', 'key' => 'page_url' ),
							array( 'type' => 'text', 'label' => 'Qual serviço você procura?' ),
							array( 'type' => 'text', 'label' => '', 'key' => 'Bad Key!!' ),
						),
					),
				),
			)
		);
		$fields = $clean['steps'][0]['fields'];
		$ids    = array_column( $fields, 'id' );
		$keys   = array_column( $fields, 'key' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'ids unique' );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'keys unique' );
		$this->assertContains( 'nome', $keys );
		$this->assertContains( 'nome_2', $keys );
		$this->assertContains( 'page_url_field', $keys, 'Reserved variable names cannot be used as keys' );
		$this->assertContains( 'qual_servico_voce_procura', $keys, 'Key derived from the label, accents removed' );
		$this->assertContains( 'bad_key', $keys );
	}

	public function test_choice_options_are_cleaned_and_defaults_must_be_valid(): void {
		$clean = Sanitizer::sanitize(
			array(
				'steps' => array(
					array(
						'fields' => array(
							array(
								'type'    => 'select',
								'label'   => 'Service',
								'default' => 'nope',
								'options' => array(
									array( 'label' => 'Website' ),
									array( 'label' => 'Website' ),
									array( 'label' => '', 'value' => 'empty' ),
									array( 'label' => '<b>Bold</b>', 'value' => 'b' ),
									'not-an-array',
								),
							),
							array(
								'type'    => 'checkbox',
								'label'   => 'Interests',
								'default' => array( 'a', 'zzz' ),
								'options' => array( array( 'label' => 'A', 'value' => 'a' ), array( 'label' => 'B', 'value' => 'b' ) ),
							),
						),
					),
				),
			)
		);
		$select = $clean['steps'][0]['fields'][0];
		$values = array_column( $select['options'], 'value' );
		$this->assertCount( 3, $select['options'], 'Empty labels and junk are dropped' );
		$this->assertSame( count( $values ), count( array_unique( $values ) ), 'Option values unique' );
		$this->assertSame( 'Bold', $select['options'][2]['label'] );
		$this->assertSame( '', $select['default'], 'An invalid default is cleared' );
		$this->assertSame( array( 'a' ), $clean['steps'][0]['fields'][1]['default'] );
	}

	public function test_limits_are_enforced(): void {
		$fields = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$fields[] = array( 'type' => 'text', 'label' => 'F' . $i );
		}
		$steps = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$steps[] = array( 'fields' => array() );
		}
		$many_fields = Sanitizer::sanitize( array( 'steps' => array( array( 'fields' => $fields ) ) ) );
		$this->assertCount( Sanitizer::MAX_FIELDS, $many_fields['steps'][0]['fields'] );
		$many_steps = Sanitizer::sanitize( array( 'steps' => $steps ) );
		$this->assertCount( Sanitizer::MAX_STEPS, $many_steps['steps'] );
	}

	public function test_logic_rules_must_point_at_real_fields_and_valid_jumps(): void {
		$raw = array(
			'mode'  => 'conversational',
			'steps' => array(
				array(
					'id'     => 's_x',
					'fields' => array(
						array( 'id' => 'f_a', 'type' => 'select', 'key' => 'a', 'label' => 'A', 'options' => array( array( 'label' => 'x' ) ) ),
						array( 'id' => 'f_b', 'type' => 'text', 'key' => 'b', 'label' => 'B' ),
						array( 'id' => 'f_c', 'type' => 'text', 'key' => 'c', 'label' => 'C' ),
					),
				),
			),
			'logic' => array(
				'rules' => array(
					array( 'id' => 'ok_show', 'when' => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:a', 'op' => 'eq', 'right' => 'x' ) ) ), 'then' => array( array( 'do' => 'show_field', 'target' => 'f_b' ) ) ),
					array( 'id' => 'bad_target', 'when' => array( 'conditions' => array() ), 'then' => array( array( 'do' => 'show_field', 'target' => 'f_ghost' ) ) ),
					array( 'id' => 'ok_goto', 'after' => 'f_a', 'when' => array( 'conditions' => array() ), 'then' => array( array( 'do' => 'goto', 'target' => 'f_c' ) ) ),
					array( 'id' => 'goto_without_after', 'when' => array( 'conditions' => array() ), 'then' => array( array( 'do' => 'goto', 'target' => 'f_c' ) ) ),
					array( 'id' => 'finish_ok', 'after' => 'f_b', 'when' => array( 'conditions' => array() ), 'then' => array( array( 'do' => 'finish' ) ) ),
					array( 'id' => 'bad_op', 'when' => array( 'conditions' => array( array( 'left' => 'field:a', 'op' => 'system', 'right' => 'x' ), array( 'left' => 'server:secret', 'op' => 'eq', 'right' => 'x' ) ) ), 'then' => array( array( 'do' => 'hide_field', 'target' => 'f_c' ) ) ),
				),
			),
		);
		$clean = Sanitizer::sanitize( $raw );
		$ids   = array_column( $clean['logic']['rules'], 'id' );
		$this->assertContains( 'ok_show', $ids );
		$this->assertContains( 'ok_goto', $ids );
		$this->assertContains( 'finish_ok', $ids );
		$this->assertNotContains( 'bad_target', $ids );
		$this->assertNotContains( 'goto_without_after', $ids );

		$bad_op = null;
		foreach ( $clean['logic']['rules'] as $rule ) {
			if ( 'bad_op' === $rule['id'] ) {
				$bad_op = $rule;
			}
		}
		$this->assertNotNull( $bad_op );
		$this->assertSame( array(), $bad_op['when']['conditions'], 'Invalid operators and operands are dropped' );

		// In form mode there are no jumps at all.
		$raw['mode'] = 'form';
		$form        = Sanitizer::sanitize( $raw );
		$this->assertNotContains( 'ok_goto', array_column( $form['logic']['rules'], 'id' ) );
		$this->assertNotContains( 'finish_ok', array_column( $form['logic']['rules'], 'id' ) );
	}

	public function test_automations_are_validated_against_the_registry(): void {
		$clean = Sanitizer::sanitize(
			$this->raw_flow(
				array(
					'automations' => array(
						array( 'id' => 'a1', 'trigger' => 'form.submitted', 'type' => 'send_email', 'enabled' => true, 'config' => array( 'to' => array( 'custom:a@b.co', 'custom:<x>', 'lead', 'site_admin' ), 'subject' => "Hi\r\nBcc: x@y.com" ) ),
						array( 'id' => 'a2', 'trigger' => 'form.submitted', 'type' => 'rm_rf', 'enabled' => true, 'config' => array() ),
						array( 'id' => 'a3', 'trigger' => 'lead.updated', 'type' => 'open_whatsapp', 'enabled' => true, 'config' => array() ),
						array( 'id' => 'a4', 'trigger' => 'form.submitted', 'type' => 'open_whatsapp', 'enabled' => true, 'managed' => true, 'config' => array() ),
						array( 'id' => 'a5', 'trigger' => 'form.submitted', 'type' => 'open_whatsapp', 'enabled' => true, 'managed' => true, 'config' => array() ),
					),
				)
			)
		);
		$byid = array();
		foreach ( $clean['automations'] as $automation ) {
			$byid[ $automation['id'] ] = $automation;
		}
		$this->assertArrayHasKey( 'a1', $byid );
		$this->assertArrayNotHasKey( 'a2', $byid, 'Unknown action types are dropped' );
		$this->assertArrayNotHasKey( 'a3', $byid, 'open_whatsapp cannot run on lead.updated' );
		$this->assertSame( array( 'lead' ), $byid['a1']['config']['to'], '"lead" is exclusive: team addresses are never mixed with the visitor' );
		$this->assertStringNotContainsString( "\n", $byid['a1']['config']['subject'] );
		$this->assertArrayHasKey( 'a4', $byid );
		$this->assertArrayNotHasKey( 'a5', $byid, 'Only one managed WhatsApp action' );
	}

	public function test_email_recipients_are_validated(): void {
		$action = ( new \Krivo\ChatFlow\Automation\Actions\SendEmail() )->sanitize(
			array( 'to' => array( 'custom:Ana@Example.COM', 'custom:not-an-email', 'custom:' . "x@y.co\r\nBcc:z@z.co", 'site_admin', 'bogus' ) )
		);
		$this->assertSame( array( 'custom:ana@example.com', 'site_admin' ), $action['to'] );
		$this->assertSame( array( 'site_admin' ), ( new \Krivo\ChatFlow\Automation\Actions\SendEmail() )->sanitize( array( 'to' => array() ) )['to'], 'Falls back to the site admin' );
	}

	public function test_bubble_design_and_display_settings_are_sanitised(): void {
		$clean = Sanitizer::sanitize(
			$this->raw_flow(
				array(
					'bubble'   => array(
						'size'      => 9999,
						'offset_x'  => -50,
						'shape'     => 'triangle',
						'position'  => 'top-left',
						'animation' => 'spin',
						'colors'    => array( 'bg' => 'red; background:url(x)', 'icon' => '#abc', 'border' => 'javascript:1' ),
						'tooltip'   => array( 'text' => '<b>Hi</b>', 'delay' => 5000 ),
						'badge'     => array( 'text' => '12345' ),
					),
					'design'   => array( 'theme' => 'neon', 'accent' => '#12' ),
					'behavior' => array( 'delay' => 500, 'scroll_percent' => 0, 'trigger' => 'exit' ),
					'display'  => array(
						'priority' => 99999,
						'mode'     => 'maybe',
						'devices'  => array( 'mobile', 'tv' ),
						'logged'   => 'robot',
						'include'  => array(
							array( 'type' => 'url', 'op' => 'regex', 'value' => '/shop/' ),
							array( 'type' => 'url', 'op' => 'contains', 'value' => '' ),
							array( 'type' => 'page_type', 'value' => 'woo_checkout' ),
							array( 'type' => 'page_type', 'value' => 'moon' ),
							array( 'type' => 'post', 'value' => '12' ),
							array( 'type' => 'post', 'value' => 'abc' ),
						),
					),
				)
			)
		);
		$b = $clean['bubble'];
		$this->assertSame( 96, $b['size'] );
		$this->assertSame( 0, $b['offset_x'] );
		$this->assertSame( 'circle', $b['shape'] );
		$this->assertSame( 'bottom-right', $b['position'] );
		$this->assertSame( 'none', $b['animation'] );
		$this->assertSame( '#7B2FF7', $b['colors']['bg'], 'Invalid colour falls back to the default' );
		$this->assertSame( '#ABC', $b['colors']['icon'] );
		$this->assertSame( '', $b['colors']['border'] );
		$this->assertSame( 'Hi', $b['tooltip']['text'] );
		$this->assertSame( 120, $b['tooltip']['delay'] );
		$this->assertSame( '123', $b['badge']['text'], 'Badge text is limited to three characters' );
		$this->assertSame( 'light', $clean['design']['theme'] );
		$this->assertSame( '#7B2FF7', $clean['design']['accent'] );
		$this->assertSame( 'delay', $clean['behavior']['trigger'] );
		$this->assertSame( 60, $clean['behavior']['delay'] );
		$this->assertSame( 1, $clean['behavior']['scroll_percent'] );
		$d = $clean['display'];
		$this->assertSame( 999, $d['priority'] );
		$this->assertSame( 'all', $d['mode'] );
		$this->assertSame( array( 'mobile' ), $d['devices'] );
		$this->assertSame( 'any', $d['logged'] );
		$types = array_map( static fn( $r ) => $r['type'] . ':' . $r['value'], $d['include'] );
		$this->assertContains( 'page_type:woo_checkout', $types );
		$this->assertContains( 'post:12', $types );
		$this->assertCount( 3, $d['include'], 'Invalid operators, empty values, unknown page types and non-numeric posts are dropped' );
		$this->assertSame( 'contains', $d['include'][0]['op'], 'A regex operator is not accepted: it falls back to a safe one' );
	}

	public function test_business_hours_are_normalised(): void {
		$clean = Sanitizer::sanitize(
			$this->raw_flow(
				array(
					'hours' => array(
						'enabled' => true,
						'days'    => array(
							'mon' => array( array( '14:00', '18:00' ), array( '09:00', '12:00' ), array( '18:00', '09:00' ), array( '25:00', '26:00' ), array( 'a', 'b' ) ),
							'xyz' => array( array( '09:00', '10:00' ) ),
						),
					),
				)
			)
		);
		$this->assertSame( array( array( '09:00', '12:00' ), array( '14:00', '18:00' ) ), $clean['hours']['days']['mon'], 'Sorted, invalid and inverted ranges dropped' );
		$this->assertArrayNotHasKey( 'xyz', $clean['hours']['days'] );
		$this->assertSame( array(), $clean['hours']['days']['sun'] );
	}

	public function test_consent_and_html_fields_use_a_tag_allowlist(): void {
		$clean = Sanitizer::sanitize(
			array(
				'steps' => array(
					array(
						'fields' => array(
							array( 'type' => 'consent', 'label' => 'LGPD', 'text' => 'I accept the <a href="javascript:alert(1)">terms</a> and <a href="https://example.test/p" target="_blank" onclick="x()">privacy</a><script>x</script>' ),
							array( 'type' => 'html', 'html' => '<p>Hi <strong>there</strong></p><iframe src="https://evil.test"></iframe><img src=x onerror=alert(1)>' ),
						),
					),
				),
			)
		);
		$consent = $clean['steps'][0]['fields'][0]['text'];
		$this->assertStringNotContainsString( 'javascript:', $consent );
		$this->assertStringNotContainsString( 'onclick', $consent );
		$this->assertStringNotContainsString( '<script', $consent );
		$this->assertStringContainsString( 'https://example.test/p', $consent );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $consent, 'Links never hand over window.opener' );
		$html = $clean['steps'][0]['fields'][1]['html'];
		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '<strong>there</strong>', $html );
	}

	// ---------------------------------------------------------------- SVG

	public function svg_attack_cases(): array {
		return array(
			'script element'      => array( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0h10v10z"/></svg>', true ),
			'onload'              => array( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0h10v10z"/></svg>', true ),
			'event on child'      => array( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" onclick="alert(1)"/></svg>', true ),
			'foreignObject'       => array( '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>hi</div></foreignObject><path d="M0 0h1v1z"/></svg>', true ),
			'external href'       => array( '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.test/x.svg#a"/><path d="M0 0h1v1z"/></svg>', true ),
			'javascript url'      => array( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" fill="url(javascript:alert(1))"/></svg>', false ),
			'external fill url'   => array( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" fill="url(https://evil.test/a)"/></svg>', false ),
			'style with import'   => array( '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.test/a.css);</style><path d="M0 0h1v1z"/></svg>', true ),
			'doctype entity'      => array( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" id="&x;"/></svg>', false ),
			'not svg'             => array( '<div>hello</div>', false ),
			'not xml'             => array( '<svg><path d="M0 0"></svg', false ),
			'wrong namespace'     => array( '<svg xmlns="http://evil.test/ns"><path d="M0 0h1v1z"/></svg>', false ),
			'cdata'               => array( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/><![CDATA[<script>x</script>]]></svg>', false ),
		);
	}

	/**
	 * @dataProvider svg_attack_cases
	 */
	public function test_svg_sanitiser_blocks_attacks( string $svg, bool $keeps_clean_part ): void {
		$out = SvgSanitizer::sanitize( $svg );
		foreach ( array( '<script', 'onload', 'onclick', 'javascript:', 'foreignobject', 'evil.test', '<style', '@import', 'file:///' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $out, "Output must not contain {$needle}" );
		}
		if ( ! $keeps_clean_part ) {
			$this->assertTrue( '' === $out || false === stripos( $out, 'url(' ) || 1 === preg_match( '/url\(#/', $out ), 'Unsafe SVG is rejected or neutralised' );
		}
	}

	public function test_svg_sanitiser_keeps_a_legitimate_icon(): void {
		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs><path fill="url(#g)" fill-rule="evenodd" d="M4 4h16v12H8l-4 4z"/><title>Chat</title></svg>';
		$out = SvgSanitizer::sanitize( $svg );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $out, 'Attribute case is preserved' );
		$this->assertStringContainsString( 'd="M4 4h16v12H8l-4 4z"', $out );
		$this->assertStringContainsString( 'fill="url(#g)"', $out );
		$this->assertStringContainsString( 'linearGradient', $out );
		$this->assertSame( '', SvgSanitizer::sanitize( str_repeat( 'x', 9000 ) ), 'Oversized input is refused' );
	}

	public function test_invalid_custom_svg_falls_back_to_the_chat_icon(): void {
		$clean = Sanitizer::sanitize( $this->raw_flow( array( 'bubble' => array( 'icon' => array( 'type' => 'custom_svg', 'svg' => '<svg><script>1</script' ) ) ) ) );
		$this->assertSame( 'chat', $clean['bubble']['icon']['type'] );
		$clean = Sanitizer::sanitize( $this->raw_flow( array( 'bubble' => array( 'icon' => array( 'type' => 'custom_image', 'attachment_id' => 999999 ) ) ) ) );
		$this->assertSame( 'chat', $clean['bubble']['icon']['type'], 'A missing attachment falls back as well' );
	}

	// ---------------------------------------------------------------- templates

	public function test_every_template_builds_a_publishable_flow(): void {
		$this->add_destination();
		foreach ( Templates::all() as $template ) {
			$config = Sanitizer::sanitize( Templates::build( $template['id'] ) );
			$report = Validator::report( $config );
			$this->assertSame( array(), $report['errors'], $template['id'] . ' has no blocking errors' );
			$this->assertNotEmpty( $config['steps'][0]['fields'], $template['id'] );
			$this->assertNotEmpty( $config['automations'], $template['id'] );
		}
		$this->assertCount( 5, Templates::all() );
		$this->assertNull( Templates::build( 'does-not-exist' ) );
	}

	public function test_template_keys_become_variables_and_follow_the_site_language(): void {
		$config = Sanitizer::sanitize( Templates::build( 'quote' ) );
		$keys   = array_column( $config['steps'][0]['fields'], 'key' );
		$this->assertContains( 'name', $keys );
		$this->assertContains( 'service', $keys );
		$this->assertContains( 'budget', $keys );
		$this->assertStringContainsString( '{name}', $config['whatsapp']['message'] );
	}

	public function test_template_without_whatsapp_has_no_whatsapp_action(): void {
		$with    = Sanitizer::sanitize( Templates::build( 'contact', array( 'whatsapp' => true ) ) );
		$without = Sanitizer::sanitize( Templates::build( 'contact', array( 'whatsapp' => false ) ) );
		$this->assertContains( 'open_whatsapp', array_column( $with['automations'], 'type' ) );
		$this->assertNotContains( 'open_whatsapp', array_column( $without['automations'], 'type' ) );
	}

	// ---------------------------------------------------------------- repository

	public function test_draft_and_published_versions_are_independent(): void {
		$id = FlowRepository::create( Templates::build( 'support' ), 1 );
		$this->assertGreaterThan( 0, $id );

		$flow = FlowRepository::get( $id );
		$this->assertSame( 'draft', $flow['status'] );
		$this->assertNull( FlowRepository::published( $id ), 'A draft is not public' );

		$published = FlowRepository::publish( $id );
		$this->assertIsArray( $published );
		$this->assertSame( 1, $published['flow']['revision'] );
		$this->assertSame( 'published', FlowRepository::get( $id )['status'] );

		$draft         = FlowRepository::get( $id )['draft'];
		$draft['name'] = 'Renamed in draft only';
		FlowRepository::save_draft( $id, $draft );

		$after = FlowRepository::get( $id );
		$this->assertSame( 'Renamed in draft only', $after['draft']['name'] );
		$this->assertNotSame( 'Renamed in draft only', $after['published']['name'], 'Editing never changes the live version' );
		$this->assertNotSame( 'Renamed in draft only', FlowRepository::published( $id )['config']['name'] );

		$again = FlowRepository::publish( $id );
		$this->assertSame( 2, $again['flow']['revision'] );
		$this->assertSame( 'Renamed in draft only', FlowRepository::published( $id )['config']['name'] );
	}

	public function test_concurrent_edits_are_detected(): void {
		$id    = FlowRepository::create( Templates::build( 'support' ), 1 );
		$flow  = FlowRepository::get( $id );
		$draft = $flow['draft'];

		$draft['name'] = 'Editor A';
		$saved         = FlowRepository::save_draft( $id, $draft, $flow['draft_hash'] );
		$this->assertIsArray( $saved );

		$draft['name'] = 'Editor B (started from the old version)';
		$conflict      = FlowRepository::save_draft( $id, $draft, $flow['draft_hash'] );
		$this->assertWPErrorCode( 'krivo_chatflow_conflict', $conflict );
		$this->assertSame( 409, $conflict->get_error_data()['status'] );
		$this->assertSame( 'Editor A', FlowRepository::get( $id )['draft']['name'], 'Nothing was overwritten' );

		$this->assertWPErrorCode( 'krivo_chatflow_not_found', FlowRepository::save_draft( 99999, $draft ) );
	}

	public function test_publishing_is_blocked_while_the_flow_has_blocking_problems(): void {
		$id     = FlowRepository::create( array( 'name' => 'Empty flow' ), 1 );
		$result = FlowRepository::publish( $id );
		$this->assertWPErrorCode( 'krivo_chatflow_validation', $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$codes = array_column( $result->get_error_data()['report']['errors'], 'code' );
		$this->assertContains( 'no_fields', $codes );
		$this->assertNull( FlowRepository::published( $id ) );
		$this->assertSame( array(), Index::all() );
	}

	public function test_whatsapp_flow_needs_a_destination_with_a_number(): void {
		$id = FlowRepository::create( Templates::build( 'contact' ), 1 );
		$this->assertWPErrorCode( 'krivo_chatflow_validation', FlowRepository::publish( $id ) );
		$codes = array_column( FlowRepository::publish( $id )->get_error_data()['report']['errors'], 'code' );
		$this->assertContains( 'no_whatsapp_destination', $codes );

		$this->add_destination();
		$this->assertIsArray( FlowRepository::publish( $id ) );
	}

	public function test_index_follows_publish_pause_and_delete(): void {
		$this->add_destination();
		$a = FlowRepository::create( Templates::build( 'contact' ), 1 );
		$b = FlowRepository::create( Templates::build( 'support' ), 1 );
		FlowRepository::publish( $a );
		FlowRepository::publish( $b );
		$this->assertSame( array( $a, $b ), array_keys( Index::all() ) );

		$this->assertTrue( FlowRepository::pause( $a ) );
		$this->assertSame( array( $b ), array_keys( Index::all() ) );
		$this->assertNull( FlowRepository::published( $a ), 'A paused flow is not served' );

		$this->assertTrue( FlowRepository::delete( $b ) );
		$this->assertSame( array(), Index::all() );
		$this->assertFalse( FlowRepository::delete( $b ) );
		$this->assertFalse( FlowRepository::pause( 424242 ) );
	}

	public function test_index_orders_by_priority_and_has_no_private_data(): void {
		$this->add_destination();
		$low  = Templates::build( 'support' );
		$high = Templates::build( 'quote' );
		$low['display']['priority']  = 1;
		$high['display']['priority'] = 50;
		$a = FlowRepository::create( $low, 1 );
		$b = FlowRepository::create( $high, 1 );
		FlowRepository::publish( $a );
		FlowRepository::publish( $b );
		$this->assertSame( array( $b, $a ), array_keys( Index::all() ), 'Higher priority first' );
		foreach ( Index::all() as $entry ) {
			$this->assertArrayNotHasKey( 'automations', $entry );
			$this->assertStringNotContainsString( 'sales@example.test', (string) wp_json_encode( $entry ) );
		}
	}

	public function test_duplicate_creates_an_independent_draft(): void {
		$id   = FlowRepository::create( Templates::build( 'support' ), 1 );
		$copy = FlowRepository::duplicate( $id, 2 );
		$this->assertGreaterThan( $id, $copy );
		$this->assertStringContainsString( '(copy)', FlowRepository::get( $copy )['draft']['name'] );
		$this->assertSame( 'draft', FlowRepository::get( $copy )['status'] );
		$this->assertSame( 0, FlowRepository::duplicate( 4242, 1 ) );
	}

	// ---------------------------------------------------------------- manifest

	public function test_manifest_contains_nothing_private(): void {
		$dest = $this->add_destination( '5511999998888', array( 'secret-sales@example.test' ) );
		$raw  = Templates::build( 'quote' );
		$raw['whatsapp']['default_destination'] = $dest;
		$raw['automations'][]                   = array( 'id' => 'a_hook', 'trigger' => 'form.submitted', 'type' => 'send_webhook', 'enabled' => true, 'config' => array( 'connection_id' => 'con_abcd1234' ) );
		$config   = Sanitizer::sanitize( $raw );
		$manifest = Manifest::build( 7, 3, $config );
		$json     = (string) wp_json_encode( $manifest );

		foreach ( array( 'secret-sales@example.test', '5511999998888', 'wa.me', 'con_abcd1234', 'automations', 'send_webhook', 'default_destination' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, "The public manifest must not expose {$needle}" );
		}
		$this->assertSame( 7, $manifest['boot']['id'] );
		$this->assertSame( 3, $manifest['boot']['rev'] );
		$this->assertTrue( $manifest['flow']['finish']['whatsapp'] );
		$this->assertNotEmpty( $manifest['flow']['i18n']['close'] );
		$this->assertNotEmpty( $manifest['flow']['messages']['required'] );
	}

	public function test_manifest_hides_static_hidden_field_values(): void {
		$config   = Sanitizer::sanitize(
			array( 'steps' => array( array( 'fields' => array( array( 'type' => 'hidden', 'label' => 'Campaign', 'source' => 'static', 'value' => 'internal-tag-42' ) ) ) ) )
		);
		$manifest = Manifest::build( 1, 1, $config );
		$this->assertStringNotContainsString( 'internal-tag-42', (string) wp_json_encode( $manifest ) );
	}

	public function test_signature_is_opt_in_and_the_link_is_a_second_consent(): void {
		$config = Sanitizer::sanitize( $this->raw_flow() );
		$this->assertNull( Manifest::boot( 1, 1, $config )['signature'], 'Off by default' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'branding' => array( 'signature_enabled' => true ) ) );
		$sig = Manifest::boot( 1, 1, $config )['signature'];
		$this->assertSame( 'Krivo Works', $sig['name'] );
		$this->assertSame( '', $sig['link'], 'Text only until the link is authorised separately' );

		Settings::update( array( 'branding' => array( 'signature_link' => true ) ) );
		$this->assertSame( KRIVO_CHATFLOW_CREDIT_URL, Manifest::boot( 1, 1, $config )['signature']['link'] );

		Settings::update( array( 'branding' => array( 'signature_enabled' => false ) ) );
		$this->assertNull( Manifest::boot( 1, 1, $config )['signature'] );
	}

	// ---------------------------------------------------------------- validation report

	public function test_report_warns_about_unknown_variables_and_unreachable_rules(): void {
		$config = Sanitizer::sanitize(
			array(
				'steps'    => array( array( 'id' => 's_a', 'fields' => array(
					array( 'id' => 'f_a', 'type' => 'text', 'key' => 'a', 'label' => 'A' ),
					array( 'id' => 'f_b', 'type' => 'text', 'key' => 'b', 'label' => 'B' ),
				) ) ),
				'whatsapp' => array( 'message' => 'Hi {name} {nonexistent_var}' ),
				'logic'    => array( 'rules' => array(
					array( 'id' => 'r1', 'when' => array( 'conditions' => array( array( 'left' => 'field:b', 'op' => 'eq', 'right' => 'x' ) ) ), 'then' => array( array( 'do' => 'show_field', 'target' => 'f_a' ) ) ),
					array( 'id' => 'r2', 'when' => array( 'conditions' => array( array( 'left' => 'field:ghost', 'op' => 'eq', 'right' => 'x' ) ) ), 'then' => array( array( 'do' => 'hide_field', 'target' => 'f_b' ) ) ),
				) ),
			)
		);
		$codes = array_column( Validator::report( $config )['warnings'], 'code' );
		$this->assertContains( 'unknown_variable', $codes );
		$this->assertContains( 'rule_forward_reference', $codes );
		$this->assertContains( 'rule_unknown_field', $codes );
		$this->assertContains( 'no_automations', $codes );
	}

	public function test_report_flags_choice_fields_without_options_and_unreadable_colours(): void {
		$config = Sanitizer::sanitize(
			array(
				'steps'  => array( array( 'fields' => array( array( 'type' => 'select', 'label' => 'Pick' ) ) ) ),
				'bubble' => array( 'colors' => array( 'bg' => '#FFFFFF', 'icon' => '#FEFEFE' ) ),
				'design' => array( 'accent' => '#FFFF00' ),
				'display' => array( 'mode' => 'only' ),
			)
		);
		$report = Validator::report( $config );
		$this->assertContains( 'field_no_options', array_column( $report['errors'], 'code' ) );
		$codes = array_column( $report['warnings'], 'code' );
		$this->assertContains( 'bubble_contrast', $codes );
		$this->assertContains( 'accent_contrast', $codes );
		$this->assertContains( 'display_nowhere', $codes );
	}

	public function test_defaults_document_the_allowed_enums(): void {
		$this->assertContains( 'conversational', Defaults::MODES );
		$this->assertContains( 'phone-chat', Defaults::ICONS );
		$this->assertNotContains( 'whatsapp', Defaults::ICONS, 'The WhatsApp logo is not shipped' );
	}
}
