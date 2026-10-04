<?php
/**
 * Tracking context sanitiser.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission;

use Krivo\ChatFlow\Core\Settings\Settings;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the page context the browser reports with a submission (page, referrer, UTM, device).
 * None of it is trusted: URLs must be http(s), everything is length-limited, and the administrator
 * can turn off the capture of referrer and UTM parameters (data minimisation).
 */
final class Tracking {

	/**
	 * Sanitises the reported context.
	 *
	 * @param array $raw Raw context from the browser.
	 * @return array<string,string>
	 */
	public static function sanitize( array $raw ): array {
		$utm      = is_array( $raw['utm'] ?? null ) ? $raw['utm'] : array();
		$page_url = self::url( $raw['url'] ?? '' );

		$out = array(
			'page_url'     => $page_url,
			'page_path'    => (string) wp_parse_url( $page_url, PHP_URL_PATH ),
			'page_title'   => Text::limit( self::text( $raw['title'] ?? '' ), 255 ),
			'referrer'     => Settings::get( 'tracking.capture_referrer', true ) ? self::url( $raw['referrer'] ?? '' ) : '',
			'utm_source'   => '',
			'utm_medium'   => '',
			'utm_campaign' => '',
			'utm_content'  => '',
			'utm_term'     => '',
			'device'       => in_array( $raw['device'] ?? '', array( 'desktop', 'tablet', 'mobile' ), true ) ? (string) $raw['device'] : '',
		);
		if ( Settings::get( 'tracking.capture_utm', true ) ) {
			foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $name ) {
				$out[ 'utm_' . $name ] = Text::limit( self::text( $utm[ $name ] ?? '' ), 191 );
			}
		}
		if ( '' === $out['page_path'] ) {
			$out['page_path'] = '/';
		}
		return $out;
	}

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * An http(s) URL or an empty string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		return strlen( $url ) > 2048 ? '' : $url;
	}
}
