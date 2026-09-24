<?php
// phpcs:disable
/**
 * Phase 1: lead capture, unanswered detection, notifications, webhooks, privacy.
 */

use Softorio\AiAssistant\Admin\LeadsPage;
use Softorio\AiAssistant\Admin\SettingsPage;
use Softorio\AiAssistant\Chat\ConversationCloser;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Leads\LeadStore;
use Softorio\AiAssistant\Leads\PrivacyTools;
use Softorio\AiAssistant\Notify\Webhooks;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Events;

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'visitor_hourly_limit' => 1000 ) );

function sai_lead( array $fields, string $token = 'leadvisitor00001' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/softorio-ai/v1/lead' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( array( 'visitor_token' => $token, 'page_url' => home_url( '/contact/' ) ), $fields ) ) );
	return rest_do_request( $request );
}

function sai_reset_io(): void {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	FakeMail::$sent = array();
	FakeMail::$fail = false;
	FakeHttp::reset();
}

T::test( 'lead form is refused while lead capture is off', function () {
	T::same( 404, sai_lead( array( 'name' => 'A', 'email' => 'a@example.com' ) )->get_status() );
	T::same( null, \Softorio\AiAssistant\Frontend\Widget::config()['leads'], 'widget gets no form' );
} );

sai_save_settings( array( 'leads_mode' => 'fallback', 'lead_name' => 'required', 'lead_email' => 'required', 'lead_phone' => 'optional' ) );

T::test( 'lead fields are validated on the server by the owner\'s rules', function () {
	$r = sai_lead( array( 'name' => '', 'email' => 'not-an-email' ) );
	T::same( 422, $r->get_status() );
	T::ok( isset( $r->get_data()['fields']['name'], $r->get_data()['fields']['email'] ), 'per-field errors' );

	$r = sai_lead( array( 'name' => 'Rahim', 'email' => 'rahim@example.com', 'phone' => '12' ) );
	T::same( 422, $r->get_status() );
	T::ok( isset( $r->get_data()['fields']['phone'] ), 'bad phone rejected' );

	sai_save_settings( array( 'lead_email' => 'optional', 'lead_phone' => 'optional' ) );
	$r = sai_lead( array( 'name' => 'Rahim' ) );
	T::same( 422, $r->get_status(), 'a lead needs some way to be contacted' );

	sai_save_settings( array( 'lead_email' => 'required', 'consent_required' => true ) );
	$r = sai_lead( array( 'name' => 'Rahim', 'email' => 'rahim@example.com' ) );
	T::ok( isset( $r->get_data()['fields']['consent'] ), 'consent enforced' );
} );

T::test( 'a valid lead is saved with consent text, linked only to the visitor\'s own chat', function () {
	sai_reset_io();
	FakeHttp::openai( 'Hi!' );
	$chat = sai_chat( 'hello', '', 'leadvisitor00001' )->get_data();

	$r = sai_lead( array( 'name' => 'Rahim <b>', 'email' => 'rahim@example.com', 'phone' => '+880 1711-000000', 'consent' => 1, 'conversation_id' => $chat['conversation_id'], 'source' => 'fallback' ) );
	T::same( 201, $r->get_status(), wp_json_encode( $r->get_data() ) );
	T::same( Settings::get( 'lead_thanks' ), $r->get_data()['message'] );

	$lead = ( new LeadStore() )->search( 'rahim@example.com' )['rows'][0];
	T::same( 'Rahim', $lead['name'], 'tags stripped' );
	T::same( '1', (string) $lead['consent'] );
	T::same( Settings::get( 'consent_text' ), $lead['consent_text'], 'exact consent wording stored' );
	T::ok( (int) $lead['conversation_id'] > 0, 'linked to own conversation' );

	$other = sai_lead( array( 'name' => 'Eve', 'email' => 'eve@example.com', 'consent' => 1, 'conversation_id' => $chat['conversation_id'] ), 'attackertoken000' );
	T::same( 201, $other->get_status() );
	$eve = ( new LeadStore() )->search( 'eve@example.com' )['rows'][0];
	T::same( '0', (string) $eve['conversation_id'], 'cannot attach to someone else\'s chat' );
} );

