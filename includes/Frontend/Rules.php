<?php
/**
 * Page display rules.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Frontend;

use Krivo\ChatFlow\Core\Conditions\Wildcard;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, once per request on the server, whether a flow's display rules match the page being shown.
 * Device, delay, scroll and business hours are NOT decided here: they belong to the visitor, and a
 * page cache would freeze the first visitor's answer for everybody.
 *
 * WooCommerce conditionals are called only if the function exists, so the plugin works without it.
 */
final class Rules {

	/**
	 * Whether a flow may be shown on the current page, given its display settings.
	 *
	 * @param array $display Display settings of the flow (mode, include, exclude, logged).
	 * @return bool
	 */
	public static function allows( array $display ): bool {
		$mode = (string) ( $display['mode'] ?? 'all' );
		if ( 'none' === $mode ) {
			return false; // Manual flows (shortcode / API) never appear by themselves.
		}

		$logged = (string) ( $display['logged'] ?? 'any' );
		if ( ( 'in' === $logged && ! is_user_logged_in() ) || ( 'out' === $logged && is_user_logged_in() ) ) {
			return false;
		}

		foreach ( (array) ( $display['exclude'] ?? array() ) as $rule ) {
			if ( self::matches( (array) $rule ) ) {
				return false;
			}
		}
		if ( 'only' === $mode ) {
			foreach ( (array) ( $display['include'] ?? array() ) as $rule ) {
				if ( self::matches( (array) $rule ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Whether one rule matches the current page.
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	public static function matches( array $rule ): bool {
		$value = $rule['value'] ?? '';
		switch ( (string) ( $rule['type'] ?? '' ) ) {
			case 'url':
				return self::url_matches( (string) ( $rule['op'] ?? 'contains' ), (string) $value );
			case 'post_type':
				return is_singular( (string) $value ) || is_post_type_archive( (string) $value );
			case 'post':
				return is_singular() && (int) get_queried_object_id() === (int) $value;
			case 'term':
				return self::term_matches( (string) ( $rule['taxonomy'] ?? '' ), (string) $value );
			case 'page_type':
				return self::page_type_matches( (string) $value );
		}
		return false;
	}

	/**
	 * URL rule against the path of the request (and the path with its query string).
	 *
	 * @param string $op      contains, equals, starts_with, ends_with or matches (wildcard "*").
	 * @param string $pattern Value typed by the administrator.
	 * @return bool
	 */
	private static function url_matches( string $op, string $pattern ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output or stored.
		$path    = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$query   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$subject = array( $path );
		if ( '' !== $query ) {
			$subject[] = $path . '?' . rawurldecode( $query );
		}
		$pattern = mb_strtolower( $pattern );

		foreach ( $subject as $candidate ) {
			$candidate = mb_strtolower( $candidate );
			switch ( $op ) {
				case 'equals':
					$hit = $candidate === $pattern || rtrim( $candidate, '/' ) === rtrim( $pattern, '/' );
					break;
				case 'starts_with':
					$hit = 0 === mb_strpos( $candidate, $pattern );
					break;
				case 'ends_with':
					$hit = mb_substr( $candidate, -mb_strlen( $pattern ) ) === $pattern;
					break;
				case 'matches':
					$hit = Wildcard::match( $pattern, $candidate );
					break;
				case 'contains':
				default:
					$hit = false !== mb_strpos( $candidate, $pattern );
			}
			if ( $hit ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Taxonomy term rule: the post has the term, or the archive being shown is that term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    Term slug, id or name.
	 * @return bool
	 */
	private static function term_matches( string $taxonomy, string $value ): bool {
		if ( '' === $taxonomy || '' === $value || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		if ( is_singular() ) {
			return has_term( ctype_digit( $value ) ? (int) $value : $value, $taxonomy, get_queried_object_id() );
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$object = get_queried_object();
			return $object instanceof \WP_Term && $object->taxonomy === $taxonomy
				&& ( (string) $object->term_id === $value || $object->slug === $value || $object->name === $value );
		}
		return false;
	}

	/**
	 * Page type rule.
	 *
	 * @param string $type Page type key.
	 * @return bool
	 */
	private static function page_type_matches( string $type ): bool {
		switch ( $type ) {
			case 'front_page':
				return is_front_page();
			case 'blog':
				return is_home();
			case 'search':
				return is_search();
			case '404':
				return is_404();
			case 'archive':
				return is_archive();
			case 'singular':
				return is_singular();
			case 'woo_shop':
				return function_exists( 'is_shop' ) && is_shop();
			case 'woo_cart':
				return function_exists( 'is_cart' ) && is_cart();
			case 'woo_checkout':
				return function_exists( 'is_checkout' ) && is_checkout();
			case 'woo_account':
				return function_exists( 'is_account_page' ) && is_account_page();
		}
		return false;
	}
}
