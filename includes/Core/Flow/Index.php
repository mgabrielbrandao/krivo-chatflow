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
				'boot'     => Manifest::boot( $id, (int) $row['revision'], $config ),
			);
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
}
