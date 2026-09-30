<?php
// phpcs:disable
/**
 * Phase 0: settings schema, upgrades, events, queue and log.
 */

use Softorio\AiAssistant\Admin\SettingsPage;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\SettingsSchema;
use Softorio\AiAssistant\Support\Events;
use Softorio\AiAssistant\Support\Log;
use Softorio\AiAssistant\Support\Queue;

sai_reset();

function sai_jobs(): array {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Installer::tables()['jobs'] . ' ORDER BY id', ARRAY_A );
}

function sai_clear_queue(): void {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['logs'] );
	delete_option( 'softorio_ai_queue_lock' );
}

T::test( 'upgrading an existing install adds new tables and keeps data', function () {
	global $wpdb;
	$t = Installer::tables();

	$wpdb->insert( $t['conversations'], array( 'public_id' => str_repeat( 'a', 32 ), 'visitor_hash' => 'x', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
	$wpdb->query( "DROP TABLE {$t['jobs']}" );
	$wpdb->query( "DROP TABLE {$t['logs']}" );
	update_option( 'softorio_ai_db_version', '1' );

	Installer::maybe_upgrade();

	T::same( Installer::DB_VERSION, get_option( 'softorio_ai_db_version' ) );
	T::ok( null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$t['jobs']}" ), 'jobs table created' );
	T::ok( null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$t['logs']}" ), 'logs table created' );
	T::same( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['conversations']}" ), 'existing conversations kept' );
} );

T::test( 'every schema field has a valid tab, a type and a default', function () {
	$tabs = SettingsSchema::tabs();
	foreach ( SettingsSchema::fields() as $key => $field ) {
		T::ok( isset( $tabs[ $field['tab'] ] ), "$key is on a real tab" );
		T::ok( isset( $field['type'], $field['label'] ), "$key has type and label" );
		T::ok( array_key_exists( 'default', $field ), "$key has a default" );
	}
	T::same( array_keys( SettingsSchema::fields() ), array_keys( Settings::defaults() ) );
} );

T::test( 'saving one tab never changes settings on another tab', function () {
	sai_save_settings( array( 'enabled' => true, 'show_sources' => true, 'assistant_name' => 'Rina', 'openai_key' => 'sk-keep-this-key-1' ) );

	// The Widget tab form, with its "show sources" box unticked (absent).
	update_option( Settings::OPTION, SettingsPage::sanitize( array( '_tab' => 'widget', 'color' => '#ff0000', 'position' => 'left' ) ) );

	T::same( false, Settings::get( 'show_sources' ), 'unticked box on the saved tab is off' );
	T::same( '#ff0000', Settings::get( 'color' ) );
	T::same( true, Settings::get( 'enabled' ), 'checkbox on another tab untouched' );
	T::same( 'Rina', Settings::get( 'assistant_name' ), 'text on another tab untouched' );
	T::same( 'sk-keep-this-key-1', Settings::api_key( 'openai' ), 'API key untouched' );
} );

T::test( 'field types are validated', function () {
	$out = SettingsPage::sanitize( array(
		'_tab'              => 'limits',
		'visitor_hourly_limit' => '99999',
		'daily_budget'      => '-5',
		'retention_days'    => 'abc',
	) );
	T::same( 1000, $out['visitor_hourly_limit'], 'number clamped to max' );
	T::same( 0.0, $out['daily_budget'], 'float clamped to min' );
	T::same( 0, $out['retention_days'], 'junk becomes a number' );

	$out = SettingsPage::sanitize( array( '_tab' => 'ai', 'provider' => 'evil', 'openai_model' => 'gpt-5<script>' ) );
	T::same( 'openai', $out['provider'], 'unknown option falls back to default' );
	T::same( 'gpt-5script', $out['openai_model'], 'model id stripped to safe characters' );

	$out = SettingsPage::sanitize( array( '_tab' => 'ai', 'openai_key_clear' => '1' ) );
	T::same( '', $out['openai_key'], '"remove saved key" clears it' );
} );

T::test( 'every settings tab renders without errors', function () {
	wp_set_current_user( 1 );
	foreach ( array_keys( SettingsSchema::tabs() ) as $tab ) {
		$_GET['tab'] = $tab;
		ob_start();
		SettingsPage::render();
		$html = ob_get_clean();
		T::ok( str_contains( $html, 'name="softorio_ai_settings[_tab]" value="' . $tab . '"' ), "$tab form rendered" );
		T::ok( ! str_contains( $html, 'sk-keep' ), "$tab never prints a saved key" );
	}
	unset( $_GET['tab'] );
} );

T::test( 'events reach listeners; a failing listener does not stop the others', function () {
	sai_clear_queue();
	$heard = array();

	Events::listen( 'test.event', function () {
		throw new RuntimeException( 'integration down' );
	} );
	Events::listen( 'test.event', function ( array $payload ) use ( &$heard ) {
		$heard[] = $payload;
	} );

	Events::emit( 'test.event', array( 'lead' => 7 ) );

	T::same( 1, count( $heard ), 'second listener still ran' );
	T::same( 7, $heard[0]['lead'] );
	T::same( 'test.event', $heard[0]['event'] );
	T::ok( isset( $heard[0]['occurred_at'], $heard[0]['site'] ), 'standard fields added' );
	T::same( 'error', Log::recent( 1 )[0]['level'] ?? null, 'failure logged' );
} );

T::test( 'queued jobs run and are removed on success', function () {
	sai_clear_queue();
	$ran = array();
	Queue::register( 'test.ok', function ( array $p ) use ( &$ran ) {
		$ran[] = $p['n'];
	} );

	Queue::push( 'test.ok', array( 'n' => 1 ) );
	Queue::push( 'test.ok', array( 'n' => 2 ) );
	Queue::push( 'test.ok', array( 'n' => 3 ), 3600 );

	T::same( 2, Queue::run() );
	T::same( array( 1, 2 ), $ran, 'due jobs ran in order' );
	T::same( 1, count( sai_jobs() ), 'delayed job still waiting' );
} );

T::test( 'failing jobs retry with backoff, then fail visibly', function () {
	sai_clear_queue();
	$calls = 0;
	Queue::register( 'test.fail', function () use ( &$calls ) {
		++$calls;
		throw new RuntimeException( 'SMTP refused' );
	} );

	Queue::push( 'test.fail', array() );
	Queue::run();

	$job = sai_jobs()[0];
	T::same( 'pending', $job['status'] );
	T::same( 1, (int) $job['attempts'] );
	T::ok( (int) $job['run_at'] >= time() + 50, 'retry scheduled about a minute later' );
	T::same( 'SMTP refused', $job['last_error'] );

	global $wpdb;
	for ( $i = 0; $i < 6; $i++ ) {
		$wpdb->query( 'UPDATE ' . Installer::tables()['jobs'] . ' SET run_at = 0' );
		Queue::run();
	}

	$job = sai_jobs()[0];
	T::same( 'failed', $job['status'] );
	T::same( 5, $calls, 'gave up after 5 attempts' );
	T::same( 1, Queue::counts()['failed'] );
	T::ok( str_contains( Log::recent( 1, 'error' )[0]['message'] ?? '', 'Gave up on test.fail' ), 'final failure in the log' );

	T::same( 1, Queue::retry_failed() );
	T::same( 'pending', sai_jobs()[0]['status'], 'retry button re-queues it' );
} );

T::test( 'only one queue runner at a time, and a stale lock is recovered', function () {
	sai_clear_queue();
	$ran = 0;
	Queue::register( 'test.count', function () use ( &$ran ) {
		++$ran;
	} );
	Queue::push( 'test.count', array() );

	add_option( 'softorio_ai_queue_lock', time() );
	T::same( 0, Queue::run(), 'fresh lock blocks a second runner' );

	update_option( 'softorio_ai_queue_lock', time() - 600 );
	T::same( 1, Queue::run(), 'stale lock taken over' );
	T::ok( false === get_option( 'softorio_ai_queue_lock' ), 'lock released' );
} );

T::test( 'jobs for a switched-off feature fail at once instead of retrying', function () {
	sai_clear_queue();
	Queue::push( 'feature.that.is.off', array() );
	Queue::run();
	T::same( 'failed', sai_jobs()[0]['status'] );
} );

T::test( 'log redacts secrets in context', function () {
	sai_clear_queue();
	Log::info( 'test', 'hello', array( 'api_key' => 'sk-123', 'nested' => array( 'Authorization' => 'Bearer x', 'ok' => 'visible' ) ) );
	$ctx = Log::recent( 1 )[0]['context'];
	T::ok( ! str_contains( $ctx, 'sk-123' ) && ! str_contains( $ctx, 'Bearer' ), 'secrets removed' );
	T::ok( str_contains( $ctx, 'visible' ), 'other context kept' );
} );

T::test( 'answering a message emits message.answered', function () {
	sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123' ) );
	$got = null;
	Events::listen( Events::MESSAGE_ANSWERED, function ( array $p ) use ( &$got ) {
		$got = $p;
	} );

	FakeHttp::openai( 'Hello there.' );
	$data = sai_chat( 'hello', '', 'eventvisitor0001' )->get_data();

	T::same( $data['conversation_id'], $got['conversation_id'] ?? null );
	T::same( 'hello', $got['question'] ?? null );
	T::same( 'Hello there.', $got['answer'] ?? null );
} );

sai_clear_queue();
