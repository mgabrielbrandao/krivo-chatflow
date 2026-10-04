<?php
/**
 * REST bootstrap.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the REST routes and the admin-ajax fallback. Classes are loaded only when the REST
 * server (or admin-ajax) actually runs, so ordinary page views pay nothing for them.
 */
final class Router {

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( PublicController::class, 'cache_headers' ), 10, 3 );
		if ( wp_doing_ajax() ) {
			AjaxFallback::register();
		}
	}

	/**
	 * Registers every route.
	 *
	 * @return void
	 */
	public static function routes(): void {
		PublicController::register();
		FlowsController::register();
		LeadsController::register();
		RoutingController::register();
		SettingsController::register();
		IntegrationsController::register();
		AnalyticsController::register();
		SystemController::register();
	}
}
