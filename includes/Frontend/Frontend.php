<?php
/**
 * Front-end wiring.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the front-end hooks. On admin, REST, AJAX and cron requests nothing is hooked at all.
 */
final class Frontend {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( Shortcode::TAG, array( Shortcode::class, 'render' ) );
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( Assets::class, 'print_footer' ), 20 );
	}
}
