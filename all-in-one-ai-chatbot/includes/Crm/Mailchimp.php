<?php
/**
 * Mailchimp.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

use Softorio\AiAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds or updates an audience member (by email), with tags and a note.
 *
 * New members get the status chosen in the settings. "Pending" (double
 * opt-in: Mailchimp emails them to confirm) is the default because a chat
 * lead has not necessarily agreed to marketing emails.
 */
final class Mailchimp extends CrmClient {

	public const STATUSES = array( 'pending', 'subscribed', 'transactional' );

	public function id(): string {
		return 'mailchimp';
	}

	public function name(): string {
		return 'Mailchimp';
	}

	/**
	 * API base for the key's data centre (the part after the dash).
	 *
	 * @throws CrmException When the key has no data centre.
	 */
	private function api(): string {
		if ( ! preg_match( '/-([a-z]+\d+)$/', $this->key(), $m ) ) {
			throw new CrmException( __( 'Mailchimp: the API key should end with its data centre, like "-us21". Copy the whole key from Mailchimp.', 'all-in-one-ai-chatbot' ) );
		}

		return 'https://' . $m[1] . '.api.mailchimp.com/3.0';
	}

	/**
	 * Auth header.
	 *
	 * @return array<string, string>
	 */
	private function auth(): array {
		return array( 'Authorization' => 'Basic ' . base64_encode( 'softorio:' . $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array<string, mixed>             $lead       Lead row.
	 * @param array<int, array<string, mixed>> $transcript Chat so far.
	 */
	public function push( array $lead, array $transcript ): string {
		$email = strtolower( trim( (string) ( $lead['email'] ?? '' ) ) );
		$list  = trim( (string) Settings::get( 'mailchimp_list', '' ) );

		if ( '' === $email ) {
			throw new CrmSkipped( __( 'no email address', 'all-in-one-ai-chatbot' ) );
		}

		if ( '' === $list ) {
			throw new CrmException( __( 'Mailchimp: choose an audience in Settings → Integrations (press "Test connection" to list them).', 'all-in-one-ai-chatbot' ) );
		}

		$status = (string) Settings::get( 'mailchimp_status', 'pending' );
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'pending';

		[ $first, $last ] = self::split_name( (string) ( $lead['name'] ?? '' ) );

		$merge = array_filter(
			array(
				'FNAME' => $first,
				'LNAME' => $last,
				'PHONE' => (string) ( $lead['phone'] ?? '' ),
			),
			static fn( string $v ): bool => '' !== $v
		);

		/** This filter is documented in includes/Crm/HubSpot.php */
		$merge = (array) apply_filters( 'softorio_ai_crm_fields', $merge, $lead, 'mailchimp' );

		$member = $this->api() . '/lists/' . rawurlencode( $list ) . '/members/' . md5( $email );
		$body   = array(
			'email_address' => $email,
			'status_if_new' => $status,
		);

		try {
			$this->request( 'PUT', $member, $body + ( array() !== $merge ? array( 'merge_fields' => $merge ) : array() ), $this->auth() );
		} catch ( CrmException $e ) {
			// Audiences without a PHONE (or other) merge field reject the
			// whole request; the contact matters more than the extra field.
			if ( 400 !== $e->getCode() || array() === $merge || ! str_contains( $e->getMessage(), 'merge' ) ) {
				throw $e;
			}

			$this->request( 'PUT', $member, $body, $this->auth() );
		}

		$tags = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'mailchimp_tags', 'AI Chatbot' ) ) ) );

		if ( array() !== $tags ) {
			$this->request(
				'POST',
				$member . '/tags',
				array(
					'tags' => array_map(
						static fn( string $t ): array => array(
							'name'   => mb_substr( $t, 0, 100 ),
							'status' => 'active',
						),
						array_values( $tags )
					),
				),
				$this->auth()
			);
		}

		if ( Settings::get( 'mailchimp_note', true ) ) {
			$this->request( 'POST', $member . '/notes', array( 'note' => mb_substr( self::summary( $lead, $transcript ), 0, 1000 ) ), $this->auth() );
		}

		return $email;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Also fills in the audience when the account has exactly one.
	 */
	public function test(): string {
		if ( '' === $this->key() ) {
			throw new \RuntimeException( __( 'Save the API key first.', 'all-in-one-ai-chatbot' ) );
		}

		$result = $this->request( 'GET', $this->api() . '/lists?count=100&fields=lists.id,lists.name', array(), $this->auth() );
		$lists  = array_map(
			static fn( array $l ): array => array( (string) $l['id'], (string) $l['name'] ),
			array_filter( (array) ( $result['lists'] ?? array() ), 'is_array' )
		);

		if ( array() === $lists ) {
			throw new \RuntimeException( __( 'Connected, but this Mailchimp account has no audiences yet. Create one in Mailchimp first.', 'all-in-one-ai-chatbot' ) );
		}

		$current = trim( (string) Settings::get( 'mailchimp_list', '' ) );
		$names   = implode( ', ', array_map( static fn( array $l ): string => $l[1] . ' (' . $l[0] . ')', $lists ) );

		if ( '' === $current && 1 === count( $lists ) ) {
			$settings                   = Settings::all();
			$settings['mailchimp_list'] = $lists[0][0];
			update_option( Settings::OPTION, $settings );

			/* translators: %s: audience name */
			return sprintf( __( 'Connected. Using your audience "%s" (saved).', 'all-in-one-ai-chatbot' ), $lists[0][1] );
		}

		foreach ( $lists as [ $id, $name ] ) {
			if ( $id === $current ) {
				/* translators: %s: audience name */
				return sprintf( __( 'Connected. Leads go to the audience "%s".', 'all-in-one-ai-chatbot' ), $name );
			}
		}

		/* translators: %s: list of audiences with their ids */
		throw new \RuntimeException( sprintf( __( 'Connected. Enter one of your audience IDs above and save: %s', 'all-in-one-ai-chatbot' ), $names ) );
	}
}
