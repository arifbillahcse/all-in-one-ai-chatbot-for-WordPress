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
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $provider,
		public readonly string $model,
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0,
		public readonly bool $truncated = false,
	) {
	}

	/**
	 * Estimated cost in USD.
	 */
	public function cost(): float {
		return Pricing::cost( $this->provider, $this->model, $this->input_tokens, $this->output_tokens );
	}
}
