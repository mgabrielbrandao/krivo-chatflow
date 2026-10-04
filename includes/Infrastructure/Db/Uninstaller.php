<?php
/**
 * Uninstall routine.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Db;

use Krivo\ChatFlow\Infrastructure\Caps\Capabilities;
use Krivo\ChatFlow\Infrastructure\Cron\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin's data on uninstall, but only when the administrator opted in with
 * "Delete all Krivo ChatFlow data on uninstall". Otherwise nothing is deleted.
 */
final class Uninstaller {

	/**
	 * Options owned by the plugin.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		'krivo_chatflow_settings',
		'krivo_chatflow_email',
		'krivo_chatflow_secrets',
		'krivo_chatflow_routing',
		'krivo_chatflow_integrations',
		'krivo_chatflow_index',
		'krivo_chatflow_onboarding',
		'krivo_chatflow_db_version',
		'krivo_chatflow_migrating',
		'krivo_chatflow_queue_lock',
	);

	/**
	 * Runs the uninstall for the whole install (every site on multisite).
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run_site();
				restore_current_blog();
			}
			return;
		}
		self::run_site();
	}

	/**
	 * Uninstalls the current site, honouring its data-removal setting.
	 *
	 * @return bool True when data was removed.
	 */
	public static function run_site(): bool {
		global $wpdb;

		$settings = get_option( 'krivo_chatflow_settings', array() );
		if ( empty( $settings['privacy']['delete_on_uninstall'] ) ) {
			Events::unschedule();
			return false;
		}

		Events::unschedule();

		foreach ( Tables::all() as $table ) {
			// Table names are built from $wpdb->prefix and constants; nothing user-controlled.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		// Transients (rate limits, duplicate fingerprints, preview tokens).
		$like = $wpdb->esc_like( '_transient_krivo_chatflow_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$like = $wpdb->esc_like( '_transient_timeout_krivo_chatflow_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		delete_metadata( 'user', 0, 'krivo_chatflow_dismissed', '', true );

		Capabilities::revoke_all();
		return true;
	}
}
