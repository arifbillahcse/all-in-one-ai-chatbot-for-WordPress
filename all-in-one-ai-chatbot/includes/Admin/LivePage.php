<?php
/**
 * Live Chat screen and admin-wide alerts.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Live\LiveChat;
use Softorio\AiAssistant\Rest\ChatController;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The agents' inbox, and a small alert script on every admin screen so an
 * agent working elsewhere in WP Admin (orders, products) still hears a
 * waiting visitor. The alert rides on WordPress's own Heartbeat, which
 * also keeps the agent marked as online.
 */
final class LivePage {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		if ( ! LiveChat::enabled() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'heartbeat_received', array( self::class, 'heartbeat' ), 10, 2 );
		add_filter( 'heartbeat_settings', array( self::class, 'heartbeat_settings' ) );
	}

	/**
	 * Faster Heartbeat for agents, so a waiting visitor is noticed quickly.
	 *
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed>
	 */
	public static function heartbeat_settings( array $settings ): array {
		if ( current_user_can( LiveChat::CAP ) ) {
			$settings['interval'] = 15;
		}

		return $settings;
	}

	/**
	 * Heartbeat: mark the agent online and report what needs attention.
	 *
	 * @param array<string, mixed> $response Response.
	 * @param array<string, mixed> $data     Data sent by the browser.
	 * @return array<string, mixed>
	 */
	public static function heartbeat( array $response, array $data ): array {
		if ( empty( $data['softorio_ai_live'] ) || ! current_user_can( LiveChat::CAP ) ) {
			return $response;
		}

		$me = get_current_user_id();
		LiveChat::heartbeat( $me );

		$response['softorio_ai_live'] = array(
			'waiting' => LiveChat::waiting_count(),
			'unread'  => LiveChat::unread_for( $me ),
		);

		return $response;
	}

	/**
	 * Scripts: the inbox app on its screen, the alert elsewhere.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! current_user_can( LiveChat::CAP ) ) {
			return;
		}

		$common = array(
			'sound' => (bool) Settings::get( 'live_sound', true ),
			'inbox' => Menu::url( 'live' ),
			'i18n'  => array(
				'waiting'    => __( 'A visitor is waiting for a live chat', 'all-in-one-ai-chatbot' ),
				'newMessage' => __( 'New live chat message', 'all-in-one-ai-chatbot' ),
				'open'       => __( 'Open Live Chat', 'all-in-one-ai-chatbot' ),
			),
		);

		if ( str_contains( $hook, Menu::SLUG . '-live' ) ) {
			wp_enqueue_style( 'softorio-ai-live', SOFTORIO_AI_URL . 'assets/css/live.css', array(), SOFTORIO_AI_VERSION );
			wp_enqueue_script( 'softorio-ai-live', SOFTORIO_AI_URL . 'assets/js/live.js', array(), SOFTORIO_AI_VERSION, true );
			wp_localize_script(
				'softorio-ai-live',
				'softorioAiLive',
				// array_merge, not +: the inbox needs its own, longer "i18n".
				array_merge(
					$common,
					array(
						'rest'  => esc_url_raw( rest_url( ChatController::NAMESPACE . '/' ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'me'    => get_current_user_id(),
						'open'  => isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which conversation to show.
					'i18n'      => $common['i18n'] + self::strings(),
					)
				)
			);
			return;
		}

		wp_enqueue_script( 'softorio-ai-live-alert', SOFTORIO_AI_URL . 'assets/js/live-alert.js', array( 'heartbeat' ), SOFTORIO_AI_VERSION, true );
		wp_localize_script( 'softorio-ai-live-alert', 'softorioAiLive', $common );
	}

	/**
	 * Strings for the inbox app.
	 *
	 * @return array<string, string>
	 */
	private static function strings(): array {
		return array(
			'available'   => __( 'Available', 'all-in-one-ai-chatbot' ),
			'away'        => __( 'Away', 'all-in-one-ai-chatbot' ),
			'online'      => __( 'Online:', 'all-in-one-ai-chatbot' ),
			'nobody'      => __( 'Nobody is available, so visitors are not offered a live chat.', 'all-in-one-ai-chatbot' ),
			'notify'      => __( 'Turn on desktop notifications', 'all-in-one-ai-chatbot' ),
			'waitingList' => __( 'Waiting', 'all-in-one-ai-chatbot' ),
			'liveList'    => __( 'Live now', 'all-in-one-ai-chatbot' ),
			'aiList'      => __( 'With the AI (last 2 hours)', 'all-in-one-ai-chatbot' ),
			'empty'       => __( 'No conversations right now. Waiting visitors appear here with a sound.', 'all-in-one-ai-chatbot' ),
			'pick'        => __( 'Choose a conversation on the left.', 'all-in-one-ai-chatbot' ),
			'takeover'    => __( 'Take over', 'all-in-one-ai-chatbot' ),
			'release'     => __( 'Hand back to AI', 'all-in-one-ai-chatbot' ),
			'close'       => __( 'End live chat', 'all-in-one-ai-chatbot' ),
			'send'        => __( 'Send', 'all-in-one-ai-chatbot' ),
			'placeholder' => __( 'Type a reply… (Enter to send, Shift+Enter for a new line)', 'all-in-one-ai-chatbot' ),
			'aiNote'      => __( 'The AI is answering. Sending a message takes over the chat.', 'all-in-one-ai-chatbot' ),
			/* translators: %s: agent's name */
			'otherAgent'  => __( '%s is handling this chat.', 'all-in-one-ai-chatbot' ),
			'visitor'     => __( 'Visitor', 'all-in-one-ai-chatbot' ),
			'ai'          => __( 'AI assistant', 'all-in-one-ai-chatbot' ),
			'typing'      => __( 'Visitor is typing…', 'all-in-one-ai-chatbot' ),
			'modeWaiting' => __( 'Waiting', 'all-in-one-ai-chatbot' ),
			'modeHuman'   => __( 'Live', 'all-in-one-ai-chatbot' ),
			'modeAi'      => __( 'AI', 'all-in-one-ai-chatbot' ),
			/* translators: %s: how long, e.g. "3m" */
			'waitingFor'  => __( 'waiting %s', 'all-in-one-ai-chatbot' ),
			'page'        => __( 'Page', 'all-in-one-ai-chatbot' ),
			'email'       => __( 'Email', 'all-in-one-ai-chatbot' ),
			'phone'       => __( 'Phone', 'all-in-one-ai-chatbot' ),
			'account'     => __( 'Account', 'all-in-one-ai-chatbot' ),
			'failed'      => __( 'Could not reach the server. Retrying…', 'all-in-one-ai-chatbot' ),
			'confirmEnd'  => __( 'End this live chat? The AI assistant will answer the visitor again.', 'all-in-one-ai-chatbot' ),
		);
	}

	/**
	 * Render the screen shell; live.js fills it in.
	 */
	public static function render(): void {
		if ( ! current_user_can( LiveChat::CAP ) ) {
			return;
		}
		?>
		<div class="wrap sai-live-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Live Chat', 'all-in-one-ai-chatbot' ); ?></h1>
			<div id="sai-live" class="sai-live">
				<noscript><?php esc_html_e( 'Live Chat needs JavaScript.', 'all-in-one-ai-chatbot' ); ?></noscript>
			</div>
		</div>
		<?php
	}
}
