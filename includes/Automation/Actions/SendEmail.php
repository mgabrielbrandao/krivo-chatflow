<?php
/**
 * Action: send e-mail.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation\Actions;

use Krivo\ChatFlow\Automation\Registry;
use Krivo\ChatFlow\Automation\Result;
use Krivo\ChatFlow\Automation\RunContext;
use Krivo\ChatFlow\Core\Flow\Sanitizer;
use Krivo\ChatFlow\Core\Routing\RoutingRepository;
use Krivo\ChatFlow\Core\Submission\AntiSpam\RateLimiter;
use Krivo\ChatFlow\Core\Variables\Renderer;
use Krivo\ChatFlow\Infrastructure\Logging\Redactor;
use Krivo\ChatFlow\Mail\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a notification (to the team) or a confirmation (to the visitor, "autoresponder").
 *
 * Always asynchronous: a slow or broken SMTP server never delays or breaks the visitor's submission.
 * Recipient tokens: site_admin, destination (the routed destination's addresses), custom:<address>,
 * lead (the visitor). "lead" is exclusive so the team's addresses are never exposed to the visitor.
 */
final class SendEmail implements ActionInterface {

	private const MAX_CUSTOM = 10;

	/**
	 * Type slug.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'send_email';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Send e-mail', 'krivo-chatflow' );
	}

	/**
	 * Mode.
	 *
	 * @return string
	 */
	public function mode(): string {
		return 'async';
	}

	/**
	 * Triggers.
	 *
	 * @return string[]
	 */
	public function triggers(): array {
		return array_keys( Registry::triggers() );
	}

	/**
	 * Defaults: the internal notification.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'to'       => array( 'site_admin' ),
			'subject'  => __( 'New lead — {name}', 'krivo-chatflow' ),
			'body'     => __( "New contact received through Krivo ChatFlow.\n\n{all_fields}\n\nPage: {page_url}\nSource: {utm_source}", 'krivo-chatflow' ),
			'reply_to' => 'lead',
		);
	}

	/**
	 * Defaults for the visitor confirmation (autoresponder). Uses only {name}: free text typed by a
	 * visitor is never echoed back to an address they typed.
	 *
	 * @return array
	 */
	public static function autoresponder_defaults(): array {
		return array(
			'to'       => array( 'lead' ),
			'subject'  => __( 'We received your message', 'krivo-chatflow' ),
			'body'     => __( "Hello {name},\n\nWe received your request and our team will get back to you soon.", 'krivo-chatflow' ),
			'reply_to' => 'none',
		);
	}

	/**
	 * Sanitises the configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array
	 */
	public function sanitize( array $config ): array {
		$defaults = $this->defaults();
		$tokens   = array();
		$custom   = 0;
		foreach ( is_array( $config['to'] ?? null ) ? $config['to'] : array() as $token ) {
			$token = is_string( $token ) ? trim( $token ) : '';
			if ( in_array( $token, array( 'site_admin', 'destination', 'lead' ), true ) ) {
				$tokens[] = $token;
			} elseif ( 0 === strpos( $token, 'custom:' ) && $custom < self::MAX_CUSTOM ) {
				$email = Sanitizer::email( substr( $token, 7 ) );
				if ( '' !== $email ) {
					$tokens[] = 'custom:' . $email;
					++$custom;
				}
			}
		}
		$tokens = array_values( array_unique( $tokens ) );
		if ( in_array( 'lead', $tokens, true ) ) {
			$tokens = array( 'lead' ); // Exclusive.
		}
		if ( array() === $tokens ) {
			$tokens = $defaults['to'];
		}

		$subject = Sanitizer::str( $config['subject'] ?? '', 200 );
		$body    = Sanitizer::textarea( $config['body'] ?? '', 3000 );
		return array(
			'to'       => $tokens,
			'subject'  => '' !== $subject ? $subject : $defaults['subject'],
			'body'     => '' !== trim( $body ) ? $body : $defaults['body'],
			'reply_to' => Sanitizer::enum( $config['reply_to'] ?? '', array( 'lead', 'none' ), 'lead' ),
		);
	}

