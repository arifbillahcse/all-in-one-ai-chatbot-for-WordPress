<?php
// phpcs:disable
/**
 * Phase 5: members-only knowledge, documents, FAQ import, web import,
 * logged-in visitors.
 */

use Softorio\AiAssistant\Admin\SourcesPage;
use Softorio\AiAssistant\Chat\ConversationStore;
use Softorio\AiAssistant\Frontend\Widget;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Knowledge\Audience;
use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\Knowledge\Retriever;
use Softorio\AiAssistant\PostTypes;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Sources\DocxText;
use Softorio\AiAssistant\Sources\FaqImporter;
use Softorio\AiAssistant\Sources\FileImporter;
use Softorio\AiAssistant\Sources\HtmlText;
use Softorio\AiAssistant\Sources\PdfText;
use Softorio\AiAssistant\Sources\SourceException;
use Softorio\AiAssistant\Sources\SourceStore;
use Softorio\AiAssistant\Sources\WebImporter;

require_once dirname( __DIR__ ) . '/fixtures/make-pdf.php';

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'visitor_hourly_limit' => 1000 ) );

$sai_tmp = get_temp_dir() . 'sai-tests-' . wp_rand() . '/';
wp_mkdir_p( $sai_tmp );

/** Write a temp file and return its path. */
function sai_file( string $name, string $data ): string {
	global $sai_tmp;
	file_put_contents( $sai_tmp . $name, $data );
	return $sai_tmp . $name;
}

/** A .docx shaped like Word's output. */
function sai_docx( string $name ): string {
	global $sai_tmp;
	$path = $sai_tmp . $name;
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
	$zip->addFromString(
		'word/document.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
		. '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Warranty &amp; Returns</w:t></w:r></w:p>'
		. '<w:p><w:r><w:t xml:space="preserve">Every phone has a </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>one-year</w:t></w:r><w:r><w:t xml:space="preserve"> warranty.</w:t></w:r><w:r><w:tab/><w:t>Keep the box.</w:t></w:r></w:p>'
		. '<w:p><w:del w:id="1"><w:r><w:delText>Old text that was deleted.</w:delText></w:r></w:del><w:r><w:t>Line one</w:t><w:br/><w:t>Line two</w:t></w:r></w:p>'
		. '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Plan</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Price</w:t></w:r></w:p></w:tc></w:tr>'
		. '<w:tr><w:tc><w:p><w:r><w:t>Gold</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>1999 Taka</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
		. '<w:p><w:r><w:t>ওয়ারেন্টি এক বছর।</w:t></w:r></w:p>'
		. '</w:body></w:document>'
	);
	$zip->addFromString( 'word/footnotes.xml', '<?xml version="1.0"?><w:footnotes xmlns:w="x"><w:footnote><w:p><w:r><w:t>Terms apply.</w:t></w:r></w:p></w:footnote></w:footnotes>' );
	$zip->close();
	return $path;
}

function sai_odt( string $name ): string {
	global $sai_tmp;
	$path = $sai_tmp . $name;
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'content.xml', '<?xml version="1.0"?><office:document-content xmlns:office="o" xmlns:text="t"><office:body><office:text><text:h>Opening hours</text:h><text:p>Open<text:s/>daily from 10 to 8.</text:p></office:text></office:body></office:document-content>' );
	$zip->close();
	return $path;
}

function sai_members_on(): void {
	sai_save_settings( array( 'members_knowledge' => true ) );
}

