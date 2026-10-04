<?php
/**
 * Flow configuration sanitiser.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Automation\Registry as AutomationRegistry;
use Krivo\ChatFlow\Core\Conditions\Evaluator;
use Krivo\ChatFlow\Core\Fields\Registry as FieldRegistry;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a flow configuration from untrusted input.
 *
 * The result contains only known keys with known types and bounded sizes: unknown keys are dropped,
 * enums fall back to defaults, ids and keys are made unique, text is sanitised for its purpose and
 * rich fields go through an allowlist. The admin editor, the manifest builder and the pipeline can
 * therefore rely on the shape of what they read.
 */
final class Sanitizer {

	public const MAX_STEPS       = 10;
	public const MAX_FIELDS      = 40;
	public const MAX_OPTIONS     = 50;
	public const MAX_RULES       = 50;
	public const MAX_CONDITIONS  = 10;
	public const MAX_ACTIONS     = 5;
	public const MAX_AUTOMATIONS = 20;

	/**
	 * Sanitises a flow configuration.
	 *
	 * @param array $raw Raw configuration.
	 * @return array Clean configuration (schema 1).
	 */
	public static function sanitize( array $raw ): array {
		$out = Defaults::blank();

		$name        = self::str( $raw['name'] ?? '', 120 );
		$out['name'] = '' !== $name ? $name : $out['name'];
		$out['mode'] = self::enum( $raw['mode'] ?? '', Defaults::MODES, 'form' );

		$ids          = array();
		$out['steps'] = self::steps( $raw['steps'] ?? array(), $ids );

		$out['logic']       = array( 'rules' => self::rules( $raw['logic']['rules'] ?? array(), $out['mode'], $out['steps'], $ids ) );
		$out['finish']      = array(
			'button_label'    => self::str( $raw['finish']['button_label'] ?? '', 60 ),
			'success_message' => self::textarea( $raw['finish']['success_message'] ?? '', 500 ),
		);
		$destination        = isset( $raw['whatsapp']['default_destination'] ) ? (string) $raw['whatsapp']['default_destination'] : '';
		$out['whatsapp']    = array(
			'message'             => self::textarea( $raw['whatsapp']['message'] ?? '', 1000 ),
			'default_destination' => preg_match( '/^dst_[a-z0-9]{4,16}$/', $destination ) ? $destination : '',
		);
		$out['automations'] = self::automations( $raw['automations'] ?? array() );
		$out['bubble']      = self::bubble( is_array( $raw['bubble'] ?? null ) ? $raw['bubble'] : array(), $out['bubble'] );
		$out['design']      = self::design( is_array( $raw['design'] ?? null ) ? $raw['design'] : array(), $out['design'] );
		$out['hours']       = self::hours( is_array( $raw['hours'] ?? null ) ? $raw['hours'] : array(), $out['hours'] );
		$out['behavior']    = self::behavior( is_array( $raw['behavior'] ?? null ) ? $raw['behavior'] : array(), $out['behavior'] );
		$out['display']     = self::display( is_array( $raw['display'] ?? null ) ? $raw['display'] : array(), $out['display'] );

		return $out;
	}

	// ----------------------------------------------------------------------------------------
	// Steps and fields
	// ----------------------------------------------------------------------------------------

	/**
	 * Sanitises the steps and their fields.
	 *
	 * @param mixed $raw  Raw steps.
	 * @param array $ids  Collects every step and field id (output).
	 * @return array[]
	 */
	private static function steps( $raw, array &$ids ): array {
		$steps       = array();
		$used_keys   = array();
		$field_count = 0;

		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $raw_step ) {
			if ( count( $steps ) >= self::MAX_STEPS || ! is_array( $raw_step ) ) {
				break;
			}
			$step_id = self::unique_id( $raw_step['id'] ?? '', 's_', $ids );
			$fields  = array();
			foreach ( is_array( $raw_step['fields'] ?? null ) ? array_values( $raw_step['fields'] ) : array() as $raw_field ) {
				if ( $field_count >= self::MAX_FIELDS ) {
					break;
				}
				$field = self::field( $raw_field, $ids, $used_keys );
				if ( null !== $field ) {
					$fields[] = $field;
					++$field_count;
				}
			}
			$steps[] = array(
				'id'     => $step_id,
				'title'  => self::str( $raw_step['title'] ?? '', 120 ),
				'fields' => $fields,
			);
		}

