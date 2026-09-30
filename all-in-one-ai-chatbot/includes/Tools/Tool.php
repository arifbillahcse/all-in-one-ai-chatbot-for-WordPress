<?php
/**
 * A capability the assistant can use mid-conversation.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Tools;

use Softorio\AiAssistant\Llm\ToolDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * A tool: what the model is told about it, when it is offered, what it does.
 *
 * Arguments come from the model, which is steered by visitor text and
 * retrieved content. Treat them as untrusted input: validate every value and
 * enforce every permission inside run(), never in the description.
 */
interface Tool {

	/**
	 * Unique name, as the model will call it.
	 */
	public function name(): string;

	/**
	 * Description and argument schema for the model.
	 */
	public function definition(): ToolDefinition;

	/**
	 * Whether to offer this tool in the current conversation.
	 *
	 * @param ToolContext $context Who is asking.
	 */
	public function available( ToolContext $context ): bool;

	/**
	 * Do the work.
	 *
	 * @param array<string, mixed> $arguments Model-supplied arguments (untrusted).
	 * @param ToolContext          $context   Who is asking.
	 */
	public function run( array $arguments, ToolContext $context ): ToolResult;
}
