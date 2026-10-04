<?php
/**
 * Encrypted secret storage.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Stores credentials (SMTP password, webhook URLs, tokens, signing secrets) encrypted at rest with
 * libsodium's secretbox (WordPress ships a polyfill, so the extension is not required).
 *
 * Secrets live in their own non-autoloaded option, separate from flow and connection JSON, which are
 * listed, exported and duplicated. They are never returned by REST, printed in HTML/JS or logged.
 *
 * Honest limit: encryption at rest protects database leaks and backups. It does not protect against
 * code execution on the site, which can read the key material from wp-config.php.
 */
final class Secrets {

	public const OPTION = 'krivo_chatflow_secrets';

	private const PREFIX = 'k1:';

	/**
	 * Stores (or replaces) a secret.
	 *
	 * @param string $ref   Reference such as "smtp.password" or "conn.abc.url".
	 * @param string $plain Plain value. An empty string deletes the secret.
	 * @return bool
	 */
	public static function set( string $ref, string $plain ): bool {
		if ( ! self::valid_ref( $ref ) ) {
			return false;
		}
		if ( '' === $plain ) {
			self::delete( $ref );
			return true;
		}
		$all         = self::all();
		$all[ $ref ] = self::encrypt( $plain );
		return update_option( self::OPTION, $all, false ) || get_option( self::OPTION ) === $all;
	}

	/**
	 * Reads and decrypts a secret.
	 *
	 * @param string $ref Reference.
	 * @return string Empty string when missing or unreadable (for example after salts were rotated).
	 */
	public static function get( string $ref ): string {
		$all = self::all();
		if ( ! isset( $all[ $ref ] ) || ! is_string( $all[ $ref ] ) ) {
			return '';
		}
		return self::decrypt( $all[ $ref ] );
	}

	/**
	 * Whether a secret exists (even if it can no longer be decrypted).
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	public static function has( string $ref ): bool {
		$all = self::all();
		return isset( $all[ $ref ] ) && '' !== $all[ $ref ];
	}

	/**
	 * Whether a secret exists and can be decrypted with the current keys.
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	public static function is_readable( string $ref ): bool {
		return self::has( $ref ) && '' !== self::get( $ref );
	}

	/**
	 * Deletes one secret.
	 *
	 * @param string $ref Reference.
	 * @return void
	 */
	public static function delete( string $ref ): void {
		$all = self::all();
		if ( isset( $all[ $ref ] ) ) {
			unset( $all[ $ref ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Deletes every secret whose reference starts with the prefix (for example when a connection is removed).
	 *
	 * @param string $prefix Reference prefix.
	 * @return void
	 */
	public static function delete_prefix( string $prefix ): void {
		$all     = self::all();
		$changed = false;
		foreach ( array_keys( $all ) as $ref ) {
			if ( 0 === strpos( (string) $ref, $prefix ) ) {
				unset( $all[ $ref ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Masks a value for display: only the last two characters survive, never the whole secret.
	 *
	 * @param string $plain Plain value.
	 * @return string
	 */
	public static function mask( string $plain ): string {
		$length = mb_strlen( $plain );
		if ( $length <= 6 ) {
			return str_repeat( '•', max( 4, $length ) );
		}
		return str_repeat( '•', 6 ) . mb_substr( $plain, -2 );
	}

	/**
	 * Allowed reference format.
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	private static function valid_ref( string $ref ): bool {
		return 1 === preg_match( '/^[a-z0-9_.:-]{1,80}$/', $ref );
	}

	/**
	 * All stored (encrypted) secrets.
	 *
	 * @return array<string,string>
	 */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Derives the 32-byte encryption key from the site's salts.
	 *
	 * @return string
	 */
	private static function key(): string {
		$length = defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;
		return hash_hkdf( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), $length, 'krivo-chatflow-secrets-v1' );
	}

	/**
	 * Encrypts a value.
	 *
	 * @param string $plain Plain text.
	 * @return string "k1:" followed by base64(nonce . ciphertext).
	 */
	private static function encrypt( string $plain ): string {
		$nonce_len = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;
		$nonce     = random_bytes( $nonce_len );
		$cipher    = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of ciphertext, not obfuscation.
	}

	/**
	 * Decrypts a value. Returns an empty string if it cannot be decrypted.
	 *
	 * @param string $stored Stored string.
	 * @return string
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return '';
		}
		$raw       = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own ciphertext.
		$nonce_len = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;
		if ( false === $raw || strlen( $raw ) <= $nonce_len ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, $nonce_len ), substr( $raw, 0, $nonce_len ), self::key() );
		return false === $plain ? '' : $plain;
	}
}
