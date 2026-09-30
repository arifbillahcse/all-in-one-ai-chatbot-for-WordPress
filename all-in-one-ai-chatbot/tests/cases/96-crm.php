<?php
// phpcs:disable
/**
 * Phase 7: HubSpot, Mailchimp and Brevo.
 */

use Softorio\AiAssistant\Admin\LeadsPage;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Crm\CrmClient;
use Softorio\AiAssistant\Crm\CrmSync;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Leads\LeadStore;
use Softorio\AiAssistant\Settings;

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'visitor_hourly_limit' => 1000, 'notify_email_events' => array() ) );
add_filter( 'softorio_ai_queue_run_on_shutdown', '__return_false' );

/** A lead with a short chat attached. */
function sai_crm_lead( array $fields = array() ): int {
	$store  = new ConversationStore();
	$thread = $store->resume_or_create( '', 'crmvisitor' . wp_rand( 100000, 999999 ), home_url( '/pricing/' ) );
	$store->add_exchange( $thread['id'], 'Do you ship to Chittagong?', 'Yes, in 3–5 days.', array(), array() );

	$id = ( new LeadStore() )->create(
		array_merge(
			array(
				'conversation_id' => $thread['id'],
				'name'            => 'Rahim Uddin Ahmed',
				'email'           => 'Rahim@Example.com',
				'phone'           => '01711-000000',
				'message'         => 'Please call me about the Gold plan.',
				'source'          => 'handoff',
				'consent'         => 1,
				'page_url'        => home_url( '/pricing/' ),
			),
			$fields
		)
	);
	$store->set_lead( $thread['id'], $id );

	// As the lead form does.
	\Softorio\AiAssistant\Support\Events::emit( \Softorio\AiAssistant\Support\Events::LEAD_CREATED, \Softorio\AiAssistant\Leads\LeadService::payload( $id, $thread['public_id'] ) );

	return $id;
}

function sai_crm_state( int $lead, string $crm ): array {
	return LeadStore::crm_state( ( new LeadStore() )->find( $lead ) )[ $crm ] ?? array();
}

function sai_crm_jobs( string $status = '' ): array {
	global $wpdb;
	$t = Installer::tables()['jobs'];
	return '' === $status ? $wpdb->get_results( "SELECT * FROM $t WHERE type = 'crm.sync'", ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE type = 'crm.sync' AND status = %s", $status ), ARRAY_A );
}

function sai_clear_jobs(): void {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
}

function sai_due_now(): void {
	global $wpdb;
	$wpdb->query( 'UPDATE ' . Installer::tables()['jobs'] . " SET run_at = 0 WHERE status = 'pending'" );
}

T::test( 'CRMs are off by default: leads go nowhere', function () {
	sai_clear_jobs();
	T::same( array(), CrmSync::enabled() );
	sai_crm_lead();
	T::same( array(), sai_crm_jobs() );
} );

T::test( 'phone numbers and names', function () {
	T::same( '+8801711000000', CrmClient::e164( '01711-000000' ), 'Bangladesh local' );
	T::same( '+8801711000000', CrmClient::e164( '+880 1711 000000' ) );
	T::same( '+447700900123', CrmClient::e164( '0044 7700 900123' ), '00 prefix' );
	T::same( '', CrmClient::e164( '12' ), 'too short' );
	T::same( '', CrmClient::e164( '' ) );
	sai_save_settings( array( 'crm_country_code' => '' ) );
	T::same( '', CrmClient::e164( '01711000000' ), 'no country code to add' );
	sai_save_settings( array( 'crm_country_code' => '+44' ) );
	T::same( '+447700900123', CrmClient::e164( '07700 900123' ), 'UK trunk zero' );
	sai_save_settings( array( 'crm_country_code' => '880' ) );

	T::same( array( 'Rahim', 'Uddin Ahmed' ), CrmClient::split_name( '  Rahim   Uddin Ahmed ' ) );
	T::same( array( 'Karim', '' ), CrmClient::split_name( 'Karim' ) );
	T::same( array( '', '' ), CrmClient::split_name( '' ) );
} );

// ── HubSpot ─────────────────────────────────────────────────────────────────

sai_save_settings( array( 'hubspot_enabled' => true, 'hubspot_key' => 'pat-na1-secret-token' ) );

T::test( 'HubSpot: new contact with a note carrying the chat', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();

	T::same( array( 'hubspot' ), array_keys( CrmSync::enabled() ) );
	T::same( 'pending', sai_crm_state( $lead, 'hubspot' )['status'] ?? '', 'queued as soon as the lead exists' );

	FakeHttp::push( 201, array( 'id' => '501' ) );
	FakeHttp::push( 201, array( 'id' => '9001' ) );
	sai_run_queue();

	$create = FakeHttp::$requests[0];
	T::same( 'POST', $create['method'] );
	T::same( 'https://api.hubapi.com/crm/v3/objects/contacts', $create['url'] );
	T::same( 'Bearer pat-na1-secret-token', $create['headers']['Authorization'] );
	T::same(
		array( 'email' => 'Rahim@Example.com', 'firstname' => 'Rahim', 'lastname' => 'Uddin Ahmed', 'phone' => '+8801711000000', 'message' => 'Please call me about the Gold plan.', 'lifecyclestage' => 'lead' ),
		$create['body']['properties']
	);

	$note = FakeHttp::$requests[1];
	T::same( 'https://api.hubapi.com/crm/v3/objects/notes', $note['url'] );
	T::same( '501', $note['body']['associations'][0]['to']['id'] );
	T::same( 202, $note['body']['associations'][0]['types'][0]['associationTypeId'] );
	T::ok( str_contains( $note['body']['properties']['hs_note_body'], 'Do you ship to Chittagong?' ), 'chat in the note' );

	$state = sai_crm_state( $lead, 'hubspot' );
	T::same( 'sent', $state['status'] );
	T::same( '501', $state['ref'] );
	T::same( array(), sai_crm_jobs(), 'job done' );
} );

