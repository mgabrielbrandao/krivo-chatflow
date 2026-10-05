<?php
/**
 * Webhook executor.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Webhook;

use Krivo\ChatFlow\Infrastructure\Http\SafeClient;
use Krivo\ChatFlow\Infrastructure\Support\Clock;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one payload to one connection through the WordPress HTTP API and reduces the answer to
 * a status, a category and a plain-language summary. Used both by the queue worker and by the
 * "Test webhook" button, so a test exercises exactly what production does.
 */
final class Executor {

	/**
	 * Sends a payload.
	 *
	 * @param array  $connection  Runtime connection (decrypted, from ConnectionRepository::runtime()).
	 * @param array  $payload     Payload.
	 * @param string $event       Event name.
	 * @param string $delivery_id Delivery id (the same on every retry of the same run).
	 * @return array{ok:bool,status:?int,category:string,retry:bool,retry_after:int,duration_ms:int,summary:string}
	 */
	public static function send( array $connection, array $payload, string $event, string $delivery_id ): array {
		if ( '' === (string) $connection['url'] ) {
			$result            = self::result( false, null, 'blocked', false, 0, 0 );
			$result['summary'] = __( 'The webhook address is missing or can no longer be read. Enter it again.', 'krivo-chatflow' );
			return $result;
		}

		$body    = Json::encode( $payload );
		$headers = array(
			'Content-Type'             => 'application/json; charset=utf-8',
			'Accept'                   => 'application/json',
			'X-KrivoChatFlow-Event'    => $event,
			'X-KrivoChatFlow-Delivery' => $delivery_id,
		);

		switch ( $connection['auth_mode'] ) {
			case 'bearer':
				$headers['Authorization'] = 'Bearer ' . $connection['auth_secret'];
				break;
			case 'basic':
				$headers['Authorization'] = 'Basic ' . base64_encode( $connection['auth_username'] . ':' . $connection['auth_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication encoding.
				break;
			case 'header':
				if ( '' !== $connection['auth_header_name'] && '' !== $connection['auth_secret'] ) {
					$headers[ $connection['auth_header_name'] ] = $connection['auth_secret'];
				}
				break;
		}
		foreach ( $connection['headers'] as $name => $value ) {
			$headers[ $name ] = $value;
		}
		if ( $connection['sign_enabled'] && '' !== $connection['sign_secret'] ) {
			$headers['X-KrivoChatFlow-Signature'] = Signer::sign( $connection['sign_secret'], $body, Clock::time() );
		}

		/**
		 * Filters the HTTP request arguments right before the webhook is sent.
		 *
		 * @param array  $args          { method, headers, body, timeout }.
		 * @param string $connection_id Connection id.
		 * @param string $event         Event name.
		 */
		$args = apply_filters(
			'krivo_chatflow_webhook_request_args',
			array(
				'method'  => $connection['method'],
				'headers' => $headers,
				'body'    => $body,
				'timeout' => $connection['timeout'],
			),
			$connection['id'],
			$event
		);

		/**
		 * Fires before a webhook is sent. The summary never contains secrets or the full URL.
		 *
		 * @param array $summary { connection, host, method, event, delivery_id }.
		 */
		do_action(
			'krivo_chatflow_before_webhook',
			array(
				'connection'  => $connection['id'],
				'host'        => (string) wp_parse_url( $connection['url'], PHP_URL_HOST ),
				'method'      => $args['method'],
				'event'       => $event,
				'delivery_id' => $delivery_id,
			)
		);

		$response = SafeClient::request(
			(string) $args['method'],
			$connection['url'],
			(string) $args['body'],
			(array) $args['headers'],
			array(
				'timeout'       => (int) $args['timeout'],
				'allow_http'    => $connection['allow_http'],
				'allow_private' => $connection['allow_private_hosts'],
			)
		);

		$result            = self::result( $response['ok'], $response['status'], $response['category'], $response['retry'], $response['retry_after'], $response['duration_ms'] );
		$result['summary'] = SafeClient::describe( $result['category'], $result['status'] );

		/**
		 * Fires after a webhook was sent.
		 *
		 * @param array $result { ok, status, category, retry, retry_after, duration_ms, summary }.
		 * @param array $summary { connection, event, delivery_id }.
		 */
		do_action(
			'krivo_chatflow_after_webhook',
			$result,
			array(
				'connection'  => $connection['id'],
				'event'       => $event,
				'delivery_id' => $delivery_id,
			)
		);

		return $result;
	}

	/**
	 * Builds a normalised result.
	 *
	 * @param bool     $ok          Success.
	 * @param int|null $status      HTTP status.
	 * @param string   $category    Category.
	 * @param bool     $retry       Whether a retry makes sense.
	 * @param int      $retry_after Seconds requested by the server.
	 * @param int      $duration_ms Duration.
	 * @return array
	 */
	private static function result( bool $ok, ?int $status, string $category, bool $retry, int $retry_after, int $duration_ms ): array {
		return array(
			'ok'          => $ok,
			'status'      => $status,
			'category'    => $category,
			'retry'       => $retry,
			'retry_after' => $retry_after,
			'duration_ms' => $duration_ms,
			'summary'     => '',
		);
	}
}
