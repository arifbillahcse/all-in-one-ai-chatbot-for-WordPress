<?php
// phpcs:disable
/**
 * Phase 9: security regressions, uninstall, translations. Runs last: the
 * uninstall test drops and recreates the plugin's tables.
 */

use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\PostTypes;
use Softorio\AiAssistant\Settings;

sai_reset();

T::test( 'Knowledge Articles are not readable through the public REST API', function () {
	$id = wp_insert_post( array( 'post_type' => PostTypes::DOC, 'post_status' => 'publish', 'post_title' => 'Internal refund rules', 'post_content' => 'Staff may refund up to 5000 Taka.' ) );

	wp_set_current_user( 0 );
	T::same( 401, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . PostTypes::DOC ) )->get_status(), 'guest: list' );
	T::same( 401, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . PostTypes::DOC . '/' . $id ) )->get_status(), 'guest: single' );

	$sub = wp_insert_user( array( 'user_login' => 'sai_rest_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	T::same( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . PostTypes::DOC . '/' . $id ) )->get_status(), 'subscriber' );

	$editor = wp_insert_user( array( 'user_login' => 'sai_rest_ed_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	wp_set_current_user( $editor );
	$r = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . PostTypes::DOC . '/' . $id ) );
	T::same( 200, $r->get_status(), 'editors can (the block editor needs it)' );

	$search = new WP_REST_Request( 'GET', '/wp/v2/search' );
	$search->set_param( 'search', 'Internal refund' );
	wp_set_current_user( 0 );
	T::same( array(), rest_do_request( $search )->get_data(), 'not in the public search endpoint' );

	wp_delete_user( $sub );
	wp_delete_user( $editor );
	wp_delete_post( $id, true );
} );

T::test( 'translations: bundled languages folder, nothing translated too early', function () {
	T::ok( str_contains( (string) file_get_contents( SOFTORIO_AI_FILE ), 'Domain Path:       /languages' ) );
	T::ok( is_dir( SOFTORIO_AI_DIR . 'languages' ), 'languages folder shipped' );
	T::ok( file_exists( SOFTORIO_AI_DIR . 'languages/all-in-one-ai-chatbot.pot' ), 'template for translators' );
	T::same( 0, has_action( 'plugins_loaded', array( \Softorio\AiAssistant\Plugin::class, 'boot' ) ) ?: 0, 'no longer booted on plugins_loaded' );
} );

T::test( 'Bangla translation of what visitors see', function () {
	load_textdomain( 'all-in-one-ai-chatbot', SOFTORIO_AI_DIR . 'languages/all-in-one-ai-chatbot-bn_BD.mo' );
	$i18n = \Softorio\AiAssistant\Frontend\Widget::config()['i18n'];
	T::same( 'পাঠান', $i18n['send'] );
	T::same( 'আপনার প্রশ্ন লিখুন…', $i18n['placeholder'] );
	T::same( 'আপনি %s-এর সাথে কথা বলছেন', $i18n['liveWith'], 'placeholders kept' );
	unload_textdomain( 'all-in-one-ai-chatbot' );
	T::same( 'Send', \Softorio\AiAssistant\Frontend\Widget::config()['i18n']['send'] );

	foreach ( array( 'mo', 'l10n.php', 'po' ) as $ext ) {
		T::ok( file_exists( SOFTORIO_AI_DIR . 'languages/all-in-one-ai-chatbot-bn_BD.' . $ext ), $ext );
	}
} );

T::test( 'setup wizard: steps render, each saves only its own fields', function () {
	wp_set_current_user( 1 );
	foreach ( array_keys( \Softorio\AiAssistant\Admin\SetupWizard::steps() ) as $step ) {
		$_GET['step'] = (string) $step;
		ob_start();
		\Softorio\AiAssistant\Admin\SetupWizard::render();
		$html = (string) ob_get_clean();
		T::ok( str_contains( $html, 'sai-wizard-steps' ), "step $step" );
		if ( $step < 5 ) {
			T::ok( str_contains( $html, 'action="' . esc_url( admin_url( 'options.php' ) ) . '"' ), "step $step posts to options.php" );
			T::ok( str_contains( $html, 'name="_wp_http_referer" value="' . esc_attr( wp_make_link_relative( \Softorio\AiAssistant\Admin\SetupWizard::url( $step + 1 ) ) ) . '"' ), "step $step returns to the next step" );
		}
	}
	unset( $_GET['step'] );

	// The page stays in the submenu (WordPress refuses admin pages it cannot
	// find there) and is only hidden from view.
	global $submenu;
	$submenu = array();
	\Softorio\AiAssistant\Admin\Menu::register();
	\Softorio\AiAssistant\Admin\Menu::order();
	T::ok( in_array( \Softorio\AiAssistant\Admin\SetupWizard::SLUG, array_column( $submenu[ \Softorio\AiAssistant\Admin\Menu::SLUG ], 2 ), true ), 'setup page registered under the menu' );
	ob_start();
	\Softorio\AiAssistant\Admin\Menu::hide_setup();
	T::ok( str_contains( (string) ob_get_clean(), 'page=softorio-ai-setup"]{display:none}' ), 'and hidden from it' );

	$before = Settings::all();
	$after  = \Softorio\AiAssistant\Admin\SettingsPage::sanitize(
		array(
			'_tab'           => 'wizard',
			'_fields'        => 'assistant_name,greeting',
			'assistant_name' => 'Rina',
			'greeting'       => 'Hello!',
			'color'          => '#ff0000',
		)
	);
	T::same( 'Rina', $after['assistant_name'] );
	T::same( 'Hello!', $after['greeting'] );
	T::same( $before['color'], $after['color'], 'fields outside the step untouched' );
	T::same( $before['streaming'], $after['streaming'], 'unticked checkboxes of other tabs not switched off' );

	// Activation opens the wizard once, only when not configured.
	delete_option( \Softorio\AiAssistant\Admin\SetupWizard::REDIRECT );
	sai_save_settings( array( 'openai_key' => '', 'openai_key_clear' => 1 ) );
	Installer::activate();
	T::same( '1', (string) get_option( \Softorio\AiAssistant\Admin\SetupWizard::REDIRECT ) );
	sai_save_settings( array( 'openai_key' => 'sk-test-openai-key-123' ) );
	delete_option( \Softorio\AiAssistant\Admin\SetupWizard::REDIRECT );
	Installer::activate();
	T::same( false, get_option( \Softorio\AiAssistant\Admin\SetupWizard::REDIRECT ), 'reactivation of a configured plugin: no wizard' );
	wp_set_current_user( 0 );
} );

