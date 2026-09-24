<?php
/**
 * Admin menu.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The "AI Chatbot" menu and its pages.
 */
final class Menu {

	public const SLUG = 'softorio-ai';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register' ) );
		add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'setup_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SOFTORIO_AI_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Register menu pages.
	 */
	public static function register(): void {
		add_menu_page(
			__( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
			__( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG,
			array( DashboardPage::class, 'render' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Overview', 'all-in-one-ai-chatbot' ),
			__( 'Overview', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG,
			array( DashboardPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Conversations', 'all-in-one-ai-chatbot' ),
			__( 'Conversations', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-conversations',
			array( ConversationsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Settings', 'all-in-one-ai-chatbot' ),
			__( 'Settings', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-settings',
			array( SettingsPage::class, 'render' )
		);
	}

	/**
	 * Admin CSS/JS, only on the plugin's own screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'softorio-ai-admin', SOFTORIO_AI_URL . 'assets/css/admin.css', array(), SOFTORIO_AI_VERSION );
		wp_enqueue_script( 'softorio-ai-admin', SOFTORIO_AI_URL . 'assets/js/admin.js', array(), SOFTORIO_AI_VERSION, true );

		wp_localize_script(
			'softorio-ai-admin',
			'softorioAiAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					'working'   => __( 'Working…', 'all-in-one-ai-chatbot' ),
					'indexing'  => __( 'Indexing %1$d of %2$d…', 'all-in-one-ai-chatbot' ),
					'indexed'   => __( 'Done. %d items are searchable.', 'all-in-one-ai-chatbot' ),
					'failed'    => __( 'Something went wrong. Please reload the page and try again.', 'all-in-one-ai-chatbot' ),
					'noResults' => __( 'Nothing matched. The assistant would say it does not know.', 'all-in-one-ai-chatbot' ),
					'confirm'   => __( 'Delete this conversation permanently?', 'all-in-one-ai-chatbot' ),
				),
			)
		);
	}

	/**
	 * Nudge the owner to finish setup, on the dashboard and plugin screens only.
	 */
	public static function setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Settings::is_ready() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'All in One AI Chatbot is almost ready. Add an AI provider API key to switch on the chat widget.', 'all-in-one-ai-chatbot' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
			esc_html__( 'Open settings', 'all-in-one-ai-chatbot' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'all-in-one-ai-chatbot' )
			)
		);

		return $links;
	}

	/**
	 * URL of a plugin admin page.
	 *
	 * @param string               $page Suffix after the slug ('' for overview).
	 * @param array<string, mixed> $args Extra query args.
	 */
	public static function url( string $page = '', array $args = array() ): string {
		$slug = '' === $page ? self::SLUG : self::SLUG . '-' . $page;

		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}
}
