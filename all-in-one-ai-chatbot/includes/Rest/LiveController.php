<?php
/**
 * Live chat endpoints.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Rest;

use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Live\LiveChat;
use Softorio\AiAssistant\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Visitor side (public, proven by the visitor token like /history):
 *
 *   GET  /live/status    is anyone available?
 *   POST /live/request   ask for a person
 *   GET  /live/poll      new agent/system messages, mode, typing
 *   POST /live/typing    the visitor is typing
 *   POST /live/leave     back to the AI
 *
 * Agent side (logged in, live-chat capability, REST nonce):
 *
 *   GET  /agent/inbox
 *   POST /agent/presence
 *   GET  /agent/conversations/{id}
 *   POST /agent/conversations/{id}/(message|takeover|release|close|typing)
 */
final class LiveController {

	/**
	 * Register routes. They answer only while live chat is switched on.
	 */
	public static function register(): void {
		$ns      = ChatController::NAMESPACE;
		$public  = static fn(): bool => LiveChat::enabled();
		$visitor = array(
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
		);

		register_rest_route(
			$ns,
			'/live/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			$ns,
			'/live/request',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'request' ),
				'permission_callback' => $public,
				'args'                => array(
					'conversation_id' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-f0-9]{0,32}$',
					),
					'visitor_token'   => $visitor['visitor_token'],
					'page_url'        => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/live/poll',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'poll' ),
				'permission_callback' => $public,
				'args'                => $visitor + array(
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		foreach ( array( 'typing', 'leave' ) as $action ) {
			register_rest_route(
				$ns,
				'/live/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, $action ),
					'permission_callback' => $public,
					'args'                => $visitor,
				)
			);
		}

		$agent = static fn(): bool => LiveChat::enabled() && current_user_can( LiveChat::CAP );