T::test( 'Site Health checks', function () {
	$tests = \Softorio\AiAssistant\Admin\Health::tests( array( 'direct' => array() ) );
	T::same( 4, count( $tests['direct'] ) );

	T::same( 'good', \Softorio\AiAssistant\Admin\Health::test_ai()['status'] );
	update_option( 'softorio_ai_last_error', array( 'time' => time(), 'message' => 'Insufficient quota' ) );
	$ai = \Softorio\AiAssistant\Admin\Health::test_ai();
	T::same( 'critical', $ai['status'] );
	T::ok( str_contains( $ai['description'], 'Insufficient quota' ) );
	delete_option( 'softorio_ai_last_error' );

	global $wpdb;
	\Softorio\AiAssistant\Support\Queue::push( 'email.send', array( 'to' => 'x@example.com' ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::tables()['jobs'] . ' SET run_at = %d', time() - HOUR_IN_SECONDS ) );
	T::same( 'critical', \Softorio\AiAssistant\Admin\Health::test_queue()['status'], 'overdue tasks = cron not running' );
	$wpdb->query( 'DELETE FROM ' . Installer::tables()['jobs'] );
	T::same( 'good', \Softorio\AiAssistant\Admin\Health::test_queue()['status'] );

	$info = \Softorio\AiAssistant\Admin\Health::debug( array() );
	T::same( SOFTORIO_AI_VERSION, $info['softorio-ai']['fields']['version']['value'] );
	T::ok( ! str_contains( wp_json_encode( $info ), 'sk-test' ), 'no keys in the debug info' );
} );

T::test( 'uninstall keeps data by default, removes everything when asked', function () {
	global $wpdb;
	$t = Installer::tables();

	// Something of everything.
	$doc  = wp_insert_post( array( 'post_type' => PostTypes::DOC, 'post_status' => 'publish', 'post_title' => 'Doc', 'post_content' => 'Body text here.' ) );
	$page = sai_post( 'Page', 'Page body.' );
	update_post_meta( $page, '_softorio_ai_exclude', 1 );
	update_user_meta( 1, 'softorio_ai_live_away', 1 );
	set_transient( 'softorio_ai_robots_test', 'x', 60 );
	update_option( 'softorio_ai_flows', array( 'x' ) );
	wp_schedule_event( time() + 3600, 'daily', 'softorio_ai_web_resync' );

	$run = static function (): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'all-in-one-ai-chatbot/all-in-one-ai-chatbot.php' );
		}
		include SOFTORIO_AI_DIR . 'uninstall.php';
	};

	$run();
	T::same( false, wp_next_scheduled( 'softorio_ai_web_resync' ), 'scheduled tasks always removed' );
	T::ok( null !== get_post( $doc ), 'articles kept' );
	T::same( array( 'x' ), get_option( 'softorio_ai_flows' ), 'options kept' );
	T::ok( null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$t['messages']}" ), 'tables kept' );

	sai_save_settings( array( 'delete_on_uninstall' => true ) );
	$run();

	foreach ( $t as $table ) {
		T::same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "dropped $table" );
	}
	T::same( null, get_post( $doc ), 'articles deleted' );
	T::ok( null !== get_post( $page ), 'the site\'s own pages are untouched' );
	T::same( '', get_post_meta( $page, '_softorio_ai_exclude', true ), 'plugin meta removed from them' );
	T::same( '', get_user_meta( 1, 'softorio_ai_live_away', true ) );
	T::same( false, get_transient( 'softorio_ai_robots_test' ) );
	T::same( false, get_option( 'softorio_ai_settings' ) );
	T::same( false, get_option( 'softorio_ai_flows' ) );

	// Put the plugin back for anything that runs after.
	Installer::install_schema();
	add_option( Settings::OPTION, Settings::defaults() );
	T::ok( null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$t['messages']}" ), 'reinstalls cleanly' );
	wp_delete_post( $page, true );
} );

sai_reset();
