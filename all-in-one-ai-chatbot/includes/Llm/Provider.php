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
	 * Messages use Anthropic's shape as the neutral format: `content` is a
	 * string, or a list of blocks (text, tool_use, tool_result) during a
	 * tool round. Providers with a different protocol convert.
	 *
	 * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages Turns, ending on user.
	 * @param int                                                                                 $max_tokens Output limit.
	 * @param array<int, ToolDefinition>                                                          $tools      Tools the model may call.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens, array $tools = array() ): LlmResponse;
}
