<?php
/**
 * Outgoing webhooks.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Notify;

use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Posts events as signed JSON to the owner's URLs.
 *
 * One mechanism that reaches Zapier, Make, n8n, Google Sheets (via Apps
 * Script) and most CRMs. Each delivery is its own queued job, so one dead
 * endpoint is retried on its own without holding up the others.
 *
 * Requests go through wp_safe_remote_post, which refuses private and local
 * addresses — a webhook URL must not become a way to probe the server's own
 * network.
 */
final class Webhooks {

	/**
	 * Subscribe and register the delivery handler.
	 */
	public static function init(): void {
		Events::listen( '*', array( self::class, 'on_event' ) );
		Queue::register( 'webhook.deliver', array( self::class, 'deliver' ) );
	}

	/**
	 * Configured endpoint URLs.
	 *
	 * @return array<int, string>
	 */
	public static function urls(): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'webhook_urls', '' ) ) ) ) );
	}

	/**
	 * Queue one delivery per URL for subscribed events.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 * @param string               $event   Event name.
	 */
	public static function on_event( array $payload, string $event ): void {
		if ( ! in_array( $event, (array) Settings::get( 'webhook_events', array() ), true ) ) {
			return;
		}

		foreach ( self::urls() as $url ) {
			Queue::push(
				'webhook.deliver',
				array(
					'url'      => $url,
					'event'    => $event,
					'delivery' => wp_generate_uuid4(),
					'body'     => $payload,
				)
			);
		}
	}

	/**
	 * Deliver one webhook. Throws on failure so the queue retries.
	 *
	 * @param array<string, mixed> $job url, event, delivery, body.
	 * @throws \RuntimeException On a failed delivery.
	 */
	public static function deliver( array $job ): void {
		$result = self::post( (string) $job['url'], (string) $job['event'], (string) $job['delivery'], (array) $job['body'] );

		if ( ! $result['ok'] ) {
			throw new \RuntimeException( $result['message'] );
		}

		Log::info( 'webhook', sprintf( 'Delivered %s to %s', $job['event'], self::host( (string) $job['url'] ) ), array( 'status' => $result['status'] ) );
	}

	/**
	 * Send one signed request.
	 *
	 * @param string               $url      Endpoint.
	 * @param string               $event    Event name.
	 * @param string               $delivery Unique delivery id, for the receiver to de-duplicate retries.
	 * @param array<string, mixed> $payload  Body.
	 * @return array{ok: bool, status: int, message: string}
	 */
	public static function post( string $url, string $event, string $delivery, array $payload ): array {
		$body = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'headers'     => array(
					'Content-Type'     => 'application/json; charset=utf-8',
					'User-Agent'       => 'AllInOneAIChatbot/' . SOFTORIO_AI_VERSION . '; ' . home_url( '/' ),
					'X-AICB-Event'     => $event,
					'X-AICB-Delivery'  => $delivery,
					'X-AICB-Signature' => 'sha256=' . self::sign( $body ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'status'  => 0,
				'message' => sprintf( 'Could not reach %s: %s', self::host( $url ), $response->get_error_message() ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return array(
				'ok'      => false,
				'status'  => $status,
				'message' => sprintf( '%s answered HTTP %d: %s', self::host( $url ), $status, mb_substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 200 ) ),
			);
		}

		return array(
			'ok'      => true,
			'status'  => $status,
			'message' => sprintf( '%s answered HTTP %d', self::host( $url ), $status ),
		);
	}

	/**
	 * HMAC-SHA256 of a body with the signing secret.
	 *
	 * @param string $body Raw request body.
	 */
	public static function sign( string $body ): string {
		return hash_hmac( 'sha256', $body, self::secret() );
	}

	/**
	 * The signing secret, created on first use if the owner never saved the tab.
	 */
	public static function secret(): string {
		$secret = (string) Settings::get( 'webhook_secret', '' );

		if ( '' === $secret ) {
			$secret                     = wp_generate_password( 40, false );
			$settings                   = Settings::all();
			$settings['webhook_secret'] = $secret;
			update_option( Settings::OPTION, $settings );
		}

		return $secret;
	}

	/**
	 * Host part of a URL, for log lines that should not repeat full URLs (they often embed tokens).
	 *
	 * @param string $url URL.
	 */
	private static function host( string $url ): string {
		return (string) wp_parse_url( $url, PHP_URL_HOST );
	}
}
