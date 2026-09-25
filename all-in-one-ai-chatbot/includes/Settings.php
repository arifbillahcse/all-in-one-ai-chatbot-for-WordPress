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
			'deepseek'   => array(
				'label'         => 'DeepSeek',
				'default_model' => 'deepseek-chat',
				'key_url'       => 'https://platform.deepseek.com/api_keys',
			),
			'gemini'     => array(
				'label'         => 'Google Gemini',
				'default_model' => 'gemini-2.5-flash',
				'key_url'       => 'https://aistudio.google.com/apikey',
			),
			'openrouter' => array(
				'label'         => 'OpenRouter',
				'default_model' => 'openai/gpt-5-mini',
				'key_url'       => 'https://openrouter.ai/settings/keys',
			),
		);
	}

	/**
	 * Default value for every setting, from the schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return SettingsSchema::defaults();
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
