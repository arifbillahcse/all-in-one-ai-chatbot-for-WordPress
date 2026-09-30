<?php
/**
 * Answer live chats from Telegram.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Live;

use Softorio\AiAssistant\Notify\Notifier;
use Softorio\AiAssistant\Rest\ChatController;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Two-way live chat through the owner's Telegram bot.
 *
 * Live-chat requests and every visitor message in a live chat are posted to
 * the owner's Telegram chat. Replying to one of those posts (Telegram's
 * "Reply") sends the text to that visitor, so a small team can run live
 * chat from their phones without keeping WP Admin open.
 *
 * Telegram calls the site's webhook with a secret header set when the
 * webhook was registered; updates from any other chat are ignored.
 */
final class TelegramBridge {

	public const JOB     = 'telegram.live';
	private const SECRET = 'softorio_ai_tg_secret';
	private const MAP    = 'softorio_ai_tg_msg_';

	/**
	 * Hook up when switched on.
	 */
	public static function init(): void {
		Queue::register( self::JOB, array( self::class, 'send' ) );
		Events::listen( Events::LIVE_REQUESTED, array( self::class, 'on_request' ) );
		add_action( 'softorio_ai_live_visitor_message', array( self::class, 'on_visitor_message' ), 10, 2 );
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Whether the bridge is on and Telegram is set up.
	 */
	public static function enabled(): bool {
		return LiveChat::enabled() && (bool) Settings::get( 'live_telegram', false ) && Notifier::telegram_ready();
	}

	/**
	 * Webhook route.
	 */
	public static function register(): void {
		register_rest_route(
			ChatController::NAMESPACE,
			'/telegram/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'webhook' ),
				'permission_callback' => array( self::class, 'verify' ),
			)
		);
	}

	/**
	 * Only Telegram knows the secret it was given when the webhook was set.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function verify( \WP_REST_Request $request ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$secret = (string) get_option( self::SECRET, '' );
		$given  = (string) $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );

		return '' !== $secret && '' !== $given && hash_equals( $secret, $given );
	}

	/**
	 * Post a live-chat request to Telegram.
	 *
	 * @param array<string, mixed> $payload live.requested payload.
	 */
	public static function on_request( array $payload ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );
		$lines = array( '<b>🙋 ' . $e( __( 'Live chat request', 'all-in-one-ai-chatbot' ) ) . '</b> — ' . $e( (string) ( $payload['visitor'] ?? '' ) ) );

		if ( '' !== (string) ( $payload['page_url'] ?? '' ) ) {
			$lines[] = $e( (string) $payload['page_url'] );
		}

		foreach ( (array) ( $payload['transcript'] ?? array() ) as $turn ) {
			$who     = 'user' === $turn['role'] ? '👤' : ( 'agent' === $turn['role'] ? '🧑‍💼' : '🤖' );
			$lines[] = $who . ' ' . $e( mb_substr( (string) $turn['content'], 0, 300 ) );
		}

		$lines[] = '';
		$lines[] = '<i>' . $e( __( 'Reply to this message to answer. /ai hands back to the AI, /end ends the chat.', 'all-in-one-ai-chatbot' ) ) . '</i>';

		Queue::push(
			self::JOB,
			array(
				'conversation' => (int) ( $payload['internal_id'] ?? 0 ),
				'text'         => mb_substr( implode( "\n", $lines ), 0, 4000 ),
			)
		);
	}

	/**
	 * Forward a visitor's live-chat message.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $text            Message.
	 */
	public static function on_visitor_message( int $conversation_id, string $text ): void {
		$row = self::enabled() ? LiveChat::row( $conversation_id ) : null;

		if ( null === $row ) {
			return;
		}

		Queue::push(
			self::JOB,
			array(
				'conversation' => $conversation_id,
				'text'         => '💬 <b>' . htmlspecialchars( LiveChat::visitor_label( $row ), ENT_NOQUOTES, 'UTF-8' ) . '</b>: ' . htmlspecialchars( mb_substr( $text, 0, 3500 ), ENT_NOQUOTES, 'UTF-8' ),
			)
		);
	}

	/**
	 * Queue handler: send, and remember which conversation the post is about
	 * so a reply to it can be routed back.
	 *
	 * @param array<string, mixed> $job conversation, text.
	 */
	public static function send( array $job ): void {
		if ( ! Notifier::telegram_ready() ) {
			throw new \RuntimeException( 'Telegram is not configured.' );
		}

		$result = Notifier::telegram_api(
			'sendMessage',
			array(
				'chat_id'                  => trim( (string) Settings::get( 'telegram_chat_id', '' ) ),
				'text'                     => (string) $job['text'],
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => true,
			)
		);

		if ( isset( $result['message_id'] ) ) {
			set_transient( self::MAP . (int) $result['message_id'], (int) $job['conversation'], 14 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Telegram update: a reply from the team.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$update  = (array) $request->get_json_params();
		$message = is_array( $update['message'] ?? null ) ? $update['message'] : array();
		$ok      = new \WP_REST_Response( array( 'ok' => true ), 200 );

		// Only the owner's configured chat may answer visitors.
		if ( (string) ( $message['chat']['id'] ?? '' ) !== trim( (string) Settings::get( 'telegram_chat_id', '' ) ) ) {
			return $ok;
		}

		$reply_to = (int) ( $message['reply_to_message']['message_id'] ?? 0 );
		$text     = trim( (string) ( $message['text'] ?? '' ) );
		$conv     = $reply_to > 0 ? (int) get_transient( self::MAP . $reply_to ) : 0;

		if ( 0 === $conv || '' === $text || null === LiveChat::row( $conv ) ) {
			return $ok;
		}

		$name = mb_substr( sanitize_text_field( (string) ( $message['from']['first_name'] ?? '' ) ), 0, 40 );

		if ( preg_match( '#^/ai\b#i', $text ) ) {
			LiveChat::release( $conv, 'agent' );
		} elseif ( preg_match( '#^/end\b#i', $text ) ) {
			LiveChat::release( $conv, 'closed' );
		} else {
			LiveChat::agent_message( $conv, 0, mb_substr( sanitize_textarea_field( $text ), 0, 4000 ), $name );
		}

		// The team's reply is itself a post they may reply to again.
		if ( isset( $message['message_id'] ) ) {
			set_transient( self::MAP . (int) $message['message_id'], $conv, 14 * DAY_IN_SECONDS );
		}

		return $ok;
	}

	/**
	 * Point the bot's webhook at this site (the "Connect" button).
	 *
	 * @return string What happened, for the owner.
	 * @throws \RuntimeException When it cannot be connected.
	 */
	public static function connect(): string {
		if ( ! Notifier::telegram_ready() ) {
			throw new \RuntimeException( __( 'Set up the Telegram bot token and chat ID under Notifications first.', 'all-in-one-ai-chatbot' ) );
		}

		$url = rest_url( ChatController::NAMESPACE . '/telegram/webhook' );

		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new \RuntimeException( __( 'Telegram only delivers replies to sites on HTTPS. Install an SSL certificate first.', 'all-in-one-ai-chatbot' ) );
		}

		$secret = wp_generate_password( 40, false, false );
		update_option( self::SECRET, $secret, false );

		Notifier::telegram_api(
			'setWebhook',
			array(
				'url'             => $url,
				'secret_token'    => $secret,
				'allowed_updates' => array( 'message' ),
			)
		);

		Log::info( 'telegram', 'Live chat replies connected' );

		return __( 'Connected. Replies to live-chat messages in Telegram now reach the visitor.', 'all-in-one-ai-chatbot' );
	}
}
