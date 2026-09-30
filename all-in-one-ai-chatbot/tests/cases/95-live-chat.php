<?php
// phpcs:disable
/**
 * Phase 6: live agent takeover, agent inbox, alerts, Telegram replies.
 */

use Softorio\AiAssistant\Admin\LivePage;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Chat\Transcript;
use Softorio\AiAssistant\Frontend\Widget;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Live\LiveChat;
use Softorio\AiAssistant\Live\TelegramBridge;
use Softorio\AiAssistant\Notify\Notifier;
use Softorio\AiAssistant\Support\Events;

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'visitor_hourly_limit' => 1000 ) );
delete_option( 'softorio_ai_agents_online' );

function sai_rest( string $method, string $route, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/softorio-ai/v1/' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	return rest_do_request( $request );
}

function sai_clear_limits(): void {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
}

$sai_live = array( 'token' => 'livevisitor00001', 'conv' => '', 'id' => 0, 'events' => array() );

add_action( 'softorio_ai_event', static function ( string $event, array $payload ) use ( &$sai_live ) {
	$sai_live['events'][] = array( $event, $payload );
}, 5, 2 );

T::test( 'live chat is off by default and changes nothing', function () {
	T::same( null, Widget::config()['live'] );
	T::same( 401, sai_rest( 'GET', 'live/status' )->get_status(), 'visitor routes closed' );
	wp_set_current_user( 1 );
	T::ok( in_array( sai_rest( 'GET', 'agent/inbox' )->get_status(), array( 401, 403 ), true ), 'agent routes closed' );
	wp_set_current_user( 0 );
	T::same( 'ai', LiveChat::mode( array( 'mode' => 'human' ) ), 'stored mode ignored while off' );
	T::same( 0, LiveChat::waiting_count() );
} );

sai_save_settings( array( 'live_chat' => true, 'live_wait_timeout' => 3 ) );

