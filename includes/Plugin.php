<?php
/**
 * Composition root.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow;

use Krivo\ChatFlow\Automation\Engine;
use Krivo\ChatFlow\Automation\Queue\Scheduler;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Db\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's modules. Each module only adds hooks; classes are loaded lazily by the
 * autoloader when a hook actually fires.
 */
final class Plugin {

	/**
	 * Boots the plugin for the current request.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Migrator::register();

		add_action( 'init', array( Settings::class, 'register' ) );

		// Queue callbacks must exist on every request type (WP-Cron runs them on a bare request).
		Scheduler::register();
		Engine::register();

		// REST routes and the admin-ajax fallback; classes load only when the REST server (or ajax) runs.
		\Krivo\ChatFlow\Rest\Router::register();

		// Front end: the bubble, the shortcode and the privacy tools.
		\Krivo\ChatFlow\Frontend\Frontend::register();
		\Krivo\ChatFlow\Core\Leads\PrivacyTools::register();
		\Krivo\ChatFlow\Core\Leads\CsvExporter::register();

		// Admin only: menu, assets, notices and the first-activation redirect.
		if ( is_admin() ) {
			\Krivo\ChatFlow\Admin\Menu::register();
			\Krivo\ChatFlow\Admin\Assets::register();
			\Krivo\ChatFlow\Admin\Notices::register();
			\Krivo\ChatFlow\Admin\Activation::register();
		}

		do_action( 'krivo_chatflow_loaded' );
	}
}
