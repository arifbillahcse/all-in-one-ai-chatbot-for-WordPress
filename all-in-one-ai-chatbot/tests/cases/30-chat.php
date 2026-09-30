<?php
// phpcs:disable
/**
 * The chat endpoint end to end, with the AI provider faked.
 */

use Softorio\AiAssistant\Settings;

sai_reset();

sai_post( 'Delivery Information', '<p>Inside Dhaka delivery takes 1-2 days and costs 60 Taka. Outside Dhaka delivery takes 3-5 days and costs 120 Taka.</p>' );
wp_insert_post( array(
	'post_type'    => 'softorio_ai_doc',
	'post_status'  => 'publish',
	'post_title'   => 'Refund policy',
	'post_content' => 'Return unused items within 7 days for a full refund, paid by bKash.',
) );

T::test( 'without an API key the endpoint refuses and the widget stays hidden', function () {
	$r = sai_chat( 'hello' );
	T::same( 503, $r->get_status() );
	T::same( 'unavailable', $r->get_data()['code'] );
	T::ok( ! \Softorio\AiAssistant\Frontend\Widget::should_show(), 'widget hidden' );
	T::same( array(), FakeHttp::$requests, 'no provider call made' );
} );

sai_save_settings( array(
	'openai_key'    => 'sk-test-openai-key-123',
	'whatsapp'      => '+880 1711-000000',
	'instructions'  => 'Always greet politely.',
) );

T::test( 'settings form encrypts keys and a blank key field keeps the saved key', function () {
	$raw = get_option( Settings::OPTION );
	T::ok( ! str_contains( wp_json_encode( $raw ), 'sk-test-openai' ), 'no plaintext key in the option' );
	T::same( 'sk-test-openai-key-123', Settings::api_key( 'openai' ) );

	sai_save_settings( array( 'assistant_name' => 'Rina' ) );
	T::same( 'sk-test-openai-key-123', Settings::api_key( 'openai' ), 'key survives an unrelated save' );
	T::same( 'Rina', Settings::get( 'assistant_name' ) );
	T::ok( in_array( 'softorio_ai_doc', Settings::get( 'post_types' ), true ), 'knowledge articles always included' );
	T::ok( \Softorio\AiAssistant\Frontend\Widget::should_show(), 'widget shown once ready' );
} );

$first_id = '';

T::test( 'a question is answered from site content', function () use ( &$first_id ) {
	FakeHttp::openai( 'Inside Dhaka delivery takes 1-2 days. See http://127.0.0.1:8899/delivery-information/' );

	$r    = sai_chat( 'How long is delivery inside Dhaka?' );
	$data = $r->get_data();

	T::same( 200, $r->get_status(), wp_json_encode( $data ) );
	T::ok( str_contains( $data['reply'], '1-2 days' ), 'reply returned' );
	T::ok( (bool) preg_match( '/^[a-f0-9]{32}$/', $data['conversation_id'] ), 'conversation id issued' );
	T::same( 'Delivery Information', $data['sources'][0]['title'] ?? null );

	$req = FakeHttp::last();
	T::ok( str_contains( $req['url'], 'api.openai.com' ), 'called OpenAI' );
	T::same( 'Bearer sk-test-openai-key-123', $req['headers']['Authorization'] );
	T::same( 'gpt-5-mini', $req['body']['model'] );
	T::same( 'minimal', $req['body']['reasoning_effort'] ?? null );
	T::ok( isset( $req['body']['max_completion_tokens'] ), 'OpenAI token field name' );

	$system = $req['body']['messages'][0]['content'];
	T::same( 'system', $req['body']['messages'][0]['role'] );
	T::ok( str_contains( $system, 'costs 60 Taka' ), 'retrieved content in prompt' );
	T::ok( str_contains( $system, 'Rina' ), 'assistant name in prompt' );
	T::ok( str_contains( $system, 'https://wa.me/8801711000000' ), 'WhatsApp hand-off in prompt' );
	T::ok( str_contains( $system, 'Always greet politely.' ), 'owner instructions in prompt' );
	T::ok( str_contains( $system, '/shop/' ), 'current page in prompt' );

	$first_id = $data['conversation_id'];
} );

T::test( 'a follow-up continues the same conversation with history', function () use ( &$first_id ) {
	FakeHttp::openai( 'Outside Dhaka it is 120 Taka.' );

	$data = sai_chat( 'and outside?', $first_id )->get_data();
	$req  = FakeHttp::last();

	T::same( $first_id, $data['conversation_id'] );
	T::same( array( 'system', 'user', 'assistant', 'user' ), array_column( $req['body']['messages'], 'role' ) );
	T::same( 'and outside?', end( $req['body']['messages'] )['content'] );
	T::ok( str_contains( $req['body']['messages'][0]['content'], '120 Taka' ), 'short follow-up still retrieves the right page' );
} );