T::test( 'who can answer: administrators, plus the roles chosen', function () {
	$manager = wp_insert_user( array( 'user_login' => 'sai_mgr_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	$sub     = wp_insert_user( array( 'user_login' => 'sai_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );

	T::ok( user_can( 1, LiveChat::CAP ), 'admin' );
	T::ok( ! user_can( $manager, LiveChat::CAP ), 'shop manager not yet' );
	sai_save_settings( array( 'live_agent_roles' => array( 'shop_manager', 'subscriber', 'bogus' ) ) );
	T::same( array( 'shop_manager' ), array_values( (array) \Softorio\AiAssistant\Settings::get( 'live_agent_roles' ) ), 'only offered roles are saved' );
	T::ok( user_can( $manager, LiveChat::CAP ), 'shop manager once chosen' );
	T::ok( ! user_can( $sub, LiveChat::CAP ), 'subscriber never' );

	wp_set_current_user( $sub );
	T::ok( in_array( sai_rest( 'GET', 'agent/inbox' )->get_status(), array( 401, 403 ), true ), 'subscriber refused' );
	wp_set_current_user( 0 );

	wp_delete_user( $manager );
	wp_delete_user( $sub );
} );

T::test( 'presence: offered only while an available agent is online', function () {
	T::same( false, sai_rest( 'GET', 'live/status' )->get_data()['available'] );

	$r = sai_rest( 'POST', 'live/request', array( 'visitor_token' => 'nobodyhome000001' ) );
	T::same( 409, $r->get_status() );
	T::same( 'no_agents', $r->get_data()['code'] );

	LiveChat::heartbeat( 1 );
	T::same( true, sai_rest( 'GET', 'live/status' )->get_data()['available'] );
	T::ok( str_contains( (string) ( sai_rest( 'GET', 'live/status' )->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), 'never cached' );

	LiveChat::set_away( 1, true );
	T::ok( ! LiveChat::available(), 'away' );
	LiveChat::set_away( 1, false );
	T::ok( LiveChat::available() );

	update_option( 'softorio_ai_agents_online', array( 1 => time() - 600 ) );
	T::ok( ! LiveChat::available(), 'stale heartbeat' );
	LiveChat::heartbeat( 1 );
} );

T::test( 'a visitor asks for a person: waiting, notice, event', function () use ( &$sai_live ) {
	sai_clear_limits();
	FakeHttp::reset();
	FakeHttp::openai( 'Our return window is 30 days.' );
	$first = sai_chat( 'What is your return policy?', '', $sai_live['token'] )->get_data();
	T::same( 'ai', $first['mode'] );

	$sai_live['events'] = array();
	$r = sai_rest( 'POST', 'live/request', array( 'conversation_id' => $first['conversation_id'], 'visitor_token' => $sai_live['token'], 'page_url' => home_url( '/returns/' ) ) );
	T::same( 200, $r->get_status() );
	T::same( 'waiting', $r->get_data()['mode'] );
	T::same( $first['conversation_id'], $r->get_data()['conversation_id'], 'same conversation' );
	T::same( 'waiting', $r->get_data()['messages'][0]['event'] );

	$sai_live['conv'] = $first['conversation_id'];
	$sai_live['id']   = (int) ( new ConversationStore() )->find_owned( $first['conversation_id'], $sai_live['token'] )['id'];

	$requested = array_values( array_filter( $sai_live['events'], static fn( $e ) => Events::LIVE_REQUESTED === $e[0] ) );
	T::same( 1, count( $requested ), 'event emitted once' );
	T::ok( str_contains( $requested[0][1]['admin_url'], 'softorio-ai-live' ) );
	T::same( 'What is your return policy?', $requested[0][1]['transcript'][0]['content'] );

	$again = sai_rest( 'POST', 'live/request', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) );
	T::same( 'waiting', $again->get_data()['mode'], 'asking twice changes nothing' );
	T::same( 1, count( array_filter( $sai_live['events'], static fn( $e ) => Events::LIVE_REQUESTED === $e[0] ) ), 'no second alert' );

	T::same( 1, LiveChat::waiting_count() );
	$email = Notifier::compose_email( Events::LIVE_REQUESTED, $requested[0][1] );
	T::ok( str_contains( $email['subject'], 'waiting for a live chat' ) );
	T::ok( str_contains( $email['html'], 'What is your return policy?' ), 'email carries the chat so far' );
} );

T::test( 'while waiting, visitor messages skip the AI entirely', function () use ( &$sai_live ) {
	FakeHttp::reset();
	$r = sai_chat( 'Hello? Is anyone there?', $sai_live['conv'], $sai_live['token'] )->get_data();
	T::same( true, $r['live'] );
	T::same( '', $r['reply'] );
	T::same( 'waiting', $r['mode'] );
	T::same( array(), FakeHttp::$requests, 'no AI call, no cost' );

	for ( $i = 0; $i < 12; $i++ ) {
		$ok = sai_chat( "quick message $i", $sai_live['conv'], $sai_live['token'] )->get_status();
	}
	T::same( 200, $ok, 'people type fast: 12 messages in a minute are fine (the AI burst limit is 6)' );
} );

T::test( 'agent inbox, takeover by replying, visitor sees it', function () use ( &$sai_live ) {
	wp_set_current_user( 1 );
	wp_update_user( array( 'ID' => 1, 'first_name' => 'Karim' ) );

	$inbox = sai_rest( 'GET', 'agent/inbox' )->get_data();
	T::same( 1, $inbox['waiting'] );
	$row = $inbox['conversations'][0];
	T::same( 'waiting', $row['mode'], 'waiting listed first' );
	T::same( $sai_live['id'], $row['id'] );
	T::same( 13, $row['unread'] );
	T::ok( str_starts_with( $row['visitor'], 'Visitor ' ) );

	$conv = sai_rest( 'GET', 'agent/conversations/' . $sai_live['id'] )->get_data();
	T::same( 'waiting', $conv['conversation']['mode'] );
	T::same( 'user', $conv['messages'][0]['role'] );
	T::same( 'assistant', $conv['messages'][1]['role'], 'agents see the AI part too' );
	T::same( 0, LiveChat::inbox( 1 )[0]['unread'], 'opening marks it read' );

	$after = (int) end( $conv['messages'] )['id'];
	$sent  = sai_rest( 'POST', 'agent/conversations/' . $sai_live['id'] . '/message', array( 'text' => "Hi, I'm Karim. Which order is it?", 'after' => $after ) )->get_data();
	T::same( 'human', $sent['conversation']['mode'], 'replying takes over' );
	T::same( 1, $sent['conversation']['agentId'] );
	T::same( array( 'system', 'agent' ), array_column( $sent['messages'], 'role' ) );
	T::same( 'joined', $sent['messages'][0]['event'] );
	T::same( 'Karim joined the chat.', $sent['messages'][0]['content'] );

	T::same( 422, sai_rest( 'POST', 'agent/conversations/' . $sai_live['id'] . '/message', array( 'text' => '   ' ) )->get_status(), 'empty reply refused' );
	sai_rest( 'POST', 'agent/conversations/' . $sai_live['id'] . '/typing' );
	wp_set_current_user( 0 );

	$poll = sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'], 'after' => 0 ) )->get_data();
	T::same( 'human', $poll['mode'] );
	T::same( 'Karim', $poll['agent']['name'] );
	T::same( 'Karim', $poll['typing'], 'agent typing shown' );
	T::same( array( 'system', 'system', 'agent' ), array_column( $poll['messages'], 'role' ), 'only agent and system messages go to the visitor' );
	T::same( "Hi, I'm Karim. Which order is it?", $poll['messages'][2]['content'] );
	T::same( 'Karim', $poll['messages'][2]['agent']['name'] );

	$last = (int) end( $poll['messages'] )['id'];
	T::same( array(), sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'], 'after' => $last ) )->get_data()['messages'], 'after = nothing new' );

	sai_rest( 'POST', 'live/typing', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) );
	T::same( '1', LiveChat::is_typing( $sai_live['id'], 'visitor' ) );
	FakeHttp::reset();
	sai_chat( 'Order #1234, it has not arrived.', $sai_live['conv'], $sai_live['token'] );
	T::same( '', LiveChat::is_typing( $sai_live['id'], 'visitor' ), 'sending clears "typing"' );
	T::same( array(), FakeHttp::$requests, 'still no AI' );
	sai_rest( 'POST', 'live/typing', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) );
	T::same( 1, LiveChat::unread_for( 1 ), 'agent has 1 unread' );

	wp_set_current_user( 1 );
	$conv = sai_rest( 'GET', 'agent/conversations/' . $sai_live['id'], array( 'after' => $last ) )->get_data();
	T::same( true, $conv['typing'], 'visitor typing shown to agent' );
	T::same( 0, LiveChat::unread_for( 1 ) );

	$heartbeat = LivePage::heartbeat( array(), array( 'softorio_ai_live' => 1 ) );
	T::same( array( 'waiting' => 0, 'unread' => 0 ), $heartbeat['softorio_ai_live'] );
	wp_set_current_user( 0 );
	T::same( array(), LivePage::heartbeat( array(), array( 'softorio_ai_live' => 1 ) ), 'nothing for non-agents' );
} );

