<?php
/**
 * Anthropic Messages API.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic Claude.
 */
final class ClaudeProvider implements Provider {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const VERSION  = '2023-06-01';

	/**
	 * Constructor.
	 *
	 * @param string          $api_key API key.
	 * @param string          $model   Model id.
	 * @param HttpClient|null $http    HTTP client.
	 */
	public function __construct(
		private readonly string $api_key,
		private readonly string $model,
		private readonly ?HttpClient $http = null,
	) {
	}

	/**
	 * Provider id.
	 */
	public function name(): string {
		return 'claude';
	}

	/**
	 * Generate an answer.
	 *
	 * @param string                                          $system     System prompt.
	 * @param array<int, array{role: string, content: string}> $messages  Turns.
	 * @param int                                             $max_tokens Output limit.
	 * @param array<int, ToolDefinition>                      $tools      Tools the model may call.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens, array $tools = array() ): LlmResponse {
		if ( '' === $this->api_key ) {
			throw new LlmException( 'No Anthropic API key is saved.', $this->name(), 'not_configured' );
		}

		// The system prompt is a top-level field here; inside `messages` it is a 400.
		$request = array(
			'model'      => $this->model,
			'max_tokens' => max( 1, $max_tokens ),
			'system'     => $system,
			'messages'   => $messages,
		);

		if ( array() !== $tools ) {
			$request['tools'] = array_map( static fn( ToolDefinition $t ): array => $t->to_claude(), $tools );
		}

		$body = ( $this->http ?? new HttpClient() )->post_json(
			$this->name(),
			self::ENDPOINT,
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::VERSION,
			),
			$request
		);

		$text  = '';
		$calls = array();

		foreach ( (array) ( $body['content'] ?? array() ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) ) {
				$text .= $block['text'];
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$calls[] = new ToolCall( (string) ( $block['id'] ?? '' ), (string) ( $block['name'] ?? '' ), is_array( $block['input'] ?? null ) ? $block['input'] : array() );
			}
		}

		$usage = is_array( $body['usage'] ?? null ) ? $body['usage'] : array();

		return new LlmResponse(
			trim( $text ),
			$this->name(),
			(string) ( $body['model'] ?? $this->model ),
			(int) ( $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['output_tokens'] ?? 0 ),
			'max_tokens' === ( $body['stop_reason'] ?? '' ),
			$calls
		);
	}
}
