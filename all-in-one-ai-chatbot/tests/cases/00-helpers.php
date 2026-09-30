<?php
// phpcs:disable
/**
 * Shared fixtures: a fake AI provider and a clean slate.
 */

use Softorio\AiAssistant\Settings;

final class FakeHttp {
	/** @var array<int, array{status: int, body: array|string}> */
	public static array $queue = array();
	/** @var array<int, array{url: string, body: array, headers: array}> */
	public static array $requests = array();

	/** @var array<string, array{0: int, 1: string, 2: array<string, string>}> Fixed responses by exact URL (web import tests). */
	public static array $routes = array();

	public static function reset(): void {
		self::$queue    = array();
		self::$requests = array();
		self::$routes   = array();
	}

	public static function route( string $url, int $status, string $body, string $type = 'text/html; charset=utf-8' ): void {
		self::$routes[ $url ] = array( $status, $body, array( 'content-type' => $type ) );
	}

	public static function push( int $status, array|string $body ): void {
		self::$queue[] = array( 'status' => $status, 'body' => $body );
	}

	public static function openai( string $text, int $in = 100, int $out = 20 ): void {
		self::push( 200, array(
			'model'   => 'gpt-5-mini-2025-08-07',
			'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => $text ), 'finish_reason' => 'stop' ) ),
			'usage'   => array( 'prompt_tokens' => $in, 'completion_tokens' => $out ),
		) );
	}

	/** OpenAI response asking for tools: [[name, args], …]. */
	public static function openai_tools( array $calls ): void {
		$tool_calls = array();
		foreach ( $calls as $i => $call ) {
			$tool_calls[] = array( 'id' => 'call_' . $i . '_' . wp_rand(), 'type' => 'function', 'function' => array( 'name' => $call[0], 'arguments' => wp_json_encode( (object) $call[1] ) ) );
		}
		self::push( 200, array(
			'model'   => 'gpt-5-mini',
			'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => null, 'tool_calls' => $tool_calls ), 'finish_reason' => 'tool_calls' ) ),
			'usage'   => array( 'prompt_tokens' => 500, 'completion_tokens' => 30 ),
		) );
	}

	public static function last(): array {
		return end( self::$requests ) ?: array();
	}
}

add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( isset( FakeHttp::$routes[ $url ] ) || str_ends_with( (string) wp_parse_url( $url, PHP_URL_HOST ), '.example.org' ) ) {
		FakeHttp::$requests[] = array( 'url' => $url, 'body' => null, 'headers' => $args['headers'] ?? array(), 'user-agent' => $args['user-agent'] ?? '' );
		[ $status, $body, $headers ] = FakeHttp::$routes[ $url ] ?? array( 404, 'Not found', array( 'content-type' => 'text/html' ) );
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	$faked = array( 'api.openai.com', 'api.anthropic.com', 'api.deepseek.com', 'api.telegram.org', 'hooks.example.com', 'generativelanguage.googleapis.com', 'openrouter.ai', 'api.hubapi.com', 'api.brevo.com' );
	$host  = (string) wp_parse_url( $url, PHP_URL_HOST );

	if ( ! in_array( $host, $faked, true ) && ! str_ends_with( $host, '.api.mailchimp.com' ) ) {
		return $pre;
	}

	FakeHttp::$requests[] = array(
		'url'     => $url,
		'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
		'body'    => json_decode( (string) $args['body'], true ),
		'headers' => $args['headers'],
	);

	$next = array_shift( FakeHttp::$queue );

	if ( null === $next ) {
		return new WP_Error( 'http_request_failed', 'No fake response queued for ' . $url );
	}

	return array(
		'headers'  => array(),
		'body'     => is_string( $next['body'] ) ? $next['body'] : wp_json_encode( $next['body'] ),
		'response' => array( 'code' => $next['status'], 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}, 10, 3 );

/** Outgoing emails, captured instead of sent. */
final class FakeMail {
	public static array $sent = array();
	public static bool $fail = false;
}

add_filter( 'pre_wp_mail', static function ( $pre, $atts ) {
	if ( FakeMail::$fail ) {
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );
		return false;
	}
	FakeMail::$sent[] = $atts;
	return true;
}, 10, 2 );

/** Run the job queue until nothing is due. */
function sai_run_queue(): void {
	delete_option( 'softorio_ai_queue_lock' );
	\Softorio\AiAssistant\Support\Queue::run();
}

/** Save settings the same way the settings form does. */
function sai_save_settings( array $changes ): void {
	$form = array_merge( Settings::all(), array( '_tab' => '*' ), $changes );

	// Keys in the form are plaintext; saved values are encrypted. Blank means keep.
	foreach ( \Softorio\AiAssistant\SettingsSchema::fields() as $key => $field ) {
		if ( 'secret' === ( $field['type'] ?? '' ) && ! array_key_exists( $key, $changes ) ) {
			$form[ $key ] = '';
		}
	}

	update_option( Settings::OPTION, \Softorio\AiAssistant\Admin\SettingsPage::sanitize( $form ) );
}

/** Call the chat endpoint like the widget does. */
function sai_chat( string $message, string $conversation = '', string $token = 'visitortoken0001' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/softorio-ai/v1/chat' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array(
		'message'         => $message,
		'conversation_id' => $conversation,
		'visitor_token'   => $token,
		'page_url'        => home_url( '/shop/' ),
	) ) );

	return rest_do_request( $request );
}

/** Empty the plugin's tables and test posts between cases. */
function sai_reset(): void {
	global $wpdb;
	foreach ( \Softorio\AiAssistant\Installer::tables() as $table ) {
		$wpdb->query( "DELETE FROM $table" );
	}
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_sai_test' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	foreach ( get_posts( array( 'post_type' => 'softorio_ai_doc', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	delete_transient( 'softorio_ai_index_stats' );
	delete_transient( 'softorio_ai_usage_today' );
	delete_option( 'softorio_ai_last_error' );
	update_option( Settings::OPTION, Settings::defaults() );
	FakeHttp::reset();
}

function sai_post( string $title, string $content, array $extra = array() ): int {
	$id = wp_insert_post( array_merge( array(
		'post_title'   => $title,
		'post_content' => $content,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'meta_input'   => array( '_sai_test' => 1 ),
	), $extra ) );

	if ( is_wp_error( $id ) || ! $id ) {
		throw new RuntimeException( 'Could not create post ' . $title );
	}

	return (int) $id;
}
