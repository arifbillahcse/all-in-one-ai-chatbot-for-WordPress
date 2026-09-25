<?php
/**
 * Token prices, for cost estimates and the daily budget.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * USD per million tokens.
 *
 * Estimates only — the provider's invoice is authoritative, and prices change.
 * An unknown model falls back to its provider's most expensive listed rate, so
 * the daily budget errs towards stopping early rather than overspending.
 * Override with the `softorio_ai_pricing` filter.
 */
final class Pricing {

	/**
	 * Price table.
	 *
	 * @return array<string, array<string, array{input: float, output: float}>>
	 */
	public static function table(): array {
		$table = array(
			'openai'   => array(
				'gpt-5-nano'   => array( 'input' => 0.05, 'output' => 0.40 ),
				'gpt-5-mini'   => array( 'input' => 0.25, 'output' => 2.00 ),
				'gpt-5'        => array( 'input' => 1.25, 'output' => 10.00 ),
				'gpt-4.1-mini' => array( 'input' => 0.40, 'output' => 1.60 ),
				'gpt-4o-mini'  => array( 'input' => 0.15, 'output' => 0.60 ),
			),
			'claude'   => array(
				'claude-haiku-4-5' => array( 'input' => 1.00, 'output' => 5.00 ),
				'claude-sonnet-5'  => array( 'input' => 3.00, 'output' => 15.00 ),
			),
			'gemini'   => array(
				'gemini-2.5-flash-lite' => array( 'input' => 0.10, 'output' => 0.40 ),
				'gemini-2.5-flash'      => array( 'input' => 0.30, 'output' => 2.50 ),
				'gemini-2.5-pro'        => array( 'input' => 1.25, 'output' => 10.00 ),
			),
			'deepseek' => array(
				'deepseek-chat'     => array( 'input' => 0.28, 'output' => 0.42 ),
				'deepseek-reasoner' => array( 'input' => 0.28, 'output' => 0.42 ),
			),
		);

		/**
		 * Filter the token price table (USD per million tokens).
		 *
		 * @param array $table provider => model => {input, output}.
		 */
		return (array) apply_filters( 'softorio_ai_pricing', $table );
	}

	/**
	 * Estimated cost of one call.
	 *
	 * @param string $provider      Provider id.
	 * @param string $model         Model id.
	 * @param int    $input_tokens  Prompt tokens.
	 * @param int    $output_tokens Completion tokens.
	 */
	public static function cost( string $provider, string $model, int $input_tokens, int $output_tokens ): float {
		$models = self::table()[ $provider ] ?? array();
		$rate   = self::match( $models, $model );

		if ( null === $rate ) {
			return 0.0;
		}

		return ( $input_tokens * $rate['input'] + $output_tokens * $rate['output'] ) / 1_000_000;
	}

	/**
	 * Rate for a model: exact id, then the longest listed prefix (dated
	 * snapshots like "claude-haiku-4-5-20251001"), then the priciest listed.
	 *
	 * @param array<string, array{input: float, output: float}> $models Rates.
	 * @param string                                            $model  Model id.
	 * @return array{input: float, output: float}|null
	 */
	private static function match( array $models, string $model ): ?array {
		if ( array() === $models ) {
			return null;
		}

		if ( isset( $models[ $model ] ) ) {
			return $models[ $model ];
		}

		$best = null;

		foreach ( array_keys( $models ) as $id ) {
			if ( str_starts_with( $model, $id ) && ( null === $best || strlen( $id ) > strlen( $best ) ) ) {
				$best = $id;
			}
		}

		if ( null !== $best ) {
			return $models[ $best ];
		}

		uasort( $models, static fn( array $a, array $b ): int => $b['output'] <=> $a['output'] );

		return reset( $models );
	}
}
