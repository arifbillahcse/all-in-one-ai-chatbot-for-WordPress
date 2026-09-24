<?php
// phpcs:disable
/**
 * Phase 2: tool calling and the WooCommerce shop assistant.
 * Skipped when WooCommerce is not active on the test site.
 */

use Softorio\AiAssistant\Chat\ChatService;
use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\Llm\OpenAiCompatibleProvider;
use Softorio\AiAssistant\Llm\ToolCall;
use Softorio\AiAssistant\Tools\ToolContext;
use Softorio\AiAssistant\Tools\ToolRegistry;
use Softorio\AiAssistant\Woo\MyOrdersTool;
use Softorio\AiAssistant\Woo\OrderStatusTool;
use Softorio\AiAssistant\Woo\ProductDetailsTool;
use Softorio\AiAssistant\Woo\SearchProductsTool;
use Softorio\AiAssistant\Woo\WooModule;

T::test( 'OpenAI message conversion handles tool calls and results', function () {
	$out = OpenAiCompatibleProvider::convert_messages( array(
		array( 'role' => 'user', 'content' => 'find headphones' ),
		array( 'role' => 'assistant', 'content' => array(
			array( 'type' => 'text', 'text' => 'Searching.' ),
			array( 'type' => 'tool_use', 'id' => 'c1', 'name' => 'search_products', 'input' => (object) array( 'query' => 'headphones' ) ),
		) ),
		array( 'role' => 'user', 'content' => array(
			array( 'type' => 'tool_result', 'tool_use_id' => 'c1', 'content' => '{"products":[]}' ),
		) ),
	) );

	T::same( 'assistant', $out[1]['role'] );
	T::same( 'search_products', $out[1]['tool_calls'][0]['function']['name'] );
	T::same( '{"query":"headphones"}', $out[1]['tool_calls'][0]['function']['arguments'] );
	T::same( array( 'role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"products":[]}' ), $out[2] );
} );

T::test( 'product cards follow the products the answer names', function () {
	$cards = array(
		array( 'type' => 'product', 'name' => 'Alpha Phone' ),
		array( 'type' => 'product', 'name' => 'Beta Phone' ),
		array( 'type' => 'order', 'number' => '5' ),
	);
	T::same( array( 'Alpha Phone', null ), array_column( ChatService::relevant_cards( $cards, 'I recommend the alpha phone.' ), 'name' ) + array( 1 => null ) );
	T::same( 3, count( ChatService::relevant_cards( $cards, 'Here are some options.' ) ), 'none named: keep all' );
} );

T::test( 'phone numbers match across formats, with a minimum length', function () {
	T::ok( OrderStatusTool::phones_match( '+880 1711-000000', '01711000000' ) );
	T::ok( OrderStatusTool::phones_match( '01711000000', '8801711000000' ) );
	T::ok( ! OrderStatusTool::phones_match( '01711000000', '01711000001' ) );
	T::ok( ! OrderStatusTool::phones_match( '01711000000', '000000' ), 'too short' );
} );

if ( ! WooModule::woocommerce_active() ) {
	echo "  (WooCommerce not active — shop tests skipped)\n";
	return;
}

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'visitor_hourly_limit' => 1000 ) );

global $wpdb;
foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ) ) ) as $old ) {
	$old->delete( true );
}
foreach ( wc_get_orders( array( 'limit' => -1 ) ) as $old ) {
	$old->delete( true );
}

function sai_product( string $name, string $price, array $extra = array() ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_short_description( $extra['desc'] ?? "$name description." );
	$p->set_status( $extra['status'] ?? 'publish' );
	if ( isset( $extra['sale'] ) ) {
		$p->set_sale_price( $extra['sale'] );
	}
	if ( isset( $extra['stock'] ) ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $extra['stock'] );
	}
	if ( isset( $extra['visibility'] ) ) {
		$p->set_catalog_visibility( $extra['visibility'] );
	}
	if ( isset( $extra['cat'] ) ) {
		$p->set_category_ids( array( $extra['cat'] ) );
	}
	$p->save();
	return $p;
}

