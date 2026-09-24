<?php
/**
 * Capturing a lead from the widget.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Leads;

use Softorio\AiAssistant\Admin\Menu;
use Softorio\AiAssistant\Chat\ChatError;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Chat\Transcript;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\RateLimiter;
use Softorio\AiAssistant\Support\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a lead form submission against the owner's settings and saves it.
 *
 * The form's rules (which fields, which required, consent) are enforced here,
 * not only in the widget: the widget is JavaScript in a stranger's browser,
 * and a lead list full of empty or fake rows is worse than none.
 */
final class LeadService {

	public const SOURCES = array( 'pre_chat', 'fallback', 'handoff' );

	/**
	 * Whether lead capture is switched on.
	 */
	public static function enabled(): bool {
		return 'off' !== Settings::get( 'leads_mode', 'off' );
	}

	/**
	 * The form definition the widget renders.
	 *
	 * @return array<string, mixed>
	 */
	public static function form_config(): array {
		$fields = array();

		foreach ( array( 'name', 'email', 'phone' ) as $field ) {
			$fields[ $field ] = (string) Settings::get( 'lead_' . $field, 'optional' );
		}

		return array(
			'mode'       => (string) Settings::get( 'leads_mode', 'off' ),
			'fields'     => $fields,
			'title'      => (string) Settings::get( 'lead_title', '' ),
			'intro'      => (string) Settings::get( 'lead_intro', '' ),
			'thanks'     => (string) Settings::get( 'lead_thanks', '' ),
			'consent'    => (bool) Settings::get( 'consent_required', false ) ? self::consent_text() : '',
			'privacyUrl' => (string) get_privacy_policy_url(),
		);
	}

	/**
	 * The consent sentence as shown to visitors.
	 */
	public static function consent_text(): string {
		return (string) Settings::get( 'consent_text', '' );
	}

	/**
	 * Validate and save a submission.
	 *
	 * @param array<string, mixed> $input         Submitted fields.
	 * @param string               $visitor_token Widget's random token.
	 * @return array{lead_id: int, message: string}
	 * @throws ChatError When invalid or not allowed.
	 */
	public function submit( array $input, string $visitor_token ): array {
		if ( ! self::enabled() ) {
			throw new ChatError( 'disabled', __( 'This form is not available.', 'all-in-one-ai-chatbot' ), 404 );
		}

		$limit = RateLimiter::hit( Visitor::limit_key( Visitor::ip() ) . '|lead', 10, HOUR_IN_SECONDS );

		if ( ! $limit['allowed'] ) {
			throw new ChatError( 'rate_limited', __( 'Too many submissions. Please try again later.', 'all-in-one-ai-chatbot' ), 429, $limit['retry_after'] );
		}

		$data = $this->validate( $input );

		// Link the conversation only when this browser owns it.
		$store        = new ConversationStore();
		$conversation = $store->find_owned( (string) ( $input['conversation_id'] ?? '' ), $visitor_token );

		$data['conversation_id'] = null !== $conversation ? (int) $conversation['id'] : 0;
		$data['user_id']         = get_current_user_id();

		$lead_id = ( new LeadStore() )->create( $data );

		if ( null !== $conversation ) {
			$store->set_lead( (int) $conversation['id'], $lead_id );
		}

		$payload = self::payload( $lead_id, null !== $conversation ? (string) $conversation['public_id'] : '' );

		Events::emit( Events::LEAD_CREATED, $payload );

		if ( 'handoff' === $data['source'] ) {
			Events::emit( Events::HANDOFF_REQUESTED, $payload );
		}

		return array(
			'lead_id' => $lead_id,
			'message' => (string) Settings::get( 'lead_thanks', '' ),
		);
	}

