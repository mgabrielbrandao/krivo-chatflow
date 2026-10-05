<?php
/**
 * Release check: runs over the ZIP that would be published and fails when anything a WordPress.org
 * reviewer (or a careful administrator) would object to is found. It also encodes, as automatic
 * checks, the problems a previous Krivo plugin was sent back for, so they cannot come back.
 *
 * Usage: php bin/release-check.php [path/to/krivo-chatflow-x.y.z.zip]
 *        (default: the newest ZIP in build/)
 *
 * Exit code 0 = every check passed. Anything else = do not publish.
 *
 * @package Krivo\ChatFlow
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$zip  = $argv[1] ?? null;
if ( null === $zip ) {
	$found = glob( $root . '/build/krivo-chatflow-*.zip' );
	rsort( $found );
	$zip = $found[0] ?? null;
}
if ( null === $zip || ! is_file( $zip ) ) {
	fwrite( STDERR, "No ZIP to check. Run: php bin/build-zip.php\n" );
	exit( 2 );
}

// ------------------------------------------------------------------------------------------ helpers

$failures = 0;
$checks   = 0;

function check( string $name, bool $ok, string $detail = '' ): void {
	global $failures, $checks;
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	printf( "%s  %s%s\n", $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $name, ( ! $ok && '' !== $detail ) ? "\n        " . str_replace( "\n", "\n        ", trim( $detail ) ) : '' );
}

function section( string $title ): void {
	echo "\n\033[1m" . $title . "\033[0m\n";
}

/**
 * Lines of every file (matching $glob_regex) that match $pattern.
 *
 * @return string[] "path:line: text"
 */
function grep_files( array $files, string $glob_regex, string $pattern, array $skip = array() ): array {
	$hits = array();
	foreach ( $files as $relative => $absolute ) {
		if ( ! preg_match( $glob_regex, $relative ) ) {
			continue;
		}
		foreach ( $skip as $skip_regex ) {
			if ( preg_match( $skip_regex, $relative ) ) {
				continue 2;
			}
		}
		foreach ( file( $absolute ) as $i => $line ) {
			if ( preg_match( $pattern, $line ) ) {
				$hits[] = $relative . ':' . ( $i + 1 ) . ': ' . trim( substr( $line, 0, 140 ) );
			}
		}
	}
	return $hits;
}

function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

// ------------------------------------------------------------------------------------------ unpack

$tmp = sys_get_temp_dir() . '/krivo-release-check-' . getmypid();
rrmdir( $tmp );
mkdir( $tmp, 0775, true );
register_shutdown_function( static function () use ( $tmp ) {
	rrmdir( $tmp );
} );

$archive = new ZipArchive();
if ( true !== $archive->open( $zip ) ) {
	fwrite( STDERR, "Cannot open $zip\n" );
	exit( 2 );
}
$archive->extractTo( $tmp );
$names = array();
for ( $i = 0; $i < $archive->numFiles; $i++ ) {
	$names[] = $archive->getNameIndex( $i );
}
$archive->close();

$top = 'krivo-chatflow';
$dir = $tmp . '/' . $top;
printf( "Checking %s\n", $zip );

$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() ) {
		$files[ ltrim( substr( $file->getPathname(), strlen( $dir ) ), '/' ) ] = $file->getPathname();
	}
}
ksort( $files );

$php_files = array_filter( $files, static fn( $k ) => str_ends_with( $k, '.php' ), ARRAY_FILTER_USE_KEY );
$main      = (string) file_get_contents( $dir . '/krivo-chatflow.php' );
$readme    = is_file( $dir . '/readme.txt' ) ? (string) file_get_contents( $dir . '/readme.txt' ) : '';

// ------------------------------------------------------------------------------------------ 1. package

section( '1. Package' );
check( 'every entry lives in the single folder krivo-chatflow/', 0 === count( array_filter( $names, static fn( $n ) => 0 !== strpos( $n, $top . '/' ) ) ) );
check( 'main plugin file, readme.txt, license.txt and uninstall.php are present', isset( $files['krivo-chatflow.php'], $files['readme.txt'], $files['license.txt'], $files['uninstall.php'] ) );