function sai_user( string $role, string $first = '' ): int {
	$id = wp_insert_user( array( 'user_login' => 'sai_' . $role . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'sai' . wp_rand() . '@example.com', 'role' => $role, 'first_name' => $first ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return (int) $id;
}

function sai_doc( string $title, string $content, string $audience = '' ): int {
	$id = wp_insert_post( array( 'post_type' => PostTypes::DOC, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content, 'meta_input' => '' !== $audience ? array( Audience::META => $audience ) : array() ) );
	return (int) $id;
}

function sai_titles( array $results ): array {
	return array_map( static fn( $r ) => $r['title'], $results );
}

$sai_users = array();

// ── Audience ────────────────────────────────────────────────────────────────

T::test( 'audience markers are normalised to known roles', function () {
	T::same( '', Audience::normalise( '' ) );
	T::same( '', Audience::normalise( 'everyone' ) );
	T::same( 'members', Audience::normalise( 'members' ) );
	T::same( ',customer,subscriber,', Audience::normalise( array( 'subscriber', 'Customer', 'nosuchrole', 'subscriber' ) ), 'sorted, known, unique' );
	T::same( '', Audience::normalise( array( 'nosuchrole' ) ) );
	T::same( 'members', Audience::from_picker( array( 'mode' => 'members' ) ) );
	T::same( ',editor,', Audience::from_picker( array( 'mode' => 'roles', 'roles' => array( 'editor' ) ) ) );
	T::same( 'members', Audience::from_picker( array( 'mode' => 'roles', 'roles' => array() ) ), 'roles with none ticked never falls open to everyone' );
	T::same( '', Audience::from_picker( 'junk' ) );
	T::same( 'Logged-in users', Audience::label( 'members' ) );
	T::ok( str_contains( Audience::label( ',customer,editor,' ), 'Editor' ) );
} );

T::test( 'members-only knowledge is filtered in the search query itself', function () use ( &$sai_users ) {
	global $wpdb;

	$public  = sai_doc( 'Shipping basics', 'Standard shipping takes four days and costs 60 Taka.' );
	$members = sai_doc( 'Member shipping perks', 'Members get express shipping in one day for free.', 'members' );
	$gold    = sai_doc( 'Gold shipping perks', 'Gold customers get same-day shipping with a personal courier.', ',customer,' );

	T::same( 'members', $wpdb->get_var( $wpdb->prepare( 'SELECT audience FROM %i WHERE post_id = %d LIMIT 1', Installer::tables()['chunks'], $members ) ), 'marker stored on chunks' );

	$r = new Retriever();
	$q = 'shipping perks express same-day courier';

	T::same( array( 'Shipping basics' ), sai_titles( $r->search( 'shipping', 10 ) ), 'default (guest): public only' );
	T::ok( ! in_array( 'Member shipping perks', sai_titles( $r->search( $q, 10, Audience::guest() ) ), true ), 'guest never gets members-only' );

	$sub      = sai_user( 'subscriber', 'Rahim' );
	$customer = sai_user( 'customer', 'Karim' );
	$sai_users = array( $sub, $customer );

	T::same( array(), sai_titles( array_filter( $r->search( $q, 10, Audience::for_user( $sub ) ), static fn( $x ) => '' !== $x['audience'] ) ), 'feature off: logged-in users are treated as guests' );

	sai_members_on();

	$as_sub = sai_titles( $r->search( $q, 10, Audience::for_user( $sub ) ) );
	T::ok( in_array( 'Member shipping perks', $as_sub, true ), 'subscriber gets members content' );
	T::ok( ! in_array( 'Gold shipping perks', $as_sub, true ), 'but not a role they lack' );

	$as_customer = sai_titles( $r->search( $q, 10, Audience::for_user( $customer ) ) );
	T::ok( in_array( 'Gold shipping perks', $as_customer, true ) && in_array( 'Member shipping perks', $as_customer, true ), 'customer gets both' );

	$all = $r->search( 'shipping', 10, Audience::everything() );
	T::same( 3, count( $all ), 'admin preview sees everything' );

	// Semantic-search paths are filtered too.
	$store = new IndexStore();
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET embedding = 'x'", Installer::tables()['chunks'] ) );
	T::same( 1, count( $store->embeddings( 100 ) ), 'embeddings: guest sees public only' );
	T::same( 3, count( $store->embeddings( 100, Audience::everything() ) ) );
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', Installer::tables()['chunks'] ) );
	T::same( 1, count( $store->by_ids( array_map( 'intval', $ids ) ) ), 'by_ids: guest sees public only' );
	T::same( 2, count( $store->by_ids( array_map( 'intval', $ids ), Audience::for_user( $sub ) ) ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET embedding = NULL', Installer::tables()['chunks'] ) );

	// Changing the audience re-indexes (the hash includes it).
	Audience::set( $members, '' );
	wp_update_post( array( 'ID' => $members, 'post_content' => 'Members get express shipping in one day for free.' ) );
	T::ok( in_array( 'Member shipping perks', sai_titles( $r->search( $q, 10 ) ), true ), 'made public' );
	Audience::set( $members, 'members' );
	\Softorio\AiAssistant\Knowledge\Indexer::on_save( $members );
	T::ok( ! in_array( 'Member shipping perks', sai_titles( $r->search( $q, 10 ) ), true ), 'restricted again' );

	// Switching the feature off hides restricted content from everyone.
	sai_save_settings( array( 'members_knowledge' => false ) );
	T::ok( ! in_array( 'Gold shipping perks', sai_titles( $r->search( $q, 10, Audience::for_user( $customer ) ) ), true ), 'off = hidden, not public' );
	sai_members_on();
} );

T::test( 'chat: members-only passages reach the prompt only for members', function () use ( &$sai_users ) {
	[ $sub, $customer ] = $sai_users;

	FakeHttp::reset();
	FakeHttp::openai( 'Standard shipping is four days.' );
	sai_chat( 'How does shipping work, any perks?', '', 'guesttoken000001' );
	$prompt = wp_json_encode( FakeHttp::last()['body'] );
	T::ok( str_contains( $prompt, 'Standard shipping' ), 'public passage used' );
	T::ok( ! str_contains( $prompt, 'express shipping in one day' ) && ! str_contains( $prompt, 'personal courier' ), 'no members-only text for a guest' );

	wp_set_current_user( $customer );
	FakeHttp::openai( 'As a Gold customer you get same-day delivery.' );
	sai_chat( 'How does shipping work, any perks?', '', 'custtoken0000001' );
	$prompt = wp_json_encode( FakeHttp::last()['body'] );
	T::ok( str_contains( $prompt, 'personal courier' ), 'role content used for a customer' );
	T::ok( str_contains( $prompt, 'Their account type on this website: Customer' ), 'role in prompt' );
	T::ok( str_contains( $prompt, 'as Karim' ), 'name in prompt' );
	wp_set_current_user( 0 );
} );

T::test( 'a logged-in conversation cannot be reopened by the same browser after logout', function () use ( &$sai_users ) {
	[ $sub ] = $sai_users;
	$store   = new ConversationStore();

	wp_set_current_user( $sub );
	$thread = $store->resume_or_create( '', 'sharedtoken00001', home_url( '/' ) );
	T::ok( null !== $store->find_owned( $thread['public_id'], 'sharedtoken00001' ), 'owner can resume' );

	wp_set_current_user( 0 );
	T::same( null, $store->find_owned( $thread['public_id'], 'sharedtoken00001' ), 'guest with the same token cannot' );

	$other = sai_user( 'subscriber' );
	wp_set_current_user( $other );
	T::same( null, $store->find_owned( $thread['public_id'], 'sharedtoken00001' ), 'another account cannot' );
	wp_delete_user( $other );

	wp_set_current_user( 0 );
	$guest = $store->resume_or_create( '', 'guesttoken000002', home_url( '/' ) );
	wp_set_current_user( $sub );
	T::ok( null !== $store->find_owned( $guest['public_id'], 'guesttoken000002' ), 'a guest chat can continue after logging in' );
	wp_set_current_user( 0 );
} );

// ── Documents ───────────────────────────────────────────────────────────────

T::test( 'PDF: Chrome output with CID fonts and Bangla ActualText', function () {
	$text = PdfText::extract( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/chrome-handbook.pdf' ) );
	T::ok( str_contains( $text, 'Gold members get free express delivery on every order over 500 Taka.' ), 'sentence intact, no stray spaces' );
	T::ok( str_contains( $text, 'Warranty' ) && ! str_contains( $text, 'W arranty' ), 'kerning is not a space' );
	T::ok( str_contains( $text, 'Gold 1999 Taka' ), 'table row' );
	T::ok( str_contains( $text, 'ঢাকার ভিতরে ডেলিভারি চার্জ ৬০ টাকা।' ), 'Bangla in logical order' );
} );

T::test( 'PDF: object streams, simple fonts, kerning, ASCII85, forms', function () {
	$simple = PdfText::extract( SaiPdf::simple() );
	T::ok( str_contains( $simple, 'We deliver to all 64 districts. Café orders ship daily.' ), 'TJ kerning and WinAnsi é' );
	T::ok( str_contains( $simple, 'Refunds take 7 days (bKash or card).' ), 'escaped parentheses' );
	T::ok( str_contains( $simple, 'Page two: • bullet' ), 'Differences glyph, ASCII85 page, inherited resources' );
	T::ok( strpos( $simple, 'Delivery policy' ) < strpos( $simple, 'Page two' ), 'page order' );

	$objstm = PdfText::extract( SaiPdf::objstm() );
	T::ok( str_contains( $objstm, 'সা ABC' ), 'fonts and pages inside an object stream, bfchar and bfrange' );
	T::ok( str_contains( $objstm, 'fiন' ), 'bfrange with an array, multi-character ligature' );
	T::ok( 1 === preg_match( '/\nA\s*$/', $objstm ), 'Form XObject text' );
} );

T::test( 'PDF: clear errors for encrypted, scanned and fake files', function () {
	foreach ( array( 'encrypted' => 'password-protected', 'scanned' => 'scanned' ) as $kind => $words ) {
		try {
			FileImporter::extract( sai_file( "$kind.pdf", SaiPdf::$kind() ), "$kind.pdf" );
			T::ok( false, "$kind should fail" );
		} catch ( SourceException $e ) {
			T::ok( str_contains( $e->getMessage(), $words ), "$kind: " . $e->getMessage() );
		}
	}

	try {
		PdfText::extract( 'hello' );
		T::ok( false );
	} catch ( SourceException $e ) {
		T::ok( str_contains( $e->getMessage(), 'not a PDF' ) );
	}

	// Garbage after the header must not hang or fatal.
	$t = microtime( true );
	try {
		PdfText::extract( "%PDF-1.4\n" . str_repeat( "1 0 obj << /Length 5 >> stream\n\xff\xfe\x00 endobj ( ((( [[[ <<<< ", 2000 ) );
	} catch ( SourceException $e ) {
	}
	T::ok( microtime( true ) - $t < 5, 'malformed input handled quickly' );
} );

T::test( 'Word and OpenDocument files', function () {
	$docx = DocxText::extract( sai_docx( 'warranty.docx' ) );
	T::ok( str_starts_with( $docx, 'Warranty & Returns' ), 'heading, entity decoded' );
	T::ok( str_contains( $docx, 'Every phone has a one-year warranty. Keep the box.' ), 'runs joined, tab as space' );
	T::ok( ! str_contains( $docx, 'Old text' ), 'tracked deletions dropped' );
	T::ok( str_contains( $docx, "Line one\nLine two" ), 'line break' );
	T::ok( str_contains( $docx, 'Gold | 1999 Taka' ), 'table cells separated' );
	T::ok( str_contains( $docx, 'ওয়ারেন্টি এক বছর।' ), 'Bangla' );
	T::ok( str_contains( $docx, 'Terms apply.' ), 'footnotes' );

	T::ok( str_contains( DocxText::extract( sai_odt( 'hours.odt' ), 'odt' ), 'Open daily from 10 to 8.' ), 'odt' );

	try {
		DocxText::extract( sai_file( 'fake.docx', 'not a zip' ) );
		T::ok( false );
	} catch ( SourceException $e ) {
		T::ok( str_contains( $e->getMessage(), 'not a valid Word' ) );
	}
} );

T::test( 'file import creates an article, updates it on re-upload, applies the audience', function () {
	[ $id, $state, $chars ] = FileImporter::import( sai_docx( 'warranty-terms.docx' ), 'warranty-terms.docx', 'members' );
	T::same( 'created', $state );
	T::ok( $chars > 50 );
	$post = get_post( $id );
	T::same( PostTypes::DOC, $post->post_type );
	T::same( 'Warranty terms', $post->post_title, 'title from the file name' );
	T::same( 'file', get_post_meta( $id, SourceStore::TYPE, true ) );
	T::same( 'warranty-terms.docx', get_post_meta( $id, SourceStore::NAME, true ) );
	T::same( 'members', Audience::for_post( $id ) );
	T::ok( str_contains( $post->post_content, '<p>Gold | 1999 Taka</p>' ) );

	[ $again, $state ] = FileImporter::import( sai_docx( 'warranty-terms.docx' ), 'warranty-terms.docx', 'members' );
	T::same( $id, $again, 'same file name = same article' );
	T::same( 'unchanged', $state );

	[ , $state ] = FileImporter::import( sai_file( 'warranty-terms.docx.txt', "New warranty: two years.\n\nThat's all." ), 'warranty-terms.txt', '' );
	T::same( 'created', $state, 'different name, different article' );

	$md = FileImporter::extract( sai_file( 'faq.md', "\xEF\xBB\xBF# Heading\n\nSee [our shop](https://shop.example.com) for **deals**.\n![img](x.png)" ), 'faq.md' );
	T::same( "Heading\n\nSee our shop (https://shop.example.com) for deals.", $md['text'], 'Markdown cleaned, BOM stripped' );

	$ansi = FileImporter::extract( sai_file( 'ansi.txt', "Caf\xE9 hours are 9 to 5, every day." ), 'ansi.txt' );
	T::ok( str_contains( $ansi['text'], 'Café' ), 'Windows-1252 converted' );

	$html = FileImporter::extract( sai_file( 'page.html', '<html><head><title>Saved page</title></head><body><nav>Home Shop</nav><main><p>' . str_repeat( 'Useful saved content. ', 20 ) . '</p></main></body></html>' ), 'page.html' );
	T::same( 'Saved page', $html['title'] );
	T::ok( ! str_contains( $html['text'], 'Home Shop' ) );

	foreach ( array( 'virus.exe' => 'not supported', 'empty.txt' => 'empty' ) as $name => $words ) {
		try {
			FileImporter::extract( sai_file( $name, 'empty.txt' === $name ? '' : 'MZ' ), $name );
			T::ok( false, $name );
		} catch ( SourceException $e ) {
			T::ok( str_contains( $e->getMessage(), $words ), $e->getMessage() );
		}
	}

	add_filter( 'softorio_ai_extract_text', $ocr = static fn() => 'Text from an OCR service, long enough to keep.' );
	T::same( 'Text from an OCR service, long enough to keep.', FileImporter::extract( sai_file( 'scan.pdf', SaiPdf::scanned() ), 'scan.pdf' )['text'], 'custom extractor filter' );
	remove_filter( 'softorio_ai_extract_text', $ocr );

	$found = sai_titles( ( new Retriever() )->search( 'warranty two years', 5 ) );
	T::ok( in_array( 'Warranty terms', $found, true ), 'imported text is searchable' );
} );

// ── FAQ import ──────────────────────────────────────────────────────────────

T::test( 'FAQ CSV: separators, header, quoted line breaks, encodings', function () {
	$csv = "\xEF\xBB\xBFQuestion;Answer\n\"How long is delivery?\";\"Inside Dhaka 1-2 days.\nOutside 3-5 days.\"\n;orphan answer\nDo you ship abroad?;\n\"ডেলিভারি চার্জ কত?\";\"৬০ টাকা\"\n\n";
	$parsed = FaqImporter::parse( $csv );
	T::same( 2, count( $parsed['rows'] ), 'header skipped, empty rows dropped' );
	T::same( 2, $parsed['skipped'] );
	T::same( array( 'How long is delivery?', "Inside Dhaka 1-2 days.\nOutside 3-5 days." ), $parsed['rows'][0], 'semicolon, multi-line answer' );
	T::same( 'ডেলিভারি চার্জ কত?', $parsed['rows'][1][0] );

	$tabbed = FaqImporter::parse( "Can I pay by card?\tYes, Visa and Mastercard.\n" );
	T::same( array( array( 'Can I pay by card?', 'Yes, Visa and Mastercard.' ) ), $tabbed['rows'], 'tab separated, no header' );

	$ansi = FaqImporter::parse( "Caf\xE9 open?,Yes \x96 daily\n" );
	T::same( 'Café open?', $ansi['rows'][0][0], 'Windows-1252 (Excel) converted' );
	T::same( 'Yes – daily', $ansi['rows'][0][1] );

	try {
		FaqImporter::parse( "just one column\n" );
		T::ok( false );
	} catch ( SourceException $e ) {
		T::ok( str_contains( $e->getMessage(), 'column A' ) );
	}

	T::same( FaqImporter::ref( 'How long is delivery?' ), FaqImporter::ref( '  how LONG is delivery ' ), 'key ignores case, spacing and punctuation' );
} );

T::test( 'FAQ import creates, updates, keeps and replaces', function () {
	$rows = array( array( 'Do you have a warranty?', 'Yes, one year.' ), array( 'Where is your shop?', 'Dhanmondi 27, Dhaka.' ) );
	T::same( array( 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'removed' => 0 ), FaqImporter::import( $rows ) );

	$id = SourceStore::find( 'faq', FaqImporter::ref( 'Where is your shop?' ) );
	T::same( 'Where is your shop?', get_the_title( $id ), 'question is the title' );

	$rows[1][1] = 'Gulshan 2, Dhaka (we moved).';
	$rows[]     = array( 'Is parking available?', 'Yes, free for customers.' );
	T::same( array( 'created' => 1, 'updated' => 1, 'unchanged' => 1, 'removed' => 0 ), FaqImporter::import( $rows ) );
	T::same( $id, SourceStore::find( 'faq', FaqImporter::ref( 'where is your shop' ) ), 'same article updated' );
	T::ok( str_contains( get_post( $id )->post_content, 'Gulshan' ) );

	$found = ( new Retriever() )->search( 'where is the shop located', 3 );
	T::same( 'Where is your shop?', $found[0]['title'], 'the question ranks first' );

	T::same( 2, FaqImporter::import( array( $rows[0] ), '', true )['removed'], 'replace removes FAQs not in the file' );
	T::same( 1, count( SourceStore::ids( 'faq' ) ) );

	FaqImporter::import( array( array( 'Member discount?', 'Members get 10% off.' ) ), ',customer,' );
	T::same( ',customer,', Audience::for_post( (int) SourceStore::find( 'faq', FaqImporter::ref( 'Member discount?' ) ) ) );
} );

// ── Web import ──────────────────────────────────────────────────────────────

$sai_page = static fn( string $title, string $body ) => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $title . ' – Help Centre</title><meta property="og:title" content="' . $title . '"></head><body>'
	. '<header class="site-header"><a href="/">Logo</a></header><nav><a href="/a">Menu item</a></nav>'
	. '<div id="cookie-notice">We use cookies. Accept?</div>'
	. '<main><article><h1>' . $title . '</h1><p>' . $body . '</p><div class="share-buttons">Share on Facebook</div></article></main>'
	. '<aside class="sidebar">Popular posts</aside><footer>© Footer text</footer><script>var x="script text";</script></body></html>';

T::test( 'HTML: main content only, title, charset', function () use ( $sai_page ) {
	$page = HtmlText::extract( $sai_page( 'Returns', str_repeat( 'You can return any item within 30 days. ', 8 ) ) );
	T::same( 'Returns', $page['title'], 'og:title' );
	T::same( 'en', $page['lang'] );
	T::ok( str_contains( $page['text'], 'You can return any item within 30 days.' ) );
	foreach ( array( 'Menu item', 'We use cookies', 'Share on Facebook', 'Popular posts', 'Footer text', 'script text', 'Logo' ) as $noise ) {
		T::ok( ! str_contains( $page['text'], $noise ), "dropped: $noise" );
	}

	$latin = HtmlText::extract( "<html><head><meta charset=\"iso-8859-1\"><title>Caf\xE9</title></head><body><p>Cr\xE8me br\xFBl\xE9e is on the menu.</p></body></html>" );
	T::same( 'Café', $latin['title'] );

	T::same( 'Delivery Information', HtmlText::extract( '<html><head><title>Delivery Information – Dhaka Gadget Shop</title></head><body><h1>Delivery Information</h1></body></html>' )['title'], 'site name suffix dropped' );
	T::same( 'Home – Shop', HtmlText::extract( '<html><head><title>Home – Shop</title></head><body><h1>Welcome</h1></body></html>' )['title'], 'title kept when the heading differs' );
	T::ok( str_contains( $latin['text'], 'Crème brûlée' ), 'charset converted' );

	$section = HtmlText::extract( '<body><div class="social-media-marketing"><p>We run social media campaigns for small shops across Bangladesh.</p></div></body>' );
	T::ok( str_contains( $section['text'], 'social media campaigns' ), 'content sections named like furniture words are kept' );
} );

T::test( 'sitemaps, robots.txt and address handling', function () {
	T::same( 'https://help.example.org/start', WebImporter::normalise( 'help.example.org/start#top' ), 'scheme added, fragment dropped' );
	T::same( null, WebImporter::normalise( 'not a url' ) );
	T::same( null, WebImporter::normalise( 'javascript:alert(1)' ) );
	T::same( null, WebImporter::normalise( 'ftp://example.org/x' ) );

	$set = WebImporter::sitemap_locations( '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.example.org/1</loc></url><url><loc> https://a.example.org/2 </loc></url><url><loc>javascript:x</loc></url></urlset>' );
	T::same( array( 'type' => 'urlset', 'urls' => array( 'https://a.example.org/1', 'https://a.example.org/2' ) ), $set );
	T::same( 'index', WebImporter::sitemap_locations( '<sitemapindex><sitemap><loc>https://a.example.org/s1.xml</loc></sitemap></sitemapindex>' )['type'] );
	T::same( null, WebImporter::sitemap_locations( '<html>not a sitemap</html>' ) );
	T::same( null, WebImporter::sitemap_locations( '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>' )['urls'][0] ?? null, 'external entities not loaded' );

	$rules = WebImporter::robots_rules( "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/\nAllow: /private/faq\nDisallow: /*.pdf$\n\nUser-agent: AllInOneAIChatbot\nUser-agent: other\nDisallow: /no-bots/\n" );
	T::same( array( array( false, '/no-bots/' ) ), $rules, 'our own group wins over *' );

	FakeHttp::reset();
	set_transient( 'softorio_ai_robots_' . md5( 'https://r.example.org' ), "User-agent: *\nDisallow: /private/\nAllow: /private/faq\nDisallow: /*.pdf$\n", HOUR_IN_SECONDS );
	T::ok( WebImporter::allowed_by_robots( 'https://r.example.org/help/' ) );
	T::ok( ! WebImporter::allowed_by_robots( 'https://r.example.org/private/x' ) );
	T::ok( WebImporter::allowed_by_robots( 'https://r.example.org/private/faq' ), 'longest match wins' );
	T::ok( ! WebImporter::allowed_by_robots( 'https://r.example.org/files/a.pdf' ), 'wildcard and $' );
	T::ok( WebImporter::allowed_by_robots( 'https://r.example.org/files/a.pdf?x=1' ) );
} );

T::test( 'discover: pages, sitemap index, site root via robots.txt, filters', function () {
	FakeHttp::reset();
	FakeHttp::route( 'https://docs.example.org/robots.txt', 200, "Sitemap: https://docs.example.org/sitemap_index.xml\n", 'text/plain' );
	FakeHttp::route( 'https://docs.example.org/sitemap_index.xml', 200, '<sitemapindex><sitemap><loc>https://docs.example.org/page-sitemap.xml</loc></sitemap><sitemap><loc>https://docs.example.org/missing.xml</loc></sitemap></sitemapindex>', 'application/xml' );
	FakeHttp::route( 'https://docs.example.org/page-sitemap.xml', 200, gzencode( '<urlset><url><loc>https://docs.example.org/docs/returns/</loc></url><url><loc>https://docs.example.org/docs/shipping/</loc></url><url><loc>https://docs.example.org/blog/news/</loc></url></urlset>' ), 'application/x-gzip' );

	$found = WebImporter::discover( "https://docs.example.org/\nhttps://docs.example.org/docs/returns/\nnot a url" );
	T::same( array( 'https://docs.example.org/docs/returns/', 'https://docs.example.org/docs/shipping/', 'https://docs.example.org/blog/news/' ), $found['urls'], 'site root → sitemap index → gzip child, de-duplicated' );
	T::same( 1, count( $found['errors'] ), 'bad line reported' );

	$docs = WebImporter::discover( 'https://docs.example.org/page-sitemap.xml', '/docs/*' );
	T::same( 2, count( $docs['urls'] ), 'include filter' );

	$ua = FakeHttp::$requests[0]['user-agent'] ?? '';
	T::ok( str_starts_with( $ua, 'AllInOneAIChatbot/' ), 'identifies itself' );
} );

T::test( 'web page import: create, unchanged, update, gone, retry, robots', function () use ( $sai_page ) {
	global $wpdb;
	FakeHttp::reset();
	set_transient( 'softorio_ai_robots_' . md5( 'https://docs.example.org' ), "User-agent: *\nDisallow: /secret/\n", HOUR_IN_SECONDS );

	$url = 'https://docs.example.org/docs/returns/';
	FakeHttp::route( $url, 200, $sai_page( 'Returns policy', str_repeat( 'Unopened items can be returned within 30 days for a refund. ', 5 ) ) );

	T::same( 'created', WebImporter::import( $url, 'members' ) );
	$id = SourceStore::find( 'url', $url );
	T::same( 'Returns policy', get_the_title( $id ) );
	T::same( 'members', Audience::for_post( $id ) );
	T::same( $url, $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM %i WHERE post_id = %d LIMIT 1', Installer::tables()['chunks'], $id ) ), 'answers link to the page' );

	T::same( 'unchanged', WebImporter::import( $url, null ) );
	T::same( 'members', Audience::for_post( $id ), 'null audience keeps the existing one' );

	FakeHttp::route( $url, 200, $sai_page( 'Returns policy', str_repeat( 'Items can be returned within 14 days now. ', 5 ) ) );
	T::same( 'updated', WebImporter::import( $url, null ) );
	T::ok( str_contains( get_post( $id )->post_content, '14 days' ) );

	FakeHttp::route( $url, 404, 'gone' );
	T::same( 'gone', WebImporter::import( $url, null ) );
	T::same( 'draft', get_post_status( $id ), 'unpublished, not deleted' );
	T::same( 'gone', get_post_meta( $id, SourceStore::STATUS, true ) );
	T::same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', Installer::tables()['chunks'], $id ) ), 'dropped from the index' );

	FakeHttp::route( $url, 200, $sai_page( 'Returns policy', str_repeat( 'Items can be returned within 14 days now. ', 5 ) ) );
	WebImporter::import( $url, null );
	T::same( 'publish', get_post_status( $id ), 'back when the page returns' );

	FakeHttp::route( 'https://docs.example.org/down/', 503, 'busy' );
	try {
		WebImporter::import( 'https://docs.example.org/down/' );
		T::ok( false, 'should throw for a retry' );
	} catch ( RuntimeException $e ) {
		T::ok( str_contains( $e->getMessage(), '503' ) );
	}

	FakeHttp::route( 'https://docs.example.org/secret/x/', 200, $sai_page( 'Secret', str_repeat( 'secret ', 50 ) ) );
	T::same( 'skipped', WebImporter::import( 'https://docs.example.org/secret/x/' ), 'robots.txt respected' );

	FakeHttp::route( 'https://docs.example.org/tiny/', 200, '<html><body><p>Hi</p></body></html>' );
	T::same( 'skipped', WebImporter::import( 'https://docs.example.org/tiny/' ), 'near-empty page' );

	FakeHttp::route( 'https://docs.example.org/files/manual.pdf', 200, SaiPdf::simple(), 'application/pdf' );
	T::same( 'created', WebImporter::import( 'https://docs.example.org/files/manual.pdf' ), 'PDF links are read too' );
	T::same( 'Manual', get_the_title( SourceStore::find( 'url', 'https://docs.example.org/files/manual.pdf' ) ) );

	FakeHttp::route( 'https://docs.example.org/logo.png', 200, "\x89PNG", 'image/png' );
	T::same( 'skipped', WebImporter::import( 'https://docs.example.org/logo.png' ) );
} );

T::test( 'web import runs through the queue, a few pages per minute', function () use ( $sai_page ) {
	global $wpdb;
	$jobs = Installer::tables()['jobs'];
	$wpdb->query( "DELETE FROM $jobs" );
	FakeHttp::reset();
	set_transient( 'softorio_ai_robots_' . md5( 'https://q.example.org' ), '', HOUR_IN_SECONDS );

	$urls = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$urls[] = "https://q.example.org/p$i/";
		FakeHttp::route( "https://q.example.org/p$i/", 200, $sai_page( "Page $i", str_repeat( "Content of page number $i. ", 10 ) ) );
	}

	T::same( 20, WebImporter::queue( $urls, '' ) );
	T::same( 20, WebImporter::pending() );
	T::same( 5, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $jobs WHERE run_at > %d", time() + 30 ) ), 'pages 16-20 wait a minute' );

	add_filter( 'softorio_ai_queue_run_on_shutdown', '__return_false' );
	sai_run_queue();
	T::same( 5, WebImporter::pending(), 'first 15 done now' );
	T::ok( null !== SourceStore::find( 'url', 'https://q.example.org/p15/' ) );

	// The re-sync re-queues every imported page, keeping audiences.
	$wpdb->query( "DELETE FROM $jobs" );
	$queued = WebImporter::resync();
	T::same( count( SourceStore::ids( 'url' ) ), $queued );
	$payload = json_decode( (string) $wpdb->get_var( "SELECT payload FROM $jobs LIMIT 1" ), true );
	T::same( null, $payload['audience'] );
	$wpdb->query( "DELETE FROM $jobs" );

	sai_save_settings( array( 'web_resync' => 'weekly' ) );
	WebImporter::schedule();
	T::ok( false !== wp_next_scheduled( WebImporter::RESYNC ), 'weekly re-check scheduled' );
	sai_save_settings( array( 'web_resync' => 'off' ) );
	WebImporter::schedule();
	T::same( false, wp_next_scheduled( WebImporter::RESYNC ) );
} );

// ── Admin ───────────────────────────────────────────────────────────────────

T::test( 'Knowledge Sources screen: handlers check permission and nonce', function () {
	$stop = static function () {
		throw new RuntimeException( 'redirect' );
	};
	$die  = static fn() => static function ( $message ) {
		throw new RuntimeException( 'died' );
	};
	add_filter( 'wp_redirect', $stop );
	add_filter( 'wp_die_handler', $die );

	wp_set_current_user( 1 );
	FakeHttp::reset();
	set_transient( 'softorio_ai_robots_' . md5( 'https://h.example.org' ), '', HOUR_IN_SECONDS );

	$_POST = $_REQUEST = array(
		'urls'     => "https://h.example.org/one/\nhttps://h.example.org/two/",
		'audience' => array( 'mode' => 'members' ),
		'_wpnonce' => wp_create_nonce( 'softorio_ai_import_web' ),
	);
	try {
		SourcesPage::handle_web();
	} catch ( RuntimeException $e ) {
		T::same( 'redirect', $e->getMessage() );
	}
	T::ok( WebImporter::pending() >= 2, 'queued' );
	$notice = get_transient( 'softorio_ai_sources_notice_1' );
	T::ok( str_contains( $notice[0][1], '2 pages' ), 'notice' );

	// Delete one article.
	$victim = SourceStore::find( 'url', 'https://q.example.org/p1/' );
	$_POST  = $_REQUEST = array( 'post' => $victim, '_wpnonce' => wp_create_nonce( 'softorio_ai_delete_source' ) );
	try {
		SourcesPage::handle_delete();
	} catch ( RuntimeException $e ) {
	}
	T::same( null, get_post( $victim ), 'deleted' );

	// A normal page cannot be deleted through this handler.
	$page  = sai_post( 'About us', 'We are a shop.' );
	$_POST = $_REQUEST = array( 'post' => $page, '_wpnonce' => wp_create_nonce( 'softorio_ai_delete_source' ) );
	try {
		SourcesPage::handle_delete();
	} catch ( RuntimeException $e ) {
	}
	T::ok( null !== get_post( $page ), 'only imported articles' );

	// Bad nonce.
	$_POST = $_REQUEST = array( 'type' => 'faq', '_wpnonce' => 'nope' );
	try {
		SourcesPage::handle_purge();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::same( 'died', $e->getMessage() );
	}
	T::ok( count( SourceStore::ids( 'faq' ) ) > 0, 'nothing purged' );

	// Subscriber.
	$sub = sai_user( 'subscriber' );
	wp_set_current_user( $sub );
	$_POST = $_REQUEST = array( 'type' => 'faq', '_wpnonce' => wp_create_nonce( 'softorio_ai_purge_sources' ) );
	try {
		SourcesPage::handle_purge();
		T::ok( false );
	} catch ( RuntimeException $e ) {
		T::same( 'died', $e->getMessage() );
	}

	wp_set_current_user( 1 );
	$_POST = $_REQUEST = array( 'type' => 'faq', '_wpnonce' => wp_create_nonce( 'softorio_ai_purge_sources' ) );
	try {
		SourcesPage::handle_purge();
	} catch ( RuntimeException $e ) {
	}
	T::same( array(), SourceStore::ids( 'faq' ), 'purged' );

	ob_start();
	SourcesPage::render();
	$html = (string) ob_get_clean();
	T::ok( str_contains( $html, 'Upload documents' ) && str_contains( $html, 'Import FAQs' ) && str_contains( $html, 'Import from websites' ) );
	T::ok( str_contains( $html, 'Warranty terms' ), 'imported list' );
	T::ok( str_contains( $html, 'Who can the assistant share this with?' ), 'audience picker when enabled' );

	remove_filter( 'wp_redirect', $stop );
	remove_filter( 'wp_die_handler', $die );
	wp_delete_user( $sub );
	wp_set_current_user( 0 );
	$_POST = $_REQUEST = array();
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
} );

T::test( 'editor box saves the audience only when the feature is on', function () {
	wp_set_current_user( 1 );
	$page = sai_post( 'Member price list', 'Gold plan costs 1999 Taka for members.' );

	$_POST = array(
		'softorio_ai_exclude_nonce' => wp_create_nonce( 'softorio_ai_exclude' ),
		'softorio_ai_audience'      => array( 'mode' => 'roles', 'roles' => array( 'customer', 'bogus' ) ),
	);
	PostTypes::save_exclude_box( $page );
	T::same( ',customer,', Audience::for_post( $page ) );

	sai_save_settings( array( 'members_knowledge' => false ) );
	$_POST['softorio_ai_audience'] = array( 'mode' => 'everyone' );
	PostTypes::save_exclude_box( $page );
	T::same( ',customer,', Audience::for_post( $page ), 'feature off: left as it was' );
	sai_members_on();

	$cols = PostTypes::columns( array( 'title' => 'Title', 'date' => 'Date' ) );
	T::same( array( 'title', 'sai_source', 'sai_audience', 'date' ), array_keys( $cols ) );

	$_POST = array();
	wp_set_current_user( 0 );
} );

T::test( 'logged-in visitors: greeting, pre-filled lead form, skip option', function () use ( &$sai_users ) {
	[ , $customer ] = $sai_users;

	sai_save_settings( array( 'greeting' => 'Hi! How can I help?', 'member_greeting' => 'Welcome back, {name}!' ) );
	T::same( 'Hi! How can I help?', Widget::config()['greeting'], 'guests get the normal greeting' );
	T::same( null, Widget::config()['user'], 'no personal data for guests' );

	wp_set_current_user( $customer );
	$c = Widget::config();
	T::same( 'Welcome back, Karim!', $c['greeting'] );
	T::same( 'Karim', $c['user']['name'] );
	T::ok( str_ends_with( $c['user']['email'], '@example.com' ) );
	T::same( false, $c['user']['skipLead'] );

	sai_save_settings( array( 'members_skip_lead' => true ) );
	T::same( true, Widget::config()['user']['skipLead'] );
	wp_set_current_user( 0 );
} );

T::test( 'knowledge settings tab renders the new fields', function () {
	wp_set_current_user( 1 );
	$_GET['tab'] = 'knowledge';
	ob_start();
	\Softorio\AiAssistant\Admin\SettingsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );

	foreach ( array( 'members_knowledge', 'member_greeting', 'members_skip_lead', 'web_resync' ) as $key ) {
		T::ok( str_contains( $html, '[' . $key . ']' ), "field $key" );
	}
} );

foreach ( $sai_users as $sai_uid ) {
	wp_delete_user( $sai_uid );
}
foreach ( get_posts( array( 'post_type' => PostTypes::DOC, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $sai_id ) {
	wp_delete_post( $sai_id, true );
}
foreach ( glob( $sai_tmp . '*' ) as $sai_f ) {
	unlink( $sai_f );
}
rmdir( $sai_tmp );
remove_all_filters( 'softorio_ai_queue_run_on_shutdown' );
sai_reset();
