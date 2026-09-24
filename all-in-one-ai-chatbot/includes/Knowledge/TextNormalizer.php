<?php
/**
 * Post content to plain text.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WordPress post content into plain text worth indexing.
 *
 * WordPress bodies are not clean prose: they carry Gutenberg block comments,
 * shortcodes, inline HTML and page-builder markup. Indexing that wastes context
 * tokens on syntax and dilutes search, so everything non-textual is stripped
 * before chunking.
 */
final class TextNormalizer {

	/**
	 * Elements whose content is markup rather than prose and goes with them.
	 */
	private const STRIP_ELEMENTS = array( 'script', 'style', 'noscript', 'svg', 'iframe', 'form' );

	/**
	 * HTML to readable plain text, keeping paragraph breaks.
	 *
	 * @param string $html Post content.
	 */
	public function normalize( string $html ): string {
		$text = $html;

		// Gutenberg block delimiters and any other HTML comments.
		$text = preg_replace( '/<!--.*?-->/s', '', $text ) ?? $text;

		foreach ( self::STRIP_ELEMENTS as $element ) {
			$text = preg_replace( '#<' . $element . '\b[^>]*>.*?</' . $element . '>#is', ' ', $text ) ?? $text;
			$text = preg_replace( '#<' . $element . '\b[^>]*/?>#i', ' ', $text ) ?? $text;
		}

		// Block-level tags become paragraph breaks so chunking can split on
		// meaning rather than mid-sentence. Table cells get a separator so a
		// price table still reads as "Plan | Price" rather than "PlanPrice".
		$text = preg_replace( '#</(p|div|section|article|header|footer|h[1-6]|li|tr|blockquote|pre|dd|dt|figcaption)\s*>#i', "\n\n", $text ) ?? $text;
		$text = preg_replace( '#</t[dh]\s*>#i', ' | ', $text ) ?? $text;
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text ) ?? $text;

		// Shortcode tags go, the text they wrap stays: [button]Buy[/button] -> Buy.
		$text = preg_replace( '/\[\/?[a-z0-9_\-]+(?:[^\]]*)?\]/i', ' ', $text ) ?? $text;

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Non-breaking and zero-width spaces break whitespace collapsing.
		$text = str_replace( array( "\u{00A0}", "\u{200B}", "\u{FEFF}" ), ' ', $text );

		return $this->collapse_whitespace( $text );
	}

	/**
	 * Collapse runs of spaces and blank lines, keeping paragraph structure.
	 *
	 * @param string $text Text.
	 */
	private function collapse_whitespace( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/ *\n */', "\n", $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
