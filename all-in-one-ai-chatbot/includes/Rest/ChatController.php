<?php
/**
 * Public REST endpoints used by the widget.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Rest;

use Softorio\AiAssistant\Chat\ChatError;
use Softorio\AiAssistant\Chat\ChatService;
use Softorio\AiAssistant\Chat\ConversationStore;

defined( 'ABSPATH' ) || exit;

/**
 * POST /softorio-ai/v1/chat and GET /softorio-ai/v1/history.
 *
 * Both are public by design — the widget serves anonymous visitors — so the
 * permission callback is open and protection comes from elsewhere: rate
 * limits and budgets on chat, and the visitor token on history.
 *
 * No REST nonce is required. Pages are commonly served from a full-page cache
 * for hours, and a nonce baked into a cached page expires, which would break
 * the widget for every visitor until the cache cleared. Nothing here acts on
 * a logged-in user's behalf, so there is nothing a forged request could do
 * that a direct one could not.
 */
final class ChatController {

	public const NAMESPACE = 'softorio-ai/v1';

	/**
	 * Register routes.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => self::chat_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/stream',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'stream' ),
				'permission_callback' => '__return_true',
				'args'                => self::chat_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/feedback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'conversation_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9]{32}$',
					),
					'visitor_token'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9]{16,64}$',
					),
					'message_id'      => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'rating'          => array(
						'type'     => 'integer',
						'required' => true,
						'enum'     => array( -1, 0, 1 ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'history' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'conversation_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-f0-9]{32}$',
					),
					'visitor_token'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9]{16,64}$',
					),
				),
			)
		);
	}

	/**
	 * Arguments shared by the chat endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function chat_args(): array {
		return array(
			'message'         => array(
				'type'     => 'string',
				'required' => true,
			),
			'conversation_id' => array(
				'type'    => 'string',
				'default' => '',
				'pattern' => '^[a-f0-9]{0,32}$',
			),
			'visitor_token'   => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z0-9]{16,64}$',
			),
			'page_url'        => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Answer a message as a server-sent-events stream.
	 *
	 * Events: `delta` {t} as text is written, `tool` {name} while a tool
	 * runs, `reset` when a tool round replaces text shown so far, then one
	 * final `done` (the same payload as the normal endpoint) or `error`.
	 *
	 * The response is written directly and the request ends here, bypassing
	 * the REST server's JSON output. When streaming is switched off this
	 * returns a normal error, and the widget uses the regular endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function stream( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! \Softorio\AiAssistant\Settings::get( 'streaming', false ) ) {
			return new \WP_REST_Response( array( 'code' => 'streaming_off', 'message' => 'Streaming is switched off.' ), 404 );
		}

		/**
		 * Filter the output sink for streamed events (tests replace it).
		 *
		 * @param callable|null $sink Receives each formatted SSE event string.
		 */
		$sink = apply_filters( 'softorio_ai_stream_sink', null );

