<?php
/**
 * WordPress test-suite configuration. The test database is DROPPED AND RECREATED on every run:
 * never point it at a real site.
 *
 * @package Krivo\ChatFlow
 */

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ? getenv( 'WP_TEST_DB_NAME' ) : 'wp_test' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ? getenv( 'WP_TEST_DB_USER' ) : 'wp' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ? getenv( 'WP_TEST_DB_PASSWORD' ) : 'wp' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ? getenv( 'WP_TEST_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Krivo ChatFlow Tests' );

define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