T::test( 'visitors cannot read or steer someone else\'s live chat', function () use ( &$sai_live ) {
	T::same( 404, sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => 'someoneelse00001' ) )->get_status() );
	T::same( 404, sai_rest( 'POST', 'live/leave', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => 'someoneelse00001' ) )->get_status() );
	T::same( 'human', LiveChat::mode( LiveChat::row( $sai_live['id'] ) ) );
} );

T::test( 'hand back to AI: the AI knows what the agent said', function () use ( &$sai_live ) {
	wp_set_current_user( 1 );
	$r = sai_rest( 'POST', 'agent/conversations/' . $sai_live['id'] . '/release' )->get_data();
	T::same( 'ai', $r['conversation']['mode'] );
	wp_set_current_user( 0 );

	sai_clear_limits();
	FakeHttp::reset();
	FakeHttp::openai( 'Your order ships tomorrow.' );
	$answer = sai_chat( 'When will it ship?', $sai_live['conv'], $sai_live['token'] )->get_data();
	T::same( 'Your order ships tomorrow.', $answer['reply'], 'AI answers again' );

	$sent = wp_json_encode( FakeHttp::last()['body'] );
	T::ok( str_contains( $sent, '[Reply from a human support agent] Hi, I' ), 'agent reply in the AI history' );
	T::ok( ! str_contains( $sent, 'joined the chat' ), 'system notices are not' );

	$ended = array_values( array_filter( $sai_live['events'], static fn( $e ) => Events::LIVE_ENDED === $e[0] ) );
	T::same( 'agent', end( $ended )[1]['reason'] );
} );

