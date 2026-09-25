<?php
/**
 * The chat pipeline.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Chat;

use Softorio\AiAssistant\Knowledge\Audience;
use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\Llm\Router;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolRegistry;
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

	private const MAX_TOOL_ROUNDS = 4;

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
		private readonly ?ToolRegistry $tools = null,
	) {
	}

	/**
	 * Answer a visitor message.
	 *
	 * @param string $message         Raw message.
	 * @param string $conversation_id Conversation to continue, or ''.
	 * @param string $visitor_token   Widget's random token.
	 * @param string $page_url        Page the visitor is on.
	 * @param callable(string, mixed): void|null $on_event Streaming: receives ("delta", text), ("tool", name) and ("reset", null) as the answer is produced.
	 * @return array{reply: string, conversation_id: string, sources: array<int, array{title: string, url: string}>, unanswered: bool, offer_lead: bool}
	 * @throws ChatError When the message cannot be answered.
	 */
	public function ask( string $message, string $conversation_id, string $visitor_token, string $page_url, ?callable $on_event = null ): array {
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

		$context  = new ToolContext( get_current_user_id(), Visitor::ip(), $thread['id'], $page_url );
		$registry = $this->tools ?? new ToolRegistry();
		$tools    = $registry->definitions( $context );

		$passages = ( $this->retriever ?? new Retriever() )->search( $query, null, Audience::for_user( $context->user_id ) );
		$system   = ( new PromptBuilder() )->build( $passages, $page_url, array_map( static fn( $t ): string => $t->name, $tools ), $context );
		$messages = array_merge( $history, array( array( 'role' => 'user', 'content' => $message ) ) );

		try {
			$outcome  = $this->generate( $system, $messages, $tools, $registry, $context, $on_event );
			$response = $outcome['response'];
		} catch ( LlmException $e ) {
			throw new ChatError(
				'generation_failed',
				__( 'Sorry, I could not answer just now. Please try again in a moment.', 'all-in-one-ai-chatbot' ),
				503
			);
		}

		$parsed     = PromptBuilder::extract_no_answer( $response->text );
		$reply      = '' !== $parsed['text'] ? $parsed['text'] : __( 'Sorry, I do not have that information.', 'all-in-one-ai-chatbot' );
		$unanswered = $parsed['unanswered'];

		// Pages the model could not use to answer are not "related", and an
		// answer built from live tool data (an order, products) has its own
		// cards; page links would only distract.
		$sources = $unanswered || $outcome['used_tools'] ? array() : self::sources( $passages, $reply );
		$cards   = self::relevant_cards( $outcome['cards'], $reply );

		$answer_id = $store->add_exchange(
			$thread['id'],
			$message,
			$reply,
			$sources,
			array(
				'provider'      => $response->provider,
				'model'         => $response->model,
				'input_tokens'  => $outcome['input_tokens'],
				'output_tokens' => $outcome['output_tokens'],
				'cost'          => $outcome['cost'],
				'unanswered'    => $unanswered,
				'cards'         => $cards,
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
		do_action( 'softorio_ai_answered', $message, $reply, $thread['public_id'] );

		$event = array(
			'conversation_id' => $thread['public_id'],
			'question'        => $message,
			'answer'          => $reply,
			'page_url'        => $page_url,
			'provider'        => $response->provider,
			'model'           => $response->model,
		);

		Events::emit( Events::MESSAGE_ANSWERED, $event );

		if ( $unanswered ) {
			Events::emit( Events::QUESTION_UNANSWERED, $event );
		}

		$conversation = $store->find( $thread['id'] );

		return array(
			'reply'           => $reply,
			'conversation_id' => $thread['public_id'],
			'sources'         => Settings::get( 'show_sources', true ) ? $sources : array(),
			'cards'           => $cards,
			'message_id'      => $answer_id,
			'unanswered'      => $unanswered,
			// Offer the lead form once per conversation, when it would help.
			'offer_lead'      => $unanswered
				&& 'fallback' === Settings::get( 'leads_mode', 'off' )
				&& 0 === (int) ( $conversation['lead_id'] ?? 0 ),
		);
	}

	/**
	 * Call the model, running the tools it asks for, until it answers.
	 *
	 * Each round is a paid request, so the number of rounds is capped. If the
	 * model still wants tools at the cap, its text (or an honest "could not
	 * finish") is used rather than looping on.
	 *
	 * @param string                                  $system   System prompt.
	 * @param array<int, array<string, mixed>>        $messages Conversation so far.
	 * @param array<int, \Softorio\AiAssistant\Llm\ToolDefinition> $tools Offered tools.
	 * @param ToolRegistry                            $registry Tool runner.
	 * @param ToolContext                             $context  Who is asking.
	 * @return array{response: \Softorio\AiAssistant\Llm\LlmResponse, cost: float, input_tokens: int, output_tokens: int, cards: array<int, array<string, mixed>>, used_tools: bool}
	 * @throws LlmException When the provider fails.
	 */
	private function generate( string $system, array $messages, array $tools, ToolRegistry $registry, ToolContext $context, ?callable $on_event = null ): array {
		$router     = $this->router ?? new Router();
		$max_tokens = max( 128, (int) Settings::get( 'max_tokens', 1024 ) );
		$cost       = 0.0;
		$in         = 0;
		$out        = 0;
		$cards      = array();
		$used_tools = false;

		for ( $round = 1; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			if ( null === $on_event ) {
				$response = $router->complete( $system, $messages, $max_tokens, $tools );
			} else {
				// Each round's text streams through its own marker filter;
				// "reset" tells the widget a new round is starting, so text
				// from a round that ended in a tool call is replaced.
				if ( $round > 1 ) {
					$on_event( 'reset', null );
				}

				$filter   = new MarkerFilter( static fn( string $text ) => $on_event( 'delta', $text ) );
				$response = $router->stream( $system, $messages, $max_tokens, $tools, array( $filter, 'push' ) );
				$filter->finish();
			}
			$cost    += $response->cost();
			$in      += $response->input_tokens;
			$out     += $response->output_tokens;

			if ( ! $response->wants_tools() || self::MAX_TOOL_ROUNDS === $round ) {
				if ( $response->wants_tools() && '' === $response->text ) {
					$response = new \Softorio\AiAssistant\Llm\LlmResponse(
						__( 'Sorry, I could not finish looking that up. Please try asking in a different way, or contact us.', 'all-in-one-ai-chatbot' ),
						$response->provider,
						$response->model
					);
				}

				break;
			}

			// The tool_use blocks must be echoed back before their results,
			// or the provider rejects the next turn.
			$assistant = array();

			if ( '' !== $response->text ) {
				$assistant[] = array(
					'type' => 'text',
					'text' => $response->text,
				);
			}

			$results    = array();
			$used_tools = true;

			foreach ( $response->tool_calls as $call ) {
				if ( null !== $on_event ) {
					$on_event( 'tool', $call->name );
				}

				$assistant[] = array(
					'type'  => 'tool_use',
					'id'    => $call->id,
					'name'  => $call->name,
					'input' => (object) $call->arguments,
				);

				$result = $registry->execute( $call, $context );

				foreach ( $result->cards as $card ) {
					$cards[ (string) ( $card['key'] ?? count( $cards ) ) ] = $card;
				}

				$results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $call->id,
					'content'     => $result->content(),
					'is_error'    => $result->is_error,
				);
			}

			$messages[] = array(
				'role'    => 'assistant',
				'content' => $assistant,
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => $results,
			);
		}

		return array(
			'response'      => $response,
			'cost'          => $cost,
			'input_tokens'  => $in,
			'output_tokens' => $out,
			'cards'         => array_slice( array_values( $cards ), 0, 6 ),
			'used_tools'    => $used_tools,
		);
	}

	/**
	 * Keep the product cards for products the answer actually talks about.
	 *
	 * A search may return five products while the reply recommends two;
	 * showing all five would contradict the answer. When the reply names
	 * none of them (a generic "here are some options"), all are kept.
	 * Non-product cards (orders) are always kept.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards from tools.
	 * @param string                           $reply Final answer.
	 * @return array<int, array<string, mixed>>
	 */
	public static function relevant_cards( array $cards, string $reply ): array {
		$reply     = mb_strtolower( $reply );
		$products  = array_filter( $cards, static fn( array $c ): bool => 'product' === ( $c['type'] ?? '' ) );
		$mentioned = array_filter(
			$products,
			static fn( array $c ): bool => '' !== (string) ( $c['name'] ?? '' ) && str_contains( $reply, mb_strtolower( (string) $c['name'] ) )
		);

		if ( array() === $mentioned ) {
			return $cards;
		}

		return array_values(
			array_filter(
				$cards,
				static fn( array $c ): bool => 'product' !== ( $c['type'] ?? '' ) || in_array( $c, $mentioned, true )
			)
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
