<?php
/**
 * Wildcard matching.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * Case-insensitive glob matching where "*" matches any sequence and every other character is literal.
 *
 * Administrators never write regular expressions (no ReDoS surface). The algorithm is the classic
 * iterative two-pointer glob with a single backtrack point, so it is O(pattern x subject) even for
 * hostile patterns such as "*a*a*a*a*b".
 */
final class Wildcard {

	private const MAX_PATTERN = 500;
	private const MAX_SUBJECT = 4096;

	/**
	 * Whether the subject matches the pattern.
	 *
	 * @param string $pattern Pattern ("*" is the only special character).
	 * @param string $subject Text to test.
	 * @return bool
	 */
	public static function match( string $pattern, string $subject ): bool {
		if ( mb_strlen( $pattern ) > self::MAX_PATTERN ) {
			return false;
		}
		$pattern = mb_strtolower( $pattern );
		$subject = mb_strtolower( mb_substr( $subject, 0, self::MAX_SUBJECT ) );

		$p_chars = preg_split( '//u', $pattern, -1, PREG_SPLIT_NO_EMPTY );
		$s_chars = preg_split( '//u', $subject, -1, PREG_SPLIT_NO_EMPTY );
		$p_chars = false === $p_chars ? array() : $p_chars;
		$s_chars = false === $s_chars ? array() : $s_chars;

		$p_len = count( $p_chars );
		$s_len = count( $s_chars );
		$p     = 0;
		$s     = 0;
		$star  = -1;
		$mark  = 0;

		while ( $s < $s_len ) {
			if ( $p < $p_len && '*' !== $p_chars[ $p ] && $p_chars[ $p ] === $s_chars[ $s ] ) {
				++$p;
				++$s;
			} elseif ( $p < $p_len && '*' === $p_chars[ $p ] ) {
				$star = $p;
				$mark = $s;
				++$p;
			} elseif ( -1 !== $star ) {
				$p = $star + 1;
				++$mark;
				$s = $mark;
			} else {
				return false;
			}
		}

		while ( $p < $p_len && '*' === $p_chars[ $p ] ) {
			++$p;
		}
		return $p === $p_len;
	}
}