		if ( ! is_callable( $sink ) ) {
			self::open_stream();
			$sink = static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON inside an event stream.
				flush();
			};
		}

		$send = static function ( string $event, array $data ) use ( $sink ): void {
			$sink( 'event: ' . $event . "\ndata: " . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n" );
		};

		try {
			$result = ( new ChatService() )->ask(
				(string) $request->get_param( 'message' ),
				(string) $request->get_param( 'conversation_id' ),
				(string) $request->get_param( 'visitor_token' ),
				self::same_site_url( (string) $request->get_param( 'page_url' ) ),
				static function ( string $type, mixed $value ) use ( $send ): void {
					match ( $type ) {
						'delta' => $send( 'delta', array( 't' => (string) $value ) ),
						'tool'  => $send( 'tool', array( 'name' => (string) $value ) ),
						'reset' => $send( 'reset', array() ),
						default => null,
					};
				}
			);

			$send( 'done', $result );
		} catch ( ChatError $e ) {
			$send(
				'error',
				array(
					'code'    => $e->error_code,
					'message' => $e->getMessage(),
				)
			);
		}

		if ( has_filter( 'softorio_ai_stream_sink' ) ) {
			return new \WP_REST_Response( null, 200 );
		}

		exit;
	}

	/**
	 * Send stream headers and switch off everything that would buffer.
	 */
	private static function open_stream(): void {
		ignore_user_abort( true );

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not available everywhere.
		}

		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky -- must not compress a stream.

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		status_header( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store' );
		header( 'X-Accel-Buffering: no' ); // nginx.

		// Some proxies wait for a first chunk of a minimum size before
		// passing anything on; a comment line gets the stream moving.
		echo ':' . str_repeat( ' ', 2048 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- padding.
		flush();
	}

	/**
	 * Record 👍 / 👎 on an answer.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function feedback( \WP_REST_Request $request ): \WP_REST_Response {
		$store = new ConversationStore();
		$row   = $store->find_owned( (string) $request->get_param( 'conversation_id' ), (string) $request->get_param( 'visitor_token' ) );
		$id    = (int) $request->get_param( 'message_id' );

		if ( null === $row || ! $store->rate( (int) $row['id'], $id, (int) $request->get_param( 'rating' ) ) ) {
			return new \WP_REST_Response( array( 'code' => 'not_found', 'message' => __( 'Answer not found.', 'all-in-one-ai-chatbot' ) ), 404 );
		}

		if ( 0 !== (int) $request->get_param( 'rating' ) ) {
			$answer = '';
			foreach ( $store->transcript( (int) $row['id'], 500 ) as $m ) {
				if ( $m['id'] === $id ) {
					$answer = $m['content'];
				}
			}

			\Softorio\AiAssistant\Support\Events::emit(
				\Softorio\AiAssistant\Support\Events::ANSWER_RATED,
				array(
					'conversation_id' => (string) $row['public_id'],
					'message_id'      => $id,
					'rating'          => (int) $request->get_param( 'rating' ) > 0 ? 'helpful' : 'not_helpful',
					'answer'          => $answer,
				)
			);
		}

		return self::no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * Answer a message.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function chat( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$result = ( new ChatService() )->ask(
				(string) $request->get_param( 'message' ),
				(string) $request->get_param( 'conversation_id' ),
				(string) $request->get_param( 'visitor_token' ),
				self::same_site_url( (string) $request->get_param( 'page_url' ) )
			);
		} catch ( ChatError $e ) {
			$response = new \WP_REST_Response(
				array(
					'code'    => $e->error_code,
					'message' => $e->getMessage(),
				),
				$e->status
			);

			if ( $e->retry_after > 0 ) {
				$response->header( 'Retry-After', (string) $e->retry_after );
			}

			return self::no_cache( $response );
		}

		return self::no_cache( new \WP_REST_Response( $result, 200 ) );
	}

	/**
	 * Messages of a conversation, for the widget to redisplay after navigation.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function history( \WP_REST_Request $request ): \WP_REST_Response {
		$store = new ConversationStore();
		$row   = $store->find_owned( (string) $request->get_param( 'conversation_id' ), (string) $request->get_param( 'visitor_token' ) );

		if ( null === $row ) {
			return self::no_cache(
				new \WP_REST_Response(
					array(
						'code'    => 'not_found',
						'message' => __( 'Conversation not found.', 'all-in-one-ai-chatbot' ),
					),
					404
				)
			);
		}

		$messages = array_map(
			static fn( array $m ): array => array(
				'role'    => $m['role'],
				'content' => $m['content'],
				'sources' => $m['sources'],
				'cards'   => $m['cards'],
				'id'      => 'user' === $m['role'] ? 0 : $m['id'],
				'rating'  => $m['rating'],
				'agent'   => $m['agent'],
			),
			$store->transcript( (int) $row['id'] )
		);

		return self::no_cache( new \WP_REST_Response( array( 'messages' => $messages ), 200 ) );
	}

	/**
	 * Keep the page URL only if it belongs to this site.
	 *
	 * It goes into the prompt, so a crafted value must not become a way to
	 * inject text there.
	 *
	 * @param string $url Claimed page URL.
	 */
	public static function same_site_url( string $url ): string {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return '';
		}

		$home = wp_parse_url( home_url() );
		$page = wp_parse_url( $url );

		if ( ! is_array( $home ) || ! is_array( $page ) || ( $home['host'] ?? '' ) !== ( $page['host'] ?? '' ) ) {
			return '';
		}

		return mb_substr( $url, 0, 500, 'UTF-8' );
	}

	/**
	 * Mark a response as uncacheable; answers are per-visitor.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	public static function no_cache( \WP_REST_Response $response ): \WP_REST_Response {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			$response->header( $name, (string) $value );
		}

		return $response;
	}
}
