<?php
// phpcs:disable
/**
 * Phase 8: analytics, charts and report emails.
 */

use Softorio\AiAssistant\Admin\AnalyticsPage;
use Softorio\AiAssistant\Analytics\Charts;
use Softorio\AiAssistant\Analytics\Report;
use Softorio\AiAssistant\Analytics\Stats;
use Softorio\AiAssistant\Installer;

sai_reset();
sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123', 'retention_days' => 90, 'feedback' => true ) );
delete_option( Stats::GAPS_DISMISSED );
delete_option( 'softorio_ai_report_last' );

$sai_tz_before = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Dhaka' );

/** UTC "Y-m-d H:i:s" of a local (Dhaka) time N days ago at H:i. */
function sai_at( int $days_ago, string $time ): string {
	$local = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-$days_ago days" )->format( 'Y-m-d' ) . ' ' . $time;
	return ( new DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function sai_local_date( int $days_ago ): string {
	return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "-$days_ago days" )->format( 'Y-m-d' );
}

function sai_conv( string $at, string $page = '' ): int {
	global $wpdb;
	$wpdb->insert( Installer::tables()['conversations'], array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'visitor_hash' => str_repeat( 'a', 64 ), 'page_url' => $page, 'created_at' => $at, 'updated_at' => $at ) );
	return (int) $wpdb->insert_id;
}

function sai_msg( int $conv, string $role, string $content, string $at, array $extra = array() ): int {
	global $wpdb;
	$wpdb->insert( Installer::tables()['messages'], array_merge( array( 'conversation_id' => $conv, 'role' => $role, 'content' => $content, 'created_at' => $at ), $extra ) );
	return (int) $wpdb->insert_id;
}

function sai_qa( int $conv, string $q, string $a, string $at, array $extra = array() ): int {
	sai_msg( $conv, 'user', $q, $at );
	return sai_msg( $conv, 'assistant', $a, $at, $extra );
}

function sai_lead_at( string $at, int $conv = 0 ): void {
	global $wpdb;
	$wpdb->insert( Installer::tables()['leads'], array( 'conversation_id' => $conv, 'name' => 'X', 'email' => 'x@example.com', 'source' => 'fallback', 'created_at' => $at, 'updated_at' => $at ) );
}

// ── Seed ────────────────────────────────────────────────────────────────────
// Yesterday (every time is in the past, whenever the tests run): 3 conversations (one at 01:30 Dhaka = the previous day in UTC).
$c1 = sai_conv( sai_at( 1, '01:30:00' ), home_url( '/pricing/' ) );
sai_qa( $c1, 'How much is delivery?', 'Delivery is 60 Taka.', sai_at( 1, '01:30:10' ), array( 'cost' => 0.002, 'rating' => 1, 'sources' => wp_json_encode( array( array( 'title' => 'Delivery Information', 'url' => home_url( '/delivery/' ) ) ) ) ) );
sai_msg( $c1, 'user', 'thanks!', sai_at( 1, '01:31:00' ) );
sai_msg( $c1, 'user', 'How much is delivery?', sai_at( 1, '01:32:00' ) );

$c2 = sai_conv( sai_at( 1, '14:00:00' ), home_url( '/pricing/' ) );
sai_qa( $c2, 'delivery how much', 'It is 60 Taka inside Dhaka.', sai_at( 1, '14:00:05' ), array( 'cost' => 0.003, 'rating' => -1, 'sources' => wp_json_encode( array( 'sources' => array( array( 'title' => 'Delivery Information', 'url' => home_url( '/delivery/' ) ) ), 'cards' => array() ) ) ) );
sai_qa( $c2, 'Do you have a store in Sylhet?', 'I do not have that information.', sai_at( 1, '14:01:00' ), array( 'unanswered' => 1, 'cost' => 0.001 ) );
sai_lead_at( sai_at( 1, '14:02:00' ), $c2 );

