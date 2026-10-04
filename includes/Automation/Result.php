<?php
/**
 * Action result.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Automation;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of running an action. Never carries secrets, response bodies or full URLs.
 */
final class Result {

	public const SUCCESS = 'success';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';

	/**
	 * Status: success, failed or skipped.
	 *
	 * @var string
	 */
	public $status = self::SUCCESS;

	/**
	 * Whether a failure is worth retrying (timeouts, 5xx) or permanent (bad URL, 4xx).
	 *
	 * @var bool
	 */
	public $retry = false;

	/**
	 * HTTP status when the action made an HTTP request.
	 *
	 * @var int|null
	 */
	public $http_status = null;

	/**
	 * Short machine category: timeout, dns, ssl, http_4xx, http_5xx, blocked...
	 *
	 * @var string
	 */
	public $error_code = '';

	/**
	 * Short, redacted, human message.
	 *
	 * @var string
	 */
	public $error_message = '';

	/**
	 * Elapsed time in milliseconds.
	 *
	 * @var int|null
	 */
	public $duration_ms = null;

	/**
	 * Instruction for the visitor's browser (sync actions only), for example {type:"open_whatsapp",url:"..."}.
	 *
	 * @var array|null
	 */
	public $directive = null;

	/**
	 * Successful result.
	 *
	 * @param array $extra Optional properties (directive, http_status, duration_ms).
	 * @return self
	 */
	public static function success( array $extra = array() ): self {
		$result = new self();
		foreach ( $extra as $name => $value ) {
			if ( property_exists( $result, $name ) ) {
				$result->$name = $value;
			}
		}
		return $result;
	}

	/**
	 * Failed result.
	 *
	 * @param string $code    Error category.
	 * @param string $message Redacted message.
	 * @param bool   $retry   Whether to retry.
	 * @param array  $extra   Optional properties (http_status, duration_ms).
	 * @return self
	 */
	public static function failure( string $code, string $message, bool $retry = false, array $extra = array() ): self {
		$result                = self::success( $extra );
		$result->status        = self::FAILED;
		$result->retry         = $retry;
		$result->error_code    = $code;
		$result->error_message = $message;
		return $result;
	}

	/**
	 * Skipped result (nothing to do: disabled, no recipient...).
	 *
	 * @param string $code    Reason category.
	 * @param string $message Message.
	 * @return self
	 */
	public static function skipped( string $code, string $message = '' ): self {
		$result                = new self();
		$result->status        = self::SKIPPED;
		$result->error_code    = $code;
		$result->error_message = $message;
		return $result;
	}
}