T::test( 'HubSpot: existing contact updated, lifecycle stage left alone', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();

	FakeHttp::push( 409, array( 'status' => 'error', 'message' => 'Contact already exists. Existing ID: 777', 'category' => 'CONFLICT' ) );
	FakeHttp::push( 200, array( 'id' => '777' ) );
	FakeHttp::push( 201, array( 'id' => '9002' ) );
	sai_run_queue();

	T::same( 'PATCH', FakeHttp::$requests[1]['method'] );
	T::same( 'https://api.hubapi.com/crm/v3/objects/contacts/777', FakeHttp::$requests[1]['url'] );
	T::ok( ! isset( FakeHttp::$requests[1]['body']['properties']['lifecyclestage'] ), 'a customer never becomes a lead again' );
	T::same( '777', sai_crm_state( $lead, 'hubspot' )['ref'] );
} );

T::test( 'HubSpot: portals missing a property still get the contact', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	sai_save_settings( array( 'hubspot_note' => false ) );
	$lead = sai_crm_lead();

	FakeHttp::push( 400, array( 'status' => 'error', 'message' => 'Property values were not valid: "message" does not exist', 'category' => 'VALIDATION_ERROR' ) );
	FakeHttp::push( 201, array( 'id' => '502' ) );
	sai_run_queue();

	T::same( 2, count( FakeHttp::$requests ), 'retried once, no note' );
	T::same( array( 'email', 'firstname', 'lastname' ), array_keys( FakeHttp::$requests[1]['body']['properties'] ) );
	T::same( 'sent', sai_crm_state( $lead, 'hubspot' )['status'] );
	sai_save_settings( array( 'hubspot_note' => true ) );
} );