		if ( array() === $steps ) {
			$ids_copy = $ids;
			$steps[]  = array(
				'id'     => self::unique_id( '', 's_', $ids_copy ),
				'title'  => '',
				'fields' => array(),
			);
			$ids      = $ids_copy;
		}
		return $steps;
	}

	/**
	 * Sanitises one field. Returns null for unknown types.
	 *
	 * @param mixed $raw       Raw field.
	 * @param array $ids       Used ids (modified).
	 * @param array $used_keys Used keys (modified).
	 * @return array|null
	 */
	private static function field( $raw, array &$ids, array &$used_keys ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$type = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : '';
		if ( ! FieldRegistry::has( $type ) ) {
			return null;
		}

		$label   = self::str( $raw['label'] ?? '', 255 );
		$collect = FieldRegistry::collects( $type );
		$meta    = FieldRegistry::types()[ $type ];

		$field = array(
			'id'          => self::unique_id( $raw['id'] ?? '', 'f_', $ids ),
			'type'        => $type,
			'key'         => $collect ? self::unique_key( $raw['key'] ?? '', $label, $type, $used_keys ) : '',
			'label'       => $label,
			'prompt'      => self::str( $raw['prompt'] ?? '', 500 ),
			'description' => self::str( $raw['description'] ?? '', 500 ),
			'placeholder' => ! empty( $meta['placeholder'] ) ? self::str( $raw['placeholder'] ?? '', 255 ) : '',
			'required'    => $collect && ! in_array( $type, array( 'hidden' ), true ) && self::bool( $raw['required'] ?? false ),
		);

		if ( ! empty( $meta['options'] ) ) {
			$field['options'] = self::options( $raw['options'] ?? array() );
		}
		if ( ! empty( $meta['length'] ) ) {
			$max                = self::int( $raw['maxlength'] ?? 0, 0, 5000, 0 );
			$field['maxlength'] = $max;
		}

		switch ( $type ) {
			case 'number':
				$field['min'] = self::number_or_empty( $raw['min'] ?? '' );
				$field['max'] = self::number_or_empty( $raw['max'] ?? '' );
				break;
			case 'date':
				$field['min'] = self::date_or_empty( $raw['min'] ?? '' );
				$field['max'] = self::date_or_empty( $raw['max'] ?? '' );
				break;
			case 'time':
				$field['min'] = self::time_or_empty( $raw['min'] ?? '' );
				$field['max'] = self::time_or_empty( $raw['max'] ?? '' );
				break;
			case 'phone':
			case 'whatsapp':
				$field['default_dial_code'] = substr( (string) preg_replace( '/\D+/', '', (string) ( $raw['default_dial_code'] ?? '' ) ), 0, 4 );
				break;
			case 'consent':
				$field['text']         = self::rich( $raw['text'] ?? '', 500, array( 'a', 'strong', 'em', 'br' ) );
				$field['privacy_link'] = self::bool( $raw['privacy_link'] ?? false );
				break;
			case 'hidden':
				$field['source'] = self::enum(
					$raw['source'] ?? '',
					array( 'static', 'query_param', 'page_url', 'page_title', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ),
					'static'
				);
				$field['value']  = self::str( $raw['value'] ?? '', 255 );
				$param           = isset( $raw['param'] ) ? (string) $raw['param'] : '';
				$field['param']  = preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $param ) ? $param : '';
				break;
			case 'html':
				$field['html'] = self::rich( $raw['html'] ?? '', 2000, array( 'a', 'strong', 'b', 'em', 'i', 'br', 'p', 'ul', 'ol', 'li' ) );
				break;
		}

		// Default value, only where it makes sense and always valid for the field.
		$field['default'] = self::default_value( $type, $raw['default'] ?? '', $field );

		return $field;
	}

	/**
	 * Sanitises the options of a choice field.
	 *
	 * @param mixed $raw Raw options.
	 * @return array[] List of {value,label}.
	 */
	private static function options( $raw ): array {
		$options = array();
		$used    = array();
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $item ) {
			if ( count( $options ) >= self::MAX_OPTIONS || ! is_array( $item ) ) {
				continue;
			}
			$label = self::str( $item['label'] ?? '', 120 );
			if ( '' === $label ) {
				continue;
			}
			$value = isset( $item['value'] ) ? self::str( $item['value'], 80 ) : '';
			if ( '' === $value ) {
				$value = Text::key_from_label( $label, 'option' );
			}
			$base = $value;
			$n    = 2;
			while ( isset( $used[ $value ] ) ) {
				$value = $base . '_' . $n++;
			}
			$used[ $value ] = true;
			$options[]      = array(
				'value' => $value,
				'label' => $label,
			);
		}
		return $options;
	}

	/**
	 * Default value for a field, restricted to what the field can actually hold.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $raw   Raw default.
	 * @param array  $field Sanitised field (for its options).
	 * @return mixed
	 */
	private static function default_value( string $type, $raw, array $field ) {
		switch ( $type ) {
			case 'select':
			case 'radio':
				$allowed = array_column( $field['options'], 'value' );
				return in_array( (string) $raw, $allowed, true ) ? (string) $raw : '';
			case 'checkbox':
				$allowed = array_column( $field['options'], 'value' );
				$values  = array_values( array_intersect( array_map( 'strval', (array) $raw ), $allowed ) );
				return $values;
			case 'consent':
				return self::bool( $raw );
			case 'html':
			case 'hidden':
				return '';
			case 'number':
				$number = is_scalar( $raw ) ? \Krivo\ChatFlow\Core\Fields\Types::parse_decimal( (string) $raw ) : null;
				return null === $number ? '' : $number;
			case 'date':
				return self::date_or_empty( $raw );
			case 'time':
				return self::time_or_empty( $raw );
			default:
				return self::str( $raw, 500 );
		}
	}

	// ----------------------------------------------------------------------------------------
	// Logic
	// ----------------------------------------------------------------------------------------

	/**
	 * Sanitises the logic rules against the real fields and steps.
	 *
	 * @param mixed  $raw   Raw rules.
	 * @param string $mode  Flow mode.
	 * @param array  $steps Sanitised steps.
	 * @param array  $ids   Known ids.
	 * @return array[]
	 */
	private static function rules( $raw, string $mode, array $steps, array $ids ): array {
		$field_ids = array();
		$unit_ids  = array();
		foreach ( $steps as $step ) {
			if ( 'steps' === $mode ) {
				$unit_ids[] = $step['id'];
			}
			foreach ( $step['fields'] as $field ) {
				$field_ids[] = $field['id'];
				if ( 'conversational' === $mode ) {
					$unit_ids[] = $field['id'];
				}
			}
		}

		$rules = array();
		$used  = array();
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $item ) {
			if ( count( $rules ) >= self::MAX_RULES || ! is_array( $item ) ) {
				continue;
			}
			$after   = isset( $item['after'] ) ? (string) $item['after'] : '';
			$actions = array();
			foreach ( is_array( $item['then'] ?? null ) ? array_values( $item['then'] ) : array() as $action ) {
				if ( count( $actions ) >= self::MAX_ACTIONS || ! is_array( $action ) ) {
					continue;
				}
				$do     = isset( $action['do'] ) ? (string) $action['do'] : '';
				$target = isset( $action['target'] ) ? (string) $action['target'] : '';

				if ( in_array( $do, array( 'show_field', 'hide_field' ), true ) && in_array( $target, $field_ids, true ) ) {
					$actions[] = array(
						'do'     => $do,
						'target' => $target,
					);
				} elseif ( 'goto' === $do && in_array( $target, $unit_ids, true ) && in_array( $after, $unit_ids, true ) ) {
					$actions[] = array(
						'do'     => 'goto',
						'target' => $target,
					);
				} elseif ( 'finish' === $do && in_array( $after, $unit_ids, true ) ) {
					$actions[] = array( 'do' => 'finish' );
				}
			}
			if ( array() === $actions ) {
				continue;
			}

			$has_jump = false;
			foreach ( $actions as $action ) {
				$has_jump = $has_jump || in_array( $action['do'], array( 'goto', 'finish' ), true );
			}

			$rule = array(
				'id'   => self::unique_id( $item['id'] ?? '', 'r_', $used ),
				'when' => self::group( $item['when'] ?? array() ),
				'then' => $actions,
			);
			if ( $has_jump ) {
				$rule['after'] = $after;
			}
			$rules[] = $rule;
		}
		return $rules;
	}

	/**
	 * Sanitises a condition group (also used by the routing rules).
	 *
	 * @param mixed $raw   Raw group.
	 * @param int   $depth Nesting depth.
	 * @return array{match:string,conditions:array}
	 */
	public static function group( $raw, int $depth = 0 ): array {
		$raw        = is_array( $raw ) ? $raw : array();
		$conditions = array();
		foreach ( is_array( $raw['conditions'] ?? null ) ? array_values( $raw['conditions'] ) : array() as $item ) {
			if ( count( $conditions ) >= self::MAX_CONDITIONS || ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['conditions'] ) ) {
				if ( $depth < 1 ) {
					$conditions[] = self::group( $item, $depth + 1 );
				}
				continue;
			}
			$left = isset( $item['left'] ) ? (string) $item['left'] : '';
			$op   = isset( $item['op'] ) ? (string) $item['op'] : '';
			if ( ! preg_match( '/^(field:[a-z0-9_]{1,40}|ctx:[a-z_]{1,24})$/', $left ) || ! in_array( $op, Evaluator::OPERATORS, true ) ) {
				continue;
			}
			$right = $item['right'] ?? '';
			if ( is_array( $right ) ) {
				$right = implode( ',', array_map( 'strval', array_filter( $right, 'is_scalar' ) ) );
			}
			$conditions[] = array(
				'left'  => $left,
				'op'    => $op,
				'right' => self::str( $right, 300 ),
			);
		}
		return array(
			'match'      => self::enum( $raw['match'] ?? '', array( 'all', 'any' ), 'all' ),
			'conditions' => $conditions,
		);
	}

	// ----------------------------------------------------------------------------------------
	// Automations
	// ----------------------------------------------------------------------------------------

	/**
	 * Sanitises the automations list.
	 *
	 * @param mixed $raw Raw automations.
	 * @return array[]
	 */
	private static function automations( $raw ): array {
		$items   = array();
		$used    = array();
		$managed = false;
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $item ) {
			if ( count( $items ) >= self::MAX_AUTOMATIONS ) {
				break;
			}
			$clean = AutomationRegistry::sanitize_item( $item );
			if ( null === $clean ) {
				continue;
			}
			if ( $clean['managed'] ) {
				if ( $managed ) {
					continue; // At most one managed WhatsApp action.
				}
				$managed = true;
			}
			$clean['id'] = self::unique_id( $clean['id'], 'a_', $used );
			$items[]     = $clean;
		}
		return $items;
	}

	// ----------------------------------------------------------------------------------------
	// Appearance, behaviour, hours, display
	// ----------------------------------------------------------------------------------------

	/**
	 * Sanitises the bubble settings.
	 *
	 * @param array $raw      Raw bubble.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function bubble( array $raw, array $defaults ): array {
		$out  = $defaults;
		$icon = is_array( $raw['icon'] ?? null ) ? $raw['icon'] : array();
		$type = self::enum( $icon['type'] ?? '', Defaults::ICONS, 'chat' );

		$svg = '';
		$att = 0;
		if ( 'custom_svg' === $type ) {
			$svg = SvgSanitizer::sanitize( (string) ( $icon['svg'] ?? '' ) );
			$type = '' === $svg ? 'chat' : $type;
		} elseif ( 'custom_image' === $type ) {
			$att  = self::attachment( $icon['attachment_id'] ?? 0 );
			$type = 0 === $att ? 'chat' : $type;
		}
		$out['icon'] = array(
			'type'          => $type,
			'svg'           => $svg,
			'attachment_id' => $att,
		);

		$out['label']    = self::str( $raw['label'] ?? '', 80 );
		$out['shape']    = self::enum( $raw['shape'] ?? '', Defaults::SHAPES, 'circle' );
		$out['radius']   = self::int( $raw['radius'] ?? $defaults['radius'], 0, 60, $defaults['radius'] );
		$out['position'] = self::enum( $raw['position'] ?? '', Defaults::POSITIONS, 'bottom-right' );
		$out['offset_x'] = self::int( $raw['offset_x'] ?? $defaults['offset_x'], 0, 300, $defaults['offset_x'] );
		$out['offset_y'] = self::int( $raw['offset_y'] ?? $defaults['offset_y'], 0, 300, $defaults['offset_y'] );
		$out['size']     = self::int( $raw['size'] ?? $defaults['size'], 40, 96, $defaults['size'] );
		$colors          = is_array( $raw['colors'] ?? null ) ? $raw['colors'] : array();
		$out['colors']   = array(
			'bg'     => self::color( $colors['bg'] ?? '', $defaults['colors']['bg'] ),
			'icon'   => self::color( $colors['icon'] ?? '', $defaults['colors']['icon'] ),
			'border' => self::color( $colors['border'] ?? '', '', true ),
		);
		$out['shadow']    = self::bool( $raw['shadow'] ?? $defaults['shadow'] );
		$out['animation'] = self::enum( $raw['animation'] ?? '', Defaults::ANIMATIONS, 'none' );

		$tooltip        = is_array( $raw['tooltip'] ?? null ) ? $raw['tooltip'] : array();
		$out['tooltip'] = array(
			'enabled'     => self::bool( $tooltip['enabled'] ?? $defaults['tooltip']['enabled'] ),
			'text'        => self::str( $tooltip['text'] ?? '', 120 ),
			'delay'       => self::int( $tooltip['delay'] ?? $defaults['tooltip']['delay'], 0, 120, $defaults['tooltip']['delay'] ),
			'duration'    => self::int( $tooltip['duration'] ?? $defaults['tooltip']['duration'], 0, 120, $defaults['tooltip']['duration'] ),
			'dismissible' => self::bool( $tooltip['dismissible'] ?? $defaults['tooltip']['dismissible'] ),
			'once'        => self::bool( $tooltip['once'] ?? $defaults['tooltip']['once'] ),
		);

		$badge        = is_array( $raw['badge'] ?? null ) ? $raw['badge'] : array();
		$out['badge'] = array(
			'enabled' => self::bool( $badge['enabled'] ?? $defaults['badge']['enabled'] ),
			'text'    => self::str( $badge['text'] ?? $defaults['badge']['text'], 3 ),
			'delay'   => self::int( $badge['delay'] ?? $defaults['badge']['delay'], 0, 120, $defaults['badge']['delay'] ),
		);
		return $out;
	}

	/**
	 * Sanitises the chat panel design.
	 *
	 * @param array $raw      Raw design.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function design( array $raw, array $defaults ): array {
		$strings = is_array( $raw['strings'] ?? null ) ? $raw['strings'] : array();
		$out     = array(
			'title'                => self::str( $raw['title'] ?? '', 80 ),
			'subtitle'             => self::str( $raw['subtitle'] ?? '', 160 ),
			'theme'                => self::enum( $raw['theme'] ?? '', Defaults::THEMES, 'light' ),
			'accent'               => self::color( $raw['accent'] ?? '', $defaults['accent'] ),
			'avatar_attachment_id' => self::attachment( $raw['avatar_attachment_id'] ?? 0 ),
			'strings'              => array(),
		);
		foreach ( array_keys( $defaults['strings'] ) as $key ) {
			$out['strings'][ $key ] = self::str( $strings[ $key ] ?? '', 120 );
		}
		return $out;
	}

	/**
	 * Sanitises business hours.
	 *
	 * @param array $raw      Raw hours.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function hours( array $raw, array $defaults ): array {
		$days = array();
		foreach ( Hours::DAYS as $day ) {
			$ranges = array();
			foreach ( is_array( $raw['days'][ $day ] ?? null ) ? array_values( $raw['days'][ $day ] ) : array() as $range ) {
				if ( count( $ranges ) >= 3 || ! is_array( $range ) || 2 !== count( $range ) ) {
					continue;
				}
				$range = array_values( $range );
				$start = self::time_or_empty( $range[0] );
				$end   = self::time_or_empty( $range[1] );
				if ( '' !== $start && '' !== $end && $start < $end ) {
					$ranges[] = array( $start, $end );
				}
			}
			usort(
				$ranges,
				static function ( $a, $b ) {
					return strcmp( $a[0], $b[0] );
				}
			);
			$days[ $day ] = $ranges;
		}
		return array(
			'enabled' => self::bool( $raw['enabled'] ?? $defaults['enabled'] ),
			'days'    => $days,
		);
	}

	/**
	 * Sanitises the display behaviour.
	 *
	 * @param array $raw      Raw behaviour.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function behavior( array $raw, array $defaults ): array {
		return array(
			'trigger'        => self::enum( $raw['trigger'] ?? '', array( 'delay', 'scroll' ), 'delay' ),
			'delay'          => self::int( $raw['delay'] ?? $defaults['delay'], 0, 60, $defaults['delay'] ),
			'scroll_percent' => self::int( $raw['scroll_percent'] ?? $defaults['scroll_percent'], 1, 100, $defaults['scroll_percent'] ),
			'auto_open'      => self::bool( $raw['auto_open'] ?? false ),
			'auto_open_once' => self::bool( $raw['auto_open_once'] ?? true ),
		);
	}

	/**
	 * Sanitises the display (where the widget appears).
	 *
	 * @param array $raw      Raw display settings.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	private static function display( array $raw, array $defaults ): array {
		$devices = array_values( array_intersect( Defaults::DEVICES, array_map( 'strval', (array) ( $raw['devices'] ?? Defaults::DEVICES ) ) ) );
		return array(
			'priority' => self::int( $raw['priority'] ?? $defaults['priority'], 0, 999, $defaults['priority'] ),
			'mode'     => self::enum( $raw['mode'] ?? '', array( 'all', 'only', 'none' ), 'all' ),
			'include'  => self::display_rules( $raw['include'] ?? array() ),
			'exclude'  => self::display_rules( $raw['exclude'] ?? array() ),
			'devices'  => array() === $devices ? Defaults::DEVICES : $devices,
			'logged'   => self::enum( $raw['logged'] ?? '', array( 'any', 'in', 'out' ), 'any' ),
		);
	}

	/**
	 * Sanitises a list of page rules.
	 *
	 * @param mixed $raw Raw rules.
	 * @return array[]
	 */
	private static function display_rules( $raw ): array {
		$rules = array();
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $item ) {
			if ( count( $rules ) >= 30 || ! is_array( $item ) ) {
				continue;
			}
			$type = self::enum( $item['type'] ?? '', array( 'url', 'post_type', 'post', 'term', 'page_type' ), '' );
			switch ( $type ) {
				case 'url':
					$value = self::str( $item['value'] ?? '', 200 );
					if ( '' !== $value ) {
						$rules[] = array(
							'type'  => 'url',
							'op'    => self::enum( $item['op'] ?? '', array( 'contains', 'equals', 'starts_with', 'ends_with', 'matches' ), 'contains' ),
							'value' => $value,
						);
					}
					break;
				case 'post_type':
					$value = sanitize_key( (string) ( $item['value'] ?? '' ) );
					if ( '' !== $value ) {
						$rules[] = array(
							'type'  => 'post_type',
							'value' => substr( $value, 0, 40 ),
						);
					}
					break;
				case 'post':
					$value = self::int( $item['value'] ?? 0, 0, PHP_INT_MAX, 0 );
					if ( $value > 0 ) {
						$rules[] = array(
							'type'  => 'post',
							'value' => $value,
						);
					}
					break;
				case 'term':
					$taxonomy = sanitize_key( (string) ( $item['taxonomy'] ?? '' ) );
					$value    = self::str( $item['value'] ?? '', 80 );
					if ( '' !== $taxonomy && '' !== $value ) {
						$rules[] = array(
							'type'     => 'term',
							'taxonomy' => substr( $taxonomy, 0, 40 ),
							'value'    => $value,
						);
					}
					break;
				case 'page_type':
					$value = self::enum(
						$item['value'] ?? '',
						array( 'front_page', 'blog', 'search', '404', 'archive', 'singular', 'woo_shop', 'woo_cart', 'woo_checkout', 'woo_account' ),
						''
					);
					if ( '' !== $value ) {
						$rules[] = array(
							'type'  => 'page_type',
							'value' => $value,
						);
					}
					break;
			}
		}
		return $rules;
	}

	// ----------------------------------------------------------------------------------------
	// Primitive sanitisers
	// ----------------------------------------------------------------------------------------

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum characters.
	 * @return string
	 */
	public static function str( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return Text::limit( sanitize_text_field( (string) $value ), $max );
	}

	/**
	 * Multi-line text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum characters.
	 * @return string
	 */
	public static function textarea( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return Text::limit( sanitize_textarea_field( (string) $value ), $max );
	}

	/**
	 * Limited HTML with an allowlist of tags (links get safe attributes only).
	 *
	 * @param mixed    $value Value.
	 * @param int      $max   Maximum characters.
	 * @param string[] $tags  Allowed tags.
	 * @return string
	 */
	public static function rich( $value, int $max, array $tags ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$allowed = array();
		foreach ( $tags as $tag ) {
			$allowed[ $tag ] = 'a' === $tag
				? array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				)
				: array();
		}
		$clean = wp_kses( Text::limit( (string) $value, $max ), $allowed, array( 'http', 'https', 'mailto' ) );
		// Links opened in a new tab must not hand the opener to the target.
		return (string) preg_replace_callback(
			'/<a\s([^>]*)>/i',
			static function ( array $m ): string {
				$attrs = (string) preg_replace( '/\s*rel=("[^"]*"|\'[^\']*\')/i', '', $m[1] );
				return '<a ' . trim( $attrs ) . ' rel="noopener noreferrer">';
			},
			$clean
		);
	}

	/**
	 * A strictly valid e-mail address, or an empty string.
	 *
	 * sanitize_email() silently drops illegal characters, so "a@b.com\r\nBcc: x@y.com" would come out
	 * as a different, valid-looking address. Mutating what a person typed is wrong (and a header
	 * injection vector), so anything the sanitiser changes is rejected instead of "repaired".
	 *
	 * @param mixed $value Value.
	 * @return string Lowercase address, or empty when invalid.
	 */
	public static function email( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$typed = strtolower( trim( $value ) );
		$clean = strtolower( sanitize_email( $typed ) );
		return '' !== $clean && $clean === $typed && is_email( $clean ) && strlen( $clean ) <= 190 ? $clean : '';
	}

	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function bool( $value ): bool {
		return is_bool( $value ) ? $value : rest_sanitize_boolean( $value );
	}

	/**
	 * Bounded integer.
	 *
	 * @param mixed $value    Value.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @param int   $fallback Value when not numeric.
	 * @return int
	 */
	public static function int( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * One of a fixed list.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback.
	 * @return string
	 */
	public static function enum( $value, array $allowed, string $fallback ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Hex colour (#RGB or #RRGGBB), normalised to uppercase.
	 *
	 * @param mixed  $value       Value.
	 * @param string $fallback    Fallback colour.
	 * @param bool   $allow_empty Whether an empty value is allowed.
	 * @return string
	 */
	public static function color( $value, string $fallback, bool $allow_empty = false ): string {
		if ( $allow_empty && ( '' === $value || null === $value ) ) {
			return '';
		}
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
		return $color ? strtoupper( $color ) : $fallback;
	}

	/**
	 * Attachment id of an image the current context can use, or 0.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function attachment( $value ): int {
		$id = absint( $value );
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * Unique id derived from a proposed one.
	 *
	 * @param mixed  $proposed Proposed id.
	 * @param string $prefix   Prefix for generated ids.
	 * @param array  $used     Used ids (modified).
	 * @return string
	 */
	private static function unique_id( $proposed, string $prefix, array &$used ): string {
		$id = is_string( $proposed ) && preg_match( '/^[a-z0-9_]{1,24}$/', $proposed ) ? $proposed : '';
		while ( '' === $id || in_array( $id, $used, true ) ) {
			$id = Text::random_id( $prefix, 6 );
		}
		$used[] = $id;
		return $id;
	}

	/**
	 * Unique field key (variable name).
	 *
	 * @param mixed  $proposed  Proposed key.
	 * @param string $label     Field label (used when no key).
	 * @param string $type      Field type.
	 * @param array  $used_keys Used keys (modified).
	 * @return string
	 */
	private static function unique_key( $proposed, string $label, string $type, array &$used_keys ): string {
		$key = is_string( $proposed ) ? strtolower( trim( (string) preg_replace( '/[^a-zA-Z0-9_]+/', '_', $proposed ), '_' ) ) : '';
		if ( '' === $key ) {
			$key = Text::key_from_label( '' !== $label ? $label : $type, $type );
		}
		$key = Text::limit( $key, 40 );
		if ( in_array( $key, Builder::reserved(), true ) ) {
			$key .= '_field';
		}
		$base = $key;
		$n    = 2;
		while ( in_array( $key, $used_keys, true ) ) {
			$key = $base . '_' . $n++;
		}
		$used_keys[] = $key;
		return $key;
	}

	/**
	 * A canonical number string or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function number_or_empty( $value ): string {
		$number = is_scalar( $value ) ? \Krivo\ChatFlow\Core\Fields\Types::parse_decimal( (string) $value ) : null;
		return null === $number ? '' : $number;
	}

	/**
	 * A valid Y-m-d date or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function date_or_empty( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * A valid H:i time or empty.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function time_or_empty( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}
}
