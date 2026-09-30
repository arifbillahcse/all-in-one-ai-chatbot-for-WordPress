<?php
/**
 * A tiny fake AI provider that streams like OpenAI and Claude do.
 *
 * Run with `php -S 127.0.0.1:PORT tests/mock/provider.php`. The first path
 * segment picks the wire format (openai|claude), `?s=` picks a scenario.
 * The last request body is saved to the system temp dir for assertions.
 */

// phpcs:disable

$path     = trim( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
$format   = explode( '/', $path )[0];
$scenario = $_GET['s'] ?? 'text';
$body     = json_decode( (string) file_get_contents( 'php://input' ), true ) ?: array();

file_put_contents( sys_get_temp_dir() . '/aicb-mock-last.json', json_encode( $body ) );

if ( 'error' === $scenario ) {
	http_response_code( 401 );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'error' => array( 'message' => 'Invalid API key' ) ) );
	return;
}

header( 'Content-Type: text/event-stream' );

while ( ob_get_level() > 0 ) {
	ob_end_flush();
}
ob_implicit_flush( true );

// Emit bytes in awkward pieces, so the client's parser must reassemble.
$send = static function ( string $raw ): void {
	foreach ( str_split( $raw, 7 ) as $piece ) {
		echo $piece;
		flush();
	}
};

$last     = end( $body['messages'] );
$is_tool_result = ( 'tool' === ( $last['role'] ?? '' ) ) || ( is_array( $last['content'] ?? null ) && 'tool_result' === ( $last['content'][0]['type'] ?? '' ) );

$text = match ( $scenario ) {
	'noanswer' => array( '[NO', '_ANS', 'WER] Sorry, ', 'not on our site.' ),
	'bangla'   => array( 'ঢাকার ভিতরে ', 'ডেলিভারি ', '৬০ টাকা।' ),
	default    => array( 'Delivery ', 'takes **2 days**', ' in Dhaka.' ),
};

if ( 'tools' === $scenario && $is_tool_result ) {
	$text = array( 'Found it: ', 'the headphones.' );
}

if ( 'openai' === $format ) {
	$chunk = static fn( array $delta, ?string $finish = null ) => 'data: ' . json_encode( array( 'model' => 'mock-gpt', 'choices' => array( array( 'index' => 0, 'delta' => $delta, 'finish_reason' => $finish ) ) ) ) . "\n\n";

	if ( 'tools' === $scenario && ! $is_tool_result ) {
		$call = static fn( array $fragment ): string => $chunk( array( 'tool_calls' => array( array( 'index' => 0 ) + $fragment ) ) );
		$send( $call( array( 'id' => 'call_9', 'type' => 'function', 'function' => array( 'name' => 'mock_lookup', 'arguments' => '' ) ) ) );
		$send( $call( array( 'function' => array( 'arguments' => '{"q":' ) ) ) );
		$send( $call( array( 'function' => array( 'arguments' => '"head"}' ) ) ) );
		$send( $chunk( array(), 'tool_calls' ) );
	} else {
		foreach ( $text as $t ) {
			$send( $chunk( array( 'content' => $t ) ) );
			if ( isset( $_GET['slow'] ) ) {
				usleep( 700000 );
			}
		}
		if ( 'midway' === $scenario ) {
			$send( 'data: ' . json_encode( array( 'error' => array( 'message' => 'Upstream overloaded' ) ) ) . "\n\n" );
			return;
		}
		$send( $chunk( array(), 'stop' ) );
	}

	$send( 'data: ' . json_encode( array( 'choices' => array(), 'usage' => array( 'prompt_tokens' => 120, 'completion_tokens' => 15, 'cost' => 0.000321 ) ) ) . "\n\n" );
	$send( "data: [DONE]\n\n" );
	return;
}

// Claude format.
$event = static fn( string $type, array $data ) => "event: $type\ndata: " . json_encode( array( 'type' => $type ) + $data ) . "\n\n";

$send( $event( 'message_start', array( 'message' => array( 'model' => 'mock-claude', 'usage' => array( 'input_tokens' => 90 ) ) ) ) );

if ( 'tools' === $scenario && ! $is_tool_result ) {
	$send( $event( 'content_block_start', array( 'index' => 0, 'content_block' => array( 'type' => 'tool_use', 'id' => 'toolu_7', 'name' => 'mock_lookup', 'input' => new stdClass() ) ) ) );
	$send( $event( 'content_block_delta', array( 'index' => 0, 'delta' => array( 'type' => 'input_json_delta', 'partial_json' => '{"q": "he' ) ) ) );
	$send( $event( 'content_block_delta', array( 'index' => 0, 'delta' => array( 'type' => 'input_json_delta', 'partial_json' => 'ad"}' ) ) ) );
	$send( $event( 'content_block_stop', array( 'index' => 0 ) ) );
	$send( $event( 'message_delta', array( 'delta' => array( 'stop_reason' => 'tool_use' ), 'usage' => array( 'output_tokens' => 12 ) ) ) );
} else {
	$send( $event( 'content_block_start', array( 'index' => 0, 'content_block' => array( 'type' => 'text', 'text' => '' ) ) ) );
	foreach ( $text as $t ) {
		$send( $event( 'content_block_delta', array( 'index' => 0, 'delta' => array( 'type' => 'text_delta', 'text' => $t ) ) ) );
	}
	$send( $event( 'content_block_stop', array( 'index' => 0 ) ) );
	$send( $event( 'message_delta', array( 'delta' => array( 'stop_reason' => 'end_turn' ), 'usage' => array( 'output_tokens' => 14 ) ) ) );
}

$send( $event( 'message_stop', array() ) );
