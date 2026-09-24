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
				'args'                => array(
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
						'message' => __( 'Conversation not found.', 'softorio-ai-assistant' ),
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
	private static function same_site_url( string $url ): string {
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
	private static function no_cache( \WP_REST_Response $response ): \WP_REST_Response {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			$response->header( $name, (string) $value );
		}

		return $response;
	}
}