		register_rest_route(
			$ns,
			'/agent/inbox',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'inbox' ),
				'permission_callback' => $agent,
			)
		);

		register_rest_route(
			$ns,
			'/agent/presence',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'presence' ),
				'permission_callback' => $agent,
				'args'                => array(
					'away' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/agent/conversations/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'conversation' ),
				'permission_callback' => $agent,
				'args'                => array(
					'after' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/agent/conversations/(?P<id>\d+)/(?P<action>message|takeover|release|close|typing)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'act' ),
				'permission_callback' => $agent,
				'args'                => array(
					'text' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	// ── Visitor ─────────────────────────────────────────────────────────────

	/**
	 * Whether a person can chat now.
	 */
	public static function status(): \WP_REST_Response {
		return ChatController::no_cache( new \WP_REST_Response( array( 'available' => LiveChat::available() ), 200 ) );
	}

	/**
	 * The visitor asks for a person.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function request( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request->get_param( 'visitor_token' );

		// Each request can ping every agent's phone: a handful per hour is plenty.
		if ( ! RateLimiter::hit( 'live-request|' . $token, 5, HOUR_IN_SECONDS )['allowed'] ) {
			return self::error( 'rate_limited', __( 'Please wait a little before asking again.', 'all-in-one-ai-chatbot' ), 429 );
		}

		if ( ! LiveChat::available() ) {
			return self::error( 'no_agents', __( 'Nobody from our team is online right now.', 'all-in-one-ai-chatbot' ), 409 );
		}

		$store  = new ConversationStore();
		$thread = $store->resume_or_create( (string) $request->get_param( 'conversation_id' ), $token, ChatController::same_site_url( (string) $request->get_param( 'page_url' ) ) );
		$mode   = LiveChat::request( $thread['id'] );

		return ChatController::no_cache(
			new \WP_REST_Response(
				array(
					'conversation_id' => $thread['public_id'],
					'mode'            => $mode,
					'messages'        => LiveChat::messages( $thread['id'], 0, array( 'agent', 'system' ) ),
				),
				200
			)
		);
	}

	/**
	 * New messages for the visitor since an id.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function poll( \WP_REST_Request $request ): \WP_REST_Response {
		$row = self::owned( $request );

		if ( null === $row ) {
			return self::error( 'not_found', __( 'Conversation not found.', 'all-in-one-ai-chatbot' ), 404 );
		}

		if ( LiveChat::maybe_timeout( $row ) ) {
			$row = LiveChat::row( (int) $row['id'] ) ?? $row;
		}

		$mode   = LiveChat::mode( $row );
		$typing = 'human' === $mode ? LiveChat::is_typing( (int) $row['id'], 'agent' ) : '';

		return ChatController::no_cache(
			new \WP_REST_Response(
				array(
					'mode'     => $mode,
					'agent'    => 'human' === $mode ? LiveChat::agent( (int) $row['agent_id'] ) : null,
					'typing'   => '' !== $typing ? $typing : false,
					'messages' => LiveChat::messages( (int) $row['id'], (int) $request->get_param( 'after' ), array( 'agent', 'system' ) ),
				),
				200
			)
		);
	}

	/**
	 * The visitor is typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function typing( \WP_REST_Request $request ): \WP_REST_Response {
		$row = self::owned( $request );

		if ( null !== $row && 'ai' !== LiveChat::mode( $row ) ) {
			LiveChat::typing( (int) $row['id'], 'visitor' );
		}

		return ChatController::no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
	}

	/**
	 * The visitor leaves the live chat.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function leave( \WP_REST_Request $request ): \WP_REST_Response {
		$row = self::owned( $request );

		if ( null === $row ) {
			return self::error( 'not_found', __( 'Conversation not found.', 'all-in-one-ai-chatbot' ), 404 );
		}

		LiveChat::release( (int) $row['id'], 'visitor' );

		return ChatController::no_cache( new \WP_REST_Response( array( 'mode' => 'ai' ), 200 ) );
	}

	/**
	 * The conversation this visitor token owns, or null.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|null
	 */
	private static function owned( \WP_REST_Request $request ): ?array {
		return ( new ConversationStore() )->find_owned( (string) $request->get_param( 'conversation_id' ), (string) $request->get_param( 'visitor_token' ) );
	}

	// ── Agent ───────────────────────────────────────────────────────────────

	/**
	 * The inbox (also counts as a heartbeat).
	 */
	public static function inbox(): \WP_REST_Response {
		$me = get_current_user_id();

		LiveChat::heartbeat( $me );

		return ChatController::no_cache(
			new \WP_REST_Response(
				array(
					'conversations' => LiveChat::inbox( $me ),
					'waiting'       => LiveChat::waiting_count(),
					'away'          => LiveChat::is_away( $me ),
					'online'        => array_map( static fn( int $id ): string => LiveChat::agent( $id )['name'], LiveChat::online_agents() ),
				),
				200
			)
		);
	}

	/**
	 * Set yourself available or away.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function presence( \WP_REST_Request $request ): \WP_REST_Response {
		LiveChat::set_away( get_current_user_id(), (bool) $request->get_param( 'away' ) );

		return self::inbox();
	}

	/**
	 * One conversation for the agent: details and messages since an id.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function conversation( \WP_REST_Request $request ): \WP_REST_Response {
		$row = LiveChat::row( (int) $request['id'] );

		if ( null === $row ) {
			return self::error( 'not_found', __( 'Conversation not found.', 'all-in-one-ai-chatbot' ), 404 );
		}

		LiveChat::maybe_timeout( $row );
		$row      = LiveChat::row( (int) $row['id'] ) ?? $row;
		$messages = LiveChat::messages( (int) $row['id'], (int) $request->get_param( 'after' ) );

		if ( array() !== $messages ) {
			LiveChat::mark_read( (int) $row['id'], (int) end( $messages )['id'] );
		}

		return ChatController::no_cache(
			new \WP_REST_Response(
				array(
					'conversation' => LiveChat::details( $row ),
					'messages'     => $messages,
					'typing'       => '' !== LiveChat::is_typing( (int) $row['id'], 'visitor' ),
				),
				200
			)
		);
	}

	/**
	 * Agent actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function act( \WP_REST_Request $request ): \WP_REST_Response {
		$id  = (int) $request['id'];
		$me  = get_current_user_id();
		$row = LiveChat::row( $id );

		if ( null === $row ) {
			return self::error( 'not_found', __( 'Conversation not found.', 'all-in-one-ai-chatbot' ), 404 );
		}

		switch ( (string) $request['action'] ) {
			case 'message':
				$text = trim( sanitize_textarea_field( (string) $request->get_param( 'text' ) ) );

				if ( '' === $text ) {
					return self::error( 'empty', __( 'Type a message first.', 'all-in-one-ai-chatbot' ), 422 );
				}

				LiveChat::agent_message( $id, $me, mb_substr( $text, 0, 4000 ) );
				break;

			case 'takeover':
				LiveChat::takeover( $id, $me );
				break;

			case 'release':
				LiveChat::release( $id, 'agent' );
				break;

			case 'close':
				LiveChat::release( $id, 'closed' );
				break;

			case 'typing':
				LiveChat::typing( $id, 'agent', LiveChat::agent( $me )['name'] );
				return ChatController::no_cache( new \WP_REST_Response( array( 'ok' => true ), 200 ) );
		}

		$request->set_param( 'after', (int) $request->get_param( 'after' ) );

		return self::conversation( $request );
	}

	/**
	 * An error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return ChatController::no_cache(
			new \WP_REST_Response(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$status
			)
		);
	}
}
