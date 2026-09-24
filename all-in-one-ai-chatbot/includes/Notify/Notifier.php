<?php
/**
 * Email and Telegram notifications.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Notify;

use Softorio\AiAssistant\Chat\Transcript;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Turns events into emails and Telegram messages, sent from the queue.
 *
 * Messages are composed when the event happens (so they describe that
 * moment) and delivered later by a job. Secrets such as the Telegram token
 * are read at send time and never stored in the job table.
 */
final class Notifier {

	/**
	 * Subscribe to events and register job handlers.
	 */
	public static function init(): void {
		Events::listen( '*', array( self::class, 'on_event' ) );
		Queue::register( 'email.send', array( self::class, 'send_email' ) );
		Queue::register( 'telegram.send', array( self::class, 'send_telegram' ) );
	}

	/**
	 * Queue whatever notifications the owner wants for this event.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 * @param string               $event   Event name.
	 */
	public static function on_event( array $payload, string $event ): void {
		if ( in_array( $event, (array) Settings::get( 'notify_email_events', array() ), true ) ) {
			$message = self::compose_email( $event, $payload );

			if ( null !== $message ) {
				Queue::push( 'email.send', array( 'to' => self::owner_email() ) + $message );
			}
		}

		if ( Events::CONVERSATION_ENDED === $event && Settings::get( 'transcript_to_visitor', false ) ) {
			$email = (string) ( $payload['lead']['email'] ?? '' );

			if ( is_email( $email ) && array() !== (array) ( $payload['transcript'] ?? array() ) ) {
				Queue::push( 'email.send', array( 'to' => $email ) + self::compose_visitor_transcript( $payload ) );
			}
		}

		if ( self::telegram_ready() && in_array( $event, (array) Settings::get( 'telegram_events', array() ), true ) ) {
			$text = self::compose_telegram( $event, $payload );

			if ( null !== $text ) {
				Queue::push( 'telegram.send', array( 'text' => $text ) );
			}
		}
	}

