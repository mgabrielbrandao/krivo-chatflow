<?php
/**
 * Admin screen host.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the container the admin app mounts into. It comes AFTER the `wp-header-end` marker, so the
 * notices WordPress and other plugins print stay above it, exactly where administrators expect them.
 */
final class Screen {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which screen to show; no state changes.
		$screen = Menu::screen_for( $page );
		$label  = Menu::screens()[ $screen ][1];

		echo '<div class="wrap krivo-chatflow-wrap">';
		echo '<h1 class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: screen name. */ __( 'Krivo ChatFlow: %s', 'krivo-chatflow' ), $label ) ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<div id="krivo-chatflow-admin" class="kcf-admin" data-screen="' . esc_attr( $screen ) . '">';
		echo '<p class="kcf-boot">' . esc_html__( 'Loading Krivo ChatFlow…', 'krivo-chatflow' ) . '</p>';
		echo '</div>';
		echo '<noscript><p>' . esc_html__( 'Krivo ChatFlow needs JavaScript to run its screens. Please enable JavaScript in your browser.', 'krivo-chatflow' ) . '</p></noscript>';
		echo '</div>';
	}
}