$audio      = wp_insert_term( 'Audio Gear', 'product_cat' );
$audio_id   = is_wp_error( $audio ) ? (int) get_term_by( 'name', 'Audio Gear', 'product_cat' )->term_id : (int) $audio['term_id'];
$headphones = sai_product( 'Wireless Headphones Pro', '4500', array( 'cat' => $audio_id, 'stock' => 20, 'desc' => 'Noise cancelling bluetooth headphones with 30 hour battery.' ) );
$earbuds    = sai_product( 'Budget Earbuds', '1500', array( 'sale' => '1200', 'cat' => $audio_id, 'stock' => 3 ) );
$speakers   = sai_product( 'Studio Monitor Speakers', '25000', array( 'stock' => 0, 'cat' => $audio_id ) );
$secret     = sai_product( 'Secret Prototype Headphones', '99999', array( 'visibility' => 'hidden' ) );
$draft      = sai_product( 'Draft Headphones', '100', array( 'status' => 'draft' ) );

$tshirt = new WC_Product_Variable();
$tshirt->set_name( 'Cotton T-Shirt' );
$size = new WC_Product_Attribute();
$size->set_name( 'Size' );
$size->set_options( array( 'M', 'L' ) );
$size->set_visible( true );
$size->set_variation( true );
$tshirt->set_attributes( array( $size ) );
$tshirt->save();
foreach ( array( 'M' => '800', 'L' => '900' ) as $s => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $tshirt->get_id() );
	$v->set_attributes( array( 'size' => $s ) );
	$v->set_regular_price( $price );
	$v->save();
}
WC_Product_Variable::sync( $tshirt->get_id() );

$customer_id = wp_insert_user( array( 'user_login' => 'shopper' . wp_rand(), 'user_pass' => 'x', 'user_email' => 'shopper' . wp_rand() . '@example.com', 'role' => 'customer', 'first_name' => 'Nusrat' ) );

$guest_order = wc_create_order();
$guest_order->add_product( $headphones, 1 );
$guest_order->set_billing_email( 'Buyer@Example.com' );
$guest_order->set_billing_phone( '+880 1711-000000' );
$guest_order->set_billing_city( 'Dhaka' );
$guest_order->calculate_totals();
$guest_order->update_status( 'processing' );
$guest_order->add_order_note( 'Your parcel was handed to the courier.', 1 );
$guest_order->add_order_note( 'PRIVATE: customer is difficult.', 0 );
$guest_order->save();

$own_order = wc_create_order( array( 'customer_id' => $customer_id ) );
$own_order->add_product( $earbuds, 2 );
$own_order->set_billing_email( 'other@example.com' );
$own_order->calculate_totals();
$own_order->update_status( 'on-hold' );
$own_order->save();

$guest   = new ToolContext( 0, '198.51.100.20' );
$shopper = new ToolContext( $customer_id, '198.51.100.21' );

T::test( 'shop tools are offered only when the owner switches them on', function () use ( $guest ) {
	T::ok( array_key_exists( 'woocommerce', \Softorio\AiAssistant\SettingsSchema::tabs() ), 'WooCommerce settings tab' );
	T::same( array(), ( new ToolRegistry() )->definitions( $guest ), 'off by default' );

	sai_save_settings( array( 'woo_enabled' => true ) );
	$names = array_map( fn( $d ) => $d->name, ( new ToolRegistry() )->definitions( $guest ) );
	T::same( array( 'search_products', 'get_product_details', 'check_order_status' ), $names, 'guest: no list_my_orders' );
} );

T::test( 'product search returns live, visible products only', function () use ( $guest, $headphones, $secret, $draft ) {
	$r = ( new SearchProductsTool() )->run( array( 'query' => 'headphones' ), $guest );
	$names = array_column( $r->data['products'], 'name' );

	T::same( array( 'Wireless Headphones Pro' ), $names, 'hidden and draft products excluded: ' . implode( ', ', $names ) );
	T::ok( str_contains( $r->data['products'][0]['price'], '4,500' ) || str_contains( $r->data['products'][0]['price'], '4500' ), 'live price: ' . $r->data['products'][0]['price'] );
	T::same( 'product-' . $headphones->get_id(), $r->cards[0]['key'] );
	T::same( 'ajax', $r->cards[0]['cart']['mode'], 'simple product: add straight to cart' );

	$r = ( new SearchProductsTool() )->run( array( 'query' => 'noise cancelling wireless headphones black' ), $guest );
	T::ok( in_array( 'Wireless Headphones Pro', array_column( $r->data['products'], 'name' ), true ), 'word-by-word fallback finds it' );
} );

