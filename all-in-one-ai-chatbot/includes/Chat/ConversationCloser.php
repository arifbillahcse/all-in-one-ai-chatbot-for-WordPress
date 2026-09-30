<?php
/**
 * Detects conversations that have ended.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

use Softorio\AiAssistant\Admin\Menu;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Leads\LeadStore;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Marks idle conversations as ended and announces them.
 *
 * A chat has no "hang up": the visitor just stops typing. After a period of
 * silence the conversation is treated as finished, which is when transcripts
 * are emailed and "conversation.ended" webhooks fire. A visitor who comes back
 * later simply reopens it, and it can end again.
 */
final class ConversationCloser {

	public const IDLE_MINUTES = 30;

	/**
	 * Piggy-back on the queue's one-minute tick, at most every five minutes.
	 */
	public static function init(): void {
		add_action( Queue::HOOK, array( self::class, 'maybe_run' ), 5 );
	}

	/**
	 * Throttled entry point.
	 */
	public static function maybe_run(): void {
		if ( false !== get_transient( 'softorio_ai_closer' ) ) {
			return;
		}

		set_transient( 'softorio_ai_closer', 1, 5 * MINUTE_IN_SECONDS );
		self::run();
	}

	/**
	 * End every conversation idle for longer than the threshold.
	 *
	 * @param int $idle_minutes Silence that counts as the end.
	 * @return int Conversations ended.
	 */
	public static function run( int $idle_minutes = self::IDLE_MINUTES ): int {
		global $wpdb;

		$t      = Installer::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $idle_minutes * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ended_at IS NULL AND message_count > 0 AND updated_at < %s ORDER BY id LIMIT 50',
				$t['conversations'],
				$cutoff
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			// Claim it first, so an overlapping run cannot announce it twice.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET ended_at = %s WHERE id = %d AND ended_at IS NULL',
					$t['conversations'],
					current_time( 'mysql', true ),
					(int) $row['id']
				)
			);

			if ( 1 !== (int) $claimed ) {
				continue;
			}

			Events::emit( Events::CONVERSATION_ENDED, self::payload( $row ) );
		}

		return count( $rows );
	}

	/**
	 * Event payload for an ended conversation.
	 *
	 * @param array<string, mixed> $row Conversation row.
	 * @return array<string, mixed>
	 */
	public static function payload( array $row ): array {
		$lead = (int) $row['lead_id'] > 0 ? ( new LeadStore() )->find( (int) $row['lead_id'] ) : null;

		return array(
			'conversation_id' => (string) $row['public_id'],
			'title'           => (string) $row['title'],
			'message_count'   => (int) $row['message_count'],
			'page_url'        => (string) $row['page_url'],
			'started_at'      => (string) $row['created_at'],
			'lead'            => null === $lead ? null : array(
				'id'    => (int) $lead['id'],
				'name'  => (string) $lead['name'],
				'email' => (string) $lead['email'],
				'phone' => (string) $lead['phone'],
			),
			'transcript'      => Transcript::messages( (int) $row['id'], 100 ),
			'admin_url'       => Menu::url( 'conversations', array( 'conversation' => (int) $row['id'] ) ),
		);
	}
}
