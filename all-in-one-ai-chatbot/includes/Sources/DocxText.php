<?php
/**
 * Text from Word documents.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads .docx (and .odt) files: they are zip archives of XML.
 *
 * Paragraphs, headings, list items and table rows keep their breaks; table
 * cells are separated with " | " so a price table stays readable.
 */
final class DocxText {

	/** Largest XML part we will read (decompressed), against zip bombs. */
	private const MAX_XML = 30_000_000;

	/**
	 * Extract the text of a .docx or .odt file.
	 *
	 * @param string $path File on disk.
	 * @param string $ext  "docx" or "odt".
	 * @throws SourceException When the file cannot be read.
	 */
	public static function extract( string $path, string $ext = 'docx' ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new SourceException( __( 'Reading Word files needs the PHP "zip" extension, which this server does not have. Ask your host to enable it, or save the document as PDF and upload that.', 'all-in-one-ai-chatbot' ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new SourceException( __( 'This file is not a valid Word document.', 'all-in-one-ai-chatbot' ) );
		}

		$parts = 'odt' === $ext ? array( 'content.xml' ) : self::docx_parts( $zip );
		$text  = array();

		if ( array() === $parts || false === $zip->locateName( $parts[0] ) ) {
			$zip->close();
			throw new SourceException( __( 'This file is not a valid Word document.', 'all-in-one-ai-chatbot' ) );
		}

		foreach ( $parts as $part ) {
			$stat = $zip->statName( $part );

			if ( false === $stat || $stat['size'] > self::MAX_XML ) {
				continue;
			}

			$xml = $zip->getFromName( $part );

			if ( false !== $xml ) {
				$text[] = 'odt' === $ext ? self::odt_xml( $xml ) : self::docx_xml( $xml );
			}
		}

		$zip->close();

		return self::tidy( implode( "\n\n", $text ) );
	}

	/**
	 * The XML parts holding text: the body, then headers/footers are skipped
	 * (they repeat on every page and add noise), footnotes kept.
	 *
	 * @param \ZipArchive $zip Archive.
	 * @return array<int, string>
	 */
	private static function docx_parts( \ZipArchive $zip ): array {
		$parts = array();

		foreach ( array( 'word/document.xml', 'word/footnotes.xml', 'word/endnotes.xml' ) as $name ) {
			if ( false !== $zip->locateName( $name ) ) {
				$parts[] = $name;
			}
		}

		return $parts;
	}

	/**
	 * WordprocessingML to text.
	 *
	 * @param string $xml document.xml.
	 */
	private static function docx_xml( string $xml ): string {
		// Breaks and tabs first, then paragraph/row/cell ends, then drop tags.
		$xml = preg_replace( '#<w:(br|cr)\b[^>]*/>#', "\n", $xml ) ?? $xml;
		$xml = preg_replace( '#<w:tab\b[^>]*/>#', ' ', $xml ) ?? $xml;
		// A table cell holds its own paragraphs: keep them on the row's line.
		$xml = preg_replace_callback( '#<w:tc\b.*?</w:tc>#s', static fn( array $m ): string => str_replace( '</w:p>', ' ', $m[0] ) . ' | ', $xml ) ?? $xml;
		$xml = preg_replace( '#</w:(p|tr)>#', "\n\n", $xml ) ?? $xml;
		// Deleted text in tracked changes is not part of the document.
		$xml = preg_replace( '#<w:del\b.*?</w:del>#s', '', $xml ) ?? $xml;
		$xml = preg_replace( '#<w:instrText\b.*?</w:instrText>#s', '', $xml ) ?? $xml;

		return html_entity_decode( wp_strip_all_tags( $xml, false ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * OpenDocument text to text.
	 *
	 * @param string $xml content.xml.
	 */
	private static function odt_xml( string $xml ): string {
		$xml = preg_replace( '#<text:(line-break|tab)\b[^>]*/>#', "\n", $xml ) ?? $xml;
		$xml = preg_replace( '#<text:s\b[^>]*/>#', ' ', $xml ) ?? $xml;
		$xml = preg_replace_callback( '#<table:table-cell\b.*?</table:table-cell>#s', static fn( array $m ): string => preg_replace( '#</text:(p|h)>#', ' ', $m[0] ) . ' | ', $xml ) ?? $xml;
		$xml = preg_replace( '#</(text:p|text:h|table:table-row)>#', "\n\n", $xml ) ?? $xml;

		return html_entity_decode( wp_strip_all_tags( $xml, false ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Collapse whitespace, keep paragraphs.
	 *
	 * @param string $text Text.
	 */
	private static function tidy( string $text ): string {
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/ *\| *(\n|$)/', '$1', $text ) ?? $text;
		$text = preg_replace( '/ *\n */', "\n", $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