T::test( 'HubSpot: wrong token fails without retries; outages are retried', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();

	FakeHttp::push( 401, array( 'status' => 'error', 'message' => 'Authentication credentials not found.' ) );
	sai_run_queue();
	$state = sai_crm_state( $lead, 'hubspot' );
	T::same( 'failed', $state['status'] );
	T::ok( str_contains( $state['detail'], 'refused' ), $state['detail'] );
	T::same( array(), sai_crm_jobs(), 'no pointless retries' );

	$lead = sai_crm_lead();
	FakeHttp::reset();
	FakeHttp::push( 503, 'Service Unavailable' );
	sai_run_queue();
	T::same( 'retrying', sai_crm_state( $lead, 'hubspot' )['status'] );
	T::same( 1, count( sai_crm_jobs( 'pending' ) ), 'waiting for a retry' );

	FakeHttp::push( 201, array( 'id' => '503' ) );
	FakeHttp::push( 201, array( 'id' => '9003' ) );
	sai_due_now();
	sai_run_queue();
	T::same( 'sent', sai_crm_state( $lead, 'hubspot' )['status'], 'sent on the retry' );

	FakeHttp::reset();
	FakeHttp::push( 429, array( 'message' => 'Too many requests' ) );
	$lead = sai_crm_lead();
	sai_run_queue();
	T::same( 'retrying', sai_crm_state( $lead, 'hubspot' )['status'], 'rate limits are retried' );
	sai_clear_jobs();
} );

T::test( 'HubSpot: nothing to send → skipped; deleted lead → dropped', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead( array( 'email' => '', 'phone' => '' ) );
	sai_run_queue();
	T::same( 'skipped', sai_crm_state( $lead, 'hubspot' )['status'] );
	T::same( array(), FakeHttp::$requests );

	$lead = sai_crm_lead();
	( new LeadStore() )->delete( $lead );
	sai_run_queue();
	T::same( array(), FakeHttp::$requests, 'erased leads are not sent' );
	T::same( array(), sai_crm_jobs() );
} );

T::test( 'HubSpot: test button and custom fields filter', function () {
	FakeHttp::reset();
	FakeHttp::push( 200, array( 'results' => array() ) );
	T::ok( str_contains( CrmSync::clients()['hubspot']->test(), 'Connected' ) );
	T::same( 'https://api.hubapi.com/crm/v3/objects/contacts?limit=1', FakeHttp::last()['url'] );

	FakeHttp::reset();
	FakeHttp::push( 401, array( 'message' => 'bad token' ) );
	try {
		CrmSync::clients()['hubspot']->test();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::ok( str_contains( $e->getMessage(), 'refused' ) );
	}

	sai_clear_jobs();
	FakeHttp::reset();
	add_filter( 'softorio_ai_crm_fields', $f = static function ( array $fields, array $lead, string $crm ) {
		if ( 'hubspot' === $crm ) {
			$fields['lead_source_detail'] = 'Website chat';
		}
		return $fields;
	}, 10, 3 );
	sai_crm_lead();
	FakeHttp::push( 201, array( 'id' => '504' ) );
	FakeHttp::push( 201, array( 'id' => '9004' ) );
	sai_run_queue();
	remove_filter( 'softorio_ai_crm_fields', $f, 10 );
	T::same( 'Website chat', FakeHttp::$requests[0]['body']['properties']['lead_source_detail'] );
} );

sai_save_settings( array( 'hubspot_enabled' => false ) );

// ── Mailchimp ───────────────────────────────────────────────────────────────

sai_save_settings( array( 'mailchimp_enabled' => true, 'mailchimp_key' => 'abc123def-us21', 'mailchimp_list' => 'L1', 'mailchimp_tags' => 'AI Chatbot, Chat lead' ) );

T::test( 'Mailchimp: member added (double opt-in), tagged, noted', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();
	FakeHttp::push( 200, array( 'id' => 'm1' ) );
	FakeHttp::push( 204, '' );
	FakeHttp::push( 200, array( 'id' => 1 ) );
	sai_run_queue();

	$member = 'https://us21.api.mailchimp.com/3.0/lists/L1/members/' . md5( 'rahim@example.com' );
	T::same( 'PUT', FakeHttp::$requests[0]['method'] );
	T::same( $member, FakeHttp::$requests[0]['url'], 'member hash of the lower-case email' );
	T::same( 'Basic ' . base64_encode( 'softorio:abc123def-us21' ), FakeHttp::$requests[0]['headers']['Authorization'] );
	T::same( 'pending', FakeHttp::$requests[0]['body']['status_if_new'], 'double opt-in by default' );
	T::same( array( 'FNAME' => 'Rahim', 'LNAME' => 'Uddin Ahmed', 'PHONE' => '01711-000000' ), FakeHttp::$requests[0]['body']['merge_fields'] );
	T::same( $member . '/tags', FakeHttp::$requests[1]['url'] );
	T::same( array( 'AI Chatbot', 'Chat lead' ), array_column( FakeHttp::$requests[1]['body']['tags'], 'name' ) );
	T::same( $member . '/notes', FakeHttp::$requests[2]['url'] );
	T::ok( mb_strlen( FakeHttp::$requests[2]['body']['note'] ) <= 1000 );
	T::same( 'sent', sai_crm_state( $lead, 'mailchimp' )['status'] );
} );

