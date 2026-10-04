<?php
/**
 * E-mail diagnostics.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Mail;

use Krivo\ChatFlow\Infrastructure\Logging\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * The "send a test e-mail" tool: connected, error and the server's answer, in plain language, with
 * credentials and base64 blobs stripped from the SMTP conversation.
 */
final class Diagnostics {

	/**
	 * Sends a test message and reports what happened.
	 *
	 * @param string $to Recipient.
	 * @return array{ok:bool,status:string,message:string,transport:string,host:string,port:int,encryption:string,server_response:string[],duration_ms:int}
	 */
	public static function test( string $to ): array {
		$start = microtime( true );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Krivo ChatFlow test e-mail from %s', 'krivo-chatflow' ),
			$site
		);
		$text = __( 'This is a test message sent by Krivo ChatFlow. If you can read it, e-mail delivery is working.', 'krivo-chatflow' );
		$html = '<p>' . esc_html( $text ) . '</p>';

		$result = Mailer::send( array( $to ), $subject, $html, $text, array( 'debug' => true ) );

		$lines = array_values(
			array_filter(
				array_map(
					static function ( $line ) {
						return trim( (string) $line );
					},
					$result['debug']
				),
				static function ( $line ) {
					return '' !== $line && ! preg_match( '/^(CLIENT -> SERVER: (AUTH|[A-Za-z0-9+\/=]{16,}))/i', $line );
				}
			)
		);
		$lines = array_slice( $lines, 0, 50 );

		if ( $result['ok'] ) {
			$message = 'smtp-chatflow' === $result['transport']
				? __( 'Connected. The test message was accepted by your SMTP server.', 'krivo-chatflow' )
				: __( 'The WordPress mailer accepted the test message.', 'krivo-chatflow' );
			if ( 'overridden' === $result['transport'] ) {
				$message = __( 'Sent, but another plugin replaced the ChatFlow SMTP settings, so the message did not use them.', 'krivo-chatflow' );
			}
			$status = 'ok';
		} else {
			$status  = 'error';
			$message = self::friendly_error( $result['error'] );
		}

		return array(
			'ok'              => $result['ok'],
			'status'          => $status,
			'message'         => $message,
			'transport'       => $result['transport'],
			'host'            => $result['host'],
			'port'            => $result['port'],
			'encryption'      => $result['encryption'],
			'server_response' => array_map(
				static function ( $line ) {
					return Redactor::clean( $line, array(), 300 );
				},
				$lines
			),
			'duration_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * Turns a technical error into a sentence a site owner can act on.
	 *
	 * @param string $error Redacted technical error.
	 * @return string
	 */
	private static function friendly_error( string $error ): string {
		$lower = strtolower( $error );
		if ( str_contains( $lower, 'could not authenticate' ) || str_contains( $lower, 'authentication' ) || str_contains( $lower, 'username' ) ) {
			return __( 'The server rejected the user name or password.', 'krivo-chatflow' );
		}
		if ( str_contains( $lower, 'connect' ) || str_contains( $lower, 'connection' ) || str_contains( $lower, 'timed out' ) ) {
			return __( 'Could not connect to the SMTP server. Check the host, the port and the encryption type.', 'krivo-chatflow' );
		}
		if ( str_contains( $lower, 'certificate' ) || str_contains( $lower, 'ssl' ) || str_contains( $lower, 'tls' ) ) {
			return __( 'The secure connection failed. Check the encryption type and that the server certificate is valid.', 'krivo-chatflow' );
		}
		if ( str_contains( $lower, 'from' ) && str_contains( $lower, 'address' ) ) {
			return __( 'The server did not accept the sender address. Use an address from your own domain.', 'krivo-chatflow' );
		}
		return __( 'The message could not be sent.', 'krivo-chatflow' );
	}
}
