<?php
// phpcs:disable
/**
 * Phase 3: Gemini and OpenRouter, streaming, feedback.
 */

use Softorio\AiAssistant\Chat\MarkerFilter;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Llm\LlmException;
use Softorio\AiAssistant\Llm\Router;
use Softorio\AiAssistant\Llm\SseParser;
use Softorio\AiAssistant\Llm\ToolDefinition;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Tools\Tool;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolResult;

sai_reset();

// ── Local streaming mock provider ────────────────────────────────────────────
$sai_mock_port = 8977;
$sai_mock      = proc_open(
	array( PHP_BINARY, '-S', '127.0.0.1:' . $sai_mock_port, __DIR__ . '/../mock/provider.php' ),
	array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
	$pipes
);
for ( $i = 0; $i < 50 && ! @fsockopen( '127.0.0.1', $sai_mock_port ); $i++ ) {
	usleep( 100000 );
}

$GLOBALS['sai_endpoint'] = null;
add_filter( 'softorio_ai_provider_endpoint', static fn( $url, $provider ) => $GLOBALS['sai_endpoint'][ $provider ] ?? $url, 10, 2 );

function sai_mock( string $provider, string $format, string $scenario ): void {
	$GLOBALS['sai_endpoint'] = array( $provider => "http://127.0.0.1:8977/$format?s=$scenario" );
}

function sai_mock_body(): array {
	return json_decode( (string) file_get_contents( sys_get_temp_dir() . '/aicb-mock-last.json' ), true ) ?: array();
}

final class MockLookupTool implements Tool {
	public static array $calls = array();
	public function name(): string { return 'mock_lookup'; }
	public function definition(): ToolDefinition { return new ToolDefinition( 'mock_lookup', 'Look something up.', array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string' ) ) ) ); }
	public function available( ToolContext $c ): bool { return true; }
	public function run( array $a, ToolContext $c ): ToolResult { self::$calls[] = $a; return new ToolResult( array( 'found' => 'headphones' ) ); }
}
$GLOBALS['sai_mock_tool'] = false;
add_filter( 'softorio_ai_tools', static function ( $tools ) {
	if ( $GLOBALS['sai_mock_tool'] ) {
		$tools[] = new MockLookupTool();
	}
	return $tools;
} );

/** Run the streaming endpoint and collect its events. */
function sai_stream( string $message, string $token = 'streamvisitor001', string $conversation = '', bool $keep_limits = false ): array {
	global $wpdb;
	if ( ! $keep_limits ) {
		// These tests send many messages a minute from one address; the
		// burst limit is tested on its own below.
		$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	}
	$events = array();
	$sink   = static function ( string $chunk ) use ( &$events ): void {
		$parser = new SseParser( static function ( string $event, string $data ) use ( &$events ): void {
			$events[] = array( $event, json_decode( $data, true ) );
		} );
		$parser->feed( $chunk );
		$parser->finish();
	};
	add_filter( 'softorio_ai_stream_sink', $f = static fn() => $sink );

	$request = new WP_REST_Request( 'POST', '/softorio-ai/v1/chat/stream' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'message' => $message, 'visitor_token' => $token, 'conversation_id' => $conversation ) ) );
	$status = rest_do_request( $request )->get_status();

	remove_filter( 'softorio_ai_stream_sink', $f );

	return array( 'status' => $status, 'events' => $events );
}

function sai_deltas( array $events ): string {
	return implode( '', array_map( fn( $e ) => $e[1]['t'], array_filter( $events, fn( $e ) => 'delta' === $e[0] ) ) );
}

// ── Pure units ───────────────────────────────────────────────────────────────

T::test( 'SSE parser reassembles events split across chunks', function () {
	$got    = array();
	$parser = new SseParser( function ( $e, $d ) use ( &$got ) { $got[] = array( $e, $d ); } );
	foreach ( str_split( "event: a\ndata: {\"x\":1}\n\n: keepalive\n\ndata: line1\ndata: line2\r\n\r\nevent: b\ndata: last", 3 ) as $piece ) {
		$parser->feed( $piece );
	}
	$parser->finish();
	T::same( array( array( 'a', '{"x":1}' ), array( '', "line1\nline2" ), array( 'b', 'last' ) ), $got );
} );

