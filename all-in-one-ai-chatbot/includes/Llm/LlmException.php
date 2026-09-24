<?php
/**
 * Provider failure.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * A provider call failed.
 *
 * The message is for the site owner (logs, the "Test connection" button) and
 * may name the provider or quote its error. Visitors only ever see a generic
 * apology, chosen from $type.
 */
final class LlmException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message  Detail for the site owner.
	 * @param string $provider Provider id.
	 * @param string $type     not_configured|auth|rate_limited|timeout|network|server|bad_request|malformed.
	 * @param int    $status   HTTP status, when there was one.
	 */
	public function __construct(
		string $message,
		public readonly string $provider = '',
		public readonly string $type = 'server',
		public readonly int $status = 0,
	) {
		parent::__construct( $message );
	}

	/**
	 * Whether trying another provider could succeed where this one failed.
	 *
	 * A bad request would fail the same way anywhere; everything else is
	 * specific to this provider or this moment.
	 */
	public function is_retryable_elsewhere(): bool {
		return 'bad_request' !== $this->type;
	}
}
