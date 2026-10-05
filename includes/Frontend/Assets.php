<?php
/**
 * Front-end assets.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the two small files every widget page needs (loader.js and bubble.css) through the WordPress
 * APIs, and prints the page's configuration as a non-executable JSON data block. The heavy panel
 * (panel.js, panel.css) is NOT enqueued: the loader downloads it on the visitor's first intent.
 *
 * A page that shows no widget gets nothing from this plugin: no file, no markup, no database query.
 */
final class Assets {

	public const HANDLE = 'krivo-chatflow-frontend';

	/**
	 * Flows rendered inline by a shortcode on this page: id => boot.
	 *
	 * @var array<int,array>
	 */
	private static $inline = array();

	/**
	 * Whether the shared files were enqueued on this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Enqueues the shared files when a widget appears on this page.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( null !== Gate::select() ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueues loader.js and bubble.css (once per request).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_style( self::HANDLE, KRIVO_CHATFLOW_URL . 'assets/frontend/bubble.css', array(), KRIVO_CHATFLOW_VERSION );
		wp_enqueue_script(
			self::HANDLE,
			KRIVO_CHATFLOW_URL . 'assets/frontend/loader.js',
			array(),
			KRIVO_CHATFLOW_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * Registers a flow shown inline by the shortcode and makes sure the files are enqueued.
	 *
	 * @param int $flow_id Flow id.
	 * @return bool Whether the flow is published and was registered.
	 */
	public static function add_inline( int $flow_id ): bool {
		$entry = Index::get( $flow_id );
		if ( null === $entry ) {
			return false;
		}
		self::$inline[ $flow_id ] = (array) $entry['boot'];
		self::enqueue();
		return true;
	}

	/**
	 * Prints the bubble and the configuration block in the footer.
	 *
	 * @return void
	 */
	public static function print_footer(): void {
		$selection = Gate::select();
		if ( null === $selection && array() === self::$inline ) {
			return;
		}

		if ( null !== $selection ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer::bubble() escapes every value it prints.
			echo Renderer::bubble( $selection['boot'] ) . "\n";
		}
		wp_print_inline_script_tag(
			self::config_json( $selection ),
			array(
				'type' => 'application/json',
				'id'   => 'krivo-chatflow-config',
			)
		);
	}

	/**
	 * The configuration the loader reads. Printed with JSON_HEX_* so no value can end the script element.
	 *
	 * @param array|null $selection Selected flow for the bubble, or null.
	 * @return string
	 */
	private static function config_json( ?array $selection ): string {
		$config = array(
			'version'   => KRIVO_CHATFLOW_VERSION,
			'rest'      => esc_url_raw( rest_url( 'krivo-chatflow/v1/' ) ),
			'ajax'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'locale'    => get_locale(),
			'panel'     => array(
				'js'  => KRIVO_CHATFLOW_URL . 'assets/frontend/panel.js?ver=' . rawurlencode( KRIVO_CHATFLOW_VERSION ),
				'css' => KRIVO_CHATFLOW_URL . 'assets/frontend/panel.css?ver=' . rawurlencode( KRIVO_CHATFLOW_VERSION ),
			),
			'datalayer' => (bool) Settings::get( 'events.datalayer', false ),
			'preview'   => null !== $selection && $selection['preview'],
			'flow'      => null === $selection ? null : $selection['boot'],
			'inline'    => (object) self::$inline,
		);
		if ( null !== $selection && $selection['preview'] ) {
			// A draft cannot be fetched from the public endpoint: the manifest travels with the page.
			$flow               = FlowRepository::get( (int) $selection['id'] );
			$config['manifest'] = null === $flow ? null : Manifest::flow( (int) $selection['id'], 0, $flow['draft'] );
		}
		/**
		 * Filters the front-end configuration (must contain nothing private).
		 *
		 * @param array $config Configuration.
		 */
		$config = apply_filters( 'krivo_chatflow_frontend_config', $config );
		return (string) wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/**
	 * Forgets per-request state (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$inline   = array();
		self::$enqueued = false;
	}
}
