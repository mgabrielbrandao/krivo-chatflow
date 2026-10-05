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
		$button    = is_array( $boot['button'] ?? null ) ? $boot['button'] : null;
		$link      = null !== $button && 'link' === $button['action'] ? (string) $button['url'] : '';

		$classes = array( 'kcf-bubble', 'kcf-shape-' . sanitize_html_class( (string) $bubble['shape'] ) );
		if ( 'none' !== $animation ) {
			$classes[] = 'kcf-anim-' . sanitize_html_class( $animation );
		}
		if ( ! empty( $bubble['shadow'] ) ) {
			$classes[] = 'kcf-shadow';
		}

		$root_classes = 'kcf-root kcf-pos-' . sanitize_html_class( (string) $bubble['position'] ) . ( null === $button ? '' : ' kcf-mode-button' );
		$html         = '<div id="krivo-chatflow-root" class="' . esc_attr( $root_classes ) . '" data-kcf-flow="' . esc_attr( (string) (int) $boot['id'] ) . '" hidden>';
		$enabled      = ! empty( $tooltip['enabled'] );
		// A "button only" chat always has the card: it also carries the message when WhatsApp cannot be opened.
		if ( $enabled || null !== $button ) {
			$html .= '<div class="kcf-tooltip" role="status" hidden>';
			$html .= '<div class="kcf-tooltip-body">';
			if ( $enabled && '' !== (string) ( $tooltip['title'] ?? '' ) ) {
				$html .= '<strong class="kcf-tooltip-title">' . esc_html( (string) $tooltip['title'] ) . '</strong>';
			}
			$html .= '<span class="kcf-tooltip-text">' . ( $enabled ? esc_html( (string) $tooltip['text'] ) : '' ) . '</span>';
			if ( $enabled && '' !== (string) ( $tooltip['cta'] ?? '' ) ) {
				$html .= self::cta( 'kcf-tooltip-cta', (string) $tooltip['cta'], $button );
			}
			$html .= '</div>';
			if ( ! $enabled || ! empty( $tooltip['dismissible'] ) ) {
				$html .= '<button type="button" class="kcf-tooltip-close" aria-label="' . esc_attr__( 'Dismiss', 'krivo-chatflow' ) . '">' . Icons::close_svg() . '</button>';
			}
			$html .= '</div>';
		}

		$new_tab = null !== $button && ! empty( $button['new_tab'] );
		$name    = $new_tab ? $label . ' ' . __( '(opens in a new tab)', 'krivo-chatflow' ) : $label;
		if ( '' !== $link ) {
			// A plain link is a real link: it works before the script runs, with middle-click, and for screen readers.
			$html .= '<a class="' . esc_attr( implode( ' ', $classes ) ) . '" href="' . esc_url( $link ) . '"' . ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . ' aria-label="' . esc_attr( $name ) . '">';
		} elseif ( null !== $button ) {
			$html .= '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-label="' . esc_attr( $name . ' ' . __( '(opens in a new tab)', 'krivo-chatflow' ) ) . '">';
		} else {
			$html .= '<button type="button" class="' . esc_attr( implode( ' ', $classes ) ) . '" aria-label="' . esc_attr( $label ) . '" aria-expanded="false" aria-controls="krivo-chatflow-panel">';
		}
		$html .= '<span class="kcf-icon" aria-hidden="true">' . self::icon( (array) $bubble['icon'] ) . '</span>';
		if ( 'pill' === $bubble['shape'] ) {
			// The pill shows its text; the other shapes are icon-only and rely on the aria-label.
			$html .= '<span class="kcf-label">' . esc_html( $label ) . '</span>';
		}
		if ( ! empty( $bubble['badge']['enabled'] ) ) {
			$html .= '<span class="kcf-badge" hidden>' . esc_html( (string) $bubble['badge']['text'] ) . '</span>';
		}
		$html .= '' !== $link ? '</a></div>' : '</button></div>';
		return $html;
	}

	/**
	 * A call-to-action: a link when the chat opens an address, a button otherwise (the loader decides
	 * what a button does: open the chat, or ask the server for the WhatsApp link).
	 *
	 * @param string     $class_name CSS class.
	 * @param string     $text       Visible text.
	 * @param array|null $button     Button settings of a "button only" chat, or null.
	 * @return string Escaped HTML.
	 */
	private static function cta( string $class_name, string $text, ?array $button ): string {
		if ( null !== $button && 'link' === $button['action'] && '' !== (string) $button['url'] ) {
			$new_tab = ! empty( $button['new_tab'] );
			return '<a class="' . esc_attr( $class_name ) . '" href="' . esc_url( (string) $button['url'] ) . '"' . ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $text ) . '</a>';
		}
		return '<button type="button" class="' . esc_attr( $class_name ) . '">' . esc_html( $text ) . '</button>';
	}

	/**
	 * Container for a flow shown inline by the shortcode. A "button only" chat has no panel: it is
	 * printed as its call-to-action button.
	 *
	 * @param int        $flow_id Flow id.
	 * @param array|null $boot    Boot data of the flow (to recognise a "button only" chat).
	 * @return string Escaped HTML.
	 */
	public static function inline( int $flow_id, ?array $boot = null ): string {
		$button = is_array( $boot['button'] ?? null ) ? $boot['button'] : null;
		if ( null !== $button ) {
			$text = '' !== (string) $boot['bubble']['tooltip']['cta'] ? (string) $boot['bubble']['tooltip']['cta'] : (string) $boot['bubble']['label'];
			return '<div class="kcf-inline-button" data-kcf-inline-button="' . esc_attr( (string) $flow_id ) . '">' . self::cta( 'kcf-inline-cta', $text, $button ) . '</div>';
		}
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