T::test( 'marker filter hides [NO_ANSWER] however it is split', function () {
	foreach ( array(
		array( array( '[NO', '_ANS', 'WER] Sorry.' ), 'Sorry.', true ),
		array( array( '**[NO_ANSWER]**', ' Not here.' ), 'Not here.', true ),
		array( array( '[Note] ', 'real text' ), '[Note] real text', false ),
		array( array( 'Hello', ' there' ), 'Hello there', false ),
		array( array( '  ', '[NO_ANSWER]' ), '', true ),
	) as [ $pieces, $expected, $marked ] ) {
		$out    = '';
		$filter = new MarkerFilter( function ( $t ) use ( &$out ) { $out .= $t; } );
		foreach ( $pieces as $p ) {
			$filter->push( $p );
		}
		$filter->finish();
		T::same( $expected, $out, implode( '|', $pieces ) );
		T::same( $marked, $filter->had_marker() );
	}
} );

// ── Gemini and OpenRouter (non-streaming) ────────────────────────────────────

T::test( 'Gemini uses Google\'s OpenAI-compatible endpoint with thinking set low', function () {
	sai_save_settings( array( 'provider' => 'gemini', 'gemini_key' => 'AIza-test-key', 'visitor_hourly_limit' => 1000 ) );
	FakeHttp::reset();
	FakeHttp::push( 200, array( 'model' => 'gemini-2.5-flash', 'choices' => array( array( 'message' => array( 'content' => 'Hi from Gemini' ), 'finish_reason' => 'stop' ) ), 'usage' => array( 'prompt_tokens' => 1000000, 'completion_tokens' => 0 ) ) );

	$data = sai_chat( 'hello', '', 'geminivisitor001' )->get_data();
	T::same( 'Hi from Gemini', $data['reply'] );

	$req = FakeHttp::last();
	T::same( 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', $req['url'] );
	T::same( 'Bearer AIza-test-key', $req['headers']['Authorization'] );
	T::same( 'gemini-2.5-flash', $req['body']['model'] );
	T::same( 'low', $req['body']['reasoning_effort'] ?? null );
	T::ok( isset( $req['body']['max_tokens'] ), 'max_tokens field' );

	global $wpdb;
	$cost = (float) $wpdb->get_var( 'SELECT cost FROM ' . Installer::tables()['messages'] . " WHERE role='assistant' ORDER BY id DESC LIMIT 1" );
	T::ok( abs( $cost - 0.30 ) < 1e-6, 'priced from the Gemini table: ' . $cost );
} );

T::test( 'OpenRouter sends attribution headers and records the exact reported cost', function () {
	sai_save_settings( array( 'provider' => 'openrouter', 'openrouter_key' => 'sk-or-test', 'openrouter_model' => 'anthropic/claude-haiku-4.5' ) );
	FakeHttp::reset();
	FakeHttp::push( 200, array( 'model' => 'anthropic/claude-haiku-4.5', 'choices' => array( array( 'message' => array( 'content' => 'Hi via OpenRouter' ), 'finish_reason' => 'stop' ) ), 'usage' => array( 'prompt_tokens' => 50, 'completion_tokens' => 5, 'cost' => 0.00123 ) ) );

	$data = sai_chat( 'hello', '', 'orvisitor0000001' )->get_data();
	T::same( 'Hi via OpenRouter', $data['reply'] );

	$req = FakeHttp::last();
	T::same( 'https://openrouter.ai/api/v1/chat/completions', $req['url'] );
	T::same( home_url( '/' ), $req['headers']['HTTP-Referer'] );
	T::ok( ! empty( $req['headers']['X-Title'] ), 'site name sent' );
	T::same( array( 'include' => true ), $req['body']['usage'] ?? null );
	T::same( 'anthropic/claude-haiku-4.5', $req['body']['model'], 'vendor/model ids kept' );

	global $wpdb;
	T::ok( abs( (float) $wpdb->get_var( 'SELECT cost FROM ' . Installer::tables()['messages'] . " WHERE role='assistant' ORDER BY id DESC LIMIT 1" ) - 0.00123 ) < 1e-9, 'exact cost stored' );
	sai_save_settings( array( 'provider' => 'openai', 'openai_key' => 'sk-test-openai-key-123' ) );
} );

// ── Streaming against a real local server ───────────────────────────────────

T::test( 'streaming is off by default and the endpoint says so', function () {
	$r = sai_stream( 'hello' );
	T::same( 404, $r['status'] );
	T::same( false, \Softorio\AiAssistant\Frontend\Widget::config()['streaming'] );
} );

sai_save_settings( array( 'streaming' => true, 'openai_key' => 'sk-test-openai-key-123' ) );

T::test( 'OpenAI-format stream: deltas arrive in order, then one done event with usage', function () {
	sai_mock( 'openai', 'openai', 'text' );
	$r = sai_stream( 'How long is delivery?' );

	T::same( 'Delivery takes **2 days** in Dhaka.', sai_deltas( $r['events'] ) );
	T::ok( count( array_filter( $r['events'], fn( $e ) => 'delta' === $e[0] ) ) >= 3, 'several deltas, not one blob' );
	$done = end( $r['events'] );
	T::same( 'done', $done[0] );
	T::same( 'Delivery takes **2 days** in Dhaka.', $done[1]['reply'] );
	T::ok( (bool) preg_match( '/^[a-f0-9]{32}$/', $done[1]['conversation_id'] ) );
	T::ok( $done[1]['message_id'] > 0, 'answer id for feedback' );

	$body = sai_mock_body();
	T::same( true, $body['stream'] );
	T::same( array( 'include_usage' => true ), $body['stream_options'] ?? null );

	global $wpdb;
	T::same( 15, (int) $wpdb->get_var( 'SELECT output_tokens FROM ' . Installer::tables()['messages'] . " WHERE role='assistant' ORDER BY id DESC LIMIT 1" ), 'usage from the final chunk' );
} );

T::test( 'Claude-format stream works the same way, including Bangla', function () {
	sai_save_settings( array( 'provider' => 'claude', 'claude_key' => 'sk-ant-test' ) );
	sai_mock( 'claude', 'claude', 'bangla' );
	$r = sai_stream( 'ডেলিভারি চার্জ?' );
	T::same( 'ঢাকার ভিতরে ডেলিভারি ৬০ টাকা।', sai_deltas( $r['events'] ) );
	T::same( 'ঢাকার ভিতরে ডেলিভারি ৬০ টাকা।', end( $r['events'] )[1]['reply'] );
	sai_save_settings( array( 'provider' => 'openai' ) );
} );

T::test( 'the [NO_ANSWER] marker never reaches the visitor, even streamed in pieces', function () {
	sai_save_settings( array( 'leads_mode' => 'fallback', 'lead_name' => 'optional', 'lead_email' => 'optional' ) );
	sai_mock( 'openai', 'openai', 'noanswer' );
	$r = sai_stream( 'wholesale?', 'streamvisitor002' );

	T::same( 'Sorry, not on our site.', sai_deltas( $r['events'] ) );
	$done = end( $r['events'] )[1];
	T::same( true, $done['unanswered'] );
	T::same( true, $done['offer_lead'] );
	sai_save_settings( array( 'leads_mode' => 'off' ) );
} );

T::test( 'streamed tool calls are assembled from fragments, run, and the answer follows', function () {
	$GLOBALS['sai_mock_tool'] = true;
	MockLookupTool::$calls    = array();

	foreach ( array( 'openai' => 'openai', 'claude' => 'claude' ) as $provider => $format ) {
		sai_save_settings( array( 'provider' => $provider, 'claude_key' => 'sk-ant-test' ) );
		sai_mock( $provider, $format, 'tools' );
		$r     = sai_stream( 'find headphones', 'toolstream' . $provider . '00' );
		$names = array_column( $r['events'], 0 );

		T::ok( in_array( 'tool', $names, true ) && in_array( 'reset', $names, true ), "$provider: tool + reset events" );
		T::same( array( 'q' => 'head' ), end( MockLookupTool::$calls ), "$provider: arguments rebuilt from JSON fragments" );
		T::same( 'Found it: the headphones.', end( $r['events'] )[1]['reply'] ?? null, "$provider: final answer" );
	}

	$GLOBALS['sai_mock_tool'] = false;
	sai_save_settings( array( 'provider' => 'openai' ) );
} );

T::test( 'a provider error before any text falls back to the backup provider', function () {
	sai_save_settings( array( 'provider' => 'openai', 'fallback_provider' => 'claude', 'claude_key' => 'sk-ant-test' ) );
	$GLOBALS['sai_endpoint'] = array(
		'openai' => 'http://127.0.0.1:8977/openai?s=error',
		'claude' => 'http://127.0.0.1:8977/claude?s=text',
	);
	$r = sai_stream( 'hello', 'fallbackstream01' );
	T::same( 'done', end( $r['events'] )[0] );
	T::same( 'Delivery takes **2 days** in Dhaka.', end( $r['events'] )[1]['reply'] );
	T::same( 'openai', get_option( 'softorio_ai_last_error' )['provider'] ?? null );
	T::ok( str_contains( get_option( 'softorio_ai_last_error' )['message'], 'Invalid API key' ), 'provider error text kept for the owner' );
} );

T::test( 'an error after text has been shown is reported, not restarted elsewhere', function () {
	$GLOBALS['sai_endpoint'] = array(
		'openai' => 'http://127.0.0.1:8977/openai?s=midway',
		'claude' => 'http://127.0.0.1:8977/claude?s=text',
	);
	$r = sai_stream( 'hello', 'midwaystream0001' );
	T::same( 'error', end( $r['events'] )[0] );
	T::same( 'generation_failed', end( $r['events'] )[1]['code'] );
	T::ok( ! str_contains( wp_json_encode( $r['events'] ), 'Upstream overloaded' ), 'no provider internals to the visitor' );
	sai_save_settings( array( 'fallback_provider' => '' ) );
} );

T::test( 'limits apply to streaming too, as an error event', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	sai_save_settings( array( 'visitor_hourly_limit' => 1 ) );
	sai_mock( 'openai', 'openai', 'text' );
	sai_stream( 'one', 'limitstream00001' );
	$r = sai_stream( 'two', 'limitstream00001', '', true );
	T::same( array( 'error', 'rate_limited' ), array( end( $r['events'] )[0], end( $r['events'] )[1]['code'] ) );
	sai_save_settings( array( 'visitor_hourly_limit' => 1000 ) );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
} );

