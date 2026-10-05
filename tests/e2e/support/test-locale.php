<?php
// Test site only: lets the browser tests switch the site language without installing WordPress language packs.
$krivo_locale = get_option( 'krivo_test_locale' );
if ( $krivo_locale ) {
	add_filter( 'locale', static function () use ( $krivo_locale ) { return $krivo_locale; } );
	add_filter( 'pre_determine_locale', static function () use ( $krivo_locale ) { return $krivo_locale; } );
}
