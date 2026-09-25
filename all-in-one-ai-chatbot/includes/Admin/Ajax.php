<?php
/**
 * Admin AJAX actions.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Knowledge\Audience;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\Llm\Router;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Index rebuild, test search, provider test and conversation delete.
 *
 * Every action checks the nonce and manage_options before doing anything.
 */
final class Ajax {

	public const NONCE = 'softorio_ai_admin';

	/**
	 * Register actions (logged-in only; none are available to visitors).
	 */
	public static function init(): void {
		add_action( 'wp_ajax_softorio_ai_rebuild', array( self::class, 'rebuild' ) );
		add_action( 'wp_ajax_softorio_ai_search', array( self::class, 'search' ) );
		add_action( 'wp_ajax_softorio_ai_test', array( self::class, 'test' ) );
		add_action( 'wp_ajax_softorio_ai_delete_conversation', array( self::class, 'delete_conversation' ) );
		add_action( 'wp_ajax_softorio_ai_test_integration', array( self::class, 'test_integration' ) );
	}

	/**
	 * Stop unless the request is from an administrator with a valid nonce.
	 */
	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'all-in-one-ai-chatbot' ) ), 403 );
		}
	}

	/**
	 * One batch of an index rebuild. The browser calls this repeatedly.
	 */
	public static function rebuild(): void {
		self::guard();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		// A rebuild after a settings change starts from a clean slate, so
		// content from a type that was switched off does not linger until
		// the final batch.
		if ( 0 === $offset && ! empty( $_POST['fresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			wp_clear_scheduled_hook( \Softorio\AiAssistant\Cron::BUILD );
		}

		$result = ( new Indexer() )->rebuild_batch( $offset, 10 );

		wp_send_json_success( $result );
	}

	/**
	 * Show what retrieval returns for a question, without calling the AI.
	 */
	public static function search(): void {
		self::guard();

		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		$results = array_map(
			static fn( array $r ): array => array(
				'title'   => $r['title'],
				'url'     => '' !== $r['url'] ? $r['url'] : get_edit_post_link( $r['post_id'], 'raw' ),
				'score'   => round( $r['score'], 2 ),
				'excerpt' => mb_substr( $r['content'], 0, 240, 'UTF-8' ),
				'members' => '' !== $r['audience'] ? Audience::label( $r['audience'] ) : '',
			),
			// The owner sees everything, marked with who it is for.
			'' === $query ? array() : ( new Retriever() )->search( $query, null, Audience::everything() )
		);

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Send a tiny request to a provider to prove the key and model work.
	 */
	public static function test(): void {
		self::guard();

		$id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		if ( ! array_key_exists( $id, Settings::providers() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'all-in-one-ai-chatbot' ) ) );
		}

		if ( '' === Settings::api_key( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Save an API key first.', 'all-in-one-ai-chatbot' ) ) );
		}

		$provider = Router::make( $id );

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'all-in-one-ai-chatbot' ) ) );
		}

		try {
			$response = $provider->complete( 'Reply with the single word OK.', array( array( 'role' => 'user', 'content' => 'Test' ) ), 256 );
		} catch ( LlmException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				/* translators: %s: model id */
				'message' => sprintf( __( 'Connected. Model %s replied.', 'all-in-one-ai-chatbot' ), $response->model ),
			)
		);
	}

	/**
	 * Try an integration with its saved settings and report what happened.
	 */
	public static function test_integration(): void {
		self::guard();

		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		try {
			$message = match ( $kind ) {
				'email'    => self::test_email(),
				'telegram' => self::test_telegram(),
				'webhook'  => self::test_webhooks(),
				default    => throw new \RuntimeException( __( 'Unknown test.', 'all-in-one-ai-chatbot' ) ),
			};
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Send a test alert email right now (not through the queue).
	 */
	private static function test_email(): string {
		$to = \Softorio\AiAssistant\Notify\Notifier::owner_email();

		\Softorio\AiAssistant\Notify\Notifier::send_email(
			array(
				'to'      => $to,
				'subject' => __( 'Test alert from your AI Chatbot', 'all-in-one-ai-chatbot' ),
				'html'    => '<p>' . esc_html__( 'Email alerts are working.', 'all-in-one-ai-chatbot' ) . '</p>',
			)
		);

		/* translators: %s: email address */
		return sprintf( __( 'Sent to %s. Check the inbox (and spam folder).', 'all-in-one-ai-chatbot' ), $to );
	}

	/**
	 * Find the chat ID if it is missing, then send a test message.
	 */
	private static function test_telegram(): string {
		if ( '' === Settings::api_key( 'telegram' ) ) {
			throw new \RuntimeException( __( 'Save the bot token first.', 'all-in-one-ai-chatbot' ) );
		}

		$note = '';

		if ( '' === trim( (string) Settings::get( 'telegram_chat_id', '' ) ) ) {
			$updates = \Softorio\AiAssistant\Notify\Notifier::telegram_api( 'getUpdates' );
			$chat_id = '';

			foreach ( array_reverse( $updates ) as $update ) {
				$chat = $update['message']['chat']['id'] ?? $update['channel_post']['chat']['id'] ?? null;

				if ( null !== $chat ) {
					$chat_id = (string) $chat;
					break;
				}
			}

			if ( '' === $chat_id ) {
				throw new \RuntimeException( __( 'No chat found. Open Telegram, send any message to your bot, then press this button again.', 'all-in-one-ai-chatbot' ) );
			}

			$settings                     = Settings::all();
			$settings['telegram_chat_id'] = $chat_id;
			update_option( Settings::OPTION, $settings );

			/* translators: %s: Telegram chat id */
			$note = sprintf( __( 'Found and saved chat ID %s. ', 'all-in-one-ai-chatbot' ), $chat_id );
		}

		\Softorio\AiAssistant\Notify\Notifier::send_telegram( array( 'text' => '✅ ' . __( 'Telegram alerts are working.', 'all-in-one-ai-chatbot' ) ) );

		return $note . __( 'Test message sent.', 'all-in-one-ai-chatbot' );
	}

	/**
	 * Post a "test" event to every configured webhook, synchronously.
	 */
	private static function test_webhooks(): string {
		$urls = \Softorio\AiAssistant\Notify\Webhooks::urls();

		if ( array() === $urls ) {
			throw new \RuntimeException( __( 'Add a webhook URL and save first.', 'all-in-one-ai-chatbot' ) );
		}

		$lines = array();
		$ok    = true;

		foreach ( $urls as $url ) {
			$result = \Softorio\AiAssistant\Notify\Webhooks::post(
				$url,
				'test',
				wp_generate_uuid4(),
				array(
					'event'       => 'test',
					'occurred_at' => gmdate( 'c' ),
					'site'        => home_url( '/' ),
					'message'     => 'Webhook test from All in One AI Chatbot',
				)
			);

			$ok      = $ok && $result['ok'];
			$lines[] = ( $result['ok'] ? '✓ ' : '✗ ' ) . $result['message'];
		}

		if ( ! $ok ) {
			throw new \RuntimeException( implode( ' · ', $lines ) );
		}

		return implode( ' · ', $lines );
	}

	/**
	 * Delete a conversation.
	 */
	public static function delete_conversation(): void {
		self::guard();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		if ( $id > 0 ) {
			( new ConversationStore() )->delete( $id );
		}

		wp_send_json_success();
	}
}
