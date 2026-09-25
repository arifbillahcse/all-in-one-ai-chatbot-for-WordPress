<?php
/**
 * Text to search terms.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns text into normalised search terms.
 *
 * Search runs in PHP over terms stored by this class rather than on MySQL's
 * FULLTEXT index, for two reasons that matter to the sites this plugin targets:
 *
 *  - FULLTEXT tokenises on ASCII word rules and handles Bangla (and most other
 *    non-Latin scripts) badly; here any Unicode letter or number is a term.
 *  - FULLTEXT defaults differ between MySQL, MariaDB and hosts (minimum word
 *    length, stopword lists), so results would silently vary per install.
 *
 * The same function tokenises documents and queries, which is the only thing
 * that has to be true for matching to work.
 */
final class Tokenizer {

	/**
	 * Words too common to say anything about relevance.
	 */
	private const STOPWORDS = array(
		'a',
		'an',
		'and',
		'are',
		'as',
		'at',
		'be',
		'but',
		'by',
		'can',
		'do',
		'does',
		'for',
		'from',
		'had',
		'has',
		'have',
		'how',
		'i',
		'if',
		'in',
		'into',
		'is',
		'it',
		'its',
		'me',
		'my',
		'no',
		'not',
		'of',
		'on',
		'or',
		'our',
		'so',
		'than',
		'that',
		'the',
		'their',
		'them',
		'then',
		'there',
		'these',
		'they',
		'this',
		'to',
		'us',
		'was',
		'we',
		'were',
		'what',
		'when',
		'where',
		'which',
		'who',
		'why',
		'will',
		'with',
		'would',
		'you',
		'your',
		'please',
		'hi',
		'hello',
		'about',
		'any',
		'get',
		'tell',
		'want',
		'need',
		'know',
		// Bangla.
		'এবং',
		'ও',
		'কি',
		'কী',
		'আমি',
		'আমার',
		'আমাদের',
		'আপনি',
		'আপনার',
		'এই',
		'সেই',
		'যে',
		'না',
		'হয়',
		'হয়',
		'করে',
		'করা',
		'এর',
		'জন্য',
		'থেকে',
		'একটি',
		'কিছু',
		'আছে',
		'দিয়ে',
		'দিয়ে',
		'তা',
		'তার',
		'কেন',
		'কোন',
		'কোথায়',
		'কোথায়',
	);

	/**
	 * Terms of a text, in order, duplicates kept (term frequency matters).
	 *
	 * @param string $text Any text.
	 * @return array<int, string>
	 */
	public static function tokenize( string $text ): array {
		$text = mb_strtolower( $text, 'UTF-8' );

		// \p{M} keeps combining marks, without which Bangla words split apart.
		$parts = preg_split( '/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		static $stopwords = null;
		$stopwords      ??= array_flip( self::STOPWORDS );

		$terms = array();

		foreach ( $parts as $part ) {
			if ( isset( $stopwords[ $part ] ) ) {
				continue;
			}

			// Single Latin letters are noise; a single digit or Bangla letter
			// may not be ("plan 2"), so length is only checked for ASCII.
			if ( 1 === strlen( $part ) && ctype_alpha( $part ) ) {
				continue;
			}

			$terms[] = self::stem( $part );
		}

		return $terms;
	}

	/**
	 * Unique terms of a query, most specific (longest) first.
	 *
	 * @param string $query Visitor question.
	 * @param int    $limit Most terms to keep.
	 * @return array<int, string>
	 */
	public static function query_terms( string $query, int $limit = 10 ): array {
		$terms = array_values( array_unique( self::tokenize( $query ) ) );

		usort( $terms, static fn( string $a, string $b ): int => mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' ) );

		return array_slice( $terms, 0, $limit );
	}

	/**
	 * Very light English suffix stripping, so "refunds" finds "refund".
	 *
	 * Deliberately conservative: an aggressive stemmer merges unrelated words
	 * ("news" -> "new"), which hurts a small index more than it helps.
	 *
	 * @param string $term Lower-cased term.
	 */
	private static function stem( string $term ): string {
		if ( ! preg_match( '/^[a-z]+$/', $term ) || strlen( $term ) <= 4 ) {
			return $term;
		}

		if ( str_ends_with( $term, 'ies' ) ) {
			return substr( $term, 0, -3 ) . 'y';
		}

		if ( str_ends_with( $term, 'ing' ) && strlen( $term ) > 6 ) {
			return substr( $term, 0, -3 );
		}

		if ( str_ends_with( $term, 'ed' ) && strlen( $term ) > 5 ) {
			return substr( $term, 0, -2 );
		}

		if ( str_ends_with( $term, 's' ) && ! str_ends_with( $term, 'ss' ) && ! str_ends_with( $term, 'us' ) ) {
			return substr( $term, 0, -1 );
		}

		return $term;
	}
}
