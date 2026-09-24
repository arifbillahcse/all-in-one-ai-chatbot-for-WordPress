<?php
/**
 * Admin AJAX actions.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Chat\ConversationStore;
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
			),
			'' === $query ? array() : ( new Retriever() )->search( $query )
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
