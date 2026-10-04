<?php
/**
 * Mail sender.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Mail;

use Krivo\ChatFlow\Infrastructure\Logging\Redactor;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the plugin's e-mails through wp_mail().
 *
 * The ChatFlow's own SMTP server is applied with hooks that exist ONLY while one ChatFlow message is
 * being sent (added right before wp_mail(), removed in a finally block). The rest of the site's mail,
 * and plugins such as WP Mail SMTP, FluentSMTP or Post SMTP, are never altered. WordPress's own
 * PHPMailer is used: the plugin does not bundle a copy.
 */
final class Mailer {

	/**
	 * Sends one message.
	 *
	 * @param string[] $to      Recipient addresses (already validated).
	 * @param string   $subject Subject (one line).
	 * @param string   $html    HTML body.
	 * @param string   $text    Plain text alternative.
	 * @param array    $options reply_to (address), reply_to_name, debug (bool: capture the SMTP conversation).
	 * @return array{ok:bool,error:string,transport:string,host:string,port:int,encryption:string,debug:string[]}
	 */
	public static function send( array $to, string $subject, string $html, string $text, array $options = array() ): array {
		$config  = EmailSettings::get();
		$custom  = 'custom' === $config['mode'] && '' !== $config['host'];
		$capture = (object) array(
			'error' => '',
			'debug' => array(),
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $options['reply_to'] ) && is_email( $options['reply_to'] ) ) {
			$name = trim( (string) preg_replace( '/["<>,;\r\n]+/', ' ', (string) ( $options['reply_to_name'] ?? '' ) ) );
			$name = Text::limit( Text::single_line( $name ), 80 );
			$headers[] = 'Reply-To: ' . ( '' !== $name ? $name . ' ' : '' ) . '<' . $options['reply_to'] . '>'; // wp_mail() and PHPMailer quote the display name.
		} elseif ( '' !== $config['reply_to'] ) {
			$headers[] = 'Reply-To: <' . $config['reply_to'] . '>';
		}

		$on_init = static function ( $phpmailer ) use ( $config, $custom, $text, $options, $capture ) {
			$phpmailer->AltBody = $text;
			if ( ! $custom ) {
				return;
			}
			$phpmailer->isSMTP();
			$phpmailer->Host        = $config['host'];
			$phpmailer->Port        = (int) $config['port'];
			$phpmailer->SMTPAuth    = (bool) $config['auth'];
			$phpmailer->SMTPSecure  = 'none' === $config['encryption'] ? '' : $config['encryption'];
			$phpmailer->SMTPAutoTLS = 'tls' === $config['encryption'];
			$phpmailer->Timeout     = 15;
			if ( $config['auth'] ) {
				$phpmailer->Username = $config['username'];
				$phpmailer->Password = EmailSettings::password();
			}
			if ( ! empty( $options['debug'] ) ) {
				$phpmailer->SMTPDebug   = 2;
				$phpmailer->Debugoutput = static function ( $line ) use ( $capture ) {
					$capture->debug[] = (string) $line;
				};
			}
		};
		$on_failed = static function ( $error ) use ( $capture ) {
			if ( $error instanceof \WP_Error ) {
				$capture->error = $error->get_error_message();
			}
		};
		$from_email = static function ( $email ) use ( $config, $custom ) {
			if ( $custom && '' !== $config['from_email'] ) {
				return $config['from_email'];
			}
			// The WordPress default sender is "wordpress@<host>": on a local site (localhost) it is not a
			// valid address and PHPMailer refuses to send. Only then, use the administrator's address.
			return \PHPMailer\PHPMailer\PHPMailer::validateAddress( (string) $email ) ? $email : (string) get_option( 'admin_email' );
		};
		$from_name = static function ( $name ) use ( $config, $custom ) {
			return $custom && '' !== $config['from_name'] ? $config['from_name'] : $name;
		};

		add_action( 'phpmailer_init', $on_init, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $on_failed );
		add_filter( 'wp_mail_from', $from_email, PHP_INT_MAX );
		if ( $custom ) {
			add_filter( 'wp_mail_from_name', $from_name, PHP_INT_MAX );
		}

		try {
			$ok = (bool) wp_mail( $to, Text::single_line( $subject ), $html, $headers );
		} finally {
			remove_action( 'phpmailer_init', $on_init, PHP_INT_MAX );
			remove_action( 'wp_mail_failed', $on_failed );
			remove_filter( 'wp_mail_from', $from_email, PHP_INT_MAX );
			if ( $custom ) {
				remove_filter( 'wp_mail_from_name', $from_name, PHP_INT_MAX );
			}
		}

		global $phpmailer;
		$transport = self::transport( $custom, $config, $phpmailer );

		return array(
			'ok'         => $ok,
			'error'      => '' === $capture->error ? '' : Redactor::clean( $capture->error, array( EmailSettings::password() ) ),
			'transport'  => $transport,
			'host'       => $custom ? $config['host'] : '',
			'port'       => $custom ? (int) $config['port'] : 0,
			'encryption' => $custom ? (string) $config['encryption'] : '',
			'debug'      => array_map(
				static function ( $line ) {
					return Redactor::clean( (string) $line, array( EmailSettings::password() ), 300 );
				},
				$capture->debug
			),
		);
	}

	/**
	 * Which transport actually carried the message.
	 *
	 * @param bool  $custom    Whether the ChatFlow SMTP was requested.
	 * @param array $config    E-mail settings.
	 * @param mixed $phpmailer The PHPMailer instance WordPress used.
	 * @return string smtp-chatflow, overridden (another plugin took over), wp-smtp (site SMTP), wp-default or unknown.
	 */
	private static function transport( bool $custom, array $config, $phpmailer ): string {
		if ( ! is_object( $phpmailer ) || ! property_exists( $phpmailer, 'Mailer' ) ) {
			return 'unknown';
		}
		$is_smtp = 'smtp' === $phpmailer->Mailer;
		if ( $custom ) {
			return $is_smtp && isset( $phpmailer->Host ) && $phpmailer->Host === $config['host'] ? 'smtp-chatflow' : 'overridden';
		}
		return $is_smtp ? 'wp-smtp' : 'wp-default';
	}
}
