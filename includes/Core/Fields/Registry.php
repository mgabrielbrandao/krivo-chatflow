<?php
/**
 * Field type registry.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of field types. Built-in types are registered lazily; add-ons add theirs on the
 * `krivo_chatflow_register_field_types` action (PHP side; the widget must also know how to render them).
 */
final class Registry {

	/**
	 * Registered types.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $types = null;

	/**
	 * All registered types (slug => definition).
	 *
	 * Definition keys: label, group, collects (stores a value), options (has an options list),
	 * placeholder, length (supports maxlength), range (number|date|time), normalize (callable).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		if ( null === self::$types ) {
			self::$types = self::builtin();
			do_action( 'krivo_chatflow_register_field_types', new self() );
		}
		return self::$types;
	}

	/**
	 * Registers or replaces a field type.
	 *
	 * @param string $slug Type slug.
	 * @param array  $def  Definition (must contain a callable "normalize").
	 * @return void
	 */
	public function register( string $slug, array $def ): void {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || empty( $def['normalize'] ) || ! is_callable( $def['normalize'] ) ) {
			return;
		}
		self::types();
		self::$types[ $slug ] = $def + array(
			'label'       => $slug,
			'group'       => 'other',
			'collects'    => true,
			'options'     => false,
			'placeholder' => false,
			'length'      => false,
			'range'       => '',
		);
	}

	/**
	 * Clears the cache (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$types = null;
	}

	/**
	 * Whether a type exists.
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::types()[ $type ] );
	}

	/**
	 * Whether the type stores a value (false for the display-only "html" block).
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public static function collects( string $type ): bool {
		$types = self::types();
		return isset( $types[ $type ] ) && ! empty( $types[ $type ]['collects'] );
	}

	/**
	 * Metadata for the admin UI (no callables).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe(): array {
		$list = array();
		foreach ( self::types() as $slug => $def ) {
			$list[] = array(
				'type'        => $slug,
				'label'       => $def['label'],
				'group'       => $def['group'],
				'collects'    => (bool) $def['collects'],
				'options'     => (bool) $def['options'],
				'placeholder' => (bool) $def['placeholder'],
				'length'      => (bool) $def['length'],
				'range'       => (string) $def['range'],
			);
		}
		return $list;
	}

	/**
	 * Validates and sanitises one submitted value against its field definition.
	 *
	 * @param array $field Field definition from the server-side flow.
	 * @param mixed $raw   Raw submitted value (null when absent).
	 * @param array $ctx   Sanitised tracking context.
	 * @return array{skip:bool,empty:bool,value:mixed,display:string,error:?string,args:array}
	 */
	public static function normalize( array $field, $raw, array $ctx = array() ): array {
		$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
		$types = self::types();
		$base  = array(
			'skip'    => false,
			'empty'   => false,
			'value'   => '',
			'display' => '',
			'error'   => null,
			'args'    => array(),
		);

		if ( ! isset( $types[ $type ] ) ) {
			$base['skip'] = true;
			return $base;
		}
		if ( empty( $types[ $type ]['collects'] ) ) {
			$base['skip'] = true;
			return $base;
		}

		// Hidden fields never depend on what the browser sent (except query_param).
		if ( 'hidden' === $type ) {
			return array_merge( $base, Types::hidden( $raw, $field, $ctx ) );
		}

		$required = ! empty( $field['required'] );

		if ( 'consent' === $type ) {
			$result = Types::consent( $raw );
			if ( $required && true !== $result['value'] ) {
				return array_merge( $base, array( 'error' => 'consent_required' ) );
			}
			return array_merge( $base, $result, array( 'empty' => true !== $result['value'] ) );
		}

		if ( self::is_blank( $raw ) ) {
			if ( $required ) {
				return array_merge( $base, array( 'error' => 'required' ) );
			}
			$empty_value = 'checkbox' === $type ? array() : '';
			return array_merge(
				$base,
				array(
					'empty' => true,
					'value' => $empty_value,
				)
			);
		}

		$result = call_user_func( $types[ $type ]['normalize'], $raw, $field, $ctx );
		return array_merge( $base, is_array( $result ) ? $result : array( 'error' => 'invalid' ) );
	}

	/**
	 * Visitor-facing message for an error code.
	 *
	 * @param string $code  Error code.
	 * @param array  $args  Message arguments (min, max).
	 * @return string
	 */
	public static function message( string $code, array $args = array() ): string {
		$messages = self::messages();
		$text     = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['invalid'];
		foreach ( $args as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * Translated messages shown to the visitor (also exported to the widget manifest).
	 *
	 * @return array<string,string>
	 */
	public static function messages(): array {
		return array(
			'required'         => __( 'This field is required.', 'krivo-chatflow' ),
			'consent_required' => __( 'Please accept to continue.', 'krivo-chatflow' ),
			'invalid'          => __( 'Please check this field.', 'krivo-chatflow' ),
			'invalid_email'    => __( 'Enter a valid e-mail address.', 'krivo-chatflow' ),
			'invalid_phone'    => __( 'Enter a valid phone number, with area code.', 'krivo-chatflow' ),
			'invalid_number'   => __( 'Enter a valid number.', 'krivo-chatflow' ),
			'invalid_url'      => __( 'Enter a valid web address.', 'krivo-chatflow' ),
			'invalid_date'     => __( 'Enter a valid date.', 'krivo-chatflow' ),
			'invalid_time'     => __( 'Enter a valid time.', 'krivo-chatflow' ),
			'invalid_option'   => __( 'Choose one of the available options.', 'krivo-chatflow' ),
			'too_long'         => __( 'This text is too long (maximum {max} characters).', 'krivo-chatflow' ),
			'min'              => __( 'The minimum is {min}.', 'krivo-chatflow' ),
			'max'              => __( 'The maximum is {max}.', 'krivo-chatflow' ),
		);
	}

	/**
	 * Whether a raw value counts as "not answered".
	 *
	 * @param mixed $raw Raw value.
	 * @return bool
	 */
	public static function is_blank( $raw ): bool {
		if ( null === $raw ) {
			return true;
		}
		if ( is_string( $raw ) ) {
			return '' === trim( $raw );
		}
		if ( is_array( $raw ) ) {
			return array() === array_filter(
				$raw,
				static function ( $item ) {
					return ! ( is_string( $item ) && '' === trim( $item ) ) && null !== $item;
				}
			);
		}
		return false;
	}

	/**
	 * Built-in types.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function builtin(): array {
		$c = static function ( string $method ): callable {
			return array( Types::class, $method );
		};

		return array(
			'name'     => array(
				'label'       => __( 'Name', 'krivo-chatflow' ),
				'group'       => 'contact',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => true,
				'range'       => '',
				'normalize'   => $c( 'name' ),
			),
			'email'    => array(
				'label'       => __( 'E-mail', 'krivo-chatflow' ),
				'group'       => 'contact',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'email' ),
			),
			'phone'    => array(
				'label'       => __( 'Phone', 'krivo-chatflow' ),
				'group'       => 'contact',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'phone' ),
			),
			'whatsapp' => array(
				'label'       => __( 'WhatsApp number', 'krivo-chatflow' ),
				'group'       => 'contact',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'phone' ),
			),
			'text'     => array(
				'label'       => __( 'Short text', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => true,
				'range'       => '',
				'normalize'   => $c( 'text' ),
			),
			'textarea' => array(
				'label'       => __( 'Long text', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => true,
				'range'       => '',
				'normalize'   => $c( 'textarea' ),
			),
			'number'   => array(
				'label'       => __( 'Number', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => false,
				'range'       => 'number',
				'normalize'   => $c( 'number' ),
			),
			'select'   => array(
				'label'       => __( 'Dropdown', 'krivo-chatflow' ),
				'group'       => 'choice',
				'collects'    => true,
				'options'     => true,
				'placeholder' => true,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'choice' ),
			),
			'radio'    => array(
				'label'       => __( 'Single choice', 'krivo-chatflow' ),
				'group'       => 'choice',
				'collects'    => true,
				'options'     => true,
				'placeholder' => false,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'choice' ),
			),
			'checkbox' => array(
				'label'       => __( 'Multiple choice', 'krivo-chatflow' ),
				'group'       => 'choice',
				'collects'    => true,
				'options'     => true,
				'placeholder' => false,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'multi_choice' ),
			),
			'date'     => array(
				'label'       => __( 'Date', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => false,
				'length'      => false,
				'range'       => 'date',
				'normalize'   => $c( 'date' ),
			),
			'time'     => array(
				'label'       => __( 'Time', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => false,
				'length'      => false,
				'range'       => 'time',
				'normalize'   => $c( 'time' ),
			),
			'url'      => array(
				'label'       => __( 'Web address', 'krivo-chatflow' ),
				'group'       => 'text',
				'collects'    => true,
				'options'     => false,
				'placeholder' => true,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'url' ),
			),
			'consent'  => array(
				'label'       => __( 'Consent (LGPD)', 'krivo-chatflow' ),
				'group'       => 'other',
				'collects'    => true,
				'options'     => false,
				'placeholder' => false,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'consent' ),
			),
			'hidden'   => array(
				'label'       => __( 'Hidden field', 'krivo-chatflow' ),
				'group'       => 'other',
				'collects'    => true,
				'options'     => false,
				'placeholder' => false,
				'length'      => false,
				'range'       => '',
				'normalize'   => $c( 'hidden' ),
			),
			'html'     => array(
				'label'       => __( 'Text / HTML block', 'krivo-chatflow' ),
				'group'       => 'other',
				'collects'    => false,
				'options'     => false,
				'placeholder' => false,
				'length'      => false,
				'range'       => '',
				'normalize'   => static function () {
					return array();
				},
			),
		);
	}
}