$dev = array_filter( array_keys( $files ), static fn( $f ) => preg_match( '#(^|/)(\.|node_modules/|vendor/|tests?/|composer\.|phpunit|phpcs|\.git|[^/]+\.(log|bak|orig|swp|tmp|zip|map)$|Thumbs\.db$|brand/|build/)#i', $f ) );
check( 'no development, temporary or backup files (tests, vendor, node_modules, dotfiles, logs, maps, brand sources)', array() === $dev, implode( "\n", $dev ) );
check( 'ZIP is small (< 3 MB)', filesize( $zip ) < 3 * 1024 * 1024, number_format( filesize( $zip ) / 1024 ) . ' KB' );

// ------------------------------------------------------------------------------------------ 2. versions and headers

section( '2. Headers, versions and readme.txt' );
$header = static function ( string $name ) use ( $main ): string {
	return preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $main, $m ) ? trim( $m[1] ) : '';
};
$version = $header( 'Version' );
$rd      = static function ( string $name ) use ( $readme ): string {
	return preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $readme, $m ) ? trim( $m[1] ) : '';
};
$package_json = json_decode( (string) file_get_contents( $dir . '/package.json' ), true );

check( 'plugin header has Name, Description, Version, Requires at least, Requires PHP, License, License URI, Text Domain', '' !== $header( 'Plugin Name' ) && '' !== $header( 'Description' ) && '' !== $version && '' !== $header( 'Requires at least' ) && '' !== $header( 'Requires PHP' ) && 'GPL-2.0-or-later' === $header( 'License' ) && '' !== $header( 'License URI' ) && 'krivo-chatflow' === $header( 'Text Domain' ) );
check( 'KRIVO_CHATFLOW_VERSION, readme Stable tag and package.json agree with the header (' . $version . ')', str_contains( $main, "define( 'KRIVO_CHATFLOW_VERSION', '$version' )" ) && $rd( 'Stable tag' ) === $version && ( $package_json['version'] ?? '' ) === $version );
check( 'readme.txt: Contributors is exactly gabrielbrandaoai', 'gabrielbrandaoai' === $rd( 'Contributors' ), 'Contributors: ' . $rd( 'Contributors' ) );
check( 'readme.txt: Requires at least / Requires PHP match the plugin header', $rd( 'Requires at least' ) === $header( 'Requires at least' ) && $rd( 'Requires PHP' ) === $header( 'Requires PHP' ) );
check( 'readme.txt: Tested up to, License and License URI are set', '' !== $rd( 'Tested up to' ) && preg_match( '/^GPL(v2| ?2)/i', $rd( 'License' ) ) && '' !== $rd( 'License URI' ) );
$tags = array_filter( array_map( 'trim', explode( ',', $rd( 'Tags' ) ) ) );
check( 'readme.txt: between 1 and 5 tags', count( $tags ) >= 1 && count( $tags ) <= 5, implode( ', ', $tags ) );
preg_match( '/^=== .+? ===\R+(?:[A-Za-z ]+:.*\R)+\R+(.+?)\R\R/s', $readme, $short );
check( 'readme.txt: short description is at most 150 characters', isset( $short[1] ) && strlen( trim( $short[1] ) ) <= 150, isset( $short[1] ) ? strlen( trim( $short[1] ) ) . ' characters' : 'not found' );
foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog', 'Upgrade Notice', 'External services' ) as $sec ) {
	check( "readme.txt has a section '== $sec =='", (bool) preg_match( '/^== ' . preg_quote( $sec, '/' ) . ' ==\s*$/mi', $readme ) );
}
check( 'readme.txt names where the human-readable source lives (src/, BUILD.md and the public repository)', str_contains( $readme, 'src/' ) && str_contains( $readme, 'BUILD.md' ) && 1 === preg_match( '#https://github\.com/[\w.-]+/[\w.-]+#', $readme ) );
check( 'the product is not presented as a WhatsApp product (the plugin name, slug and text domain do not contain a third-party brand)', ! preg_match( '/whatsapp/i', $header( 'Plugin Name' ) . $header( 'Text Domain' ) . $top ) && str_starts_with( $readme, '=== Krivo ChatFlow ===' ) );
check( 'readme.txt says WhatsApp is a trademark of its owner and that the plugin is not affiliated', (bool) preg_match( '/WhatsApp is a trademark.*not affiliated/s', $readme ) );

// ------------------------------------------------------------------------------------------ 3. dangerous code

