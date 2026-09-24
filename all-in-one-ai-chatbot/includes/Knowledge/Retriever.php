<?php
/**
 * Finds the passages that answer a question.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks indexed chunks against a question.
 *
 * Keyword relevance is BM25, the standard search-engine formula: a term counts
 * for more when it is rare across the site and frequent in the passage, with
 * long passages not rewarded just for being long. When semantic search is on,
 * cosine similarity of embeddings is blended in, which catches answers phrased
 * differently from the question.
 */
final class Retriever {

	private const K1 = 1.2;
	private const B  = 0.75;

	private IndexStore $store;

	/**
	 * Constructor.
	 *
	 * @param IndexStore|null $store Chunk storage.
	 */
	public function __construct( ?IndexStore $store = null ) {
		$this->store = $store ?? new IndexStore();
	}

	/**
	 * Best passages for a question.
	 *
	 * @param string   $query Visitor question.
	 * @param int|null $limit Most results.
	 * @return array<int, array{chunk_id: int, post_id: int, title: string, url: string, content: string, score: float}>
	 */
	public function search( string $query, ?int $limit = null ): array {
		$limit ??= max( 1, (int) Settings::get( 'results', 5 ) );

		$terms      = Tokenizer::query_terms( $query );
		$candidates = $this->store->candidates( $terms );
		$lexical    = self::bm25( $terms, $candidates, $this->store->stats() );

		$vector = array();

		if ( Embedder::enabled() ) {
			$vector = $this->vector_scores( $query );

			// Pull in strong semantic matches that shared no keyword with the
			// question — the case semantic search exists for.
			$missing = array_diff( array_keys( $vector ), array_map( static fn( $r ): int => (int) $r['id'], $candidates ) );

			if ( array() !== $missing ) {
				$candidates = array_merge( $candidates, $this->store->by_ids( $missing ) );
			}
		}

		$ranked = self::blend( $candidates, $lexical, $vector );

		return self::diversify( $ranked, $limit );
	}

	/**
	 * BM25 score for each candidate chunk.
	 *
	 * Document frequency is counted within the candidate set. The candidates
	 * are every chunk matching any term (up to the store's cap), so for a
	 * normal-sized site that count is exact, and it saves a query per term.
	 *
	 * @param array<int, string>               $terms      Query terms.
	 * @param array<int, array<string, mixed>> $candidates Chunk rows.
	 * @param array{chunks: int, avg_tokens: float} $stats Index stats.
	 * @return array<int, float> chunk id => score
	 */
	public static function bm25( array $terms, array $candidates, array $stats ): array {
		if ( array() === $terms || array() === $candidates ) {
			return array();
		}

		$total_docs = max( count( $candidates ), (int) ( $stats['chunks'] ?? 0 ) );
		$avg_length = max( 1.0, (float) ( $stats['avg_tokens'] ?? 0 ) );

		// Term frequencies per chunk, using the same prefix rule as the SQL.
		$frequencies = array();
		$doc_freq    = array_fill_keys( $terms, 0 );

		foreach ( $candidates as $row ) {
			$id     = (int) $row['id'];
			$tokens = explode( ' ', trim( (string) $row['search_text'] ) );
			$counts = array_fill_keys( $terms, 0 );

			foreach ( $tokens as $token ) {
				foreach ( $terms as $term ) {
					if ( str_starts_with( $token, $term ) ) {
						++$counts[ $term ];
					}
				}
			}

			foreach ( $terms as $term ) {
				if ( $counts[ $term ] > 0 ) {
					++$doc_freq[ $term ];
				}
			}

			$frequencies[ $id ] = array(
				'counts' => $counts,
				'length' => max( 1, (int) $row['token_count'] ),
			);
		}

		$scores = array();

		foreach ( $frequencies as $id => $doc ) {
			$score = 0.0;

			foreach ( $terms as $term ) {
				$tf = $doc['counts'][ $term ];

				if ( 0 === $tf ) {
					continue;
				}

				$df    = $doc_freq[ $term ];
				$idf   = log( 1 + ( $total_docs - $df + 0.5 ) / ( $df + 0.5 ) );
				$norm  = self::K1 * ( 1 - self::B + self::B * $doc['length'] / $avg_length );
				$score += $idf * ( $tf * ( self::K1 + 1 ) ) / ( $tf + $norm );
			}

			$scores[ $id ] = $score;
		}

		return $scores;
	}