$c3 = sai_conv( sai_at( 1, '22:00:00' ), home_url( '/' ) );
sai_qa( $c3, 'ডেলিভারি চার্জ কত?', 'ঢাকার ভিতরে ৬০ টাকা।', sai_at( 1, '22:00:05' ), array( 'cost' => 0.002 ) );
sai_qa( $c3, 'store in sylhet do you have?', 'Sorry, I do not know.', sai_at( 1, '22:01:00' ), array( 'unanswered' => 1 ) );
// Live chat: waited 90 seconds.
sai_msg( $c3, 'system', 'Connecting…', sai_at( 1, '22:02:00' ), array( 'sources' => wp_json_encode( array( 'live' => array( 'event' => 'waiting' ) ) ) ) );
sai_msg( $c3, 'system', 'Karim joined the chat.', sai_at( 1, '22:03:30' ), array( 'sources' => wp_json_encode( array( 'live' => array( 'event' => 'joined' ) ) ) ) );
sai_msg( $c3, 'agent', 'Hi, how can I help?', sai_at( 1, '22:03:40' ), array( 'agent_id' => 1 ) );

// Three days ago: 1 conversation, 1 lead. A waiting visitor nobody answered.
$c4 = sai_conv( sai_at( 3, '10:00:00' ), home_url( '/pricing/' ) );
sai_qa( $c4, 'How much is delivery??', 'Delivery is 60 Taka.', sai_at( 3, '10:00:05' ), array( 'cost' => 0.004 ) );
sai_lead_at( sai_at( 3, '10:05:00' ), $c4 );
sai_msg( $c4, 'system', 'Connecting…', sai_at( 3, '10:06:00' ), array( 'sources' => wp_json_encode( array( 'live' => array( 'event' => 'waiting' ) ) ) ) );
sai_msg( $c4, 'system', 'Busy.', sai_at( 3, '10:09:00' ), array( 'sources' => wp_json_encode( array( 'live' => array( 'event' => 'timeout' ) ) ) ) );

// Ten days ago (the "previous" period for a 7-day view).
$c5 = sai_conv( sai_at( 10, '12:00:00' ) );
sai_qa( $c5, 'Opening hours?', 'We open at 10.', sai_at( 10, '12:00:05' ), array( 'cost' => 0.01 ) );

T::test( 'daily series in the site timezone', function () {
	$stats  = new Stats();
	$series = $stats->series( 7 );

	T::same( 7, count( $series ) );
	T::same( sai_local_date( 0 ), array_key_last( $series ), 'ends today' );

	$today = $series[ sai_local_date( 1 ) ];
	T::same( 3, $today['conversations'], 'the 01:30 chat counts on its Dhaka date, although in UTC it is the day before' );
	T::same( 7, $today['messages'] );
	T::same( 5, $today['answers'] );
	T::same( 2, $today['unanswered'] );
	T::same( 1, $today['up'] );
	T::same( 1, $today['down'] );
	T::same( 1, $today['leads'] );
	T::same( 1, $today['live'] );
	T::ok( abs( $today['cost'] - 0.008 ) < 1e-9, 'cost' );

	T::same( 0, $series[ sai_local_date( 2 ) ]['conversations'], 'the day before is empty' );
	T::same( 1, $series[ sai_local_date( 3 ) ]['conversations'] );
} );

T::test( 'totals, rates and the previous period', function () {
	$p   = ( new Stats() )->period( 7 );
	$cur = $p['current'];

	T::same( 4, $cur['conversations'] );
	T::same( 2, $cur['leads'] );
	T::ok( abs( $cur['answered_rate'] - 4 / 6 ) < 1e-9, 'answered rate' );
	T::same( 0.5, $cur['satisfaction'] );
	T::same( 0.5, $cur['conversion'] );
	T::ok( abs( $cur['cost_per_chat'] - 0.003 ) < 1e-9 );
	T::same( 1, $p['previous']['conversations'], 'the chat 10 days ago is in the period before' );

	$empty = Stats::totals( array( 'x' => Stats::zero() ) );
	T::same( null, $empty['satisfaction'], 'no ratings = no percentage (not 0%)' );
	T::same( null, $empty['answered_rate'] );
} );