T::test( 'agents can join an AI chat unasked; the visitor can leave', function () use ( &$sai_live ) {
	wp_set_current_user( 1 );
	sai_rest( 'POST', 'agent/conversations/' . $sai_live['id'] . '/takeover' );
	T::same( 'human', LiveChat::mode( LiveChat::row( $sai_live['id'] ) ) );
	wp_set_current_user( 0 );

	$r = sai_rest( 'POST', 'live/leave', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) );
	T::same( 'ai', $r->get_data()['mode'] );
	$poll = sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'], 'after' => 0 ) )->get_data();
	T::same( 'visitor', end( $poll['messages'] )['event'] );
} );

T::test( 'nobody answers in time: back to the AI with a lead offer', function () use ( &$sai_live ) {
	global $wpdb;
	sai_clear_limits();
	$sai_live['events'] = array();

	$r = sai_rest( 'POST', 'live/request', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) );
	T::same( 'waiting', $r->get_data()['mode'] );

	$wpdb->update( Installer::tables()['conversations'], array( 'mode_since' => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ), array( 'id' => $sai_live['id'] ) );

	$poll = sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'], 'after' => 0 ) )->get_data();
	T::same( 'ai', $poll['mode'] );
	T::same( 'timeout', end( $poll['messages'] )['event'] );
	T::ok( str_contains( end( $poll['messages'] )['content'], 'busy' ) );

	// The same happens if the visitor writes instead of polling.
	LiveChat::request( $sai_live['id'] );
	$wpdb->update( Installer::tables()['conversations'], array( 'mode_since' => gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) ), array( 'id' => $sai_live['id'] ) );
	FakeHttp::reset();
	FakeHttp::openai( 'I can help with that.' );
	T::same( 'I can help with that.', sai_chat( 'hello?', $sai_live['conv'], $sai_live['token'] )->get_data()['reply'] );
} );

T::test( 'request limits: a handful per hour', function () {
	sai_clear_limits();
	$codes = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$codes[] = sai_rest( 'POST', 'live/request', array( 'visitor_token' => 'spammer000000001' ) )->get_status();
	}
	T::same( array( 200, 200, 200, 200, 200, 429 ), $codes );
} );

T::test( 'transcripts, emails and the Conversations screen show agents', function () use ( &$sai_live ) {
	$messages = Transcript::messages( $sai_live['id'] );
	T::ok( ! in_array( 'system', array_column( $messages, 'role' ), true ), 'system notices left out' );
	T::ok( str_contains( Transcript::text( $messages ), "Karim: Hi, I'm Karim." ), 'agent named' );

	wp_set_current_user( 1 );
	$_GET['conversation'] = $sai_live['id'];
	ob_start();
	\Softorio\AiAssistant\Admin\ConversationsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['conversation'] );
	wp_set_current_user( 0 );
	T::ok( str_contains( $html, 'Karim (team)' ) && str_contains( $html, 'sai-turn-system' ), 'agent and notices rendered' );

	$history = sai_rest( 'GET', 'history', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'] ) )->get_data()['messages'];
	$agent   = array_values( array_filter( $history, static fn( $m ) => 'agent' === $m['role'] ) );
	T::same( 'Karim', $agent[0]['agent']['name'], 'widget history knows the agent' );
	T::same( array(), $agent[0]['sources'] );
} );

T::test( 'feature off mid-chat: the AI answers, nothing is stuck', function () use ( &$sai_live ) {
	LiveChat::request( $sai_live['id'] );
	sai_save_settings( array( 'live_chat' => false ) );
	sai_clear_limits();
	FakeHttp::reset();
	FakeHttp::openai( 'AI here.' );
	T::same( 'AI here.', sai_chat( 'hi', $sai_live['conv'], $sai_live['token'] )->get_data()['reply'] );
	sai_save_settings( array( 'live_chat' => true ) );
	LiveChat::release( $sai_live['id'] );
} );