T::test( 'another browser cannot resume or read the conversation', function () use ( &$first_id ) {
	FakeHttp::openai( 'Hello!' );

	$data = sai_chat( 'hi', $first_id, 'someoneelse00000' )->get_data();
	T::ok( $data['conversation_id'] !== $first_id, 'a new conversation was started instead' );
	T::same( array( 'system', 'user' ), array_column( FakeHttp::last()['body']['messages'], 'role' ), 'no leaked history' );

	$req = new WP_REST_Request( 'GET', '/softorio-ai/v1/history' );
	$req->set_query_params( array( 'conversation_id' => $first_id, 'visitor_token' => 'someoneelse00000' ) );
	T::same( 404, rest_do_request( $req )->get_status() );

	$req->set_query_params( array( 'conversation_id' => $first_id, 'visitor_token' => 'visitortoken0001' ) );
	$own = rest_do_request( $req );
	T::same( 200, $own->get_status() );
	T::same( 4, count( $own->get_data()['messages'] ), 'owner sees both exchanges' );
} );

T::test( 'conversations are stored with usage and cost', function () use ( &$first_id ) {
	global $wpdb;
	$t   = \Softorio\AiAssistant\Installer::tables();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['conversations']} WHERE public_id = %s", $first_id ), ARRAY_A );

	T::same( 4, (int) $row['message_count'] );
	T::same( 'How long is delivery inside Dhaka?', $row['title'] );
	T::ok( (float) $row['total_cost'] > 0, 'cost recorded' );
	T::ok( 64 === strlen( $row['visitor_hash'] ) && ! str_contains( $row['visitor_hash'], 'visitortoken' ), 'token stored hashed' );
} );

T::test( 'invalid input is rejected before any provider call', function () {
	FakeHttp::reset();
	T::same( 422, sai_chat( "   \x07 " )->get_status() );
	T::same( 400, sai_chat( 'hi', 'not-hex!' )->get_status(), 'bad conversation id' );
	T::same( 400, sai_chat( 'hi', '', 'short' )->get_status(), 'bad visitor token' );
	T::same( array(), FakeHttp::$requests );
} );

T::test( 'provider failure falls back to the backup provider', function () {
	sai_save_settings( array( 'claude_key' => 'sk-ant-test-key-999', 'fallback_provider' => 'claude' ) );

	FakeHttp::push( 500, array( 'error' => array( 'message' => 'overloaded' ) ) );
	FakeHttp::push( 200, array(
		'model'       => 'claude-haiku-4-5-20251001',
		'content'     => array( array( 'type' => 'text', 'text' => 'Refunds are paid by bKash.' ) ),
		'usage'       => array( 'input_tokens' => 50, 'output_tokens' => 10 ),
		'stop_reason' => 'end_turn',
	) );

	$r = sai_chat( 'How are refunds paid?', '', 'visitortoken0002' );
	T::same( 200, $r->get_status(), wp_json_encode( $r->get_data() ) );
	T::same( 'Refunds are paid by bKash.', $r->get_data()['reply'] );

	$claude = FakeHttp::last();
	T::ok( str_contains( $claude['url'], 'anthropic.com' ), 'second call went to Anthropic' );
	T::same( 'sk-ant-test-key-999', $claude['headers']['x-api-key'] );
	T::ok( is_string( $claude['body']['system'] ) && '' !== $claude['body']['system'], 'system prompt top-level for Claude' );
	T::same( array( 'user' ), array_column( $claude['body']['messages'], 'role' ) );
	T::same( 'openai', get_option( 'softorio_ai_last_error' )['provider'] ?? null, 'failure recorded for the dashboard' );
} );

T::test( 'when every provider fails the visitor gets a polite error, not internals', function () {
	FakeHttp::push( 401, array( 'error' => array( 'message' => 'Incorrect API key provided: sk-...' ) ) );
	FakeHttp::push( 529, array( 'error' => array( 'message' => 'Overloaded' ) ) );

	$r = sai_chat( 'hello?', '', 'visitortoken0003' );
	T::same( 503, $r->get_status() );
	T::same( 'generation_failed', $r->get_data()['code'] );
	T::ok( ! str_contains( wp_json_encode( $r->get_data() ), 'sk-' ), 'no provider detail leaked' );
} );

T::test( 'an empty answer is treated as a failure', function () {
	sai_save_settings( array( 'fallback_provider' => '' ) );
	FakeHttp::push( 200, array(
		'model'   => 'gpt-5-mini',
		'choices' => array( array( 'message' => array( 'content' => '' ), 'finish_reason' => 'length' ) ),
		'usage'   => array( 'prompt_tokens' => 10, 'completion_tokens' => 1024 ),
	) );

	T::same( 503, sai_chat( 'hi', '', 'visitortoken0004' )->get_status() );
	T::ok( str_contains( get_option( 'softorio_ai_last_error' )['message'], 'output budget' ), 'owner told why' );
} );
