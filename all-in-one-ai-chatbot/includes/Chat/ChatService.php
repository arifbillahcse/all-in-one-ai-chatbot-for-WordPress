<?php
/**
 * The chat pipeline.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\Llm\Router;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\RateLimiter;
use Softorio\AiAssistant\Support\Visitor;

defined( 'ABSPATH' ) || exit;

/**
 * Question in, grounded answer out.
 *
 * Order of work, cheapest refusal first:
 *
 *  1. Validate the message.
 *  2. Per-visitor rate limit, then the site-wide daily cap and budget — all
 *     before any paid call, so abuse is refused for the price of a DB query.
 *  3. Resume or start the conversation and load recent turns.
 *  4. Retrieve passages, build the prompt, call the model.
 *  5. Save both turns only once there is an answer, so a failed call never
 *     leaves an orphaned question in the history.
 */
final class ChatService {

	/**
	 * Constructor.
	 *
	 * @param ConversationStore|null $store     Conversation storage.
	 * @param Retriever|null         $retriever Knowledge search.
	 * @param Router|null            $router    Provider router.
	 */
	public function __construct(
		private readonly ?ConversationStore $store = null,
		private readonly ?Retriever $retriever = null,
		private readonly ?Router $router = null,
	) {
	}

	/**
	 * Answer a visitor message.
	 *
	 * @param string $message         Raw message.
	 * @param string $conversation_id Conversation to continue, or ''.
	 * @param string $visitor_token   Widget's random token.
	 * @param string $page_url        Page the visitor is on.
	 * @return array{reply: string, conversation_id: string, sources: array<int, array{title: string, url: string}>}
	 * @throws ChatError When the message cannot be answered.
	 */
	public function ask( string $message, string $conversation_id, string $visitor_token, string $page_url ): array {
		if ( ! Settings::get( 'enabled', true ) || ! Settings::is_ready() ) {
			throw new ChatError( 'unavailable', __( 'The assistant is not available right now.', 'all-in-one-ai-chatbot' ), 503 );
		}

		$message = self::clean_message( $message, (int) Settings::get( 'max_message_length', 1000 ) );

		if ( '' === $message ) {
			throw new ChatError( 'empty', __( 'Please type a message.', 'all-in-one-ai-chatbot' ), 422 );
		}

		$this->enforce_limits();

		$store   = $this->store ?? new ConversationStore();
		$thread  = $store->resume_or_create( $conversation_id, $visitor_token, $page_url );
		$history = $thread['resumed'] ? $store->history( $thread['id'], (int) Settings::get( 'history_turns', 6 ) ) : array();

		// Short follow-ups ("and the price?") search badly on their own, so the
		// previous question is searched along with them.
		$query = $message;

		if ( mb_strlen( $message, 'UTF-8' ) < 60 ) {
			foreach ( array_reverse( $history ) as $turn ) {
				if ( 'user' === $turn['role'] ) {
					$query = $turn['content'] . ' ' . $message;
					break;
				}
			}
		}

		$passages = ( $this->retriever ?? new Retriever() )->search( $query );
		$system   = ( new PromptBuilder() )->build( $passages, $page_url );
		$messages = array_merge( $history, array( array( 'role' => 'user', 'content' => $message ) ) );

		try {
			$response = ( $this->router ?? new Router() )->complete(
				$system,
				$messages,
				max( 128, (int) Settings::get( 'max_tokens', 1024 ) )
			);
		} catch ( LlmException $e ) {
			throw new ChatError(
				'generation_failed',
				__( 'Sorry, I could not answer just now. Please try again in a moment.', 'all-in-one-ai-chatbot' ),
				503
			);
		}

		$sources = self::sources( $passages, $response->text );

		$store->add_exchange(
			$thread['id'],
			$message,
			$response->text,
			$sources,
			array(
				'provider'      => $response->provider,
				'model'         => $response->model,
				'input_tokens'  => $response->input_tokens,
				'output_tokens' => $response->output_tokens,
				'cost'          => $response->cost(),
			)
		);

		delete_transient( 'softorio_ai_usage_today' );

		/**
		 * Fires after the assistant answers.
		 *
		 * @param string $message  Visitor message.
		 * @param string $reply    Assistant reply.
		 * @param string $public_id Conversation id.
		 */
		do_action( 'softorio_ai_answered', $message, $response->text, $thread['public_id'] );

		return array(
			'reply'           => $response->text,
			'conversation_id' => $thread['public_id'],
			'sources'         => Settings::get( 'show_sources', true ) ? $sources : array(),
		);
	}