T::test( 'lead capture is rate limited per network', function () {
	sai_reset_io();
	for ( $i = 0; $i < 10; $i++ ) {
		sai_lead( array( 'name' => 'Spam', 'email' => "s$i@example.com", 'consent' => 1 ) );
	}
	T::same( 429, sai_lead( array( 'name' => 'Spam', 'email' => 'x@example.com', 'consent' => 1 ) )->get_status() );
} );

sai_save_settings( array( 'consent_required' => false ) );

T::test( 'an unanswerable question is flagged, hidden from the visitor and offers the lead form', function () {
	sai_reset_io();
	$heard = array();
	Events::listen( Events::QUESTION_UNANSWERED, function ( $p ) use ( &$heard ) { $heard[] = $p; } );

	FakeHttp::openai( "[NO_ANSWER] Sorry, I don't have information about wholesale prices." );
	$data = sai_chat( 'Do you sell wholesale?', '', 'unansweredvis001' )->get_data();

	T::same( "Sorry, I don't have information about wholesale prices.", $data['reply'], 'marker removed' );
	T::same( true, $data['unanswered'] );
	T::same( true, $data['offer_lead'], 'fallback mode offers the form' );
	T::same( array(), $data['sources'], 'no "related pages" for a non-answer' );
	T::same( 'Do you sell wholesale?', $heard[0]['question'] ?? null, 'question.unanswered emitted' );

	global $wpdb;
	T::same( '1', (string) $wpdb->get_var( 'SELECT unanswered FROM ' . Installer::tables()['messages'] . " WHERE role = 'assistant' ORDER BY id DESC LIMIT 1" ) );

	// Once the visitor leaves details in this chat, stop offering the form.
	sai_lead( array( 'name' => 'Karim', 'email' => 'karim@example.com', 'conversation_id' => $data['conversation_id'] ), 'unansweredvis001' );
	FakeHttp::openai( '[NO_ANSWER] I am not sure.' );
	$again = sai_chat( 'And bulk discounts?', $data['conversation_id'], 'unansweredvis001' )->get_data();
	T::same( false, $again['offer_lead'] );

	FakeHttp::openai( '**[NO_ANSWER]**  Not covered.' );
	T::same( 'Not covered.', sai_chat( 'x?', '', 'unansweredvis002' )->get_data()['reply'], 'bold-wrapped marker handled' );

	FakeHttp::openai( 'Delivery is 60 Taka.' );
	T::same( false, sai_chat( 'delivery?', '', 'unansweredvis003' )->get_data()['unanswered'] );
} );

T::test( 'new lead sends an owner email from the queue, after the response', function () {
	sai_reset_io();
	sai_save_settings( array( 'notify_email' => 'owner@example.com', 'notify_email_events' => array( Events::LEAD_CREATED, Events::HANDOFF_REQUESTED ) ) );

	sai_lead( array( 'name' => 'Salma', 'email' => 'salma@example.com', 'message' => 'Call me' ), 'mailvisitor00001' );
	T::same( array(), FakeMail::$sent, 'nothing sent during the request' );

	sai_run_queue();

	T::same( 1, count( FakeMail::$sent ) );
	$mail = FakeMail::$sent[0];
	T::same( 'owner@example.com', $mail['to'] );
	T::ok( str_contains( $mail['subject'], 'New lead: Salma' ), $mail['subject'] );
	T::ok( str_contains( $mail['message'], 'salma@example.com' ), 'details in body' );
	T::ok( in_array( 'Content-Type: text/html; charset=UTF-8', (array) $mail['headers'], true ), 'HTML email' );
} );

T::test( 'handoff requests send both lead and handoff alerts', function () {
	sai_reset_io();
	sai_lead( array( 'name' => 'Nadia', 'email' => 'nadia@example.com', 'source' => 'handoff', 'message' => 'Need a human' ), 'handoffvisit0001' );
	sai_run_queue();
	$subjects = array_column( FakeMail::$sent, 'subject' );
	T::same( 2, count( $subjects ) );
	T::ok( (bool) array_filter( $subjects, fn( $s ) => str_contains( $s, 'wants to talk to a person' ) ), 'handoff alert' );
} );

