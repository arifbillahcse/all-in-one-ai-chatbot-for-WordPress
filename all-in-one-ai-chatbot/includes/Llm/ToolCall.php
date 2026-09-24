<?php
/**
 * A tool call requested by the model.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * One requested call: id (to match the result), tool name and arguments.
 */
final class ToolCall {

	/**
	 * Constructor.
	 *
	 * @param string               $id        Provider's call id.
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Decoded arguments.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $arguments,
	) {
	}
}
