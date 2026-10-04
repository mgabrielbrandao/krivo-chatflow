<?php
/**
 * Table names.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the plugin's table names with the current site's prefix (multisite safe).
 */
final class Tables {

	/**
	 * Flows table.
	 *
	 * @return string
	 */
	public static function flows(): string {
		global $wpdb;
		return $wpdb->prefix . 'krivo_chatflow_flows';
	}

	/**
	 * Leads table.
	 *
	 * @return string
	 */
	public static function leads(): string {
		global $wpdb;
		return $wpdb->prefix . 'krivo_chatflow_leads';
	}

	/**
	 * Automation runs table (queue and log).
	 *
	 * @return string
	 */
	public static function runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'krivo_chatflow_runs';
	}

	/**
	 * Daily stats table.
	 *
	 * @return string
	 */
	public static function stats(): string {
		global $wpdb;
		return $wpdb->prefix . 'krivo_chatflow_stats';
	}

	/**
	 * All table names.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::flows(), self::leads(), self::runs(), self::stats() );
	}
}
