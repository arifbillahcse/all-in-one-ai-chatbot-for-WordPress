<?php
/**
 * Leads to CRMs.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Crm;

use Softorio\AiAssistant\Chat\Transcript;
use Softorio\AiAssistant\Leads\LeadStore;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Sends every new lead to the CRMs the owner switched on.
 *
 * One queue job per lead and CRM, so a CRM that is down is retried on its
 * own without holding up the others (or the visitor). The job reads the
 * lead when it runs, and records the outcome on the lead, where the Leads
 * screen shows it: sent, skipped (and why), or failed (and why).
 */
final class CrmSync {

	public const JOB = 'crm.sync';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		Events::listen( Events::LEAD_CREATED, array( self::class, 'on_lead' ) );
		Queue::register( self::JOB, array( self::class, 'handle' ) );
	}

	/**
	 * Every connector, enabled or not.
	 *
	 * @return array<string, CrmClient>
	 */
	public static function clients(): array {
		$clients = array();

		/**
		 * Filter the CRM connectors (add your own by extending CrmClient).
		 *
		 * @param array<int, CrmClient> $list Connectors.
		 */
		foreach ( (array) apply_filters( 'softorio_ai_crm_clients', array( new HubSpot(), new Mailchimp(), new Brevo() ) ) as $client ) {
			if ( $client instanceof CrmClient ) {
				$clients[ $client->id() ] = $client;
			}
		}

		return $clients;
	}

	/**
	 * The switched-on connectors.
	 *
	 * @return array<string, CrmClient>
	 */
	public static function enabled(): array {
		return array_filter( self::clients(), static fn( CrmClient $c ): bool => $c->enabled() );
	}

	/**
	 * A lead was captured.
	 *
	 * @param array<string, mixed> $payload lead.created payload.
	 */
	public static function on_lead( array $payload ): void {
		$id = (int) ( $payload['lead']['id'] ?? 0 );

		if ( $id > 0 ) {
			self::queue( $id );
		}
	}

	/**
	 * Queue a lead for every enabled CRM (also the "Send again" button).
	 *
	 * @param int $lead_id Lead.
	 * @return int Jobs queued.
	 */
	public static function queue( int $lead_id ): int {
		$lead = ( new LeadStore() )->find( $lead_id );

		if ( null === $lead ) {
			return 0;
		}

		$queued = 0;

		foreach ( self::enabled() as $id => $client ) {
			// Marketing tools must not receive people who did not agree,
			// when the owner asks for consent.
			if ( Settings::get( 'crm_require_consent', false ) && empty( $lead['consent'] ) ) {
				self::record( $lead_id, $id, 'skipped', __( 'no consent', 'all-in-one-ai-chatbot' ) );
				continue;
			}

			self::record( $lead_id, $id, 'pending', '' );
			Queue::push(
				self::JOB,
				array(
					'crm'  => $id,
					'lead' => $lead_id,
				)
			);
			++$queued;
		}

		return $queued;
	}

	/**
	 * Queue handler.
	 *
	 * @param array<string, mixed> $job crm, lead.
	 * @throws \RuntimeException On a temporary failure, so the queue retries.
	 */
	public static function handle( array $job ): void {
		$id      = (string) ( $job['crm'] ?? '' );
		$lead_id = (int) ( $job['lead'] ?? 0 );
		$client  = self::clients()[ $id ] ?? null;
		$lead    = ( new LeadStore() )->find( $lead_id );

		// Deleted (e.g. a privacy erasure) or the CRM switched off since.
		if ( null === $lead || null === $client ) {
			return;
		}

		if ( ! $client->enabled() ) {
			self::record( $lead_id, $id, 'skipped', __( 'switched off', 'all-in-one-ai-chatbot' ) );
			return;
		}

		try {
			$ref = $client->push( $lead, Transcript::messages( (int) $lead['conversation_id'], 30 ) );
			self::record( $lead_id, $id, 'sent', '', $ref );
			Log::info( 'crm', 'Lead sent to ' . $client->name(), array( 'lead' => $lead_id ) );
		} catch ( CrmSkipped $e ) {
			self::record( $lead_id, $id, 'skipped', $e->getMessage() );
		} catch ( CrmException $e ) {
			// Retrying will not fix a wrong key or a refused contact.
			self::record( $lead_id, $id, 'failed', $e->getMessage() );
			Log::error( 'crm', $e->getMessage(), array( 'lead' => $lead_id ) );
		} catch ( \RuntimeException $e ) {
			self::record( $lead_id, $id, 'retrying', $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Store a CRM outcome on the lead.
	 *
	 * @param int    $lead_id Lead.
	 * @param string $crm     Connector id.
	 * @param string $status  pending|sent|skipped|failed|retrying.
	 * @param string $detail  Reason.
	 * @param string $ref     Id in the CRM.
	 */
	public static function record( int $lead_id, string $crm, string $status, string $detail, string $ref = '' ): void {
		( new LeadStore() )->set_crm(
			$lead_id,
			$crm,
			array(
				'status' => $status,
				'detail' => mb_substr( $detail, 0, 300 ),
				'ref'    => mb_substr( $ref, 0, 100 ),
				'at'     => time(),
			)
		);
	}

	/**
	 * Queue every lead not yet sent to all enabled CRMs (the "Send all"
	 * button, for leads captured before a CRM was connected).
	 *
	 * @param int $limit Most leads per click.
	 * @return int Leads queued.
	 */
	public static function backfill( int $limit = 500 ): int {
		$enabled = array_keys( self::enabled() );

		if ( array() === $enabled ) {
			return 0;
		}

		$count = 0;

		foreach ( ( new LeadStore() )->search( '', '', $limit, 0 )['rows'] as $row ) {
			$state = LeadStore::crm_state( $row );
			// Sent, or genuinely on its way (a "pending" mark whose job was
			// lost, e.g. a cleared queue, is sent again after an hour).
			$done = array_filter(
				$enabled,
				static fn( string $id ): bool => 'sent' === ( $state[ $id ]['status'] ?? '' )
					|| ( in_array( $state[ $id ]['status'] ?? '', array( 'pending', 'retrying' ), true ) && (int) ( $state[ $id ]['at'] ?? 0 ) > time() - HOUR_IN_SECONDS )
			);

			if ( count( $done ) < count( $enabled ) && self::queue( (int) $row['id'] ) > 0 ) {
				++$count;
			}
		}

		return $count;
	}
}