section( '3. Dangerous or forbidden code (PHP)' );
$deny = array(
	'eval()'                         => '/\beval\s*\(/',
	'create_function / assert()'     => '/\b(create_function|assert)\s*\(/',
	'base64_decode (obfuscation)'    => '/\bbase64_decode\s*\(/',
	'str_rot13 / gzinflate / gzuncompress' => '/\b(str_rot13|gzinflate|gzuncompress|convert_uudecode)\s*\(/',
	'curl_*'                         => '/\bcurl_(init|setopt|exec)\s*\(/',
	'file_get_contents/fopen of a URL' => '/\b(file_get_contents|fopen|readfile)\s*\(\s*[\'"]https?:/',
	'move_uploaded_file / $_FILES'   => '/\bmove_uploaded_file\s*\(|\$_FILES\b/',
	'shell execution'                => '/\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/',
	'debug output'                   => '/\b(var_dump|print_r|var_export|error_log|debug_print_backtrace|xdebug_\w+)\s*\(/',
	'ini_set / error_reporting / set_time_limit' => '/\b(ini_set|error_reporting|set_time_limit)\s*\(/',
	'unserialize() of data'          => '/\bunserialize\s*\(/',
	'$_REQUEST'                      => '/\$_REQUEST\b/',
);
// base64_decode is flagged as a possible obfuscation tool. Two places legitimately decode data that this plugin itself
// encoded (the signed visitor token and the encrypted secret store), each with a documented phpcs:ignore.
$decode_allowed = array( 'includes/Core/Submission/AntiSpam/Token.php', 'includes/Infrastructure/Crypto/Secrets.php' );
foreach ( $deny as $label => $pattern ) {
	$hits = grep_files( $php_files, '/\.php$/', $pattern );
	if ( 0 === strpos( $label, 'base64_decode' ) ) {
		$hits = array_filter( $hits, static fn( $h ) => ! ( in_array( strstr( $h, ':', true ), $decode_allowed, true ) && str_contains( $h, 'phpcs:ignore' ) ) );
		$label = 'base64_decode outside the two documented places (signed token, secret store)';
	}
	check( "no $label", array() === $hits, implode( "\n", $hits ) );
}
$short_tags = grep_files( $php_files, '/\.php$/', '/<\?(?!php|=)/' );
check( 'no PHP short open tags', array() === $short_tags, implode( "\n", $short_tags ) );

$guards = array();
foreach ( $php_files as $relative => $absolute ) {
	$head = implode( '', array_slice( file( $absolute ), 0, 40 ) );
	if ( 'uninstall.php' === $relative ) {
		if ( ! str_contains( $head, 'WP_UNINSTALL_PLUGIN' ) ) {
			$guards[] = $relative;
		}
	} elseif ( ! str_contains( $head, "defined( 'ABSPATH' )" ) ) {
		$guards[] = $relative;
	}
}
check( "every PHP file refuses direct access (ABSPATH / WP_UNINSTALL_PLUGIN guard)", array() === $guards, implode( "\n", $guards ) );

$todo = grep_files( $files, '/\.(php|js|css|jsx|mjs)$/', '/\b(TODO|FIXME|XXX|HACK)\b|console\.(log|debug|trace)|debugger;/', array( '#^assets/#', '#^bin/#' ) ); // bin/ holds command-line tools, which print to the terminal.
$todo = array_merge( $todo, grep_files( $files, '#^assets/.*\.js$#', '/console\.(log|debug|trace)\(|debugger;/' ) );
check( 'no TODO/FIXME, console.log or debugger statements in shipped code', array() === $todo, implode( "\n", $todo ) );

$secrets = grep_files( $files, '/\.(php|js|jsx|json|txt|md|css|mjs)$/', '/(AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|sk_live_[0-9a-zA-Z]+|xox[bp]-[0-9A-Za-z-]+|ghp_[0-9A-Za-z]{30,}|api[_-]?key\s*[=:]\s*[\'"][0-9A-Za-z]{16,})/i' );
check( 'no credentials, private keys or tokens', array() === $secrets, implode( "\n", $secrets ) );

$tmp_hits = array_filter( array_keys( $files ), static fn( $f ) => preg_match( '/\.(env|pem|key|sql|sqlite|db)$/i', $f ) );
check( 'no .env, key, SQL or database files', array() === $tmp_hits, implode( "\n", $tmp_hits ) );

// ------------------------------------------------------------------------------------------ 4. WordPress APIs

