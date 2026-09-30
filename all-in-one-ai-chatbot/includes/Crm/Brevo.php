<?php
/**
 * Brevo (formerly Sendinblue).
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates a Brevo contact and adds it to the chosen lists.
 * Brevo can reach contacts by SMS and WhatsApp too, so phone-only leads are
 * sent (as the SMS attribute, in international format).
 */
final class Brevo extends CrmClient {

	private const API = 'https://api.brevo.com/v3';

	public function id(): string {
		return 'brevo';
	}

	public function name(): string {
		return 'Brevo';
	}

	/**
	 * Auth header.
	 *
	 * @return array<string, string>
	 */
	private function auth(): array {
		return array( 'api-key' => $this->key() );
	}

	/**
	 * List ids from the setting ("2, 7").
	 *
	 * @return array<int, int>
	 */
	private static function lists(): array {
		return array_values( array_filter( array_map( 'intval', preg_split( '/[\s,;]+/', (string) Settings::get( 'brevo_lists', '' ) ) ?: array() ) ) );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed>             $lead       Lead row.
	 * @param array<int, array<string, mixed>> $transcript Chat so far.
	 */
	public function push( array $lead, array $transcript ): string {
		$email = strtolower( trim( (string) ( $lead['email'] ?? '' ) ) );
		$sms   = self::e164( (string) ( $lead['phone'] ?? '' ) );

		if ( '' === $email && '' === $sms ) {
			throw new CrmSkipped( __( 'no email or valid phone number', 'all-in-one-ai-chatbot' ) );
		}

		[ $first, $last ] = self::split_name( (string) ( $lead['name'] ?? '' ) );

		$attributes = array_filter(
			array(
				'FIRSTNAME' => $first,
				'LASTNAME'  => $last,
				'SMS'       => $sms,
			),
			static fn( string $v ): bool => '' !== $v
		);

		/** This filter is documented in includes/Crm/HubSpot.php */
		$attributes = (array) apply_filters( 'softorio_ai_crm_fields', $attributes, $lead, 'brevo' );

		$body = array_filter(
			array(
				'email'         => $email,
				'attributes'    => $attributes,
				'listIds'       => self::lists(),
				'updateEnabled' => true,
			),
			static fn( $v ): bool => '' !== $v && array() !== $v
		);

		try {
			$result = $this->request( 'POST', self::API . '/contacts', $body, $this->auth() );
		} catch ( CrmException $e ) {
			// A number Brevo will not accept, or one already on another
			// contact: keep the contact, drop the number.
			if ( 400 !== $e->getCode() || '' === $email || ! isset( $attributes['SMS'] ) || ! preg_match( '/sms|phone/i', $e->getMessage() ) ) {
				throw $e;
			}

			unset( $body['attributes']['SMS'] );
			$result = $this->request( 'POST', self::API . '/contacts', $body, $this->auth() );
		}

		return isset( $result['id'] ) ? (string) $result['id'] : ( '' !== $email ? $email : $sms );
	}

	/**
	 * {@inheritdoc}
	 */
	public function test(): string {
		if ( '' === $this->key() ) {
			throw new \RuntimeException( __( 'Save the API key first.', 'all-in-one-ai-chatbot' ) );
		}

		$account = $this->request( 'GET', self::API . '/account', array(), $this->auth() );
		$names   = array();

		foreach ( self::lists() as $id ) {
			try {
				$list    = $this->request( 'GET', self::API . '/contacts/lists/' . $id, array(), $this->auth() );
				$names[] = (string) ( $list['name'] ?? $id );
			} catch ( CrmException $e ) {
				/* translators: %d: list id */
				throw new \RuntimeException( sprintf( __( 'Connected, but list %d was not found in this Brevo account.', 'all-in-one-ai-chatbot' ), $id ), 0, $e );
			}
		}

		/* translators: 1: company name, 2: list names */
		return sprintf( __( 'Connected to %1$s. %2$s', 'all-in-one-ai-chatbot' ), (string) ( $account['companyName'] ?? $account['email'] ?? 'Brevo' ), array() !== $names ? sprintf( __( 'Leads go to: %s.', 'all-in-one-ai-chatbot' ), implode( ', ', $names ) ) : __( 'No list chosen: contacts are added without a list.', 'all-in-one-ai-chatbot' ) );
	}
}
