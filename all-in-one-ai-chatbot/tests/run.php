<?php
/**
 * Test runner.
 *
 * Runs against a real WordPress install, because most of what can go wrong in
 * a plugin is in how it meets WordPress (hooks, dbDelta, REST, options), not in
 * isolated functions.
 *
 * Usage (use a throwaway site — the tests create and delete content):
 *
 *     WP_PATH=/path/to/wordpress php tests/run.php
 *
 * The plugin must be present in that site's plugins directory. No network
 * access is needed: every AI provider call is intercepted with the
 * `pre_http_request` filter.
 *
 * @package Softorio\AiAssistant
 */

// phpcs:disable

$wp_path = getenv( 'WP_PATH' );

if ( ! $wp_path || ! is_file( rtrim( $wp_path, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set WP_PATH to a WordPress install.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

require rtrim( $wp_path, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

final class T {
	public static int $passed = 0;
	public static array $failed = array();
	private static string $current = '';

	public static function test( string $name, callable $fn ): void {
		self::$current = $name;
		try {
			$fn();
			echo "  ✓ $name\n";
		} catch ( Throwable $e ) {
			self::$failed[] = "$name: " . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
			echo "  ✗ $name\n    " . $e->getMessage() . "\n";
		}
	}

	public static function ok( bool $condition, string $message = 'assertion failed' ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
		++self::$passed;
	}

	public static function same( mixed $expected, mixed $actual, string $message = '' ): void {
		self::ok( $expected === $actual, ( '' !== $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$plugin = 'all-in-one-ai-chatbot/all-in-one-ai-chatbot.php';

if ( ! is_plugin_active( $plugin ) ) {
	$result = activate_plugin( $plugin );

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'Could not activate: ' . $result->get_error_message() . "\n" );
		exit( 2 );
	}

	// activate_plugin() includes the file, but plugins_loaded and init have
	// already run. REST routes register themselves when the first REST
	// request builds the server.
	\Softorio\AiAssistant\Plugin::boot();
	\Softorio\AiAssistant\PostTypes::register();
}

foreach ( glob( __DIR__ . '/cases/*.php' ) as $file ) {
	echo "\n" . basename( $file, '.php' ) . "\n";
	require $file;
}

echo "\n" . T::$passed . ' assertions passed, ' . count( T::$failed ) . " test(s) failed.\n";

foreach ( T::$failed as $failure ) {
	echo "  - $failure\n";
}

exit( T::$failed ? 1 : 0 );
