<?php
/**
 * HubSpot.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates a HubSpot contact (matched by email) and attaches the
 * chat as a note on it. Uses a Private App access token with the
 * crm.objects.contacts.read/write scopes.
 */
final class HubSpot extends CrmClient {

	private const API = 'https://api.hubapi.com';

	/** Optional properties dropped if a portal rejects them. */
	private const OPTIONAL = array( 'message', 'phone', 'lifecyclestage' );

	public function id(): string {
		return 'hubspot';
	}

	public function name(): string {
		return 'HubSpot';
	}

	/**
	 * Auth header.
	 *
	 * @return array<string, string>
	 */
	private function auth(): array {
		return array( 'Authorization' => 'Bearer ' . $this->key() );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed>             $lead       Lead row.
	 * @param array<int, array<string, mixed>> $transcript Chat so far.
	 */
	public function push( array $lead, array $transcript ): string {
		$email = (string) ( $lead['email'] ?? '' );
		$phone = (string) ( $lead['phone'] ?? '' );

		if ( '' === $email && '' === $phone ) {
			throw new CrmSkipped( __( 'no email or phone', 'all-in-one-ai-chatbot' ) );
		}

		[ $first, $last ] = self::split_name( (string) ( $lead['name'] ?? '' ) );

		$properties = array_filter(
			array(
				'email'          => $email,
				'firstname'      => $first,
				'lastname'       => $last,
				'phone'          => '' !== self::e164( $phone ) ? self::e164( $phone ) : $phone,
				'message'        => mb_substr( (string) ( $lead['message'] ?? '' ), 0, 5000 ),
				'lifecyclestage' => (string) Settings::get( 'hubspot_lifecycle', 'lead' ),
			),
			static fn( string $v ): bool => '' !== $v
		);

		/**
		 * Filter the HubSpot contact properties sent for a lead.
		 *
		 * @param array<string, string> $properties Properties.
		 * @param array<string, mixed>  $lead       Lead.
		 */
		$properties = (array) apply_filters( 'softorio_ai_crm_fields', $properties, $lead, 'hubspot' );

		$id = $this->upsert( $properties );

		if ( Settings::get( 'hubspot_note', true ) ) {
			$this->note( $id, self::summary( $lead, $transcript ) );
		}

		return $id;
	}

	/**
	 * Create the contact, or update the one with that email.
	 *
	 * @param array<string, string> $properties Properties.
	 * @param bool                  $retry      Whether optional properties may still be dropped.
	 * @throws CrmException When HubSpot refuses the contact.
	 */
	private function upsert( array $properties, bool $retry = true ): string {
		try {
			$created = $this->request( 'POST', self::API . '/crm/v3/objects/contacts', array( 'properties' => $properties ), $this->auth() );

			return (string) ( $created['id'] ?? '' );
		} catch ( CrmException $e ) {
			$message = $e->getMessage();

			// Already there: HubSpot says which id. Update it, but never move
			// its lifecycle stage (a customer must not become a "lead" again).
			if ( 409 === $e->getCode() && preg_match( '/Existing ID:\s*(\d+)/i', $message, $m ) ) {
				unset( $properties['lifecyclestage'] );
				$this->request( 'PATCH', self::API . '/crm/v3/objects/contacts/' . $m[1], array( 'properties' => $properties ), $this->auth() );

				return $m[1];
			}

			// A portal without a property, or one that rejects a phone format.
			if ( $retry && 400 === $e->getCode() ) {
				$trimmed = array_diff_key( $properties, array_flip( self::OPTIONAL ) );

				if ( $trimmed !== $properties ) {
					return $this->upsert( $trimmed, false );
				}
			}

			throw $e;
		}
	}

	/**
	 * Attach a note to a contact.
	 *
	 * @param string $contact_id Contact.
	 * @param string $text       Note text.
	 */
	private function note( string $contact_id, string $text ): void {
		if ( '' === $contact_id ) {
			return;
		}

		$this->request(
			'POST',
			self::API . '/crm/v3/objects/notes',
			array(
				'properties'   => array(
					'hs_timestamp' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
					'hs_note_body' => nl2br( esc_html( $text ), false ),
				),
				'associations' => array(
					array(
						'to'    => array( 'id' => $contact_id ),
						'types' => array(
							array(
								'associationCategory' => 'HUBSPOT_DEFINED',
								'associationTypeId'   => 202,
							),
						),
					),
				),
			),
			$this->auth()
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function test(): string {
		if ( '' === $this->key() ) {
			throw new \RuntimeException( __( 'Save the access token first.', 'all-in-one-ai-chatbot' ) );
		}

		$this->request( 'GET', self::API . '/crm/v3/objects/contacts?limit=1', array(), $this->auth() );

		return __( 'Connected to HubSpot. New leads will be added as contacts.', 'all-in-one-ai-chatbot' );
	}
}
