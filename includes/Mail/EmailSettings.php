<?php
/**
 * E-mail settings.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Mail;

use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Infrastructure\Crypto\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Sending configuration: use the WordPress mailer as it is, or the ChatFlow's own SMTP server.
 * The SMTP password lives in the encrypted secret store (or in the KRIVO_CHATFLOW_SMTP_PASSWORD
 * constant) and is never returned to the browser.
 */
final class EmailSettings {

	public const OPTION          = 'krivo_chatflow_email';
	public const PASSWORD_SECRET = 'smtp.password';

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'       => 'wp',
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => true,
			'username'   => '',
			'from_email' => '',
			'from_name'  => '',
			'reply_to'   => '',
		);
	}

	/**
	 * Current settings (without the password).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), self::sanitize( is_array( $stored ) ? $stored : array() ) );
	}

	/**
	 * Sanitises settings input.
	 *
	 * @param array $raw Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $raw ): array {
		$defaults = self::defaults();
		$host     = isset( $raw['host'] ) ? strtolower( trim( (string) $raw['host'] ) ) : '';
		$host     = preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host ) ? $host : '';
		$from     = Sanitizer::email( $raw['from_email'] ?? '' );
		$reply_to = Sanitizer::email( $raw['reply_to'] ?? '' );
		$port     = isset( $raw['port'] ) && is_numeric( $raw['port'] ) ? (int) $raw['port'] : 0;

		return array(
			'mode'       => Sanitizer::enum( $raw['mode'] ?? '', array( 'wp', 'custom' ), $defaults['mode'] ),
			'host'       => $host,
			'port'       => $port >= 1 && $port <= 65535 ? $port : $defaults['port'], // A port that cannot exist is not "fixed" to some other number.
			'encryption' => Sanitizer::enum( $raw['encryption'] ?? '', array( 'none', 'ssl', 'tls' ), $defaults['encryption'] ),
			'auth'       => Sanitizer::bool( $raw['auth'] ?? $defaults['auth'] ),
			'username'   => Sanitizer::str( $raw['username'] ?? '', 190 ),
			'from_email' => $from,
			'from_name'  => Sanitizer::str( $raw['from_name'] ?? '', 120 ),
			'reply_to'   => $reply_to,
		);
	}

	/**
	 * Saves settings. The password is optional: omitted keeps the current one, an empty string removes it.
	 *
	 * @param array       $raw      Raw input.
	 * @param string|null $password New password, or null to keep the current one.
	 * @return array<string,mixed> Stored settings.
	 */
	public static function update( array $raw, ?string $password = null ): array {
		update_option( self::OPTION, self::sanitize( $raw ), false );
		if ( null !== $password && ! defined( 'KRIVO_CHATFLOW_SMTP_PASSWORD' ) ) {
			Secrets::set( self::PASSWORD_SECRET, $password );
		}
		return self::get();
	}

	/**
	 * The SMTP password in clear, read only at send time.
	 *
	 * @return string
	 */
	public static function password(): string {
		if ( defined( 'KRIVO_CHATFLOW_SMTP_PASSWORD' ) ) {
			return (string) KRIVO_CHATFLOW_SMTP_PASSWORD;
		}
		return Secrets::get( self::PASSWORD_SECRET );
	}

	/**
	 * Describes the password for the UI without revealing it.
	 *
	 * @return array{is_set:bool,source:string,readable:bool}
	 */
	public static function password_status(): array {
		if ( defined( 'KRIVO_CHATFLOW_SMTP_PASSWORD' ) ) {
			return array(
				'is_set'   => '' !== (string) KRIVO_CHATFLOW_SMTP_PASSWORD,
				'source'   => 'constant',
				'readable' => true,
			);
		}
		return array(
			'is_set'   => Secrets::has( self::PASSWORD_SECRET ),
			'source'   => 'database',
			'readable' => Secrets::is_readable( self::PASSWORD_SECRET ) || ! Secrets::has( self::PASSWORD_SECRET ),
		);
	}
}