T::test( 'snapshots keep history after chats are deleted', function () {
	global $wpdb;
	$daily = Installer::tables()['daily'];

	( new Stats() )->snapshot_recent( 3 );
	$row = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT stats FROM $daily WHERE day = %s", sai_local_date( 3 ) ) ), true );
	T::same( 1, $row['conversations'], 'finished days saved' );
	T::same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $daily WHERE day = %s", sai_local_date( 0 ) ) ), 'today is not final yet' );
	T::same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $daily WHERE day = %s", sai_local_date( 2 ) ) ), 'empty days saved too' );

	( new Stats() )->snapshot_recent( 3 );
	T::same( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $daily" ), 'saving again replaces' );

	// A day beyond retention whose chats are gone: the snapshot is used.
	$old = sai_local_date( 40 );
	$wpdb->insert( $daily, array( 'day' => $old, 'stats' => wp_json_encode( array( 'conversations' => 12, 'leads' => 3, 'cost' => 0.5 ) ), 'updated_at' => current_time( 'mysql', true ) ) );
	sai_save_settings( array( 'retention_days' => 30 ) );
	$series = ( new Stats() )->series( 90 );
	T::same( 12, $series[ $old ]['conversations'] );
	T::same( 0, $series[ $old ]['answers'], 'missing metrics are zero' );
	T::same( 3, $series[ sai_local_date( 1 ) ]['conversations'], 'recent days still live' );
	sai_save_settings( array( 'retention_days' => 90 ) );
} );

T::test( 'busiest times heatmap', function () {
	$grid = ( new Stats() )->heatmap( 7 );
	$day  = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' );
	$w    = (int) $day->format( 'w' );
	T::same( 1, $grid[ $w ][1], '01:30 yesterday' );
	T::same( 1, $grid[ $w ][14] );
	T::same( 1, $grid[ $w ][22] );
	T::same( 4, array_sum( array_map( 'array_sum', $grid ) ) );
} );

T::test( 'top questions group the same question asked differently', function () {
	$top = ( new Stats() )->top_questions( 7 );
	T::same( 'delivery much', $top[0]['signature'], '"how" and "is" are stopwords' );
	T::same( 4, $top[0]['count'], '"How much is delivery?" ×2, "delivery how much", "How much is delivery??"' );
	T::same( 'How much is delivery?', $top[0]['example'], 'the most common wording' );
	T::ok( ! in_array( 'thanks', array_column( $top, 'signature' ), true ), 'chatter ignored' );
	T::ok( in_array( 'ডেলিভারি চার্জ কত?', array_column( $top, 'example' ), true ), 'Bangla' );
	T::same( Stats::signature( 'Do you have a store in Sylhet?' ), Stats::signature( 'store in sylhet do you have?' ) );
	T::same( '', Stats::signature( 'ok thanks!' ) );
} );

T::test( 'knowledge gaps: grouped, dismissible, and they come back when asked again', function () {
	$stats = new Stats();
	$gaps  = $stats->gaps( 7 );
	T::same( 1, count( $gaps ) );
	T::same( 2, $gaps[0]['count'], 'two wordings of the Sylhet question' );
	T::ok( in_array( $gaps[0]['example'], array( 'Do you have a store in Sylhet?', 'store in sylhet do you have?' ), true ), 'the question, not the answer' );

	Stats::dismiss_gap( $gaps[0]['signature'] );
	T::same( array(), $stats->gaps( 7 ), 'dismissed' );
	T::same( 1, count( $stats->gaps( 7, 10, true ) ), 'still there when asked for' );

	$c = sai_conv( gmdate( 'Y-m-d H:i:s', time() + 60 ) );
	sai_qa( $c, 'Any store in Sylhet?', 'I do not know.', gmdate( 'Y-m-d H:i:s', time() + 60 ), array( 'unanswered' => 1 ) );
	T::same( 1, count( $stats->gaps( 7 ) ), 'asked again after dismissal: back' );

	$url = Report::add_answer_url( 'Do you have a store in Sylhet?' );
	T::ok( str_contains( $url, 'post-new.php' ) && str_contains( $url, 'post_type=softorio_ai_doc' ) && str_contains( $url, 'post_title=Do%20you%20have' ), $url );
} );

T::test( 'rated answers, pages, knowledge used, live chat', function () {
	$stats = new Stats();

	$low = $stats->low_rated( 7 );
	T::same( 1, count( $low ) );
	T::same( 'delivery how much', $low[0]['question'] );

	$pages = $stats->top_pages( 7 );
	T::same( home_url( '/pricing/' ), $pages[0]['page'] );
	T::same( 3, $pages[0]['count'] );

	$sources = $stats->top_sources( 7 );
	T::same( 'Delivery Information', $sources[0]['title'] );
	T::same( 2, $sources[0]['count'], 'both source formats read' );

	$live = $stats->live( 7 );
	T::same( 1, $live['chats'] );
	T::same( 1, $live['answered'] );
	T::same( 1, $live['missed'] );
	T::same( 90, $live['avg_wait'] );
} );

T::test( 'charts: SVG with labels and the numbers behind them', function () {
	$series = ( new Stats() )->series( 7 );
	$svg    = Charts::bars( array_map( static fn( $d ) => $d['conversations'], $series ), array_map( static fn( $d ) => $d['leads'], $series ), array( 'bars' => 'Conversations', 'line' => 'Leads' ) );

	T::same( 7, substr_count( $svg, 'class="sai-bar"' ) );
	$tot = Stats::totals( $series );
	T::ok( str_contains( $svg, 'role="img"' ) && str_contains( $svg, 'aria-label="Conversations: ' . $tot['conversations'] . ', Leads: ' . $tot['leads'] . '"' ), 'accessible summary' );
	T::ok( str_contains( $svg, '<polyline' ) );
	T::ok( str_contains( $svg, '<details class="sai-chart-data">' ), 'data table' );

	$zero = Charts::bars( array( '2026-01-01' => 0, '2026-01-02' => 0 ) );
	T::ok( ! str_contains( $zero, 'NAN' ) && ! str_contains( $zero, 'INF' ), 'all zero is fine' );

	$money = Charts::bars( array( '2026-01-01' => 0.004 ), array(), array( 'bars' => 'Cost' ), 'money' );
	T::ok( str_contains( $money, '$0.004' ) );

	$heat = Charts::heatmap( ( new Stats() )->heatmap( 7 ), array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) );
	T::same( 168, substr_count( $heat, 'class="sai-heat"' ) );
	T::ok( str_contains( $heat, '<svg' ) && ! str_contains( $heat, '<script' ) );

	$xss = Charts::bars( array( '<script>' => 1 ), array(), array( 'bars' => '<img src=x onerror=alert(1)>' ) );
	T::ok( ! str_contains( $xss, '<script>' ) && ! str_contains( $xss, '<img' ), 'labels escaped' );
} );