T::test( 'Mailchimp: audiences without PHONE, no email, bad key, no audience', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	sai_save_settings( array( 'mailchimp_note' => false, 'mailchimp_tags' => '' ) );
	$lead = sai_crm_lead();
	FakeHttp::push( 400, array( 'title' => 'Invalid Resource', 'status' => 400, 'detail' => 'Your merge fields were invalid.' ) );
	FakeHttp::push( 200, array( 'id' => 'm2' ) );
	sai_run_queue();
	T::ok( ! isset( FakeHttp::$requests[1]['body']['merge_fields'] ), 'retried without merge fields' );
	T::same( 'sent', sai_crm_state( $lead, 'mailchimp' )['status'] );

	FakeHttp::reset();
	$lead = sai_crm_lead( array( 'email' => '' ) );
	sai_run_queue();
	T::same( 'skipped', sai_crm_state( $lead, 'mailchimp' )['status'] );
	T::same( 'no email address', sai_crm_state( $lead, 'mailchimp' )['detail'] );

	sai_save_settings( array( 'mailchimp_key' => 'abc123def' ) );
	$lead = sai_crm_lead();
	sai_run_queue();
	T::ok( str_contains( sai_crm_state( $lead, 'mailchimp' )['detail'], 'data centre' ) );

	sai_save_settings( array( 'mailchimp_key' => 'abc123def-us21', 'mailchimp_list' => '' ) );
	$lead = sai_crm_lead();
	sai_run_queue();
	T::same( 'failed', sai_crm_state( $lead, 'mailchimp' )['status'] );
	T::same( array(), FakeHttp::$requests, 'nothing sent without an audience' );
} );

T::test( 'Mailchimp: test button lists audiences and picks the only one', function () {
	FakeHttp::reset();
	FakeHttp::push( 200, array( 'lists' => array( array( 'id' => 'ONLY1', 'name' => 'Dhaka Gadget Shop' ) ) ) );
	T::ok( str_contains( CrmSync::clients()['mailchimp']->test(), 'Dhaka Gadget Shop' ) );
	T::same( 'ONLY1', Settings::get( 'mailchimp_list' ), 'saved' );

	FakeHttp::push( 200, array( 'lists' => array( array( 'id' => 'A', 'name' => 'Shop' ), array( 'id' => 'B', 'name' => 'Blog' ) ) ) );
	sai_save_settings( array( 'mailchimp_list' => 'nope' ) );
	try {
		CrmSync::clients()['mailchimp']->test();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::ok( str_contains( $e->getMessage(), 'Shop (A), Blog (B)' ), $e->getMessage() );
	}
} );

sai_save_settings( array( 'mailchimp_enabled' => false ) );

// ── Brevo ───────────────────────────────────────────────────────────────────

sai_save_settings( array( 'brevo_enabled' => true, 'brevo_key' => 'xkeysib-123', 'brevo_lists' => '2, 7 ,x' ) );

T::test( 'Brevo: contact with lists and SMS number', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();
	FakeHttp::push( 201, array( 'id' => 42 ) );
	sai_run_queue();

	$r = FakeHttp::$requests[0];
	T::same( 'https://api.brevo.com/v3/contacts', $r['url'] );
	T::same( 'xkeysib-123', $r['headers']['api-key'] );
	T::same( 'rahim@example.com', $r['body']['email'] );
	T::same( array( 'FIRSTNAME' => 'Rahim', 'LASTNAME' => 'Uddin Ahmed', 'SMS' => '+8801711000000' ), $r['body']['attributes'] );
	T::same( array( 2, 7 ), $r['body']['listIds'] );
	T::same( true, $r['body']['updateEnabled'], 'updates existing contacts' );
	T::same( '42', sai_crm_state( $lead, 'brevo' )['ref'] );

	FakeHttp::reset();
	FakeHttp::push( 204, '' );
	$lead = sai_crm_lead();
	sai_run_queue();
	T::same( 'sent', sai_crm_state( $lead, 'brevo' )['status'], 'update answers 204' );
} );

