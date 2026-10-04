<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Data is removed only if the administrator enabled "Delete all Krivo ChatFlow data on uninstall".
 *
 * @package Krivo\ChatFlow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/Autoloader.php';
\Krivo\ChatFlow\Autoloader::register( plugin_dir_path( __FILE__ ) . 'includes/' );

\Krivo\ChatFlow\Infrastructure\Db\Uninstaller::run();
