<?php
/**
 * A provider's answer.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral result of one completion.
 */
final class LlmResponse {

	/**
	 * Constructor.
	 *
	 * @param string $text          Answer text.
	 * @param string $provider      Provider id.
	 * @param string $model         Model that served the request.
	 * @param int    $input_tokens  Prompt tokens billed.
	 * @param int    $output_tokens Completion tokens billed.
	 * @param bool   $truncated     Whether the answer hit the token limit.
	 * @param array<int, ToolCall> $tool_calls Tools the model wants run before it answers.
	 * @param float|null           $reported_cost Exact cost reported by the provider, when it does.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $provider,
		public readonly string $model,
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0,
		public readonly bool $truncated = false,
		public readonly array $tool_calls = array(),
		public readonly ?float $reported_cost = null,
	) {
	}

	/**
	 * Whether the model asked for tools instead of (or before) answering.
	 */
	public function wants_tools(): bool {
		return array() !== $this->tool_calls;
	}

	/**
	 * Estimated cost in USD.
	 */
	public function cost(): float {
		// OpenRouter reports the exact charge; everyone else is estimated
		// from token counts.
		if ( null !== $this->reported_cost ) {
			return $this->reported_cost;
		}

		return Pricing::cost( $this->provider, $this->model, $this->input_tokens, $this->output_tokens );
	}
}