// ── Feedback ─────────────────────────────────────────────────────────────────

function sai_rate( string $conversation, int $message, int $rating, string $token ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/softorio-ai/v1/feedback' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'conversation_id' => $conversation, 'message_id' => $message, 'rating' => $rating, 'visitor_token' => $token ) ) );
	return rest_do_request( $request );
}

T::test( 'visitors can rate their own answers only', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	$GLOBALS['sai_endpoint'] = null;
	FakeHttp::reset();
	FakeHttp::openai( 'Our store opens at 10am.' );
	$chat = sai_chat( 'opening hours?', '', 'ratevisitor00001' )->get_data();

	$heard = array();
	Events::listen( Events::ANSWER_RATED, function ( $p ) use ( &$heard ) { $heard[] = $p; } );

	T::same( 200, sai_rate( $chat['conversation_id'], $chat['message_id'], -1, 'ratevisitor00001' )->get_status() );
	T::same( 'not_helpful', $heard[0]['rating'] ?? null );
	T::same( 'Our store opens at 10am.', $heard[0]['answer'] ?? null );

	T::same( 404, sai_rate( $chat['conversation_id'], $chat['message_id'], 1, 'someoneelse00000' )->get_status(), 'other browser refused' );
	T::same( 404, sai_rate( $chat['conversation_id'], $chat['message_id'] - 1, 1, 'ratevisitor00001' )->get_status(), 'the visitor\'s own question cannot be rated' );
	T::same( 400, sai_rate( $chat['conversation_id'], $chat['message_id'], 5, 'ratevisitor00001' )->get_status(), 'only -1, 0, 1' );

	T::same( 200, sai_rate( $chat['conversation_id'], $chat['message_id'], 1, 'ratevisitor00001' )->get_status(), 'can change their mind' );

	$history = new WP_REST_Request( 'GET', '/softorio-ai/v1/history' );
	$history->set_query_params( array( 'conversation_id' => $chat['conversation_id'], 'visitor_token' => 'ratevisitor00001' ) );
	$msgs = rest_do_request( $history )->get_data()['messages'];
	T::same( 1, $msgs[1]['rating'] );
	T::same( $chat['message_id'], $msgs[1]['id'] );
	T::same( 0, $msgs[0]['id'], 'visitor messages expose no id' );

	$ratings = ( new \Softorio\AiAssistant\Chat\ConversationStore() )->ratings_since( '2000-01-01 00:00:00' );
	T::ok( $ratings['up'] >= 1, 'counted for the dashboard' );
} );

T::test( 'new provider and streaming settings render and validate', function () {
	$out = \Softorio\AiAssistant\Admin\SettingsPage::sanitize( array( '_tab' => 'ai', 'provider' => 'gemini', 'gemini_reasoning' => 'bogus', 'openrouter_model' => 'meta-llama/llama-3.3-70b-instruct:free' ) );
	T::same( 'gemini', $out['provider'] );
	T::same( 'low', $out['gemini_reasoning'], 'unknown option falls back' );
	T::same( 'meta-llama/llama-3.3-70b-instruct:free', $out['openrouter_model'], 'OpenRouter ids with / and : kept' );
	T::ok( Router::make( 'openrouter' ) instanceof \Softorio\AiAssistant\Llm\OpenAiCompatibleProvider );
	T::ok( Router::make( 'gemini' ) instanceof \Softorio\AiAssistant\Llm\OpenAiCompatibleProvider );
} );

proc_terminate( $sai_mock );
remove_all_filters( 'softorio_ai_provider_endpoint' );
sai_reset();
