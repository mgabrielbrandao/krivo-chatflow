<?php
/**
 * [krivo_chatflow] shortcode.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

use Krivo\ChatFlow\Core\Flow\Index;

defined( 'ABSPATH' ) || exit;

/**
 * `[krivo_chatflow id="123"]` shows the same panel inline (no bubble, always open) wherever it is
 * placed; a "button only" chat shows its button instead. Assets are enqueued from inside the
 * callback, so only the pages that use the shortcode load them. Only a published flow is rendered.
 */
final class Shortcode {

	public const TAG = 'krivo_chatflow';

	/**
	 * Renders the shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::TAG );
		$id   = absint( $atts['id'] );
		if ( $id <= 0 || ! Gate::context_allows() || ! Assets::add_inline( $id ) ) {
			return '';
		}
		$entry = Index::get( $id );
		return Renderer::inline( $id, null === $entry ? null : (array) $entry['boot'] );
	}
}
