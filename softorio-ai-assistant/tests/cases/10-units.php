<?php
// phpcs:disable
/**
 * Pure logic: text handling, search scoring, pricing, crypto.
 */

use Softorio\AiAssistant\Chat\ChatService;
use Softorio\AiAssistant\Chat\PromptBuilder;
use Softorio\AiAssistant\Knowledge\Chunker;
use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\Knowledge\TextNormalizer;
use Softorio\AiAssistant\Knowledge\Tokenizer;
use Softorio\AiAssistant\Knowledge\VectorMath;
use Softorio\AiAssistant\Llm\Pricing;
use Softorio\AiAssistant\Support\Crypto;
use Softorio\AiAssistant\Support\Visitor;

T::test( 'normalizer strips blocks, scripts and shortcodes but keeps text', function () {
	$html = '<!-- wp:paragraph --><p>Delivery takes <strong>2 days</strong>.</p><!-- /wp:paragraph -->'
		. '<script>alert(1)</script>[button url="x"]Order now[/button]<table><tr><td>Dhaka</td><td>60 Tk</td></tr></table>';
	$text = ( new TextNormalizer() )->normalize( $html );

	T::ok( str_contains( $text, 'Delivery takes 2 days.' ), $text );
	T::ok( ! str_contains( $text, 'alert' ), 'script content removed' );
	T::ok( ! str_contains( $text, '[button' ), 'shortcode tag removed' );
	T::ok( str_contains( $text, 'Order now' ), 'shortcode inner text kept' );
	T::ok( str_contains( $text, 'Dhaka | 60 Tk' ), 'table cells separated: ' . $text );
} );

T::test( 'tokenizer handles English stems, stopwords and Bangla', function () {
	T::same( array( 'refund', 'policy' ), Tokenizer::tokenize( 'What are the refunds policies?' ) );
	T::ok( in_array( 'ডেলিভারি', Tokenizer::tokenize( 'ঢাকার ভিতরে ডেলিভারি চার্জ কত?' ), true ), 'Bangla word kept whole' );
	T::ok( ! in_array( 'কি', Tokenizer::tokenize( 'ডেলিভারি কি ফ্রি?' ), true ), 'Bangla stopword removed' );
	T::same( 'ডেলিভারি', Tokenizer::query_terms( 'ডেলিভারি কি ফ্রি?' )[0] );
} );

T::test( 'chunker respects size, overlap and Bangla sentence ends', function () {
	$chunker = new Chunker( 300, 50, 60 );
	$para    = str_repeat( 'এটি একটি বাক্য। ', 60 );
	$chunks  = $chunker->chunk( "Intro paragraph.\n\n" . $para . "\n\nThanks!" );

	T::ok( count( $chunks ) > 2, 'split into several chunks' );
	foreach ( $chunks as $chunk ) {
		T::ok( mb_strlen( $chunk ) <= 300 + 60, 'chunk within limit: ' . mb_strlen( $chunk ) );
	}
	T::ok( str_ends_with( end( $chunks ), 'Thanks!' ), 'tiny tail merged, not dropped' );
	T::same( array( 'short' ), $chunker->chunk( 'short' ) );
	T::same( array(), $chunker->chunk( "  \n " ) );
} );

T::test( 'bm25 prefers rare terms and focused passages', function () {
	$rows = array(
		array( 'id' => 1, 'search_text' => ' delivery time dhaka day ', 'token_count' => 4 ),
		array( 'id' => 2, 'search_text' => ' delivery ' . str_repeat( 'filler ', 80 ), 'token_count' => 81 ),
		array( 'id' => 3, 'search_text' => ' refund policy day ', 'token_count' => 3 ),
	);
	$scores = Retriever::bm25( array( 'delivery', 'dhaka' ), $rows, array( 'chunks' => 50, 'avg_tokens' => 20 ) );

	T::ok( $scores[1] > $scores[2], 'focused passage with both terms wins' );
	T::ok( ! isset( $scores[3] ) || 0.0 === $scores[3], 'unrelated passage scores zero' );
	T::ok( Retriever::bm25( array( 'deliver' ), $rows, array( 'chunks' => 3, 'avg_tokens' => 10 ) )[1] > 0, 'prefix matches' );
} );