T::test( 'Brevo: bad or taken number dropped; phone-only leads', function () {
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();
	FakeHttp::push( 400, array( 'code' => 'duplicate_parameter', 'message' => 'Unable to create contact, SMS is already associated with another Contact' ) );
	FakeHttp::push( 201, array( 'id' => 43 ) );
	sai_run_queue();
	T::ok( ! isset( FakeHttp::$requests[1]['body']['attributes']['SMS'] ) );
	T::same( 'sent', sai_crm_state( $lead, 'brevo' )['status'] );

	FakeHttp::reset();
	$lead = sai_crm_lead( array( 'email' => '' ) );
	FakeHttp::push( 201, array( 'id' => 44 ) );
	sai_run_queue();
	T::ok( ! isset( FakeHttp::$requests[0]['body']['email'] ), 'no email sent' );
	T::same( '+8801711000000', FakeHttp::$requests[0]['body']['attributes']['SMS'] );

	FakeHttp::reset();
	$lead = sai_crm_lead( array( 'email' => '', 'phone' => '12' ) );
	sai_run_queue();
	T::same( 'skipped', sai_crm_state( $lead, 'brevo' )['status'] );
} );

T::test( 'Brevo: test button names the account and lists', function () {
	sai_save_settings( array( 'brevo_lists' => '2' ) );
	FakeHttp::reset();
	FakeHttp::push( 200, array( 'companyName' => 'Dhaka Gadget Shop', 'email' => 'owner@example.com' ) );
	FakeHttp::push( 200, array( 'id' => 2, 'name' => 'Chat leads' ) );
	$msg = CrmSync::clients()['brevo']->test();
	T::ok( str_contains( $msg, 'Dhaka Gadget Shop' ) && str_contains( $msg, 'Chat leads' ), $msg );

	FakeHttp::push( 200, array( 'companyName' => 'X' ) );
	FakeHttp::push( 404, array( 'code' => 'document_not_found', 'message' => 'List not found' ) );
	try {
		CrmSync::clients()['brevo']->test();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::ok( str_contains( $e->getMessage(), 'list 2 was not found' ) );
	}
} );

// ── Together ────────────────────────────────────────────────────────────────

T::test( 'several CRMs: one job each, a failure stays with its CRM', function () {
	sai_save_settings( array( 'hubspot_enabled' => true, 'mailchimp_enabled' => true, 'mailchimp_list' => 'L1', 'hubspot_note' => false ) );
	sai_clear_jobs();
	FakeHttp::reset();
	$lead = sai_crm_lead();
	T::same( 3, count( sai_crm_jobs() ) );

	// Jobs run in id order: hubspot, mailchimp, brevo.
	FakeHttp::push( 401, array( 'message' => 'nope' ) );
	FakeHttp::push( 200, array( 'id' => 'm' ) );
	FakeHttp::push( 201, array( 'id' => 45 ) );
	sai_run_queue();

	T::same( 'failed', sai_crm_state( $lead, 'hubspot' )['status'] );
	T::same( 'sent', sai_crm_state( $lead, 'mailchimp' )['status'] );
	T::same( 'sent', sai_crm_state( $lead, 'brevo' )['status'] );
} );

