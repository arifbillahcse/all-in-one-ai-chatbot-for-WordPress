<?php
/**
 * Readable text from a web page.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

use Softorio\AiAssistant\Knowledge\TextNormalizer;

defined( 'ABSPATH' ) || exit;

// DOM properties (textContent, parentNode…) are PHP's own camelCase names.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Pulls the main content out of an HTML page.
 *
 * Menus, headers, footers, cookie banners and sidebars repeat on every page;
 * indexing them would make every page look alike to the search and waste
 * tokens. So the page is parsed, that furniture is removed, and the main
 * content area is kept when the page marks one.
 */
final class HtmlText {

	/** Elements that are never content. */
	private const DROP_TAGS = array( 'script', 'style', 'noscript', 'svg', 'iframe', 'form', 'nav', 'header', 'footer', 'aside', 'template', 'button', 'select', 'dialog', 'canvas', 'video', 'audio', 'picture', 'img' );

	/** Class or id words of page furniture. */
	private const DROP_PATTERN = '/(^|[\s_-])(cookies?|cookie-notice|gdpr|consent|popup|modal|newsletter|share-buttons|sharing|sharedaddy|social-(?:links|icons|share)|breadcrumbs?|sidebar|widget-area|comments?|comment-respond|skip-link|screen-reader-text|related-posts|site-header|site-footer|mega-menu|offcanvas|off-canvas)($|[\s_-])/i';

	/** Candidates for the main content, best first. */
	private const MAIN_XPATH = array(
		'//main',
		'//*[@role="main"]',
		'//article',
		'//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
		'//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
		'//*[@id="content"]',
		'//*[@id="main"]',
	);

	/**
	 * Title and text of a page.
	 *
	 * @param string $html    Page HTML.
	 * @param string $charset Charset from the Content-Type header, if any.
	 * @return array{title: string, text: string, lang: string}
	 */
	public static function extract( string $html, string $charset = '' ): array {
		$html = self::to_utf8( $html, $charset );

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		// The XML declaration makes libxml read the markup as UTF-8.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new \DOMXPath( $doc );
		$title = self::title( $xpath );
		$lang  = (string) ( $xpath->query( '//html/@lang' )->item( 0 )->nodeValue ?? '' );

		self::drop_furniture( $xpath );

		$node = null;

		foreach ( self::MAIN_XPATH as $query ) {
			$found = $xpath->query( $query );

			foreach ( $found ?: array() as $candidate ) {
				if ( mb_strlen( trim( $candidate->textContent ), 'UTF-8' ) >= 200 ) {
					$node = $candidate;
					break 2;
				}
			}
		}

		$node ??= $xpath->query( '//body' )->item( 0 ) ?? $doc->documentElement;
		$inner = '';

		if ( null !== $node ) {
			foreach ( $node->childNodes as $child ) {
				$inner .= $doc->saveHTML( $child );
			}
		}

		return array(
			'title' => $title,
			'text'  => ( new TextNormalizer() )->normalize( $inner ),
			'lang'  => sanitize_text_field( $lang ),
		);
	}

	/**
	 * Convert to UTF-8 using the header charset or the page's own meta tag.
	 *
	 * @param string $html    HTML.
	 * @param string $charset Header charset.
	 */
	private static function to_utf8( string $html, string $charset ): string {
		if ( '' === $charset && preg_match( '/<meta[^>]+charset=["\']?([\w-]+)/i', substr( $html, 0, 4096 ), $m ) ) {
			$charset = $m[1];
		}

		$charset = strtoupper( trim( $charset ) );

		if ( '' !== $charset && 'UTF-8' !== $charset && 'UTF8' !== $charset ) {
			$converted = @mb_convert_encoding( $html, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unknown charsets fall back below.
			$html      = is_string( $converted ) ? $converted : $html;
		}

		$html = mb_check_encoding( $html, 'UTF-8' ) ? $html : (string) mb_convert_encoding( $html, 'UTF-8', 'Windows-1252' );

		// The page's own charset declaration would make libxml decode the
		// (now UTF-8) bytes a second time.
		return preg_replace( '/<meta[^>]+charset\s*=[^>]*>/i', '', $html ) ?? $html;
	}

	/**
	 * The page's title: og:title, then <title>, then the first h1.
	 *
	 * @param \DOMXPath $xpath Page.
	 */
	private static function title( \DOMXPath $xpath ): string {
		$read = static function ( string $query ) use ( $xpath ): string {
			$value = trim( (string) ( $xpath->query( $query )->item( 0 )->textContent ?? '' ) );

			return mb_substr( preg_replace( '/\s+/u', ' ', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ?? $value, 0, 200 );
		};

		$og    = $read( '//meta[@property="og:title"]/@content' );
		$title = $read( '//title' );
		$h1    = $read( '//h1' );

		if ( '' !== $og ) {
			return $og;
		}

		// "Delivery Information – Shop Name": the heading without the suffix.
		if ( '' !== $h1 && '' !== $title && str_starts_with( mb_strtolower( $title ), mb_strtolower( $h1 ) ) ) {
			return $h1;
		}

		return '' !== $title ? $title : $h1;
	}

	/**
	 * Remove navigation, banners and other repeated furniture.
	 *
	 * @param \DOMXPath $xpath Page.
	 */
	private static function drop_furniture( \DOMXPath $xpath ): void {
		$remove = array();

		foreach ( self::DROP_TAGS as $tag ) {
			foreach ( $xpath->query( '//' . $tag ) ?: array() as $node ) {
				$remove[] = $node;
			}
		}

		foreach ( $xpath->query( '//*[@class or @id or @role or @aria-hidden or @hidden]' ) ?: array() as $node ) {
			if ( ! $node instanceof \DOMElement || in_array( strtolower( $node->tagName ), array( 'html', 'body', 'main', 'article' ), true ) ) {
				continue;
			}

			$role  = strtolower( $node->getAttribute( 'role' ) );
			$words = $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' );

			if (
				in_array( $role, array( 'navigation', 'banner', 'contentinfo', 'complementary', 'dialog', 'alertdialog', 'search' ), true )
				|| 'true' === $node->getAttribute( 'aria-hidden' )
				|| $node->hasAttribute( 'hidden' )
				|| 1 === preg_match( self::DROP_PATTERN, $words )
			) {
				$remove[] = $node;
			}
		}

		foreach ( $remove as $node ) {
			if ( null !== $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}
}
