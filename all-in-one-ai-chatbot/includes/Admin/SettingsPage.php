<?php
/**
 * Settings screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Cron;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\PostTypes;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * One settings form, grouped into sections, saved through the Settings API.
 */
final class SettingsPage {

	private const GROUP = 'softorio_ai';

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Validate and normalise submitted settings.
	 *
	 * Starts from the saved values so a field missing from the form never
	 * resets a setting, and API key fields left blank keep the saved key —
	 * keys are never sent back to the browser, so a blank field means
	 * "unchanged", not "delete".
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$old   = Settings::all();
		$input = is_array( $input ) ? $input : array();

		// register_setting also runs this on add_option/update_option calls
		// made in code (activation, tests). Those pass complete, already clean
		// arrays and must not be treated as a form post with every checkbox off.
		if ( ! isset( $input['_form'] ) ) {
			return array_merge( $old, $input );
		}

		$out = $old;

		$text = static fn( string $key, int $max = 200 ): string => mb_substr( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ), 0, $max, 'UTF-8' );
		$area = static fn( string $key, int $max = 4000 ): string => mb_substr( sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) ), 0, $max, 'UTF-8' );
		$int  = static fn( string $key, int $min, int $max ): int => min( $max, max( $min, (int) ( $input[ $key ] ?? $min ) ) );
		$bool = static fn( string $key ): bool => ! empty( $input[ $key ] );

		// General.
		$out['enabled']        = $bool( 'enabled' );
		$out['assistant_name'] = $text( 'assistant_name', 60 );
		$out['company_name']   = $text( 'company_name', 120 );
		$out['greeting']       = $area( 'greeting', 500 );
		$out['instructions']   = $area( 'instructions', 4000 );

		// Provider.
		$providers = array_keys( Settings::providers() );

		$out['provider']          = in_array( $input['provider'] ?? '', $providers, true ) ? $input['provider'] : 'openai';
		$out['fallback_provider'] = in_array( $input['fallback_provider'] ?? '', $providers, true ) ? $input['fallback_provider'] : '';

		foreach ( $providers as $id ) {
			$new_key = trim( sanitize_text_field( (string) ( $input[ $id . '_key' ] ?? '' ) ) );

			if ( ! empty( $input[ $id . '_key_clear' ] ) ) {
				$out[ $id . '_key' ] = '';
			} elseif ( '' !== $new_key ) {
				$out[ $id . '_key' ] = Crypto::encrypt( $new_key );
			}

			$model = preg_replace( '/[^A-Za-z0-9._:\-\/]/', '', (string) ( $input[ $id . '_model' ] ?? '' ) );

			$out[ $id . '_model' ] = '' !== $model ? mb_substr( $model, 0, 100 ) : Settings::providers()[ $id ]['default_model'];
		}

		$out['openai_reasoning'] = in_array( $input['openai_reasoning'] ?? '', array( '', 'minimal', 'low', 'medium' ), true ) ? $input['openai_reasoning'] : 'minimal';
		$out['max_tokens']       = $int( 'max_tokens', 128, 8000 );
		$out['history_turns']    = $int( 'history_turns', 0, 20 );

		// Knowledge.
		$types = array_filter(
			array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ),
			'post_type_exists'
		);

		$out['post_types']      = array_values( array_unique( array_merge( array_values( $types ), array( PostTypes::DOC ) ) ) );
		$out['results']         = $int( 'results', 1, 10 );
		$out['semantic_search'] = $bool( 'semantic_search' );

		// Hand-off.
		$out['whatsapp']      = preg_replace( '/[^0-9+\s\-]/', '', $text( 'whatsapp', 30 ) );
		$out['contact_email'] = sanitize_email( (string) ( $input['contact_email'] ?? '' ) );
		$out['contact_url']   = esc_url_raw( (string) ( $input['contact_url'] ?? '' ) );

		// Appearance.
		$color = sanitize_hex_color( (string) ( $input['color'] ?? '' ) );

		$out['color']           = $color ? $color : '#2563eb';
		$out['position']        = 'left' === ( $input['position'] ?? '' ) ? 'left' : 'right';
		$out['suggestions']     = $area( 'suggestions', 600 );
		$out['show_sources']    = $bool( 'show_sources' );
		$out['hide_for_admins'] = $bool( 'hide_for_admins' );

		// Limits and privacy.
		$out['max_message_length']   = $int( 'max_message_length', 100, 4000 );
		$out['visitor_hourly_limit'] = $int( 'visitor_hourly_limit', 0, 1000 );
		$out['daily_message_cap']    = $int( 'daily_message_cap', 0, 100000 );
		$out['daily_budget']         = round( min( 1000, max( 0, (float) ( $input['daily_budget'] ?? 0 ) ) ), 2 );
		$out['trust_cloudflare']     = $bool( 'trust_cloudflare' );
		$out['retention_days']       = $int( 'retention_days', 0, 3650 );
		$out['delete_on_uninstall']  = $bool( 'delete_on_uninstall' );

		// What gets indexed changed: rebuild in the background so the index
		// matches without the owner having to know to press a button.
		sort( $old['post_types'] );
		$new_types = $out['post_types'];
		sort( $new_types );

		if ( $old['post_types'] !== $new_types || ( ! $old['semantic_search'] && $out['semantic_search'] ) ) {
			update_option( Indexer::STATE_OPTION, array( 'status' => 'pending' ), false );
			Cron::queue_build( 0 );
		}

		return $out;
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Settings::all();

		$name = static fn( string $key ): string => Settings::OPTION . '[' . $key . ']';
		?>
		<div class="wrap sai-admin">
			<h1><?php esc_html_e( 'AI Chatbot Settings', 'all-in-one-ai-chatbot' ); ?></h1>

			<?php
			// Pages outside Settings → … do not print the "Settings saved"
			// notice on their own.
			settings_errors();
			?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name( '_form' ) ); ?>" value="1">

				<div class="sai-card">
					<h2><?php esc_html_e( 'AI provider', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'The assistant uses your own account with an AI provider. You pay the provider directly for what the assistant uses. API keys are stored encrypted.', 'all-in-one-ai-chatbot' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sai-provider"><?php esc_html_e( 'Main provider', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<select id="sai-provider" name="<?php echo esc_attr( $name( 'provider' ) ); ?>">
									<?php foreach ( Settings::providers() as $id => $p ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-fallback"><?php esc_html_e( 'Backup provider', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<select id="sai-fallback" name="<?php echo esc_attr( $name( 'fallback_provider' ) ); ?>">
									<option value=""><?php esc_html_e( 'None', 'all-in-one-ai-chatbot' ); ?></option>
									<?php foreach ( Settings::providers() as $id => $p ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['fallback_provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used automatically if the main provider fails. Needs its own API key below.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>

						<?php foreach ( Settings::providers() as $id => $p ) : ?>
							<?php $hint = Crypto::hint( (string) $s[ $id . '_key' ] ); ?>
							<tr class="sai-provider-row">
								<th scope="row"><?php echo esc_html( $p['label'] ); ?></th>
								<td>
									<label class="sai-inline">
										<span><?php esc_html_e( 'API key', 'all-in-one-ai-chatbot' ); ?></span>
										<input type="password" class="regular-text" autocomplete="new-password"
											name="<?php echo esc_attr( $name( $id . '_key' ) ); ?>"
											placeholder="<?php echo esc_attr( '' !== $hint ? sprintf( /* translators: %s: last characters of the saved key */ __( 'Saved (%s) — leave blank to keep', 'all-in-one-ai-chatbot' ), $hint ) : __( 'Paste your API key', 'all-in-one-ai-chatbot' ) ); ?>">
									</label>
									<?php if ( '' !== $hint ) : ?>
										<label class="sai-clear">
											<input type="checkbox" name="<?php echo esc_attr( $name( $id . '_key_clear' ) ); ?>" value="1">
											<?php esc_html_e( 'Remove saved key', 'all-in-one-ai-chatbot' ); ?>
										</label>
									<?php elseif ( '' !== (string) $s[ $id . '_key' ] ) : ?>
										<p class="sai-warn"><?php esc_html_e( 'A key was saved but can no longer be read (the site security keys changed). Please enter it again.', 'all-in-one-ai-chatbot' ); ?></p>
									<?php endif; ?>
									<label class="sai-inline">
										<span><?php esc_html_e( 'Model', 'all-in-one-ai-chatbot' ); ?></span>
										<input type="text" class="regular-text code" name="<?php echo esc_attr( $name( $id . '_model' ) ); ?>" value="<?php echo esc_attr( (string) $s[ $id . '_model' ] ); ?>">
									</label>
									<p class="description">
										<a href="<?php echo esc_url( $p['key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'all-in-one-ai-chatbot' ); ?></a>
										· <button type="button" class="button-link sai-test" data-provider="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Test connection', 'all-in-one-ai-chatbot' ); ?></button>
										<span class="sai-test-result" data-for="<?php echo esc_attr( $id ); ?>"></span>
									</p>
								</td>
							</tr>
						<?php endforeach; ?>

						<tr>
							<th scope="row"><label for="sai-reasoning"><?php esc_html_e( 'OpenAI reasoning effort', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<select id="sai-reasoning" name="<?php echo esc_attr( $name( 'openai_reasoning' ) ); ?>">
									<?php foreach ( array( 'minimal', 'low', 'medium', '' ) as $effort ) : ?>
										<option value="<?php echo esc_attr( $effort ); ?>" <?php selected( $s['openai_reasoning'], $effort ); ?>><?php echo esc_html( '' === $effort ? __( 'Model default', 'all-in-one-ai-chatbot' ) : $effort ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'For GPT-5 models only. "minimal" is fastest and cheapest, and is plenty for support answers.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-max-tokens"><?php esc_html_e( 'Max answer length (tokens)', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-max-tokens" type="number" min="128" max="8000" name="<?php echo esc_attr( $name( 'max_tokens' ) ); ?>" value="<?php echo esc_attr( (string) $s['max_tokens'] ); ?>" class="small-text"></td>
						</tr>
					</table>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Assistant', 'all-in-one-ai-chatbot' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'all-in-one-ai-chatbot' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'enabled' ) ); ?>" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Show the chat widget on the website', 'all-in-one-ai-chatbot' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-name"><?php esc_html_e( 'Assistant name', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-name" type="text" class="regular-text" name="<?php echo esc_attr( $name( 'assistant_name' ) ); ?>" value="<?php echo esc_attr( (string) $s['assistant_name'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-company"><?php esc_html_e( 'Business name', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-company" type="text" class="regular-text" name="<?php echo esc_attr( $name( 'company_name' ) ); ?>" value="<?php echo esc_attr( (string) $s['company_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-greeting"><?php esc_html_e( 'Welcome message', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><textarea id="sai-greeting" class="large-text" rows="2" name="<?php echo esc_attr( $name( 'greeting' ) ); ?>"><?php echo esc_textarea( (string) $s['greeting'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-instructions"><?php esc_html_e( 'Extra instructions', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<textarea id="sai-instructions" class="large-text" rows="5" name="<?php echo esc_attr( $name( 'instructions' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Always answer in Bangla unless the visitor writes in English. Never discuss competitors. Mention that delivery inside Dhaka takes 1–2 days.', 'all-in-one-ai-chatbot' ); ?>"><?php echo esc_textarea( (string) $s['instructions'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Tone, language and rules for the assistant. Put facts and answers in Knowledge Articles instead, so they can be searched.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Knowledge', 'all-in-one-ai-chatbot' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Content the assistant reads', 'all-in-one-ai-chatbot' ); ?></th>
							<td>
								<?php foreach ( self::selectable_post_types() as $type => $label ) : ?>
									<label class="sai-block">
										<input type="checkbox" name="<?php echo esc_attr( $name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $s['post_types'], true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Knowledge Articles are always included. Only published, non-password-protected content is used. Hide a single page with the "AI Chatbot" box in the editor.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-results"><?php esc_html_e( 'Passages per answer', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<input id="sai-results" type="number" min="1" max="10" class="small-text" name="<?php echo esc_attr( $name( 'results' ) ); ?>" value="<?php echo esc_attr( (string) $s['results'] ); ?>">
								<p class="description"><?php esc_html_e( 'More passages give the AI more to work with but cost more per answer. 4–6 suits most sites.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Semantic search', 'all-in-one-ai-chatbot' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name( 'semantic_search' ) ); ?>" value="1" <?php checked( $s['semantic_search'] ); ?>> <?php esc_html_e( 'Match meaning, not only words (uses OpenAI embeddings)', 'all-in-one-ai-chatbot' ); ?></label>
								<p class="description"><?php esc_html_e( 'Finds answers phrased differently from the question — e.g. "money back" finds your refund policy. Needs an OpenAI API key even if another provider writes the answers. Costs a fraction of a cent per question.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Talk to a person', 'all-in-one-ai-chatbot' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Shown in the widget and offered by the assistant when it cannot help.', 'all-in-one-ai-chatbot' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sai-whatsapp"><?php esc_html_e( 'WhatsApp number', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-whatsapp" type="text" class="regular-text" name="<?php echo esc_attr( $name( 'whatsapp' ) ); ?>" value="<?php echo esc_attr( (string) $s['whatsapp'] ); ?>" placeholder="+8801XXXXXXXXX"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-email"><?php esc_html_e( 'Support email', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-email" type="email" class="regular-text" name="<?php echo esc_attr( $name( 'contact_email' ) ); ?>" value="<?php echo esc_attr( (string) $s['contact_email'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-contact"><?php esc_html_e( 'Contact page URL', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-contact" type="url" class="regular-text" name="<?php echo esc_attr( $name( 'contact_url' ) ); ?>" value="<?php echo esc_attr( (string) $s['contact_url'] ); ?>"></td>
						</tr>
					</table>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Appearance', 'all-in-one-ai-chatbot' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sai-color"><?php esc_html_e( 'Colour', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-color" type="color" name="<?php echo esc_attr( $name( 'color' ) ); ?>" value="<?php echo esc_attr( (string) $s['color'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Position', 'all-in-one-ai-chatbot' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $name( 'position' ) ); ?>" value="right" <?php checked( $s['position'], 'right' ); ?>> <?php esc_html_e( 'Bottom right', 'all-in-one-ai-chatbot' ); ?></label>
								&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( $name( 'position' ) ); ?>" value="left" <?php checked( $s['position'], 'left' ); ?>> <?php esc_html_e( 'Bottom left', 'all-in-one-ai-chatbot' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-suggestions"><?php esc_html_e( 'Suggested questions', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<textarea id="sai-suggestions" class="large-text" rows="3" name="<?php echo esc_attr( $name( 'suggestions' ) ); ?>" placeholder="<?php esc_attr_e( "What are your delivery charges?\nHow do I return an item?", 'all-in-one-ai-chatbot' ); ?>"><?php echo esc_textarea( (string) $s['suggestions'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One per line, up to four. Shown as buttons before the first message.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options', 'all-in-one-ai-chatbot' ); ?></th>
							<td>
								<label class="sai-block"><input type="checkbox" name="<?php echo esc_attr( $name( 'show_sources' ) ); ?>" value="1" <?php checked( $s['show_sources'] ); ?>> <?php esc_html_e( 'Show links to related pages under answers', 'all-in-one-ai-chatbot' ); ?></label>
								<label class="sai-block"><input type="checkbox" name="<?php echo esc_attr( $name( 'hide_for_admins' ) ); ?>" value="1" <?php checked( $s['hide_for_admins'] ); ?>> <?php esc_html_e( 'Hide the widget from administrators', 'all-in-one-ai-chatbot' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="sai-card">
					<h2><?php esc_html_e( 'Limits and privacy', 'all-in-one-ai-chatbot' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sai-hourly"><?php esc_html_e( 'Messages per visitor per hour', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-hourly" type="number" min="0" class="small-text" name="<?php echo esc_attr( $name( 'visitor_hourly_limit' ) ); ?>" value="<?php echo esc_attr( (string) $s['visitor_hourly_limit'] ); ?>"> <span class="description"><?php esc_html_e( 'Per network address. 0 = no limit (not recommended).', 'all-in-one-ai-chatbot' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-cap"><?php esc_html_e( 'Answers per day (whole site)', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-cap" type="number" min="0" class="small-text" name="<?php echo esc_attr( $name( 'daily_message_cap' ) ); ?>" value="<?php echo esc_attr( (string) $s['daily_message_cap'] ); ?>"> <span class="description"><?php esc_html_e( '0 = no limit.', 'all-in-one-ai-chatbot' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-budget"><?php esc_html_e( 'Daily budget (USD)', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td>
								<input id="sai-budget" type="number" min="0" step="0.01" class="small-text" name="<?php echo esc_attr( $name( 'daily_budget' ) ); ?>" value="<?php echo esc_attr( (string) $s['daily_budget'] ); ?>">
								<p class="description"><?php esc_html_e( 'The assistant pauses for the rest of the day once estimated spend reaches this. Estimates only — your provider\'s bill is authoritative. 0 = no limit.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-maxlen"><?php esc_html_e( 'Longest visitor message (characters)', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-maxlen" type="number" min="100" max="4000" class="small-text" name="<?php echo esc_attr( $name( 'max_message_length' ) ); ?>" value="<?php echo esc_attr( (string) $s['max_message_length'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cloudflare', 'all-in-one-ai-chatbot' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name( 'trust_cloudflare' ) ); ?>" value="1" <?php checked( $s['trust_cloudflare'] ); ?>> <?php esc_html_e( 'This site is behind Cloudflare', 'all-in-one-ai-chatbot' ); ?></label>
								<p class="description"><?php esc_html_e( 'Only tick this if it is true. It lets the per-visitor limit see real visitor addresses; on a site not behind Cloudflare it would let anyone bypass the limit.', 'all-in-one-ai-chatbot' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sai-retention"><?php esc_html_e( 'Keep conversations for (days)', 'all-in-one-ai-chatbot' ); ?></label></th>
							<td><input id="sai-retention" type="number" min="0" class="small-text" name="<?php echo esc_attr( $name( 'retention_days' ) ); ?>" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>"> <span class="description"><?php esc_html_e( '0 = keep forever.', 'all-in-one-ai-chatbot' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uninstall', 'all-in-one-ai-chatbot' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'delete_on_uninstall' ) ); ?>" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all plugin data, including Knowledge Articles and conversations, when the plugin is deleted', 'all-in-one-ai-chatbot' ); ?></label></td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Public post types a site owner might want indexed, excluding the plugin's own.
	 *
	 * @return array<string, string> slug => label
	 */
	private static function selectable_post_types(): array {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || PostTypes::DOC === $type->name ) {
				continue;
			}

			$out[ $type->name ] = $type->labels->name;
		}

		return $out;
	}
}
