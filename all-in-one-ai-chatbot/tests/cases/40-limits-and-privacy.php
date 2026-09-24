<?php
// phpcs:disable
/**
 * Abuse and cost controls, retention and uninstall safety.
 */

use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Installer;

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123' ) );

T::test( 'per-visitor hourly limit returns 429 with Retry-After, before calling the AI', function () {
	sai_save_settings( array( 'visitor_hourly_limit' => 2 ) );

	FakeHttp::openai( 'one' );
	FakeHttp::openai( 'two' );

	T::same( 200, sai_chat( 'first' )->get_status() );
	T::same( 200, sai_chat( 'second' )->get_status() );

	$calls = count( FakeHttp::$requests );
	$third = sai_chat( 'third' );

	T::same( 429, $third->get_status() );
	T::same( 'rate_limited', $third->get_data()['code'] );
	T::ok( (int) ( $third->get_headers()['Retry-After'] ?? 0 ) > 0, 'Retry-After header' );
	T::same( $calls, count( FakeHttp::$requests ), 'no provider call for a limited request' );
} );

T::test( 'the limit follows the network, not the browser token', function () {
	T::same( 429, sai_chat( 'new token same ip', '', 'freshtoken000000' )->get_status() );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
	FakeHttp::openai( 'hi' );
	T::same( 200, sai_chat( 'different visitor' )->get_status() );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
} );

T::test( 'a spoofed Cloudflare header is ignored unless the owner enabled it', function () {
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
	T::same( '203.0.113.10', \Softorio\AiAssistant\Support\Visitor::ip() );

	sai_save_settings( array( 'trust_cloudflare' => true, 'visitor_hourly_limit' => 2 ) );
	T::same( '198.51.100.77', \Softorio\AiAssistant\Support\Visitor::ip() );

	unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	sai_save_settings( array( 'trust_cloudflare' => false ) );
} );

T::test( 'site-wide daily answer cap stops further answers', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	delete_transient( 'softorio_ai_usage_today' );

	sai_save_settings( array( 'visitor_hourly_limit' => 100, 'daily_message_cap' => 3 ) );

	// Three answers exist already from the earlier tests in this file.
	$r = sai_chat( 'anything', '', 'capvisitor000000' );
	T::same( 429, $r->get_status() );
	T::same( 'daily_limit', $r->get_data()['code'] );
} );

T::test( 'daily budget stops answers once estimated spend reaches it', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	delete_transient( 'softorio_ai_usage_today' );

	sai_save_settings( array( 'daily_message_cap' => 0, 'daily_budget' => 0.01 ) );

	// One expensive answer: 10k output tokens on gpt-5-mini ≈ $0.02.
	FakeHttp::openai( 'long answer', 1000, 10000 );
	T::same( 200, sai_chat( 'expensive', '', 'budgetvisitor000' )->get_status() );
	T::same( 429, sai_chat( 'again', '', 'budgetvisitor000' )->get_status() );

	sai_save_settings( array( 'daily_budget' => 0 ) );
} );

T::test( 'old conversations are purged after the retention period', function () {
	global $wpdb;
	$t = Installer::tables();

	$wpdb->query( $wpdb->prepare( "UPDATE {$t['conversations']} SET updated_at = %s", gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['conversations']}" );

	T::ok( $before > 0, 'there are conversations to purge' );
	T::same( $before, ( new ConversationStore() )->purge_older_than( 30 ) );
	T::same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['messages']}" ), 'messages removed too' );
	T::same( 0, ( new ConversationStore() )->purge_older_than( 0 ), '0 keeps forever' );
} );

T::test( 'admin AJAX actions require a nonce and manage_options', function () {
	// Admin hooks only load in wp-admin; the runner is not wp-admin.
	\Softorio\AiAssistant\Admin\Ajax::init();

	T::ok( has_action( 'wp_ajax_softorio_ai_rebuild' ) !== false, 'registered for logged-in users' );
	T::ok( false === has_action( 'wp_ajax_nopriv_softorio_ai_rebuild' ), 'not available to visitors' );
	T::ok( false === has_action( 'wp_ajax_nopriv_softorio_ai_test' ), 'test not available to visitors' );

	$source = file_get_contents( SOFTORIO_AI_DIR . 'includes/Admin/Ajax.php' );
	T::same( substr_count( $source, "add_action( 'wp_ajax_" ), substr_count( $source, 'self::guard();' ), 'every action calls the nonce + capability guard' );
} );

T::test( 'widget config exposes no secrets', function () {
	$json = wp_json_encode( \Softorio\AiAssistant\Frontend\Widget::config() );
	T::ok( ! str_contains( $json, 'sk-' ), 'no API key' );
	T::ok( str_contains( $json, 'softorio-ai\/v1' ), 'REST base URL present' );
} );

T::test( 'uninstall keeps data unless the owner opted in', function () {
	global $wpdb;
	$source = file_get_contents( SOFTORIO_AI_DIR . 'uninstall.php' );

	T::ok( str_contains( $source, "empty( \$softorio_ai_settings['delete_on_uninstall'] )" ), 'guarded by the opt-in setting' );
	T::ok( false === \Softorio\AiAssistant\Settings::defaults()['delete_on_uninstall'], 'opt-in is off by default' );
	T::ok( null !== $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Installer::tables()['chunks'] ), 'tables still present' );
} );

sai_reset();
