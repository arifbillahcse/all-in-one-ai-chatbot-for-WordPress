<?php
/**
 * Web pages and sitemaps as knowledge.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Sources;

use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\PageRules;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Imports pages from the web: a help centre on another domain, a docs site,
 * or this site's own pages built with a page builder that keeps its text
 * outside the post content.
 *
 * The owner lists page addresses, sitemaps, or just a site address (its
 * sitemap is then found through robots.txt or the usual locations). Each page
 * is fetched in the background through the job queue, a few per minute, so
 * neither this server nor the other site is hammered, and robots.txt is
 * respected. Imported pages link back to their address under answers.
 */
final class WebImporter {

	public const JOB        = 'source.fetch';
	public const RESYNC     = 'softorio_ai_web_resync';
	public const MAX_PAGES  = 500;
	private const PER_MINUTE = 15;
	private const MAX_BYTES  = 3 * MB_IN_BYTES;

	/**
	 * Register the job handler and the re-sync schedule.
	 */
	public static function init(): void {
		Queue::register( self::JOB, array( self::class, 'handle' ) );
		add_action( self::RESYNC, array( self::class, 'resync' ) );
		add_action( 'softorio_ai_settings_changed', array( self::class, 'schedule' ) );
	}

	/**
	 * Keep the re-sync event in step with the setting.
	 */
	public static function schedule(): void {
		$every = (string) Settings::get( 'web_resync', 'off' );

		wp_clear_scheduled_hook( self::RESYNC );

		if ( in_array( $every, array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $every, self::RESYNC );
		}
	}

	/**
	 * Queue every imported page for a fresh fetch (the scheduled re-sync).
	 */
	public static function resync(): int {
		$urls = array();

		foreach ( SourceStore::ids( 'url' ) as $post_id ) {
			$url = (string) get_post_meta( $post_id, SourceStore::URL, true );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		// Audience null: re-fetching keeps whatever each article has now.
		return self::queue( $urls, null );
	}

	/**
	 * Expand the owner's input into page addresses.
	 *
	 * @param string $input   One address per line: pages, sitemaps (.xml) or site roots.
	 * @param string $include Optional path patterns (one per line) pages must match.
	 * @return array{urls: array<int, string>, errors: array<int, string>}
	 */
	public static function discover( string $input, string $include = '' ): array {
		$urls   = array();
		$errors = array();

		foreach ( preg_split( '/\R/', $input ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$url = self::normalise( $line );

			if ( null === $url ) {
				/* translators: %s: what the owner typed */
				$errors[] = sprintf( __( 'Not a web address: %s', 'all-in-one-ai-chatbot' ), $line );
				continue;
			}

			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			try {
				if ( preg_match( '#\.xml(\.gz)?$#i', $path ) ) {
					$urls = array_merge( $urls, self::sitemap( $url ) );
				} elseif ( '' === trim( $path, '/' ) && '' === (string) wp_parse_url( $url, PHP_URL_QUERY ) ) {
					// A bare site address: use its sitemap when it has one.
					$map  = self::find_sitemap( $url );
					$urls = array_merge( $urls, null !== $map ? self::sitemap( $map ) : array( $url ) );
				} else {
					$urls[] = $url;
				}
			} catch ( SourceException $e ) {
				$errors[] = $e->getMessage();
			}
		}

		$urls = array_values( array_unique( $urls ) );

		if ( '' !== trim( $include ) ) {
			$urls = array_values(
				array_filter(
					$urls,
					static fn( string $u ): bool => PageRules::matches( (string) wp_parse_url( $u, PHP_URL_PATH ) ?: '/', $include )
				)
			);
		}

		return array(
			'urls'   => array_slice( $urls, 0, self::MAX_PAGES ),
			'errors' => $errors,
		);
	}

	/**
	 * Queue page fetches, spread out over time.
	 *
	 * @param array<int, string> $urls     Pages.
	 * @param string|null        $audience Audience marker; null keeps each article's own.
	 * @return int Pages queued.
	 */
	public static function queue( array $urls, ?string $audience ): int {
		$queued = 0;

		foreach ( array_values( array_unique( $urls ) ) as $i => $url ) {
			// A few per minute: polite to the other site, and emails and
			// webhooks queued meanwhile are not stuck behind hundreds of pages.
			Queue::push(
				self::JOB,
				array(
					'url'      => $url,
					'audience' => $audience,
				),
				intdiv( $i, self::PER_MINUTE ) * MINUTE_IN_SECONDS
			);
			++$queued;
		}

		return $queued;
	}

	/**
	 * Pages still waiting to be fetched.
	 */
	public static function pending(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = %s AND status IN ('pending', 'running')", \Softorio\AiAssistant\Installer::tables()['jobs'], self::JOB ) );
	}

	/**
	 * Queue handler: fetch one page and store it.
	 *
	 * @param array<string, mixed> $job Payload.
	 * @throws \RuntimeException On a temporary failure, so the queue retries.
	 */
	public static function handle( array $job ): void {
		$url      = self::normalise( (string) ( $job['url'] ?? '' ) );
		$audience = isset( $job['audience'] ) && is_string( $job['audience'] ) ? $job['audience'] : null;

		if ( null === $url ) {
			return;
		}

		self::import( $url, $audience );
	}

	/**
	 * Fetch and store one page.
	 *
	 * @param string      $url      Page.
	 * @param string|null $audience Audience marker; null keeps the existing one.
	 * @return string created|updated|unchanged|gone|skipped
	 * @throws \RuntimeException On a temporary failure.
	 */
	public static function import( string $url, ?string $audience = null ): string {
		if ( ! self::allowed_by_robots( $url ) ) {
			Log::info( 'sources', 'Skipped a page blocked by robots.txt', array( 'url' => $url ) );
			return 'skipped';
		}

		$response = self::get( $url );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Could not fetch ' . $url . ': ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 404, 410 ), true ) ) {
			return self::gone( $url, $code );
		}

