<?php
/**
 * Admin assets.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Admin;

use Krivo\ChatFlow\Core\Leads\CsvExporter;
use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the admin app only on the plugin's own screens (checked with the hook suffix), through the
 * WordPress enqueue APIs. React and the WordPress packages come from WordPress itself: nothing is
 * bundled twice, and there is no global admin CSS.
 */
final class Assets {

	public const HANDLE = 'krivo-chatflow-admin';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueues the app.
	 *
	 * @param string $hook Hook suffix of the current admin page.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( ! Menu::owns( $hook ) ) {
			return;
		}
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which screen to show.
		$screen = Menu::screen_for( $page );

		$deps = array( 'wp-element', 'wp-i18n', 'wp-api-fetch' );
		if ( 'flows' === $screen || 'dashboard' === $screen ) {
			// The editor's live preview runs the visitor's own panel; its files are enqueued the normal way.
			wp_enqueue_style( 'krivo-chatflow-preview-bubble', KRIVO_CHATFLOW_URL . 'assets/frontend/bubble.css', array(), KRIVO_CHATFLOW_VERSION );
			wp_enqueue_style( 'krivo-chatflow-preview-panel', KRIVO_CHATFLOW_URL . 'assets/frontend/panel.css', array( 'krivo-chatflow-preview-bubble' ), KRIVO_CHATFLOW_VERSION );
			wp_register_script( 'krivo-chatflow-preview-panel', KRIVO_CHATFLOW_URL . 'assets/frontend/panel.js', array(), KRIVO_CHATFLOW_VERSION, true );
			$deps[] = 'krivo-chatflow-preview-panel';
			// Media library, to pick the bubble icon and the avatar from the site's own uploads.
			wp_enqueue_media();
		}

		wp_enqueue_style( self::HANDLE, KRIVO_CHATFLOW_URL . 'assets/admin/admin.css', array(), KRIVO_CHATFLOW_VERSION );
		wp_enqueue_script( self::HANDLE, KRIVO_CHATFLOW_URL . 'assets/admin/admin.js', $deps, KRIVO_CHATFLOW_VERSION, true );
		wp_set_script_translations( self::HANDLE, 'krivo-chatflow', KRIVO_CHATFLOW_DIR . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.krivoChatflowAdmin = ' . wp_json_encode( self::data( $screen ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * Data the app starts with. Nothing secret: no keys, no passwords, no webhook addresses.
	 *
	 * @param string $screen Current screen.
	 * @return array
	 */
	public static function data( string $screen ): array {
		$caps = array();
		foreach ( Capabilities::all() as $cap ) {
			$caps[ $cap ] = current_user_can( $cap );
		}
		$menu = array();
		foreach ( Menu::screens() as $name => $def ) {
			if ( '' !== $def[2] && current_user_can( $def[2] ) ) {
				$menu[] = array(
					'screen' => $name,
					'label'  => $def[1],
					'url'    => Menu::url( $name ),
				);
			}
		}
		$dismissed = get_user_meta( get_current_user_id(), 'krivo_chatflow_dismissed', true );

		return array(
			'version'    => KRIVO_CHATFLOW_VERSION,
			'screen'     => $screen,
			'rest'       => esc_url_raw( rest_url( 'krivo-chatflow/v1/' ) ),
			'restRoot'   => esc_url_raw( rest_url() ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'caps'       => $caps,
			'menu'       => $menu,
			'exportUrl'  => CsvExporter::url(),
			'siteUrl'    => home_url( '/' ),
			'siteName'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'adminEmail' => (string) get_option( 'admin_email' ),
			'creditUrl'  => KRIVO_CHATFLOW_CREDIT_URL,
			'timezone'   => wp_timezone_string(),
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'defaultDial' => (string) Settings::get( 'general.default_dial_code', '' ),
			'dismissed'  => is_array( $dismissed ) ? array_values( $dismissed ) : array(),
			'images'     => array(
				'symbol' => KRIVO_CHATFLOW_URL . 'assets/images/symbol.svg',
			),
			'previewReady' => true,
		);
	}
}
