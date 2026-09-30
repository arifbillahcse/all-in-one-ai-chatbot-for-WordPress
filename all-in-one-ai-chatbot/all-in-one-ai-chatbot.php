<?php
/**
 * Plugin Name:       All in One AI Chatbot
 * Plugin URI:        https://softorio.com/
 * Description:       An AI support and sales assistant that answers visitors from your own content, captures leads, helps WooCommerce shoppers, and hands over to your team for live chat. Bring your own OpenAI, Claude, Gemini, DeepSeek or OpenRouter key.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Softorio
 * Author URI:        https://softorio.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       all-in-one-ai-chatbot
 * Domain Path:       /languages
 *
 * @package Softorio\AiAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'SOFTORIO_AI_VERSION', '2.0.0' );
define( 'SOFTORIO_AI_FILE', __FILE__ );
define( 'SOFTORIO_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOFTORIO_AI_URL', plugin_dir_url( __FILE__ ) );

/*
 * Checked before anything else loads: the rest of the plugin uses PHP 8.1
 * syntax, and a parse error on an older runtime would take the whole site down
 * rather than just refusing to start.
 */
if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'All in One AI Chatbot needs PHP 8.1 or newer. Ask your host to upgrade PHP, then reactivate the plugin.', 'all-in-one-ai-chatbot' );
			echo '</p></div>';
		}
	);

	return;
}

/*
 * mbstring handles Bangla and every other non-Latin script. It is on almost
 * every host, but a minimal server without it would otherwise fail with a
 * fatal error in the middle of a visitor's chat.
 */
if ( ! extension_loaded( 'mbstring' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'All in One AI Chatbot needs the PHP "mbstring" extension. Ask your host to enable it (in cPanel: Select PHP Version → Extensions), then reload this page.', 'all-in-one-ai-chatbot' );
			echo '</p></div>';
		}
	);

	return;
}

require_once SOFTORIO_AI_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( \Softorio\AiAssistant\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Softorio\AiAssistant\Installer::class, 'deactivate' ) );

/*
 * Boot on init (early), not plugins_loaded: settings defaults and other
 * strings are translated during boot, and WordPress 6.7+ warns when a
 * plugin's translations are used before init.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'all-in-one-ai-chatbot', false, dirname( plugin_basename( SOFTORIO_AI_FILE ) ) . '/languages' );
		\Softorio\AiAssistant\Plugin::boot();
	},
	0
);

/*
 * Orders are only read through WooCommerce's CRUD API (wc_get_order,
 * wc_get_orders), which works with both order storage systems, and the
 * plugin never touches cart or checkout. Declaring it stops WooCommerce
 * flagging the plugin as incompatible.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