	/**
	 * Cosine similarity of the question to every embedded chunk; top matches only.
	 *
	 * @param string $query Question.
	 * @return array<int, float> chunk id => similarity
	 */
	private function vector_scores( string $query ): array {
		$packed = ( new Embedder() )->embed_one( $query );

		if ( '' === $packed ) {
			return array();
		}

		$question = VectorMath::unpack( $packed );
		$scores   = array();

		foreach ( $this->store->embeddings() as $id => $stored ) {
			$scores[ $id ] = VectorMath::dot( $question, VectorMath::unpack( $stored ) );
		}

		arsort( $scores );

		return array_slice( $scores, 0, 20, true );
	}

	/**
	 * Combine keyword and semantic scores into one ranking.
	 *
	 * BM25 is unbounded, so it is scaled to [0, 1] by the best score of this
	 * query before blending with cosine similarity, which already is.
	 *
	 * @param array<int, array<string, mixed>> $candidates Chunk rows.
	 * @param array<int, float>                $lexical    BM25 by chunk id.
	 * @param array<int, float>                $vector     Similarity by chunk id.
	 * @return array<int, array{chunk_id: int, post_id: int, title: string, url: string, content: string, score: float}>
	 */
	public static function blend( array $candidates, array $lexical, array $vector ): array {
		$max_lexical = array() === $lexical ? 0.0 : max( $lexical );
		$use_vector  = array() !== $vector;
		$results     = array();
		$seen        = array();

		foreach ( $candidates as $row ) {
			$id = (int) $row['id'];

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$lex = $max_lexical > 0 ? ( $lexical[ $id ] ?? 0.0 ) / $max_lexical : 0.0;
			$vec = max( 0.0, $vector[ $id ] ?? 0.0 );

			$score = $use_vector ? ( 0.45 * $lex + 0.55 * $vec ) : $lex;

			// Weak semantic-only matches are noise, not context.
			if ( $score <= 0.0 || ( 0.0 === $lex && $vec < 0.3 ) ) {
				continue;
			}

			$results[] = array(
				'chunk_id' => $id,
				'post_id'  => (int) $row['post_id'],
				'title'    => (string) $row['title'],
				'url'      => (string) $row['url'],
				'content'  => (string) $row['content'],
				'score'    => $score,
			);
		}

		usort( $results, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $results;
	}

	/**
	 * Top results, at most two passages per post, weak tail dropped.
	 *
	 * Without the per-post cap one long article can fill every slot with
	 * near-duplicate passages and crowd out the page that actually answers.
	 *
	 * @param array<int, array<string, mixed>> $ranked Sorted results.
	 * @param int                              $limit  Most results.
	 * @return array<int, array{chunk_id: int, post_id: int, title: string, url: string, content: string, score: float}>
	 */
	public static function diversify( array $ranked, int $limit ): array {
		if ( array() === $ranked ) {
			return array();
		}

		$best     = (float) $ranked[0]['score'];
		$per_post = array();
		$out      = array();

		foreach ( $ranked as $result ) {
			if ( count( $out ) >= $limit ) {
				break;
			}

			// Passages scoring under a fifth of the best one rarely help and
			// always cost tokens.
			if ( $result['score'] < $best * 0.2 ) {
				break;
			}

			$post = $result['post_id'];
			$per_post[ $post ] = ( $per_post[ $post ] ?? 0 ) + 1;

			if ( $per_post[ $post ] > 2 ) {
				continue;
			}

			$out[] = $result;
		}

		return $out;
	}
}
