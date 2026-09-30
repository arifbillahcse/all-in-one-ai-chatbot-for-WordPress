<?php
// phpcs:disable
/**
 * Phase 4: widget look, pop-up, page rules, business hours, quick replies,
 * inline chat, voice input.
 */

use Softorio\AiAssistant\Admin\FlowsPage;
use Softorio\AiAssistant\Admin\SettingsPage;
use Softorio\AiAssistant\Chat\PromptBuilder;
use Softorio\AiAssistant\Flows\FlowStore;
use Softorio\AiAssistant\Frontend\Inline;
use Softorio\AiAssistant\Frontend\Widget;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\SettingsSchema;
use Softorio\AiAssistant\Support\BusinessHours;
use Softorio\AiAssistant\Support\PageRules;

sai_reset();
delete_option( FlowStore::OPTION );
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123' ) );

$sai_old_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Dhaka' );

T::test( 'every Phase 4 feature is off by default', function () {
	$c = Widget::config();
	T::same( null, $c['popup'], 'no pop-up' );
	T::same( null, $c['hours'], 'no business hours' );
	T::same( null, $c['voice'], 'no voice input' );
	T::same( null, $c['flows'], 'no quick replies' );
	T::same( '', $c['avatar'] );
	T::same( '', $c['label'] );
	T::same( 'chat', $c['icon'] );
	T::same( 'all', Settings::get( 'display_mode' ) );
	T::same( '', BusinessHours::prompt_line(), 'prompt unchanged' );
} );

T::test( 'page rules match paths, patterns and full URLs', function () {
	T::ok( PageRules::matches( '/pricing/', '/pricing' ), 'trailing slash ignored' );
	T::ok( PageRules::matches( '/Pricing', "/other\n/pricing/" ), 'case-insensitive, any line' );
	T::ok( PageRules::matches( '/product/blue-shirt/', '/product/*' ), 'wildcard' );
	T::ok( ! PageRules::matches( '/products/', '/product/*' ), 'wildcard is not a prefix match' );
	T::ok( PageRules::matches( '/', '/' ), 'home page' );
	T::ok( ! PageRules::matches( '/about/', '/' ), 'home rule is exact' );
	T::ok( PageRules::matches( '/contact/', home_url( '/contact/' ) ), 'full URL of this site' );
	T::ok( ! PageRules::matches( '/contact/', "# comment\n\n" ), 'comments and blanks ignored' );
	T::ok( ! PageRules::matches( '/a.b/', '/a*b.c' ), 'regex characters are literal' );

	$_SERVER['REQUEST_URI'] = '/shop/?utm=x';
	T::same( '/shop/', PageRules::current_path(), 'query string dropped' );
} );

T::test( 'display rules decide where the floating widget shows', function () {
	$_SERVER['REQUEST_URI'] = '/checkout/';
	T::ok( Widget::should_show(), 'shows everywhere by default' );

	sai_save_settings( array( 'display_mode' => 'exclude', 'display_rules' => "/checkout/\n/my-account/*" ) );
	T::ok( ! Widget::should_show(), 'hidden on excluded page' );
	$_SERVER['REQUEST_URI'] = '/my-account/orders/';
	T::ok( ! Widget::should_show(), 'hidden on excluded pattern' );
	$_SERVER['REQUEST_URI'] = '/blog/';
	T::ok( Widget::should_show(), 'shown elsewhere' );

	sai_save_settings( array( 'display_mode' => 'include', 'display_rules' => '/support/*' ) );
	T::ok( ! Widget::should_show(), 'include mode: hidden off-list' );
	T::same( false, Widget::config()['floating'], 'widget told not to float' );
	$_SERVER['REQUEST_URI'] = '/support/refunds/';
	T::ok( Widget::should_show(), 'include mode: shown on list' );

	sai_save_settings( array( 'display_mode' => 'nonsense' ) );
	T::same( 'all', Settings::get( 'display_mode' ), 'unknown mode rejected' );
} );

T::test( 'pop-up greeting respects its switch, text and pages', function () {
	$_SERVER['REQUEST_URI'] = '/pricing/';
	sai_save_settings( array( 'popup_enabled' => true, 'popup_message' => '' ) );
	T::same( null, Widget::config()['popup'], 'no text, no pop-up' );

	sai_save_settings( array( 'popup_message' => '<b>Need help</b> choosing a plan?', 'popup_delay' => 3 ) );
	$p = Widget::config()['popup'];
	T::same( 'Need help choosing a plan?', $p['message'], 'plain text only' );
	T::same( 3, $p['delay'] );
	T::same( false, $p['mobile'] );

	sai_save_settings( array( 'popup_pages' => '/shop/*' ) );
	T::same( null, Widget::config()['popup'], 'other pages get no pop-up' );
	$_SERVER['REQUEST_URI'] = '/shop/shirts/';
	T::ok( null !== Widget::config()['popup'], 'listed page gets it' );

	sai_save_settings( array( 'popup_enabled' => false, 'popup_pages' => '' ) );
} );

