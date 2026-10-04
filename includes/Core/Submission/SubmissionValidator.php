<?php
/**
 * Submission validation.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Submission;

use Krivo\ChatFlow\Core\Fields\Registry;
use Krivo\ChatFlow\Core\Flow\Hours;
use Krivo\ChatFlow\Core\Flow\Journey;
use Krivo\ChatFlow\Core\Variables\Builder;
use Krivo\ChatFlow\Infrastructure\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Validates what the visitor sent against the SERVER-side flow, never against anything the browser
 * claims about the form.
 *
 * It first normalises every submitted value leniently, replays the visitor's journey with the
 * conditional logic (so a field hidden by logic is neither required nor stored, and a skipped
 * question stays skipped), and then enforces the rules only on what the visitor actually saw.
 * Unknown keys are dropped.
 */
final class SubmissionValidator {

	/**
	 * Validates the answers.
	 *
	 * @param array $flow     Published flow configuration.
	 * @param array $answers  Raw answers by field key.
	 * @param array $tracking Sanitised tracking context.
	 * @return array{values:array,answers:array,errors:array,finished:bool,in_hours:?bool,consent:array,ctx:array}
	 */
	public static function validate( array $flow, array $answers, array $tracking ): array {
		$fields = Builder::fields( $flow );

		$in_hours = Hours::is_open( (array) ( $flow['hours'] ?? array() ) );
		$ctx      = array_merge(
			$tracking,
			array( 'in_hours' => null === $in_hours ? '' : ( $in_hours ? 'yes' : 'no' ) )
		);

		// Pass 1: lenient normalisation (no "required"), values feed the conditions.
		$lenient = array();
		foreach ( $fields as $field ) {
			if ( ! Registry::collects( $field['type'] ) ) {
				continue;
			}
			$raw                       = $answers[ $field['key'] ] ?? null;
			$result                    = Registry::normalize( array_merge( $field, array( 'required' => false ) ), $raw, $ctx );
			$lenient[ $field['key'] ] = null !== $result['error'] ? '' : $result['value'];
		}

		// Pass 2: which fields did the visitor actually see?
		$journey = Journey::compute( $flow, $lenient, $ctx );

		// Pass 3: strict validation of what was visible.
		$values  = array();
		$stored  = array();
		$errors  = array();
		$consent = array();
		foreach ( $fields as $field ) {
			if ( ! Registry::collects( $field['type'] ) || empty( $journey['visible'][ $field['id'] ] ) ) {
				continue;
			}
			$result = Registry::normalize( $field, $answers[ $field['key'] ] ?? null, $ctx );
			if ( null !== $result['error'] ) {
				$errors[ $field['key'] ] = Registry::message( $result['error'], (array) ( $result['args'] ?? array() ), $field );
				continue;
			}
			$values[ $field['key'] ] = $result['value'];
			if ( ! $result['empty'] && ( '' !== (string) $result['display'] || is_array( $result['value'] ) ) ) {
				$stored[ $field['key'] ] = array(
					'label'   => '' !== $field['label'] ? $field['label'] : $field['key'],
					'type'    => $field['type'],
					'value'   => $result['value'],
					'display' => $result['display'],
				);
			}
			if ( 'consent' === $field['type'] && true === $result['value'] ) {
				$consent[] = array(
					'key'       => $field['key'],
					'accepted'  => true,
					'text_hash' => md5( (string) ( $field['text'] ?? '' ) ),
					'at'        => gmdate( 'Y-m-d\TH:i:s\Z', Clock::time() ),
				);
			}
		}

		return array(
			'values'   => $values,
			'answers'  => $stored,
			'errors'   => $errors,
			'finished' => $journey['finished'],
			'in_hours' => $in_hours,
			'consent'  => $consent,
			'ctx'      => $ctx,
		);
	}
}