T::test( 'product search filters by budget, category and stock', function () use ( $guest ) {
	$r = ( new SearchProductsTool() )->run( array( 'category' => 'Audio Gear', 'max_price' => 2000 ), $guest );
	T::same( array( 'Budget Earbuds' ), array_column( $r->data['products'], 'name' ), 'budget filter uses the sale price' );
	$price = $r->data['products'][0]['price'];
	T::ok( str_contains( $price, '1,200' ) && str_contains( $price, '(was' ) && str_contains( $price, '1,500' ), 'clean sale price: ' . $price );
	T::ok( ! str_contains( $price, 'Original price' ), 'no screen-reader text' );
	T::ok( str_contains( $r->cards[0]['was'], '1,500' ) && ! str_contains( $r->cards[0]['price'], '1,500' ), 'card: current and struck-out price separate' );
	T::ok( str_contains( $r->data['products'][0]['stock'], 'left' ), 'low stock shown (live: the test order reserved 2 of 3): ' . $r->data['products'][0]['stock'] );

	$r = ( new SearchProductsTool() )->run( array( 'category' => 'audio-gear' ), $guest );
	T::ok( in_array( 'Studio Monitor Speakers', array_column( $r->data['products'], 'name' ), true ), 'out of stock listed by default' );

	sai_save_settings( array( 'woo_hide_out_of_stock' => true ) );
	$r = ( new SearchProductsTool() )->run( array( 'category' => 'audio-gear' ), $guest );
	T::ok( ! in_array( 'Studio Monitor Speakers', array_column( $r->data['products'], 'name' ), true ), 'hidden when the owner says so' );
	sai_save_settings( array( 'woo_hide_out_of_stock' => false ) );

	T::ok( ( new SearchProductsTool() )->run( array(), $guest )->is_error, 'empty search refused' );
	T::same( array(), ( new SearchProductsTool() )->run( array( 'query' => 'refrigerator' ), $guest )->data['products'] );
} );

T::test( 'product details include variations; hidden products stay hidden', function () use ( $guest, $tshirt, $secret ) {
	$r = ( new ProductDetailsTool() )->run( array( 'product_id' => $tshirt->get_id() ), $guest );
	T::same( 'Cotton T-Shirt', $r->data['product']['name'] );
	T::same( 2, count( $r->data['product']['variations'] ) );
	T::ok( str_contains( $r->data['product']['price'], '800' ) && str_contains( $r->data['product']['price'], '900' ), 'variable price range: ' . $r->data['product']['price'] );
	T::same( 'link', $r->cards[0]['cart']['mode'], 'variable product: choose options on the product page' );

	T::ok( ( new ProductDetailsTool() )->run( array( 'product_id' => $secret->get_id() ), $guest )->is_error, 'hidden product refused' );
	T::same( 'Wireless Headphones Pro', ( new ProductDetailsTool() )->run( array( 'name' => 'wireless headphones' ), $guest )->data['product']['name'] ?? null );
} );

T::test( 'guests must prove an order is theirs; failures all look the same', function () use ( $guest, $guest_order ) {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	$tool = new OrderStatusTool();
	$num  = (string) $guest_order->get_order_number();

	$r = $tool->run( array( 'order_number' => $num ), $guest );
	T::ok( $r->is_error && str_contains( $r->data['error'], 'email' ), 'asks for proof' );

	$wrong_email  = $tool->run( array( 'order_number' => $num, 'email' => 'someone@example.com' ), $guest );
	$wrong_number = $tool->run( array( 'order_number' => '999999', 'email' => 'buyer@example.com' ), $guest );
	T::ok( $wrong_email->is_error && $wrong_number->is_error );
	T::same( $wrong_email->data, $wrong_number->data, 'wrong number and wrong email indistinguishable' );

	$ok = $tool->run( array( 'order_number' => '#' . $num, 'email' => 'BUYER@example.com' ), $guest );
	T::ok( ! $ok->is_error, 'email match (case-insensitive)' );
	T::same( 'Processing', $ok->data['order']['status'] );
	T::same( array( 'Wireless Headphones Pro × 1' ), $ok->data['order']['items'] );
	T::same( array( 'Your parcel was handed to the courier.' ), $ok->data['order']['updates_from_shop'], 'customer notes only, never private ones' );
	$json = wp_json_encode( $ok->data );
	T::ok( ! str_contains( strtolower( $json ), 'buyer@example.com' ) && ! str_contains( $json, '1711' ), 'no contact details echoed back' );
	T::ok( ! isset( $ok->cards[0]['url'] ), 'no My Account link for guests' );

	T::ok( ! $tool->run( array( 'order_number' => $num, 'phone' => '01711-000000' ), $guest )->is_error, 'phone match across formats' );

	sai_save_settings( array( 'woo_guest_verify' => 'email' ) );
	T::ok( $tool->run( array( 'order_number' => $num, 'phone' => '01711000000' ), $guest )->is_error, 'phone not accepted in email-only mode' );
	sai_save_settings( array( 'woo_guest_verify' => 'none' ) );
	T::ok( ! $tool->available( $guest ), 'guest lookups can be switched off' );
	sai_save_settings( array( 'woo_guest_verify' => 'email_or_phone' ) );
} );