T::test( 'analytics screen renders every range', function () {
	wp_set_current_user( 1 );
	foreach ( AnalyticsPage::RANGES as $days ) {
		$_GET['days'] = (string) $days;
		ob_start();
		AnalyticsPage::render();
		$html = (string) ob_get_clean();
		T::ok( str_contains( $html, 'sai-kpis' ) && str_contains( $html, '<svg' ), "range $days" );
	}
	$_GET['days'] = '7';
	ob_start();
	AnalyticsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['days'] );
	wp_set_current_user( 0 );

	T::ok( str_contains( $html, 'Knowledge gaps' ) && str_contains( $html, 'Add the answer' ) );
	T::ok( str_contains( $html, 'How much is delivery?' ) );
	T::ok( str_contains( $html, 'average wait 1 min 30 s' ), 'live wait shown' );
	$rate = ( new Stats() )->period( 7 )['current']['answered_rate'];
	T::ok( str_contains( $html, number_format_i18n( 100 * $rate ) . '%' ), 'answered rate' );
	T::ok( str_contains( $html, '▲' ), 'change against the previous period' );
} );

T::test( 'report email: content, schedule, recipients', function () {
	$report = Report::build( 7 );
	T::ok( str_contains( $report['subject'], 'Chatbot report' ) );
	T::ok( str_contains( $report['html'], 'What visitors asked most' ) );
	T::ok( str_contains( $report['html'], 'Add the answer' ), 'gaps with a link' );
	T::ok( str_contains( $report['html'], 'softorio-ai-analytics' ) );

	sai_save_settings( array( 'report_recipients' => 'boss@example.com, bad-address, me@agency.com' ) );
	T::same( array( 'boss@example.com', 'me@agency.com' ), Report::recipients() );
	sai_save_settings( array( 'report_recipients' => '', 'notify_email' => 'owner@example.com' ) );
	T::same( array( 'owner@example.com' ), Report::recipients(), 'falls back to the alert email' );

	global $wpdb;
	$jobs = Installer::tables()['jobs'];
	$wpdb->query( "DELETE FROM $jobs" );
	add_filter( 'softorio_ai_queue_run_on_shutdown', '__return_false' );

	T::same( false, Report::maybe_send(), 'off by default' );
	sai_save_settings( array( 'report_frequency' => 'weekly' ) );
	T::same( true, Report::maybe_send(), 'first run of the week sends' );
	T::same( false, Report::maybe_send(), 'once per week' );
	$payload = json_decode( (string) $wpdb->get_var( "SELECT payload FROM $jobs WHERE type = 'email.send'" ), true );
	T::same( 'owner@example.com', $payload['to'] );

	sai_save_settings( array( 'report_frequency' => 'monthly' ) );
	T::same( true, Report::maybe_send(), 'switching to monthly starts a new schedule' );
	$wpdb->query( "DELETE FROM $jobs" );

	FakeMail::$sent = array();
	T::ok( str_contains( Report::send_now(), 'owner@example.com' ) );
	T::same( 1, count( FakeMail::$sent ) );
	T::ok( str_contains( FakeMail::$sent[0]['subject'], 'Chatbot report' ) );
	remove_all_filters( 'softorio_ai_queue_run_on_shutdown' );
} );

