<?php
/**
 * Builds the distributable plugin ZIP from an ALLOWLIST of paths.
 *
 * Nothing is copied "except what is excluded": a file only ships if it is listed here, so a stray
 * backup, a test fixture or a credential can never end up in the package by accident.
 *
 * Usage: php bin/build-zip.php [output-dir]     (default: build/)
 *
 * The result is reproducible: entries are sorted and carry a fixed timestamp.
 *
 * @package Krivo\ChatFlow
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );
$slug = 'krivo-chatflow';

// Files and folders that ship. Folders are copied recursively, subject to the filters below.
$allow = array(
	'krivo-chatflow.php',
	'uninstall.php',
	'readme.txt',
	'license.txt',
	'includes',
	'assets',
	'languages',
	// The human-readable source of every compiled file in assets/, and what is needed to rebuild it.
	'src',
	'bin/build.mjs',
	'package.json',
	'package-lock.json',
	'docs/BUILD.md',
	'docs/HOOKS.md',
	'docs/EVENTS.md',
	'docs/EXTERNAL-SERVICES.md',
);

// Never ships, wherever it is found inside an allowed folder.
$deny = '#(^|/)(\.[^/]+|Thumbs\.db|[^/]+\.(map|log|bak|orig|swp|tmp|zip|psd|ai|sketch)|node_modules|vendor|tests?|__pycache__)(/|$)#i';

$version = null;
if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents( $root . '/krivo-chatflow.php' ), $m ) ) {
	$version = $m[1];
}
if ( null === $version ) {
	fwrite( STDERR, "Could not read the version from krivo-chatflow.php\n" );
	exit( 1 );
}

$out_dir = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : $root . '/build';
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0775, true ) ) {
	fwrite( STDERR, "Cannot create $out_dir\n" );
	exit( 1 );
}
$zip_path = $out_dir . '/' . $slug . '-' . $version . '.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$files = array();
foreach ( $allow as $entry ) {
	$path = $root . '/' . $entry;
	if ( is_file( $path ) ) {
		$files[ $entry ] = $path;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		fwrite( STDERR, "Listed but missing: $entry\n" );
		exit( 1 );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$relative = ltrim( substr( $file->getPathname(), strlen( $root ) ), '/' );
		if ( preg_match( $deny, $relative ) ) {
			continue;
		}
		$files[ $relative ] = $file->getPathname();
	}
}
ksort( $files, SORT_STRING );

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot write $zip_path\n" );
	exit( 1 );
}
foreach ( $files as $relative => $absolute ) {
	$name = $slug . '/' . $relative;
	$zip->addFile( $absolute, $name );
	$zip->setMtimeName( $name, 1767225600 ); // 2026-01-01 00:00:00 UTC: the same bytes on every build.
	$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 0100644 << 16 ) );
}
$zip->close();

printf( "%s  (%d files, %s KB)\n", $zip_path, count( $files ), number_format( filesize( $zip_path ) / 1024, 1 ) );