section( '4. WordPress APIs: assets, requests, permissions, SQL' );
$literal_assets = grep_files( $php_files, '/\.php$/', '/<(script|link|style)\b/i' );
$literal_assets = array_filter( $literal_assets, static fn( $h ) => ! preg_match( '/^includes\/Frontend\/Icons\.php/', $h ) );
check( 'no <script>, <style> or <link> printed by PHP (everything goes through wp_enqueue_* / wp_add_inline_* / wp_print_inline_script_tag)', array() === $literal_assets, implode( "\n", $literal_assets ) );
$enqueue = grep_files( $php_files, '/\.php$/', '/\bwp_enqueue_(script|style)\s*\(/' );
check( 'assets are registered with wp_enqueue_script / wp_enqueue_style', count( $enqueue ) >= 4 );

$routes_without_permission = array();
foreach ( $php_files as $relative => $absolute ) {
	$code = (string) file_get_contents( $absolute );
	if ( ! preg_match_all( '/register_rest_route\s*\(/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $m[0] as $match ) {
		$chunk = substr( $code, $match[1], 2500 );
		$until = preg_split( '/\n\t\t\);\n/', $chunk )[0] ?? $chunk;
		if ( ! str_contains( $until, 'permission_callback' ) ) {
			$routes_without_permission[] = $relative . ' near byte ' . $match[1];
		}
	}
}
check( 'every REST route declares a permission_callback', array() === $routes_without_permission, implode( "\n", $routes_without_permission ) );
$return_true = grep_files( $php_files, '/\.php$/', "/permission_callback'\s*=>\s*'__return_true'/" );
check( "no permission_callback is '__return_true' (public routes use an explicit Origin/token check)", array() === $return_true, implode( "\n", $return_true ) );

$handlers = array();
foreach ( $php_files as $relative => $absolute ) {
	$code = (string) file_get_contents( $absolute );
	if ( preg_match( '/add_action\(\s*[\'"](wp_ajax_|admin_post_)/', $code ) ) {
		$handlers[ $relative ] = $code;
	}
}
$unprotected = array();
foreach ( $handlers as $relative => $code ) {
	$has_nonce = (bool) preg_match( '/check_admin_referer|check_ajax_referer|wp_verify_nonce|Token::verify/', $code );
	$has_cap   = (bool) preg_match( '/current_user_can|Capabilities::first_granted|Capabilities::can/', $code );
	$is_public = str_contains( $code, 'wp_ajax_nopriv_' );
	if ( ! $has_nonce && ! $is_public ) {
		$unprotected[] = "$relative: admin-ajax/admin-post handler without a nonce check";
	}
	if ( ! $has_cap && ! $is_public ) {
		$unprotected[] = "$relative: admin-ajax/admin-post handler without a capability check";
	}
}
check( 'every admin-ajax / admin-post handler checks a nonce AND a capability (public ones use the signed token)', array() === $unprotected, implode( "\n", $unprotected ) );
check( 'the public admin-ajax fallback verifies the signed token and the Origin', (bool) preg_match( '/Guard::|Token::|origin_ok/', (string) @file_get_contents( $dir . '/includes/Rest/AjaxFallback.php' ) ) );

$menu_calls = grep_files( $php_files, '/\.php$/', '/\badd_menu_page\s*\(/' );
check( 'the top-level admin menu is registered (add_menu_page)', count( $menu_calls ) >= 1 );
$menu_code = (string) file_get_contents( $dir . '/includes/Admin/Menu.php' );
check( 'the admin menu does not claim a fixed numeric position (it cannot displace WordPress or other plugins)', 1 === preg_match( '/add_menu_page\(.*?,\s*null\s*\)/s', $menu_code ), 'add_menu_page() must end with a null position' );

$http = grep_files( $php_files, '/\.php$/', '/\bwp_(safe_)?remote_(get|post|request)\s*\(|\bwp_remote_/' );
$http_outside = array_filter( $http, static fn( $h ) => ! preg_match( '/^includes\/Infrastructure\/Http\/SafeClient\.php/', $h ) );
check( 'the only code that makes HTTP requests is Infrastructure/Http/SafeClient.php (SSRF-safe, used by webhooks only)', array() === $http_outside, implode( "\n", $http_outside ) );
$client_users = grep_files( $php_files, '/\.php$/', '/SafeClient::/' );
$client_outside = array_filter( $client_users, static fn( $h ) => ! preg_match( '/^includes\/(Automation\/Webhook\/|Infrastructure\/Http\/)/', $h ) );
check( 'SafeClient is used only by the webhook module (no other code can reach the network)', array() === $client_outside, implode( "\n", $client_outside ) );

$ext_in_assets = array();
foreach ( $files as $relative => $absolute ) {
	if ( ! preg_match( '#^assets/.*\.(js|css)$#', $relative ) ) {
		continue;
	}
	preg_match_all( '#https?://[^\s"\'`)<>\\\\]+#', (string) file_get_contents( $absolute ), $urls );
	foreach ( array_unique( $urls[0] ) as $url ) {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'www.w3.org', 'wa.me' ), true ) ) {
			$ext_in_assets[] = "$relative: $url";
		}
	}
}
check( 'compiled JS/CSS reference no external hosts (only the SVG namespace and the wa.me link)', array() === $ext_in_assets, implode( "\n", $ext_in_assets ) );
$ext_loads = grep_files( $files, '#^(assets|src)/.*\.(js|jsx|css)$#', '/(@import\s+url|url\(\s*[\'"]?https?:|src\s*=\s*[\'"]https?:|\bimport\(\s*[\'"]https?:|fonts\.googleapis|cdn\.)/i' );
check( 'no remote fonts, imports or CDN files are loaded', array() === $ext_loads, implode( "\n", $ext_loads ) );

