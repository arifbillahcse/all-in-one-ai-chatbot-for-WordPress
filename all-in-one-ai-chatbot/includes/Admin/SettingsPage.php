<?php
/**
 * Settings screen.
 *
 * @package Softorio\AiAssistant
 */

namespace Softorio\AiAssistant\Admin;

use Softorio\AiAssistant\Cron;
use Softorio\AiAssistant\Knowledge\Indexer;
use Softorio\AiAssistant\Settings;
use Softorio\AiAssistant\SettingsSchema;
use Softorio\AiAssistant\Support\Crypto;
use Softorio\AiAssistant\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings, rendered and validated from SettingsSchema.
 *
 * Each tab is its own form and posts only its own fields. The sanitizer
 * updates just that tab's fields and keeps everything else as saved, so an
 * unticked checkbox on one tab can never switch off a setting on another.
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
	 * Validate a submission.
	 *
	 * A form post carries `_tab` (one tab's fields) or `_tab = *` (every
	 * field, used by tests and imports). Without it the value is a complete
	 * settings array written from code — activation, WP-CLI — and is only
	 * merged over what is saved.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$old   = Settings::all();
		$input = is_array( $input ) ? $input : array();

		if ( ! isset( $input['_tab'] ) ) {
			return array_merge( $old, $input );
		}

		$tab = (string) $input['_tab'];
		$out = $old;

		foreach ( SettingsSchema::fields() as $key => $field ) {
			if ( '*' !== $tab && ( $field['tab'] ?? '' ) !== $tab ) {
				continue;
			}

			$out[ $key ] = SettingsSchema::sanitize_value( $field, $input[ $key ] ?? null, $old[ $key ] ?? null, $input, $key );
		}

		self::after_save( $old, $out );

		return $out;
	}

	/**
	 * Side effects of particular changes.
	 *
	 * @param array<string, mixed> $old Previous settings.
	 * @param array<string, mixed> $saved New settings.
	 */
	private static function after_save( array $old, array $saved ): void {
		$old_types = (array) $old['post_types'];
		$new_types = (array) $saved['post_types'];
		sort( $old_types );
		sort( $new_types );

		// What gets indexed changed: rebuild in the background so the index
		// matches without the owner having to know to press a button.
		if ( $old_types !== $new_types || ( empty( $old['semantic_search'] ) && ! empty( $saved['semantic_search'] ) ) ) {
			update_option( Indexer::STATE_OPTION, array( 'status' => 'pending' ), false );
			Cron::queue_build( 0 );
		}

		/**
		 * Fires after settings are validated, before they are saved.
		 *
		 * @param array $old Previous settings.
		 * @param array $saved New settings.
		 */
		do_action( 'softorio_ai_settings_changed', $old, $saved );
	}

	/**
	 * The tab being viewed.
	 */
	private static function current_tab(): string {
		$tabs = SettingsSchema::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.

		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current  = self::current_tab();
		$settings = Settings::all();
		$sections = SettingsSchema::sections();
		$grouped  = array();

		foreach ( SettingsSchema::fields() as $key => $field ) {
			if ( ( $field['tab'] ?? '' ) === $current ) {
				$grouped[ (string) ( $field['section'] ?? 'main' ) ][ $key ] = $field;
			}
		}
		?>
		<div class="wrap sai-admin">
			<h1><?php esc_html_e( 'AI Chatbot Settings', 'all-in-one-ai-chatbot' ); ?></h1>

			<?php
			// Pages outside Settings → … do not print the "Settings saved"
			// notice on their own.
			settings_errors();
			?>

			<nav class="nav-tab-wrapper sai-tabs">
				<?php foreach ( SettingsSchema::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( Menu::url( 'settings', array( 'tab' => $slug ) ) ); ?>" class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::name( '_tab' ) ); ?>" value="<?php echo esc_attr( $current ); ?>">

				<?php foreach ( $grouped as $section => $fields ) : ?>
					<?php $meta = $sections[ $current . '.' . $section ] ?? array(); ?>
					<div class="sai-card">
						<?php if ( ! empty( $meta['title'] ) ) : ?>
							<h2><?php echo esc_html( $meta['title'] ); ?></h2>
						<?php endif; ?>
						<?php if ( ! empty( $meta['desc'] ) ) : ?>
							<p class="description"><?php echo esc_html( $meta['desc'] ); ?></p>
						<?php endif; ?>

						<table class="form-table" role="presentation">
							<?php foreach ( $fields as $key => $field ) : ?>
								<tr>
									<th scope="row">
										<?php if ( in_array( $field['type'], array( 'checkbox', 'radio', 'post_types', 'multicheck' ), true ) ) : ?>
											<?php echo esc_html( (string) $field['label'] ); ?>
										<?php else : ?>
											<label for="sai-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( (string) $field['label'] ); ?></label>
										<?php endif; ?>
									</th>
									<td><?php self::field( $key, $field, $settings[ $key ] ?? ( $field['default'] ?? null ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Input name for a setting.
	 *
	 * @param string $key Setting key.
	 */
	private static function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Render one input.
	 *
	 * @param string               $key   Setting key.
	 * @param array<string, mixed> $field Definition.
	 * @param mixed                $value Current value.
	 */
	private static function field( string $key, array $field, mixed $value ): void {
		$id   = 'sai-' . $key;
		$name = self::name( $key );
		$desc = (string) ( $field['desc'] ?? '' );
		$ph   = (string) ( $field['placeholder'] ?? '' );

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( $desc )
				);
				$desc = '';
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) $field['options'] as $option => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $option ), selected( (string) $value, (string) $option, false ), esc_html( (string) $label ) );
				}
				echo '</select>';
				break;

			case 'radio':
				foreach ( (array) $field['options'] as $option => $label ) {
					printf(
						'<label class="sai-radio"><input type="radio" name="%s" value="%s" %s> %s</label>',
						esc_attr( $name ),
						esc_attr( (string) $option ),
						checked( (string) $value, (string) $option, false ),
						esc_html( (string) $label )
					);
				}
				break;

			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="%d" class="large-text" placeholder="%s">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? 4 ),
					esc_attr( $ph ),
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
			case 'float':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" %s %s %s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : '',
					isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : '',
					'float' === $field['type'] ? 'step="0.01"' : ''
				);
				break;

			case 'color':
				printf( '<input type="color" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;

			case 'secret':
				$hint = Crypto::hint( (string) $value );
				printf(
					'<input type="password" id="%s" name="%s" class="regular-text" autocomplete="new-password" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr(
						'' !== $hint
							/* translators: %s: last characters of the saved key */
							? sprintf( __( 'Saved (%s) — leave blank to keep', 'all-in-one-ai-chatbot' ), $hint )
							: __( 'Paste your key', 'all-in-one-ai-chatbot' )
					)
				);
				if ( '' !== $hint ) {
					printf(
						'<label class="sai-clear"><input type="checkbox" name="%s" value="1"> %s</label>',
						esc_attr( self::name( $key . '_clear' ) ),
						esc_html__( 'Remove saved key', 'all-in-one-ai-chatbot' )
					);
				} elseif ( '' !== (string) $value ) {
					printf( '<p class="sai-warn">%s</p>', esc_html__( 'A key was saved but can no longer be read (the site security keys changed). Please enter it again.', 'all-in-one-ai-chatbot' ) );
				}
				if ( ! empty( $field['key_url'] ) || ! empty( $field['provider'] ) ) {
					echo '<p class="description">';
					if ( ! empty( $field['key_url'] ) ) {
						printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $field['key_url'] ), esc_html__( 'Get a key', 'all-in-one-ai-chatbot' ) );
					}
					if ( ! empty( $field['provider'] ) ) {
						printf(
							' · <button type="button" class="button-link sai-test" data-provider="%1$s">%2$s</button> <span class="sai-test-result" data-for="%1$s"></span>',
							esc_attr( (string) $field['provider'] ),
							esc_html__( 'Test connection', 'all-in-one-ai-chatbot' )
						);
					}
					echo '</p>';
				}
				break;

			case 'post_types':
				foreach ( self::selectable_post_types() as $type => $label ) {
					printf(
						'<label class="sai-block"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
						esc_attr( $name ),
						esc_attr( $type ),
						checked( in_array( $type, (array) $value, true ), true, false ),
						esc_html( $label )
					);
				}
				break;

			case 'image':
				printf(
					'<div class="sai-image-field"><img class="sai-image-preview" src="%1$s" alt="" %2$s><input type="url" id="%3$s" name="%4$s" value="%1$s" class="regular-text"> <button type="button" class="button sai-media" data-target="%3$s">%5$s</button></div>',
					esc_url( (string) $value ),
					'' === (string) $value ? 'hidden' : '',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_html__( 'Choose image', 'all-in-one-ai-chatbot' )
				);
				break;

			case 'hours':
				printf(
					'<input type="text" id="%s" name="%s" value="%s" class="regular-text code sai-hours" placeholder="%s" pattern="[0-9:.,\s\-–]*">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $ph )
				);
				break;

			case 'multicheck':
				foreach ( (array) $field['options'] as $option => $label ) {
					printf(
						'<label class="sai-block"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
						esc_attr( $name ),
						esc_attr( (string) $option ),
						checked( in_array( (string) $option, (array) $value, true ), true, false ),
						esc_html( (string) $label )
					);
				}
				break;

			case 'urls':
				printf(
					'<textarea id="%s" name="%s" rows="%d" class="large-text code" placeholder="%s">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? 3 ),
					esc_attr( $ph ),
					esc_textarea( (string) $value )
				);
				break;

			case 'generated':
				printf(
					'<input type="text" id="%s" value="%s" class="regular-text code" readonly onclick="this.select()"> <label class="sai-clear"><input type="checkbox" name="%s" value="1"> %s</label>',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( self::name( $key . '_regenerate' ) ),
					esc_html__( 'Generate a new secret', 'all-in-one-ai-chatbot' )
				);
				break;

			case 'model':
				printf( '<input type="text" id="%s" name="%s" value="%s" class="regular-text code">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;

			default:
				$type = match ( $field['type'] ) {
					'email' => 'email',
					'url'   => 'url',
					'phone' => 'tel',
					default => 'text',
				};
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s">',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $ph )
				);
		}

		if ( '' !== $desc ) {
			printf( '<p class="description">%s</p>', esc_html( $desc ) );
		}

		if ( ! empty( $field['test'] ) ) {
			printf(
				'<p><button type="button" class="button sai-test-integration" data-kind="%1$s">%2$s</button> <span class="sai-test-result" data-for="%1$s"></span></p>',
				esc_attr( (string) $field['test'] ),
				esc_html(
					match ( (string) $field['test'] ) {
						'telegram'      => __( 'Find my chat ID / send test', 'all-in-one-ai-chatbot' ),
						'telegram_live' => __( 'Connect Telegram replies', 'all-in-one-ai-chatbot' ),
						'hubspot', 'mailchimp', 'brevo' => __( 'Test connection', 'all-in-one-ai-chatbot' ),
						'report'        => __( 'Send a report now', 'all-in-one-ai-chatbot' ),
						default         => __( 'Send a test', 'all-in-one-ai-chatbot' ),
					}
				)
			);
		}
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
