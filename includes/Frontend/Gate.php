<?php
/**
 * Decides whether the widget appears on the current request.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

use Krivo\ChatFlow\Core\Flow\FlowRepository;
use Krivo\ChatFlow\Core\Flow\Index;
use Krivo\ChatFlow\Core\Flow\Manifest;
use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The cheapest possible answer to "does this page need a widget?": it reads the small autoloaded
 * index (no database query) and, when nothing matches, nothing is enqueued and nothing is printed.
 *
 * One bubble per page: when several flows match, the highest priority wins.
 */
final class Gate {

	/**
	 * Memoised selection for this request.
	 *
	 * @var array|false|null
	 */
	private static $selection = null;

	/**
	 * The flow to show on this request.
	 *
	 * @return array|null { id, rev, boot, preview } or null when nothing should appear.
	 */
	public static function select(): ?array {
		if ( null === self::$selection ) {
			self::$selection = self::compute();
		}
		return false === self::$selection ? null : self::$selection;
	}

	/**
	 * Forgets the memoised selection (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$selection = null;
	}

	/**
	 * Whether this kind of request can show a widget at all.
	 *
	 * @return bool
	 */
	public static function context_allows(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() || is_feed() || is_embed() || is_customize_preview() || is_robots() || is_favicon() || is_trackback() ) {
			return false;
		}
		// Page builder canvases: the widget would sit on top of the editing interface.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() ) || ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Works out the selection.
	 *
	 * @return array|false
	 */
	private static function compute() {
		if ( ! self::context_allows() ) {
			return false;
		}

		$preview = self::preview();
		if ( null !== $preview ) {
			return $preview;
		}

		foreach ( Index::all() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['boot'], $entry['display'] ) ) {
				continue;
			}
			if ( ! Rules::allows( (array) $entry['display'] ) ) {
				continue;
			}
			/**
			 * Filters whether the widget of a flow is rendered on the current page.
			 *
			 * @param bool  $render Whether to render.
			 * @param array $entry  Index entry { id, rev, priority, display, boot }.
			 */
			if ( ! apply_filters( 'krivo_chatflow_should_render', true, $entry ) ) {
				continue;
			}
			return array(
				'id'      => (int) $entry['id'],
				'rev'     => (int) $entry['rev'],
				'boot'    => (array) $entry['boot'],
				'preview' => false,
			);
		}
		return false;
	}

	/**
	 * The draft of a flow, shown only to a logged-in editor who followed a nonce link from the admin.
	 * It ignores the display rules so the editor can see it on any page.
	 *
	 * @return array|null
	 */
	private static function preview(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified right below, before anything is used.
		if ( ! isset( $_GET['krivo_chatflow_preview'], $_GET['_wpnonce'] ) ) {
			return null;
		}
		$id    = absint( wp_unslash( $_GET['krivo_chatflow_preview'] ) );
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		// phpcs:enable
		if ( $id <= 0 || ! wp_verify_nonce( $nonce, 'krivo_chatflow_preview_' . $id ) || ! current_user_can( Capabilities::EDIT_FLOWS ) ) {
			return null;
		}
		$flow = FlowRepository::get( $id );
		if ( null === $flow ) {
			return null;
		}
		return array(
			'id'      => $id,
			'rev'     => 0,
			'boot'    => Manifest::boot( $id, 0, $flow['draft'] ),
			'preview' => true,
		);
	}
}
