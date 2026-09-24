<?php
/**
 * Plugin settings: defaults, reads and the provider catalogue.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant;

use Softorio\AiAssistant\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one autoloaded option.
 *
 * One option rather than many because every request that shows the widget
 * needs most of them, and WordPress loads autoloaded options in a single query.
 * API keys are stored encrypted inside it (see Crypto) and are only ever
 * decrypted by key() at the moment a provider needs them.
 */
final class Settings {

	public const OPTION = 'softorio_ai_settings';

	/**
	 * Providers the plugin can talk to, with sensible defaults.
	 *
	 * The default models are the cheaper tier of each vendor: support answers
	 * grounded in retrieved text do not need a frontier model, and the client
	 * pays per token. Any model id can be typed into the settings instead.
	 *
	 * @return array<string, array{label: string, default_model: string, key_url: string}>
	 */
	public static function providers(): array {
		return array(
			'openai'   => array(
				'label'         => 'OpenAI',
				'default_model' => 'gpt-5-mini',
				'key_url'       => 'https://platform.openai.com/api-keys',
			),
			'claude'   => array(
				'label'         => 'Anthropic Claude',
				'default_model' => 'claude-haiku-4-5',
				'key_url'       => 'https://console.anthropic.com/settings/keys',
			),
			'deepseek' => array(
				'label'         => 'DeepSeek',
				'default_model' => 'deepseek-chat',
				'key_url'       => 'https://platform.deepseek.com/api_keys',
			),
		);
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// General.
			'enabled'              => true,
			'assistant_name'       => __( 'Support Assistant', 'softorio-ai-assistant' ),
			'company_name'         => '',
			'greeting'             => __( 'Hi! 👋 How can I help you today?', 'softorio-ai-assistant' ),
			'instructions'         => '',

			// AI provider.
			'provider'             => 'openai',
			'fallback_provider'    => '',
			'openai_key'           => '',
			'openai_model'         => 'gpt-5-mini',
			'openai_reasoning'     => 'minimal',
			'claude_key'           => '',
			'claude_model'         => 'claude-haiku-4-5',
			'deepseek_key'         => '',
			'deepseek_model'       => 'deepseek-chat',
			'max_tokens'           => 1024,
			'history_turns'        => 6,

			// Knowledge.
			'post_types'           => array( 'post', 'page', PostTypes::DOC ),
			'results'              => 5,
			'semantic_search'      => false,

			// Hand-off to a human.
			'whatsapp'             => '',
			'contact_email'        => '',
			'contact_url'          => '',

			// Widget appearance.
			'color'                => '#2563eb',
			'position'             => 'right',
			'suggestions'          => '',
			'show_sources'         => true,
			'hide_for_admins'      => false,

			// Limits and privacy.
			'max_message_length'   => 1000,
			'visitor_hourly_limit' => 30,
			'daily_message_cap'    => 500,
			'daily_budget'         => 2.0,
			'trust_cloudflare'     => false,
			'retention_days'       => 90,
			'delete_on_uninstall'  => false,
		);
	}

	/**
	 * Every setting, with defaults filled in for anything never saved.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $name     Setting name.
	 * @param mixed  $fallback Returned when the setting does not exist at all.
	 * @return mixed
	 */
	public static function get( string $name, mixed $fallback = null ): mixed {
		$all = self::all();

		return array_key_exists( $name, $all ) ? $all[ $name ] : $fallback;
	}

	/**
	 * The decrypted API key for a provider, or '' when none is usable.
	 *
	 * @param string $provider Provider id.
	 */
	public static function api_key( string $provider ): string {
		$stored = (string) self::get( $provider . '_key', '' );

		return '' === $stored ? '' : Crypto::decrypt( $stored );
	}

	/**
	 * The model configured for a provider.
	 *
	 * @param string $provider Provider id.
	 */
	public static function model( string $provider ): string {
		$model = trim( (string) self::get( $provider . '_model', '' ) );

		if ( '' !== $model ) {
			return $model;
		}

		return self::providers()[ $provider ]['default_model'] ?? '';
	}

	/**
	 * Whether the primary provider has a key, i.e. whether the assistant can answer at all.
	 */
	public static function is_ready(): bool {
		return '' !== self::api_key( (string) self::get( 'provider', 'openai' ) );
	}

	/**
	 * The company name shown to the model, falling back to the site title.
	 */
	public static function company_name(): string {
		$name = trim( (string) self::get( 'company_name', '' ) );

		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
