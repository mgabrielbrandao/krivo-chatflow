<?php
/**
 * Capabilities.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Caps;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's granular capabilities. Granted to administrators on activation; other roles can be
 * given a subset by a role editor (e.g. an agency client who may only see leads).
 */
final class Capabilities {

	public const CREATE_FLOWS        = 'krivo_chatflow_create_flows';
	public const EDIT_FLOWS          = 'krivo_chatflow_edit_flows';
	public const DELETE_FLOWS        = 'krivo_chatflow_delete_flows';
	public const VIEW_LEADS          = 'krivo_chatflow_view_leads';
	public const EDIT_LEADS          = 'krivo_chatflow_edit_leads';
	public const DELETE_LEADS        = 'krivo_chatflow_delete_leads';
	public const EXPORT_LEADS        = 'krivo_chatflow_export_leads';
	public const MANAGE_ROUTING      = 'krivo_chatflow_manage_routing';
	public const MANAGE_EMAIL        = 'krivo_chatflow_manage_email';
	public const VIEW_INTEGRATIONS   = 'krivo_chatflow_view_integrations';
	public const MANAGE_INTEGRATIONS = 'krivo_chatflow_manage_integrations';
	public const VIEW_ANALYTICS      = 'krivo_chatflow_view_analytics';
	public const MANAGE_SETTINGS     = 'krivo_chatflow_manage_settings';

	/**
	 * Every capability the plugin defines.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::CREATE_FLOWS,
			self::EDIT_FLOWS,
			self::DELETE_FLOWS,
			self::VIEW_LEADS,
			self::EDIT_LEADS,
			self::DELETE_LEADS,
			self::EXPORT_LEADS,
			self::MANAGE_ROUTING,
			self::MANAGE_EMAIL,
			self::VIEW_INTEGRATIONS,
			self::MANAGE_INTEGRATIONS,
			self::VIEW_ANALYTICS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Grants every capability to the administrator role of the current site.
	 *
	 * @return void
	 */
	public static function grant_default(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes every plugin capability from every role of the current site.
	 *
	 * @return void
	 */
	public static function revoke_all(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $name ) {
			$role = get_role( $name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user has the capability.
	 *
	 * @param string $cap One of the constants of this class.
	 * @return bool
	 */
	public static function can( string $cap ): bool {
		return current_user_can( $cap );
	}

	/**
	 * Returns the first plugin capability the current user holds, or an empty string.
	 * Used as the capability of the top-level admin menu.
	 *
	 * @return string
	 */
	public static function first_granted(): string {
		foreach ( self::all() as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}
		return '';
	}
}