		if ( $code >= 500 || 429 === $code ) {
			throw new \RuntimeException( 'HTTP ' . $code . ' from ' . $url );
		}

		if ( $code < 200 || $code >= 300 ) {
			Log::warning( 'sources', 'Page not imported (HTTP ' . $code . ')', array( 'url' => $url ) );
			return 'skipped';
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body = (string) wp_remote_retrieve_body( $response );

		try {
			if ( str_contains( $type, 'application/pdf' ) || str_starts_with( $body, '%PDF-' ) ) {
				$page = array(
					'title' => FileImporter::title_from_name( rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ),
					'text'  => PdfText::extract( $body ),
				);
			} elseif ( '' === $type || str_contains( $type, 'html' ) ) {
				$charset = preg_match( '/charset=([\w-]+)/', $type, $m ) ? $m[1] : '';
				$page    = HtmlText::extract( $body, $charset );
			} elseif ( str_contains( $type, 'text/plain' ) ) {
				$page = array(
					'title' => FileImporter::title_from_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ),
					'text'  => FileImporter::plain( $body ),
				);
			} else {
				Log::info( 'sources', 'Skipped a file that is not a page (' . $type . ')', array( 'url' => $url ) );
				return 'skipped';
			}
		} catch ( SourceException $e ) {
			Log::warning( 'sources', $e->getMessage(), array( 'url' => $url ) );
			return 'skipped';
		}

		if ( mb_strlen( $page['text'], 'UTF-8' ) < 50 ) {
			Log::info( 'sources', 'Skipped a page with almost no text', array( 'url' => $url ) );
			return 'skipped';
		}

		$title = '' !== $page['title'] ? $page['title'] : $url;

		[ , $state ] = SourceStore::upsert(
			'url',
			$url,
			$title,
			$page['text'],
			array(
				'url'      => $url,
				'audience' => $audience,
			)
		);

