<?php
/**
 * WordPress personal data export and erasure.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Leads;

use Softorio\AiAssistant\Chat\ConversationStore;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs leads, and the chats linked to them, into Tools → Export / Erase
 * Personal Data, so a GDPR request is handled from the normal WordPress
 * screens.
 */
final class PrivacyTools {

	/**
	 * Register exporter and eraser.
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Add the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['all-in-one-ai-chatbot'] = array(
			'exporter_friendly_name' => __( 'AI Chatbot leads and chats', 'all-in-one-ai-chatbot' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['all-in-one-ai-chatbot'] = array(
			'eraser_friendly_name' => __( 'AI Chatbot leads and chats', 'all-in-one-ai-chatbot' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export everything held about an email address.
	 *
	 * @param string $email Email address.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email ): array {
		$items         = array();
		$conversations = new ConversationStore();

		foreach ( ( new LeadStore() )->by_email( $email ) as $lead ) {
			$data = array(
				array(
					'name'  => __( 'Name', 'all-in-one-ai-chatbot' ),
					'value' => $lead['name'],
				),
				array(
					'name'  => __( 'Email', 'all-in-one-ai-chatbot' ),
					'value' => $lead['email'],
				),
				array(
					'name'  => __( 'Phone', 'all-in-one-ai-chatbot' ),
					'value' => $lead['phone'],
				),
				array(
					'name'  => __( 'Message', 'all-in-one-ai-chatbot' ),
					'value' => (string) $lead['message'],
				),
				array(
					'name'  => __( 'Consent given', 'all-in-one-ai-chatbot' ),
					'value' => $lead['consent'] ? (string) $lead['consent_text'] : __( 'No', 'all-in-one-ai-chatbot' ),
				),
				array(
					'name'  => __( 'Date', 'all-in-one-ai-chatbot' ),
					'value' => $lead['created_at'] . ' UTC',
				),
			);

			if ( (int) $lead['conversation_id'] > 0 ) {
				$lines = array();

				foreach ( $conversations->transcript( (int) $lead['conversation_id'], 500 ) as $message ) {
					$lines[] = ( 'user' === $message['role'] ? 'You' : 'Assistant' ) . ': ' . $message['content'];
				}

				$data[] = array(
					'name'  => __( 'Chat', 'all-in-one-ai-chatbot' ),
					'value' => implode( "\n\n", $lines ),
				);
			}

			$items[] = array(
				'group_id'    => 'all-in-one-ai-chatbot',
				'group_label' => __( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
				'item_id'     => 'lead-' . $lead['id'],
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase leads for an email address and the chats linked to them.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: int, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email ): array {
		$leads         = new LeadStore();
		$conversations = new ConversationStore();
		$removed       = 0;

		foreach ( $leads->by_email( $email ) as $lead ) {
			if ( (int) $lead['conversation_id'] > 0 ) {
				$conversations->delete( (int) $lead['conversation_id'] );
			}

			$leads->delete( (int) $lead['id'] );
			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
