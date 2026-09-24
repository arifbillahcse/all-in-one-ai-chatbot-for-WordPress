<?php
/**
 * Who a tool is acting for.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Facts the server established about the visitor — never anything the model
 * or the visitor merely claimed.
 */
final class ToolContext {

	/**
	 * Constructor.
	 *
	 * @param int    $user_id         Logged-in WordPress user (verified by cookie + nonce), or 0.
	 * @param string $ip              Client IP, for rate limits.
	 * @param int    $conversation_id Internal conversation id, or 0.
	 * @param string $page_url        Page the visitor is on (same-site only).
	 */
	public function __construct(
		public readonly int $user_id = 0,
		public readonly string $ip = '',
		public readonly int $conversation_id = 0,
		public readonly string $page_url = '',
	) {
	}

	/**
	 * Whether the visitor is a logged-in user.
	 */
	public function logged_in(): bool {
		return $this->user_id > 0;
	}
}