	/**
	 * Apply the owner's field rules.
	 *
	 * @param array<string, mixed> $input Submitted fields.
	 * @return array<string, mixed>
	 * @throws ChatError On invalid input.
	 */
	private function validate( array $input ): array {
		$rules  = self::form_config()['fields'];
		$errors = array();

		$name    = mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 120 );
		$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$raw_tel = (string) ( $input['phone'] ?? '' );
		$phone   = mb_substr( (string) preg_replace( '/[^0-9+\-\s()]/', '', $raw_tel ), 0, 30 );
		$message = mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 1000 );
		$source  = in_array( $input['source'] ?? '', self::SOURCES, true ) ? (string) $input['source'] : 'fallback';

		if ( 'hidden' === $rules['name'] ) {
			$name = '';
		}
		if ( 'hidden' === $rules['email'] ) {
			$email = '';
		}
		if ( 'hidden' === $rules['phone'] ) {
			$phone = '';
		}

		if ( 'required' === $rules['name'] && '' === $name ) {
			$errors['name'] = __( 'Please enter your name.', 'all-in-one-ai-chatbot' );
		}

		if ( '' !== trim( (string) ( $input['email'] ?? '' ) ) && '' === $email && 'hidden' !== $rules['email'] ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'all-in-one-ai-chatbot' );
		} elseif ( 'required' === $rules['email'] && '' === $email ) {
			$errors['email'] = __( 'Please enter your email address.', 'all-in-one-ai-chatbot' );
		}

		$digits = strlen( (string) preg_replace( '/\D/', '', $phone ) );

		if ( '' !== $phone && ( $digits < 6 || $digits > 16 ) ) {
			$errors['phone'] = __( 'Please enter a valid phone number.', 'all-in-one-ai-chatbot' );
		} elseif ( 'required' === $rules['phone'] && '' === $phone ) {
			$errors['phone'] = __( 'Please enter your phone number.', 'all-in-one-ai-chatbot' );
		}

		// A lead nobody can contact is not a lead.
		if ( '' === $email && '' === $phone && ! isset( $errors['email'] ) && ! isset( $errors['phone'] ) ) {
			$errors[ 'hidden' !== $rules['email'] ? 'email' : 'phone' ] = __( 'Please leave an email address or phone number so we can reach you.', 'all-in-one-ai-chatbot' );
		}

		$consent_required = (bool) Settings::get( 'consent_required', false );
		$consent          = ! empty( $input['consent'] );

		if ( $consent_required && ! $consent ) {
			$errors['consent'] = __( 'Please tick the box to agree.', 'all-in-one-ai-chatbot' );
		}

		if ( array() !== $errors ) {
			$error         = new ChatError( 'invalid', (string) reset( $errors ), 422 );
			$error->fields = $errors;

			throw $error;
		}

		$page_url = esc_url_raw( (string) ( $input['page_url'] ?? '' ) );
		$home     = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' !== $page_url && wp_parse_url( $page_url, PHP_URL_HOST ) !== $home ) {
			$page_url = '';
		}

		return array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'message'      => $message,
			'source'       => $source,
			'consent'      => $consent_required ? $consent : false,
			'consent_text' => $consent_required ? self::consent_text() : '',
			'page_url'     => mb_substr( $page_url, 0, 500 ),
		);
	}

	/**
	 * Event payload for a lead: the lead, where it came from, and the chat so far.
	 *
	 * @param int    $lead_id         Lead id.
	 * @param string $conversation_id Public conversation id, or ''.
	 * @return array<string, mixed>
	 */
	public static function payload( int $lead_id, string $conversation_id ): array {
		$lead = ( new LeadStore() )->find( $lead_id ) ?? array();

		return array(
			'lead'            => array(
				'id'         => $lead_id,
				'name'       => (string) ( $lead['name'] ?? '' ),
				'email'      => (string) ( $lead['email'] ?? '' ),
				'phone'      => (string) ( $lead['phone'] ?? '' ),
				'message'    => (string) ( $lead['message'] ?? '' ),
				'source'     => (string) ( $lead['source'] ?? '' ),
				'consent'    => (bool) ( $lead['consent'] ?? false ),
				'page_url'   => (string) ( $lead['page_url'] ?? '' ),
				'created_at' => (string) ( $lead['created_at'] ?? '' ),
			),
			'conversation_id' => $conversation_id,
			'transcript'      => Transcript::messages( (int) ( $lead['conversation_id'] ?? 0 ), 30 ),
			'admin_url'       => Menu::url( 'leads', array( 's' => (string) ( $lead['email'] ?? '' ) ) ),
		);
	}
}
