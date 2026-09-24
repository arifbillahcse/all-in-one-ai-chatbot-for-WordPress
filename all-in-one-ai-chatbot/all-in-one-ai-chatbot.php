<?php
/**
 * Plugin Name:       All in One AI Chatbot
 * Plugin URI:        https://softorio.com/
 * Description:       An AI support assistant that answers your visitors from your own posts, pages and knowledge articles. Bring your own OpenAI, Anthropic Claude or DeepSeek API key.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Softorio
 * Author URI:        https://softorio.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       all-in-one-ai-chatbot
 *
 * @package Softorio\AiAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'SOFTORIO_AI_VERSION', '1.0.0' );
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

require_once SOFTORIO_AI_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( \Softorio\AiAssistant\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Softorio\AiAssistant\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Softorio\AiAssistant\Plugin::class, 'boot' ) );