	/**
	 * Where owner alerts go.
	 */
	public static function owner_email(): string {
		$email = sanitize_email( (string) Settings::get( 'notify_email', '' ) );

		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Whether Telegram has what it needs.
	 */
	public static function telegram_ready(): bool {
		return '' !== Settings::api_key( 'telegram' ) && '' !== trim( (string) Settings::get( 'telegram_chat_id', '' ) );
	}

	// ── Composing ──────────────────────────────────────────────────────────

	/**
	 * Owner email for an event.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $payload Payload.
	 * @return array{subject: string, html: string}|null
	 */
	public static function compose_email( string $event, array $payload ): ?array {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lead = is_array( $payload['lead'] ?? null ) ? $payload['lead'] : array();
		$who  = (string) ( $lead['name'] ?? '' ) ?: (string) ( $lead['email'] ?? '' ) ?: (string) ( $lead['phone'] ?? '' );

		$subject = match ( $event ) {
			/* translators: 1: site name, 2: visitor name or contact */
			Events::LEAD_CREATED        => sprintf( __( '[%1$s] New lead: %2$s', 'all-in-one-ai-chatbot' ), $site, $who ),
			/* translators: %s: site name */
			Events::HANDOFF_REQUESTED   => sprintf( __( '[%s] A visitor wants to talk to a person', 'all-in-one-ai-chatbot' ), $site ),
			/* translators: %s: site name */
			Events::QUESTION_UNANSWERED => sprintf( __( '[%s] The chatbot could not answer a question', 'all-in-one-ai-chatbot' ), $site ),
			/* translators: 1: site name, 2: conversation title */
			Events::CONVERSATION_ENDED  => sprintf( __( '[%1$s] Chat transcript: %2$s', 'all-in-one-ai-chatbot' ), $site, mb_substr( (string) ( $payload['title'] ?? '' ), 0, 60 ) ),
			default                     => null,
		};

		if ( null === $subject ) {
			return null;
		}

		$rows = array();

		if ( array() !== $lead ) {
			$rows[ __( 'Name', 'all-in-one-ai-chatbot' ) ]    = (string) ( $lead['name'] ?? '' );
			$rows[ __( 'Email', 'all-in-one-ai-chatbot' ) ]   = (string) ( $lead['email'] ?? '' );
			$rows[ __( 'Phone', 'all-in-one-ai-chatbot' ) ]   = (string) ( $lead['phone'] ?? '' );
			$rows[ __( 'Message', 'all-in-one-ai-chatbot' ) ] = (string) ( $lead['message'] ?? '' );
		}

		if ( Events::QUESTION_UNANSWERED === $event ) {
			$rows[ __( 'Question', 'all-in-one-ai-chatbot' ) ] = (string) ( $payload['question'] ?? '' );
			$rows[ __( 'Reply', 'all-in-one-ai-chatbot' ) ]    = (string) ( $payload['answer'] ?? '' );
		}

		$rows[ __( 'Page', 'all-in-one-ai-chatbot' ) ] = (string) ( $payload['page_url'] ?? $lead['page_url'] ?? '' );

		$html = self::table( array_filter( $rows, static fn( string $v ): bool => '' !== $v ) );

		$transcript = (array) ( $payload['transcript'] ?? array() );

		if ( array() !== $transcript ) {
			$html .= '<h3 style="font-size:15px;margin:24px 0 8px">' . esc_html__( 'Conversation', 'all-in-one-ai-chatbot' ) . '</h3>' . Transcript::html( $transcript );
		}

		if ( ! empty( $payload['admin_url'] ) ) {
			$html .= sprintf(
				'<p style="margin-top:24px"><a href="%s" style="background:#2563eb;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">%s</a></p>',
				esc_url( (string) $payload['admin_url'] ),
				esc_html__( 'Open in WordPress', 'all-in-one-ai-chatbot' )
			);
		}

		return array(
			'subject' => $subject,
			'html'    => self::wrap( $subject, $html ),
		);
	}

	/**
	 * Visitor's copy of their chat.
	 *
	 * @param array<string, mixed> $payload conversation.ended payload.
	 * @return array{subject: string, html: string}
	 */
	public static function compose_visitor_transcript( array $payload ): array {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your chat with %s', 'all-in-one-ai-chatbot' ), $site );

		$html = '<p>' . esc_html__( 'Here is a copy of your conversation. Reply to this email if you need anything else.', 'all-in-one-ai-chatbot' ) . '</p>'
			. Transcript::html( (array) $payload['transcript'] );

		return array(
			'subject'  => $subject,
			'html'     => self::wrap( $subject, $html ),
			'reply_to' => self::owner_email(),
		);
	}

	/**
	 * Telegram text for an event (Telegram's HTML subset, escaped).
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $payload Payload.
	 */
	public static function compose_telegram( string $event, array $payload ): ?string {
		$lead = is_array( $payload['lead'] ?? null ) ? $payload['lead'] : array();
		$e    = static fn( string $s ): string => htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );

		$title = match ( $event ) {
			Events::LEAD_CREATED        => '🟢 ' . __( 'New lead', 'all-in-one-ai-chatbot' ),
			Events::HANDOFF_REQUESTED   => '🙋 ' . __( 'Visitor wants a person', 'all-in-one-ai-chatbot' ),
			Events::QUESTION_UNANSWERED => '❓ ' . __( 'Unanswered question', 'all-in-one-ai-chatbot' ),
			Events::CONVERSATION_ENDED  => '💬 ' . __( 'Chat ended', 'all-in-one-ai-chatbot' ),
			default                     => null,
		};

		if ( null === $title ) {
			return null;
		}

		$lines = array( '<b>' . $e( $title ) . '</b> — ' . $e( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );

		foreach ( array( 'name', 'email', 'phone', 'message' ) as $field ) {
			if ( '' !== (string) ( $lead[ $field ] ?? '' ) ) {
				$lines[] = ucfirst( $field ) . ': ' . $e( (string) $lead[ $field ] );
			}
		}

		if ( ! empty( $payload['question'] ) ) {
			$lines[] = __( 'Question', 'all-in-one-ai-chatbot' ) . ': ' . $e( mb_substr( (string) $payload['question'], 0, 500 ) );
		}

		if ( ! empty( $payload['title'] ) && Events::CONVERSATION_ENDED === $event ) {
			$lines[] = $e( mb_substr( (string) $payload['title'], 0, 200 ) ) . ' (' . (int) ( $payload['message_count'] ?? 0 ) . ')';
		}

		if ( ! empty( $payload['admin_url'] ) ) {
			$lines[] = $e( (string) $payload['admin_url'] );
		}

		return mb_substr( implode( "\n", $lines ), 0, 4000 );
	}

	/**
	 * Key/value table for emails.
	 *
	 * @param array<string, string> $rows Rows.
	 */
	private static function table( array $rows ): string {
		$html = '<table style="border-collapse:collapse;width:100%">';

		foreach ( $rows as $label => $value ) {
			$html .= sprintf(
				'<tr><td style="padding:6px 12px 6px 0;color:#6b7280;vertical-align:top;white-space:nowrap">%s</td><td style="padding:6px 0">%s</td></tr>',
				esc_html( $label ),
				nl2br( esc_html( $value ) )
			);
		}

		return $html . '</table>';
	}

	/**
	 * Wrap email content in a simple, client-safe layout.
	 *
	 * @param string $title Heading.
	 * @param string $body  Escaped HTML.
	 */
	private static function wrap( string $title, string $body ): string {
		return '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.5;color:#111827;max-width:640px">'
			. '<h2 style="font-size:18px;margin:0 0 16px">' . esc_html( $title ) . '</h2>'
			. $body
			. '<p style="margin-top:32px;font-size:12px;color:#9ca3af">' . esc_html__( 'Sent by All in One AI Chatbot', 'all-in-one-ai-chatbot' ) . '</p></div>';
	}

	// ── Sending (queue handlers) ───────────────────────────────────────────

	/**
	 * Send an email. Throws so the queue retries a failure.
	 *
	 * @param array<string, mixed> $job to, subject, html, reply_to.
	 * @throws \RuntimeException When wp_mail fails.
	 */
	public static function send_email( array $job ): void {
		$error   = '';
		$capture = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( ! empty( $job['reply_to'] ) && is_email( (string) $job['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $job['reply_to'];
		}

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( (string) $job['to'], (string) $job['subject'], (string) $job['html'], $headers );
		remove_action( 'wp_mail_failed', $capture );

		if ( ! $sent ) {
			throw new \RuntimeException( 'Email could not be sent' . ( '' !== $error ? ': ' . $error : '. Check the site can send mail (an SMTP plugin usually fixes this).' ) );
		}

		Log::info( 'email', 'Sent: ' . $job['subject'], array( 'to' => $job['to'] ) );
	}

	/**
	 * Send a Telegram message. Throws so the queue retries a failure.
	 *
	 * @param array<string, mixed> $job text.
	 * @throws \RuntimeException On API failure.
	 */
	public static function send_telegram( array $job ): void {
		if ( ! self::telegram_ready() ) {
			throw new \RuntimeException( 'Telegram is not configured.' );
		}

		self::telegram_api(
			'sendMessage',
			array(
				'chat_id'                  => trim( (string) Settings::get( 'telegram_chat_id', '' ) ),
				'text'                     => (string) $job['text'],
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => true,
			)
		);

		Log::info( 'telegram', 'Message sent' );
	}

	/**
	 * Call the Telegram Bot API.
	 *
	 * @param string               $method API method.
	 * @param array<string, mixed> $params Parameters.
	 * @param string               $token  Token override (for testing an unsaved one).
	 * @return array<string, mixed> The "result" field.
	 * @throws \RuntimeException On failure.
	 */
	public static function telegram_api( string $method, array $params = array(), string $token = '' ): array {
		$token = '' !== $token ? $token : Settings::api_key( 'telegram' );

		$response = wp_remote_post(
			'https://api.telegram.org/bot' . rawurlencode( $token ) . '/' . $method,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $params ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Telegram unreachable: ' . $response->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			throw new \RuntimeException( 'Telegram: ' . ( is_array( $body ) && isset( $body['description'] ) ? $body['description'] : 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) );
		}

		return is_array( $body['result'] ?? null ) ? $body['result'] : array();
	}
}
