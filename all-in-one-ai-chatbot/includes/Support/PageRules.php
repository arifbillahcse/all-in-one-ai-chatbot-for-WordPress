<?php
/**
 * Page matching for "where to show".
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Matches the current page against owner-written rules.
 *
 * A rule is a path ("/pricing/"), a full URL of this site, or a pattern with
 * "*" ("/product/*"). Trailing slashes and query strings do not matter, and
 * a site installed in a subfolder is matched relative to its home.
 */
final class PageRules {

	/**
	 * Whether a path matches any of the rules.
	 *
	 * @param string $path  Request path relative to the site home, e.g. "/shop/".
	 * @param string $rules One rule per line.
	 */
	public static function matches( string $path, string $rules ): bool {
		$path = self::normalise( $path );

		foreach ( preg_split( '/\R/', $rules ) ?: array() as $rule ) {
			$rule = trim( $rule );

			if ( '' === $rule || str_starts_with( $rule, '#' ) ) {
				continue;
			}

			if ( preg_match( '#^https?://#i', $rule ) ) {
				$rule = self::relative( (string) wp_parse_url( $rule, PHP_URL_PATH ) );
			}

			$rule    = self::normalise( $rule );
			$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';

			if ( 1 === preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The current request's path, relative to the site home.
	 */
	public static function current_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return self::relative( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/**
	 * Strip the home path, for sites installed in a subfolder.
	 *
	 * @param string $path Absolute path.
	 */
	private static function relative( string $path ): string {
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $home && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Lower-case, single leading slash, no trailing slash (except the root).
	 *
	 * @param string $path Path or pattern.
	 */
	private static function normalise( string $path ): string {
		$path = '/' . ltrim( strtolower( rawurldecode( $path ) ), '/' );

		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
