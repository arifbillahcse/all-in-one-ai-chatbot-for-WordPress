<?php
/**
 * Documents uploaded as knowledge.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an uploaded PDF, Word, text or HTML file into a Knowledge Article.
 *
 * The file itself is not kept: only its text is. Files in the media library
 * are public at a guessable address, which would defeat marking a price list
 * or member handbook as members-only, and the owner has the original anyway.
 */
final class FileImporter {

	public const MAX_BYTES = 20 * MB_IN_BYTES;

	/** Accepted extensions and what reads them. */
	public const TYPES = array(
		'pdf'  => 'PDF',
		'docx' => 'Word',
		'odt'  => 'OpenDocument',
		'txt'  => 'Text',
		'md'   => 'Markdown',
		'html' => 'HTML',
		'htm'  => 'HTML',
	);

	/**
	 * Import one file.
	 *
	 * @param string $path     Temporary file.
	 * @param string $filename Original name (for the title and type).
	 * @param string $audience Audience marker.
	 * @return array{0: int, 1: string, 2: int} Post id, created|updated|unchanged, characters of text.
	 * @throws SourceException When the file is unusable.
	 */
	public static function import( string $path, string $filename, string $audience = '' ): array {
		$extracted = self::extract( $path, $filename );

		[ $post_id, $state ] = SourceStore::upsert(
			'file',
			strtolower( sanitize_file_name( $filename ) ),
			$extracted['title'],
			$extracted['text'],
			array(
				'name'     => $filename,
				'audience' => $audience,
			)
		);

		return array( $post_id, $state, mb_strlen( $extracted['text'], 'UTF-8' ) );
	}

	/**
	 * Read a file's title and text.
	 *
	 * @param string $path     File.
	 * @param string $filename Original name.
	 * @return array{title: string, text: string}
	 * @throws SourceException When the file is unusable.
	 */
	public static function extract( string $path, string $filename ): array {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! isset( self::TYPES[ $ext ] ) ) {
			throw new SourceException( sprintf( /* translators: %s: list of file types */ __( 'This file type is not supported. Upload one of: %s.', 'all-in-one-ai-chatbot' ), implode( ', ', array_keys( self::TYPES ) ) ) );
		}

		$size = is_file( $path ) ? (int) filesize( $path ) : 0;

		if ( $size <= 0 ) {
			throw new SourceException( __( 'The file is empty or did not upload completely.', 'all-in-one-ai-chatbot' ) );
		}

		if ( $size > self::MAX_BYTES ) {
			throw new SourceException( sprintf( /* translators: %s: size like 20 MB */ __( 'The file is larger than %s. Split it into smaller files.', 'all-in-one-ai-chatbot' ), size_format( self::MAX_BYTES ) ) );
		}

		$title = self::title_from_name( $filename );

		/**
		 * Extract text yourself (e.g. an OCR service for scanned PDFs).
		 * Return a string to use it; null lets the plugin read the file.
		 *
		 * @param string|null $text     Text, or null.
		 * @param string      $path     File on disk.
		 * @param string      $filename Original name.
		 */
		$text = apply_filters( 'softorio_ai_extract_text', null, $path, $filename );

		if ( ! is_string( $text ) ) {
			$text = match ( $ext ) {
				'pdf'          => PdfText::extract( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
				'docx', 'odt'  => DocxText::extract( $path, $ext ),
				'html', 'htm'  => self::html( $path, $title ),
				default        => self::plain( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
			};
		}

		if ( is_array( $text ) ) {
			$title = '' !== $text['title'] ? $text['title'] : $title;
			$text  = $text['text'];
		}

		$text = trim( (string) $text );

		if ( mb_strlen( preg_replace( '/\s+/u', '', $text ) ?? '', 'UTF-8' ) < 20 ) {
			throw new SourceException(
				'pdf' === $ext
					? __( 'No text could be read from this PDF. It is probably scanned (a picture of text). Use a PDF with selectable text, or copy the text into a Knowledge Article.', 'all-in-one-ai-chatbot' )
					: __( 'No text could be read from this file.', 'all-in-one-ai-chatbot' )
			);
		}

		return array(
			'title' => $title,
			'text'  => $text,
		);
	}

	/**
	 * "refund-policy_2025.pdf" -> "Refund policy 2025".
	 *
	 * @param string $filename File name.
	 */
	public static function title_from_name( string $filename ): string {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		$name = trim( preg_replace( '/[\s_\-]+/u', ' ', $name ) ?? $name );

		return '' === $name ? $filename : mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
	}

	/**
	 * Plain text or Markdown, in any common encoding.
	 *
	 * @param string $raw File contents.
	 */
	public static function plain( string $raw ): string {
		$raw = self::utf8( $raw );

		// Light Markdown clean-up: the meaning survives without the syntax.
		$raw = preg_replace( '/^#{1,6}\s*/m', '', $raw ) ?? $raw;
		$raw = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $raw ) ?? $raw;
		$raw = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $raw ) ?? $raw;
		$raw = preg_replace( '/(\*\*|__)(.+?)\1/', '$2', $raw ) ?? $raw;

		return trim( preg_replace( '/\n{3,}/', "\n\n", str_replace( array( "\r\n", "\r" ), "\n", $raw ) ) ?? $raw );
	}

	/**
	 * A saved HTML page.
	 *
	 * @param string $path  File.
	 * @param string $title Fallback title.
	 * @return array{title: string, text: string}
	 */
	private static function html( string $path, string $title ): array {
		$page = HtmlText::extract( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.

		return array(
			'title' => '' !== $page['title'] ? $page['title'] : $title,
			'text'  => $page['text'],
		);
	}

	/**
	 * Bytes to UTF-8: strips a BOM, reads UTF-16, and treats anything else
	 * that is not valid UTF-8 as Windows-1252 (Excel and Notepad defaults).
	 *
	 * @param string $raw Bytes.
	 */
	public static function utf8( string $raw ): string {
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			return substr( $raw, 3 );
		}

		if ( str_starts_with( $raw, "\xFF\xFE" ) ) {
			return (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
		}

		if ( str_starts_with( $raw, "\xFE\xFF" ) ) {
			return (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		}

		return mb_check_encoding( $raw, 'UTF-8' ) ? $raw : (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
	}
}
