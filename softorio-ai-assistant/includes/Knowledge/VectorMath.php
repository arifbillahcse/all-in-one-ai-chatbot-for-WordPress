<?php
/**
 * Vector helpers for semantic search.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Packing and cosine similarity.
 *
 * Vectors are stored as base64 of little-endian float32s: a quarter of the size
 * of JSON, and text-safe, so the database layer never has to handle raw binary
 * (which some hosts' charset checks mangle).
 */
final class VectorMath {

	/**
	 * Pack a vector for storage, normalised to unit length so similarity is a dot product.
	 *
	 * @param array<int, float|int> $vector Vector.
	 */
	public static function pack( array $vector ): string {
		return base64_encode( pack( 'g*', ...self::normalize( $vector ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary vector encoding.
	}

	/**
	 * Unpack a stored vector.
	 *
	 * @param string $packed Stored value.
	 * @return array<int, float>
	 */
	public static function unpack( string $packed ): array {
		$raw = base64_decode( $packed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary vector decoding.

		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$values = unpack( 'g*', $raw );

		return false === $values ? array() : array_values( $values );
	}

	/**
	 * Scale a vector to unit length.
	 *
	 * @param array<int, float|int> $vector Vector.
	 * @return array<int, float>
	 */
	public static function normalize( array $vector ): array {
		$sum = 0.0;

		foreach ( $vector as $value ) {
			$sum += (float) $value * (float) $value;
		}

		$length = sqrt( $sum );

		if ( $length <= 0.0 ) {
			return array_map( 'floatval', array_values( $vector ) );
		}

		return array_map( static fn( $v ): float => (float) $v / $length, array_values( $vector ) );
	}

	/**
	 * Cosine similarity of two unit vectors.
	 *
	 * @param array<int, float> $a Unit vector.
	 * @param array<int, float> $b Unit vector.
	 */
	public static function dot( array $a, array $b ): float {
		$n   = min( count( $a ), count( $b ) );
		$sum = 0.0;

		for ( $i = 0; $i < $n; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}

		return $sum;
	}
}
