<?php
/**
 * Keeps the search index in step with site content.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\PostTypes;
use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes posts into searchable chunks.
 *
 * Content is indexed on save, so an edit to a refund policy is live in the
 * assistant's next answer without anyone pressing "rebuild". The full rebuild
 * exists for first activation and for settings changes (a new post type, or
 * semantic search switched on), and runs in small batches because shared
 * hosting kills long requests.
 */
final class Indexer {

	public const STATE_OPTION = 'softorio_ai_index_state';

	private IndexStore $store;
	private TextNormalizer $normalizer;
	private Chunker $chunker;

	/**
	 * Constructor.
	 *
	 * @param IndexStore|null $store Chunk storage.
	 */
	public function __construct( ?IndexStore $store = null ) {
		$this->store      = $store ?? new IndexStore();
		$this->normalizer = new TextNormalizer();
		$this->chunker    = new Chunker();
	}

	/**
	 * Hook indexing into content changes.
	 */
	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'on_save' ), 20, 1 );
		add_action( 'trashed_post', array( self::class, 'on_delete' ) );
		add_action( 'deleted_post', array( self::class, 'on_delete' ) );
		add_action( 'softorio_ai_index_post', array( self::class, 'on_cron_index' ) );
	}

	/**
	 * Index a post after it is saved.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}

		$indexer = new self();

		// Keyword indexing is instant, so it happens on save. Fetching
		// embeddings is a network call; it is deferred to cron so the editor's
		// "Update" button does not wait on a third-party API.
		$indexer->index_post( $post_id, false );

		if ( Embedder::enabled() && ! wp_next_scheduled( 'softorio_ai_index_post', array( $post_id ) ) ) {
			wp_schedule_single_event( time(), 'softorio_ai_index_post', array( $post_id ) );
		}
	}

	/**
	 * Cron: re-index a post with embeddings.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_cron_index( int $post_id ): void {
		( new self() )->index_post( (int) $post_id, true );
	}

	/**
	 * Drop a post from the index when it is trashed or deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_delete( int $post_id ): void {
		( new IndexStore() )->delete( (int) $post_id );
	}

	/**
	 * Post types the assistant reads, limited to ones that still exist.
	 *
	 * @return array<int, string>
	 */
	public static function post_types(): array {
		/**
		 * Filter the post types the assistant reads (modules add their own).
		 *
		 * @param array<int, string> $types Post type slugs.
		 */
		$types = array_filter( (array) apply_filters( 'softorio_ai_post_types', (array) Settings::get( 'post_types', array() ) ), 'is_string' );

		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	/**
	 * Index (or un-index) one post.
	 *
	 * @param int  $post_id    Post id.
	 * @param bool $embeddings Whether to fetch embeddings when semantic search is on.
	 * @return string indexed|unchanged|removed|skipped
	 */
	public function index_post( int $post_id, bool $embeddings = true ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_eligible( $post ) ) {
			$this->store->delete( $post_id );

			return $post instanceof \WP_Post ? 'removed' : 'skipped';
		}

		$title = wp_strip_all_tags( get_the_title( $post ) );
		$body  = $this->normalizer->normalize( (string) $post->post_content );

		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$body = $this->normalizer->normalize( (string) $post->post_excerpt ) . "\n\n" . $body;
		}

		/**
		 * Filter the text indexed for a post.
		 *
		 * Page builders and custom fields keep content outside post_content;
		 * this is the place to append it.
		 *
		 * @param string   $body Plain text that will be indexed.
		 * @param \WP_Post $post The post.
		 */
		$body = (string) apply_filters( 'softorio_ai_index_content', $body, $post );

		if ( '' === trim( $body ) && '' === trim( $title ) ) {
			$this->store->delete( $post_id );

			return 'removed';
		}

		// Knowledge Articles have no page of their own, except ones imported
		// from a web page, which link to that page.
		$url          = PostTypes::DOC === $post->post_type ? \Softorio\AiAssistant\Sources\SourceStore::link( $post_id ) : (string) get_permalink( $post );
		$audience     = Audience::for_post( $post_id );
		$use_vectors  = $embeddings && Embedder::enabled();
		$content_hash = hash( 'sha256', implode( "\x1f", array( $title, $body, $url, $audience, $use_vectors ? 'e1' : 'e0' ) ) );

		if ( $this->store->hash_for( $post_id ) === $content_hash ) {
			return 'unchanged';
		}

		$chunks = $this->chunker->chunk( $body );

		if ( array() === $chunks ) {
			$chunks = array( $title );
		}

		$title_terms = Tokenizer::tokenize( $title );
		$rows        = array();

		foreach ( $chunks as $chunk ) {
			$terms = Tokenizer::tokenize( $chunk );

			// The title is repeated in every chunk's terms — and twice — so a
			// question naming the article ranks all of it, weighted above a
			// passing mention in some other post's body.
			$search = array_merge( $title_terms, $title_terms, $terms );

			$rows[] = array(
				'title'        => $title,
				'url'          => $url,
				'content'      => $chunk,
				'search_text'  => ' ' . implode( ' ', $search ) . ' ',
				'token_count'  => count( $search ),
				'content_hash' => $content_hash,
				'audience'     => $audience,
				'embedding'    => null,
			);
		}

		if ( $use_vectors ) {
			try {
				$vectors = ( new Embedder() )->embed_many(
					array_map( static fn( string $c ): string => $title . "\n\n" . $c, $chunks )
				);

				foreach ( $rows as $i => $row ) {
					$rows[ $i ]['embedding'] = $vectors[ $i ] ?? null;
				}
			} catch ( LlmException $e ) {
				// Index keyword-only now, and store a hash that does not claim
				// embeddings, so the next rebuild tries again.
				$fallback_hash = hash( 'sha256', implode( "\x1f", array( $title, $body, $url, $audience, 'e0' ) ) );

				foreach ( $rows as $i => $row ) {
					$rows[ $i ]['content_hash'] = $fallback_hash;
				}
			}
		}

		$this->store->replace( $post_id, $rows );

		return 'indexed';
	}

	/**
	 * Whether a post should be searchable by the assistant.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function is_eligible( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}

		if ( PostTypes::is_excluded( $post->ID ) ) {
			return false;
		}

		/**
		 * Filter whether a post is indexed.
		 *
		 * @param bool     $eligible Whether the post is indexed.
		 * @param \WP_Post $post     The post.
		 */
		return (bool) apply_filters( 'softorio_ai_should_index', true, $post );
	}

	/**
	 * Ids of every published post of the indexed types.
	 *
	 * @return array<int, int>
	 */
	public function eligible_ids(): array {
		$types = self::post_types();

		if ( array() === $types ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'has_password'     => false,
				'suppress_filters' => true,
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Index one batch of a full rebuild.
	 *
	 * @param int $offset Position in the list of eligible posts.
	 * @param int $size   Posts per batch.
	 * @return array{processed: int, total: int, next: int, done: bool, indexed: int}
	 */
	public function rebuild_batch( int $offset, int $size = 20 ): array {
		$ids     = $this->eligible_ids();
		$total   = count( $ids );
		$batch   = array_slice( $ids, max( 0, $offset ), max( 1, $size ) );
		$indexed = 0;

		foreach ( $batch as $post_id ) {
			if ( 'indexed' === $this->index_post( $post_id, true ) ) {
				++$indexed;
			}
		}

		$next = $offset + count( $batch );
		$done = $next >= $total;

		if ( $done ) {
			// Anything indexed earlier that is no longer eligible — a post
			// type switched off, a post excluded while the plugin was off.
			$this->store->delete_except( $ids );

			update_option(
				self::STATE_OPTION,
				array(
					'status'   => 'done',
					'finished' => time(),
				),
				false
			);
		} else {
			update_option(
				self::STATE_OPTION,
				array(
					'status' => 'running',
					'next'   => $next,
					'total'  => $total,
				),
				false
			);
		}

		return array(
			'processed' => $next,
			'total'     => $total,
			'next'      => $next,
			'done'      => $done,
			'indexed'   => $indexed,
		);
	}
}
