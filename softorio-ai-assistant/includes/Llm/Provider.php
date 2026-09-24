<?php
/**
 * Provider contract.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * One chat-completion backend.
 */
interface Provider {

	/**
	 * Provider id: openai, claude or deepseek.
	 */
	public function name(): string;

	/**
	 * Generate an answer.
	 *
	 * @param string                                          $system     System prompt.
	 * @param array<int, array{role: string, content: string}> $messages  Alternating user/assistant turns, ending on user.
	 * @param int                                             $max_tokens Output limit.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens ): LlmResponse;
}