		return $state;
	}

	/**
	 * A page that no longer exists: its article is unpublished, not deleted,
	 * so the owner can see what happened and restore it.
	 *
	 * @param string $url  Page.
	 * @param int    $code HTTP status.
	 */
	private static function gone( string $url, int $code ): string {
		$post_id = SourceStore::find( 'url', $url );

		if ( null !== $post_id ) {
			update_post_meta( $post_id, SourceStore::STATUS, 'gone' );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			Log::warning( 'sources', 'An imported page is gone (HTTP ' . $code . '); its article was unpublished', array( 'url' => $url ) );
		}

		return 'gone';
	}

	/**
	 * Page addresses in a sitemap (or sitemap index, followed one level).
	 *
	 * @param string $url   Sitemap address.
	 * @param int    $depth Nesting level.
	 * @return array<int, string>
	 * @throws SourceException When the sitemap cannot be read.
	 */
	public static function sitemap( string $url, int $depth = 0 ): array {
		$response = self::get( $url );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			/* translators: %s: sitemap address */
			throw new SourceException( sprintf( __( 'Could not download the sitemap %s.', 'all-in-one-ai-chatbot' ), $url ) );
		}

		$xml = (string) wp_remote_retrieve_body( $response );

		if ( str_starts_with( $xml, "\x1f\x8b" ) ) {
			$xml = (string) @gzdecode( $xml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
		}

		$locs = self::sitemap_locations( $xml );

		if ( null === $locs ) {
			/* translators: %s: sitemap address */
			throw new SourceException( sprintf( __( '%s is not a valid sitemap.', 'all-in-one-ai-chatbot' ), $url ) );
		}

		if ( 'index' === $locs['type'] ) {
			$pages = array();

			if ( $depth >= 2 ) {
				return array();
			}

			foreach ( array_slice( $locs['urls'], 0, 50 ) as $child ) {
				try {
					$pages = array_merge( $pages, self::sitemap( $child, $depth + 1 ) );
				} catch ( SourceException $e ) {
					Log::warning( 'sources', $e->getMessage() );
				}

				if ( count( $pages ) >= self::MAX_PAGES ) {
					break;
				}
			}

			return $pages;
		}

		return $locs['urls'];
	}

	/**
	 * Parse sitemap XML.
	 *
	 * @param string $xml Sitemap.
	 * @return array{type: string, urls: array<int, string>}|null
	 */
	public static function sitemap_locations( string $xml ): ?array {
		if ( '' === trim( $xml ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $doc ) {
			return null;
		}

		$type = 'sitemapindex' === $doc->getName() ? 'index' : ( 'urlset' === $doc->getName() ? 'urlset' : '' );

		if ( '' === $type ) {
			return null;
		}

		$urls = array();

		foreach ( $doc->children() as $entry ) {
			$loc = self::normalise( trim( (string) $entry->loc ) );

			if ( null !== $loc ) {
				$urls[] = $loc;
			}
		}

		return array(
			'type' => $type,
			'urls' => $urls,
		);
	}

	/**
	 * A site's sitemap: from robots.txt, else the usual places.
	 *
	 * @param string $site Site address.
	 */
	public static function find_sitemap( string $site ): ?string {
		$root = self::origin( $site );

		if ( preg_match_all( '/^\s*sitemap:\s*(\S+)/im', self::robots( $root ), $m ) ) {
			foreach ( $m[1] as $candidate ) {
				$candidate = self::normalise( $candidate );
				if ( null !== $candidate ) {
					return $candidate;
				}
			}
		}

		foreach ( array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml' ) as $path ) {
			$response = self::get( $root . $path );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && null !== self::sitemap_locations( (string) wp_remote_retrieve_body( $response ) ) ) {
				return $root . $path;
			}
		}

		return null;
	}

	/**
	 * Whether robots.txt lets us fetch a page.
	 *
	 * @param string $url Page.
	 */
	public static function allowed_by_robots( string $url ): bool {
		$rules = self::robots_rules( self::robots( self::origin( $url ) ) );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );

		$best_len = -1;
		$allowed  = true;

		foreach ( $rules as [ $allow, $pattern ] ) {
			$regex = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $pattern, '#' ) ) . '#';

			if ( 1 === preg_match( $regex, $path ) && strlen( $pattern ) > $best_len ) {
				$best_len = strlen( $pattern );
				$allowed  = $allow;
			}
		}

		return $allowed;
	}

	/**
	 * Allow/Disallow rules that apply to us (our agent group, else "*").
	 *
	 * @param string $robots robots.txt.
	 * @return array<int, array{0: bool, 1: string}>
	 */
	public static function robots_rules( string $robots ): array {
		$groups  = array();
		$current = array();
		$in_rules = false;

		foreach ( preg_split( '/\R/', $robots ) ?: array() as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) ?? '' );

			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			[ $key, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$key             = strtolower( $key );

			if ( 'user-agent' === $key ) {
				if ( $in_rules ) {
					$current  = array();
					$in_rules = false;
				}
				$current[]                        = strtolower( $value );
				$groups[ strtolower( $value ) ] ??= array();
				continue;
			}

			if ( 'allow' === $key || 'disallow' === $key ) {
				$in_rules = true;

				if ( '' === $value ) {
					continue; // "Disallow:" with nothing allows everything.
				}

				foreach ( $current as $agent ) {
					$groups[ $agent ][] = array( 'allow' === $key, $value );
				}
			}
		}

		foreach ( $groups as $agent => $rules ) {
			if ( '*' !== $agent && '' !== $agent && str_contains( strtolower( self::AGENT_TOKEN ), $agent ) ) {
				return $rules;
			}
		}

		return $groups['*'] ?? array();
	}

	private const AGENT_TOKEN = 'AllInOneAIChatbot';

	/**
	 * robots.txt of an origin, cached for an hour.
	 *
	 * @param string $origin scheme://host[:port].
	 */
	private static function robots( string $origin ): string {
		$key    = 'softorio_ai_robots_' . md5( $origin );
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$response = self::get( $origin . '/robots.txt' );
		$body     = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$body     = mb_substr( $body, 0, 100000 );

		set_transient( $key, $body, HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * GET with our user agent and limits. Private network addresses are
	 * refused (wp_safe_remote_get), except this site's own address.
	 *
	 * @param string $url Address.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get( string $url ) {
		$own   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allow = static fn( $external, $host ) => $external || strtolower( (string) $host ) === $own;

		add_filter( 'http_request_host_is_external', $allow, 10, 2 );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => self::AGENT_TOKEN . '/' . SOFTORIO_AI_VERSION . ' (+' . home_url( '/' ) . ')',
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/pdf;q=0.8,*/*;q=0.5' ),
			)
		);

		remove_filter( 'http_request_host_is_external', $allow, 10 );

		return $response;
	}

	/**
	 * A clean http(s) address without fragment, or null.
	 *
	 * @param string $url Address as typed.
	 */
	public static function normalise( string $url ): ?string {
		$url = trim( $url );

		if ( '' === $url ) {
			return null;
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			// "example.com/help" as typed by a person.
			if ( ! preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $url ) ) {
				return null;
			}
			$url = 'https://' . $url;
		}

		$url  = (string) preg_replace( '/#.*$/', '', $url );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host || str_contains( $url, ' ' ) ) {
			return null;
		}

		return esc_url_raw( $url, array( 'http', 'https' ) ) ?: null;
	}

	/**
	 * scheme://host[:port] of an address.
	 *
	 * @param string $url Address.
	 */
	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) ) . $port;
	}
}
