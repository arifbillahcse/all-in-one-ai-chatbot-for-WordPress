<?php
/**
 * Streaming HTTP.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * POSTs JSON and hands back a server-sent-events response as it arrives.
 *
 * The WordPress HTTP API only returns a response once it is complete, so
 * streaming uses cURL directly. It still honours WordPress's CA bundle and
 * proxy constants, so it behaves like the rest of the site's outgoing calls.
 */
class StreamTransport {

	/**
	 * Send a request and feed each SSE `data:` payload to a callback.
	 *
	 * @param string                       $provider Provider id, for errors.
	 * @param string                       $url      Endpoint.
	 * @param array<string, string>        $headers  Headers.
	 * @param array<string, mixed>         $body     JSON body.
	 * @param callable(string, string): void $on_event Receives (event name, data) per SSE event.
	 * @param int                          $timeout  Seconds.
	 * @throws LlmException On transport or HTTP failure.
	 */
	public function post( string $provider, string $url, array $headers, array $body, callable $on_event, int $timeout = 60 ): void {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new LlmException( 'cURL is not available for streaming.', $provider, 'network' );
		}

		$parser = new SseParser( $on_event );
		$status = 0;
		$raw    = '';
		$failed = null;

		$lines = array( 'Content-Type: application/json', 'Accept: text/event-stream' );
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$handle = curl_init( $url );

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => (string) wp_json_encode( $body ),
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_HEADERFUNCTION => static function ( $ch, string $header ) use ( &$status ): int {
					if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {
						$status = (int) $m[1];
					}
					return strlen( $header );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $ch, string $chunk ) use ( &$status, &$raw, &$failed, $parser ): int {
					if ( $status >= 200 && $status < 300 ) {
						// An exception must not unwind through cURL's C code:
						// keep it, and return 0 to abort the transfer.
						try {
							$parser->feed( $chunk );
						} catch ( \Throwable $e ) {
							$failed = $e;
							return 0;
						}
					} elseif ( strlen( $raw ) < 8192 ) {
						$raw .= $chunk;
					}
					return strlen( $chunk );
				},
			)
		);

		self::apply_proxy( $handle, $url );

		$ok    = curl_exec( $handle );
		$error = curl_error( $handle );
		curl_close( $handle );

		if ( null !== $failed ) {
			throw $failed instanceof LlmException ? $failed : new LlmException( $failed->getMessage(), $provider, 'server' );
		}

		if ( false === $ok ) {
			throw new LlmException( '' !== $error ? $error : 'Streaming request failed.', $provider, str_contains( strtolower( $error ), 'timed out' ) ? 'timeout' : 'network' );
		}

		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$message = is_array( $decoded ) ? ( $decoded['error']['message'] ?? $decoded['message'] ?? '' ) : '';

			throw new LlmException(
				sprintf( 'HTTP %d: %s', $status, '' !== $message ? $message : mb_substr( wp_strip_all_tags( $raw ), 0, 200 ) ),
				$provider,
				match ( true ) {
					401 === $status, 403 === $status => 'auth',
					429 === $status                  => 'rate_limited',
					$status >= 500                   => 'server',
					default                          => 'bad_request',
				},
				$status
			);
		}

		try {
			$parser->finish();
		} catch ( LlmException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new LlmException( $e->getMessage(), $provider, 'server' );
		}
	}

	/**
	 * Route through the proxy configured for WordPress, if any.
	 *
	 * @param \CurlHandle $handle Handle.
	 * @param string      $url    Target.
	 */
	private static function apply_proxy( \CurlHandle $handle, string $url ): void {
		if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
			return;
		}

		$proxy = new \WP_HTTP_Proxy();

		if ( ! $proxy->is_enabled() || ! $proxy->send_through_proxy( $url ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_PROXY, $proxy->host() );
		curl_setopt( $handle, CURLOPT_PROXYPORT, (int) $proxy->port() );

		if ( $proxy->use_authentication() ) {
			curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $proxy->authentication() );
		}
	}
}