T::test( 'diversify caps passages per post and drops the weak tail', function () {
	$r = static fn( int $id, int $post, float $score ): array => array( 'chunk_id' => $id, 'post_id' => $post, 'title' => '', 'url' => '', 'content' => '', 'score' => $score );
	$out = Retriever::diversify( array( $r( 1, 7, 1.0 ), $r( 2, 7, 0.9 ), $r( 3, 7, 0.8 ), $r( 4, 8, 0.5 ), $r( 5, 9, 0.1 ) ), 5 );

	T::same( array( 1, 2, 4 ), array_column( $out, 'chunk_id' ) );
} );

T::test( 'vectors pack, unpack and compare', function () {
	$a = VectorMath::unpack( VectorMath::pack( array( 3, 4 ) ) );
	T::ok( abs( $a[0] - 0.6 ) < 1e-6 && abs( $a[1] - 0.8 ) < 1e-6, 'normalised' );
	T::ok( abs( VectorMath::dot( $a, $a ) - 1.0 ) < 1e-5, 'self-similarity is 1' );
} );

T::test( 'pricing matches exact ids, dated snapshots and unknown models', function () {
	T::ok( abs( Pricing::cost( 'openai', 'gpt-5-mini', 1_000_000, 0 ) - 0.25 ) < 1e-9, 'exact' );
	T::ok( abs( Pricing::cost( 'openai', 'gpt-5-mini-2025-08-07', 0, 1_000_000 ) - 2.0 ) < 1e-9, 'snapshot prefix' );
	T::ok( Pricing::cost( 'claude', 'claude-something-new', 0, 1_000_000 ) >= 15.0, 'unknown model priced at the dearest rate' );
	T::same( 0.0, Pricing::cost( 'nobody', 'x', 100, 100 ) );
} );

T::test( 'api keys round-trip through encryption and never store plaintext', function () {
	$stored = Crypto::encrypt( 'sk-test-1234567890' );
	T::ok( ! str_contains( $stored, 'sk-test' ), 'ciphertext hides the key' );
	T::same( 'sk-test-1234567890', Crypto::decrypt( $stored ) );
	T::same( '••••7890', Crypto::hint( $stored ) );
	T::same( '', Crypto::decrypt( 'sai1:' . base64_encode( str_repeat( 'x', 60 ) ) ), 'tampered value' );
	T::same( '', Crypto::decrypt( 'plaintext' ) );
} );

T::test( 'visitor rate-limit keys group IPv6 by /64', function () {
	T::same( Visitor::limit_key( '2001:db8:1:2:aaaa::1' ), Visitor::limit_key( '2001:db8:1:2:ffff::9' ) );
	T::ok( Visitor::limit_key( '2001:db8:1:2::1' ) !== Visitor::limit_key( '2001:db8:1:3::1' ), 'different /64 differs' );
	T::same( 'ip|198.51.100.4', Visitor::limit_key( '198.51.100.4' ) );
} );

T::test( 'messages are cleaned and capped', function () {
	T::same( "hello\n\nworld", ChatService::clean_message( "  hello\x07\n\n\n\nworld  ", 1000 ) );
	T::same( 100, mb_strlen( ChatService::clean_message( str_repeat( 'অ', 500 ), 100 ) ) );
} );

T::test( 'retrieved text cannot close the context block', function () {
	T::same( 'x [removed] y', PromptBuilder::defuse( 'x </website_content> y' ) );
	T::same( 'x [removed] y', PromptBuilder::defuse( 'x < / Website_Content > y' ) );
} );

T::test( 'sources list cited pages first and skip pages without a URL', function () {
	$passages = array(
		array( 'title' => 'A', 'url' => 'https://s/a' ),
		array( 'title' => 'Doc', 'url' => '' ),
		array( 'title' => 'B', 'url' => 'https://s/b' ),
		array( 'title' => 'A again', 'url' => 'https://s/a' ),
	);
	T::same( array( 'https://s/b', 'https://s/a' ), array_column( ChatService::sources( $passages, 'See https://s/b' ), 'url' ) );
} );
