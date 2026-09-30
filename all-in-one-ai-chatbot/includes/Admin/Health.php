<?php
/**
 * Site Health checks.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Installer;
use Softorio\AiAssistant\Knowledge\IndexStore;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the plugin's own checks to Tools → Site Health, where owners and
 * hosts already look, and a section to the debug information that is
 * copied into support requests.
 */
final class Health {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( self::class, 'tests' ) );
		add_filter( 'debug_information', array( self::class, 'debug' ) );
	}

	/**
	 * Register the tests.
	 *
	 * @param array<string, mixed> $tests Tests.
	 * @return array<string, mixed>
	 */
	public static function tests( array $tests ): array {
		foreach ( array( 'ai', 'index', 'queue', 'extensions' ) as $name ) {
			$tests['direct'][ 'softorio_ai_' . $name ] = array(
				'label' => 'All in One AI Chatbot',
				'test'  => array( self::class, 'test_' . $name ),
			);
		}

		return $tests;
	}

	/**
	 * A result in Site Health's format.
	 *
	 * @param string $name        Test id.
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Headline.
	 * @param string $description Explanation (plain text).
	 * @param string $action_url  Where to fix it.
	 * @param string $action_text Link text.
	 * @return array<string, mixed>
	 */
	private static function result( string $name, string $status, string $label, string $description, string $action_url = '', string $action_text = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'AI Chatbot', 'all-in-one-ai-chatbot' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '' !== $action_url ? sprintf( '<p><a href="%s">%s</a></p>', esc_url( $action_url ), esc_html( $action_text ) ) : '',
			'test'        => 'softorio_ai_' . $name,
		);
	}

	/**
	 * An AI provider is configured and has not been failing.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_ai(): array {
		if ( ! Settings::is_ready() ) {
			return self::result( 'ai', 'recommended', __( 'The AI chatbot has no AI provider key', 'all-in-one-ai-chatbot' ), __( 'The chat widget stays hidden until an API key for OpenAI, Claude, Gemini, DeepSeek or OpenRouter is saved.', 'all-in-one-ai-chatbot' ), SetupWizard::url( 1 ), __( 'Run the setup', 'all-in-one-ai-chatbot' ) );
		}

		$error = get_option( 'softorio_ai_last_error', array() );

		if ( is_array( $error ) && ! empty( $error['time'] ) && time() - (int) $error['time'] < DAY_IN_SECONDS ) {
			/* translators: %s: error message from the AI provider */
			return self::result( 'ai', 'critical', __( 'The AI provider returned errors in the last 24 hours', 'all-in-one-ai-chatbot' ), sprintf( __( 'Latest: %s. Visitors may not be getting answers. Check the key, the account balance and the model name.', 'all-in-one-ai-chatbot' ), (string) ( $error['message'] ?? '' ) ), Menu::url( 'settings', array( 'tab' => 'ai' ) ), __( 'Check the AI settings', 'all-in-one-ai-chatbot' ) );
		}

		return self::result( 'ai', 'good', __( 'The AI chatbot is connected to its AI provider', 'all-in-one-ai-chatbot' ), __( 'An API key is saved and no provider errors were recorded in the last 24 hours.', 'all-in-one-ai-chatbot' ) );
	}

	/**
	 * The knowledge index has content.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_index(): array {
		$stats = ( new IndexStore() )->stats();

		if ( 0 === (int) $stats['posts'] ) {
			return self::result( 'index', 'recommended', __( 'The AI chatbot has nothing to answer from yet', 'all-in-one-ai-chatbot' ), __( 'Its knowledge index is empty, so it will say it does not know. Build the index, or add Knowledge Articles or documents.', 'all-in-one-ai-chatbot' ), Menu::url(), __( 'Build the index', 'all-in-one-ai-chatbot' ) );
		}

		/* translators: 1: pages and posts, 2: passages */
		return self::result( 'index', 'good', __( 'The AI chatbot\'s knowledge index is built', 'all-in-one-ai-chatbot' ), sprintf( __( '%1$s pages, posts and articles are searchable (%2$s passages).', 'all-in-one-ai-chatbot' ), number_format_i18n( (int) $stats['posts'] ), number_format_i18n( (int) $stats['chunks'] ) ) );
	}

	/**
	 * Background work (emails, CRM, web imports) is running.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_queue(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
		$stuck = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'pending' AND run_at < %d", Installer::tables()['jobs'], time() - 15 * MINUTE_IN_SECONDS ) );

		if ( $stuck > 0 ) {
			return self::result(
				'queue',
				'critical',
				__( 'The AI chatbot\'s background tasks are not running', 'all-in-one-ai-chatbot' ),
				/* translators: %d: number of tasks */
				sprintf( __( '%d tasks (lead emails, Telegram, CRM, web imports) are overdue. WP-Cron is probably not running: either the site gets no visits, or DISABLE_WP_CRON is set without a real cron job. Ask your host to run wp-cron.php every minute.', 'all-in-one-ai-chatbot' ), $stuck ),
				Menu::url( 'log' ),
				__( 'Open the Activity Log', 'all-in-one-ai-chatbot' )
			);
		}

		$failed = Queue::counts()['failed'];

		if ( $failed > 0 ) {
			/* translators: %d: number of tasks */
			return self::result( 'queue', 'recommended', __( 'Some AI chatbot background tasks failed', 'all-in-one-ai-chatbot' ), sprintf( __( '%d tasks failed after several attempts. The Activity Log shows why, and can retry them.', 'all-in-one-ai-chatbot' ), $failed ), Menu::url( 'log' ), __( 'Open the Activity Log', 'all-in-one-ai-chatbot' ) );
		}

		return self::result( 'queue', 'good', __( 'The AI chatbot\'s background tasks are running', 'all-in-one-ai-chatbot' ), __( 'Emails, alerts and CRM updates are being sent.', 'all-in-one-ai-chatbot' ) );
	}

	/**
	 * PHP extensions some features need.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_extensions(): array {
		$missing = array_values( array_filter( array( 'zip', 'dom', 'curl', 'sodium' ), static fn( string $ext ): bool => ! extension_loaded( $ext ) ) );

		if ( array() !== $missing ) {
			return self::result(
				'extensions',
				'recommended',
				__( 'Some AI chatbot features need PHP extensions this server lacks', 'all-in-one-ai-chatbot' ),
				/* translators: %s: extension names */
				sprintf( __( 'Missing: %s. zip reads Word files, dom reads web pages, curl makes live typing (streaming) work, sodium encrypts API keys. Ask your host to enable them (cPanel: Select PHP Version → Extensions).', 'all-in-one-ai-chatbot' ), implode( ', ', $missing ) )
			);
		}

		return self::result( 'extensions', 'good', __( 'The server has everything the AI chatbot needs', 'all-in-one-ai-chatbot' ), __( 'PHP extensions for document import, web import, streaming and key encryption are available.', 'all-in-one-ai-chatbot' ) );
	}

	/**
	 * A section in Site Health → Info, copied into support requests.
	 * No keys or personal data.
	 *
	 * @param array<string, mixed> $info Sections.
	 * @return array<string, mixed>
	 */
	public static function debug( array $info ): array {
		$stats  = ( new IndexStore() )->stats();
		$queue  = Queue::counts();
		$fields = array(
			'version'   => array( __( 'Version', 'all-in-one-ai-chatbot' ), SOFTORIO_AI_VERSION ),
			'db'        => array( __( 'Database version', 'all-in-one-ai-chatbot' ), (string) get_option( 'softorio_ai_db_version' ) ),
			'provider'  => array( __( 'AI provider', 'all-in-one-ai-chatbot' ), (string) Settings::get( 'provider', '' ) . ( Settings::is_ready() ? '' : ' (' . __( 'no key', 'all-in-one-ai-chatbot' ) . ')' ) ),
			'index'     => array( __( 'Indexed items / passages', 'all-in-one-ai-chatbot' ), $stats['posts'] . ' / ' . $stats['chunks'] ),
			'queue'     => array( __( 'Pending / failed tasks', 'all-in-one-ai-chatbot' ), $queue['pending'] . ' / ' . $queue['failed'] ),
			'streaming' => array( __( 'Live typing', 'all-in-one-ai-chatbot' ), Settings::get( 'streaming', false ) ? 'on' : 'off' ),
			'live'      => array( __( 'Live chat', 'all-in-one-ai-chatbot' ), Settings::get( 'live_chat', false ) ? 'on' : 'off' ),
			'members'   => array( __( 'Members-only knowledge', 'all-in-one-ai-chatbot' ), Settings::get( 'members_knowledge', false ) ? 'on' : 'off' ),
			'retention' => array( __( 'Conversation retention (days)', 'all-in-one-ai-chatbot' ), (string) Settings::get( 'retention_days', 90 ) ),
		);

		$info['softorio-ai'] = array(
			'label'  => __( 'All in One AI Chatbot', 'all-in-one-ai-chatbot' ),
			'fields' => array_map(
				static fn( array $f ): array => array(
					'label' => $f[0],
					'value' => $f[1],
				),
				$fields
			),
		);

		return $info;
	}
}
