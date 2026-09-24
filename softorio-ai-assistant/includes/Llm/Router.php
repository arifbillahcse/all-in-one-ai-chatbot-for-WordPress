<?php
/**
 * Chooses the provider for a request, with fallback.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a request to the primary provider, then the fallback if it fails.
 *
 * A single provider outage — or an expired card on one account — should cost a
 * visitor a slower answer, not no answer.
 */
final class Router {

	/**
	 * Build a provider from saved settings.
	 *
	 * @param string $id Provider id.
	 */
	public static function make( string $id ): ?Provider {
		$key   = Settings::api_key( $id );
		$model = Settings::model( $id );

		$provider = match ( $id ) {
			'openai'   => OpenAiCompatibleProvider::openai( $key, $model, (string) Settings::get( 'openai_reasoning', '' ) ),
			'claude'   => new ClaudeProvider( $key, $model ),
			'deepseek' => OpenAiCompatibleProvider::deepseek( $key, $model ),
			default    => null,
		};

		/**
		 * Filter the provider used for a given id — e.g. to add a new vendor.
		 *
		 * @param Provider|null $provider Provider instance.
		 * @param string        $id       Provider id.
		 */
		$provider = apply_filters( 'softorio_ai_provider', $provider, $id );

		return $provider instanceof Provider ? $provider : null;
	}

	/**
	 * Providers to try, in order.
	 *
	 * @return array<int, string>
	 */
	public static function chain(): array {
		$chain = array( (string) Settings::get( 'provider', 'openai' ) );

		$fallback = (string) Settings::get( 'fallback_provider', '' );

		if ( '' !== $fallback && ! in_array( $fallback, $chain, true ) && '' !== Settings::api_key( $fallback ) ) {
			$chain[] = $fallback;
		}

		return $chain;
	}

	/**
	 * Generate an answer.
	 *
	 * @param string                                          $system     System prompt.
	 * @param array<int, array{role: string, content: string}> $messages  Turns.
	 * @param int                                             $max_tokens Output limit.
	 * @throws LlmException When every provider failed; carries the last error.
	 */
	public function complete( string $system, array $messages, int $max_tokens ): LlmResponse {
		$last = null;

		foreach ( self::chain() as $id ) {
			$provider = self::make( $id );

			if ( null === $provider ) {
				continue;
			}

			try {
				$response = $provider->complete( $system, $messages, $max_tokens );

				// Reasoning models can spend the whole budget thinking and
				// return nothing visible. That is a failure, not an answer.
				if ( '' === $response->text ) {
					throw new LlmException(
						$response->truncated
							? 'The model used its whole output budget without answering. Raise "Max answer length" or pick a non-reasoning model.'
							: 'The model returned an empty answer.',
						$id,
						'empty'
					);
				}

				return $response;
			} catch ( LlmException $e ) {
				$last = $e;

				self::log_failure( $e );

				if ( ! $e->is_retryable_elsewhere() ) {
					break;
				}
			}
		}

		throw $last ?? new LlmException( 'No AI provider is configured.', '', 'not_configured' );
	}

	/**
	 * Remember the most recent failure so the dashboard can show it.
	 *
	 * @param LlmException $e Failure.
	 */
	private static function log_failure( LlmException $e ): void {
		update_option(
			'softorio_ai_last_error',
			array(
				'provider' => $e->provider,
				'type'     => $e->type,
				'message'  => mb_substr( $e->getMessage(), 0, 500, 'UTF-8' ),
				'time'     => time(),
			),
			false
		);
	}
}
