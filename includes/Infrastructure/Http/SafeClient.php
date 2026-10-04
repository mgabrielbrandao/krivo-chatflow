<?php
/**
 * Safe outbound HTTP client.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Every outbound HTTP request of the plugin goes through here and through the WordPress HTTP API
 * (wp_safe_remote_request): never cURL directly.
 *
 * Defences: HTTPS only unless the administrator explicitly allows HTTP; no redirects are followed;
 * TLS certificates are always verified; loopback, private and metadata addresses and unusual ports
 * are refused (SSRF) unless the administrator explicitly allows private hosts for this connection.
 * Results are reduced to a status, a category and a timing: no response body, no headers and no raw
 * error text ever leave this class.
 */
final class SafeClient {

	/**
	 * Sends a request.
	 *
	 * @param string $method  HTTP method (POST, PUT, PATCH).
	 * @param string $url     Target URL.
	 * @param string $body    Request body.
	 * @param array  $headers Request headers (name => value).
	 * @param array  $options timeout (seconds), allow_http (bool), allow_private (bool).
	 * @return array{ok:bool,status:?int,category:string,retry:bool,retry_after:int,duration_ms:int}
	 */
	public static function request( string $method, string $url, string $body, array $headers, array $options = array() ): array {
		$allow_http    = ! empty( $options['allow_http'] );
		$allow_private = ! empty( $options['allow_private'] );
		$timeout       = max( 1, min( 20, (int) ( $options['timeout'] ?? 10 ) ) );

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 2048 ) {
			return self::failure( 'blocked', 0 );
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $allow_http ) ) {
			return self::failure( 'insecure', 0 );
		}
		if ( ! in_array( strtoupper( $method ), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return self::failure( 'blocked', 0 );
		}

		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		$filters = array();
		if ( $allow_private ) {
			// Applies to this one request only: the filters are removed again below.
			$filters['http_request_host_is_external'] = static function ( $external, $request_host ) use ( $host ) {
				return strtolower( (string) $request_host ) === $host ? true : $external;
			};
			$filters['http_allowed_safe_ports']       = static function ( $ports ) use ( $port ) {
				if ( $port > 0 ) {
					$ports[] = $port;
				}
				return $ports;
			};
			add_filter( 'http_request_host_is_external', $filters['http_request_host_is_external'], 10, 2 );
			add_filter( 'http_allowed_safe_ports', $filters['http_allowed_safe_ports'] );
		}

		$start = microtime( true );
		try {
			$response = wp_safe_remote_request(
				$url,
				array(
					'method'      => strtoupper( $method ),
					'timeout'     => $timeout,
					'redirection' => 0,
					'sslverify'   => true,
					'blocking'    => true,
					'headers'     => $headers,
					'body'        => $body,
				)
			);
		} finally {
			if ( $allow_private ) {
				remove_filter( 'http_request_host_is_external', $filters['http_request_host_is_external'], 10 );
				remove_filter( 'http_allowed_safe_ports', $filters['http_allowed_safe_ports'] );
			}
		}
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$result                = self::failure( self::categorize( $response ), $duration );
			$result['retry']       = in_array( $result['category'], array( 'timeout', 'dns', 'connection', 'network' ), true );
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'          => true,
				'status'      => $status,
				'category'    => 'ok',
				'retry'       => false,
				'retry_after' => 0,
				'duration_ms' => $duration,
			);
		}

		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		$retry_after = max( 0, min( HOUR_IN_SECONDS, $retry_after ) );

		if ( $status >= 300 && $status < 400 ) {
			$category = 'redirect';
			$retry    = false;
		} elseif ( 408 === $status || 425 === $status || 429 === $status ) {
			$category = 'http_4xx';
			$retry    = true;
		} elseif ( $status >= 500 ) {
			$category = 'http_5xx';
			$retry    = true;
		} else {
			$category = 'http_4xx';
			$retry    = false;
		}

		return array(
			'ok'          => false,
			'status'      => $status,
			'category'    => $category,
			'retry'       => $retry,
			'retry_after' => $retry_after,
			'duration_ms' => $duration,
		);
	}

	/**
	 * Plain-language summary of a result, safe to show in the admin.
	 *
	 * @param string   $category Result category.
	 * @param int|null $status   HTTP status.
	 * @return string
	 */
	public static function describe( string $category, ?int $status = null ): string {
		switch ( $category ) {
			case 'ok':
				return __( 'The receiving service accepted the request.', 'krivo-chatflow' );
			case 'timeout':
				return __( 'The receiving service took too long to answer.', 'krivo-chatflow' );
			case 'dns':
				return __( 'The address could not be found. Check the domain name.', 'krivo-chatflow' );
			case 'connection':
				return __( 'The connection was refused. Check that the service is running and the address is correct.', 'krivo-chatflow' );
			case 'ssl':
				return __( 'The secure connection failed. Check that the certificate of the receiving service is valid.', 'krivo-chatflow' );
			case 'blocked':
				return __( 'This address is not allowed. Private addresses and unusual ports need to be enabled explicitly.', 'krivo-chatflow' );
			case 'insecure':
				return __( 'The address does not use HTTPS. Use an https:// address, or allow HTTP in the advanced options.', 'krivo-chatflow' );
			case 'redirect':
				return __( 'The service answered with a redirect. Redirects are not followed; use the final address.', 'krivo-chatflow' );
			case 'http_4xx':
				return 429 === $status || 408 === $status
					? __( 'The service asked to try again later.', 'krivo-chatflow' )
					: __( 'The service rejected the request. Check the address, the credentials and the payload.', 'krivo-chatflow' );
			case 'http_5xx':
				return __( 'The service had an internal error.', 'krivo-chatflow' );
			default:
				return __( 'The request could not be completed.', 'krivo-chatflow' );
		}
	}

	/**
	 * Builds a failure result.
	 *
	 * @param string $category    Category.
	 * @param int    $duration_ms Elapsed milliseconds.
	 * @return array
	 */
	private static function failure( string $category, int $duration_ms ): array {
		return array(
			'ok'          => false,
			'status'      => null,
			'category'    => $category,
			'retry'       => false,
			'retry_after' => 0,
			'duration_ms' => $duration_ms,
		);
	}

	/**
	 * Maps a WP_Error to a short category. The raw message is inspected and then discarded.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function categorize( \WP_Error $error ): string {
		$code    = $error->get_error_code();
		$message = strtolower( $error->get_error_message() );

		if ( 'http_request_not_executed' === $code || str_contains( $message, 'blocked' ) || str_contains( $message, 'a valid url was not provided' ) || str_contains( $message, 'unsafe' ) ) {
			return 'blocked';
		}
		if ( str_contains( $message, 'ssl' ) || str_contains( $message, 'certificate' ) || str_contains( $message, 'tls' ) ) {
			return 'ssl';
		}
		if ( str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) ) {
			return 'timeout';
		}
		if ( str_contains( $message, 'resolve host' ) || str_contains( $message, 'name or service' ) || str_contains( $message, 'getaddrinfo' ) || str_contains( $message, 'no such host' ) ) {
			return 'dns';
		}
		if ( str_contains( $message, 'refused' ) || str_contains( $message, 'failed to connect' ) || str_contains( $message, 'connection' ) ) {
			return 'connection';
		}
		return 'network';
	}
}
