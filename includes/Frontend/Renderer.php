<?php
/**
 * Server-side rendering of the closed bubble.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the CLOSED bubble (and its tooltip) in the page footer, with the `hidden` attribute so it
 * costs nothing until the loader decides to reveal it. The panel itself is built by JavaScript on the
 * first interaction. Colours and offsets are applied by the loader through the CSS object model, never
 * through a style attribute or a style tag, so the widget works under a strict Content Security Policy.
 */
final class Renderer {

	/**
	 * Markup of the bubble root.
	 *
	 * @param array $boot Boot data of the flow (Manifest::boot).
	 * @return string Escaped HTML.
	 */
	public static function bubble( array $boot ): string {
		$bubble    = (array) $boot['bubble'];
		$label     = (string) $bubble['label'];
		$tooltip   = (array) $bubble['tooltip'];
		$animation = (string) $bubble['animation'];

		$classes = array( 'kcf-bubble', 'kcf-shape-' . sanitize_html_class( (string) $bubble['shape'] ) );
		if ( 'none' !== $animation ) {
			$classes[] = 'kcf-anim-' . sanitize_html_class( $animation );
		}
		if ( ! empty( $bubble['shadow'] ) ) {
			$classes[] = 'kcf-shadow';
		}

		$html  = '<div id="krivo-chatflow-root" class="kcf-root kcf-pos-' . esc_attr( sanitize_html_class( (string) $bubble['position'] ) ) . '" data-kcf-flow="' . esc_attr( (string) (int) $boot['id'] ) . '" hidden>';
		if ( ! empty( $tooltip['enabled'] ) ) {
			$html .= '<div class="kcf-tooltip" role="status" hidden>';
			$html .= '<span class="kcf-tooltip-text">' . esc_html( (string) $tooltip['text'] ) . '</span>';
			if ( ! empty( $tooltip['dismissible'] ) ) {
				$html .= '<button type="button" class="kcf-tooltip-close" aria-label="' . esc_attr__( 'Dismiss', 'krivo-chatflow' ) . '">' . Icons::close_svg() . '</button>';
			}
			$html .= '</div>';
		}
		$html .= '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-label="' . esc_attr( $label ) . '" aria-expanded="false" aria-controls="krivo-chatflow-panel">';
		$html .= '<span class="kcf-icon" aria-hidden="true">' . self::icon( (array) $bubble['icon'] ) . '</span>';
		if ( 'pill' === $bubble['shape'] ) {
			// The pill shows its text; the other shapes are icon-only and rely on the aria-label.
			$html .= '<span class="kcf-label">' . esc_html( $label ) . '</span>';
		}
		if ( ! empty( $bubble['badge']['enabled'] ) ) {
			$html .= '<span class="kcf-badge" hidden>' . esc_html( (string) $bubble['badge']['text'] ) . '</span>';
		}
		$html .= '</button></div>';
		return $html;
	}

	/**
	 * Container for a flow shown inline by the shortcode.
	 *
	 * @param int $flow_id Flow id.
	 * @return string Escaped HTML.
	 */
	public static function inline( int $flow_id ): string {
		return '<div class="kcf-inline-host" data-kcf-inline="' . esc_attr( (string) $flow_id ) . '"></div>';
	}

	/**
	 * Icon markup.
	 *
	 * @param array $icon Icon settings { type, svg, image_url }.
	 * @return string
	 */
	private static function icon( array $icon ): string {
		$type = (string) ( $icon['type'] ?? 'chat' );
		if ( 'custom_svg' === $type && '' !== (string) $icon['svg'] ) {
			return wp_kses( (string) $icon['svg'], Icons::allowed_svg() );
		}
		if ( 'custom_image' === $type && '' !== (string) $icon['image_url'] ) {
			return '<img src="' . esc_url( (string) $icon['image_url'] ) . '" alt="" width="32" height="32" decoding="async">';
		}
		return Icons::svg( in_array( $type, Icons::names(), true ) ? $type : 'chat' );
	}
}
