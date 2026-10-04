<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite (wp-phpunit) with the plugin active.
 *
 * Environment:
 *   WP_CORE_DIR  Path to a WordPress checkout (default: ../wp-core relative to WP_CORE_DIR env, required).
 *   DB_*         Optional database overrides, see wp-tests-config.php.
 *
 * @package Krivo\ChatFlow
 */

$krivo_plugin_dir = dirname( __DIR__, 2 );
$krivo_tests_dir  = $krivo_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

if ( ! is_dir( $krivo_tests_dir ) ) {
	fwrite( STDERR, "Run `composer install` first (wp-phpunit not found).\n" );
	exit( 1 );
}

if ( ! getenv( 'WP_CORE_DIR' ) ) {
	fwrite( STDERR, "Set WP_CORE_DIR to a WordPress checkout.\n" );
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $krivo_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $krivo_plugin_dir ) {
		require $krivo_plugin_dir . '/krivo-chatflow.php';
	}
);

require $krivo_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Krivo_TestCase.php';