	/**
	 * Sends the e-mail.
	 *
	 * @param RunContext $context Context.
	 * @param array      $config  Configuration.
	 * @return Result
	 */
	public function run( RunContext $context, array $config ): Result {
		$lead = $context->lead;
		if ( 'spam' === ( $lead['status'] ?? '' ) ) {
			return Result::skipped( 'spam', __( 'The lead was held as possible spam.', 'krivo-chatflow' ) );
		}

		$tokens         = (array) ( $config['to'] ?? array() );
		$is_autoresponder = array( 'lead' ) === $tokens;
		$recipients     = $this->recipients( $tokens, $lead );
		if ( array() === $recipients ) {
			return Result::skipped( 'no_recipient', __( 'There is no valid recipient for this e-mail.', 'krivo-chatflow' ) );
		}

		if ( $is_autoresponder ) {
			// One confirmation per address per hour: nobody can use the form to flood a third party.
			if ( ! RateLimiter::hit( 'autoresponder:' . strtolower( $recipients[0] ), 1, HOUR_IN_SECONDS ) ) {
				return Result::skipped( 'rate_limited', __( 'A confirmation was already sent to this address recently.', 'krivo-chatflow' ) );
			}
		}

		$vars    = $context->vars();
		$body    = (string) ( $config['body'] ?? '' );
		$subject = Renderer::render( (string) ( $config['subject'] ?? '' ), $vars, 'subject' );
		$text    = Renderer::render( $body, $vars, 'text' );
		// The template text itself is escaped as HTML first; then values are inserted escaped.
		$inner   = Renderer::render( nl2br( esc_html( $body ), false ), $vars, 'html' );
		$html    = self::wrap( $inner );

		$args = array(
			'to'      => $recipients,
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
			'options' => array(),
		);
		if ( 'lead' === ( $config['reply_to'] ?? 'lead' ) && ! $is_autoresponder && is_email( (string) ( $lead['email'] ?? '' ) ) ) {
			$args['options']['reply_to']      = $lead['email'];
			$args['options']['reply_to_name'] = (string) ( $lead['name'] ?? '' );
		}

		/**
		 * Filters the e-mail before it is sent.
		 *
		 * @param array $args    { to[], subject, html, text, options }.
		 * @param array $lead    Lead.
		 * @param array $config  Action configuration.
		 */
		$args = apply_filters( 'krivo_chatflow_email_args', $args, $lead, $config );

		$sent = Mailer::send( (array) $args['to'], (string) $args['subject'], (string) $args['html'], (string) $args['text'], (array) $args['options'] );
		if ( $sent['ok'] ) {
			return Result::success();
		}
		return Result::failure(
			'mail_failed',
			'' !== $sent['error'] ? $sent['error'] : Redactor::clean( __( 'wp_mail() reported a failure.', 'krivo-chatflow' ) ),
			true
		);
	}

	/**
	 * Resolves the recipient tokens to valid addresses.
	 *
	 * @param string[] $tokens Recipient tokens.
	 * @param array    $lead   Lead.
	 * @return string[]
	 */
	private function recipients( array $tokens, array $lead ): array {
		$list = array();
		foreach ( $tokens as $token ) {
			if ( 'site_admin' === $token ) {
				$list[] = (string) get_option( 'admin_email' );
			} elseif ( 'destination' === $token ) {
				$destination = RoutingRepository::destination( (string) ( $lead['destination_key'] ?? '' ) );
				foreach ( (array) ( $destination['emails'] ?? array() ) as $email ) {
					$list[] = (string) $email;
				}
			} elseif ( 'lead' === $token ) {
				$list[] = (string) ( $lead['email'] ?? '' );
			} elseif ( is_string( $token ) && 0 === strpos( $token, 'custom:' ) ) {
				$list[] = substr( $token, 7 );
			}
		}
		$clean = array();
		foreach ( $list as $email ) {
			$email = Sanitizer::email( $email );
			if ( '' !== $email && ! in_array( $email, $clean, true ) ) {
				$clean[] = $email;
			}
		}
		return $clean;
	}

	/**
	 * Wraps the body in a minimal, client-safe HTML layout (inline styles only).
	 *
	 * @param string $inner Escaped HTML.
	 * @return string
	 */
	private static function wrap( string $inner ): string {
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#F7F4FC;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#171519">'
			. '<div style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E7E1F2;border-radius:12px;padding:24px;font-size:15px;line-height:1.55">'
			. $inner
			. '</div></body></html>';
	}
}
