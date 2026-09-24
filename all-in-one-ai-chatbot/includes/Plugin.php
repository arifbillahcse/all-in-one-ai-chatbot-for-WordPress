<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every hook. The only entry point after the main plugin file.
 */
final class Plugin {

	/**
	 * Boot on plugins_loaded.
	 */
	public static function boot(): void {
		Installer::maybe_upgrade();

		PostTypes::init();
		Knowledge\Indexer::init();
		Cron::init();
		Support\Queue::init();

		/**
		 * Fires once the core is loaded: feature modules register their
		 * settings, event listeners and queue handlers here.
		 */
		do_action( 'softorio_ai_loaded' );

		add_action( 'rest_api_init', array( Rest\ChatController::class, 'register' ) );

		if ( is_admin() ) {
			Admin\Menu::init();
			Admin\Ajax::init();
			Admin\Privacy::init();
		} else {
			Frontend\Widget::init();
		}
	}
}
