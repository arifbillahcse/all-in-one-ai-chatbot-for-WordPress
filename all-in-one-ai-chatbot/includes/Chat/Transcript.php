<?php
/**
 * A conversation as text, for emails and integrations.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Formats a conversation's messages for people and machines.
 */
final class Transcript {

	/**
	 * Messages in a compact shape for event payloads.
	 *
	 * @param int $conversation_id Internal conversation id.
	 * @param int $limit           Most recent messages to include.
	 * @return array<int, array{role: string, content: string, at: string}>
	 */
	public static function messages( int $conversation_id, int $limit = 50 ): array {
		if ( $conversation_id <= 0 ) {
			return array();
		}

		// System notices ("Karim joined the chat") are not part of what was said.
		$all = array_filter( ( new ConversationStore() )->transcript( $conversation_id, 500 ), static fn( array $m ): bool => 'system' !== $m['role'] );
		$all = array_slice( array_values( $all ), -$limit );

		return array_map(
			static fn( array $m ): array => array(
				'role'    => $m['role'],
				'content' => $m['content'],
				'at'      => $m['created_at'],
				'agent'   => (string) ( $m['agent']['name'] ?? '' ),
			),
			$all
		);
	}

	/**
	 * Plain-text transcript.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 */
	public static function text( array $messages ): string {
		$lines = array();

		foreach ( $messages as $m ) {
			$lines[] = self::speaker( $m['role'], (string) ( $m['agent'] ?? '' ) ) . ': ' . $m['content'];
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * HTML transcript for emails. Everything is escaped.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 */
	public static function html( array $messages ): string {
		$html = '';

		foreach ( $messages as $m ) {
			$visitor = 'user' === $m['role'];

			$html .= sprintf(
				'<div style="margin:0 0 10px;padding:10px 14px;border-radius:10px;background:%s"><div style="font-size:12px;color:#6b7280;margin-bottom:4px">%s</div>%s</div>',
				$visitor ? '#eff6ff' : '#f3f4f6',
				esc_html( self::speaker( $m['role'], (string) ( $m['agent'] ?? '' ) ) ),
				nl2br( esc_html( $m['content'] ) )
			);
		}

		return $html;
	}

	/**
	 * Display name of a message's author.
	 *
	 * @param string $role  Message role.
	 * @param string $agent Agent's name, for agent messages.
	 */
	private static function speaker( string $role, string $agent = '' ): string {
		return match ( $role ) {
			'user'  => __( 'Visitor', 'all-in-one-ai-chatbot' ),
			'agent' => '' !== $agent ? $agent : __( 'Agent', 'all-in-one-ai-chatbot' ),
			default => __( 'Assistant', 'all-in-one-ai-chatbot' ),
		};
	}
}
