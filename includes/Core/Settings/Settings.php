<?php
/**
 * Plugin settings.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Settings;

use Krivo\ChatFlow\Infrastructure\Support\Arr;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin's general settings: the schema drives defaults,
 * sanitisation (register_setting), REST validation and the settings screen.
 */
final class Settings {

	public const OPTION = 'krivo_chatflow_settings';
	public const GROUP  = 'krivo_chatflow_settings_group';

	/**
	 * Registers the option with the Settings API (so sanitisation also runs on direct update_option calls).
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'description'       => 'Krivo ChatFlow general settings.',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Schema of every setting. Keys use dot notation (group.key).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema(): array {
		return array(
			'branding.signature_enabled'  => array(
				'type'    => 'bool',
				'default' => false,
			),
			'branding.signature_link'     => array(
				'type'    => 'bool',
				'default' => false,
			),
			'branding.authorized_by'      => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'max'     => PHP_INT_MAX,
			),
			'branding.authorized_at'      => array(
				'type'    => 'string',
				'default' => '',
				'max'     => 30,
			),
			'privacy.retention_leads_days' => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'max'     => 3650,
			),
			'privacy.retention_logs_days' => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
			),
			'privacy.delete_on_uninstall' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'tracking.capture_utm'        => array(
				'type'    => 'bool',
				'default' => true,
			),
			'tracking.capture_referrer'   => array(
				'type'    => 'bool',
				'default' => true,
			),
			'events.datalayer'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'analytics.count_views'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'antispam.rate_limit_max'     => array(
				'type'    => 'int',
				'default' => 8,
				'min'     => 1,
				'max'     => 1000,
			),
			'antispam.rate_limit_window'  => array(
				'type'    => 'int',
				'default' => 900,
				'min'     => 60,
				'max'     => 86400,
			),
			'antispam.min_seconds'        => array(
				'type'    => 'int',
				'default' => 3,
				'min'     => 0,
				'max'     => 60,
			),
			'antispam.breaker_per_minute' => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 1,
				'max'     => 10000,
			),
			'antispam.proxy_header'       => array(
				'type'    => 'enum',
				'default' => 'none',
				'values'  => array( 'none', 'cloudflare', 'x-forwarded-for' ),
			),
			'general.default_dial_code'   => array(
				'type'    => 'digits',
				'default' => null, // Locale dependent, resolved in defaults().
				'max'     => 4,
			),
		);
	}

	/**
	 * Default values as a nested array.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::schema() as $path => $rule ) {
			$value = $rule['default'];
			if ( 'general.default_dial_code' === $path ) {
				$value = 'pt_BR' === get_locale() ? '55' : '';
			}
			Arr::set( $defaults, $path, $value );
		}
		return $defaults;
	}

	/**
	 * Current settings merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$result = self::defaults();
		foreach ( self::schema() as $path => $rule ) {
			$value = Arr::get( $stored, $path, '__missing__' );
			if ( '__missing__' !== $value ) {
				Arr::set( $result, $path, self::coerce( $value, $rule, Arr::get( $result, $path ) ) );
			}
		}
		return $result;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $path     Dot path.
	 * @param mixed  $fallback Value when unknown.
	 * @return mixed
	 */
	public static function get( string $path, $fallback = null ) {
		return Arr::get( self::all(), $path, $fallback );
	}

	/**
	 * Sanitises a (partial or full) settings array against the schema and applies the
	 * consistency rules for the optional "Powered by" signature. Idempotent.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();
		$clean   = $current;

		foreach ( self::schema() as $path => $rule ) {
			$value = Arr::get( $input, $path, '__missing__' );
			if ( '__missing__' === $value ) {
				continue;
			}
			Arr::set( $clean, $path, self::coerce( $value, $rule, Arr::get( $current, $path ) ) );
		}

		// The signature link is a second, separate consent: it needs the signature itself.
		$was_enabled = (bool) Arr::get( $current, 'branding.signature_enabled' );
		$is_enabled  = (bool) Arr::get( $clean, 'branding.signature_enabled' );

		if ( ! $is_enabled ) {
			Arr::set( $clean, 'branding.signature_link', false );
			Arr::set( $clean, 'branding.authorized_by', 0 );
			Arr::set( $clean, 'branding.authorized_at', '' );
		} elseif ( ! $was_enabled && '' === (string) Arr::get( $input, 'branding.authorized_at', '' ) ) {
			// Record who authorised the credit and when (proof of consent).
			Arr::set( $clean, 'branding.authorized_by', get_current_user_id() );
			Arr::set( $clean, 'branding.authorized_at', Clock::now() );
		}

		return $clean;
	}

	/**
	 * Applies a partial update from a trusted caller (REST controller). Authorisation fields can
	 * never be set from outside: they are stamped by sanitize().
	 *
	 * @param array $changes Partial nested settings.
	 * @return array<string,mixed> The stored settings.
	 */
	public static function update( array $changes ): array {
		unset( $changes['branding']['authorized_by'], $changes['branding']['authorized_at'] );
		$merged = self::sanitize( array_replace_recursive( self::all(), $changes ) );
		update_option( self::OPTION, $merged, false );
		return self::all();
	}

	/**
	 * Coerces a value to the type described by a schema rule.
	 *
	 * @param mixed $value    Raw value.
	 * @param array $rule     Schema rule.
	 * @param mixed $fallback Value kept when the input is unusable.
	 * @return mixed
	 */
	private static function coerce( $value, array $rule, $fallback ) {
		switch ( $rule['type'] ) {
			case 'bool':
				return is_bool( $value ) ? $value : rest_sanitize_boolean( $value );
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return $fallback;
				}
				return max( (int) $rule['min'], min( (int) $rule['max'], (int) $value ) );
			case 'enum':
				return in_array( $value, $rule['values'], true ) ? $value : $fallback;
			case 'digits':
				return substr( (string) preg_replace( '/\D+/', '', (string) $value ), 0, (int) $rule['max'] );
			case 'string':
			default:
				return substr( sanitize_text_field( (string) $value ), 0, (int) ( $rule['max'] ?? 255 ) );
		}
	}
}