T::test( 'look settings: avatar URL, launcher icon and label are validated', function () {
	sai_save_settings( array( 'avatar_url' => 'javascript:alert(1)', 'launcher_icon' => 'rocket', 'launcher_label' => '<i>Chat with us</i>' ) );
	T::same( '', Settings::get( 'avatar_url' ), 'script URL refused' );
	T::same( 'chat', Settings::get( 'launcher_icon' ), 'unknown icon falls back' );
	T::same( 'Chat with us', Settings::get( 'launcher_label' ) );

	sai_save_settings( array( 'avatar_url' => 'https://example.com/face.png', 'launcher_icon' => 'avatar' ) );
	$c = Widget::config();
	T::same( 'https://example.com/face.png', $c['avatar'] );
	T::same( 'avatar', $c['icon'] );
} );

T::test( 'hours fields parse and normalise, junk is dropped', function () {
	T::same( array( array( 540, 1080 ) ), SettingsSchema::parse_hours( '9:00-18:00' ) );
	T::same( array( array( 540, 780 ), array( 840, 1440 ) ), SettingsSchema::parse_hours( '09.00 – 13:00, 14:00-24:00' ), 'dot, en dash, 24:00' );
	T::same( array(), SettingsSchema::parse_hours( '18:00-09:00' ), 'backwards range dropped' );
	T::same( array(), SettingsSchema::parse_hours( '9:75-10:00' ), 'bad minutes dropped' );
	T::same( array(), SettingsSchema::parse_hours( 'closed' ) );

	sai_save_settings( array( 'hours_mon' => '9:00-13:00, lunch, 14:00-18:00', 'hours_tue' => 'nope' ) );
	T::same( '09:00-13:00, 14:00-18:00', Settings::get( 'hours_mon' ) );
	T::same( '', Settings::get( 'hours_tue' ), 'unparseable day becomes closed' );
	T::same( '', Settings::get( 'hours_fri' ), 'Friday closed by default' );
	T::same( '09:00-18:00', Settings::get( 'hours_sat' ) );
} );

T::test( 'business hours: open/closed and next opening in site time', function () {
	sai_save_settings( array( 'hours_mode' => 'notice', 'hours_mon' => '09:00-13:00, 14:00-18:00', 'hours_tue' => '09:00-18:00', 'hours_offline_message' => 'We reply tomorrow.' ) );
	$tz = new DateTimeZone( 'Asia/Dhaka' );

	// 2026-09-28 is a Monday.
	T::ok( BusinessHours::is_open( new DateTimeImmutable( '2026-09-28 10:00', $tz ) ), 'Mon 10:00 open' );
	T::ok( ! BusinessHours::is_open( new DateTimeImmutable( '2026-09-28 13:30', $tz ) ), 'lunch break closed' );
	T::ok( ! BusinessHours::is_open( new DateTimeImmutable( '2026-09-28 18:00', $tz ) ), 'end is exclusive' );
	T::ok( BusinessHours::is_open( new DateTimeImmutable( '2026-09-28 04:00', new DateTimeZone( 'UTC' ) ) ), 'UTC moment converted to Dhaka (10:00)' );
	T::ok( ! BusinessHours::is_open( new DateTimeImmutable( '2026-10-02 12:00', $tz ) ), 'Friday closed' );

	T::same( '2026-09-28 14:00', BusinessHours::next_open( new DateTimeImmutable( '2026-09-28 13:30', $tz ) )->format( 'Y-m-d H:i' ), 'after lunch' );
	T::same( '2026-10-03 09:00', BusinessHours::next_open( new DateTimeImmutable( '2026-10-02 12:00', $tz ) )->format( 'Y-m-d H:i' ), 'Friday → Saturday' );

	$w = BusinessHours::widget_config();
	T::same( 'notice', $w['mode'] );
	T::same( 360, $w['offset'], 'Dhaka is UTC+6' );
	T::same( array( array( 540, 780 ), array( 840, 1080 ) ), $w['days'][1], 'Monday is index 1 like getDay()' );
	T::same( array(), $w['days'][5], 'Friday empty' );
	T::same( 7, count( $w['dayNames'] ) );
	T::same( 'We reply tomorrow.', $w['message'] );
	T::same( $w, Widget::config()['hours'] );
} );