T::test( 'settings: report fields and button', function () {
	wp_set_current_user( 1 );
	$_GET['tab'] = 'notify';
	ob_start();
	\Softorio\AiAssistant\Admin\SettingsPage::render();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	wp_set_current_user( 0 );
	T::ok( str_contains( $html, '[report_frequency]' ) && str_contains( $html, '[report_recipients]' ) );
	T::ok( str_contains( $html, 'Send a report now' ) );
} );

update_option( 'timezone_string', $sai_tz_before );
delete_option( Stats::GAPS_DISMISSED );
delete_option( 'softorio_ai_report_last' );

T::test( 'rates change in points, counts in percent', function () {
	T::ok( str_contains( Report::change( 0.72, 0.67, true, 'percent' ), '▲ 5 pts' ) );
	T::ok( str_contains( Report::change( 0.60, 0.67, true, 'percent' ), '#b91c1c' ), 'a drop in a good rate is red' );
	T::ok( str_contains( Report::change( 12, 10, true ), '▲ 20%' ) );
	T::ok( str_contains( Report::change( 12, 10, false ), '#b91c1c' ), 'a cost rise is red' );
	T::ok( str_contains( Report::change( 5, 0, true ), '&nbsp;' ), 'nothing to compare with' );
} );
sai_reset();
