<?php
/**
 * Conversation persistence.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Support\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Stores conversations and their messages.
 *
 * Ownership rule: a conversation can only be resumed or read with the random
 * visitor token of the browser that started it. The conversation id alone is
 * not enough, so an id that leaks (a shared screenshot, a log line) does not
 * expose the chat. The token is stored only as a keyed hash.
 *
 * The token lives in the visitor's browser rather than being tied to their IP,
 * because mobile IPs change mid-conversation and many visitors share one IP
 * behind carrier NAT.
 */
final class ConversationStore {

	/** @var array{chunks: string, conversations: string, messages: string, limits: string} */
	private array $t;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->t = Installer::tables();
	}

	/**
	 * Resume a conversation the visitor owns, or start a new one.
	 *
	 * @param string $public_id     Conversation id from the widget, or ''.
	 * @param string $visitor_token Widget's random token.
	 * @param string $page_url      Page the chat started on.
	 * @return array{id: int, public_id: string, resumed: bool}
	 */
	public function resume_or_create( string $public_id, string $visitor_token, string $page_url ): array {
		if ( '' !== $public_id ) {
			$row = $this->find_owned( $public_id, $visitor_token );

			if ( null !== $row ) {
				return array(
					'id'        => (int) $row['id'],
					'public_id' => (string) $row['public_id'],
					'resumed'   => true,
				);
			}
		}

		global $wpdb;

		$now = current_time( 'mysql', true );
		$new = bin2hex( random_bytes( 16 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->insert(
			$this->t['conversations'],
			array(
				'public_id'    => $new,
				'visitor_hash' => Visitor::hash_token( $visitor_token ),
				'user_id'      => get_current_user_id(),
				'page_url'     => mb_substr( $page_url, 0, 2000, 'UTF-8' ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return array(
			'id'        => (int) $wpdb->insert_id,
			'public_id' => $new,
			'resumed'   => false,
		);
	}

	/**
	 * A conversation, only if this visitor token owns it.
	 *
	 * @param string $public_id     Conversation id.
	 * @param string $visitor_token Widget's random token.
	 * @return array<string, mixed>|null
	 */
	public function find_owned( string $public_id, string $visitor_token ): ?array {
		global $wpdb;

		if ( '' === $public_id || '' === $visitor_token ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id = %s', $this->t['conversations'], $public_id ), ARRAY_A );

		if ( ! is_array( $row ) || ! hash_equals( (string) $row['visitor_hash'], Visitor::hash_token( $visitor_token ) ) ) {
			return null;
		}

		// A conversation started while logged in stays with that account:
		// after logging out, or on a shared computer, the browser's token
		// alone must not reopen it (it may hold order or members-only details).
		if ( (int) $row['user_id'] > 0 && (int) $row['user_id'] !== get_current_user_id() ) {
			return null;
		}

		return $row;
	}

	/**
	 * A conversation by internal id.
	 *
	 * @param int $id Conversation id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->t['conversations'], $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Attach a lead to a conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $lead_id         Lead id.
	 */
	public function set_lead( int $conversation_id, int $lead_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->update( $this->t['conversations'], array( 'lead_id' => $lead_id ), array( 'id' => $conversation_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Recent turns to replay to the model, oldest first, strictly alternating.
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $turns           Most exchanges to include.
	 * @return array<int, array{role: string, content: string}>
	 */
	public function history( int $conversation_id, int $turns ): array {
		global $wpdb;

		if ( $turns <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM %i WHERE conversation_id = %d AND role <> 'system' ORDER BY id DESC LIMIT %d",
				$this->t['messages'],
				$conversation_id,
				$turns * 2
			),
			ARRAY_A
		);

		$history  = array();
		$expected = 'user';

		// Providers reject a history that does not alternate from a user turn,
		// so anything out of step (a failed request's orphan) is skipped.
		foreach ( array_reverse( (array) $rows ) as $row ) {
			// A person's replies are part of what the visitor was told, so
			// the AI sees them as the site's side of the conversation.
			if ( 'agent' === $row['role'] ) {
				$row['role']    = 'assistant';
				$row['content'] = '[Reply from a human support agent] ' . $row['content'];
			}

			if ( $row['role'] !== $expected ) {
				continue;
			}

			$history[] = array(
				'role'    => (string) $row['role'],
				'content' => (string) $row['content'],
			);
			$expected  = 'user' === $expected ? 'assistant' : 'user';
		}

		if ( array() !== $history && 'user' === end( $history )['role'] ) {
			array_pop( $history );
		}

		return $history;
	}

	/**
	 * Messages for redisplay in the widget.
	 *
	 * @param int $conversation_id Conversation.
	 * @return array<int, array{role: string, content: string, sources: array<int, mixed>}>
	 */
	public function transcript( int $conversation_id, int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE conversation_id = %d ORDER BY id ASC LIMIT %d',
				$this->t['messages'],
				$conversation_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$sources = json_decode( (string) ( $row['sources'] ?? '' ), true );
				$sources = is_array( $sources ) ? $sources : array();
				$cards   = array();
				$live    = array();

				// Live-chat messages store who wrote them, not sources.
				if ( isset( $sources['live'] ) ) {
					$live    = (array) $sources['live'];
					$sources = array();
				}

				// Answers with cards store {sources, cards}; plain ones a list.
				if ( isset( $sources['sources'] ) || isset( $sources['cards'] ) ) {
					$cards   = (array) ( $sources['cards'] ?? array() );
					$sources = (array) ( $sources['sources'] ?? array() );
				}

				return array(
					'id'            => (int) ( $row['id'] ?? 0 ),
					'rating'        => (int) ( $row['rating'] ?? 0 ),
					'role'          => (string) $row['role'],
					'content'       => (string) $row['content'],
					'sources'       => $sources,
					'cards'         => $cards,
					'provider'      => (string) ( $row['provider'] ?? '' ),
					'model'         => (string) ( $row['model'] ?? '' ),
					'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
					'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
					'cost'          => (float) ( $row['cost'] ?? 0 ),
					'created_at'    => (string) ( $row['created_at'] ?? '' ),
					'agent'         => is_array( $live['agent'] ?? null ) ? $live['agent'] : null,
					'event'         => (string) ( $live['event'] ?? '' ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Save one question and its answer.
	 *
	 * @param int                               $conversation_id Conversation.
	 * @param string                            $question        Visitor message.
	 * @param string                            $answer          Assistant reply.
	 * @param array<int, array<string, string>> $sources         Links shown with the answer.
	 * @param array<string, mixed>              $usage           provider, model, input_tokens, output_tokens, cost.
	 */
	public function add_exchange( int $conversation_id, string $question, string $answer, array $sources, array $usage ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables.
		$wpdb->insert(
			$this->t['messages'],
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'user',
				'content'         => $question,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$wpdb->insert(
			$this->t['messages'],
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
				'content'         => $answer,
				'sources'         => wp_json_encode( array() === ( $usage['cards'] ?? array() ) ? $sources : array( 'sources' => $sources, 'cards' => $usage['cards'] ) ),
				'provider'        => (string) ( $usage['provider'] ?? '' ),
				'model'           => (string) ( $usage['model'] ?? '' ),
				'input_tokens'    => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens'   => (int) ( $usage['output_tokens'] ?? 0 ),
				'cost'            => (float) ( $usage['cost'] ?? 0 ),
				'unanswered'      => ! empty( $usage['unanswered'] ) ? 1 : 0,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%s' )
		);

		$answer_id = (int) $wpdb->insert_id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET message_count = message_count + 2, total_cost = total_cost + %f, updated_at = %s, ended_at = NULL,
				 title = CASE WHEN title = '' THEN %s ELSE title END
				 WHERE id = %d",
				$this->t['conversations'],
				(float) ( $usage['cost'] ?? 0 ),
				$now,
				mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $question ) ), 0, 150, 'UTF-8' ),
				$conversation_id
			)
		);
		// phpcs:enable

		return $answer_id;
	}

	/**
	 * Record a visitor's rating of one answer in their conversation.
	 *
	 * @param int $conversation_id Conversation the visitor owns.
	 * @param int $message_id      Assistant message.
	 * @param int $rating          1 (helpful), -1 (not helpful) or 0 (cleared).
	 * @return bool Whether an answer in that conversation was updated.
	 */
	public function rate( int $conversation_id, int $message_id, int $rating ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET rating = %d WHERE id = %d AND conversation_id = %d AND role = 'assistant'",
				$this->t['messages'],
				max( -1, min( 1, $rating ) ),
				$message_id,
				$conversation_id
			)
		);

		// A rating that did not change still counts as the right message.
		return false !== $updated && ( $updated > 0 || null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE id = %d AND conversation_id = %d AND role = 'assistant'", $this->t['messages'], $message_id, $conversation_id ) ) );
	}

	/**
	 * Share of rated answers rated helpful, since a moment.
	 *
	 * @param string $since_gmt MySQL datetime, UTC.
	 * @return array{up: int, down: int}
	 */
	public function ratings_since( string $since_gmt ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS up, SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) AS down FROM %i WHERE role = 'assistant' AND created_at >= %s",
				$this->t['messages'],
				$since_gmt
			),
			ARRAY_A
		);

		return array(
			'up'   => (int) ( $row['up'] ?? 0 ),
			'down' => (int) ( $row['down'] ?? 0 ),
		);
	}

	/**
	 * Delete a conversation and its messages.
	 *
	 * @param int $conversation_id Conversation.
	 */
	public function delete( int $conversation_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom tables.
		$wpdb->delete( $this->t['messages'], array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( $this->t['conversations'], array( 'id' => $conversation_id ), array( '%d' ) );
		// phpcs:enable
	}

	/**
	 * Delete conversations idle for longer than the retention period.
	 *
	 * @param int $days Retention in days; 0 keeps everything.
	 * @return int Conversations removed.
	 */
	public function purge_older_than( int $days ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE updated_at < %s LIMIT 500', $this->t['conversations'], $cutoff ) );

		foreach ( $ids as $id ) {
			$this->delete( (int) $id );
		}

		return count( $ids );
	}

	/**
	 * Usage since a moment: answers and cost.
	 *
	 * @param string $since_gmt MySQL datetime, UTC.
	 * @return array{answers: int, cost: float}
	 */
	public function usage_since( string $since_gmt ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS answers, COALESCE(SUM(cost), 0) AS cost FROM %i WHERE role = 'assistant' AND created_at >= %s",
				$this->t['messages'],
				$since_gmt
			),
			ARRAY_A
		);

		return array(
			'answers' => (int) ( $row['answers'] ?? 0 ),
			'cost'    => (float) ( $row['cost'] ?? 0 ),
		);
	}
}