T::test( 'the assistant is told whether the team is available', function () {
	$line = BusinessHours::prompt_line();
	T::ok( str_contains( $line, 'business hours' ) && str_contains( $line, 'Monday: 09:00-13:00, 14:00-18:00' ), 'schedule in prompt' );
	T::ok( str_contains( $line, 'available right now' ) || str_contains( $line, 'offline right now' ), 'states now' );
	T::ok( str_contains( ( new PromptBuilder() )->build( array() ), $line ), 'included in the system prompt' );

	// All closed: never open, no "back" time.
	$closed = array( 'hours_mode' => 'hide' );
	foreach ( array_keys( SettingsSchema::weekdays() ) as $d ) {
		$closed[ 'hours_' . $d ] = '';
	}
	sai_save_settings( $closed );
	T::ok( ! BusinessHours::is_open() );
	T::same( null, BusinessHours::next_open() );
	T::ok( str_contains( BusinessHours::prompt_line(), 'offline right now (' ), 'no return time' );

	sai_save_settings( array( 'hours_mode' => 'off' ) );
	T::same( null, Widget::config()['hours'] );
} );

T::test( 'quick replies: tree is cleaned, limited and link-checked', function () {
	$count = 0;
	$tree  = FlowStore::sanitize( array(
		array( 'label' => '<script>x</script>Delivery', 'action' => 'reply', 'reply' => 'Where to?', 'children' => array(
			array( 'label' => 'Dhaka', 'reply' => '60 Taka' ),
			array( 'label' => '', 'reply' => 'no label → dropped' ),
		) ),
		array( 'label' => 'Docs', 'action' => 'link', 'url' => 'javascript:alert(1)' ),
		array( 'label' => 'Site', 'action' => 'link', 'url' => 'not a url' ),
		array( 'label' => 'Mail', 'action' => 'link', 'url' => 'mailto:help@example.com' ),
		array( 'label' => 'Ask', 'action' => 'ask_ai', 'prompt' => 'Refund policy?', 'url' => 'https://ignored.example', 'children' => array( array( 'label' => 'x' ) ) ),
		array( 'label' => 'Hack', 'action' => 'eval' ),
		'not a node',
	), 1, $count );

	T::same( 6, count( $tree ) );
	T::same( 'Delivery', $tree[0]['label'], 'tags stripped' );
	T::same( 1, count( $tree[0]['children'] ), 'unlabelled child dropped' );
	T::same( 'reply', $tree[0]['children'][0]['action'], 'default action' );
	T::ok( '' !== $tree[0]['id'], 'id assigned' );
	T::same( 'reply', $tree[1]['action'], 'script link becomes a plain reply' );
	T::same( '', $tree[1]['url'] );
	T::same( '', $tree[2]['url'], 'text is not "repaired" into a URL' );
	T::same( 'link', $tree[3]['action'] );
	T::same( 'mailto:help@example.com', $tree[3]['url'] );
	T::same( '', $tree[4]['url'], 'only links keep a URL' );
	T::same( array(), $tree[4]['children'], 'only reply buttons have sub-menus' );
	T::same( 'reply', $tree[5]['action'], 'unknown action' );

	// Depth limit.
	$deep = array( 'label' => 'L5' );
	for ( $i = 4; $i >= 1; $i-- ) {
		$deep = array( 'label' => 'L' . $i, 'children' => array( $deep ) );
	}
	$count = 0;
	$t     = FlowStore::sanitize( array( $deep ), 1, $count );
	T::same( array(), $t[0]['children'][0]['children'][0]['children'][0]['children'], 'depth capped at ' . FlowStore::MAX_DEPTH );

	// Size limit.
	$many  = array_fill( 0, 200, array( 'label' => 'b' ) );
	$count = 0;
	T::same( FlowStore::MAX_NODES, count( FlowStore::sanitize( $many, 1, $count ) ) );

	$ex    = FlowStore::example();
	$count = 0;
	T::same( count( $ex ), count( FlowStore::sanitize( $ex, 1, $count ) ), 'the example is valid' );
} );