T::test( 'a failing mail server is retried and logged, not lost', function () {
	sai_reset_io();
	FakeMail::$fail = true;
	sai_lead( array( 'name' => 'Fail', 'email' => 'fail@example.com' ), 'failvisitor00001' );
	sai_run_queue();

	global $wpdb;
	$job = $wpdb->get_row( 'SELECT * FROM ' . Installer::tables()['jobs'] . " WHERE type = 'email.send'", ARRAY_A );
	T::same( 'pending', $job['status'], 'will retry' );
	T::ok( str_contains( $job['last_error'], 'SMTP connect() failed' ), 'real mail error kept: ' . $job['last_error'] );
} );

T::test( 'Telegram alerts use the saved token and chat id, never storing the token in jobs', function () {
	sai_reset_io();
	sai_save_settings( array( 'notify_email_events' => array(), 'telegram_key' => '123456:ABC-test-token', 'telegram_chat_id' => '987654', 'telegram_events' => array( Events::LEAD_CREATED ) ) );

	sai_lead( array( 'name' => 'Tariq', 'email' => 'tariq@example.com', 'phone' => '01711000000' ), 'tgvisitor0000001' );

	global $wpdb;
	$payloads = implode( '', $wpdb->get_col( 'SELECT payload FROM ' . Installer::tables()['jobs'] ) );
	T::ok( ! str_contains( $payloads, 'ABC-test-token' ), 'token not in the jobs table' );

	FakeHttp::push( 200, array( 'ok' => true, 'result' => array( 'message_id' => 1 ) ) );
	sai_run_queue();

	$req = FakeHttp::last();
	T::ok( str_contains( $req['url'], 'api.telegram.org/bot123456%3AABC-test-token/sendMessage' ), $req['url'] );
	T::same( '987654', $req['body']['chat_id'] );
	T::ok( str_contains( $req['body']['text'], 'Tariq' ) && str_contains( $req['body']['text'], 'New lead' ), 'lead in message' );

	sai_save_settings( array( 'telegram_events' => array() ) );
} );

