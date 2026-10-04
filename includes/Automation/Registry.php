<?php
/**
 * Automation registry.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

use Krivo\ChatFlow\Automation\Actions\ActionInterface;
use Krivo\ChatFlow\Automation\Actions\ChangeLeadStatus;
use Krivo\ChatFlow\Automation\Actions\OpenWhatsapp;
use Krivo\ChatFlow\Automation\Actions\RouteLead;
use Krivo\ChatFlow\Automation\Actions\SendEmail;
use Krivo\ChatFlow\Automation\Actions\SendWebhook;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of triggers and actions. Add-ons register their own on `krivo_chatflow_register_actions`
 * and `krivo_chatflow_register_triggers` without touching the core.
 */
final class Registry {

	public const LEAD_CREATED     = 'lead.created';
	public const FORM_SUBMITTED   = 'form.submitted';
	public const WHATSAPP_CLICKED = 'whatsapp.clicked';
	public const FLOW_COMPLETED   = 'flow.completed';
	public const LEAD_UPDATED     = 'lead.updated';
	public const ROUTING_MATCHED  = 'routing.matched';

	/**
	 * Registered actions.
	 *
	 * @var array<string,ActionInterface>|null
	 */
	private static $actions = null;

	/**
	 * Registered triggers (key => label).
	 *
	 * @var array<string,string>|null
	 */
	private static $triggers = null;

	/**
	 * Triggers in the order they fire during a submission, then the others.
	 *
	 * @return array<string,string>
	 */
	public static function triggers(): array {
		if ( null === self::$triggers ) {
			self::$triggers = array(
				self::FORM_SUBMITTED   => __( 'When the form is submitted', 'krivo-chatflow' ),
				self::LEAD_CREATED     => __( 'When a lead is created', 'krivo-chatflow' ),
				self::ROUTING_MATCHED  => __( 'When a routing rule matches', 'krivo-chatflow' ),
				self::WHATSAPP_CLICKED => __( 'When the visitor continues on WhatsApp', 'krivo-chatflow' ),
				self::FLOW_COMPLETED   => __( 'When the flow is completed', 'krivo-chatflow' ),
				self::LEAD_UPDATED     => __( 'When a lead is updated', 'krivo-chatflow' ),
			);
			do_action( 'krivo_chatflow_register_triggers', new self() );
		}
		return self::$triggers;
	}

	/**
	 * Registers a trigger (add-ons).
	 *
	 * @param string $key   Trigger key such as "crm.synced".
	 * @param string $label Label.
	 * @return void
	 */
	public function register_trigger( string $key, string $label ): void {
		self::triggers();
		if ( preg_match( '/^[a-z0-9_.]{3,40}$/', $key ) ) {
			self::$triggers[ $key ] = $label;
		}
	}

	/**
	 * Registered actions.
	 *
	 * @return array<string,ActionInterface>
	 */
	public static function actions(): array {
		if ( null === self::$actions ) {
			self::$actions = array();
			foreach ( array( new SendEmail(), new OpenWhatsapp(), new SendWebhook(), new ChangeLeadStatus(), new RouteLead() ) as $action ) {
				self::$actions[ $action->type() ] = $action;
			}
			do_action( 'krivo_chatflow_register_actions', new self() );
		}
		return self::$actions;
	}

	/**
	 * Registers an action (add-ons).
	 *
	 * @param ActionInterface $action Action.
	 * @return void
	 */
	public function register_action( ActionInterface $action ): void {
		self::actions();
		if ( preg_match( '/^[a-z0-9_]{3,40}$/', $action->type() ) ) {
			self::$actions[ $action->type() ] = $action;
		}
	}

	/**
	 * Returns an action by type.
	 *
	 * @param string $type Action type.
	 * @return ActionInterface|null
	 */
	public static function action( string $type ): ?ActionInterface {
		$actions = self::actions();
		return $actions[ $type ] ?? null;
	}

	/**
	 * Clears caches (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$actions  = null;
		self::$triggers = null;
	}

	/**
	 * Metadata for the admin UI.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe(): array {
		$actions = array();
		foreach ( self::actions() as $type => $action ) {
			$actions[] = array(
				'type'     => $type,
				'label'    => $action->label(),
				'mode'     => $action->mode(),
				'triggers' => $action->triggers(),
				'defaults' => $action->defaults(),
			);
		}
		$triggers = array();
		foreach ( self::triggers() as $key => $label ) {
			$triggers[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return array(
			'actions'  => $actions,
			'triggers' => $triggers,
		);
	}

	/**
	 * Sanitises one automation item of a flow.
	 *
	 * @param mixed $raw Raw item {id, trigger, type, enabled, managed, config}.
	 * @return array|null Clean item or null when it cannot be kept.
	 */
	public static function sanitize_item( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$type   = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : '';
		$action = self::action( $type );
		if ( null === $action ) {
			return null;
		}

		$trigger = isset( $raw['trigger'] ) ? (string) $raw['trigger'] : self::FORM_SUBMITTED;
		if ( ! isset( self::triggers()[ $trigger ] ) || ! in_array( $trigger, $action->triggers(), true ) ) {
			return null;
		}

		$id = isset( $raw['id'] ) ? (string) $raw['id'] : '';
		if ( ! preg_match( '/^[a-z0-9_]{1,24}$/', $id ) ) {
			$id = Text::random_id( 'a_', 8 );
		}

		return array(
			'id'      => $id,
			'trigger' => $trigger,
			'type'    => $type,
			'enabled' => ! isset( $raw['enabled'] ) || rest_sanitize_boolean( $raw['enabled'] ),
			'managed' => 'open_whatsapp' === $type && ! empty( $raw['managed'] ),
			'config'  => $action->sanitize( isset( $raw['config'] ) && is_array( $raw['config'] ) ? $raw['config'] : array() ),
		);
	}
}
