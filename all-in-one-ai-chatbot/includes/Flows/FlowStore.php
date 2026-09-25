<?php
/**
 * Quick replies and flows.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the owner's button menu tree.
 *
 * A node is a button. When a visitor taps it, the widget shows its reply and
 * then does its action — entirely in the browser, with no AI call and no
 * cost. Buttons can open sub-menus, so common journeys ("Delivery → Inside
 * Dhaka") need no AI at all, and the "ask_ai" action hands anything else to
 * the assistant.
 *
 * Node: {id, label, action, reply, url, prompt, children[]}.
 */
final class FlowStore {

	public const OPTION    = 'softorio_ai_flows';
	public const ACTIONS   = array( 'reply', 'ask_ai', 'link', 'lead', 'human' );
	public const MAX_DEPTH = 4;
	public const MAX_NODES = 80;

	/**
	 * The saved tree.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get(): array {
		$tree = get_option( self::OPTION, array() );

		return is_array( $tree ) ? $tree : array();
	}

	/**
	 * Validate and save a tree (e.g. JSON from the builder).
	 *
	 * @param mixed $tree Decoded tree.
	 * @return array<int, array<string, mixed>> What was saved.
	 */
	public static function save( mixed $tree ): array {
		$count = 0;
		$clean = self::sanitize( is_array( $tree ) ? $tree : array(), 1, $count );

		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Clean a list of nodes, recursively, within depth and size limits.
	 *
	 * The tree is shown to every visitor, so every string is plain text and
	 * every URL is checked, whatever the builder sent.
	 *
	 * @param array<int|string, mixed> $nodes Nodes.
	 * @param int                      $depth Current depth (1 = top menu).
	 * @param int                      $count Nodes kept so far, across the tree.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize( array $nodes, int $depth, int &$count ): array {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || $count >= self::MAX_NODES ) {
				continue;
			}

			$label = mb_substr( trim( sanitize_text_field( (string) ( $node['label'] ?? '' ) ) ), 0, 40 );

			if ( '' === $label ) {
				continue;
			}

			$action = in_array( $node['action'] ?? '', self::ACTIONS, true ) ? (string) $node['action'] : 'reply';
			$id     = sanitize_key( (string) ( $node['id'] ?? '' ) );

			++$count;

			$clean = array(
				'id'       => '' !== $id ? $id : 'n' . substr( md5( $label . $count . wp_rand() ), 0, 8 ),
				'label'    => $label,
				'action'   => $action,
				'reply'    => mb_substr( trim( sanitize_textarea_field( (string) ( $node['reply'] ?? '' ) ) ), 0, 1000 ),
				'url'      => 'link' === $action ? self::clean_url( (string) ( $node['url'] ?? '' ) ) : '',
				'prompt'   => 'ask_ai' === $action ? mb_substr( trim( sanitize_text_field( (string) ( $node['prompt'] ?? '' ) ) ), 0, 300 ) : '',
				'children' => array(),
			);

			// Only "reply" buttons open a sub-menu; the others end the path.
			if ( 'reply' === $action && $depth < self::MAX_DEPTH && is_array( $node['children'] ?? null ) ) {
				$clean['children'] = self::sanitize( $node['children'], $depth + 1, $count );
			}

			if ( 'link' === $action && '' === $clean['url'] ) {
				$clean['action'] = 'reply';
			}

			$out[] = $clean;
		}

		return $out;
	}

	/**
	 * A link target, or '' when it is not a real link.
	 *
	 * esc_url_raw() alone would "repair" any text into http://text, so the
	 * scheme is required up front.
	 *
	 * @param string $url Submitted URL.
	 */
	private static function clean_url( string $url ): string {
		$url = trim( $url );

		if ( ! preg_match( '#^(https?://[^\s/?\#]+|mailto:[^\s]+@|tel:\+?[0-9])#i', $url ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * A starting point the owner can edit, for "Load an example".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function example(): array {
		return array(
			array(
				'label'    => __( '🚚 Delivery', 'all-in-one-ai-chatbot' ),
				'action'   => 'reply',
				'reply'    => __( 'Where should we deliver?', 'all-in-one-ai-chatbot' ),
				'children' => array(
					array(
						'label'  => __( 'Inside Dhaka', 'all-in-one-ai-chatbot' ),
						'action' => 'reply',
						'reply'  => __( 'Inside Dhaka: 1–2 days, 60 Taka.', 'all-in-one-ai-chatbot' ),
					),
					array(
						'label'  => __( 'Outside Dhaka', 'all-in-one-ai-chatbot' ),
						'action' => 'reply',
						'reply'  => __( 'Outside Dhaka: 3–5 days, 120 Taka.', 'all-in-one-ai-chatbot' ),
					),
				),
			),
			array(
				'label'  => __( '↩️ Returns', 'all-in-one-ai-chatbot' ),
				'action' => 'ask_ai',
				'prompt' => __( 'What is your return and refund policy?', 'all-in-one-ai-chatbot' ),
			),
			array(
				'label'  => __( '🙋 Talk to a person', 'all-in-one-ai-chatbot' ),
				'action' => 'human',
				'reply'  => __( 'Sure — here is how to reach our team.', 'all-in-one-ai-chatbot' ),
			),
		);
	}
}
