<?php
/**
 * Shared helpers: a published flow, routing, and a visitor who submits it.
 *
 * @package Krivo\ChatFlow
 */

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Submission\AntiSpam\Token;
use Krivo\ChatFlow\Core\Submission\Pipeline;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

/**
 * Used by the test cases that need real flows and real submissions.
 */
trait Krivo_FlowFactory {

	private const T0 = 1700000000;

	/**
	 * Creates routing with two destinations and one rule.
	 *
	 * @return void
	 */
	private function routing(): void {
		$saved = RoutingRepository::save(
			array(
				'default_destination' => 'dst_sales',
				'destinations'        => array(
					array( 'id' => 'dst_sales', 'name' => 'Sales', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'sales@example.test' ) ),
					array( 'id' => 'dst_vip01', 'name' => 'VIP', 'dial_code' => '55', 'number' => '21988887777', 'emails' => array( 'vip@example.test' ) ),
				),
				'rules'               => array(
					array(
						'id'          => 'rl_vip',
						'name'        => 'Big budget',
						'flows'       => array(),
						'when'        => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:budget', 'op' => 'eq', 'right' => 'high' ) ) ),
						'destination' => 'dst_vip01',
					),
				),
			)
		);
		$this->assertIsArray( $saved );
	}

	/**
	 * Creates and publishes a flow with name, e-mail, phone, budget and message.
	 *
	 * @param array $automations Automations (defaults: e-mail + WhatsApp).
	 * @param array $over        Config overrides.
	 * @return int Flow id.
	 */
	private function flow( ?array $automations = null, array $over = array() ): int {
		$this->routing();
		$config                = array_replace_recursive(
			array(
				'name'        => 'Contact',
				'mode'        => 'form',
				'steps'       => array(
					array(
						'id'     => 's_one',
						'fields' => array(
							array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Name', 'key' => 'name', 'required' => true ),
							array( 'id' => 'f_mail', 'type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'required' => true ),
							array( 'id' => 'f_phone', 'type' => 'phone', 'label' => 'Phone', 'key' => 'phone' ),
							array(
								'id'      => 'f_budget',
								'type'    => 'select',
								'label'   => 'Budget',
								'key'     => 'budget',
								'options' => array(
									array( 'label' => 'Low', 'value' => 'low' ),
									array( 'label' => 'High', 'value' => 'high' ),
								),
							),
							array( 'id' => 'f_msg', 'type' => 'textarea', 'label' => 'Message', 'key' => 'message' ),
							array( 'id' => 'f_consent', 'type' => 'consent', 'label' => 'Consent', 'key' => 'consent', 'required' => true, 'text' => 'I agree to be contacted.' ),
						),
					),
				),
				'automations' => $automations ?? array(
					array( 'id' => 'a_mail', 'trigger' => 'form.submitted', 'type' => 'send_email', 'config' => array( 'to' => array( 'site_admin', ), 'subject' => 'Lead {name}', 'body' => '{all_fields}' ) ),
					array( 'id' => 'a_wa', 'trigger' => 'form.submitted', 'type' => 'open_whatsapp', 'managed' => true, 'config' => array( 'message' => 'Hi, I am {name}' ) ),
				),
			),
			$over
		);
		$config['automations'] = $automations ?? $config['automations'];
		$id                    = FlowRepository::create( $config, 1 );
		$this->assertGreaterThan( 0, $id );
		$published = FlowRepository::publish( $id );
		$this->assertIsArray( $published, is_wp_error( $published ) ? $published->get_error_message() : '' );
		return $id;
	}

	private function valid_answers( array $over = array() ): array {
		return array_merge(
			array(
				'name'    => 'Maria Silva',
				'email'   => 'maria@example.test',
				'phone'   => '(11) 99999-1234',
				'budget'  => 'low',
				'message' => 'I would like a quote.',
				'consent' => true,
			),
			$over
		);
	}

	/**
	 * Submits as a visitor who opened the chat 10 seconds ago.
	 *
	 * @param int   $flow_id Flow id.
	 * @param array $answers Answers.
	 * @param array $extra   Payload overrides.
	 * @return array|WP_Error
	 */
	private function submit( int $flow_id, array $answers, array $extra = array() ) {
		Clock::freeze( self::T0 );
		$token = Token::issue( $flow_id );
		Clock::freeze( self::T0 + 10 );
		return Pipeline::handle(
			array_merge(
				array(
					'flow'    => $flow_id,
					'token'   => $token,
					'answers' => $answers,
					'hp'      => '',
					'context' => array(
						'url'      => 'https://example.test/pricing?x=1',
						'title'    => 'Pricing',
						'referrer' => 'https://www.google.com/',
						'device'   => 'mobile',
						'utm'      => array( 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'spring' ),
					),
				),
				$extra
			)
		);
	}
}
