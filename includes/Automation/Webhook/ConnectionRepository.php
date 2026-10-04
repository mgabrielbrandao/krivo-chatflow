<?php
/**
 * Webhook connections.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Webhook;

use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Infrastructure\Crypto\Secrets;
use Krivo\ChatFlow\Infrastructure\Http\SafeClient;
use Krivo\ChatFlow\Infrastructure\Logging\Redactor;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable webhook endpoints ("connections"): URL, method, authentication, headers and signing.
 * Several flows can use the same connection, and credentials live in one place.
 *
 * Secrets (the URL itself, which in n8n/Make/Zapier carries a token in its path, bearer tokens,
 * passwords, secret header values, the signing secret) are encrypted in the secret store. The public
 * view returned to the admin never contains them: only "is set" flags and a masked host.
 *
 * Secret semantics on save: field omitted = keep; a non-empty string = replace; "clear": true = remove.
 */
final class ConnectionRepository {

	public const OPTION = 'krivo_chatflow_integrations';

	public const MAX_CONNECTIONS = 20;
	public const MAX_HEADERS     = 10;

	public const PRESETS = array( 'custom', 'n8n', 'make', 'zapier' );

	private const FORBIDDEN_HEADERS = array( 'host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'expect', 'keep-alive', 'user-agent' );

	/**
	 * All stored connections (internal shape, no secrets inside).
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = is_array( $stored ) && isset( $stored['connections'] ) ? (array) $stored['connections'] : array();
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * Admin-safe list of connections.
	 *
	 * @return array[]
	 */
	public static function public_list(): array {
		return array_map( array( __CLASS__, 'public_view' ), self::all() );
	}

	/**
	 * Finds one stored connection.
	 *
	 * @param string $id Connection id.
	 * @return array|null
	 */
	public static function find( string $id ): ?array {
		foreach ( self::all() as $connection ) {
			if ( ( $connection['id'] ?? '' ) === $id ) {
				return $connection;
			}
		}
		return null;
	}

	/**
	 * Admin-safe view: flags and masks, never secrets.
	 *
	 * @param array $c Stored connection.
	 * @return array
	 */
	public static function public_view( array $c ): array {
		$id      = (string) $c['id'];
		$headers = array();
		foreach ( (array) ( $c['headers'] ?? array() ) as $i => $header ) {
			$secret    = ! empty( $header['secret'] );
			$headers[] = array(
				'name'      => (string) $header['name'],
				'secret'    => $secret,
				'value'     => $secret ? '' : (string) ( $header['value'] ?? '' ),
				'has_value' => $secret ? Secrets::has( "conn.{$id}.hdr.{$i}" ) : '' !== (string) ( $header['value'] ?? '' ),
			);
		}
		return array(
			'id'                  => $id,
			'name'                => (string) $c['name'],
			'preset'              => (string) $c['preset'],
			'method'              => (string) $c['method'],
			'url_host'            => (string) ( $c['url_host'] ?? '' ),
			'has_url'             => Secrets::has( "conn.{$id}.url" ),
			'url_readable'        => Secrets::is_readable( "conn.{$id}.url" ),
			'auth'                => array(
				'mode'        => (string) ( $c['auth']['mode'] ?? 'none' ),
				'header_name' => (string) ( $c['auth']['header_name'] ?? '' ),
				'username'    => (string) ( $c['auth']['username'] ?? '' ),
				'has_secret'  => Secrets::has( "conn.{$id}.auth" ),
			),
			'headers'             => $headers,
			'signing'             => array(
				'enabled'    => ! empty( $c['signing']['enabled'] ),
				'has_secret' => Secrets::has( "conn.{$id}.sign" ),
			),
			'timeout'             => (int) $c['timeout'],
			'allow_http'          => ! empty( $c['allow_http'] ),
			'allow_private_hosts' => ! empty( $c['allow_private_hosts'] ),
			'enabled'             => ! empty( $c['enabled'] ),
			'acknowledged_at'     => (string) ( $c['acknowledged_at'] ?? '' ),
			'acknowledged_by'     => (int) ( $c['acknowledged_by'] ?? 0 ),
		);
	}

	/**
	 * Decrypted connection for the executor. Never returned by any REST route.
	 *
	 * @param string $id Connection id.
	 * @return array|null
	 */
	public static function runtime( string $id ): ?array {
		$c = self::find( $id );
		if ( null === $c ) {
			return null;
		}
		$headers = array();
		foreach ( (array) ( $c['headers'] ?? array() ) as $i => $header ) {
			$value = ! empty( $header['secret'] ) ? Secrets::get( "conn.{$id}.hdr.{$i}" ) : (string) ( $header['value'] ?? '' );
			if ( '' !== $value ) {
				$headers[ (string) $header['name'] ] = $value;
			}
		}
		return array(
			'id'                  => $id,
			'name'                => (string) $c['name'],
			'url'                 => Secrets::get( "conn.{$id}.url" ),
			'method'              => (string) $c['method'],
			'auth_mode'           => (string) ( $c['auth']['mode'] ?? 'none' ),
			'auth_header_name'    => (string) ( $c['auth']['header_name'] ?? '' ),
			'auth_username'       => (string) ( $c['auth']['username'] ?? '' ),
			'auth_secret'         => Secrets::get( "conn.{$id}.auth" ),
			'headers'             => $headers,
			'sign_enabled'        => ! empty( $c['signing']['enabled'] ),
			'sign_secret'         => Secrets::get( "conn.{$id}.sign" ),
			'timeout'             => (int) $c['timeout'],
			'allow_http'          => ! empty( $c['allow_http'] ),
			'allow_private_hosts' => ! empty( $c['allow_private_hosts'] ),
			'enabled'             => ! empty( $c['enabled'] ),
		);
	}

	/**
	 * Creates or updates a connection.
	 *
	 * @param array $raw     Raw input (see class description).
	 * @param int   $user_id User saving it (recorded when the data-sharing notice is acknowledged).
	 * @return array|\WP_Error The admin-safe view, or a validation error.
	 */
	public static function save( array $raw, int $user_id ) {
		$all      = self::all();
		$id       = isset( $raw['id'] ) ? (string) $raw['id'] : '';
		$existing = '' !== $id ? self::find( $id ) : null;
		if ( '' !== $id && null === $existing ) {
			return new \WP_Error( 'krivo_chatflow_not_found', __( 'Connection not found.', 'krivo-chatflow' ), array( 'status' => 404 ) );
		}
		if ( null === $existing && count( $all ) >= self::MAX_CONNECTIONS ) {
			return new \WP_Error( 'krivo_chatflow_limit', __( 'Too many connections.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}
		if ( null === $existing ) {
			do {
				$id = Text::random_id( 'con_', 8 );
			} while ( null !== self::find( $id ) );
		}

		$name = Sanitizer::str( $raw['name'] ?? ( $existing['name'] ?? '' ), 80 );
		if ( '' === $name ) {
			return new \WP_Error( 'krivo_chatflow_invalid', __( 'Give the connection a name.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}

		$allow_http    = Sanitizer::bool( $raw['allow_http'] ?? ( $existing['allow_http'] ?? false ) );
		$allow_private = Sanitizer::bool( $raw['allow_private_hosts'] ?? ( $existing['allow_private_hosts'] ?? false ) );

		// URL: a new value replaces it; omitting keeps the stored one. It is a secret.
		$url_host = (string) ( $existing['url_host'] ?? '' );
		if ( isset( $raw['url'] ) && is_string( $raw['url'] ) && '' !== trim( $raw['url'] ) ) {
			$url   = trim( $raw['url'] );
			$check = self::validate_url( $url, $allow_http );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			Secrets::set( "conn.{$id}.url", $url );
			$url_host = $check;
		}
		if ( null === $existing && ! Secrets::has( "conn.{$id}.url" ) ) {
			return new \WP_Error( 'krivo_chatflow_invalid', __( 'Enter the webhook URL.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}

		// Authentication.
		$auth_raw = is_array( $raw['auth'] ?? null ) ? $raw['auth'] : array();
		$old_auth = (array) ( $existing['auth'] ?? array() );
		$mode     = Sanitizer::enum( $auth_raw['mode'] ?? ( $old_auth['mode'] ?? 'none' ), array( 'none', 'bearer', 'basic', 'header' ), 'none' );
		$header   = isset( $auth_raw['header_name'] ) ? (string) $auth_raw['header_name'] : (string) ( $old_auth['header_name'] ?? '' );
		if ( 'header' === $mode && ! self::valid_header_name( $header ) ) {
			return new \WP_Error( 'krivo_chatflow_invalid', __( 'The header name is not valid.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}
		$auth = array(
			'mode'        => $mode,
			'header_name' => 'header' === $mode ? $header : '',
			'username'    => 'basic' === $mode ? Sanitizer::str( $auth_raw['username'] ?? ( $old_auth['username'] ?? '' ), 190 ) : '',
		);
		if ( 'none' === $mode || ! empty( $auth_raw['clear'] ) ) {
			Secrets::delete( "conn.{$id}.auth" );
		} elseif ( isset( $auth_raw['secret'] ) && is_string( $auth_raw['secret'] ) && '' !== $auth_raw['secret'] ) {
			Secrets::set( "conn.{$id}.auth", self::strip_newlines( $auth_raw['secret'] ) );
		}

		// Extra headers: replaced as a whole; "keep" retains the stored secret value of the same name.
		$headers     = array();
		$old_headers = (array) ( $existing['headers'] ?? array() );
		$old_secrets = array();
		foreach ( $old_headers as $i => $old ) {
			if ( ! empty( $old['secret'] ) ) {
				$old_secrets[ strtolower( (string) $old['name'] ) ] = Secrets::get( "conn.{$id}.hdr.{$i}" );
			}
		}
		Secrets::delete_prefix( "conn.{$id}.hdr." );
		foreach ( is_array( $raw['headers'] ?? null ) ? array_values( $raw['headers'] ) : array() as $item ) {
			if ( count( $headers ) >= self::MAX_HEADERS || ! is_array( $item ) ) {
				continue;
			}
			$hname = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
			if ( '' === $hname ) {
				continue;
			}
			if ( ! self::valid_header_name( $hname ) ) {
				return new \WP_Error( 'krivo_chatflow_invalid', __( 'A header name is not valid.', 'krivo-chatflow' ), array( 'status' => 400 ) );
			}
			$secret = ! empty( $item['secret'] );
			$index  = count( $headers );
			$value  = isset( $item['value'] ) && is_string( $item['value'] ) ? self::strip_newlines( $item['value'] ) : '';
			if ( $secret ) {
				if ( '' === $value && ! empty( $item['keep'] ) && isset( $old_secrets[ strtolower( $hname ) ] ) ) {
					$value = $old_secrets[ strtolower( $hname ) ];
				}
				if ( '' !== $value ) {
					Secrets::set( "conn.{$id}.hdr.{$index}", $value );
				}
				$headers[] = array(
					'name'   => $hname,
					'secret' => true,
				);
			} else {
				$headers[] = array(
					'name'   => $hname,
					'secret' => false,
					'value'  => Text::limit( $value, 500 ),
				);
			}
		}

		// Signing.
		$sign_raw = is_array( $raw['signing'] ?? null ) ? $raw['signing'] : array();
		$signing  = array( 'enabled' => Sanitizer::bool( $sign_raw['enabled'] ?? ( $existing['signing']['enabled'] ?? false ) ) );
		if ( ! empty( $sign_raw['clear'] ) || ! $signing['enabled'] ) {
			Secrets::delete( "conn.{$id}.sign" );
			$signing['enabled'] = $signing['enabled'] && empty( $sign_raw['clear'] );
		} elseif ( isset( $sign_raw['secret'] ) && is_string( $sign_raw['secret'] ) && '' !== $sign_raw['secret'] ) {
			Secrets::set( "conn.{$id}.sign", self::strip_newlines( $sign_raw['secret'] ) );
		}
		if ( $signing['enabled'] && ! Secrets::has( "conn.{$id}.sign" ) ) {
			return new \WP_Error( 'krivo_chatflow_invalid', __( 'Enter a signing secret, or turn signing off.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}

		// Enabling requires the administrator to acknowledge what data will be sent.
		$enabled      = Sanitizer::bool( $raw['enabled'] ?? ( $existing['enabled'] ?? false ) );
		$acknowledged = (string) ( $existing['acknowledged_at'] ?? '' );
		$ack_by       = (int) ( $existing['acknowledged_by'] ?? 0 );
		if ( ! empty( $raw['acknowledged'] ) && '' === $acknowledged ) {
			$acknowledged = Clock::now();
			$ack_by       = $user_id;
		}
		if ( $enabled && '' === $acknowledged ) {
			return new \WP_Error( 'krivo_chatflow_ack', __( 'Confirm that you understand which data is sent to this address before turning the connection on.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}

		$record = array(
			'id'                  => $id,
			'name'                => $name,
			'preset'              => Sanitizer::enum( $raw['preset'] ?? ( $existing['preset'] ?? '' ), self::PRESETS, 'custom' ),
			'method'              => Sanitizer::enum( strtoupper( (string) ( $raw['method'] ?? ( $existing['method'] ?? 'POST' ) ) ), array( 'POST', 'PUT', 'PATCH' ), 'POST' ),
			'url_host'            => $url_host,
			'auth'                => $auth,
			'headers'             => $headers,
			'signing'             => $signing,
			'timeout'             => Sanitizer::int( $raw['timeout'] ?? ( $existing['timeout'] ?? 10 ), 1, 20, 10 ),
			'allow_http'          => $allow_http,
			'allow_private_hosts' => $allow_private,
			'enabled'             => $enabled,
			'acknowledged_at'     => $acknowledged,
			'acknowledged_by'     => $ack_by,
		);

		$updated = false;
		foreach ( $all as $i => $c ) {
			if ( ( $c['id'] ?? '' ) === $id ) {
				$all[ $i ] = $record;
				$updated   = true;
			}
		}
		if ( ! $updated ) {
			$all[] = $record;
		}
		self::store( $all );
		return self::public_view( $record );
	}

	/**
	 * Deletes a connection and its secrets.
	 *
	 * @param string $id Connection id.
	 * @return bool
	 */
	public static function delete( string $id ): bool {
		$all = self::all();
		$new = array_values(
			array_filter(
				$all,
				static function ( $c ) use ( $id ) {
					return ( $c['id'] ?? '' ) !== $id;
				}
			)
		);
		if ( count( $new ) === count( $all ) ) {
			return false;
		}
		Secrets::delete_prefix( "conn.{$id}." );
		self::store( $new );
		return true;
	}

	/**
	 * Validates a URL typed by the administrator and returns its host for display.
	 *
	 * @param string $url        URL.
	 * @param bool   $allow_http Whether plain HTTP is allowed.
	 * @return string|\WP_Error Host on success.
	 */
	public static function validate_url( string $url, bool $allow_http ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || strlen( $url ) > 2048 || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'krivo_chatflow_invalid', __( 'Enter a complete web address, starting with https://.', 'krivo-chatflow' ), array( 'status' => 400 ) );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $allow_http ) ) {
			return new \WP_Error( 'krivo_chatflow_invalid', SafeClient::describe( 'insecure' ), array( 'status' => 400 ) );
		}
		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Header name validity (RFC 7230 token, restricted) excluding headers that must not be overridden.
	 *
	 * @param string $name Header name.
	 * @return bool
	 */
	private static function valid_header_name( string $name ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9-]{1,40}$/', $name ) && ! in_array( strtolower( $name ), self::FORBIDDEN_HEADERS, true ) && 0 !== stripos( $name, 'proxy-' );
	}

	/**
	 * Removes CR, LF and other control characters (header injection).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function strip_newlines( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $value ) );
	}

	/**
	 * Persists the list.
	 *
	 * @param array[] $connections Connections.
	 * @return void
	 */
	private static function store( array $connections ): void {
		update_option( self::OPTION, array( 'connections' => array_values( $connections ) ), false );
	}

	/**
	 * Redacts a string using every known secret of a connection (used on error messages).
	 *
	 * @param string $text Text.
	 * @param array  $runtime Runtime connection.
	 * @return string
	 */
	public static function redact( string $text, array $runtime ): string {
		return Redactor::clean( $text, array( $runtime['url'] ?? '', $runtime['auth_secret'] ?? '', $runtime['sign_secret'] ?? '' ) );
	}
}