T::test( 'quick replies: admin save handler checks nonce and permission', function () {
	$stop = static function () {
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $stop );

	wp_set_current_user( 1 );
	$_POST = $_REQUEST = array(
		'flows'         => wp_slash( wp_json_encode( array( array( 'label' => 'Hours', 'reply' => '9–6 "daily"' ) ) ) ),
		'flows_enabled' => '1',
		'_wpnonce'      => wp_create_nonce( 'softorio_ai_save_flows' ),
	);
	try {
		FlowsPage::save();
	} catch ( RuntimeException $e ) {
		T::same( 'redirect', $e->getMessage() );
	}
	T::same( 'Hours', FlowStore::get()[0]['label'] );
	T::same( '9–6 "daily"', FlowStore::get()[0]['reply'], 'unslashed once' );
	T::same( true, Settings::get( 'flows_enabled' ) );
	T::same( FlowStore::get(), Widget::config()['flows'], 'widget receives the tree' );

	// Bad nonce: nothing changes.
	$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'bad';
	$_POST['flows']    = '[]';
	add_filter( 'wp_die_handler', $die = static fn() => static function () {
		throw new RuntimeException( 'died' );
	} );
	try {
		FlowsPage::save();
		T::ok( false, 'should have died' );
	} catch ( RuntimeException $e ) {
		T::same( 'died', $e->getMessage() );
	}
	T::same( 1, count( FlowStore::get() ), 'tree kept' );

	// Subscriber: refused.
	$sub = wp_insert_user( array( 'user_login' => 'sai_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	try {
		FlowsPage::save();
		T::ok( false, 'should have died' );
	} catch ( RuntimeException $e ) {
		T::same( 'died', $e->getMessage() );
	}
	T::same( 1, count( FlowStore::get() ) );

	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_redirect', $stop );
	wp_delete_user( $sub );
	wp_set_current_user( 0 );
	$_POST = $_REQUEST = array();

	sai_save_settings( array( 'flows_enabled' => false ) );
	T::same( null, Widget::config()['flows'], 'switch off hides them, tree stays' );
	T::same( 1, count( FlowStore::get() ) );
} );

T::test( 'voice input config', function () {
	sai_save_settings( array( 'voice_input' => true, 'voice_lang' => 'bn-BD' ) );
	T::same( array( 'lang' => 'bn-BD' ), Widget::config()['voice'] );
	sai_save_settings( array( 'voice_input' => false ) );
} );

function sai_inline_reset(): void {
	( new ReflectionProperty( Inline::class, 'rendered' ) )->setValue( null, false );
}

function sai_inline( string $shortcode ): string {
	sai_inline_reset();
	return do_shortcode( $shortcode );
}

T::test( 'inline chat: shortcode and block render a container and load the script', function () {
	sai_inline_reset();
	$html = do_shortcode( '[ai_chatbot height="600"]' );
	T::same( '', do_shortcode( '[ai_chatbot]' ), 'only one chat per page' );
	T::ok( str_contains( $html, 'data-aicb-inline="1"' ), 'container' );
	T::ok( str_contains( $html, 'height:600px' ) );
	T::ok( wp_script_is( 'softorio-ai-widget', 'enqueued' ), 'script loaded' );

	T::ok( str_contains( sai_inline( '[ai_chatbot height="50"]' ), 'height:320px' ), 'height clamped (min)' );
	T::ok( str_contains( sai_inline( '[ai_chatbot height="9999"]' ), 'height:1200px' ), 'height clamped (max)' );
	T::ok( str_contains( sai_inline( '[ai_chatbot height="abc"]' ), 'height:320px' ), 'junk height' );

	T::ok( WP_Block_Type_Registry::get_instance()->is_registered( 'all-in-one-ai-chatbot/chat' ), 'block registered' );
	sai_inline_reset();
	$block = render_block( array( 'blockName' => 'all-in-one-ai-chatbot/chat', 'attrs' => array( 'height' => 480 ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	T::ok( str_contains( $block, 'height:480px' ), 'block renders' );

	sai_save_settings( array( 'enabled' => false ) );
	sai_inline_reset();
	T::same( '', Inline::render( 500 ), 'nothing when the assistant is off' );
	sai_save_settings( array( 'enabled' => true ) );
} );

T::test( 'widget settings tab renders the new fields', function () {
	wp_set_current_user( 1 );
	$_GET['tab'] = 'widget';
	ob_start();
	SettingsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );

	foreach ( array( 'avatar_url', 'launcher_icon', 'launcher_label', 'popup_message', 'display_rules', 'hours_mode', 'hours_mon', 'hours_sun', 'voice_input' ) as $key ) {
		T::ok( str_contains( $html, '[' . $key . ']' ), "field $key" );
	}
	T::ok( str_contains( $html, 'sai-media' ), 'media picker button' );
} );

update_option( 'timezone_string', $sai_old_tz );
delete_option( FlowStore::OPTION );
$_SERVER['REQUEST_URI'] = '/';
sai_reset();

T::test( 'widget strings: the network "offline" message is not overwritten by the hours status', function () {
	$i18n = \Softorio\AiAssistant\Frontend\Widget::config()['i18n'];
	T::ok( str_contains( $i18n['offline'], 'connection' ), $i18n['offline'] );
	T::same( 'Offline', $i18n['statusOffline'] );
	T::same( 'Online', $i18n['statusOnline'] );
} );
