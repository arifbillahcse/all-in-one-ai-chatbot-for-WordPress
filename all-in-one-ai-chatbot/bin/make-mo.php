<?php
/**
 * Compile languages/*.po into .mo and .l10n.php (the faster format
 * WordPress 6.5+ prefers), using WordPress's own libraries.
 *
 * Usage: WP_PATH=/path/to/wordpress php bin/make-mo.php
 *
 * @package Softorio\AiAssistant
 */

// phpcs:disable

$wp = rtrim( (string) getenv( 'WP_PATH' ), '/' );

if ( '' === $wp || ! is_file( $wp . '/wp-includes/pomo/po.php' ) ) {
	fwrite( STDERR, "Set WP_PATH to a WordPress install.\n" );
	exit( 2 );
}

require_once $wp . '/wp-includes/compat.php'; // Polyfills newer WordPress versions rely on.
require $wp . '/wp-includes/pomo/po.php';
require $wp . '/wp-includes/pomo/mo.php';

$l10n = $wp . '/wp-includes/l10n/class-wp-translation-file.php';

if ( is_file( $l10n ) ) {
	require $l10n;
	require $wp . '/wp-includes/l10n/class-wp-translation-file-mo.php';
	require $wp . '/wp-includes/l10n/class-wp-translation-file-php.php';
}

foreach ( glob( dirname( __DIR__ ) . '/languages/*.po' ) as $po_file ) {
	$po = new PO();

	if ( ! $po->import_from_file( $po_file ) ) {
		fwrite( STDERR, "Could not read $po_file\n" );
		exit( 1 );
	}

	$mo = new MO();
	$mo->set_headers( $po->headers );

	foreach ( $po->entries as $entry ) {
		if ( array() !== array_filter( $entry->translations ) ) {
			$mo->add_entry( $entry );
		}
	}

	$mo_file = substr( $po_file, 0, -3 ) . '.mo';
	$mo->export_to_file( $mo_file );
	echo basename( $mo_file ), ': ', count( $mo->entries ), " strings\n";

	if ( class_exists( 'WP_Translation_File' ) ) {
		$php = WP_Translation_File::transform( $mo_file, 'php' );

		if ( false !== $php ) {
			file_put_contents( substr( $po_file, 0, -3 ) . '.l10n.php', $php );
			echo basename( substr( $po_file, 0, -3 ) . '.l10n.php' ), "\n";
		}
	}
}
