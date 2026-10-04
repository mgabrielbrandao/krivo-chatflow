<?php
/**
 * Test-only webhook receiver, run with PHP's built-in server by StubServer.
 *
 * Every request is appended to a log file (path in KRIVO_STUB_LOG) and answered according to its path.
 * Never shipped, never exposed: it exists to exercise real HTTP behaviour (status codes, redirects,
 * timeouts, authentication, signatures) against the plugin's HTTP client.
 */

$path    = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body    = (string) file_get_contents( 'php://input' );
$headers = array();
foreach ( $_SERVER as $key => $value ) {
	if ( 0 === strpos( $key, 'HTTP_' ) ) {
		$headers[ strtolower( str_replace( '_', '-', substr( $key, 5 ) ) ) ] = $value;
	}
}
if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
	$headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$log = getenv( 'KRIVO_STUB_LOG' );
if ( $log ) {
	file_put_contents(
		$log,
		json_encode(
			array(
				'path'    => $path,
				'method'  => $_SERVER['REQUEST_METHOD'],
				'headers' => $headers,
				'body'    => $body,
			)
		) . "\n",
		FILE_APPEND | LOCK_EX
	);
}

header( 'Content-Type: application/json' );

switch ( $path ) {
	case '/created':
		http_response_code( 201 );
		echo '{"created":true}';
		break;
	case '/500':
		http_response_code( 500 );
		echo '{"error":"boom secret-token-in-body"}';
		break;
	case '/503':
		http_response_code( 503 );
		header( 'Retry-After: 120' );
		break;
	case '/429':
		http_response_code( 429 );
		header( 'Retry-After: 30' );
		break;
	case '/400':
		http_response_code( 400 );
		echo '{"error":"bad"}';
		break;
	case '/redirect':
		http_response_code( 302 );
		header( 'Location: /ok' );
		break;
	case '/slow':
		sleep( 3 );
		echo '{"ok":true}';
		break;
	case '/auth':
		if ( ( $headers['authorization'] ?? '' ) === 'Bearer test-token' ) {
			echo '{"ok":true}';
		} else {
			http_response_code( 401 );
		}
		break;
	case '/signed':
		$secret = 'shh-secret';
		$sig    = $headers['x-krivochatflow-signature'] ?? '';
		$valid  = preg_match( '/^t=(\d+),v1=([a-f0-9]{64})$/', $sig, $m ) && hash_equals( hash_hmac( 'sha256', $m[1] . '.' . $body, $secret ), $m[2] );
		if ( $valid ) {
			echo '{"ok":true}';
		} else {
			http_response_code( 401 );
		}
		break;
	case '/ok':
	default:
		echo '{"ok":true}';
}
