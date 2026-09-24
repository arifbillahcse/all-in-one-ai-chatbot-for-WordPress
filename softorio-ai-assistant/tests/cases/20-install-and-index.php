<?php
// phpcs:disable
/**
 * Activation, indexing on save, exclusions and search over real posts.
 */

use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\PostTypes;

sai_reset();

T::test( 'activation creates every table', function () {
	global $wpdb;
	foreach ( Installer::tables() as $table ) {
		T::ok( null !== $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), "table $table exists" );
	}
	T::same( Installer::DB_VERSION, get_option( 'softorio_ai_db_version' ) );
	T::ok( post_type_exists( PostTypes::DOC ), 'knowledge post type registered' );
} );

T::test( 'published content is indexed on save; drafts, protected and excluded content is not', function () {
	$store = new IndexStore();

	$delivery = sai_post( 'Delivery Information', '<p>Inside Dhaka delivery takes 1-2 days and costs 60 Taka. Outside Dhaka delivery takes 3-5 days and costs 120 Taka.</p>' );
	$draft    = sai_post( 'Secret launch plan', 'Unannounced product launching next month.', array( 'post_status' => 'draft' ) );
	$locked   = sai_post( 'Staff area', 'Internal wifi password is hunter2.', array( 'post_password' => 'pw' ) );
	$hidden   = sai_post( 'Supplier pricing', 'Our supplier margin is 40 percent.', array( 'meta_input' => array( '_sai_test' => 1, PostTypes::EXCLUDE_KEY => true ) ) );
	$doc      = wp_insert_post( array(
		'post_type'    => PostTypes::DOC,
		'post_status'  => 'publish',
		'post_title'   => 'Refund policy',
		'post_content' => 'You can return any unused item within 7 days for a full refund. Refunds are paid by bKash within 3 working days.',
	) );

	// Saving again must not bring an excluded page back.
	wp_update_post( array( 'ID' => $hidden, 'post_content' => 'Our supplier margin is 40 percent.' ) );

	$ids = $store->indexed_post_ids();

	T::ok( in_array( $delivery, $ids, true ), 'published page indexed' );
	T::ok( in_array( $doc, $ids, true ), 'knowledge article indexed' );
	T::ok( ! in_array( $draft, $ids, true ), 'draft not indexed' );
	T::ok( ! in_array( $locked, $ids, true ), 'password-protected not indexed' );
	T::ok( ! in_array( $hidden, $ids, true ), 'excluded page not indexed' );
} );

T::test( 'search finds the right passage in English and for paraphrased keywords', function () {
	$results = ( new Retriever() )->search( 'How long does delivery take to Dhaka?' );

	T::ok( array() !== $results, 'got results' );
	T::same( 'Delivery Information', $results[0]['title'] );
	T::ok( '' !== $results[0]['url'], 'public page has a URL' );

	$refund = ( new Retriever() )->search( 'can I get my money refunded?' );
	T::same( 'Refund policy', $refund[0]['title'] ?? null );
	T::same( '', $refund[0]['url'], 'knowledge articles have no public URL' );

	T::same( array(), ( new Retriever() )->search( 'quantum chromodynamics' ), 'unrelated question finds nothing' );
} );

T::test( 'Bangla content is searchable', function () {
	sai_post( 'ডেলিভারি চার্জ', 'ঢাকার ভিতরে ডেলিভারি চার্জ ৬০ টাকা। ঢাকার বাইরে ১২০ টাকা।' );
	$results = ( new Retriever() )->search( 'ডেলিভারি চার্জ কত?' );

	T::same( 'ডেলিভারি চার্জ', $results[0]['title'] ?? null );
} );

T::test( 'editing re-indexes, unpublishing and trashing remove', function () {
	$id = sai_post( 'Opening hours', 'We are open 10am to 8pm, Saturday to Thursday.' );

	wp_update_post( array( 'ID' => $id, 'post_content' => 'We are open 9am to 9pm every day including Friday.' ) );
	$hit = ( new Retriever() )->search( 'open on friday?' );
	T::same( 'Opening hours', $hit[0]['title'] ?? null, 'new content searchable' );
	T::ok( str_contains( $hit[0]['content'], '9am' ), 'old content replaced' );

	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
	T::ok( ! in_array( $id, ( new IndexStore() )->indexed_post_ids(), true ), 'unpublished removed' );

	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	T::ok( in_array( $id, ( new IndexStore() )->indexed_post_ids(), true ), 'republished indexed again' );

	wp_trash_post( $id );
	T::ok( ! in_array( $id, ( new IndexStore() )->indexed_post_ids(), true ), 'trashed removed' );
} );

T::test( 'full rebuild runs in batches and prunes content no longer eligible', function () {
	for ( $i = 1; $i <= 25; $i++ ) {
		sai_post( "Bulk page $i", "Bulk content number $i about widgets." );
	}

	// Simulate something indexed earlier that is no longer eligible.
	( new IndexStore() )->replace( 999999, array( array(
		'title' => 'Ghost', 'url' => '', 'content' => 'ghost', 'search_text' => ' ghost ', 'token_count' => 1, 'content_hash' => 'x',
	) ) );

	$indexer = new Indexer();
	$offset  = 0;
	$batches = 0;

	do {
		$r = $indexer->rebuild_batch( $offset, 10 );
		$offset = $r['next'];
		++$batches;
	} while ( ! $r['done'] && $batches < 20 );

	T::ok( $batches >= 3, "ran in $batches batches" );
	T::same( 'done', get_option( Indexer::STATE_OPTION )['status'] ?? null );
	T::ok( ! in_array( 999999, ( new IndexStore() )->indexed_post_ids(), true ), 'stale entry pruned' );
	T::ok( ( new IndexStore() )->stats()['posts'] >= 28, 'all pages indexed' );

	$second = $indexer->rebuild_batch( 0, 100 );
	T::same( 0, $second['indexed'], 'unchanged content is skipped on the next rebuild' );
} );
