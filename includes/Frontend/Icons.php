<?php
/**
 * Built-in bubble icons.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Four icons drawn for this plugin (24 x 24 grid, one colour, `currentColor`). No icon library is
 * bundled, so there is no third-party licence to carry, and nothing here imitates the logo of any
 * messaging service.
 */
final class Icons {

	/**
	 * Path data by icon name.
	 *
	 * @var array<string,string>
	 */
	private const PATHS = array(
		'chat'       => 'M6 3h12a4 4 0 0 1 4 4v7a4 4 0 0 1-4 4h-5.2l-4.6 3.6a.6.6 0 0 1-1-.47V18H6a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm2.5 8a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5zm3.5 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5zm3.5 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5z',
		'message'    => 'M6 3h12a4 4 0 0 1 4 4v7a4 4 0 0 1-4 4h-5.2l-4.6 3.6a.6.6 0 0 1-1-.47V18H6a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm1.5 4.25a.75.75 0 0 0 0 1.5h9a.75.75 0 0 0 0-1.5zm0 3.5a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5z',
		'headset'    => 'M12 2.5A8.5 8.5 0 0 0 3.5 11v4.5A2.5 2.5 0 0 0 6 18h1.2a1 1 0 0 0 1-1v-5.4a1 1 0 0 0-1-1H5V11a7 7 0 0 1 14 0v.6h-2.2a1 1 0 0 0-1 1V17a1 1 0 0 0 1 1h.2v.2a2 2 0 0 1-2 2h-2.4a1.4 1.4 0 1 0 0 1.5H15a3.5 3.5 0 0 0 3.45-2.95A2.5 2.5 0 0 0 20.5 15.5V11A8.5 8.5 0 0 0 12 2.5z',
		'phone-chat' => 'M6 3h12a4 4 0 0 1 4 4v7a4 4 0 0 1-4 4h-5.2l-4.6 3.6a.6.6 0 0 1-1-.47V18H6a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zm3.1 3.9a1.1 1.1 0 0 1 1.55 0l.9 1.2a1.1 1.1 0 0 1-.05 1.4l-.5.55a6 6 0 0 0 2.5 2.5l.55-.5a1.1 1.1 0 0 1 1.4-.05l1.2.9a1.1 1.1 0 0 1 0 1.55l-.6.6c-.65.65-1.65.85-2.5.5a9.2 9.2 0 0 1-5-5c-.35-.85-.15-1.85.5-2.5z',
	);

	/**
	 * Names of the built-in icons.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::PATHS );
	}

	/**
	 * SVG markup of a built-in icon (a safe constant, escaped by construction).
	 *
	 * @param string $name Icon name (falls back to "chat").
	 * @return string
	 */
	public static function svg( string $name ): string {
		$path = self::PATHS[ $name ] ?? self::PATHS['chat'];
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="' . esc_attr( $path ) . '"/></svg>';
	}

	/**
	 * The close glyph used by the tooltip and the panel (a plain X).
	 *
	 * @return string
	 */
	public static function close_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
	}

	/**
	 * Elements and attributes allowed when printing an administrator's own SVG (already sanitised on
	 * save; this is the late escaping at output).
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed_svg(): array {
		$common = array(
			'fill'            => true,
			'fill-rule'       => true,
			'fill-opacity'    => true,
			'clip-rule'       => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'stroke-opacity'  => true,
			'opacity'         => true,
			'transform'       => true,
			'id'              => true,
			'class'           => true,
			'clip-path'       => true,
		);
		return array(
			'svg'            => array(
				'xmlns'       => true,
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'focusable'   => true,
				'aria-hidden' => true,
				'role'        => true,
			) + $common,
			'g'              => $common,
			'path'           => array( 'd' => true ) + $common,
			'circle'         => array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			) + $common,
			'ellipse'        => array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			) + $common,
			'rect'           => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			) + $common,
			'line'           => array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			) + $common,
			'polyline'       => array( 'points' => true ) + $common,
			'polygon'        => array( 'points' => true ) + $common,
			'defs'           => array(),
			'lineargradient' => array(
				'id'            => true,
				'x1'            => true,
				'y1'            => true,
				'x2'            => true,
				'y2'            => true,
				'gradientunits' => true,
			),
			'radialgradient' => array(
				'id'            => true,
				'cx'            => true,
				'cy'            => true,
				'r'             => true,
				'gradientunits' => true,
			),
			'stop'           => array(
				'offset'       => true,
				'stop-color'   => true,
				'stop-opacity' => true,
			),
			'clippath'       => array( 'id' => true ),
			'title'          => array(),
			'desc'           => array(),
		);
	}
}
