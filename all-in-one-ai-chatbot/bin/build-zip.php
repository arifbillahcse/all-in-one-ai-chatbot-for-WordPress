<?php
/**
 * Build the installable plugin zip.
 *
 *     php bin/build-zip.php
 *
 * Writes dist/all-in-one-ai-chatbot-<version>.zip (at the repository root),
 * containing only what a site needs: tests and build tooling are left out.
 *
 * @package Softorio\AiAssistant
 */

// phpcs:disable

$root    = dirname( __DIR__ );
$slug    = 'all-in-one-ai-chatbot';
$header  = (string) file_get_contents( $root . '/' . $slug . '.php' );
$version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $m ) ? $m[1] : 'dev';
$exclude = array( 'tests', 'bin', '.git', '.DS_Store' );
$dist    = dirname( $root ) . '/dist';
$target  = "$dist/$slug-$version.zip";

if ( ! is_dir( $dist ) && ! mkdir( $dist, 0755, true ) ) {
	fwrite( STDERR, "Cannot create $dist\n" );
	exit( 1 );
}

@unlink( $target );

$zip = new ZipArchive();

if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot write $target\n" );
	exit( 1 );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$count = 0;

foreach ( $files as $file ) {
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	$first    = explode( '/', $relative )[0];

	if ( in_array( $first, $exclude, true ) || in_array( $file->getFilename(), $exclude, true ) ) {
		continue;
	}

	$zip->addFile( $file->getPathname(), "$slug/$relative" );
	++$count;
}

$zip->close();

echo "Built $target ($count files)\n";
