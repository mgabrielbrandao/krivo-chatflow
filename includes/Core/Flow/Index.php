<?php
/**
 * Published flows index.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Core\Flow;

use Krivo\ChatFlow\Infrastructure\Db\Tables;
use Krivo\ChatFlow\Infrastructure\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A small autoloaded option with just what a page view needs to decide whether to show a widget:
 * flow id, revision, priority, display rules and the "boot" part of the manifest. A page view then
 * costs no database query at all, and a page without a matching flow loads nothing.
 */
final class Index {

	public const OPTION = 'krivo_chatflow_index';

	/**
	 * All published flows.
	 *
	 * @return array<int,array>
	 */
	public static function all(): array {
		$index = get_option( self::OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * One entry.
	 *
	 * @param int $id Flow id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Rebuilds the index from the published flows. Called after publish, pause, delete and when
	 * settings that appear in the boot data (signature) change.
	 *
	 * @return void
	 */
	public static function rebuild(): void {
		global $wpdb;
		$table = Tables::flows();
		$rows  = $wpdb->get_results( "SELECT id, revision, config_published FROM {$table} WHERE status = 'published' AND config_published IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// The default texts in the boot data ("Open chat"...) are translated when the index is built, so it is
		// built in the site's language (an administrator's own language must not leak into it).
		$site_locale = get_locale();
		$switched    = determine_locale() !== $site_locale && switch_to_locale( $site_locale );

		$index = array();
		foreach ( (array) $rows as $row ) {
			$config = Json::decode( (string) $row['config_published'] );
			if ( array() === $config ) {
				continue;
			}
			$id           = (int) $row['id'];
			$index[ $id ] = array(
				'id'       => $id,
				'rev'      => (int) $row['revision'],
				'priority' => (int) $config['display']['priority'],
				'display'  => $config['display'],
				'locale'   => $site_locale,
				'boot'     => Manifest::boot( $id, (int) $row['revision'], $config ),
			);
		}
		if ( $switched ) {
			restore_previous_locale();
		}
		uasort(
			$index,
			static function ( $a, $b ) {
				$by_priority = $b['priority'] <=> $a['priority'];
				return 0 !== $by_priority ? $by_priority : $a['id'] <=> $b['id'];
			}
		);
		update_option( self::OPTION, $index, true );
	}

	/**
	 * The boot data of an index entry in the language of the current request.
	 *
	 * Normally that is the language the index was built in. When the language differs (the site language was
	 * changed, or a multilingual plugin serves several languages) the boot data is rebuilt from the published
	 * flow and kept for a day per flow, revision and language, so the index itself is never rewritten on a page view.
	 *
	 * @param array $entry Index entry.
	 * @return array
	 */
	public static function boot_for_current_locale( array $entry ): array {
		$locale = get_locale();
		if ( ( $entry['locale'] ?? '' ) === $locale ) {
			return (array) $entry['boot'];
		}
		// The hash of the stored boot data changes whenever something in it changes (for example the credit line).
		$key    = 'krivo_chatflow_boot_' . (int) $entry['id'] . '_' . (int) $entry['rev'] . '_' . substr( md5( $locale . Json::encode( (array) $entry['boot'] ) ), 0, 16 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$row = FlowRepository::published( (int) $entry['id'] );
		if ( null === $row ) {
			return (array) $entry['boot'];
		}
		$boot = Manifest::boot( $row['id'], $row['revision'], $row['config'] );
		set_transient( $key, $boot, DAY_IN_SECONDS );
		return $boot;
	}
}
