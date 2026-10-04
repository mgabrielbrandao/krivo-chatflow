<?php
/**
 * Activation and deactivation.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Db;

use Krivo\ChatFlow\Infrastructure\Cron\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Activation installs the schema; deactivation only removes scheduled events. Deactivating never
 * deletes data (item 89 of the product brief).
 */
final class Installer {

	public const ACTIVATION_TRANSIENT = 'krivo_chatflow_activation_redirect';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Migrator::upgrade();
		set_transient( self::ACTIVATION_TRANSIENT, 1, 60 );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Events::unschedule();
	}
}