T::test( 'order lookups are rate limited per network', function () use ( $guest_order ) {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	$tool = new OrderStatusTool();
	$ctx  = new ToolContext( 0, '198.51.100.99' );
	for ( $i = 0; $i < OrderStatusTool::ATTEMPTS_PER_HOUR; $i++ ) {
		$tool->run( array( 'order_number' => (string) ( 100000 + $i ), 'email' => 'guess@example.com' ), $ctx );
	}
	$r = $tool->run( array( 'order_number' => (string) $guest_order->get_order_number(), 'email' => 'buyer@example.com' ), $ctx );
	T::ok( $r->is_error && str_contains( $r->data['error'], 'Too many' ), 'even a correct guess is blocked once over the limit' );
} );

T::test( 'logged-in customers see only their own orders', function () use ( $shopper, $own_order, $guest_order ) {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );

	$mine = ( new OrderStatusTool() )->run( array( 'order_number' => (string) $own_order->get_order_number() ), $shopper );
	T::ok( ! $mine->is_error, 'own order without extra proof' );
	T::ok( isset( $mine->cards[0]['url'] ), 'link to My Account' );

	$theirs = ( new OrderStatusTool() )->run( array( 'order_number' => (string) $guest_order->get_order_number() ), $shopper );
	T::ok( $theirs->is_error, 'someone else\'s order refused without proof' );

	T::ok( ( new MyOrdersTool() )->available( $shopper ) && ! ( new MyOrdersTool() )->available( new ToolContext() ) );
	$list = ( new MyOrdersTool() )->run( array(), $shopper );
	T::same( array( (string) $own_order->get_order_number() ), array_column( $list->data['orders'], 'order_number' ) );
} );

T::test( 'the registry refuses tools that were not offered', function () use ( $guest ) {
	$r = ( new ToolRegistry() )->execute( new ToolCall( 'x', 'list_my_orders', array() ), $guest );
	T::ok( $r->is_error, 'guest cannot run a logged-in-only tool by naming it' );
	T::ok( ( new ToolRegistry() )->execute( new ToolCall( 'x', 'delete_everything', array() ), $guest )->is_error );
} );

T::test( 'chat end to end: the model searches, answers, and cards come from real data', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	FakeHttp::reset();

	FakeHttp::openai_tools( array( array( 'search_products', array( 'query' => 'headphones', 'max_price' => 5000 ) ) ) );
	FakeHttp::openai( 'The **Wireless Headphones Pro** fits your budget and has 30 hours of battery.' );

	$data = sai_chat( 'Any good headphones under 5000?', '', 'shopvisitor00001' )->get_data();

	T::same( 'The **Wireless Headphones Pro** fits your budget and has 30 hours of battery.', $data['reply'] );
	T::same( array( 'Wireless Headphones Pro' ), array_column( $data['cards'], 'name' ) );
	T::same( array(), $data['sources'], 'no page links on a tool-based answer' );

	$first  = FakeHttp::$requests[0]['body'];
	$second = FakeHttp::$requests[1]['body'];
	T::same( 'search_products', $first['tools'][0]['function']['name'] ?? null, 'tools sent to OpenAI' );
	T::ok( str_contains( $first['messages'][0]['content'], 'Shop assistant rules' ), 'shop rules in the prompt' );
	T::same( 'tool', end( $second['messages'] )['role'], 'tool result sent back' );
	T::ok( str_contains( end( $second['messages'] )['content'], 'Wireless Headphones Pro' ), 'result carries the product' );

	$history = new WP_REST_Request( 'GET', '/softorio-ai/v1/history' );
	$history->set_query_params( array( 'conversation_id' => $data['conversation_id'], 'visitor_token' => 'shopvisitor00001' ) );
	$msgs = rest_do_request( $history )->get_data()['messages'];
	T::same( 'Wireless Headphones Pro', $msgs[1]['cards'][0]['name'] ?? null, 'cards survive a page reload' );
} );

