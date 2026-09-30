<?php
/**
 * Internal events.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Named things that happen in a conversation, for integrations to react to.
 *
 * The chat code only announces what happened ("a lead was captured"); it
 * never knows who is listening. Email, Telegram, webhooks and CRMs each
 * subscribe on their own, so adding an integration never means touching the
 * chat pipeline — and a broken integration cannot break a chat.
 *
 * Listeners should be quick: anything slow belongs in a Queue job.
 */
final class Events {

	public const LEAD_CREATED        = 'lead.created';
	public const HANDOFF_REQUESTED   = 'handoff.requested';
	public const QUESTION_UNANSWERED = 'question.unanswered';
	public const CONVERSATION_ENDED  = 'conversation.ended';
	public const MESSAGE_ANSWERED    = 'message.answered';
	public const ANSWER_RATED        = 'answer.rated';
	public const LIVE_REQUESTED      = 'live.requested';
	public const LIVE_STARTED        = 'live.started';
	public const LIVE_ENDED          = 'live.ended';

	/**
	 * Every event name, with a label for admin screens (webhook pickers etc.).
	 *
	 * @return array<string, string>
	 */
	public static function catalogue(): array {
		return array(
			self::LEAD_CREATED        => __( 'New lead captured', 'all-in-one-ai-chatbot' ),
			self::HANDOFF_REQUESTED   => __( 'Visitor asked for a person', 'all-in-one-ai-chatbot' ),
			self::QUESTION_UNANSWERED => __( 'Question the assistant could not answer', 'all-in-one-ai-chatbot' ),
			self::CONVERSATION_ENDED  => __( 'Conversation ended', 'all-in-one-ai-chatbot' ),
			self::MESSAGE_ANSWERED    => __( 'Every answered message', 'all-in-one-ai-chatbot' ),
			self::ANSWER_RATED        => __( 'Visitor rated an answer (👍 / 👎)', 'all-in-one-ai-chatbot' ),
			self::LIVE_REQUESTED      => __( 'Visitor is waiting for a live chat', 'all-in-one-ai-chatbot' ),
			self::LIVE_STARTED        => __( 'An agent joined a live chat', 'all-in-one-ai-chatbot' ),
			self::LIVE_ENDED          => __( 'A live chat went back to the AI', 'all-in-one-ai-chatbot' ),
		);
	}

	/**
	 * Subscribe to an event.
	 *
	 * @param string                                  $event    Event name, or '*' for all.
	 * @param callable(array<string, mixed>, string): void $listener Receives payload and event name.
	 */
	public static function listen( string $event, callable $listener ): void {
		add_action(
			'softorio_ai_event',
			static function ( string $name, array $payload ) use ( $event, $listener ): void {
				if ( '*' !== $event && $name !== $event ) {
					return;
				}

				// Isolated per listener, so one failing integration does not
				// stop the others from hearing about the event.
				try {
					$listener( $payload, $name );
				} catch ( \Throwable $e ) {
					Log::error( 'events', sprintf( 'A listener for %s failed', $name ), array( 'error' => $e->getMessage() ) );
				}
			},
			10,
			2
		);
	}

	/**
	 * Announce an event.
	 *
	 * A listener that throws is logged and skipped (see listen()); the others
	 * still run and the visitor's request carries on.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $payload Details.
	 */
	public static function emit( string $event, array $payload ): void {
		$payload['event']       = $event;
		$payload['occurred_at'] = gmdate( 'c' );
		$payload['site']        = home_url( '/' );

		try {
			/**
			 * Fires for every plugin event.
			 *
			 * @param string $event   Event name, e.g. "lead.created".
			 * @param array  $payload Event details.
			 */
			do_action( 'softorio_ai_event', $event, $payload );
		} catch ( \Throwable $e ) {
			Log::error( 'events', sprintf( 'A listener for %s failed', $event ), array( 'error' => $e->getMessage() ) );
		}
	}
}
