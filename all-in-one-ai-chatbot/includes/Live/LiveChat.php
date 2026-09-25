<?php
/**
 * Live agent takeover.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Live;

use Softorio\AiAssistant\Admin\Menu;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Leads\LeadStore;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;

defined( 'ABSPATH' ) || exit;

/**
 * A person from the site's team takes over a conversation from the AI.
 *
 * Each conversation has a mode:
 *
 *   ai       the assistant answers (the default)
 *   waiting  the visitor asked for a person; messages wait for an agent
 *   human    an agent is answering; the AI stays quiet
 *
 * There are no websockets on shared hosting, so both sides poll small REST
 * endpoints, and only while a live chat is actually going on. Agents are
 * "online" while their admin screens send a heartbeat; visitors are only
 * offered a live chat when at least one agent is.
 */
final class LiveChat {

	public const CAP          = 'softorio_ai_live_chat';
	public const MODES        = array( 'ai', 'waiting', 'human' );
	private const PRESENCE    = 'softorio_ai_agents_online';
	private const ONLINE_FOR  = 120; // Seconds since an agent's last heartbeat.
	public const AWAY_META    = 'softorio_ai_live_away';

	/**
	 * Hook the capability mapping.
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
	}

	/**
	 * Whether live chat is switched on.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'live_chat', false );
	}

	/**
	 * Give the live-chat capability to administrators and the roles the
	 * owner chose (e.g. shop managers), without touching stored roles.
	 *
	 * @param array<string, bool> $allcaps User's capabilities.
	 * @param array<int, string>  $caps    Required primitive caps.
	 * @param array<int, mixed>   $args    Requested cap and arguments.
	 * @param \WP_User            $user    User.
	 * @return array<string, bool>
	 */
	public static function grant( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! in_array( self::CAP, $caps, true ) || ! $user instanceof \WP_User ) {
			return $allcaps;
		}

		$roles = (array) Settings::get( 'live_agent_roles', array() );

		if ( ! empty( $allcaps['manage_options'] ) || array() !== array_intersect( (array) $user->roles, $roles ) ) {
			$allcaps[ self::CAP ] = true;
		}

