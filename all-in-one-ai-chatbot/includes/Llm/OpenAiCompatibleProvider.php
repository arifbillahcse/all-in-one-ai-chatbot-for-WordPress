<?php
/**
 * OpenAI Chat Completions protocol (OpenAI, DeepSeek, Gemini, OpenRouter).
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Every provider that speaks OpenAI's chat-completions protocol.
 *
 * They differ in small ways captured by the factories: the name of the
 * output-limit field, whether a reasoning-effort field is accepted, extra
 * headers, and whether the provider reports its own charge.
 */
final class OpenAiCompatibleProvider implements Provider {

	/**
	 * Constructor.
	 *
	 * @param string                $name             Provider id.
	 * @param string                $endpoint         Chat completions URL.
	 * @param string                $api_key          API key.
	 * @param string                $model            Model id.
	 * @param string                $token_param      Name of the output-limit field.
	 * @param string                $reasoning_effort Reasoning effort; '' to omit.
	 * @param array<string, string> $headers          Extra request headers.
	 * @param array<string, mixed>  $extra            Extra body fields.
	 * @param bool                  $stream_usage     Ask for usage in the final stream chunk.
	 * @param HttpClient|null       $http             HTTP client.
	 * @param StreamTransport|null  $stream           Streaming transport.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $endpoint,
		private readonly string $api_key,
		private readonly string $model,
		private readonly string $token_param = 'max_tokens',
		private readonly string $reasoning_effort = '',
		private readonly array $headers = array(),
		private readonly array $extra = array(),
		private readonly bool $stream_usage = true,
		private readonly ?HttpClient $http = null,
		private readonly ?StreamTransport $stream = null,
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

		return new self( 'openai', self::endpoint( 'openai', 'https://api.openai.com/v1/chat/completions' ), $api_key, $model, 'max_completion_tokens', $reasons ? $reasoning_effort : '' );
	}

	/**
	 * DeepSeek.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model id.
	 */
	public static function deepseek( string $api_key, string $model ): self {
		return new self( 'deepseek', self::endpoint( 'deepseek', 'https://api.deepseek.com/chat/completions' ), $api_key, $model );
	}

