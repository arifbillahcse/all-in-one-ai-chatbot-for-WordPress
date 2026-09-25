<?php
/**
 * Storage for indexed chunks.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

use Softorio\AiAssistant\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the chunks table. All SQL for the index lives here.
 */
final class IndexStore {

	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->table = Installer::tables()['chunks'];
	}

	/**
	 * Content hash of what is currently indexed for a post, or '' if nothing is.
	 *
	 * @param int $post_id Post id.
	 */
	public function hash_for( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, no WP API exists for it.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT content_hash FROM %i WHERE post_id = %d LIMIT 1', $this->table, $post_id ) );
	}

	/**
	 * Replace every chunk of a post.
	 *
	 * @param int                               $post_id Post id.
	 * @param array<int, array<string, mixed>> $rows    Chunk rows without post_id.
	 */
	public function replace( int $post_id, array $rows ): void {
		global $wpdb;

		$this->delete( $post_id );

		$now = current_time( 'mysql', true );

		foreach ( $rows as $index => $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			$wpdb->insert(
				$this->table,
				array(
					'post_id'      => $post_id,
					'chunk_index'  => $index,
					'title'        => (string) $row['title'],
					'url'          => (string) $row['url'],
					'content'      => (string) $row['content'],
					'search_text'  => (string) $row['search_text'],
					'token_count'  => (int) $row['token_count'],
					'content_hash' => (string) $row['content_hash'],
					'audience'     => (string) ( $row['audience'] ?? '' ),
					'embedding'    => $row['embedding'] ?? null,
					'indexed_at'   => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}

		$this->forget_stats();
	}

	/**
	 * Remove a post from the index.
	 *
	 * @param int $post_id Post id.
	 */
	public function delete( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$deleted = $wpdb->delete( $this->table, array( 'post_id' => $post_id ), array( '%d' ) );

		if ( $deleted ) {
			$this->forget_stats();
		}
	}

	/**
	 * Remove everything.
	 */
	public function truncate(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->table ) );
		$this->forget_stats();
	}

	/**
	 * Remove posts that are no longer in the given set of ids.
	 *
	 * @param array<int, int> $keep Post ids that should stay indexed.
	 */
	public function delete_except( array $keep ): int {
		$removed = 0;

		foreach ( $this->indexed_post_ids() as $post_id ) {
			if ( ! in_array( $post_id, $keep, true ) ) {
				$this->delete( $post_id );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Ids of every indexed post.
	 *
	 * @return array<int, int>
	 */
	public function indexed_post_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', $this->table ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Chunks containing any of the terms, as search candidates.
	 *
	 * Terms are stored space-delimited with a leading space, so `% term%` is a
	 * prefix match on a whole term: "refund" matches "refundable" but "fund"
	 * does not match "refund".
	 *
	 * @param array<int, string> $terms    Query terms.
	 * @param int                $limit    Most rows to return.
	 * @param Audience|null      $audience Who is asking; null means everyone may see the result (guest).
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( array $terms, int $limit = 400, ?Audience $audience = null ): array {
		global $wpdb;

		if ( array() === $terms ) {
			return array();
		}

		$clauses = array();
		$args    = array( $this->table );

		foreach ( $terms as $term ) {
			$clauses[] = 'search_text LIKE %s';
			$args[]    = '% ' . $wpdb->esc_like( $term ) . '%';
		}

		[ $who, $who_args ] = ( $audience ?? Audience::guest() )->where();

		$args = array_merge( $args, $who_args );
		$args[] = $limit;

		$sql = 'SELECT id, post_id, title, url, content, search_text, token_count, audience FROM %i WHERE ('
			. implode( ' OR ', $clauses )
			. ')' . ( '' !== $who ? ' AND ' . $who : '' )
			. ' LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Chunks by id.
	 *
	 * @param array<int, int> $ids      Chunk ids.
	 * @param Audience|null   $audience Who is asking; null = guest.
	 * @return array<int, array<string, mixed>>
	 */
	public function by_ids( array $ids, ?Audience $audience = null ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		[ $who, $who_args ] = ( $audience ?? Audience::guest() )->where();

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT id, post_id, title, url, content, search_text, token_count, audience FROM %i WHERE id IN ($placeholders)" . ( '' !== $who ? ' AND ' . $who : '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $this->table ), $ids, $who_args ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every stored embedding, for semantic search.
	 *
	 * @param int           $limit    Safety cap on rows loaded into memory.
	 * @param Audience|null $audience Who is asking; null = guest.
	 * @return array<int, string> chunk id => packed vector
	 */
	public function embeddings( int $limit = 5000, ?Audience $audience = null ): array {
		global $wpdb;

		[ $who, $who_args ] = ( $audience ?? Audience::guest() )->where();

		$sql = 'SELECT id, embedding FROM %i WHERE embedding IS NOT NULL' . ( '' !== $who ? ' AND ' . $who : '' ) . ' LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $this->table ), $who_args, array( $limit ) ) ), ARRAY_A );

		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( is_string( $row['embedding'] ) && '' !== $row['embedding'] ) {
				$out[ (int) $row['id'] ] = $row['embedding'];
			}
		}

		return $out;
	}

	/**
	 * Index size figures, cached until the index changes.
	 *
	 * @return array{chunks: int, posts: int, avg_tokens: float, embedded: int}
	 */
	public function stats(): array {
		$cached = get_transient( 'softorio_ai_index_stats' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS chunks, COUNT(DISTINCT post_id) AS posts, AVG(token_count) AS avg_tokens, SUM(CASE WHEN embedding IS NULL THEN 0 ELSE 1 END) AS embedded FROM %i',
				$this->table
			),
			ARRAY_A
		);

		$stats = array(
			'chunks'     => (int) ( $row['chunks'] ?? 0 ),
			'posts'      => (int) ( $row['posts'] ?? 0 ),
			'avg_tokens' => (float) ( $row['avg_tokens'] ?? 0 ),
			'embedded'   => (int) ( $row['embedded'] ?? 0 ),
		);

		set_transient( 'softorio_ai_index_stats', $stats, DAY_IN_SECONDS );

		return $stats;
	}

	private function forget_stats(): void {
		delete_transient( 'softorio_ai_index_stats' );
	}
}
