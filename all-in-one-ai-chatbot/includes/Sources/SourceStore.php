<?php
/**
 * Imported knowledge.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

use Softorio\AiAssistant\Knowledge\Audience;
use Softorio\AiAssistant\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Every imported file, FAQ and web page becomes a Knowledge Article.
 *
 * Reusing the post type means imported content is indexed, searched,
 * members-only-filtered, revisioned and editable exactly like hand-written
 * articles, with no second index to keep in step. Source metadata on the post
 * says where it came from, so a re-import updates the same article instead of
 * adding a duplicate.
 */
final class SourceStore {

	public const TYPE    = '_softorio_ai_source';      // file | faq | url.
	public const REF     = '_softorio_ai_source_ref';  // What makes it unique within its type.
	public const URL     = '_softorio_ai_source_url';  // Web page address (url only).
	public const NAME    = '_softorio_ai_source_name'; // Original file name (file only).
	public const HASH    = '_softorio_ai_source_hash'; // Hash of the imported text.
	public const FETCHED = '_softorio_ai_source_fetched';
	public const STATUS  = '_softorio_ai_source_status'; // ok | gone | error, for web pages.

	public const TYPES = array( 'file', 'faq', 'url' );

	/**
	 * Create or update the article for a source.
	 *
	 * @param string               $type  file|faq|url.
	 * @param string               $ref   Unique reference within the type.
	 * @param string               $title Article title.
	 * @param string               $text  Plain text (paragraphs separated by blank lines).
	 * @param array<string, mixed> $extra url, name, audience (null keeps the current one).
	 * @return array{0: int, 1: string} Post id, created|updated|unchanged.
	 * @throws SourceException When WordPress refuses the post.
	 */
	public static function upsert( string $type, string $ref, string $title, string $text, array $extra = array() ): array {
		$existing = self::find( $type, $ref );
		$title    = mb_substr( trim( wp_strip_all_tags( $title ) ), 0, 200 );
		$title    = '' !== $title ? $title : __( 'Untitled', 'all-in-one-ai-chatbot' );
		$hash     = hash( 'sha256', $title . "\x1f" . $text );

		if ( null !== $existing ) {
			$post_id = $existing;

			if ( array_key_exists( 'audience', $extra ) && null !== $extra['audience'] ) {
				Audience::set( $post_id, $extra['audience'] );
			}

			update_post_meta( $post_id, self::FETCHED, time() );

			if ( 'url' === $type ) {
				update_post_meta( $post_id, self::STATUS, 'ok' );
			}

			$post = get_post( $post_id );

			if ( get_post_meta( $post_id, self::HASH, true ) === $hash && $post instanceof \WP_Post && 'publish' === $post->post_status ) {
				// Nothing new, but an audience change still needs a re-index.
				\Softorio\AiAssistant\Knowledge\Indexer::on_save( $post_id );

				return array( $post_id, 'unchanged' );
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $title,
					'post_content' => self::to_html( $text ),
					'post_status'  => 'publish',
				),
				true
			);
			$state  = 'updated';
		} else {
			$meta = array(
				self::TYPE    => $type,
				self::REF     => $ref,
				self::FETCHED => time(),
			);

			if ( 'url' === $type ) {
				$meta[ self::URL ]    = (string) ( $extra['url'] ?? $ref );
				$meta[ self::STATUS ] = 'ok';
			}

			if ( ! empty( $extra['name'] ) ) {
				$meta[ self::NAME ] = mb_substr( sanitize_file_name( (string) $extra['name'] ), 0, 200 );
			}

			$audience = Audience::normalise( $extra['audience'] ?? '' );

			if ( '' !== $audience ) {
				// Set before the insert so the first index already has it.
				$meta[ Audience::META ] = $audience;
			}

			$result = wp_insert_post(
				array(
					'post_type'    => PostTypes::DOC,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => self::to_html( $text ),
					'meta_input'   => $meta,
				),
				true
			);
			$state  = 'created';
		}

		if ( is_wp_error( $result ) || ! $result ) {
			throw new SourceException( is_wp_error( $result ) ? $result->get_error_message() : __( 'The article could not be saved.', 'all-in-one-ai-chatbot' ) );
		}

		update_post_meta( (int) $result, self::HASH, $hash );

		return array( (int) $result, $state );
	}

	/**
	 * The article for a source, if imported before (in any status but trash).
	 *
	 * @param string $type Type.
	 * @param string $ref  Reference.
	 */
	public static function find( string $type, string $ref ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => PostTypes::DOC,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'numberposts'      => 1,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- keyed lookup on our own meta.
				'meta_query'       => array(
					array(
						'key'   => self::TYPE,
						'value' => $type,
					),
					array(
						'key'   => self::REF,
						'value' => $ref,
					),
				),
			)
		);

		return array() === $ids ? null : (int) $ids[0];
	}

	/**
	 * Every imported article of a type (ids).
	 *
	 * @param string $type   Type.
	 * @param string $status Post status or 'any'.
	 * @return array<int, int>
	 */
	public static function ids( string $type, string $status = 'any' ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => PostTypes::DOC,
					'post_status'      => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
					'fields'           => 'ids',
					'numberposts'      => -1,
					'suppress_filters' => true,
					'meta_key'         => self::TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- our own meta.
					'meta_value'       => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- our own meta.
				)
			)
		);
	}

	/**
	 * A page of imported articles for the admin list.
	 *
	 * @param string $type     Type, or '' for all.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array{rows: array<int, \WP_Post>, total: int}
	 */
	public static function page( string $type, int $page, int $per_page ): array {
		$query = new \WP_Query(
			array(
				'post_type'           => PostTypes::DOC,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'      => $per_page,
				'paged'               => max( 1, $page ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin screen, our own meta.
				'meta_query'          => array(
					'' === $type
						? array(
							'key'     => self::TYPE,
							'compare' => 'EXISTS',
						)
						: array(
							'key'   => self::TYPE,
							'value' => $type,
						),
				),
			)
		);

		return array(
			'rows'  => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * How many articles came from each source type.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$out = array();

		foreach ( self::TYPES as $type ) {
			$out[ $type ] = count( self::ids( $type ) );
		}

		return $out;
	}

	/**
	 * The link shown under answers for an article: the page it was imported
	 * from, or '' (hand-written articles and files have no page).
	 *
	 * @param int $post_id Post id.
	 */
	public static function link( int $post_id ): string {
		if ( 'url' !== get_post_meta( $post_id, self::TYPE, true ) ) {
			return '';
		}

		$url = (string) get_post_meta( $post_id, self::URL, true );

		return 1 === preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Plain text to simple paragraphs for the editor.
	 *
	 * @param string $text Text.
	 */
	public static function to_html( string $text ): string {
		$paragraphs = preg_split( '/\n{2,}/', str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) ) ) ?: array();
		$html       = array();

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' !== $paragraph ) {
				$html[] = '<p>' . nl2br( esc_html( $paragraph ), false ) . '</p>';
			}
		}

		return implode( "\n", $html );
	}
}