	/**
	 * Refuse the request if a visitor or the site is over its limits.
	 *
	 * @throws ChatError When over a limit.
	 */
	private function enforce_limits(): void {
		$hourly = RateLimiter::hit( Visitor::limit_key( Visitor::ip() ), (int) Settings::get( 'visitor_hourly_limit', 30 ), HOUR_IN_SECONDS );

		if ( ! $hourly['allowed'] ) {
			throw new ChatError(
				'rate_limited',
				__( 'You have sent a lot of messages. Please wait a little and try again.', 'all-in-one-ai-chatbot' ),
				429,
				$hourly['retry_after']
			);
		}

		// A short burst limit on top of the hourly one stops a script from
		// spending a whole hour's allowance in a second.
		$burst = RateLimiter::hit( Visitor::limit_key( Visitor::ip() ) . '|burst', 6, MINUTE_IN_SECONDS );

		if ( ! $burst['allowed'] ) {
			throw new ChatError(
				'rate_limited',
				__( 'You are sending messages too quickly. Please wait a moment.', 'all-in-one-ai-chatbot' ),
				429,
				$burst['retry_after']
			);
		}

		$usage  = self::usage_today();
		$cap    = (int) Settings::get( 'daily_message_cap', 500 );
		$budget = (float) Settings::get( 'daily_budget', 0 );

		if ( ( $cap > 0 && $usage['answers'] >= $cap ) || ( $budget > 0 && $usage['cost'] >= $budget ) ) {
			throw new ChatError(
				'daily_limit',
				__( 'The assistant has reached its limit for today. Please use the contact options, and we will get back to you.', 'all-in-one-ai-chatbot' ),
				429
			);
		}
	}

	/**
	 * Answers and cost since midnight (site time), cached briefly.
	 *
	 * @return array{answers: int, cost: float}
	 */
	public static function usage_today(): array {
		$cached = get_transient( 'softorio_ai_usage_today' );

		if ( is_array( $cached ) && ( $cached['day'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
			return $cached['usage'];
		}

		$midnight = new \DateTimeImmutable( 'today', wp_timezone() );
		$usage    = ( new ConversationStore() )->usage_since( $midnight->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );

		set_transient(
			'softorio_ai_usage_today',
			array(
				'day'   => wp_date( 'Y-m-d' ),
				'usage' => $usage,
			),
			5 * MINUTE_IN_SECONDS
		);

		return $usage;
	}

	/**
	 * Normalise a visitor message: valid UTF-8, no control characters, capped length.
	 *
	 * @param string $message Raw message.
	 * @param int    $max     Longest allowed, in characters.
	 */
	public static function clean_message( string $message, int $max ): string {
		$clean = mb_convert_encoding( $message, 'UTF-8', 'UTF-8' );
		$clean = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean );
		$clean = str_replace( array( "\r\n", "\r" ), "\n", $clean );
		$clean = (string) preg_replace( "/\n{3,}/", "\n\n", $clean );
		$clean = trim( $clean );

		return mb_substr( $clean, 0, max( 50, $max ), 'UTF-8' );
	}

	/**
	 * Links to show under the answer: pages with a URL, one per page.
	 *
	 * Pages the answer links to itself are listed first; the rest follow in
	 * relevance order. Knowledge Articles have no URL and are never listed.
	 *
	 * @param array<int, array{title: string, url: string}> $passages Retrieved passages.
	 * @param string                                        $answer   Model answer.
	 * @return array<int, array{title: string, url: string}>
	 */
	public static function sources( array $passages, string $answer ): array {
		$cited = array();
		$other = array();

		foreach ( $passages as $passage ) {
			$url = (string) $passage['url'];

			if ( '' === $url || isset( $cited[ $url ] ) || isset( $other[ $url ] ) ) {
				continue;
			}

			$entry = array(
				'title' => (string) $passage['title'],
				'url'   => $url,
			);

			if ( str_contains( $answer, $url ) ) {
				$cited[ $url ] = $entry;
			} else {
				$other[ $url ] = $entry;
			}
		}

		return array_slice( array_values( array_merge( $cited, $other ) ), 0, 3 );
	}
}
