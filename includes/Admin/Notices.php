<?php
/**
 * Admin notices.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Admin;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * One welcome notice, only on the Plugins screen, only for people who can create a chat, dismissible
 * for good (stored per user) and gone as soon as the first chat exists. No promotion, no repetition.
 */
final class Notices {

	public const ACTION = 'krivo_chatflow_dismiss_notice';
	public const META   = 'krivo_chatflow_dismissed';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'welcome' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Whether a notice was dismissed by the current user.
	 *
	 * @param string $key Notice key.
	 * @return bool
	 */
	public static function dismissed( string $key ): bool {
		$list = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $list ) && in_array( $key, $list, true );
	}

	/**
	 * Prints the welcome notice.
	 *
	 * @return void
	 */
	public static function welcome(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( Capabilities::CREATE_FLOWS ) || self::dismissed( 'welcome' ) || array() !== FlowRepository::all() ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&notice=welcome' ), self::ACTION );
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Krivo ChatFlow is ready.', 'krivo-chatflow' ) . '</strong> ';
		echo esc_html__( 'Create your first chat: it takes a couple of minutes.', 'krivo-chatflow' ) . ' ';
		echo '<a href="' . esc_url( Menu::url( 'dashboard' ) ) . '">' . esc_html__( 'Get started', 'krivo-chatflow' ) . '</a> &middot; ';
		echo '<a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'krivo-chatflow' ) . '</a></p></div>';
	}

	/**
	 * Remembers, for the current user, that a notice was dismissed. Unknown keys are ignored.
	 *
	 * @param string $key Notice key.
	 * @return void
	 */
	public static function remember( string $key ): void {
		$list = get_user_meta( get_current_user_id(), self::META, true );
		$list = is_array( $list ) ? $list : array();
		if ( in_array( $key, array( 'welcome' ), true ) && ! in_array( $key, $list, true ) ) {
			$list[] = $key;
			update_user_meta( get_current_user_id(), self::META, $list );
		}
	}

	/**
	 * Stores the dismissal and goes back.
	 *
	 * @return void
	 */
	public static function dismiss(): void {
		check_admin_referer( self::ACTION );
		if ( '' === Capabilities::first_granted() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'krivo-chatflow' ), '', array( 'response' => 403 ) );
		}
		$key = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() above.
		self::remember( $key );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}
}
