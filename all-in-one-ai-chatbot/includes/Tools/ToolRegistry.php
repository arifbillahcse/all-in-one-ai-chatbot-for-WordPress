<?php
/**
 * The set of tools and their execution.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Tools;

use Softorio\AiAssistant\Llm\ToolCall;
use Softorio\AiAssistant\Llm\ToolDefinition;
use Softorio\AiAssistant\Support\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Collects tools from feature modules and runs the ones the model calls.
 *
 * Two rules live here so no individual tool can forget them:
 *  - a tool the model was not offered in this conversation is refused, even
 *    if the model names it (it may have been talked into guessing names);
 *  - a tool that throws becomes an error result, never a failed chat.
 */
final class ToolRegistry {

	/**
	 * Tools by name.
	 *
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, Tool>|null $tools Tools; defaults to those registered by modules.
	 */
	public function __construct( ?array $tools = null ) {
		/**
		 * Filter the tools the assistant can use.
		 *
		 * @param array<int, Tool> $tools Tools.
		 */
		$tools ??= (array) apply_filters( 'softorio_ai_tools', array() );

		foreach ( $tools as $tool ) {
			if ( $tool instanceof Tool ) {
				$this->tools[ $tool->name() ] = $tool;
			}
		}
	}

	/**
	 * Definitions of the tools offered in this context.
	 *
	 * @param ToolContext $context Who is asking.
	 * @return array<int, ToolDefinition>
	 */
	public function definitions( ToolContext $context ): array {
		$out = array();

		foreach ( $this->tools as $tool ) {
			if ( $tool->available( $context ) ) {
				$out[] = $tool->definition();
			}
		}

		return $out;
	}

	/**
	 * Run a call the model made.
	 *
	 * @param ToolCall    $call    The call.
	 * @param ToolContext $context Who is asking.
	 */
	public function execute( ToolCall $call, ToolContext $context ): ToolResult {
		$tool = $this->tools[ $call->name ] ?? null;

		if ( null === $tool || ! $tool->available( $context ) ) {
			Log::warning( 'tools', sprintf( 'Refused unknown or unavailable tool "%s"', mb_substr( $call->name, 0, 64 ) ) );

			return ToolResult::error( 'No such tool is available.' );
		}

		try {
			$result = $tool->run( $call->arguments, $context );
		} catch ( \Throwable $e ) {
			Log::error( 'tools', sprintf( '%s failed', $call->name ), array( 'error' => $e->getMessage() ) );

			return ToolResult::error( 'That lookup failed because of a technical problem. Suggest contacting the shop.' );
		}

		// Arguments can hold an email or phone number, so only the tool
		// name and outcome are logged.
		Log::info( 'tools', sprintf( '%s %s', $call->name, $result->is_error ? 'returned an error' : 'ran' ) );

		return $result;
	}
}