T::test( 'Telegram: requests posted, replies reach the visitor, /ai hands back', function () use ( &$sai_live ) {
	global $wpdb;
	sai_save_settings( array( 'telegram_key' => '123:ABC', 'telegram_chat_id' => '777', 'telegram_events' => array( Events::LIVE_REQUESTED ), 'live_telegram' => true ) );
	T::ok( TelegramBridge::enabled() );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	add_filter( 'softorio_ai_queue_run_on_shutdown', '__return_false' );

	LiveChat::request( $sai_live['id'] );
	$jobs = $wpdb->get_col( 'SELECT type FROM ' . Installer::tables()['jobs'] );
	T::same( array( 'telegram.live' ), $jobs, 'one replyable post, no duplicate plain alert' );

	FakeHttp::reset();
	FakeHttp::push( 200, array( 'ok' => true, 'result' => array( 'message_id' => 555 ) ) );
	sai_run_queue();
	T::ok( str_contains( FakeHttp::last()['body']['text'], 'Reply to this message' ) );
	T::same( '777', FakeHttp::last()['body']['chat_id'] );

	$hook = static function ( array $update, string $secret = '' ): WP_REST_Response {
		$r = new WP_REST_Request( 'POST', '/softorio-ai/v1/telegram/webhook' );
		$r->set_header( 'Content-Type', 'application/json' );
		if ( '' !== $secret ) {
			$r->set_header( 'X-Telegram-Bot-Api-Secret-Token', $secret );
		}
		$r->set_body( wp_json_encode( $update ) );
		return rest_do_request( $r );
	};

	$reply = array( 'message' => array( 'message_id' => 900, 'chat' => array( 'id' => 777 ), 'from' => array( 'first_name' => 'Asma' ), 'text' => 'Hello from Telegram!', 'reply_to_message' => array( 'message_id' => 555 ) ) );

	T::ok( in_array( $hook( $reply )->get_status(), array( 401, 403 ), true ), 'no secret, no entry' );
	update_option( 'softorio_ai_tg_secret', 'topsecret123', false );
	T::ok( in_array( $hook( $reply, 'wrong' )->get_status(), array( 401, 403 ), true ), 'wrong secret' );

	$stranger = $reply;
	$stranger['message']['chat']['id'] = 999;
	$hook( $stranger, 'topsecret123' );
	T::same( 'waiting', LiveChat::mode( LiveChat::row( $sai_live['id'] ) ), 'other chats ignored' );

	T::same( 200, $hook( $reply, 'topsecret123' )->get_status() );
	$row = LiveChat::row( $sai_live['id'] );
	T::same( 'human', LiveChat::mode( $row ) );
	$poll = sai_rest( 'GET', 'live/poll', array( 'conversation_id' => $sai_live['conv'], 'visitor_token' => $sai_live['token'], 'after' => 0 ) )->get_data();
	T::same( 'Hello from Telegram!', end( $poll['messages'] )['content'] );
	T::same( 'Asma', end( $poll['messages'] )['agent']['name'] );

	// A visitor message is forwarded, and replying to the agent's own post works too.
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	sai_clear_limits();
	sai_chat( 'Thanks Asma!', $sai_live['conv'], $sai_live['token'] );
	$payload = json_decode( (string) $wpdb->get_var( 'SELECT payload FROM ' . Installer::tables()['jobs'] ), true );
	T::ok( str_contains( $payload['text'], 'Thanks Asma!' ) );

	$again = $reply;
	$again['message']['reply_to_message']['message_id'] = 900;
	$again['message']['text'] = '/ai';
	$hook( $again, 'topsecret123' );
	T::same( 'ai', LiveChat::mode( LiveChat::row( $sai_live['id'] ) ), '/ai hands back' );

	try {
		TelegramBridge::connect();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::ok( str_contains( $e->getMessage(), 'HTTPS' ), 'needs HTTPS' );
	}

	remove_all_filters( 'softorio_ai_queue_run_on_shutdown' );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	sai_save_settings( array( 'live_telegram' => false, 'telegram_key' => '', 'telegram_key_clear' => 1 ) );
} );

T::test( 'widget config and settings screen', function () {
	T::same( array( 'label' => 'Chat with our team' ), Widget::config()['live'] );
	T::ok( isset( Widget::config()['i18n']['liveWith'] ) );

	wp_set_current_user( 1 );
	$_GET['tab'] = 'live';
	ob_start();
	\Softorio\AiAssistant\Admin\SettingsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	foreach ( array( 'live_chat', 'live_agent_roles', 'live_wait_timeout', 'live_telegram', 'live_waiting_message' ) as $key ) {
		T::ok( str_contains( $html, '[' . $key . ']' ), $key );
	}
	T::ok( str_contains( $html, 'Connect Telegram replies' ) );

	ob_start();
	LivePage::render();
	T::ok( str_contains( (string) ob_get_clean(), 'id="sai-live"' ) );
	wp_set_current_user( 0 );
} );

delete_option( 'softorio_ai_agents_online' );
delete_option( 'softorio_ai_tg_secret' );
delete_user_meta( 1, LiveChat::AWAY_META );
sai_reset();
