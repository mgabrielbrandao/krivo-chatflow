<?php
/**
 * SVG sanitiser.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitises SVG markup pasted by the administrator ("custom SVG icon").
 *
 * SVG can carry script, event handlers, external references and entity bombs, so nothing is trusted:
 * the markup is parsed as XML (no DTD, no entities, no network) and rebuilt from an allowlist of
 * elements and attributes. Anything unknown is dropped; anything suspicious rejects the whole SVG.
 * The plugin never accepts SVG as an upload: it is pasted text, sanitised here, escaped at output.
 */
final class SvgSanitizer {

	private const MAX_BYTES = 8192;
	private const NS        = 'http://www.w3.org/2000/svg';

	/**
	 * Allowed elements.
	 *
	 * @var string[]
	 */
	private const ELEMENTS = array(
		'svg',
		'g',
		'path',
		'circle',
		'ellipse',
		'rect',
		'line',
		'polyline',
		'polygon',
		'defs',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'title',
		'desc',
	);

	/**
	 * Allowed attributes (lowercase for comparison).
	 *
	 * @var string[]
	 */
	private const ATTRIBUTES = array(
		'xmlns',
		'viewbox',
		'width',
		'height',
		'fill',
		'fill-rule',
		'fill-opacity',
		'clip-rule',
		'clip-path',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-miterlimit',
		'stroke-opacity',
		'stroke-dasharray',
		'stroke-dashoffset',
		'opacity',
		'transform',
		'd',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'x',
		'y',
		'x1',
		'y1',
		'x2',
		'y2',
		'points',
		'offset',
		'stop-color',
		'stop-opacity',
		'gradientunits',
		'gradienttransform',
		'preserveaspectratio',
		'id',
		'role',
		'aria-hidden',
		'focusable',
	);

	/**
	 * Returns clean SVG markup, or an empty string when the input is unusable or unsafe.
	 *
	 * @param string $svg Pasted markup.
	 * @return string
	 */
	public static function sanitize( string $svg ): string {
		$svg = trim( $svg );
		if ( '' === $svg || strlen( $svg ) > self::MAX_BYTES || ! class_exists( '\DOMDocument' ) ) {
			return '';
		}

		// DTDs and entities are never needed in an icon and are how XML bombs and XXE work.
		if ( preg_match( '/<!(DOCTYPE|ENTITY|\[CDATA\[)|<\?(?!xml\b)/i', $svg ) ) {
			return '';
		}
		$svg = (string) preg_replace( '/^<\?xml[^>]*\?>\s*/i', '', $svg );
		$svg = (string) preg_replace( '/<!--.*?-->/s', '', $svg );
		if ( 0 !== stripos( $svg, '<svg' ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->nodeName ) ) {
			return '';
		}

		$root = $dom->documentElement;
		// Namespace declarations are not DOM attributes: check the element's real namespace.
		if ( ! in_array( (string) $root->namespaceURI, array( '', self::NS ), true ) ) {
			return '';
		}
		if ( ! self::clean_node( $root ) ) {
			return '';
		}
		if ( ! $root->hasAttribute( 'xmlns' ) ) {
			$root->setAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		}

		$out = $dom->saveXML( $root );
		return false === $out ? '' : trim( $out );
	}

	/**
	 * Recursively removes everything outside the allowlist.
	 *
	 * @param \DOMElement $element Element to clean.
	 * @return bool False when the SVG must be rejected entirely.
	 */
	private static function clean_node( \DOMElement $element ): bool {
		// Attributes.
		$to_remove = array();
		foreach ( $element->attributes as $attribute ) {
			$name  = strtolower( $attribute->nodeName );
			$value = (string) $attribute->nodeValue;

			if ( ! in_array( $name, self::ATTRIBUTES, true ) ) {
				$to_remove[] = $attribute->nodeName;
				continue;
			}
			// No scripts, no external or data references. url(#id) to an in-document gradient is fine.
			if ( preg_match( '/javascript:|vbscript:|data:|expression\s*\(|@import|&#|\\\\/i', $value ) ) {
				return false;
			}
			if ( preg_match_all( '/url\(\s*([^)]*)\)/i', $value, $urls ) ) {
				foreach ( $urls[1] as $target ) {
					if ( 0 !== strpos( trim( $target, " '\"" ), '#' ) ) {
						return false;
					}
				}
			}
			if ( 'xmlns' === $name && 'http://www.w3.org/2000/svg' !== $value ) {
				return false;
			}
		}
		foreach ( $to_remove as $name ) {
			$element->removeAttribute( $name );
		}

		// Children.
		$children = array();
		foreach ( $element->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				if ( ! in_array( (string) $child->namespaceURI, array( '', self::NS ), true ) || ! in_array( strtolower( $child->nodeName ), self::ELEMENTS, true ) ) {
					$element->removeChild( $child );
					continue;
				}
				if ( ! self::clean_node( $child ) ) {
					return false;
				}
			} elseif ( $child instanceof \DOMText ) {
				// Text is only meaningful in <title>/<desc>; keep it (it is escaped when serialised).
				continue;
			} else {
				$element->removeChild( $child );
			}
		}
		return true;
	}
}
