<?php
/**
 * First-activation redirect.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Admin;

use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Db\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the administrator to the welcome screen ONCE after activating the plugin by hand. Never on bulk
 * activation, network admin, AJAX or WP-CLI, and never for people without access to the plugin.
 */
final class Activation {

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	/**
	 * Redirects once.
	 *
	 * @return void
	 */
	public static function maybe_redirect(): void {
		if ( ! get_transient( Installer::ACTIVATION_TRANSIENT ) ) {
			return;
		}
		delete_transient( Installer::ACTIVATION_TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own flag for bulk activation; nothing is changed here.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( '' === Capabilities::first_granted() ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'kcf-welcome', '1', Menu::url( 'dashboard' ) ) );
		exit;
	}
}
