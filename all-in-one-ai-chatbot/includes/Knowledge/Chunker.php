<?php
/**
 * Splits documents into retrievable pieces.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Splits text on natural boundaries — paragraphs first, then sentences.
 *
 * A chunk cut mid-sentence searches poorly and reads badly when quoted to the
 * model. Consecutive chunks overlap slightly so an answer that straddles a
 * boundary is still whole in at least one of them.
 */
final class Chunker {

	/**
	 * Constructor.
	 *
	 * @param int $max_chars     Largest chunk, in characters.
	 * @param int $overlap_chars Characters carried over from the previous chunk.
	 * @param int $min_chars     A final chunk smaller than this is merged back.
	 */
	public function __construct(
		private readonly int $max_chars = 1200,
		private readonly int $overlap_chars = 150,
		private readonly int $min_chars = 120,
	) {
	}

	/**
	 * Split text into chunks.
	 *
	 * @param string $text Plain text.
	 * @return array<int, string>
	 */
	public function chunk( string $text ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return array();
		}

		$max     = max( 200, $this->max_chars );
		$overlap = min( max( 0, $this->overlap_chars ), (int) floor( $max / 2 ) );

		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';

		foreach ( $this->units( $text, $max ) as $unit ) {
			$candidate = '' === $current ? $unit : $current . "\n\n" . $unit;

			if ( mb_strlen( $candidate, 'UTF-8' ) <= $max ) {
				$current = $candidate;
				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
				$tail     = $this->overlap_tail( $current, $overlap );
				$current  = '' === $tail ? $unit : $tail . "\n\n" . $unit;

				if ( mb_strlen( $current, 'UTF-8' ) > $max ) {
					$current = $unit;
				}

				continue;
			}

			$current = $unit;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $this->merge_small_tail( $chunks, $max );
	}

	/**
	 * Paragraph-sized units; oversized paragraphs split to sentences, then slices.
	 *
	 * @param string $text Text.
	 * @param int    $max  Largest unit.
	 * @return array<int, string>
	 */
	private function units( string $text, int $max ): array {
		$units = array();

		foreach ( preg_split( '/\n{2,}/u', $text ) ?: array() as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			if ( mb_strlen( $paragraph, 'UTF-8' ) <= $max ) {
				$units[] = $paragraph;
				continue;
			}

			// Sentence ends: . ! ? and the Bengali/Hindi danda.
			$sentences = preg_split( '/(?<=[.!?।])\s+/u', $paragraph ) ?: array( $paragraph );

			foreach ( $sentences as $sentence ) {
				$sentence = trim( $sentence );
				$length   = mb_strlen( $sentence, 'UTF-8' );

				if ( 0 === $length ) {
					continue;
				}

				for ( $offset = 0; $offset < $length; $offset += $max ) {
					$units[] = mb_substr( $sentence, $offset, $max, 'UTF-8' );
				}
			}
		}

		return $units;
	}

	/**
	 * The end of a chunk, starting at a word boundary.
	 *
	 * @param string $chunk   Chunk.
	 * @param int    $overlap Characters wanted.
	 */
	private function overlap_tail( string $chunk, int $overlap ): string {
		if ( $overlap <= 0 ) {
			return '';
		}

		$tail  = mb_substr( $chunk, -$overlap, null, 'UTF-8' );
		$space = mb_strpos( $tail, ' ', 0, 'UTF-8' );

		if ( false !== $space && $space < mb_strlen( $tail, 'UTF-8' ) - 1 ) {
			$tail = mb_substr( $tail, $space + 1, null, 'UTF-8' );
		}

		return trim( $tail );
	}

	/**
	 * Fold a tiny trailing chunk ("Thanks!") into the one before it.
	 *
	 * @param array<int, string> $chunks Chunks.
	 * @param int                $max    Largest chunk.
	 * @return array<int, string>
	 */
	private function merge_small_tail( array $chunks, int $max ): array {
		$count = count( $chunks );

		if ( $count < 2 || mb_strlen( $chunks[ $count - 1 ], 'UTF-8' ) >= $this->min_chars ) {
			return $chunks;
		}

		$merged = $chunks[ $count - 2 ] . "\n\n" . $chunks[ $count - 1 ];

		if ( mb_strlen( $merged, 'UTF-8' ) > $max + $this->min_chars ) {
			return $chunks;
		}

		array_splice( $chunks, $count - 2, 2, array( $merged ) );

		return $chunks;
	}
}