	/**
	 * Google Gemini, through Google's OpenAI-compatible endpoint.
	 *
	 * @param string $api_key          API key.
	 * @param string $model            Model id.
	 * @param string $reasoning_effort Thinking level, or ''.
	 */
	public static function gemini( string $api_key, string $model, string $reasoning_effort = '' ): self {
		// Thinking is a Gemini 2.5+ feature; older models reject the field.
		$thinks = (bool) preg_match( '/^gemini-(2\.5|[3-9])/', $model );

		return new self( 'gemini', self::endpoint( 'gemini', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' ), $api_key, $model, 'max_tokens', $thinks ? $reasoning_effort : '', array(), array(), false );
	}

	/**
	 * OpenRouter: one key, many vendors' models.
	 *
	 * @param string $api_key API key.
	 * @param string $model   Model id, "vendor/model".
	 */
	public static function openrouter( string $api_key, string $model ): self {
		return new self(
			'openrouter',
			self::endpoint( 'openrouter', 'https://openrouter.ai/api/v1/chat/completions' ),
			$api_key,
			$model,
			'max_tokens',
			'',
			array(
				// Identifies the site in OpenRouter's dashboard.
				'HTTP-Referer' => home_url( '/' ),
				'X-Title'      => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			// Ask OpenRouter to report the real charge, so cost figures are exact.
			array( 'usage' => array( 'include' => true ) ),
			false
		);
	}

	/**
	 * Endpoint URL, filterable (proxies, EU endpoints, local testing).
	 *
	 * @param string $provider Provider id.
	 * @param string $default  Default URL.
	 */
	private static function endpoint( string $provider, string $default ): string {
		/**
		 * Filter a provider's API endpoint.
		 *
		 * @param string $url      Endpoint.
		 * @param string $provider Provider id.
		 */
		return (string) apply_filters( 'softorio_ai_provider_endpoint', $default, $provider );
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
	 * @param array<int, array<string, mixed>>                $messages   Turns.
	 * @param int                                             $max_tokens Output limit.
	 * @param array<int, ToolDefinition>                      $tools      Tools the model may call.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens, array $tools = array() ): LlmResponse {
		$body = ( $this->http ?? new HttpClient() )->post_json(
			$this->name,
			$this->endpoint,
			$this->auth_headers(),
			$this->request( $system, $messages, $max_tokens, $tools )
		);

		$choice  = is_array( $body['choices'][0] ?? null ) ? $body['choices'][0] : array();
		$message = is_array( $choice['message'] ?? null ) ? $choice['message'] : array();

		if ( array() === $choice ) {
			throw new LlmException( 'The response had no choices.', $this->name, 'malformed' );
		}

		$calls = array();

		foreach ( (array) ( $message['tool_calls'] ?? array() ) as $call ) {
			$function = is_array( $call['function'] ?? null ) ? $call['function'] : array();
			$args     = json_decode( (string) ( $function['arguments'] ?? '' ), true );

			// Arguments arrive as a JSON string here, unlike Claude's object.
			$calls[] = new ToolCall( (string) ( $call['id'] ?? '' ), (string) ( $function['name'] ?? '' ), is_array( $args ) ? $args : array() );
		}

		return $this->response(
			is_string( $message['content'] ?? null ) ? $message['content'] : '',
			(string) ( $body['model'] ?? $this->model ),
			is_array( $body['usage'] ?? null ) ? $body['usage'] : array(),
			(string) ( $choice['finish_reason'] ?? '' ),
			$calls
		);
	}

	/**
	 * Generate an answer, passing text to $on_text as it arrives.
	 *
	 * Tool calls arrive in fragments (name first, arguments in pieces) keyed
	 * by index, and are assembled before being returned.
	 *
	 * @param string                           $system     System prompt.
	 * @param array<int, array<string, mixed>> $messages   Turns.
	 * @param int                              $max_tokens Output limit.
	 * @param array<int, ToolDefinition>       $tools      Tools.
	 * @param callable(string): void           $on_text    Receives text deltas.
	 * @throws LlmException On failure.
	 */
	public function stream( string $system, array $messages, int $max_tokens, array $tools, callable $on_text ): LlmResponse {
		$request           = $this->request( $system, $messages, $max_tokens, $tools );
		$request['stream'] = true;

		if ( $this->stream_usage ) {
			$request['stream_options'] = array( 'include_usage' => true );
		}

		$text   = '';
		$model  = $this->model;
		$usage  = array();
		$finish = '';
		$parts  = array();

		( $this->stream ?? new StreamTransport() )->post(
			$this->name,
			$this->endpoint,
			$this->auth_headers(),
			$request,
			static function ( string $event, string $data ) use ( &$text, &$model, &$usage, &$finish, &$parts, $on_text ): void {
				if ( '[DONE]' === trim( $data ) ) {
					return;
				}

				$chunk = json_decode( $data, true );

				if ( ! is_array( $chunk ) ) {
					return;
				}

				if ( isset( $chunk['error'] ) ) {
					throw new LlmException( (string) ( $chunk['error']['message'] ?? 'Stream error' ), '', 'server' );
				}

				$model = (string) ( $chunk['model'] ?? $model );

				if ( is_array( $chunk['usage'] ?? null ) ) {
					$usage = $chunk['usage'];
				}

				$choice = is_array( $chunk['choices'][0] ?? null ) ? $chunk['choices'][0] : array();
				$delta  = is_array( $choice['delta'] ?? null ) ? $choice['delta'] : array();

				if ( ! empty( $choice['finish_reason'] ) ) {
					$finish = (string) $choice['finish_reason'];
				}

				if ( is_string( $delta['content'] ?? null ) && '' !== $delta['content'] ) {
					$text .= $delta['content'];
					$on_text( $delta['content'] );
				}

				foreach ( (array) ( $delta['tool_calls'] ?? array() ) as $fragment ) {
					$index = (int) ( $fragment['index'] ?? 0 );
					$parts[ $index ] ??= array( 'id' => '', 'name' => '', 'arguments' => '' );

					if ( ! empty( $fragment['id'] ) ) {
						$parts[ $index ]['id'] = (string) $fragment['id'];
					}
					if ( ! empty( $fragment['function']['name'] ) ) {
						$parts[ $index ]['name'] .= (string) $fragment['function']['name'];
					}
					if ( isset( $fragment['function']['arguments'] ) ) {
						$parts[ $index ]['arguments'] .= (string) $fragment['function']['arguments'];
					}
				}
			}
		);

		ksort( $parts );
		$calls = array();

		foreach ( $parts as $part ) {
			$args    = json_decode( '' !== $part['arguments'] ? $part['arguments'] : '{}', true );
			$calls[] = new ToolCall( $part['id'], $part['name'], is_array( $args ) ? $args : array() );
		}

		return $this->response( $text, $model, $usage, $finish, $calls );
	}

	/**
	 * Request body shared by both modes.
	 *
	 * @param string                           $system     System prompt.
	 * @param array<int, array<string, mixed>> $messages   Turns.
	 * @param int                              $max_tokens Output limit.
	 * @param array<int, ToolDefinition>       $tools      Tools.
	 * @return array<string, mixed>
	 * @throws LlmException When no key is saved.
	 */
	private function request( string $system, array $messages, int $max_tokens, array $tools ): array {
		if ( '' === $this->api_key ) {
			throw new LlmException( 'No API key is saved for ' . $this->name . '.', $this->name, 'not_configured' );
		}

		$request = array(
			'model'            => $this->model,
			'messages'         => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), self::convert_messages( $messages ) ),
			$this->token_param => max( 1, $max_tokens ),
		) + $this->extra;

		if ( '' !== $this->reasoning_effort ) {
			$request['reasoning_effort'] = $this->reasoning_effort;
		}

		if ( array() !== $tools ) {
			$request['tools'] = array_map( static fn( ToolDefinition $t ): array => $t->to_openai(), $tools );
		}

		return $request;
	}

	/**
	 * Headers with authentication.
	 *
	 * @return array<string, string>
	 */
	private function auth_headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key ) + $this->headers;
	}

	/**
	 * Build the neutral response.
	 *
	 * @param string               $text   Answer text.
	 * @param string               $model  Model that answered.
	 * @param array<string, mixed> $usage  Usage block.
	 * @param string               $finish Finish reason.
	 * @param array<int, ToolCall> $calls  Tool calls.
	 */
	private function response( string $text, string $model, array $usage, string $finish, array $calls ): LlmResponse {
		return new LlmResponse(
			trim( $text ),
			$this->name,
			$model,
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			'length' === $finish,
			$calls,
			isset( $usage['cost'] ) && is_numeric( $usage['cost'] ) ? (float) $usage['cost'] : null
		);
	}

	/**
	 * Translate the neutral (Anthropic-shaped) messages into this protocol.
	 *
	 * Where the two differ: a tool call is a `tool_calls` array on the
	 * assistant message with JSON-string arguments, and each tool result is
	 * its own `role: tool` message rather than a block in a user turn.
	 *
	 * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages Neutral messages.
	 * @return array<int, array<string, mixed>>
	 */
	public static function convert_messages( array $messages ): array {
		$out = array();

		foreach ( $messages as $message ) {
			if ( is_string( $message['content'] ) ) {
				$out[] = array(
					'role'    => $message['role'],
					'content' => $message['content'],
				);
				continue;
			}

			$text    = array();
			$calls   = array();
			$results = array();

			foreach ( $message['content'] as $block ) {
				switch ( $block['type'] ?? '' ) {
					case 'text':
						$text[] = (string) $block['text'];
						break;

					case 'tool_use':
						$calls[] = array(
							'id'       => (string) $block['id'],
							'type'     => 'function',
							'function' => array(
								'name'      => (string) $block['name'],
								'arguments' => (string) wp_json_encode( (object) ( $block['input'] ?? array() ) ),
							),
						);
						break;

					case 'tool_result':
						$results[] = array(
							'role'         => 'tool',
							'tool_call_id' => (string) $block['tool_use_id'],
							'content'      => is_string( $block['content'] ?? null ) ? $block['content'] : (string) wp_json_encode( $block['content'] ?? '' ),
						);
						break;
				}
			}

			if ( array() !== $results ) {
				array_push( $out, ...$results );
				continue;
			}

			$entry = array(
				'role'    => $message['role'],
				'content' => array() === $text && array() !== $calls ? null : implode( "\n", $text ),
			);

			if ( array() !== $calls ) {
				$entry['tool_calls'] = $calls;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