// ------------------------------------------------------------------------------------------ 5. external services

section( '5. External services are documented, with consent' );
$hosts = array();
foreach ( $php_files as $relative => $absolute ) {
	preg_match_all( '#https?://[A-Za-z0-9.-]+#', (string) file_get_contents( $absolute ), $urls );
	foreach ( $urls[0] as $url ) {
		$hosts[ (string) parse_url( $url, PHP_URL_HOST ) ][] = $relative;
	}
}
// example.invalid is the reserved name (RFC 2606) used for fake sample data in the dry run; it can never resolve.
$allowed_hosts = array( 'www.w3.org', 'wa.me', 'krivo.works', 'www.gnu.org', 'wordpress.org', 'api.w.org', 'localhost', 'example.invalid' );
$unknown       = array();
foreach ( $hosts as $host => $in ) {
	// A sentence that ends with "https://." is punctuation, not a host: a host has at least one letter or digit.
	if ( 1 === preg_match( '/[A-Za-z0-9]/', (string) $host ) && ! in_array( $host, $allowed_hosts, true ) ) {
		$unknown[] = $host . '  (' . implode( ', ', array_unique( $in ) ) . ')';
	}
}
check( 'PHP code mentions no hosts other than the documented ones', array() === $unknown, implode( "\n", $unknown ) );
$services_doc = (string) @file_get_contents( $dir . '/docs/EXTERNAL-SERVICES.md' );
foreach ( array( 'wa.me', 'krivo.works' ) as $host ) {
	check( "readme.txt and EXTERNAL-SERVICES.md both document $host", str_contains( $readme, $host ) && str_contains( $services_doc, $host ) );
}
$ext_section = preg_match( '/^== External services ==\s*$(.*?)(?=^== |\z)/ms', $readme, $es ) ? $es[1] : '';
foreach ( array( 'WhatsApp', 'n8n', 'Make', 'Zapier', 'SMTP' ) as $service ) {
	check( "readme.txt External services names $service", str_contains( $ext_section, $service ) );
}
check( 'for each service the readme states: what, when, data sent, official site, Terms and Privacy Policy', substr_count( $ext_section, 'Terms' ) >= 3 && substr_count( $ext_section, 'Privacy' ) >= 3 && substr_count( $ext_section, 'https://' ) >= 8 );
$placeholders = grep_files( $files, '#^(readme\.txt|docs/.*\.md|includes/.*\.php)$#', '#(example\.(com|org)/privacy|example\.com/terms|your-?domain\.|lorem ipsum|placeholder\.com)#i' );
check( 'no placeholder legal URLs', array() === $placeholders, implode( "\n", $placeholders ) );
$webhook_gate = (string) file_get_contents( $dir . '/includes/Automation/Webhook/ConnectionRepository.php' );
check( 'a webhook connection cannot be enabled without the administrator acknowledging which data is sent', str_contains( $webhook_gate, 'acknowledged' ) && str_contains( $webhook_gate, 'krivo_chatflow_ack' ) );
$telemetry = grep_files( $php_files, '/\.php$/', '/(api\.krivo|krivo\.works\/(api|track|collect|ping)|phone[_ ]home|telemetry|google-analytics|googletagmanager)/i' );
check( 'no telemetry, tracking or "phone home" code', array() === $telemetry, implode( "\n", $telemetry ) );

// ------------------------------------------------------------------------------------------ 6. build and sources

