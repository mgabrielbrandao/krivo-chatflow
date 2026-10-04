<?php
/**
 * Seeds the local test site with one published flow per mode and prints their ids as JSON.
 * Run with: wp eval-file tests/e2e/seed.php   (the site is a throw-away test site).
 *
 * Every flow is limited to a URL that contains "kcf=<name>", so each test opens exactly one widget.
 */

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Templates;
use Krivo\ChatFlow\Core\Leads\LeadRepository;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Db\Tables;

global $wpdb;
Capabilities::grant_default();

foreach ( FlowRepository::all() as $flow ) {
	FlowRepository::delete( $flow['id'] );
}
$wpdb->query( 'DELETE FROM ' . Tables::leads() ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DELETE FROM ' . Tables::runs() ); // phpcs:ignore WordPress.DB
$wpdb->query( 'DELETE FROM ' . Tables::stats() ); // phpcs:ignore WordPress.DB
delete_transient( 'doing_cron' );
Settings::update(
	array(
		'branding'  => array( 'signature_enabled' => false, 'signature_link' => false ),
		'antispam'  => array( 'min_seconds' => 1, 'rate_limit_max' => 500, 'breaker_per_minute' => 500 ),
		'events'    => array( 'datalayer' => false ),
		'analytics' => array( 'count_views' => true ),
	)
);

RoutingRepository::save(
	array(
		'default_destination' => 'dst_sales',
		'destinations'        => array(
			array( 'id' => 'dst_sales', 'name' => 'Comercial', 'dial_code' => '55', 'number' => '11999998888', 'emails' => array( 'comercial@example.test' ) ),
			array( 'id' => 'dst_vip01', 'name' => 'Contas grandes', 'dial_code' => '55', 'number' => '21988887777', 'emails' => array( 'vip@example.test' ) ),
		),
		'rules'               => array(
			array(
				'id'          => 'rl_big',
				'name'        => 'Orçamento alto',
				'when'        => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:budget_range', 'op' => 'eq', 'right' => 'high' ) ) ),
				'destination' => 'dst_vip01',
			),
		),
	)
);

$only = static function ( string $name ): array {
	return array(
		'mode'    => 'only',
		'include' => array( array( 'type' => 'url', 'op' => 'contains', 'value' => 'kcf=' . $name ) ),
	);
};

$mail = array( 'id' => 'a_mail', 'trigger' => 'form.submitted', 'type' => 'send_email', 'config' => array( 'to' => array( 'destination' ), 'subject' => 'Novo lead {name}', 'body' => '{all_fields}' ) );
$wa   = array( 'id' => 'a_wa', 'trigger' => 'form.submitted', 'type' => 'open_whatsapp', 'managed' => true, 'config' => array( 'message' => 'Olá, sou {name}. {all_fields}' ) );

$ids = array();

// 1. Single form, with a conditional field and a consent checkbox.
$form                       = Templates::build( 'quote', array( 'name' => 'Formulário', 'whatsapp' => true ) );
$form['display']            = $only( 'form' );
$form['behavior']['delay']  = 0;
$form['steps'][0]['fields'] = array(
	array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Nome', 'key' => 'name', 'required' => true, 'autocomplete' => 'name' ),
	array( 'id' => 'f_mail', 'type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'required' => true ),
	array( 'id' => 'f_phone', 'type' => 'phone', 'label' => 'WhatsApp', 'key' => 'phone', 'required' => true, 'default_dial_code' => '55' ),
	array( 'id' => 'f_service', 'type' => 'select', 'label' => 'Serviço', 'key' => 'service', 'required' => true, 'options' => array( array( 'label' => 'Site', 'value' => 'site' ), array( 'label' => 'Loja virtual', 'value' => 'shop' ), array( 'label' => 'Outro', 'value' => 'other' ) ) ),
	array( 'id' => 'f_other', 'type' => 'text', 'label' => 'Qual serviço?', 'key' => 'other_service', 'required' => true ),
	array( 'id' => 'f_budget', 'type' => 'radio', 'label' => 'Orçamento', 'key' => 'budget_range', 'options' => array( array( 'label' => 'Até R$ 5 mil', 'value' => 'low' ), array( 'label' => 'Acima de R$ 5 mil', 'value' => 'high' ) ) ),
	array( 'id' => 'f_msg', 'type' => 'textarea', 'label' => 'Mensagem', 'key' => 'message', 'placeholder' => 'Conte um pouco sobre o projeto' ),
	array( 'id' => 'f_ok', 'type' => 'consent', 'label' => 'Consentimento', 'key' => 'consent', 'required' => true, 'text' => 'Concordo em ser contatado(a).', 'privacy_link' => false ),
);
$form['logic']['rules']       = array(
	array(
		'id'   => 'r_other',
		'when' => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:service', 'op' => 'eq', 'right' => 'other' ) ) ),
		'then' => array( array( 'do' => 'show_field', 'target' => 'f_other' ) ),
	),
);
$form['automations']          = array( $mail, $wa );
$form['whatsapp']['message']  = '';
$form['finish']               = array( 'button_label' => '', 'success_message' => 'Obrigado, {name}! Recebemos sua mensagem.' );
$ids['form']                  = FlowRepository::create( $form, 1 );

// 2. Steps with a jump (small budgets skip the company step) and a finish rule.
$steps                       = Templates::build( 'contact', array( 'name' => 'Etapas', 'whatsapp' => true ) );
$steps['mode']               = 'steps';
$steps['display']            = $only( 'steps' );
$steps['behavior']['delay']  = 0;
$steps['steps']              = array(
	array( 'id' => 's_who', 'title' => 'Quem é você?', 'fields' => array(
		array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Nome', 'key' => 'name', 'required' => true ),
		array( 'id' => 'f_mail', 'type' => 'email', 'label' => 'E-mail', 'key' => 'email', 'required' => true ),
	) ),
	array( 'id' => 's_need', 'title' => 'O que você precisa?', 'fields' => array(
		array( 'id' => 'f_kind', 'type' => 'radio', 'label' => 'Tipo', 'key' => 'kind', 'required' => true, 'options' => array( array( 'label' => 'Orçamento', 'value' => 'quote' ), array( 'label' => 'Suporte', 'value' => 'support' ) ) ),
	) ),
	array( 'id' => 's_company', 'title' => 'Sobre a empresa', 'fields' => array(
		array( 'id' => 'f_company', 'type' => 'text', 'label' => 'Empresa', 'key' => 'company', 'required' => true ),
	) ),
	array( 'id' => 's_end', 'title' => 'Para finalizar', 'fields' => array(
		array( 'id' => 'f_msg', 'type' => 'textarea', 'label' => 'Mensagem', 'key' => 'message' ),
	) ),
);
$steps['logic']['rules']     = array(
	array( 'id' => 'r_skip', 'after' => 's_need', 'when' => array( 'match' => 'all', 'conditions' => array( array( 'left' => 'field:kind', 'op' => 'eq', 'right' => 'support' ) ) ), 'then' => array( array( 'do' => 'goto', 'target' => 's_end' ) ) ),
);
$steps['automations']        = array( $mail, $wa );
$ids['steps']                = FlowRepository::create( $steps, 1 );

// 3. Conversational.
$conv                       = Templates::build( 'support', array( 'name' => 'Conversa', 'whatsapp' => true ) );
$conv['mode']               = 'conversational';
$conv['display']            = $only( 'conv' );
$conv['behavior']['delay']  = 0;
$conv['steps'][0]['fields'] = array(
	array( 'id' => 'f_name', 'type' => 'name', 'label' => 'Nome', 'key' => 'name', 'prompt' => 'Oi! Qual é o seu nome?', 'required' => true ),
	array( 'id' => 'f_topic', 'type' => 'select', 'label' => 'Assunto', 'key' => 'topic', 'prompt' => 'Prazer, {name}! Sobre o que quer falar?', 'required' => true, 'options' => array( array( 'label' => 'Vendas', 'value' => 'sales' ), array( 'label' => 'Suporte', 'value' => 'support' ) ) ),
	array( 'id' => 'f_phone', 'type' => 'phone', 'label' => 'WhatsApp', 'key' => 'phone', 'prompt' => 'Qual o seu WhatsApp, {name}?', 'required' => true, 'default_dial_code' => '55' ),
	array( 'id' => 'f_ok', 'type' => 'consent', 'label' => 'Consentimento', 'key' => 'consent', 'required' => true, 'text' => 'Posso falar com você por WhatsApp?' ),
);
$conv['automations']        = array( $mail, $wa );
$ids['conv']                = FlowRepository::create( $conv, 1 );

foreach ( $ids as $name => $id ) {
	$result = FlowRepository::publish( $id );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "Could not publish $name: " . wp_json_encode( $result->get_error_data() ) . "\n" );
		exit( 1 );
	}
}
echo wp_json_encode( $ids ) . "\n";
