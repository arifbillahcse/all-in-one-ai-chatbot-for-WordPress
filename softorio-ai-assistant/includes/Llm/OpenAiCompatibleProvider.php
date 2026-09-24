<?php
/**
 * OpenAI Chat Completions protocol (OpenAI and DeepSeek).
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI, and DeepSeek which speaks the same protocol.
 *
 * The two differ in small ways the constructor captures: OpenAI's current
 * models take `max_completion_tokens` and only their default temperature,
 * DeepSeek still takes `max_tokens`.
 */
final class OpenAiCompatibleProvider implements Provider {

	/**
	 * Constructor.
	 *
	 * @param string          $name             Provider id.
	 * @param string          $endpoint         Chat completions URL.
	 * @param string          $api_key          API key.
	 * @param string          $model            Model id.
	 * @param string          $token_param      Name of the output-limit field.
	 * @param string          $reasoning_effort Reasoning effort for reasoning models; '' to omit.
	 * @param HttpClient|null $http             HTTP client.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $endpoint,
		private readonly string $api_key,
		private readonly string $model,
		private readonly string $token_param = 'max_tokens',
		private readonly string $reasoning_effort = '',
		private readonly ?HttpClient $http = null,
	) {
	}

	/**
	 * OpenAI.
	 *
	 * @param string $api_key          API key.
	 * @param string $model            Model id.
	 * @param string $reasoning_effort Reasoning effort, or ''.
	 */
	public static function openai( string $api_key, string $model, string $reasoning_effort = '' ): self {
		// Only reasoning models (gpt-5 family, o-series) accept the effort
		// parameter; sending it to others is a 400.
		$reasons = (bool) preg_match( '/^(gpt-5|o\d)/', $model );

		return new self(
			'openai',
			'https://api.openai.com/v1/chat/completions',
			$api_key,
			$model,
			'max_completion_tokens',
			$reasons ? $reasoning_effort : ''
		);
	}

	/**
	 * DeepSeek.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model id.
	 */
	public static function deepseek( string $api_key, string $model ): self {
		return new self( 'deepseek', 'https://api.deepseek.com/chat/completions', $api_key, $model );
	}

	/**
	 * Provider id.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Generate an answer.
	 *
	 * @param string                                          $system     System prompt.
	 * @param array<int, array{role: string, content: string}> $messages  Turns.
	 * @param int                                             $max_tokens Output limit.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens ): LlmResponse {
		if ( '' === $this->api_key ) {
			throw new LlmException( 'No API key is saved for ' . $this->name . '.', $this->name, 'not_configured' );
		}

		$request = array(
			'model'            => $this->model,
			'messages'         => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $messages ),
			$this->token_param => max( 1, $max_tokens ),
		);

		if ( '' !== $this->reasoning_effort ) {
			$request['reasoning_effort'] = $this->reasoning_effort;
		}

		$body = ( $this->http ?? new HttpClient() )->post_json(
			$this->name,
			$this->endpoint,
			array( 'Authorization' => 'Bearer ' . $this->api_key ),
			$request
		);

		$choice  = is_array( $body['choices'][0] ?? null ) ? $body['choices'][0] : array();
		$message = is_array( $choice['message'] ?? null ) ? $choice['message'] : array();
		$usage   = is_array( $body['usage'] ?? null ) ? $body['usage'] : array();

		if ( array() === $choice ) {
			throw new LlmException( 'The response had no choices.', $this->name, 'malformed' );
		}

		return new LlmResponse(
			trim( is_string( $message['content'] ?? null ) ? $message['content'] : '' ),
			$this->name,
			(string) ( $body['model'] ?? $this->model ),
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			'length' === ( $choice['finish_reason'] ?? '' )
		);
	}
}
