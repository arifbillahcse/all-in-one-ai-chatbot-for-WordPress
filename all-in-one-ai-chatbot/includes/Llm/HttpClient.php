<?php
/**
 * JSON over HTTPS via the WordPress HTTP API.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Posts JSON to a provider and turns every failure into an LlmException.
 *
 * Uses wp_remote_post rather than cURL directly so the site's proxy settings,
 * CA bundle and any HTTP filters apply, as WordPress.org requires.
 */
final class HttpClient {

	/**
	 * POST a JSON body and return the decoded JSON response.
	 *
	 * @param string               $provider Provider id, for error context.
	 * @param string               $url      Endpoint.
	 * @param array<string, string> $headers Extra headers.
	 * @param array<string, mixed> $body     Request body.
	 * @param int                  $timeout  Seconds.
	 * @return array<string, mixed>
	 * @throws LlmException On any failure.
	 */
	public function post_json( string $provider, string $url, array $headers, array $body, int $timeout = 45 ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $this->timeout( $timeout ),
				'redirection' => 0,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$type    = str_contains( strtolower( $message ), 'timed out' ) ? 'timeout' : 'network';

			throw new LlmException( $message, $provider, $type );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			throw new LlmException(
				$this->error_message( $status, is_array( $decoded ) ? $decoded : array(), $raw ),
				$provider,
				$this->error_type( $status ),
				$status
			);
		}

		if ( ! is_array( $decoded ) ) {
			throw new LlmException( 'The provider returned a response that is not JSON.', $provider, 'malformed', $status );
		}

		return $decoded;
	}

	/**
	 * Keep the call inside PHP's own execution limit.
	 *
	 * On shared hosting max_execution_time is often 30 seconds. A longer HTTP
	 * timeout never fires — PHP dies first and the visitor gets a blank error
	 * instead of a polite one.
	 *
	 * @param int $wanted Desired timeout.
	 */
	private function timeout( int $wanted ): int {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			return $wanted;
		}

		$elapsed = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;

		return max( 5, min( $wanted, (int) floor( $limit - $elapsed - 3 ) ) );
	}

	/**
	 * Map an HTTP status to a failure type.
	 *
	 * @param int $status HTTP status.
	 */
	private function error_type( int $status ): string {
		return match ( true ) {
			401 === $status, 403 === $status => 'auth',
			429 === $status                  => 'rate_limited',
			408 === $status                  => 'timeout',
			$status >= 500                   => 'server',
			default                          => 'bad_request',
		};
	}

	/**
	 * The provider's own error text, which is what a site owner needs to fix it.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $decoded Decoded body.
	 * @param string               $raw     Raw body.
	 */
	private function error_message( int $status, array $decoded, string $raw ): string {
		$error  = $decoded['error'] ?? null;
		$detail = '';

		if ( is_array( $error ) && is_string( $error['message'] ?? null ) ) {
			$detail = $error['message'];
		} elseif ( is_string( $error ) ) {
			$detail = $error;
		} elseif ( is_string( $decoded['message'] ?? null ) ) {
			$detail = $decoded['message'];
		} else {
			$detail = mb_substr( wp_strip_all_tags( $raw ), 0, 200, 'UTF-8' );
		}

		return sprintf( 'HTTP %d: %s', $status, '' !== $detail ? $detail : 'no details' );
	}
}
