<?php
/**
 * Starts and stops the test webhook receiver.
 *
 * @package Krivo\ChatFlow
 */

/**
 * Wraps PHP's built-in web server so tests can talk real HTTP to a local receiver.
 */
final class StubServer {

	public const HOST = '127.0.0.1';

	/**
	 * Port chosen for this run.
	 *
	 * @var int
	 */
	private static $port = 0;

	/**
	 * Server process.
	 *
	 * @var resource|null
	 */
	private static $process = null;

	/**
	 * Request log file.
	 *
	 * @var string
	 */
	private static $log = '';

	/**
	 * Starts the server (idempotent).
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( null !== self::$process ) {
			return;
		}
		$probe = stream_socket_server( 'tcp://' . self::HOST . ':0', $errno, $errstr );
		if ( ! $probe ) {
			throw new RuntimeException( 'No free port for the test webhook receiver.' );
		}
		self::$port = (int) substr( strrchr( (string) stream_socket_get_name( $probe, false ), ':' ), 1 );
		fclose( $probe );

		self::$log = sys_get_temp_dir() . '/krivo-stub-' . getmypid() . '.log';
		@unlink( self::$log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$cmd           = sprintf( 'exec php -S %s:%d %s', self::HOST, self::$port, escapeshellarg( __DIR__ . '/stub/receiver.php' ) );
		$descriptors   = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'file', '/dev/null', 'w' ),
			2 => array( 'file', '/dev/null', 'w' ),
		);
		self::$process = proc_open( $cmd, $descriptors, $pipes, null, array_merge( $_ENV, array( 'KRIVO_STUB_LOG' => self::$log, 'PATH' => (string) getenv( 'PATH' ), 'PHP_CLI_SERVER_WORKERS' => '4' ) ) );

		for ( $i = 0; $i < 50; $i++ ) {
			$sock = @fsockopen( self::HOST, self::$port, $errno, $errstr, 0.2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $sock ) {
				fclose( $sock );
				return;
			}
			usleep( 100000 );
		}
		throw new RuntimeException( 'Test webhook receiver did not start.' );
	}

	/**
	 * Stops the server.
	 *
	 * @return void
	 */
	public static function stop(): void {
		if ( null !== self::$process ) {
			proc_terminate( self::$process );
			proc_close( self::$process );
			self::$process = null;
		}
		if ( '' !== self::$log ) {
			@unlink( self::$log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Port of the receiver.
	 *
	 * @return int
	 */
	public static function port(): int {
		return self::$port;
	}

	/**
	 * Base URL of the receiver.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function url( string $path = '/ok' ): string {
		return 'http://' . self::HOST . ':' . self::$port . $path;
	}

	/**
	 * Requests received so far.
	 *
	 * @return array[]
	 */
	public static function requests(): array {
		if ( '' === self::$log || ! file_exists( self::$log ) ) {
			return array();
		}
		$lines = array_filter( explode( "\n", (string) file_get_contents( self::$log ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array_map(
			static function ( $line ) {
				return json_decode( $line, true );
			},
			array_values( $lines )
		);
	}

	/**
	 * Forgets the received requests.
	 *
	 * @return void
	 */
	public static function clear(): void {
		if ( '' !== self::$log ) {
			file_put_contents( self::$log, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