section( '6. Compiled files come from the shipped, readable source' );
check( 'src/ is shipped with the build script, package.json and the lock file', is_dir( $dir . '/src' ) && isset( $files['bin/build.mjs'], $files['package.json'], $files['package-lock.json'] ) );
check( 'BUILD.md exists and states the Node version, the tool and the commands', isset( $files['docs/BUILD.md'] ) && 1 === preg_match( '/Node/i', (string) @file_get_contents( $dir . '/docs/BUILD.md' ) ) && str_contains( (string) @file_get_contents( $dir . '/docs/BUILD.md' ), 'npm run build' ) );
$maps = grep_files( $files, '#^assets/.*\.(js|css)$#', '/sourceMappingURL/' );
check( 'no dangling source-map comments in the bundles', array() === $maps, implode( "\n", $maps ) );
$bundles = array_filter( array_keys( $files ), static fn( $f ) => preg_match( '#^assets/(admin|frontend)/.*\.(js|css)$#', $f ) );
$obfuscated = array();
foreach ( $bundles as $bundle ) {
	$code = (string) file_get_contents( $files[ $bundle ] );
	if ( preg_match( '/\b_0x[0-9a-f]{4,}\b|\beval\s*\(|\bFunction\s*\(\s*[\'"]|atob\s*\(|String\.fromCharCode\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+/', $code ) ) {
		$obfuscated[] = $bundle;
	}
}
check( 'no obfuscation patterns in the compiled files (hex identifiers, eval, Function(), atob)', array() === $obfuscated, implode( "\n", $obfuscated ) );

$build_out = $tmp . '/rebuild';
mkdir( $build_out, 0775, true );
$cmd = sprintf( 'cd %s && KRIVO_BUILD_OUT=%s node bin/build.mjs 2>&1', escapeshellarg( $root ), escapeshellarg( $build_out ) );
exec( $cmd, $build_log, $build_status );
if ( 0 !== $build_status ) {
	check( 'the shipped sources build (node bin/build.mjs)', false, implode( "\n", $build_log ) );
} else {
	$diff = array();
	foreach ( $bundles as $bundle ) {
		$rebuilt = $build_out . '/' . $bundle;
		if ( ! is_file( $rebuilt ) || hash_file( 'sha256', $rebuilt ) !== hash_file( 'sha256', $files[ $bundle ] ) ) {
			$diff[] = $bundle;
		}
	}
	check( 'rebuilding src/ gives byte-for-byte the compiled files in the ZIP (' . count( $bundles ) . ' files)', array() === $diff, "differs:\n" . implode( "\n", $diff ) );
}

// ------------------------------------------------------------------------------------------ 7. i18n, accessibility basics, size

section( '7. Translations and size budget' );
$pot = glob( $dir . '/languages/*.pot' );
check( 'a .pot translation template is shipped', array() !== $pot );
check( 'a Brazilian Portuguese translation (.po and .mo) is shipped', is_file( $dir . '/languages/krivo-chatflow-pt_BR.po' ) && is_file( $dir . '/languages/krivo-chatflow-pt_BR.mo' ) );
$wrong_domain = grep_files( $php_files, '/\.php$/', '/\b(__|_e|esc_html__|esc_attr__|_x|_n)\(\s*\'[^\']+\'\s*,\s*\'(?!krivo-chatflow\')[a-z-]+\'/' );
check( "every translatable PHP string uses the text domain 'krivo-chatflow'", array() === $wrong_domain, implode( "\n", $wrong_domain ) );

exec( sprintf( 'cd %s && node bin/size-budget.mjs 2>&1', escapeshellarg( $root ) ), $size_log, $size_status );
check( 'front-end and admin bundles are within their size budget', 0 === $size_status, implode( "\n", $size_log ) );

// ------------------------------------------------------------------------------------------ 8. coding standards

section( '8. WordPress Coding Standards (PHPCS: security, escaping, SQL, i18n, compatibility)' );
$phpcs = $root . '/vendor/bin/phpcs';
if ( is_file( $phpcs ) ) {
	exec( sprintf( 'cd %s && %s -q --report=summary 2>&1', escapeshellarg( $root ), escapeshellarg( $phpcs ) ), $cs_log, $cs_status );
	check( 'phpcs reports no errors and no warnings', 0 === $cs_status, implode( "\n", array_slice( $cs_log, -12 ) ) );
} else {
	check( 'phpcs is installed (composer install)', false );
}

echo "\n";
printf( "%d checks, %d failed.\n", $checks, $failures );
exit( $failures > 0 ? 1 : 0 );
