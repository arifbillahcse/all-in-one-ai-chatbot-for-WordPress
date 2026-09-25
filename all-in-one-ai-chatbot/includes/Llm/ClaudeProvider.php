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
	 * @param string               $api_key API key.
	 * @param string               $model   Model id.
	 * @param HttpClient|null      $http    HTTP client.
	 * @param StreamTransport|null $stream  Streaming transport.
	 */
	public function __construct(
		private readonly string $api_key,
		private readonly string $model,
		private readonly ?HttpClient $http = null,
		private readonly ?StreamTransport $stream = null,
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
	 * @param string                           $system     System prompt.
	 * @param array<int, array<string, mixed>> $messages   Turns.
	 * @param int                              $max_tokens Output limit.
	 * @param array<int, ToolDefinition>       $tools      Tools the model may call.
	 * @throws LlmException On failure.
	 */
	public function complete( string $system, array $messages, int $max_tokens, array $tools = array() ): LlmResponse {
		$body = ( $this->http ?? new HttpClient() )->post_json( $this->name(), self::endpoint(), $this->headers(), $this->request( $system, $messages, $max_tokens, $tools ) );

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

	/**
	 * Generate an answer, passing text to $on_text as it arrives.
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

		$text   = '';
		$model  = $this->model;
		$in     = 0;
		$out    = 0;
		$stop   = '';
		$blocks = array();

		( $this->stream ?? new StreamTransport() )->post(
			$this->name(),
			self::endpoint(),
			$this->headers(),
			$request,
			static function ( string $event, string $data ) use ( &$text, &$model, &$in, &$out, &$stop, &$blocks, $on_text ): void {
				$payload = json_decode( $data, true );

				if ( ! is_array( $payload ) ) {
					return;
				}

				switch ( $payload['type'] ?? $event ) {
					case 'message_start':
						$model = (string) ( $payload['message']['model'] ?? $model );
						$in    = (int) ( $payload['message']['usage']['input_tokens'] ?? 0 );
						break;

					case 'content_block_start':
						$block                             = (array) ( $payload['content_block'] ?? array() );
						$blocks[ (int) $payload['index'] ] = array(
							'type' => (string) ( $block['type'] ?? '' ),
							'id'   => (string) ( $block['id'] ?? '' ),
							'name' => (string) ( $block['name'] ?? '' ),
							'json' => '',
						);
						break;

					case 'content_block_delta':
						$delta = (array) ( $payload['delta'] ?? array() );
						$index = (int) ( $payload['index'] ?? 0 );

						if ( 'text_delta' === ( $delta['type'] ?? '' ) && '' !== (string) ( $delta['text'] ?? '' ) ) {
							$text .= (string) $delta['text'];
							$on_text( (string) $delta['text'] );
						} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) && isset( $blocks[ $index ] ) ) {
							$blocks[ $index ]['json'] .= (string) ( $delta['partial_json'] ?? '' );
						}
						break;

					case 'message_delta':
						$stop = (string) ( $payload['delta']['stop_reason'] ?? $stop );
						$out  = (int) ( $payload['usage']['output_tokens'] ?? $out );
						break;

					case 'error':
						throw new LlmException( (string) ( $payload['error']['message'] ?? 'Stream error' ), 'claude', 'server' );
				}
			}
		);

		ksort( $blocks );
		$calls = array();

		foreach ( $blocks as $block ) {
			if ( 'tool_use' === $block['type'] ) {
				$args    = json_decode( '' !== $block['json'] ? $block['json'] : '{}', true );
				$calls[] = new ToolCall( $block['id'], $block['name'], is_array( $args ) ? $args : array() );
			}
		}

		return new LlmResponse( trim( $text ), $this->name(), $model, $in, $out, 'max_tokens' === $stop, $calls );
	}

	/**
	 * Request body.
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

		return $request;
	}

	/**
	 * Authentication headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::VERSION,
		);
	}

	/**
	 * Endpoint URL, filterable (proxies, local testing).
	 */
	private static function endpoint(): string {
		/** This filter is documented in includes/Llm/OpenAiCompatibleProvider.php */
		return (string) apply_filters( 'softorio_ai_provider_endpoint', self::ENDPOINT, 'claude' );
	}
}