		return $allcaps;
	}

	// ── Presence ────────────────────────────────────────────────────────────

	/**
	 * Record that an agent is at their screen (unless they set themselves away).
	 *
	 * @param int $user_id Agent.
	 */
	public static function heartbeat( int $user_id ): void {
		$online = self::presence();

		if ( self::is_away( $user_id ) ) {
			unset( $online[ $user_id ] );
		} else {
			$online[ $user_id ] = time();
		}

		update_option( self::PRESENCE, $online, false );
	}

	/**
	 * Available / away switch for an agent.
	 *
	 * @param int  $user_id Agent.
	 * @param bool $away    Away.
	 */
	public static function set_away( int $user_id, bool $away ): void {
		if ( $away ) {
			update_user_meta( $user_id, self::AWAY_META, 1 );
		} else {
			delete_user_meta( $user_id, self::AWAY_META );
		}

		self::heartbeat( $user_id );
	}

	/**
	 * Whether an agent set themselves away.
	 *
	 * @param int $user_id Agent.
	 */
	public static function is_away( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::AWAY_META, true );
	}

	/**
	 * Agents seen recently, by id => last heartbeat.
	 *
	 * @return array<int, int>
	 */
	private static function presence(): array {
		$online = get_option( self::PRESENCE, array() );
		$cutoff = time() - self::ONLINE_FOR;

		return array_filter( is_array( $online ) ? $online : array(), static fn( $t ): bool => (int) $t >= $cutoff );
	}

	/**
	 * Ids of agents online now.
	 *
	 * @return array<int, int>
	 */
	public static function online_agents(): array {
		return array_map( 'intval', array_keys( self::presence() ) );
	}

	/**
	 * Whether a visitor can be offered a live chat right now.
	 */
	public static function available(): bool {
		return self::enabled() && array() !== self::online_agents();
	}

	// ── Conversation state ──────────────────────────────────────────────────

	/**
	 * A conversation's live mode (always "ai" while the feature is off).
	 *
	 * @param array<string, mixed> $row Conversation row.
	 */
	public static function mode( array $row ): string {
		$mode = (string) ( $row['mode'] ?? 'ai' );

		return self::enabled() && in_array( $mode, self::MODES, true ) ? $mode : 'ai';
	}

	/**
	 * The visitor asks for a person.
	 *
	 * @param int $conversation_id Conversation.
	 * @return string The new mode.
	 */
	public static function request( int $conversation_id ): string {
		$row = self::row( $conversation_id );

		if ( null === $row ) {
			return 'ai';
		}

		$mode = self::mode( $row );

		if ( 'ai' !== $mode ) {
			return $mode;
		}

		self::set_mode( $conversation_id, 'waiting', 0 );
		self::system( $conversation_id, (string) Settings::get( 'live_waiting_message', '' ) ?: __( 'Connecting you to our team. Someone will be with you shortly.', 'all-in-one-ai-chatbot' ), 'waiting' );

		Events::emit( Events::LIVE_REQUESTED, self::payload( $conversation_id ) );

		return 'waiting';
	}

	/**
	 * An agent takes over the conversation.
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $agent_id        Agent user id (0 = someone replying from Telegram).
	 * @param string $name         Display name for agents without an account.
	 */
	public static function takeover( int $conversation_id, int $agent_id, string $name = '' ): void {
		$row = self::row( $conversation_id );

		if ( null === $row || ( 'human' === self::mode( $row ) && (int) $row['agent_id'] === $agent_id ) ) {
			return;
		}

		$was_human = 'human' === self::mode( $row );

		self::set_mode( $conversation_id, 'human', $agent_id );

		$name = '' !== $name ? $name : self::agent( $agent_id )['name'];

		/* translators: %s: agent's first name */
		self::system( $conversation_id, sprintf( __( '%s joined the chat.', 'all-in-one-ai-chatbot' ), $name ), 'joined', array( 'agent' => self::agent( $agent_id, $name ) ) );

		if ( ! $was_human ) {
			Events::emit( Events::LIVE_STARTED, self::payload( $conversation_id ) + array( 'agent' => $name ) );
		}
	}

	/**
	 * Back to the AI.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $reason          agent|visitor|timeout|closed.
	 */
	public static function release( int $conversation_id, string $reason = 'agent' ): void {
		$row = self::row( $conversation_id );

		if ( null === $row || 'ai' === (string) $row['mode'] ) {
			return;
		}

		self::set_mode( $conversation_id, 'ai', 0 );

		$text = match ( $reason ) {
			'timeout' => (string) Settings::get( 'live_timeout_message', '' ) ?: __( 'Sorry, our team is busy right now. The AI assistant can keep helping, or leave your details and we will get back to you.', 'all-in-one-ai-chatbot' ),
			'visitor' => __( 'You left the live chat. The AI assistant is here if you need anything else.', 'all-in-one-ai-chatbot' ),
			'closed'  => __( 'The chat with our team has ended. The AI assistant is here if you need anything else.', 'all-in-one-ai-chatbot' ),
			default   => __( 'You are chatting with the AI assistant again.', 'all-in-one-ai-chatbot' ),
		};

		self::system( $conversation_id, $text, $reason );

		Events::emit( Events::LIVE_ENDED, self::payload( $conversation_id ) + array( 'reason' => $reason ) );
	}

	/**
	 * Hand a waiting visitor back to the AI once nobody picked up in time.
	 *
	 * @param array<string, mixed> $row Conversation row.
	 * @return bool Whether it timed out.
	 */
	public static function maybe_timeout( array $row ): bool {
		if ( 'waiting' !== self::mode( $row ) || empty( $row['mode_since'] ) ) {
			return false;
		}

		$limit = max( 1, (int) Settings::get( 'live_wait_timeout', 3 ) ) * MINUTE_IN_SECONDS;

		if ( strtotime( (string) $row['mode_since'] . ' UTC' ) + $limit > time() ) {
			return false;
		}

		self::release( (int) $row['id'], 'timeout' );

		return true;
	}

	/**
	 * Store a visitor message sent during a live chat (no AI answer).
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $text            Message.
	 * @return int Message id.
	 */
	public static function visitor_message( int $conversation_id, string $text ): int {
		delete_transient( self::typing_key( $conversation_id, 'visitor' ) );

		$id = self::insert( $conversation_id, 'user', $text );

		/**
		 * A visitor wrote during a live chat (used to forward it to Telegram).
		 *
		 * @param int    $conversation_id Conversation.
		 * @param string $text            Message.
		 * @param int    $id              Message id.
		 */
		do_action( 'softorio_ai_live_visitor_message', $conversation_id, $text, $id );

		return $id;
	}

	/**
	 * Store an agent's reply, taking the conversation over if needed.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param int    $agent_id        Agent (0 for Telegram).
	 * @param string $text            Message.
	 * @param string $name            Name for agents without an account.
	 * @return int Message id.
	 */
	public static function agent_message( int $conversation_id, int $agent_id, string $text, string $name = '' ): int {
		$row = self::row( $conversation_id );

		if ( null !== $row && 'human' !== self::mode( $row ) ) {
			self::takeover( $conversation_id, $agent_id, $name );
		}

		delete_transient( self::typing_key( $conversation_id, 'agent' ) );

		return self::insert( $conversation_id, 'agent', $text, $agent_id, array( 'agent' => self::agent( $agent_id, $name ) ) );
	}

	/**
	 * Add a system notice ("Karim joined the chat").
	 *
	 * @param int                  $conversation_id Conversation.
	 * @param string               $text            Notice.
	 * @param string               $event           waiting|joined|agent|visitor|timeout|closed.
	 * @param array<string, mixed> $extra           More data for the widget.
	 */
	private static function system( int $conversation_id, string $text, string $event, array $extra = array() ): int {
		return self::insert( $conversation_id, 'system', $text, 0, array( 'event' => $event ) + $extra );
	}

	/**
	 * Insert a message and touch the conversation.
	 *
	 * @param int                  $conversation_id Conversation.
	 * @param string               $role            user|agent|system.
	 * @param string               $text            Content.
	 * @param int                  $agent_id        Agent.
	 * @param array<string, mixed> $meta            Stored in the sources column.
	 */
	private static function insert( int $conversation_id, string $role, string $text, int $agent_id = 0, array $meta = array() ): int {
		global $wpdb;

		$t   = Installer::tables();
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables.
		$wpdb->insert(
			$t['messages'],
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => $text,
				'sources'         => array() === $meta ? null : wp_json_encode( array( 'live' => $meta ) ),
				'agent_id'        => $agent_id,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET message_count = message_count + %d, updated_at = %s, ended_at = NULL,
				 title = CASE WHEN title = '' AND %s = 'user' THEN %s ELSE title END
				 WHERE id = %d",
				$t['conversations'],
				'system' === $role ? 0 : 1,
				$now,
				$role,
				mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, 150, 'UTF-8' ),
				$conversation_id
			)
		);

		// An agent's own message counts as read up to there.
		if ( 'agent' === $role ) {
			$wpdb->update( $t['conversations'], array( 'agent_read_id' => $id ), array( 'id' => $conversation_id ), array( '%d' ), array( '%d' ) );
		}
		// phpcs:enable

		return $id;
	}

	/**
	 * Change mode.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $mode            ai|waiting|human.
	 * @param int    $agent_id        Agent.
	 */
	private static function set_mode( int $conversation_id, string $mode, int $agent_id ): void {
		global $wpdb;

		$t    = Installer::tables();
		$data = array(
			'mode'       => $mode,
			'agent_id'   => $agent_id,
			'mode_since' => current_time( 'mysql', true ),
			'status'     => 'open',
		);

		// Going live: what the visitor said to the AI before is context, not
		// unread messages for the team.
		$row = self::row( $conversation_id );

		if ( null !== $row && 'ai' === (string) $row['mode'] && 'ai' !== $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$data['agent_read_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i WHERE conversation_id = %d', $t['messages'], $conversation_id ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( $t['conversations'], $data, array( 'id' => $conversation_id ), null, array( '%d' ) );
	}

	/**
	 * A conversation row.
	 *
	 * @param int $conversation_id Conversation.
	 * @return array<string, mixed>|null
	 */
	public static function row( int $conversation_id ): ?array {
		return ( new ConversationStore() )->find( $conversation_id );
	}

	// ── Reading ─────────────────────────────────────────────────────────────

	/**
	 * Messages after an id, in order.
	 *
	 * @param int                $conversation_id Conversation.
	 * @param int                $after           Last id already shown.
	 * @param array<int, string> $roles           Roles to include.
	 * @return array<int, array<string, mixed>>
	 */
	public static function messages( int $conversation_id, int $after, array $roles = array( 'user', 'assistant', 'agent', 'system' ) ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, role, content, sources, agent_id, created_at FROM %i WHERE conversation_id = %d AND id > %d AND role IN ($placeholders) ORDER BY id ASC LIMIT 200",
				array_merge( array( Installer::tables()['messages'], $conversation_id, $after ), $roles )
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * One message for the widget or the agent screen.
	 *
	 * @param array<string, mixed> $row Message row.
	 * @return array<string, mixed>
	 */
	public static function shape( array $row ): array {
		$meta = json_decode( (string) ( $row['sources'] ?? '' ), true );
		$live = is_array( $meta['live'] ?? null ) ? $meta['live'] : array();

		return array(
			'id'      => (int) $row['id'],
			'role'    => (string) $row['role'],
			'content' => (string) $row['content'],
			'event'   => (string) ( $live['event'] ?? '' ),
			'agent'   => is_array( $live['agent'] ?? null ) ? $live['agent'] : null,
			'time'    => (string) $row['created_at'],
		);
	}

	/**
	 * Public face of an agent: first name and avatar.
	 *
	 * @param int    $agent_id Agent.
	 * @param string $name     Name override.
	 * @return array{name: string, avatar: string}
	 */
	public static function agent( int $agent_id, string $name = '' ): array {
		$user = $agent_id > 0 ? get_userdata( $agent_id ) : false;

		if ( '' === $name ) {
			$name = $user ? trim( (string) $user->first_name ) : '';
			$name = '' !== $name ? $name : ( $user ? (string) $user->display_name : __( 'Support', 'all-in-one-ai-chatbot' ) );
		}

		return array(
			'name'   => mb_substr( $name, 0, 40 ),
			'avatar' => $user ? (string) get_avatar_url( $agent_id, array( 'size' => 64 ) ) : '',
		);
	}

	// ── Typing ──────────────────────────────────────────────────────────────

	/**
	 * Transient key for "is typing".
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $who             visitor|agent.
	 */
	private static function typing_key( int $conversation_id, string $who ): string {
		return 'softorio_ai_typing_' . $who . '_' . $conversation_id;
	}

	/**
	 * Mark someone as typing for a few seconds.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $who             visitor|agent.
	 * @param string $name            Agent name.
	 */
	public static function typing( int $conversation_id, string $who, string $name = '' ): void {
		set_transient( self::typing_key( $conversation_id, $who ), '' !== $name ? $name : '1', 8 );
	}

	/**
	 * Who is typing, or ''.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $who             visitor|agent.
	 */
	public static function is_typing( int $conversation_id, string $who ): string {
		$value = get_transient( self::typing_key( $conversation_id, $who ) );

		return is_string( $value ) ? $value : '';
	}

	// ── Inbox ───────────────────────────────────────────────────────────────

	/**
	 * Conversations waiting for a person.
	 */
	public static function waiting_count(): int {
		global $wpdb;

		if ( ! self::enabled() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE mode = 'waiting'", Installer::tables()['conversations'] ) );
	}

	/**
	 * Unread visitor messages in an agent's live chats.
	 *
	 * @param int $agent_id Agent.
	 */
	public static function unread_for( int $agent_id ): int {
		global $wpdb;

		$t = Installer::tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom tables.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i m INNER JOIN %i c ON c.id = m.conversation_id
				 WHERE c.mode = 'human' AND c.agent_id = %d AND m.role = 'user' AND m.id > c.agent_read_id",
				$t['messages'],
				$t['conversations'],
				$agent_id
			)
		);
	}

	/**
	 * The agent inbox: live and recent conversations.
	 *
	 * @param int $agent_id Current agent.
	 * @return array<int, array<string, mixed>>
	 */
	public static function inbox( int $agent_id ): array {
		global $wpdb;

		$t      = Installer::tables();
		$recent = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE mode IN ('waiting', 'human') OR updated_at >= %s
				 ORDER BY CASE mode WHEN 'waiting' THEN 0 WHEN 'human' THEN 1 ELSE 2 END, updated_at DESC LIMIT 60",
				$t['conversations'],
				$recent
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$id   = (int) $row['id'];
			$last = $wpdb->get_row( $wpdb->prepare( "SELECT role, content, created_at FROM %i WHERE conversation_id = %d AND role <> 'system' ORDER BY id DESC LIMIT 1", $t['messages'], $id ), ARRAY_A );

			$unread = 'ai' === $row['mode'] ? 0 : (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE conversation_id = %d AND role = 'user' AND id > %d", $t['messages'], $id, (int) $row['agent_read_id'] )
			);

			$out[] = array(
				'id'       => $id,
				'mode'     => (string) $row['mode'],
				'mine'     => 'human' === $row['mode'] && (int) $row['agent_id'] === $agent_id,
				'agent'    => (int) $row['agent_id'] > 0 ? self::agent( (int) $row['agent_id'] )['name'] : '',
				'visitor'  => self::visitor_label( $row ),
				'title'    => (string) $row['title'],
				'page'     => (string) $row['page_url'],
				'last'     => is_array( $last ) ? mb_substr( (string) $last['content'], 0, 120 ) : '',
				'last_by'  => is_array( $last ) ? (string) $last['role'] : '',
				'updated'  => (string) $row['updated_at'],
				'since'    => (string) ( $row['mode_since'] ?? '' ),
				'unread'   => $unread,
				'typing'   => '' !== self::is_typing( $id, 'visitor' ),
			);
		}
		// phpcs:enable

		return $out;
	}

	/**
	 * Mark a conversation read up to a message, for an agent.
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $message_id      Last message seen.
	 */
	public static function mark_read( int $conversation_id, int $message_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET agent_read_id = CASE WHEN agent_read_id < %d THEN %d ELSE agent_read_id END WHERE id = %d', Installer::tables()['conversations'], $message_id, $message_id, $conversation_id ) );
	}

	/**
	 * Who the visitor is, as far as we know.
	 *
	 * @param array<string, mixed> $row Conversation row.
	 */
	public static function visitor_label( array $row ): string {
		if ( (int) $row['lead_id'] > 0 ) {
			$lead = ( new LeadStore() )->find( (int) $row['lead_id'] );

			if ( is_array( $lead ) ) {
				$name = trim( (string) ( $lead['name'] ?? '' ) );

				if ( '' !== $name || '' !== (string) ( $lead['email'] ?? '' ) ) {
					return '' !== $name ? $name : (string) $lead['email'];
				}
			}
		}

		if ( (int) $row['user_id'] > 0 ) {
			$user = get_userdata( (int) $row['user_id'] );

			if ( $user ) {
				return (string) $user->display_name;
			}
		}

		/* translators: %s: short code identifying an anonymous visitor */
		return sprintf( __( 'Visitor %s', 'all-in-one-ai-chatbot' ), strtoupper( substr( (string) $row['public_id'], 0, 5 ) ) );
	}

	/**
	 * Details about a conversation for the agent's side panel.
	 *
	 * @param array<string, mixed> $row Conversation row.
	 * @return array<string, mixed>
	 */
	public static function details( array $row ): array {
		$lead = (int) $row['lead_id'] > 0 ? ( new LeadStore() )->find( (int) $row['lead_id'] ) : null;
		$user = (int) $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : false;

		return array(
			'id'      => (int) $row['id'],
			'mode'    => self::mode( $row ),
			'agent'   => (int) $row['agent_id'] > 0 ? self::agent( (int) $row['agent_id'] )['name'] : '',
			'agentId' => (int) $row['agent_id'],
			'visitor' => self::visitor_label( $row ),
			'email'   => is_array( $lead ) ? (string) ( $lead['email'] ?? '' ) : ( $user ? (string) $user->user_email : '' ),
			'phone'   => is_array( $lead ) ? (string) ( $lead['phone'] ?? '' ) : '',
			'account' => $user ? (string) $user->display_name : '',
			'userUrl' => $user && current_user_can( 'edit_users' ) ? (string) get_edit_user_link( (int) $row['user_id'] ) : '',
			'page'    => (string) $row['page_url'],
			'started' => (string) $row['created_at'],
			'since'   => (string) ( $row['mode_since'] ?? '' ),
		);
	}

	/**
	 * Event payload for live events.
	 *
	 * @param int $conversation_id Conversation.
	 * @return array<string, mixed>
	 */
	public static function payload( int $conversation_id ): array {
		$row = self::row( $conversation_id ) ?? array();

		$transcript = array_map(
			static fn( array $m ): array => array(
				'role'    => $m['role'],
				'content' => $m['content'],
			),
			array_slice( array_filter( ( new ConversationStore() )->transcript( $conversation_id ), static fn( array $m ): bool => 'system' !== $m['role'] ), -8 )
		);

		$lead = (int) ( $row['lead_id'] ?? 0 ) > 0 ? ( new LeadStore() )->find( (int) $row['lead_id'] ) : null;

		return array(
			'conversation_id' => (string) ( $row['public_id'] ?? '' ),
			'internal_id'     => $conversation_id,
			'visitor'         => array() !== $row ? self::visitor_label( $row ) : '',
			'page_url'        => (string) ( $row['page_url'] ?? '' ),
			'lead'            => is_array( $lead ) ? array_intersect_key( $lead, array_flip( array( 'name', 'email', 'phone', 'message' ) ) ) : array(),
			'transcript'      => array_values( $transcript ),
			'admin_url'       => Menu::url( 'live', array( 'conversation' => $conversation_id ) ),
		);
	}
}
