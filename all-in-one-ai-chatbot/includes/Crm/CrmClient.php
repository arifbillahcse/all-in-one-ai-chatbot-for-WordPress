<?php
/**
 * Shared CRM plumbing.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Base for the CRM connectors: HTTP with error classification, and turning
 * a lead into the fields every CRM wants.
 */
abstract class CrmClient {

	/** Short id, also the settings prefix: hubspot, mailchimp, brevo. */
	abstract public function id(): string;

	/** Display name. */
	abstract public function name(): string;

	/**
	 * Add or update the contact for a lead.
	 *
	 * @param array<string, mixed>             $lead       Lead row.
	 * @param array<int, array<string, mixed>> $transcript Chat so far.
	 * @return string The contact's id or address in the CRM, for the record.
	 * @throws CrmException On a permanent failure.
	 * @throws \RuntimeException On a temporary failure (retried).
	 */
	abstract public function push( array $lead, array $transcript ): string;

	/**
	 * Check the saved settings work (the "Test connection" button).
	 *
	 * @return string What was found, for the owner.
	 * @throws \RuntimeException When they do not.
	 */
	abstract public function test(): string;

	/**
	 * Whether this CRM is switched on and has a key.
	 */
	public function enabled(): bool {
		return (bool) Settings::get( $this->id() . '_enabled', false ) && '' !== $this->key();
	}

	/**
	 * The decrypted API key.
	 */
	protected function key(): string {
		return Settings::api_key( $this->id() );
	}

	/**
	 * Call an API and decode its JSON.
	 *
	 * 2xx returns the body; 401/403/404/400-type answers are permanent
	 * (CrmException); 429, 5xx and network errors are temporary.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     URL.
	 * @param array<string, mixed> $body    JSON body, or empty.
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 * @throws CrmException On a permanent failure.
	 * @throws \RuntimeException On a temporary failure.
	 */
	protected function request( string $method, string $url, array $body = array(), array $headers = array() ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => $headers + array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => array() === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $this->name() . ' unreachable: ' . $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code >= 200 && $code < 300 ) {
			return $decoded;
		}

		$message = $this->error_message( $decoded );
		$detail  = sprintf( '%s: HTTP %d%s', $this->name(), $code, '' !== $message ? ' — ' . $message : '' );

		if ( 429 === $code || $code >= 500 ) {
			throw new \RuntimeException( $detail );
		}

		throw new CrmException( 401 === $code || 403 === $code ? $this->name() . ': ' . __( 'the API key was refused. Check it in Settings → Integrations.', 'all-in-one-ai-chatbot' ) . ( '' !== $message ? ' (' . $message . ')' : '' ) : $detail, $code );
	}

	/**
	 * The human-readable error in an API's error body.
	 *
	 * @param array<string, mixed> $body Decoded body.
	 */
	protected function error_message( array $body ): string {
		foreach ( array( 'message', 'detail', 'error', 'title' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) && '' !== $body[ $key ] ) {
				return mb_substr( $body[ $key ], 0, 300 );
			}
		}

		return '';
	}

	/**
	 * "Rahim Uddin Ahmed" -> ["Rahim", "Uddin Ahmed"].
	 *
	 * @param string $name Full name.
	 * @return array{0: string, 1: string}
	 */
	public static function split_name( string $name ): array {
		$parts = preg_split( '/\s+/u', trim( $name ), 2 ) ?: array();

		return array( (string) ( $parts[0] ?? '' ), (string) ( $parts[1] ?? '' ) );
	}

	/**
	 * A phone number in international format (+8801711000000), or '' when
	 * it cannot be made one. Numbers without a country code use the
	 * default country code from the settings (Bangladesh: 880).
	 *
	 * @param string $phone As typed.
	 */
	public static function e164( string $phone ): string {
		$phone = trim( $phone );

		if ( '' === $phone ) {
			return '';
		}

		$plus   = str_starts_with( $phone, '+' ) || str_starts_with( $phone, '00' );
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		$digits = str_starts_with( $phone, '00' ) ? substr( $digits, 2 ) : $digits;

		if ( ! $plus ) {
			$country = preg_replace( '/\D+/', '', (string) Settings::get( 'crm_country_code', '880' ) ) ?? '';

			if ( '' === $country ) {
				return '';
			}

			// Local numbers start with a trunk 0 (017… in Bangladesh, 07… in the UK).
			$digits = $country . ltrim( $digits, '0' );
		}

		return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
	}

	/**
	 * Plain-text summary of the lead and chat, for CRM notes.
	 *
	 * @param array<string, mixed>             $lead       Lead.
	 * @param array<int, array<string, mixed>> $transcript Chat.
	 */
	protected static function summary( array $lead, array $transcript ): string {
		$lines = array( __( 'Lead from the website chat', 'all-in-one-ai-chatbot' ) );

		if ( '' !== (string) ( $lead['message'] ?? '' ) ) {
			$lines[] = __( 'Message', 'all-in-one-ai-chatbot' ) . ': ' . $lead['message'];
		}

		if ( '' !== (string) ( $lead['page_url'] ?? '' ) ) {
			$lines[] = __( 'Page', 'all-in-one-ai-chatbot' ) . ': ' . $lead['page_url'];
		}

		if ( array() !== $transcript ) {
			$lines[] = '';
			$lines[] = __( 'Conversation', 'all-in-one-ai-chatbot' ) . ':';
			$lines[] = \Softorio\AiAssistant\Chat\Transcript::text( $transcript );
		}

		return mb_substr( implode( "\n", $lines ), 0, 60000 );
	}
}
