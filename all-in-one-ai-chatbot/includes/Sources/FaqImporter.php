<?php
/**
 * FAQ spreadsheets.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Imports question/answer pairs from a CSV file (Excel, Google Sheets,
 * Numbers: "Save as CSV").
 *
 * Each pair becomes its own Knowledge Article titled with the question, which
 * is what search ranks highest, so "How long is delivery?" finds its answer
 * directly. Re-importing an edited sheet updates answers in place, keyed by
 * the question.
 */
final class FaqImporter {

	public const MAX_ROWS = 2000;

	/**
	 * Read question/answer rows from CSV text.
	 *
	 * Column A is the question, column B the answer. A header row is
	 * recognised and skipped. Comma, semicolon (European Excel) and tab
	 * separators are all detected.
	 *
	 * @param string $csv File contents.
	 * @return array{rows: array<int, array{0: string, 1: string}>, skipped: int}
	 * @throws SourceException When nothing usable is found.
	 */
	public static function parse( string $csv ): array {
		$csv = FileImporter::utf8( $csv );
		$csv = str_replace( array( "\r\n", "\r" ), "\n", $csv );

		$delimiter = self::delimiter( $csv );

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream for fgetcsv.

		if ( false === $handle ) {
			throw new SourceException( __( 'The file could not be read.', 'all-in-one-ai-chatbot' ) );
		}

		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
		rewind( $handle );

		$rows    = array();
		$skipped = 0;
		$line    = 0;

		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- read row by row.
			++$line;

			if ( array( null ) === $cells ) {
				continue; // Blank line.
			}

			$question = self::clean( (string) ( $cells[0] ?? '' ) );
			$answer   = self::clean( (string) ( $cells[1] ?? '' ), true );

			if ( 1 === $line && self::is_header( $question, $answer ) ) {
				continue;
			}

			if ( '' === $question || '' === $answer ) {
				++$skipped;
				continue;
			}

			if ( count( $rows ) >= self::MAX_ROWS ) {
				++$skipped;
				continue;
			}

			$rows[] = array( $question, $answer );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.

		if ( array() === $rows ) {
			throw new SourceException( __( 'No questions and answers were found. Put questions in column A and answers in column B, then save as CSV (UTF-8).', 'all-in-one-ai-chatbot' ) );
		}

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/**
	 * The separator that splits the first lines into exactly two columns
	 * most often. Ties go to tab, then semicolon: answers often contain
	 * commas, rarely tabs.
	 *
	 * @param string $csv File contents.
	 */
	private static function delimiter( string $csv ): string {
		$lines = array_slice( array_filter( explode( "\n", $csv ), static fn( string $l ): bool => '' !== trim( $l ) ), 0, 20 );
		$best  = ',';
		$score = -1;

		foreach ( array( "\t", ';', ',' ) as $candidate ) {
			$exact = 0;

			foreach ( $lines as $line ) {
				if ( 2 === count( str_getcsv( $line, $candidate, '"', '' ) ) ) {
					++$exact;
				}
			}

			if ( $exact > $score ) {
				$score = $exact;
				$best  = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Import parsed rows.
	 *
	 * @param array<int, array{0: string, 1: string}> $rows     Question/answer pairs.
	 * @param string                                  $audience Audience marker.
	 * @param bool                                    $replace  Remove FAQ articles not in this file.
	 * @return array{created: int, updated: int, unchanged: int, removed: int}
	 */
	public static function import( array $rows, string $audience = '', bool $replace = false ): array {
		$counts = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'removed'   => 0,
		);
		$kept   = array();

		foreach ( $rows as [ $question, $answer ] ) {
			[ $post_id, $state ] = SourceStore::upsert(
				'faq',
				self::ref( $question ),
				$question,
				$answer,
				array( 'audience' => $audience )
			);

			$kept[ $post_id ] = true;
			++$counts[ $state ];
		}

		if ( $replace ) {
			foreach ( SourceStore::ids( 'faq' ) as $post_id ) {
				if ( ! isset( $kept[ $post_id ] ) ) {
					wp_delete_post( $post_id, true );
					++$counts['removed'];
				}
			}
		}

		return $counts;
	}

	/**
	 * The key an FAQ is updated by: its question, ignoring case and spacing.
	 *
	 * @param string $question Question.
	 */
	public static function ref( string $question ): string {
		$normal = mb_strtolower( preg_replace( '/[\s\p{P}]+/u', ' ', $question ) ?? $question, 'UTF-8' );

		return md5( trim( $normal ) );
	}

	/**
	 * Whether the first row is column headings.
	 *
	 * @param string $a First cell.
	 * @param string $b Second cell.
	 */
	private static function is_header( string $a, string $b ): bool {
		$a = mb_strtolower( $a, 'UTF-8' );
		$b = mb_strtolower( $b, 'UTF-8' );

		return (bool) preg_match( '/^(question|questions|q|faq|প্রশ্ন)\b/u', $a ) && (bool) preg_match( '/^(answer|answers|a|reply|উত্তর)\b/u', $b );
	}

	/**
	 * Trim a cell; answers keep line breaks, questions become one line.
	 *
	 * @param string $value     Cell.
	 * @param bool   $multiline Keep line breaks.
	 */
	private static function clean( string $value, bool $multiline = false ): string {
		$value = wp_strip_all_tags( $value );
		$value = str_replace( "\u{00A0}", ' ', $value );

		if ( ! $multiline ) {
			return mb_substr( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ), 0, 200 );
		}

		$value = preg_replace( '/[ \t]+/u', ' ', $value ) ?? $value;

		return mb_substr( trim( $value ), 0, 5000 );
	}
}
