<?php
/**
 * Optional semantic search: OpenAI embeddings.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

use Softorio\AiAssistant\Llm\HttpClient;
use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns text into vectors so questions can match answers that use different
 * words ("money back" finding a "refund policy" article).
 *
 * Off by default. Keyword search is free and good enough for most small sites;
 * embeddings cost a little per indexed chunk and per question, and need an
 * OpenAI key even when answers come from another provider.
 */
final class Embedder {

	private const MODEL    = 'text-embedding-3-small';
	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	/**
	 * Whether semantic search is switched on and has a key to work with.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'semantic_search', false ) && '' !== Settings::api_key( 'openai' );
	}

	/**
	 * Embed several texts in one request.
	 *
	 * @param array<int, string> $texts Texts, in order.
	 * @return array<int, string> packed vectors, same order and keys
	 * @throws LlmException On API failure.
	 */
	public function embed_many( array $texts ): array {
		if ( array() === $texts ) {
			return array();
		}

		$texts = array_values( $texts );

		$body = ( new HttpClient() )->post_json(
			'openai',
			self::ENDPOINT,
			array( 'Authorization' => 'Bearer ' . Settings::api_key( 'openai' ) ),
			array(
				'model' => self::MODEL,
				'input' => array_map( static fn( string $t ): string => mb_substr( $t, 0, 8000, 'UTF-8' ), $texts ),
			),
			30
		);

		$out = array();

		foreach ( (array) ( $body['data'] ?? array() ) as $item ) {
			if ( is_array( $item ) && isset( $item['index'], $item['embedding'] ) && is_array( $item['embedding'] ) ) {
				$out[ (int) $item['index'] ] = VectorMath::pack( $item['embedding'] );
			}
		}

		if ( count( $out ) !== count( $texts ) ) {
			throw new LlmException( 'Embedding response did not cover every input.', 'openai', 'malformed' );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Embed one text. Returns '' on failure — search then falls back to keywords.
	 *
	 * @param string $text Text.
	 */
	public function embed_one( string $text ): string {
		try {
			return $this->embed_many( array( $text ) )[0] ?? '';
		} catch ( LlmException $e ) {
			return '';
		}
	}
}