T::test( 'consent rule, and the lead form triggers everything', function () {
	sai_save_settings( array( 'crm_require_consent' => true, 'mailchimp_enabled' => false, 'hubspot_enabled' => false ) );
	sai_clear_jobs();
	$lead = sai_crm_lead( array( 'consent' => 0 ) );
	T::same( 'skipped', sai_crm_state( $lead, 'brevo' )['status'] );
	T::same( 'no consent', sai_crm_state( $lead, 'brevo' )['detail'] );
	T::same( array(), sai_crm_jobs() );
	sai_save_settings( array( 'crm_require_consent' => false ) );

	sai_save_settings( array( 'leads_mode' => 'fallback', 'lead_name' => 'optional', 'lead_email' => 'required', 'lead_phone' => 'optional', 'consent_required' => false ) );
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	$request = new WP_REST_Request( 'POST', '/softorio-ai/v1/lead' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array( 'visitor_token' => 'formvisitor00001', 'name' => 'Karim', 'email' => 'karim@example.com', 'page_url' => home_url( '/' ) ) ) );
	$status = rest_do_request( $request )->get_status();
	T::ok( in_array( $status, array( 200, 201 ), true ), 'lead saved: ' . $status );
	$jobs = sai_crm_jobs();
	T::ok( in_array( 'crm.sync', array_column( $jobs, 'type' ), true ), 'lead.created queues the CRM job' );
	sai_clear_jobs();
} );

T::test( 'Leads screen: badges, send again, send unsent', function () {
	$stop = static function () {
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $stop );
	wp_set_current_user( 1 );
	sai_clear_jobs();

	$failed = sai_crm_lead();
	CrmSync::record( $failed, 'brevo', 'failed', 'Brevo: HTTP 400 — Invalid email' );
	$sent = sai_crm_lead();
	CrmSync::record( $sent, 'brevo', 'sent', '', '99' );
	sai_clear_jobs();

	ob_start();
	LeadsPage::render();
	$html = (string) ob_get_clean();
	T::ok( str_contains( $html, 'sai-crm-failed' ) && str_contains( $html, 'Invalid email' ), 'failure and reason shown' );
	T::ok( str_contains( $html, '✓ Brevo' ) );
	T::ok( str_contains( $html, 'Send unsent leads to Brevo' ) );

	$_POST = $_REQUEST = array( 'lead' => $failed, '_wpnonce' => wp_create_nonce( 'softorio_ai_lead_crm' ) );
	try {
		LeadsPage::send_to_crm();
	} catch ( RuntimeException $e ) {
	}
	T::same( 1, count( sai_crm_jobs() ), 'one lead re-queued' );
	sai_clear_jobs();
	// That job was dropped (queue cleared) an hour ago: its "pending" mark is stale.
	( new LeadStore() )->set_crm( $failed, 'brevo', array( 'status' => 'pending', 'at' => time() - 2 * HOUR_IN_SECONDS ) );

	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'softorio_ai_lead_crm' ) );
	try {
		LeadsPage::send_to_crm();
	} catch ( RuntimeException $e ) {
	}
	$queued = array_map( static fn( $j ) => json_decode( $j['payload'], true )['lead'], sai_crm_jobs() );
	T::ok( in_array( $failed, $queued, true ), 'failed lead re-sent' );
	T::ok( ! in_array( $sent, $queued, true ), 'sent lead left alone' );

	$_POST = $_REQUEST = array( '_wpnonce' => 'bad' );
	add_filter( 'wp_die_handler', $die = static fn() => static function () {
		throw new RuntimeException( 'died' );
	} );
	try {
		LeadsPage::send_to_crm();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::same( 'died', $e->getMessage() );
	}
	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_redirect', $stop );
	$_POST = $_REQUEST = array();
	wp_set_current_user( 0 );
	sai_clear_jobs();
} );

T::test( 'Integrations settings tab', function () {
	wp_set_current_user( 1 );
	$_GET['tab'] = 'integrations';
	ob_start();
	\Softorio\AiAssistant\Admin\SettingsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );
	foreach ( array( 'hubspot_key', 'hubspot_lifecycle', 'mailchimp_key', 'mailchimp_list', 'mailchimp_status', 'brevo_key', 'brevo_lists', 'crm_require_consent', 'crm_country_code' ) as $key ) {
		T::ok( str_contains( $html, '[' . $key . ']' ), $key );
	}
	T::same( 3, substr_count( $html, 'data-kind="hubspot"' ) + substr_count( $html, 'data-kind="mailchimp"' ) + substr_count( $html, 'data-kind="brevo"' ), 'test buttons' );
	T::ok( ! str_contains( $html, 'xkeysib-123' ), 'keys never printed' );
} );

remove_all_filters( 'softorio_ai_queue_run_on_shutdown' );
sai_clear_jobs();
sai_reset();
