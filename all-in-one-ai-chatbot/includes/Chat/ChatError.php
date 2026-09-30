<?php
/**
 * A chat request that cannot be answered.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a visitor-safe message and the HTTP status to send it with.
 */
final class ChatError extends \RuntimeException {

	/**
	 * Per-field messages for form validation errors.
	 *
	 * @var array<string, string>
	 */
	public array $fields = array();

	/**
	 * Constructor.
	 *
	 * @param string $error_code  Machine-readable code for the widget.
	 * @param string $message     Visitor-facing message; never provider internals.
	 * @param int    $status      HTTP status.
	 * @param int    $retry_after Seconds until a retry makes sense, when limited.
	 */
	public function __construct(
		public readonly string $error_code,
		string $message,
		public readonly int $status = 400,
		public readonly int $retry_after = 0,
	) {
		parent::__construct( $message );
	}
}
