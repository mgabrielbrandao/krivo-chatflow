<?php
/**
 * PSR-4 style autoloader for the Krivo\ChatFlow namespace.
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Krivo\ChatFlow\Foo\Bar to includes/Foo/Bar.php.
 *
 * The plugin deliberately does not ship Composer's autoloader: no vendor directory,
 * no third-party licences, no version clashes with other plugins.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this loader.
	 */
	private const PREFIX = 'Krivo\\ChatFlow\\';

	/**
	 * Absolute path to the includes directory (with trailing slash).
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base Absolute path to the includes directory.
	 * @return void
	 */
	public static function register( string $base ): void {
		self::$base = $base;
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file when the class belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = self::$base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
