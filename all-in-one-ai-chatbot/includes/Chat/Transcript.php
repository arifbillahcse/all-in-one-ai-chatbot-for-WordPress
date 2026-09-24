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

		$all = ( new ConversationStore() )->transcript( $conversation_id, 500 );
		$all = array_slice( $all, -$limit );

		return array_map(
			static fn( array $m ): array => array(
				'role'    => $m['role'],
				'content' => $m['content'],
				'at'      => $m['created_at'],
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
			$lines[] = self::speaker( $m['role'] ) . ': ' . $m['content'];
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
				esc_html( self::speaker( $m['role'] ) ),
				nl2br( esc_html( $m['content'] ) )
			);
		}

		return $html;
	}

	/**
	 * Display name of a message's author.
	 *
	 * @param string $role Message role.
	 */
	private static function speaker( string $role ): string {
		return match ( $role ) {
			'user'  => __( 'Visitor', 'all-in-one-ai-chatbot' ),
			'agent' => __( 'Agent', 'all-in-one-ai-chatbot' ),
			default => __( 'Assistant', 'all-in-one-ai-chatbot' ),
		};
	}
}
