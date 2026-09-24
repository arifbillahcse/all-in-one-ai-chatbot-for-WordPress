<?php
/**
 * Class autoloader.
 *
 * Softorio\AiAssistant\Knowledge\Indexer -> includes/Knowledge/Indexer.php
 *
 * Hand-written rather than Composer's so the plugin has no build step and no
 * vendor directory to ship.
 *
 * @package Softorio\AiAssistant
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Softorio\\AiAssistant\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$path     = __DIR__ . '/' . $relative . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);
