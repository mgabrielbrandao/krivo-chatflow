<?php
/**
 * Template variable rendering.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Variables;

use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces {variables} in messages and templates. Escaping happens at output time and depends on
 * where the text will be used:
 *
 *  - "text"    plain text (WhatsApp, plain e-mail, JSON string leaves): values are inserted as typed.
 *  - "html"    HTML e-mail: values are escaped with esc_html, line breaks become <br>.
 *  - "subject" one line: control characters (including CR/LF) are removed (header injection).
 *
 * Unknown variables become an empty string (never the literal {name}). The token syntax only
 * matches {lowercase_letters_digits_underscore}, so braces in JSON or prose are left alone.
 */
final class Renderer {

	/**
	 * Portuguese aliases that always work, whatever the site language.
	 *
	 * @var array<string,string>
	 */
	public const ALIASES = array(
		'nome'          => 'name',
		'telefone'      => 'phone',
		'pagina'        => 'page_url',
		'pagina_titulo' => 'page_title',
		'fluxo'         => 'flow_name',
		'data'          => 'date',
		'hora'          => 'time',
		'destino'       => 'destination',
		'respostas'     => 'all_fields',
		'site_nome'     => 'site_name',
	);

	/**
	 * Renders a template.
	 *
	 * @param string $template Template with {variables}.
	 * @param array  $vars     Variables: name => string, and "all_fields" => list of {label,value} rows.
	 * @param string $mode     "text", "html" or "subject".
	 * @return string
	 */
	public static function render( string $template, array $vars, string $mode = 'text' ): string {
		$result = (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static function ( array $match ) use ( $vars, $mode ): string {
				$name = $match[1];
				if ( isset( self::ALIASES[ $name ] ) && ! array_key_exists( $name, $vars ) ) {
					$name = self::ALIASES[ $name ];
				}
				if ( 'all_fields' === $name ) {
					return self::all_fields( isset( $vars['all_fields'] ) && is_array( $vars['all_fields'] ) ? $vars['all_fields'] : array(), $mode );
				}
				$value = array_key_exists( $name, $vars ) && is_scalar( $vars[ $name ] ) ? (string) $vars[ $name ] : '';
				return self::escape( $value, $mode );
			},
			$template
		);

		return 'subject' === $mode ? Text::single_line( $result ) : $result;
	}

	/**
	 * Lists the variable names a template uses (canonical names, aliases resolved).
	 *
	 * @param string $template Template.
	 * @return string[]
	 */
	public static function used( string $template ): array {
		preg_match_all( '/\{([a-z0-9_]+)\}/', $template, $matches );
		$names = array();
		foreach ( $matches[1] as $name ) {
			$names[] = $name;
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Escapes a value for the output mode.
	 *
	 * @param string $value Raw value.
	 * @param string $mode  Output mode.
	 * @return string
	 */
	private static function escape( string $value, string $mode ): string {
		if ( 'html' === $mode ) {
			return nl2br( esc_html( $value ), false );
		}
		if ( 'subject' === $mode ) {
			return Text::single_line( $value );
		}
		return $value;
	}

	/**
	 * Formats the "all answers" summary.
	 *
	 * @param array  $rows Rows of {label,value}.
	 * @param string $mode Output mode.
	 * @return string
	 */
	private static function all_fields( array $rows, string $mode ): string {
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return is_array( $row ) && isset( $row['label'] ) && '' !== trim( (string) ( $row['value'] ?? '' ) );
				}
			)
		);

		if ( 'html' === $mode ) {
			$html = '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">';
			foreach ( $rows as $row ) {
				$html .= '<tr><td style="padding:6px 12px 6px 0;color:#666;vertical-align:top;white-space:nowrap">' . esc_html( (string) $row['label'] ) . '</td>'
					. '<td style="padding:6px 0;vertical-align:top">' . nl2br( esc_html( (string) $row['value'] ), false ) . '</td></tr>';
			}
			return $html . '</table>';
		}

		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = Text::single_line( (string) $row['label'] ) . ': ' . (string) $row['value'];
		}
		$text = implode( "\n", $lines );
		return 'subject' === $mode ? Text::single_line( $text ) : $text;
	}
}
