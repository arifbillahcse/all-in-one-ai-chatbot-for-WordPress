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
		add_action( 'admin_menu', array( self::class, 'order' ), 999 );
		add_action( 'admin_init', array( SettingsPage::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'setup_notice' ) );
		LogPage::init();
		LeadsPage::init();
		FlowsPage::init();
		SourcesPage::init();
		LivePage::init();
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

		if ( \Softorio\AiAssistant\Live\LiveChat::enabled() ) {
			$waiting = \Softorio\AiAssistant\Live\LiveChat::waiting_count();

			add_submenu_page(
				self::SLUG,
				__( 'AI Chatbot — Live Chat', 'all-in-one-ai-chatbot' ),
				__( 'Live Chat', 'all-in-one-ai-chatbot' ) . ' <span class="awaiting-mod sai-live-count' . ( 0 === $waiting ? ' count-0' : '' ) . '"><span class="pending-count">' . number_format_i18n( $waiting ) . '</span></span>',
				\Softorio\AiAssistant\Live\LiveChat::CAP,
				self::SLUG . '-live',
				array( LivePage::class, 'render' )
			);
		}

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Knowledge Sources', 'all-in-one-ai-chatbot' ),
			__( 'Knowledge Sources', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-sources',
			array( SourcesPage::class, 'render' ),
			1
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
			__( 'AI Chatbot — Leads', 'all-in-one-ai-chatbot' ),
			__( 'Leads', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-leads',
			array( LeadsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Quick Replies', 'all-in-one-ai-chatbot' ),
			__( 'Quick Replies', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-flows',
			array( FlowsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Settings', 'all-in-one-ai-chatbot' ),
			__( 'Settings', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-settings',
			array( SettingsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'AI Chatbot — Activity Log', 'all-in-one-ai-chatbot' ),
			__( 'Activity Log', 'all-in-one-ai-chatbot' ),
			'manage_options',
			self::SLUG . '-log',
			array( LogPage::class, 'render' )
		);
	}

	/**
	 * Overview first (the top-level "AI Chatbot" link goes to the first
	 * item), then Live Chat, then the rest in the order they were added.
	 */
	public static function order(): void {
		global $submenu;

		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}

		$rank = static fn( array $item ): int => match ( (string) ( $item[2] ?? '' ) ) {
			self::SLUG           => 0,
			self::SLUG . '-live' => 1,
			default              => 2,
		};

		$items = array_values( $submenu[ self::SLUG ] );
		$keyed = array();

		foreach ( $items as $i => $item ) {
			$keyed[] = array( $rank( $item ), $i, $item );
		}

		sort( $keyed );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu.
		$submenu[ self::SLUG ] = array_column( $keyed, 2 );
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

		if ( str_contains( $hook, 'settings' ) ) {
			wp_enqueue_media();
		}

		if ( str_contains( $hook, self::SLUG . '-flows' ) ) {
			wp_enqueue_script( 'softorio-ai-flows', SOFTORIO_AI_URL . 'assets/js/flows.js', array(), SOFTORIO_AI_VERSION, true );
			wp_localize_script(
				'softorio-ai-flows',
				'softorioAiFlows',
				array(
					'actions' => array(
						'reply'  => __( 'Show a reply (and sub-menu)', 'all-in-one-ai-chatbot' ),
						'ask_ai' => __( 'Ask the AI', 'all-in-one-ai-chatbot' ),
						'link'   => __( 'Open a link', 'all-in-one-ai-chatbot' ),
						'lead'   => __( 'Open the lead form', 'all-in-one-ai-chatbot' ),
						'human'  => __( 'Show how to reach a person', 'all-in-one-ai-chatbot' ),
					),
					'i18n'    => array(
						'label'         => __( 'Button text, e.g. 🚚 Delivery', 'all-in-one-ai-chatbot' ),
						'reply'         => __( 'Reply shown when tapped', 'all-in-one-ai-chatbot' ),
						'replyOptional' => __( 'Optional message shown first', 'all-in-one-ai-chatbot' ),
						'prompt'        => __( 'Question for the AI (defaults to the button text)', 'all-in-one-ai-chatbot' ),
						'addChild'      => __( '+ Add sub-button', 'all-in-one-ai-chatbot' ),
						'up'            => __( 'Move up', 'all-in-one-ai-chatbot' ),
						'down'          => __( 'Move down', 'all-in-one-ai-chatbot' ),
						'remove'        => __( 'Delete', 'all-in-one-ai-chatbot' ),
						'confirm'       => __( 'Delete this button and its sub-buttons?', 'all-in-one-ai-chatbot' ),
						'replace'       => __( 'Replace your buttons with the example?', 'all-in-one-ai-chatbot' ),
						'empty'         => __( 'No buttons yet. Add one, or load the example to see how it works.', 'all-in-one-ai-chatbot' ),
					),
				)
			);
		}
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