T::test( 'webhooks are signed, carry the event, and retry on failure', function () {
	sai_reset_io();
	sai_save_settings( array(
		'webhook_urls'   => "https://hooks.example.com/a\nnot a url\nhttps://hooks.example.com/b",
		'webhook_events' => array( Events::LEAD_CREATED, 'made.up.event' ),
	) );

	T::same( "https://hooks.example.com/a\nhttps://hooks.example.com/b", Settings::get( 'webhook_urls' ), 'invalid URL dropped' );
	T::same( array( Events::LEAD_CREATED ), Settings::get( 'webhook_events' ), 'unknown event dropped' );
	T::same( 40, strlen( Webhooks::secret() ), 'secret generated' );

	sai_lead( array( 'name' => 'Webhook', 'email' => 'wh@example.com' ), 'whvisitor0000001' );

	FakeHttp::push( 200, 'ok' );
	FakeHttp::push( 500, 'boom' );
	sai_run_queue();

	T::same( 2, count( FakeHttp::$requests ), 'one delivery per URL' );
	$first = FakeHttp::$requests[0];
	T::same( Events::LEAD_CREATED, $first['headers']['X-AICB-Event'] );
	T::same( 'Webhook', $first['body']['lead']['name'] );
	$raw = wp_json_encode( $first['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	T::same( 'sha256=' . hash_hmac( 'sha256', $raw, Webhooks::secret() ), $first['headers']['X-AICB-Signature'], 'valid HMAC signature' );

	global $wpdb;
	$failed = $wpdb->get_row( 'SELECT * FROM ' . Installer::tables()['jobs'] . " WHERE type = 'webhook.deliver'", ARRAY_A );
	T::same( 'pending', $failed['status'], 'failing endpoint retried alone' );
	T::ok( str_contains( $failed['last_error'], 'HTTP 500' ), $failed['last_error'] );

	sai_save_settings( array( 'webhook_urls' => '' ) );
} );

T::test( 'webhooks refuse private network addresses', function () {
	$result = Webhooks::post( 'http://127.0.0.1:9/hook', 'test', 'x', array() );
	T::same( false, $result['ok'] );
} );

T::test( 'idle conversations end once, with transcript and lead, and reopen on a new message', function () {
	sai_reset_io();
	sai_save_settings( array( 'notify_email_events' => array( Events::CONVERSATION_ENDED ), 'transcript_to_visitor' => true ) );
	$ended = array();
	Events::listen( Events::CONVERSATION_ENDED, function ( $p ) use ( &$ended ) { $ended[] = $p; } );

	FakeHttp::openai( 'We deliver in 2 days.' );
	$chat = sai_chat( 'delivery time?', '', 'endvisitor000001' )->get_data();
	sai_lead( array( 'name' => 'End', 'email' => 'end@example.com', 'conversation_id' => $chat['conversation_id'] ), 'endvisitor000001' );

	global $wpdb;
	$t = Installer::tables();
	$wpdb->query( $wpdb->prepare( "UPDATE {$t['conversations']} SET updated_at = %s, ended_at = NULL", gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );

	ConversationCloser::run();
	ConversationCloser::run();

	$mine = array_values( array_filter( $ended, fn( $p ) => $p['conversation_id'] === $chat['conversation_id'] ) );
	T::same( 1, count( $mine ), 'ended exactly once' );
	T::same( 'end@example.com', $mine[0]['lead']['email'] ?? null );
	T::same( 'delivery time?', $mine[0]['transcript'][0]['content'] ?? null );

	sai_run_queue();
	$to = array_column( FakeMail::$sent, 'to' );
	T::ok( in_array( 'end@example.com', $to, true ), 'visitor got a transcript' );

	FakeHttp::openai( 'Anything else?' );
	sai_chat( 'one more question', $chat['conversation_id'], 'endvisitor000001' );
	T::same( null, $wpdb->get_var( $wpdb->prepare( "SELECT ended_at FROM {$t['conversations']} WHERE public_id = %s", $chat['conversation_id'] ) ), 'reopened' );

	sai_save_settings( array( 'notify_email_events' => array(), 'transcript_to_visitor' => false ) );
} );

T::test( 'privacy export and erase cover leads and their chats', function () {
	$export = PrivacyTools::export( 'end@example.com' );
	T::ok( count( $export['data'] ) >= 1, 'lead exported' );
	$json = wp_json_encode( $export['data'] );
	T::ok( str_contains( $json, 'delivery time?' ), 'chat included in export' );

	$erase = PrivacyTools::erase( 'end@example.com' );
	T::ok( $erase['items_removed'] >= 1 );
	T::same( array(), ( new LeadStore() )->by_email( 'end@example.com' ) );
	T::same( array(), PrivacyTools::export( 'end@example.com' )['data'] );
} );

T::test( 'CSV export neutralises spreadsheet formulas', function () {
	T::same( "'=HYPERLINK(\"x\")", LeadsPage::csv_safe( '=HYPERLINK("x")' ) );
	T::same( "'+8801711", LeadsPage::csv_safe( '+8801711' ) );
	T::same( 'Rahim', LeadsPage::csv_safe( 'Rahim' ) );
} );

T::test( 'leads screen and new settings tabs render', function () {
	wp_set_current_user( 1 );
	ob_start();
	LeadsPage::render();
	$html = ob_get_clean();
	T::ok( str_contains( $html, 'Karim' ) || str_contains( $html, 'Salma' ), 'leads listed' );

	foreach ( array( 'leads', 'notify', 'integrations' ) as $tab ) {
		$_GET['tab'] = $tab;
		ob_start();
		SettingsPage::render();
		T::ok( str_contains( ob_get_clean(), 'value="' . $tab . '"' ), "$tab tab renders" );
	}
	unset( $_GET['tab'] );
} );

sai_reset_io();