T::test( 'Claude receives tools in its own format', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	sai_save_settings( array( 'provider' => 'claude', 'claude_key' => 'sk-ant-test-key-1' ) );
	FakeHttp::reset();

	FakeHttp::push( 200, array( 'model' => 'claude-haiku-4-5', 'content' => array( array( 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_product_details', 'input' => array( 'name' => 'cotton t-shirt' ) ) ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ), 'stop_reason' => 'tool_use' ) );
	FakeHttp::push( 200, array( 'model' => 'claude-haiku-4-5', 'content' => array( array( 'type' => 'text', 'text' => 'The Cotton T-Shirt comes in M and L.' ) ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ), 'stop_reason' => 'end_turn' ) );

	$data = sai_chat( 'What sizes does the t-shirt come in?', '', 'claudevisitor001' )->get_data();
	T::same( 'The Cotton T-Shirt comes in M and L.', $data['reply'] );

	$req = FakeHttp::$requests[1]['body'];
	T::same( 'get_product_details', FakeHttp::$requests[0]['body']['tools'][1]['name'] ?? null );
	$last = end( $req['messages'] );
	T::same( 'tool_result', $last['content'][0]['type'] );
	T::same( 'tu_1', $last['content'][0]['tool_use_id'] );

	sai_save_settings( array( 'provider' => 'openai' ) );
} );

T::test( 'a model stuck calling tools is stopped after 4 rounds', function () {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	FakeHttp::reset();
	for ( $i = 0; $i < 4; $i++ ) {
		FakeHttp::openai_tools( array( array( 'search_products', array( 'query' => 'x' . $i ) ) ) );
	}
	$r = sai_chat( 'loop forever', '', 'loopvisitor00001' );
	T::same( 200, $r->get_status() );
	T::same( 4, count( FakeHttp::$requests ), 'exactly 4 paid calls' );
	T::ok( str_contains( $r->get_data()['reply'], 'could not finish' ), $r->get_data()['reply'] );
} );

T::test( 'logged-in shoppers get list_my_orders and are greeted by name', function () use ( $customer_id ) {
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['limits'] );
	FakeHttp::reset();
	wp_set_current_user( $customer_id );

	FakeHttp::openai( 'Hi Nusrat!' );
	sai_chat( 'hello', '', 'loggedvisitor001' );
	$req = FakeHttp::last()['body'];
	T::ok( in_array( 'list_my_orders', array_column( array_column( $req['tools'], 'function' ), 'name' ), true ) );
	T::ok( str_contains( $req['messages'][0]['content'], 'logged in to the website as Nusrat' ) );

	wp_set_current_user( 0 );
} );

T::test( 'products are indexed with categories and attributes, never prices', function () use ( $headphones, $tshirt ) {
	( new \Softorio\AiAssistant\Knowledge\Indexer() )->index_post( $tshirt->get_id(), false );
	( new \Softorio\AiAssistant\Knowledge\Indexer() )->index_post( $headphones->get_id(), false );
	global $wpdb;
	$text = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM ' . Installer::tables()['chunks'] . ' WHERE post_id = %d', $headphones->get_id() ) );
	T::ok( str_contains( $text, 'Category: Audio Gear' ), $text );
	T::ok( ! str_contains( $text, '4500' ) && ! str_contains( $text, '4,500' ), 'no price in the index' );
	$shirt = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM ' . Installer::tables()['chunks'] . ' WHERE post_id = %d', $tshirt->get_id() ) );
	T::ok( str_contains( $shirt, 'Size: M, L' ), $shirt );
} );

T::test( 'switching the shop assistant off removes the tools at once', function () use ( $guest ) {
	sai_save_settings( array( 'woo_enabled' => false ) );
	T::same( array(), ( new ToolRegistry() )->definitions( $guest ) );
	T::same( null, \Softorio\AiAssistant\Frontend\Widget::config()['woo'] );
} );

sai_reset();
